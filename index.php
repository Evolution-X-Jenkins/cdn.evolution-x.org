<?php
/**
 * PHP File Browser for R2 Directory
 * Main router with clean URLs and modular structure
 */

require_once 'modules/setup/config.php';
require_once 'modules/setup/database.php';

// Initialize database
$db = Database::getInstance();

// Parse the URL path
$request_uri = $_SERVER['REQUEST_URI'];
$path_info = parse_url($request_uri, PHP_URL_PATH);

// Remove leading slash and decode URL
$clean_path = ltrim($path_info, '/');
$clean_path = urldecode($clean_path);

// Check for API routes
if (strpos($clean_path, 'api/') === 0) {
    require_once 'api.php';
    exit;
}

// Check for special pages
if ($clean_path === 'health') {
    require_once 'modules/core/health.php';
    show_health_page();
    exit;
}

// Check for pre-release pages
if (strpos($clean_path, 'pre-release') === 0) {
    $preReleasePath = substr($clean_path, strlen('pre-release'));
    $preReleasePath = ltrim($preReleasePath, '/');
    
    require_once 'modules/core/file_operations.php';
    $fileOps = new FileHasher();
    
    try {
        $files = $fileOps->listPreReleaseFiles($preReleasePath);
        $page_title = 'Pre-Release Files - ' . ($preReleasePath ?: 'Root');
        include 'templates/pre_release_list.php';
    } catch (Exception $e) {
        header('HTTP/1.0 404 Not Found');
        echo '404 - Directory not found';
    }
    exit;
}

// Check if this is a download or stats request
$is_download = false;
$is_direct_download = false;
$is_proxy_download = false;
$is_stats = false;
$target_file = '';
if (preg_match('/^(.+?)\/download$/', $clean_path, $matches)) {
    $is_download = true;
    $target_file = $matches[1];
    $clean_path = dirname($target_file);
    if ($clean_path === '.') {
        $clean_path = '';
    }
} elseif (preg_match('/^(.+?)\/direct-download$/', $clean_path, $matches)) {
    $is_direct_download = true;
    $target_file = $matches[1];
    $clean_path = dirname($target_file);
    if ($clean_path === '.') {
        $clean_path = '';
    }
} elseif (preg_match('/^(.+?)\/proxy-download$/', $clean_path, $matches)) {
    $is_proxy_download = true;
    $target_file = $matches[1];
    $clean_path = dirname($target_file);
    if ($clean_path === '.') {
        $clean_path = '';
    }
} elseif (preg_match('/^(.+?)\/stats$/', $clean_path, $matches)) {
    $is_stats = true;
    $target_file = $matches[1];
    $clean_path = dirname($target_file);
    if ($clean_path === '.') {
        $clean_path = '';
    }
}

// Handle download requests - show download page with countdown
if ($is_download) {
    require_once 'modules/core/download.php';
    $file_path = sanitize_path($target_file, BASE_PATH);
    if (is_file($file_path)) {
        log_action('Download page accessed', $target_file);
        
        // Check for user agents that should bypass countdown
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $bypassCountdown = false;
        
        // Allow specific user agents to bypass countdown
        $allowedAgents = ['evoxupdater', 'wget', 'curl', 'aria2', 'php'];
        $userAgentLower = strtolower($userAgent);
        foreach ($allowedAgents as $agent) {
            if (strpos($userAgentLower, $agent) !== false) {
                $bypassCountdown = true;
                break;
            }
        }
        
        // Record download statistics
        $userId = get_user_id();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $fileSize = filesize($file_path);
        
        try {
            $db->recordDownload($target_file, $userId, $ipAddress, $userAgent, $referer, $fileSize);
        } catch (Exception $e) {
            error_log('Failed to record download: ' . $e->getMessage());
        }
        
        // If bypass is enabled, redirect directly to download
        if ($bypassCountdown) {
            log_action('Direct download (user agent bypass)', $target_file);
            header('Location: ' . $target_file . '/direct-download');
            exit;
        }
        
        show_download_page($target_file, $file_path);
        exit;
    } else {
        log_action('Download failed - file not found', $target_file);
        // File not found, redirect to directory
        header('Location: /' . dirname($target_file));
        exit;
    }
}

// Handle direct download requests - immediate R2 redirect
if ($is_direct_download) {
    require_once 'modules/setup/r2_download.php';
    $file_path = sanitize_path($target_file, BASE_PATH);
    if (is_file($file_path)) {
        log_action('Direct download requested', $target_file);
        redirect_to_r2($target_file, false); // false = regular R2 download
        exit;
    } else {
        log_action('Direct download failed - file not found', $target_file);
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
}

// Handle proxy download requests
if ($is_proxy_download) {
    require_once 'modules/setup/r2_download.php';
    $file_path = sanitize_path($target_file, BASE_PATH);
    if (is_file($file_path)) {
        log_action('Proxy download requested', $target_file);
        redirect_to_r2($target_file, true); // true = use fallback/proxy
        exit;
    } else {
        log_action('Proxy download failed - file not found', $target_file);
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
}

// Handle stats requests
if ($is_stats) {
    require_once 'info.php';
    $file_path = sanitize_path($target_file, BASE_PATH);
    if (is_file($file_path)) {
        log_action('File stats accessed', $target_file);
        show_info_page($target_file, $file_path);
        exit;
    } else {
        log_action('File stats failed - file not found', $target_file);
        // File not found, redirect to directory
        header('Location: /' . dirname($target_file));
        exit;
    }
}

// Handle file listing
require_once 'list.php';
log_action('Directory browsed', $clean_path ?: '/');
show_file_listing($clean_path);

