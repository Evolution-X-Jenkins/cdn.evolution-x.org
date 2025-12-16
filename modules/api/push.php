<?php
/**
 * Push API Module
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../core/file_operations.php';
require_once __DIR__ . '/../core/bucket_cache.php';

function handlePushApi($method, $pathParts) {
    // Clear hashes FIRST, before any validation or error checking
    // This ensures hashes are cleared even if push fails for any reason
    if (!empty($pathParts) && count($pathParts) >= 4) {
        $codename = $pathParts[0];
        $version = $pathParts[2]; 
        $buildType = $pathParts[3];
        // Use relative path for database (no BASE_PATH, no leading slash)
        $dbPath = "$codename/$version/$buildType";
        clearHashesForPath($dbPath);
        error_log("Hash clearing for push attempt: $dbPath");
    }
    
    if ($method !== 'POST') {
        return [
            'status' => 'error',
            'APICode' => 'T-0002'
        ];
    }
    
    // Check for evoxupdater user agent (bypass auth like Python version)
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isEvoxUpdater = strpos($userAgent, 'evoxupdater') !== false;
    $username = 'unknown';
    
    if ($isEvoxUpdater) {
        // Skip authentication for evoxupdater
        $username = "evoxupdater";
        error_log("Using evoxupdater bypass with admin privileges");
    } else {
        // Normal authentication check - simplified for PHP (no session system)
        $authToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
        $authToken = str_replace('Bearer ', '', $authToken);
        if (!validatePushToken($authToken)) {
            return [
                'status' => 'error',
                'APICode' => 'T-0003'
            ];
        }
        $username = "api_user";
    }
    
    try {
        // Handle both JSON payload and URL parameters like the Python version
        if (!empty($pathParts) && count($pathParts) >= 4) {
            // Legacy URL parameter format: /api/push/{codename}/{date}/{version}/{buildType}
            $codename = $pathParts[0];
            $date = $pathParts[1];
            $version = $pathParts[2];
            $buildType = $pathParts[3];
            
            error_log("LEGACY ENDPOINT calling process_push_request");
        } else {
            // Handle both JSON and form-encoded data like Python version
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') === 0) {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!$data) {
                    return [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                }
            } elseif (strpos($contentType, 'application/x-www-form-urlencoded') === 0) {
                $data = $_POST;
                if (!$data) {
                    return [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                }
            } else {
                // Default to JSON for backwards compatibility
                $data = json_decode(file_get_contents('php://input'), true);
                if (!$data) {
                    return [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                }
            }
            
            // Extract parameters from data
            $codename = $data['codename'] ?? '';
            $date = $data['date'] ?? '';
            $version = strval($data['version'] ?? '');
            $buildType = $data['buildType'] ?? '';
            error_log("JSON ENDPOINT calling process_push_request");
        }
        
        $result = processPushRequest($codename, $date, $version, $buildType, $username);
        error_log("process_push_request returned");
        return $result;
        
    } catch (Exception $e) {
        error_log("Unexpected error in push API: " . $e->getMessage());
        return [
            'status' => 'error',
            'APICode' => 'T-0006'
        ];
    }
}

function validatePushToken($token) {
    // Implement your authentication logic here
    // This could check against environment variables, database, etc.
    $validTokens = [
        getenv('PUSH_API_TOKEN'),
        'test-token', // For testing purposes
        // Add more tokens if needed
    ];
    
    return in_array($token, array_filter($validTokens));
}

function processPushRequest($codename, $date, $version, $buildType, $username = 'unknown') {
    // Generate request ID like Python version
    $requestId = (int)(microtime(true) * 1000) . '_' . getmypid() . '_' . $codename . '_' . $date;
    error_log(">>> PROCESS_PUSH_REQUEST START - ID: $requestId");
    
    try {
        // Validate input parameters like Python version
        if (!$codename || !$date || !$version || !$buildType) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Validate build type
        if (!in_array(strtolower($buildType), ['vanilla', 'gapps'])) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
    
        // Validate version
        if (!in_array((int)$version, [14, 15, 16])) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Validate date format
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Define paths like Python version
        $preReleaseRoot = PRE_RELEASE_PATH; // From database.php
        $mainRoot = BASE_PATH;
        
        // Convert date format
        $sourceDate = str_replace('-', '', $date); // 2025-10-30 -> 20251030
        
        // Build paths based on type
        if (strtolower($buildType) === 'gapps') {
            $sourcePath = $preReleaseRoot . "/$codename/$sourceDate";
            $destPath = $mainRoot . "/$codename/$version";
        } else { // vanilla
            $sourcePath = $preReleaseRoot . "/$codename/{$sourceDate}_Vanilla";
            $destPath = $mainRoot . "/$codename/{$version}_vanilla";
        }
        
        error_log("=== PUSH REQUEST START ===");
        error_log("Push request from $username: $codename $date -> $version $buildType");
        error_log("Request ID: $requestId");
        error_log("Source: $sourcePath");
        error_log("Destination: $destPath");
        
        // Enhanced directory checking like Python version
        error_log("Checking if source directory exists: $sourcePath");
        
        try {
            $pathExists = is_dir($sourcePath);
            error_log("is_dir($sourcePath) = " . ($pathExists ? 'true' : 'false'));
            
            if (!$pathExists) {
                // Try alternative checks for mounted directories
                try {
                    $contents = scandir($sourcePath);
                    error_log("Directory accessible via scandir despite is_dir=false");
                    $pathExists = true;
                } catch (Exception $listError) {
                    error_log("Directory not accessible via scandir: " . $listError->getMessage());
                    
                    // Check parent directory
                    $parentPath = dirname($sourcePath);
                    error_log("Checking parent directory: $parentPath");
                    $parentExists = is_dir($parentPath);
                    error_log("Parent directory exists: " . ($parentExists ? 'true' : 'false'));
                    
                    if ($parentExists) {
                        try {
                            $parentContents = scandir($parentPath);
                            error_log("Parent directory contents: " . implode(', ', array_slice($parentContents, 2, 10)));
                        } catch (Exception $parentError) {
                            error_log("Cannot list parent directory: " . $parentError->getMessage());
                        }
                    }
                }
            }
            
            if (!$pathExists) {
                error_log("Source directory not found: $sourcePath");
                return [
                    'status' => 'error',
                    'APICode' => 'T-0005'
                ];
            }
            
        } catch (Exception $checkError) {
            error_log("Error checking source directory $sourcePath: " . $checkError->getMessage());
            return [
                'status' => 'error',
                'APICode' => 'T-0006'
            ];
        }
        
        // Pre-flight validation completed successfully
        // Return immediate response and continue processing in background like Python version
        error_log("Pre-flight validation passed - returning T-0007 and starting background processing");
        
        // Start background processing (simplified for PHP - could use exec() or job queue in production)
        
        // Create destination directory
        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }
        
        // Copy files
        $result = copyRecursively($sourcePath, $destPath);
        
        if ($result) {
            // Invalidate bucket size cache after successful file operation
            $cache = new BucketCache();
            $cache->invalidateCache();
            
            error_log("Background processing completed successfully - cache invalidated - ID: $requestId");
            return [
                'status' => 'started',
                'APICode' => 'T-0007'
            ];
        } else {
            error_log("Background processing failed - ID: $requestId");
            return [
                'status' => 'error',
                'APICode' => 'T-0006'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Unexpected error in process_push_request: " . $e->getMessage());
        error_log("=== PUSH REQUEST EXCEPTION - ID: $requestId ===");
        return [
            'status' => 'error',
            'APICode' => 'T-0006'
        ];
    } finally {
        error_log(">>> PROCESS_PUSH_REQUEST END - ID: $requestId");
    }
}

function copyRecursively($source, $dest) {
    if (!is_dir($source)) {
        return false;
    }
    
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            copy($item, $destPath);
        }
    }
    
    return true;
}

function clearHashesForPath($path) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        // Handle both absolute and relative paths
        if (strpos($path, BASE_PATH) === 0) {
            // Absolute path - convert to relative
            $relativePath = '/' . ltrim(str_replace(BASE_PATH, '', $path), '/');
        } else {
            // Already relative path - ensure no leading slash for database
            $relativePath = ltrim($path, '/');
        }
        
        // Clear hashes for all files in this path (recursive) in download_stat table
        $stmt = $pdo->prepare("UPDATE download_stat SET sha256 = NULL, md5 = NULL WHERE key_path LIKE ?");
        $stmt->execute([$relativePath . '/%']);
        
        $clearedCount = $stmt->rowCount();
        error_log("Cleared hashes for $clearedCount entries in path: $relativePath");
        
        return $clearedCount;
    } catch (Exception $e) {
        error_log("Error clearing hashes for path $path: " . $e->getMessage());
        return false;
    }
}