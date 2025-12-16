<?php

require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/config.php';

function handleHashManagementApi() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT');
    header('Access-Control-Allow-Headers: Content-Type');

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    try {
        switch ($method) {
            case 'POST':
                switch ($action) {
                    case 'generate':
                        return generateFileHash();
                    case 'regenerate':
                        return regeneratePathHashes();
                    case 'clear':
                        return clearPathHashes();
                    default:
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid action']);
                        return;
                }
            case 'DELETE':
                return deleteFileHash();
            case 'GET':
                return getFileHash();
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function generateFileHash() {
    $filepath = $_POST['filepath'] ?? '';
    $algorithm = $_POST['algorithm'] ?? 'sha256';
    
    if (empty($filepath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Filepath required']);
        return;
    }

    $fullPath = BASE_PATH . '/' . ltrim($filepath, '/');
    
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }

    $hash = generateOptimizedHash($fullPath, $algorithm);
    $fileSize = filesize($fullPath);
    
    // Store in existing download_stat table
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO download_stat (key_path, count, sha256, md5, file_size) 
        VALUES (?, COALESCE((SELECT count FROM download_stat WHERE key_path = ?), 0), ?, ?, ?)
    ");
    
    $md5Hash = ($algorithm === 'md5') ? $hash : hash_file('md5', $fullPath);
    $sha256Hash = ($algorithm === 'sha256') ? $hash : hash_file('sha256', $fullPath);
    
    $stmt->execute([$filepath, $filepath, $sha256Hash, $md5Hash, $fileSize]);

    echo json_encode([
        'success' => true,
        'filepath' => $filepath,
        'hash' => $hash,
        'algorithm' => $algorithm,
        'file_size' => $fileSize,
        'generated_at' => time()
    ]);
}

function regeneratePathHashes() {
    $path = $_POST['path'] ?? '';
    $recursive = filter_var($_POST['recursive'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $algorithm = $_POST['algorithm'] ?? 'sha256';
    
    if (empty($path)) {
        http_response_code(400);
        echo json_encode(['error' => 'Path required']);
        return;
    }

    $fullPath = BASE_PATH . '/' . ltrim($path, '/');
    
    if (!is_dir($fullPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Directory not found']);
        return;
    }

    $results = [];
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    $iterator = $recursive ? 
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath)) :
        new DirectoryIterator($fullPath);

    foreach ($iterator as $file) {
        if ($file->isDot() || $file->isDir()) continue;
        
        $relativePath = '/' . ltrim(str_replace(BASE_PATH, '', $file->getPathname()), '/');
        $hash = generateOptimizedHash($file->getPathname(), $algorithm);
        $fileSize = $file->getSize();
        
        // Update download_stat table with hash info
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO download_stat (key_path, count, sha256, md5, file_size) 
            VALUES (?, COALESCE((SELECT count FROM download_stat WHERE key_path = ?), 0), ?, ?, ?)
        ");
        
        $md5Hash = ($algorithm === 'md5') ? $hash : hash_file('md5', $file->getPathname());
        $sha256Hash = ($algorithm === 'sha256') ? $hash : hash_file('sha256', $file->getPathname());
        
        $stmt->execute([$relativePath, $relativePath, $sha256Hash, $md5Hash, $fileSize]);
        
        $results[] = [
            'filepath' => $relativePath,
            'hash' => $hash,
            'size' => $fileSize
        ];
    }

    echo json_encode([
        'success' => true,
        'path' => $path,
        'files_processed' => count($results),
        'recursive' => $recursive,
        'results' => $results
    ]);
}

function clearPathHashes() {
    $path = $_POST['path'] ?? '';
    $recursive = filter_var($_POST['recursive'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($path)) {
        http_response_code(400);
        echo json_encode(['error' => 'Path required']);
        return;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    if ($recursive) {
        $stmt = $pdo->prepare("UPDATE download_stat SET sha256 = NULL, md5 = NULL WHERE key_path LIKE ?");
        $stmt->execute([rtrim($path, '/') . '/%']);
    } else {
        $stmt = $pdo->prepare("UPDATE download_stat SET sha256 = NULL, md5 = NULL WHERE key_path = ?");
        $stmt->execute([$path]);
    }
    
    $deletedCount = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'path' => $path,
        'recursive' => $recursive,
        'cleared_count' => $deletedCount
    ]);
}

function deleteFileHash() {
    $filepath = $_GET['filepath'] ?? '';
    
    if (empty($filepath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Filepath required']);
        return;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("UPDATE download_stat SET sha256 = NULL, md5 = NULL WHERE key_path = ?");
    $stmt->execute([$filepath]);
    
    echo json_encode([
        'success' => true,
        'filepath' => $filepath,
        'cleared' => $stmt->rowCount() > 0
    ]);
}

function getFileHash() {
    $filepath = $_GET['filepath'] ?? '';
    $path = $_GET['path'] ?? '';
    $recursive = filter_var($_GET['recursive'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    if (!empty($filepath)) {
        // Get single file hash
        $stmt = $pdo->prepare("SELECT key_path, sha256, md5, file_size, count FROM download_stat WHERE key_path = ?");
        $stmt->execute([$filepath]);
        $row = $stmt->fetch();
        
        if ($row && ($row['sha256'] || $row['md5'])) {
            echo json_encode([
                'success' => true,
                'hash_data' => $row
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Hash not found']);
        }
    } elseif (!empty($path)) {
        // Get path hashes
        if ($recursive) {
            $stmt = $pdo->prepare("SELECT key_path, sha256, md5, file_size, count FROM download_stat WHERE key_path LIKE ? AND (sha256 IS NOT NULL OR md5 IS NOT NULL) ORDER BY key_path");
            $stmt->execute([rtrim($path, '/') . '/%']);
        } else {
            $stmt = $pdo->prepare("SELECT key_path, sha256, md5, file_size, count FROM download_stat WHERE key_path = ? AND (sha256 IS NOT NULL OR md5 IS NOT NULL)");
            $stmt->execute([$path]);
        }
        
        $results = $stmt->fetchAll();
        echo json_encode([
            'success' => true,
            'path' => $path,
            'recursive' => $recursive,
            'files' => $results
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Filepath or path required']);
    }
}

function generateOptimizedHash($filepath, $algorithm = 'sha256') {
    $filesize = filesize($filepath);
    
    // For small files (< 10MB), hash entire file
    if ($filesize < 10 * 1024 * 1024) {
        return hash_file($algorithm, $filepath);
    }
    
    // For large files, use signature-based hashing
    $handle = fopen($filepath, 'rb');
    $signature = '';
    
    // First 64KB
    $signature .= fread($handle, 65536);
    
    // Middle 64KB
    if ($filesize > 131072) {
        fseek($handle, intval($filesize / 2) - 32768);
        $signature .= fread($handle, 65536);
        
        // Last 64KB
        fseek($handle, -65536, SEEK_END);
        $signature .= fread($handle, 65536);
    }
    
    fclose($handle);
    
    // Include filesize in signature to detect size changes
    $signature .= $filesize;
    
    return hash($algorithm, $signature);
}

?>