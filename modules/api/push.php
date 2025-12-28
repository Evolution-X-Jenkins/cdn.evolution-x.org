<?php
/**
 * Push API Module
 */

// Enable error logging to a specific file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/push_api.log');

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../core/file_operations.php';
require_once __DIR__ . '/../core/bucket_cache.php';

// Add at the top after opening PHP tag
function logPushApiExit($result, $requestId = null, $context = '') {
    $timestamp = date('Y-m-d H:i:s');
    $id = $requestId ?: 'unknown';
    
    error_log("[$timestamp] PUSH API EXIT - ID: $id");
    error_log("[$timestamp] Exit Context: $context");
    error_log("[$timestamp] Result: " . json_encode($result));
    error_log("[$timestamp] PUSH API REQUEST END - ID: $id");
    error_log(""); // Empty line for readability
}

function handlePushApi($method, $pathParts) {
    // Log all incoming push requests immediately
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestId = uniqid('push_', true);
    
    error_log("[$timestamp] PUSH API REQUEST START - ID: $requestId");
    error_log("[$timestamp] Method: $method, IP: $clientIP");
    error_log("[$timestamp] User-Agent: $userAgent");
    error_log("[$timestamp] Path Parts: " . json_encode($pathParts));
    
    // Log raw input
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        error_log("[$timestamp] Raw POST data: " . substr($rawInput, 0, 500));
    } else {
        error_log("[$timestamp] No POST data received");
    }
    
    // Clear hashes FIRST, before any validation or error checking
    // This ensures hashes are cleared even if push fails for any reason
    if (!empty($pathParts) && count($pathParts) >= 4) {
        $codename = $pathParts[0];
        $version = $pathParts[2]; 
        $buildType = $pathParts[3];
        // Use relative path for database (no BASE_PATH, no leading slash)
        $dbPath = "$codename/$version/$buildType";
        clearHashesForPath($dbPath);
        error_log("[$timestamp] Hash clearing for push attempt: $dbPath");
    }
    
    if ($method !== 'POST') {
        error_log("[$timestamp] PUSH API ERROR: Invalid method $method (T-0002)");
        $result = [
            'status' => 'error',
            'APICode' => 'T-0002'
        ];
        logPushApiExit($result, $requestId, 'Invalid HTTP method');
        return $result;
    }
    
    // Check for evoxupdater user agent
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
            error_log("[$timestamp] PUSH API ERROR: Invalid authentication token (T-0003)");
            $result = [
                'status' => 'error',
                'APICode' => 'T-0003'
            ];
            logPushApiExit($result, $requestId, 'Authentication failed');
            return $result;
        }
        $username = "api_user";
    }
    
    try {
        // Handle both JSON payload and URL parameters
        if (!empty($pathParts) && count($pathParts) >= 4) {
            // Legacy URL parameter format: /api/push/{codename}/{date}/{version}/{buildType}
            $codename = $pathParts[0];
            $date = $pathParts[1];
            $version = $pathParts[2];
            $buildType = $pathParts[3];
            
            error_log("LEGACY ENDPOINT calling process_push_request");
        } else {
            // Handle both JSON and form-encoded data
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') === 0) {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!$data) {
                    error_log("[$timestamp] PUSH API ERROR: Invalid JSON payload (T-0002)");
                    $result = [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                    logPushApiExit($result, $requestId, 'Invalid JSON payload');
                    return $result;
                }
            } elseif (strpos($contentType, 'application/x-www-form-urlencoded') === 0) {
                $data = $_POST;
                if (!$data) {
                    error_log("[$timestamp] PUSH API ERROR: No form data received (T-0002)");
                    $result = [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                    logPushApiExit($result, $requestId, 'No form data received');
                    return $result;
                }
            } else {
                // Default to JSON for backwards compatibility
                $data = json_decode(file_get_contents('php://input'), true);
                if (!$data) {
                    error_log("[$timestamp] PUSH API ERROR: No valid data received (T-0002)");
                    $result = [
                        'status' => 'error',
                        'APICode' => 'T-0002'
                    ];
                    logPushApiExit($result, $requestId, 'No valid data received');
                    return $result;
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
        
        // If result is null, it means the response was already sent directly
        if ($result === null) {
            error_log("Response already sent directly to client - background processing continuing");
            return null; // Don't send another response
        }
        
        logPushApiExit($result, $requestId, 'Normal execution path');
        return $result;
        
    } catch (Exception $e) {
        error_log("Unexpected error in push API: " . $e->getMessage());
        $result = [
            'status' => 'error',
            'APICode' => 'T-0006'
        ];
        logPushApiExit($result, $requestId, 'Exception caught: ' . $e->getMessage());
        return $result;
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
    // Generate request ID
    $requestId = (int)(microtime(true) * 1000) . '_' . getmypid() . '_' . $codename . '_' . $date;
    error_log(">>> PROCESS_PUSH_REQUEST START - ID: $requestId");
    
    try {
        // Validate input parameters
        if (!$codename || !$date || !$version || !$buildType) {
            error_log("[$requestId] VALIDATION ERROR: Missing required parameters - codename:$codename, date:$date, version:$version, buildType:$buildType");
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Validate build type
        if (!in_array(strtolower($buildType), ['vanilla', 'gapps'])) {
            error_log("[$requestId] VALIDATION ERROR: Invalid build type '$buildType' - must be vanilla or gapps");
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
    
        // Validate version
        if (!in_array((int)$version, [14, 15, 16])) {
            error_log("[$requestId] VALIDATION ERROR: Invalid version '$version' - must be 14, 15, or 16");
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Validate date format
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            error_log("[$requestId] VALIDATION ERROR: Invalid date format '$date' - must be Y-m-d format");
            return [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
        }
        
        // Define paths
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
        
        // Enhanced directory checking
        error_log("Checking if source directory exists: $sourcePath");
        
        try {
            $pathExists = is_dir($sourcePath);
            error_log("is_dir($sourcePath) = " . ($pathExists ? 'true' : 'false'));
            
            if (!$pathExists) {
                // Try alternative checks for mounted directories
                // Suppress warnings and check the return value instead
                $contents = @scandir($sourcePath);
                if ($contents !== false) {
                    error_log("Directory accessible via scandir despite is_dir=false");
                    $pathExists = true;
                } else {
                    error_log("Directory not accessible via scandir");
                    
                    // Check parent directory
                    $parentPath = dirname($sourcePath);
                    error_log("Checking parent directory: $parentPath");
                    $parentExists = is_dir($parentPath);
                    error_log("Parent directory exists: " . ($parentExists ? 'true' : 'false'));
                    
                    if ($parentExists) {
                        $parentContents = @scandir($parentPath);
                        if ($parentContents !== false) {
                            error_log("Parent directory contents: " . implode(', ', array_slice($parentContents, 2, 10)));
                        } else {
                            error_log("Cannot list parent directory");
                        }
                    }
                }
            }
            
            if (!$pathExists) {
                error_log("[$requestId] SOURCE ERROR: Directory not found at $sourcePath");
                error_log("[$requestId] Expected source path structure was not accessible");
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
        // Return immediate response and continue processing in background
        error_log("Pre-flight validation passed - returning T-0007 and starting background processing");
        
        // Send immediate T-0007 response and flush to client
        $response = [
            'status' => 'started',
            'APICode' => 'T-0007'
        ];
        
        // Send response immediately and close connection to client
        http_response_code(202); // Accepted
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen(json_encode($response)));
        echo json_encode($response);
        
        // Flush all output to ensure client gets the response
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        
        // Close connection to client but continue processing
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Now do background processing without affecting client response
        error_log("Starting background file copy from $sourcePath to $destPath - ID: $requestId");
        
        // Create destination directory
        if (!is_dir($destPath)) {
            if (!mkdir($destPath, 0755, true)) {
                error_log("Failed to create destination directory: $destPath - ID: $requestId");
            } else {
                error_log("Created destination directory: $destPath - ID: $requestId");
            }
        }
        
        // Copy files in background
        $copyResult = copyRecursively($sourcePath, $destPath);
        
        if ($copyResult) {
            // Invalidate bucket size cache after successful file operation
            $cache = new BucketCache();
            $cache->invalidateCache();
            
            error_log("Background processing completed successfully - cache invalidated - ID: $requestId");
        } else {
            error_log("Background processing failed during file copy - ID: $requestId");
        }
        
        // Don't return anything here since we already sent the response
        return null;
        
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
        $stmt = $pdo->prepare("UPDATE download_stat SET `sha256` = NULL, `md5` = NULL WHERE `key` LIKE ?");
        $stmt->execute([$relativePath . '/%']);
        
        $clearedCount = $stmt->rowCount();
        error_log("Cleared hashes for $clearedCount entries in path: $relativePath");
        
        return $clearedCount;
    } catch (Exception $e) {
        error_log("Error clearing hashes for path $path: " . $e->getMessage());
        return false;
    }
}