<?php

require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/config.php';

/**
 * Store hashes retrieved from OTA or other sources into the database
 * Used by frontend when OTA hashes are loaded
 */
function handleStoreHashesApi($method, $pathParts) {
    $logFile = __DIR__ . '/../../logs/store_hashes_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $log = function($msg) use ($logFile) {
        file_put_contents($logFile, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    };
    
    $log("=== NEW REQUEST ===");
    $log("Method: $method");
    
    if ($method !== 'POST') {
        $log("ERROR: Not POST method");
        return [
            'success' => false,
            'message' => 'Only POST method allowed'
        ];
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $log("Input received: " . json_encode($input));
    
    if (!$input) {
        $log("ERROR: Invalid JSON input");
        return [
            'success' => false,
            'message' => 'Invalid JSON input'
        ];
    }
    
    $filePath = $input['file_path'] ?? '';
    $md5 = $input['md5'] ?? '';
    $sha256 = $input['sha256'] ?? '';
    $source = $input['source'] ?? 'manual';
    
    $log("Parsed: file_path=$filePath, md5=$md5, sha256=$sha256, source=$source");
    
    if (empty($filePath) || (empty($md5) && empty($sha256))) {
        $log("ERROR: Missing required fields");
        return [
            'success' => false,
            'message' => 'File path and at least one hash required'
        ];
    }
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $log("Database connection established");
        
        // Normalize file path
        $normalizedPath = ltrim($filePath, '/');
        $log("Normalized path: $normalizedPath");
        
        // Get file size if it exists
        $fullPath = BASE_PATH . '/' . $normalizedPath;
        $log("Full path: $fullPath, exists: " . (file_exists($fullPath) ? 'yes' : 'no'));
        $fileSize = file_exists($fullPath) ? filesize($fullPath) : null;
        
        // Get current count if record exists (preserve download count)
        $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';
        $countStmt = $pdo->prepare('SELECT count FROM download_stat WHERE ' . $keyColumn . ' = ?');
        $countStmt->execute([$normalizedPath]);
        $result = $countStmt->fetchColumn();
        $currentCount = ($result !== false && $result !== null) ? (int)$result : 0;
        $log("Current count from DB: $currentCount");
        
        // Use appropriate SQL syntax based on database type
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $sql = '
                INSERT INTO download_stat (`key`, count, md5, sha256, file_size)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    md5 = VALUES(md5),
                    sha256 = VALUES(sha256),
                    file_size = VALUES(file_size)
            ';
        } else {
            // SQLite
            $sql = '
                INSERT INTO download_stat (key_path, count, md5, sha256, file_size)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(key_path) DO UPDATE SET 
                    md5 = excluded.md5,
                    sha256 = excluded.sha256,
                    file_size = excluded.file_size
            ';
        }
        
        $log("Executing SQL: INSERT with path=$normalizedPath, count=$currentCount, md5=$md5, sha256=$sha256, filesize=$fileSize");
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$normalizedPath, $currentCount, $md5, $sha256, $fileSize]);
        
        // Invalidate Redis hash cache so info page picks up fresh values
        require_once __DIR__ . '/../core/cache.php';
        CacheManager::getInstance()->delete('hash:' . $normalizedPath);
        
        $log("SUCCESS: Data inserted/updated");
        
        return [
            'success' => true,
            'message' => "Hashes stored from $source",
            'file_path' => $filePath,
            'md5' => $md5,
            'sha256' => $sha256
        ];
    } catch (Exception $e) {
        $log("EXCEPTION: " . $e->getMessage());
        $log("Stack: " . $e->getTraceAsString());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

?>
