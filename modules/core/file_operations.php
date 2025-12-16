<?php
/**
 * File hashing utilities with caching and async support
 */

require_once __DIR__ . '/../setup/database.php';

class FileHasher {
    private $db;
    private $chunkSize;
    
    public function __construct($chunkSize = 8 * 1024 * 1024) { // 8MB chunks
        $this->db = Database::getInstance();
        $this->chunkSize = $chunkSize;
    }
    
    /**
     * Get file hashes with caching
     */
    public function getFileHashes($filePath, $algorithms = ['md5', 'sha1', 'sha256']) {
        $fullPath = BASE_PATH . '/' . ltrim($filePath, '/');
        
        if (!file_exists($fullPath)) {
            throw new Exception('File not found: ' . $filePath);
        }
        
        $fileSize = filesize($fullPath);
        $modifiedTime = filemtime($fullPath);
        
        // Calculate hashes directly (no caching)
        $hashes = $this->calculateHashes($fullPath, $algorithms);
        
        return $hashes;
    }
    
    /**
     * Calculate file hashes efficiently
     */
    private function calculateHashes($filePath, $algorithms) {
        $hashes = [];
        $contexts = [];
        
        // Initialize hash contexts
        foreach ($algorithms as $algo) {
            $contexts[$algo] = hash_init($algo);
        }
        
        // Read file in chunks and update all hash contexts
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new Exception('Cannot open file for reading: ' . $filePath);
        }
        
        while (!feof($handle)) {
            $chunk = fread($handle, $this->chunkSize);
            foreach ($contexts as $algo => $context) {
                hash_update($context, $chunk);
            }
        }
        
        fclose($handle);
        
        // Finalize hashes
        foreach ($contexts as $algo => $context) {
            $hashes[$algo] = hash_final($context);
        }
        
        return $hashes;
    }
    

    
    /**
     * Trigger background hash calculation (for cron job to pick up)
     */
    public function triggerBackgroundHashCalculation($filePath) {
        // Mark this file as needing hash calculation
        $hash_queue_file = sys_get_temp_dir() . '/filebrowser_hash_queue.txt';
        file_put_contents($hash_queue_file, $filePath . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Calculate hashes asynchronously (background process)
     */
    public function calculateHashesAsync($filePath, $algorithms = ['md5', 'sha1', 'sha256']) {
        $operationId = $this->db->createFileOperation('hash', $filePath);
        
        // In a real implementation, this would spawn a background process
        // For now, we'll simulate it with a simple approach
        $this->db->updateFileOperation($operationId, 'running', 0);
        
        try {
            $hashes = $this->getFileHashes($filePath, $algorithms);
            $this->db->updateFileOperation($operationId, 'completed', 100);
            return ['operation_id' => $operationId, 'hashes' => $hashes];
        } catch (Exception $e) {
            $this->db->updateFileOperation($operationId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Verify file integrity
     */
    public function verifyFileIntegrity($filePath, $expectedHashes) {
        $actualHashes = $this->getFileHashes($filePath, array_keys($expectedHashes));
        $results = [];
        
        foreach ($expectedHashes as $algo => $expected) {
            $actual = $actualHashes[$algo] ?? null;
            $results[$algo] = [
                'expected' => $expected,
                'actual' => $actual,
                'valid' => $actual && hash_equals($expected, $actual)
            ];
        }
        
        return $results;
    }
    
    /**
     * Get hash information for multiple files
     */
    public function getMultipleFileHashes($filePaths, $algorithms = ['md5', 'sha256']) {
        $results = [];
        
        foreach ($filePaths as $filePath) {
            try {
                $results[$filePath] = $this->getFileHashes($filePath, $algorithms);
            } catch (Exception $e) {
                $results[$filePath] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Clear hash cache for a file
     */
    public function clearHashCache($filePath) {
        // File metadata table removed - no cleanup needed
    }
    
    /**
     * Rebuild hash cache for directory
     */
    public function rebuildDirectoryHashes($directory, $algorithms = ['md5', 'sha256']) {
        $basePath = BASE_PATH . '/' . ltrim($directory, '/');
        
        if (!is_dir($basePath)) {
            throw new Exception('Directory not found: ' . $directory);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $results = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace(BASE_PATH . '/', '', $file->getPathname());
                try {
                    // Clear existing cache
                    $this->clearHashCache($relativePath);
                    // Recalculate
                    $results[$relativePath] = $this->getFileHashes($relativePath, $algorithms);
                } catch (Exception $e) {
                    $results[$relativePath] = ['error' => $e->getMessage()];
                }
            }
        }
        
        return $results;
    }
}

/**
 * File operation utilities
 */
class FileOperations {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Copy files from pre-release to main directory
     */
    public function copyFromPreRelease($sourcePath, $destPath = null) {
        if (!$destPath) {
            $destPath = $sourcePath;
        }
        
        $sourceFullPath = PRE_RELEASE_PATH . '/' . ltrim($sourcePath, '/');
        $destFullPath = BASE_PATH . '/' . ltrim($destPath, '/');
        
        if (!file_exists($sourceFullPath)) {
            throw new Exception('Source file not found: ' . $sourcePath);
        }
        
        $operationId = $this->db->createFileOperation('copy', $sourcePath, $destPath);
        $this->db->updateFileOperation($operationId, 'running', 0);
        
        try {
            // Ensure destination directory exists
            $destDir = dirname($destFullPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            
            // Copy file
            if (is_dir($sourceFullPath)) {
                $this->copyDirectory($sourceFullPath, $destFullPath, $operationId);
            } else {
                $this->copyFile($sourceFullPath, $destFullPath, $operationId);
            }
            
            $this->db->updateFileOperation($operationId, 'completed', 100);
            
            // Log the copy operation
            log_action('COPY_FILE', "Copied {$sourcePath} to {$destPath}", $destPath);
            
            return ['operation_id' => $operationId, 'success' => true];
            
        } catch (Exception $e) {
            $this->db->updateFileOperation($operationId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }
    
    private function copyFile($source, $dest, $operationId) {
        if (!copy($source, $dest)) {
            throw new Exception('Failed to copy file');
        }
        
        // Preserve file permissions
        chmod($dest, fileperms($source));
        
        // Update progress
        $this->db->updateFileOperation($operationId, 'running', 90);
    }
    
    private function copyDirectory($source, $dest, $operationId) {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $totalFiles = iterator_count(clone $iterator);
        $processedFiles = 0;
        
        foreach ($iterator as $item) {
            $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item, $destPath);
                chmod($destPath, $item->getPerms());
            }
            
            $processedFiles++;
            $progress = ($processedFiles / $totalFiles) * 90; // Reserve 10% for completion
            $this->db->updateFileOperation($operationId, 'running', $progress);
        }
    }
    
    /**
     * List files in pre-release directory
     */
    public function listPreReleaseFiles($directory = '') {
        $fullPath = PRE_RELEASE_PATH . '/' . ltrim($directory, '/');
        
        if (!is_dir($fullPath)) {
            throw new Exception('Pre-release directory not found: ' . $directory);
        }
        
        $files = [];
        $items = scandir($fullPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $fullPath . '/' . $item;
            $relativePath = $directory ? $directory . '/' . $item : $item;
            
            $files[] = [
                'name' => $item,
                'path' => $relativePath,
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'size' => is_file($itemPath) ? filesize($itemPath) : null,
                'modified' => filemtime($itemPath),
                'permissions' => substr(sprintf('%o', fileperms($itemPath)), -4)
            ];
        }
        
        // Sort directories first, then files
        usort($files, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $files;
    }
    
    /**
     * Process file upload for push API
     */
    public function processUpload($filePath, $fileData, $fileSize, $expectedChecksum = null) {
        $fullPath = BASE_PATH . '/' . ltrim($filePath, '/');
        
        // Ensure destination directory exists
        $destDir = dirname($fullPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        
        // Handle different upload methods
        if (is_string($fileData)) {
            if (filter_var($fileData, FILTER_VALIDATE_URL)) {
                // Download from URL
                return $this->downloadFromUrl($fileData, $fullPath, $expectedChecksum);
            } else {
                // Assume base64 encoded data
                return $this->saveBase64Data($fileData, $fullPath, $expectedChecksum);
            }
        } elseif (is_array($fileData) && isset($fileData['chunks'])) {
            // Handle chunked upload
            return $this->assembleChunkedUpload($fileData, $fullPath, $expectedChecksum);
        } else {
            throw new Exception('Invalid file data format');
        }
    }
    
    private function downloadFromUrl($url, $destPath, $expectedChecksum = null) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 300, // 5 minutes
                'user_agent' => 'PHP File Browser Upload/1.0'
            ]
        ]);
        
        $data = file_get_contents($url, false, $context);
        if ($data === false) {
            throw new Exception('Failed to download file from URL');
        }
        
        if (file_put_contents($destPath, $data) === false) {
            throw new Exception('Failed to save downloaded file');
        }
        
        return $this->validateUploadedFile($destPath, $expectedChecksum);
    }
    
    private function saveBase64Data($base64Data, $destPath, $expectedChecksum = null) {
        // Remove data URL prefix if present
        if (strpos($base64Data, ',') !== false) {
            $base64Data = explode(',', $base64Data, 2)[1];
        }
        
        $data = base64_decode($base64Data);
        if ($data === false) {
            throw new Exception('Invalid base64 data');
        }
        
        if (file_put_contents($destPath, $data) === false) {
            throw new Exception('Failed to save file');
        }
        
        return $this->validateUploadedFile($destPath, $expectedChecksum);
    }
    
    private function assembleChunkedUpload($chunkData, $destPath, $expectedChecksum = null) {
        $chunks = $chunkData['chunks'];
        $totalChunks = $chunkData['total_chunks'];
        
        // Sort chunks by sequence
        ksort($chunks);
        
        if (count($chunks) !== $totalChunks) {
            throw new Exception('Missing chunks: expected ' . $totalChunks . ', got ' . count($chunks));
        }
        
        $fp = fopen($destPath, 'wb');
        if (!$fp) {
            throw new Exception('Cannot open destination file for writing');
        }
        
        try {
            foreach ($chunks as $sequence => $chunkData) {
                $data = base64_decode($chunkData);
                if ($data === false) {
                    throw new Exception('Invalid base64 data in chunk ' . $sequence);
                }
                fwrite($fp, $data);
            }
        } finally {
            fclose($fp);
        }
        
        return $this->validateUploadedFile($destPath, $expectedChecksum);
    }
    
    private function validateUploadedFile($filePath, $expectedChecksum = null) {
        if (!file_exists($filePath)) {
            throw new Exception('Uploaded file does not exist');
        }
        
        $size = filesize($filePath);
        $modifiedTime = filemtime($filePath);
        
        // Calculate hashes
        $hasher = new FileHasher();
        $hashes = $hasher->getFileHashes(str_replace(BASE_PATH . '/', '', $filePath), ['md5', 'sha1', 'sha256']);
        
        // Verify checksum if provided
        if ($expectedChecksum) {
            $algorithm = strlen($expectedChecksum) === 32 ? 'md5' : (strlen($expectedChecksum) === 64 ? 'sha256' : 'sha1');
            if (isset($hashes[$algorithm]) && !hash_equals($expectedChecksum, $hashes[$algorithm])) {
                unlink($filePath); // Remove invalid file
                throw new Exception('Checksum verification failed');
            }
        }
        
        return [
            'success' => true,
            'size' => $size,
            'modified_time' => $modifiedTime,
            'hashes' => $hashes,
            'checksum_verified' => $expectedChecksum !== null
        ];
    }
    
    /**
     * Validate pre-release file for release
     */
    public function validatePreReleaseFile($filePath) {
        $fullPath = PRE_RELEASE_PATH . '/' . ltrim($filePath, '/');
        
        if (!file_exists($fullPath)) {
            throw new Exception('Pre-release file not found: ' . $filePath);
        }
        
        // Check file permissions and accessibility
        if (!is_readable($fullPath)) {
            throw new Exception('Pre-release file is not readable: ' . $filePath);
        }
        
        $size = filesize($fullPath);
        $modifiedTime = filemtime($fullPath);
        
        // Calculate basic hash for integrity
        $md5 = md5_file($fullPath);
        
        return [
            'status' => 'valid',
            'size' => $size,
            'modified_time' => $modifiedTime,
            'checksum' => $md5
        ];
    }
}
?>