<?php
/**
 * Health Check API Module
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/bunny_storage.php';
require_once __DIR__ . '/../core/health.php';

function handleHealthApi($method, $pathParts) {
    switch ($method) {
        case 'GET':
            return performHealthChecks();
            
        default:
            return [
                'error' => 'Method not allowed',
                'success' => false
            ];
    }
}

function performHealthChecks() {
    $checks = [];
    
    // Database check
    try {
        $db = Database::getInstance();
        $checks['database'] = [
            'status' => 'healthy',
            'message' => 'Database connection successful'
        ];
    } catch (Exception $e) {
        $checks['database'] = [
            'status' => 'error',
            'message' => 'Database connection failed: ' . $e->getMessage()
        ];
    }
    
    // Local filesystem check (informational in Bunny-backed deployments).
    $baseReadable = is_readable(BASE_PATH);
    $baseWritable = is_writable(BASE_PATH);
    $checks['filesystem'] = [
        'status' => ($baseReadable && $baseWritable) ? 'healthy' : 'warning',
        'message' => ($baseReadable && $baseWritable) ? 'Local filesystem accessible' : 'Local filesystem limited (Bunny-backed mode may still be healthy)',
        'base_path' => BASE_PATH,
        'readable' => $baseReadable,
        'writable' => $baseWritable
    ];
    
    // Pre-release path check
    if (defined('PRE_RELEASE_PATH')) {
        $checks['prerelease'] = [
            'status' => file_exists(PRE_RELEASE_PATH) && is_readable(PRE_RELEASE_PATH) ? 'healthy' : 'warning',
            'message' => file_exists(PRE_RELEASE_PATH) && is_readable(PRE_RELEASE_PATH) ? 'Pre-release path accessible' : 'Pre-release path issues',
            'path' => PRE_RELEASE_PATH,
            'exists' => file_exists(PRE_RELEASE_PATH),
            'readable' => file_exists(PRE_RELEASE_PATH) ? is_readable(PRE_RELEASE_PATH) : false
        ];
    }
    
    // Bunny storage configuration and connectivity check.
    $checks['bunny_storage'] = performBunnyHealthCheck();

    // Manifest-backed bucket status check.
    $bucketStatus = check_bucket_status();
    $checks['bucket_manifest'] = [
        'status' => $bucketStatus['status'] ?? 'warning',
        'message' => $bucketStatus['message'] ?? 'Manifest status unavailable',
        'details' => $bucketStatus['details'] ?? []
    ];
    
    // PHP extension checks should follow configured DB backend.
    $requiredExtensions = ['pdo', 'json'];
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $requiredExtensions[] = 'pdo_mysql';
    } else {
        $requiredExtensions[] = 'pdo_sqlite';
    }

    $optionalExtensions = ['curl'];
    $missingExtensions = [];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        }
    }

    $missingOptionalExtensions = [];
    foreach ($optionalExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingOptionalExtensions[] = $ext;
        }
    }
    
    $checks['php_extensions'] = [
        'status' => empty($missingExtensions) ? 'healthy' : 'error',
        'message' => empty($missingExtensions) ? 'All required extensions loaded' : 'Missing extensions: ' . implode(', ', $missingExtensions),
        'required' => $requiredExtensions,
        'missing' => $missingExtensions,
        'optional' => $optionalExtensions,
        'optional_missing' => $missingOptionalExtensions
    ];
    
    // Add push queue status
    try {
        $pushQueueStatus = check_push_queue_status();
        $checks['push_queue'] = [
            'status' => $pushQueueStatus['status'],
            'message' => $pushQueueStatus['message'],
            'details' => $pushQueueStatus['details']
        ];
    } catch (Exception $e) {
        $checks['push_queue'] = [
            'status' => 'error',
            'message' => 'Push queue check failed',
            'details' => ['error' => $e->getMessage()]
        ];
    }
    
    // Determine overall status
    $overallStatus = 'healthy';
    foreach ($checks as $check) {
        if ($check['status'] === 'error') {
            $overallStatus = 'error';
            break;
        } elseif ($check['status'] === 'warning' && $overallStatus === 'healthy') {
            $overallStatus = 'warning';
        }
    }
    
    return [
        'success' => true,
        'status' => $overallStatus,
        'timestamp' => date('c'),
        'checks' => $checks,
        'version' => [
            'php' => PHP_VERSION,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
        ]
    ];
}

function performBunnyHealthCheck() {
    $zone = trim((string)BUNNY_STORAGE_ZONE);
    $accessKey = trim((string)BUNNY_STORAGE_ACCESS_KEY);
    $configured = ($zone !== '' && $zone !== 'your-storage-zone' && $accessKey !== '' && $accessKey !== 'your-bunny-storage-access-key');

    if (!$configured) {
        return [
            'status' => 'error',
            'message' => 'Bunny storage configuration missing',
            'configured' => false,
            'details' => [
                'zone' => $zone,
                'region' => (string)BUNNY_STORAGE_REGION,
            ]
        ];
    }

    try {
        $items = bunny_list_directory_items('/');
        return [
            'status' => 'healthy',
            'message' => 'Bunny storage accessible',
            'configured' => true,
            'details' => [
                'zone' => $zone,
                'region' => (string)BUNNY_STORAGE_REGION,
                'root_items' => is_array($items) ? count($items) : 0,
            ]
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'message' => 'Bunny storage connectivity failed',
            'configured' => true,
            'details' => [
                'zone' => $zone,
                'region' => (string)BUNNY_STORAGE_REGION,
                'error' => $e->getMessage(),
            ]
        ];
    }
}