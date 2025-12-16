<?php
/**
 * Health Check API Module
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

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
    
    // Filesystem check
    $checks['filesystem'] = [
        'status' => is_readable(BASE_PATH) && is_writable(BASE_PATH) ? 'healthy' : 'error',
        'message' => is_readable(BASE_PATH) && is_writable(BASE_PATH) ? 'Filesystem accessible' : 'Filesystem access issues',
        'base_path' => BASE_PATH,
        'readable' => is_readable(BASE_PATH),
        'writable' => is_writable(BASE_PATH)
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
    
    // R2 configuration check
    if (defined('R2_ENABLED') && R2_ENABLED) {
        $checks['r2'] = [
            'status' => (getenv('R2_ACCOUNT_ID') && getenv('R2_ACCESS_KEY_ID') && getenv('R2_SECRET_ACCESS_KEY')) ? 'healthy' : 'error',
            'message' => (getenv('R2_ACCOUNT_ID') && getenv('R2_ACCESS_KEY_ID') && getenv('R2_SECRET_ACCESS_KEY')) ? 'R2 configuration present' : 'R2 configuration missing',
            'configured' => (getenv('R2_ACCOUNT_ID') && getenv('R2_ACCESS_KEY_ID') && getenv('R2_SECRET_ACCESS_KEY'))
        ];
    }
    
    // PHP extensions check
    $requiredExtensions = ['pdo', 'pdo_sqlite', 'json', 'curl'];
    $missingExtensions = [];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        }
    }
    
    $checks['php_extensions'] = [
        'status' => empty($missingExtensions) ? 'healthy' : 'error',
        'message' => empty($missingExtensions) ? 'All required extensions loaded' : 'Missing extensions: ' . implode(', ', $missingExtensions),
        'required' => $requiredExtensions,
        'missing' => $missingExtensions
    ];
    
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