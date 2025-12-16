<?php
/**
 * Main API Router - Modular Version
 */

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/database.php';

// API Modules
require_once __DIR__ . '/modules/api/download.php';
require_once __DIR__ . '/modules/api/file_operations.php';
require_once __DIR__ . '/modules/api/health.php';
require_once __DIR__ . '/modules/api/push.php';
require_once __DIR__ . '/modules/api/daily_downloads.php';
require_once __DIR__ . '/modules/api/hash_management.php';

// CORS headers
function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    
    // Allow specific origins in production
    $allowedOrigins = [
        'https://evolution-x.org',
        'https://cdn.evolution-x.org',
        'http://localhost:3000',
        'http://localhost:8000'
    ];
    
    if (in_array($origin, $allowedOrigins) || $origin === 'http://localhost:8000') {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header("Access-Control-Allow-Origin: *");
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // 24 hours
}

function jsonResponse($data, $status = 200) {
    setCorsHeaders();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function handleApiRequest() {
    setCorsHeaders();
    
    // Handle preflight requests
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim($_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '', '/');
    
    // Remove query string from path
    if (($pos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $pos);
    }
    
    // Remove 'api/' prefix if present
    $path = preg_replace('#^api/#', '', $path);
    
    $pathParts = array_filter(explode('/', $path));
    $endpoint = array_shift($pathParts);
    
    $result = null;
    
    switch ($endpoint) {
        case 'push':
            $result = handlePushApi($method, $pathParts);
            if (isset($result['APICode'])) {
                // Handle specific API codes with proper HTTP status
                $statusMap = [
                    'T-0002' => 400, // Bad Request
                    'T-0003' => 401, // Unauthorized
                    'T-0004' => 405, // Method Not Allowed
                    'T-0005' => 404, // Not Found
                    'T-0006' => 500, // Internal Server Error
                    'T-0007' => 202  // Accepted
                ];
                $status = $statusMap[$result['APICode']] ?? 200;
                jsonResponse($result, $status);
            }
            break;
            
        case 'download-statistics':
        case 'download-stats':
            $result = handleDownloadStatisticsApi($method, $pathParts);
            break;
            
        case 'daily-downloads':
            $result = handleDailyDownloadsApi($method, $pathParts);
            break;
            
        case 'hash':
        case 'hashes':
            handleHashManagementApi();
            return; // This function handles its own response
            
        case 'daily-summary':
            $result = handleDailyDownloadsSummaryApi($method, $pathParts);
            break;
            
        case 'file-hashes':
            $result = handleFileHashesApi($method, $pathParts);
            break;
            
        case 'file-operations':
            $result = handleFileOperationsApi($method, $pathParts);
            break;
            
        case 'health':
            $result = handleHealthApi($method, $pathParts);
            break;
            
        default:
            $result = [
                'error' => 'Unknown API endpoint',
                'endpoint' => $endpoint,
                'available' => ['push', 'download-statistics', 'daily-downloads', 'daily-summary', 'file-hashes', 'file-operations', 'health']
            ];
            jsonResponse($result, 404);
    }
    
    if ($result !== null) {
        $status = isset($result['success']) && $result['success'] === false ? 400 : 200;
        jsonResponse($result, $status);
    }
}

// Handle the API request
handleApiRequest();