<?php
/**
 * File Operations API Module
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../core/file_operations.php';

function handleFileHashesApi($method, $pathParts) {
    $fileOps = new FileHasher();
    
    switch ($method) {
        case 'GET':
            $filePath = isset($pathParts[1]) ? $pathParts[1] : '';
            if (empty($filePath)) {
                return [
                    'error' => 'Missing file path',
                    'success' => false
                ];
            }
            
            try {
                $hashes = $fileOps->getFileHashes($filePath);
                return [
                    'success' => true,
                    'data' => $hashes
                ];
            } catch (Exception $e) {
                return [
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }

        case 'POST':
            // Force refresh cache
            $input = json_decode(file_get_contents('php://input'), true);
            $filePath = $input['file_path'] ?? '';
            
            if (empty($filePath)) {
                return [
                    'error' => 'Missing file_path parameter',
                    'success' => false
                ];
            }
            
            try {
                $hashes = $fileOps->getFileHashes($filePath, ['md5', 'sha1', 'sha256'], true);
                return [
                    'success' => true,
                    'data' => $hashes
                ];
            } catch (Exception $e) {
                return [
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }

        case 'DELETE':
            // Clear cache for specific file
            $input = json_decode(file_get_contents('php://input'), true);
            $filePath = $input['file_path'] ?? '';
            
            if (empty($filePath)) {
                return [
                    'error' => 'Missing file_path parameter',
                    'success' => false
                ];
            }
            
            $fileOps->clearCache($filePath);
            return ['success' => true];

        default:
            return [
                'error' => 'Method not allowed',
                'success' => false
            ];
    }
}

function handleFileOperationsApi($method, $pathParts) {
    $db = Database::getInstance();
    $fileOps = new FileHasher();
    
    switch ($method) {
        case 'GET':
            // List recent file operations
            try {
                $operations = $db->getRecentOperations();
                return [
                    'success' => true,
                    'data' => $operations
                ];
            } catch (Exception $e) {
                return [
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $operation = $input['operation'] ?? '';
            
            switch ($operation) {
                case 'copy_from_prerelease':
                    $sourcePath = $input['source_path'] ?? '';
                    $targetPath = $input['target_path'] ?? '';
                    
                    if (empty($sourcePath) || empty($targetPath)) {
                        return [
                            'error' => 'Missing source_path or target_path',
                            'success' => false
                        ];
                    }
                    
                    try {
                        $result = copyFromPreRelease($sourcePath, $targetPath);
                        $db->logOperation('copy_from_prerelease', $sourcePath, $targetPath, $result ? 'success' : 'failed');
                        
                        return [
                            'success' => $result,
                            'message' => $result ? 'File copied successfully' : 'Copy operation failed'
                        ];
                    } catch (Exception $e) {
                        $db->logOperation('copy_from_prerelease', $sourcePath, $targetPath, 'error: ' . $e->getMessage());
                        return [
                            'error' => $e->getMessage(),
                            'success' => false
                        ];
                    }
                    
                default:
                    return [
                        'error' => 'Unknown operation',
                        'success' => false
                    ];
            }

        default:
            return [
                'error' => 'Method not allowed',
                'success' => false
            ];
    }
}

function copyFromPreRelease($sourcePath, $targetPath) {
    $sourceFullPath = PRE_RELEASE_PATH . '/' . ltrim($sourcePath, '/');
    $targetFullPath = BASE_PATH . '/' . ltrim($targetPath, '/');
    
    if (!file_exists($sourceFullPath)) {
        throw new Exception('Source file not found in pre-release');
    }
    
    // Ensure target directory exists
    $targetDir = dirname($targetFullPath);
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    return copy($sourceFullPath, $targetFullPath);
}