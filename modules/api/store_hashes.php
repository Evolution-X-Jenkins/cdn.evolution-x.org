<?php

require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/config.php';

/**
 * Store hashes retrieved from OTA or other sources into the database
 * Used by frontend when OTA hashes are loaded
 */
function handleStoreHashesApi($method, $pathParts) {
    if ($method !== 'POST') {
        return [
            'success' => false,
            'message' => 'Only POST method allowed'
        ];
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        return [
            'success' => false,
            'message' => 'Invalid JSON input'
        ];
    }
    
    $filePath = $input['file_path'] ?? '';
    $md5 = $input['md5'] ?? '';
    $sha256 = $input['sha256'] ?? '';
    $source = $input['source'] ?? 'manual';
    
    if (empty($filePath) || (empty($md5) && empty($sha256))) {
        return [
            'success' => false,
            'message' => 'File path and at least one hash required'
        ];
    }
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        // Normalize file path
        $normalizedPath = ltrim($filePath, '/');
        
        // Update or insert into download_stat table
        $stmt = $pdo->prepare('
            INSERT INTO download_stat (`key`, count, md5, sha256, file_size, last_updated)
            VALUES (?, COALESCE((SELECT count FROM download_stat WHERE `key` = ?), 0), ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
                md5 = VALUES(md5),
                sha256 = VALUES(sha256),
                last_updated = CURRENT_TIMESTAMP
        ');
        
        $fullPath = BASE_PATH . '/' . $normalizedPath;
        $fileSize = file_exists($fullPath) ? filesize($fullPath) : null;
        
        $stmt->execute([$normalizedPath, $normalizedPath, $md5, $sha256, $fileSize]);
        
        return [
            'success' => true,
            'message' => "Hashes stored from $source",
            'file_path' => $filePath,
            'md5' => $md5,
            'sha256' => $sha256
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

?>
