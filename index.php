<?php
/**
 * PHP File Browser for R2 Directory
 * Main router with clean URLs and modular structure
 */

require_once 'modules/setup/config.php';
require_once 'modules/setup/database.php';
require_once 'modules/core/rate_limit.php';

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

if ($clean_path === 'stats') {
    require_once 'stats.php';
    show_stats_page();
    exit;
}

// Block Robots
if (strpos($clean_path, 'robots.txt') === 0) {
    header('Content-Type: text/plain');
    readfile(__DIR__ . '/robots.txt');
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
$is_download_page = false;
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
} elseif (preg_match('/^(.+?)\/download-page$/', $clean_path, $matches)) {
    $is_download_page = true;
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

// Bare file URLs (e.g. /folder/file.zip) should open the countdown page.
if (!$is_download && !$is_download_page && !$is_direct_download && !$is_proxy_download && !$is_stats && $clean_path !== '') {
    $resolved_path = sanitize_path($clean_path, BASE_PATH);
    if (is_file($resolved_path)) {
        $is_download_page = true;
        $target_file = $clean_path;
        $clean_path = dirname($target_file);
        if ($clean_path === '.') {
            $clean_path = '';
        }
    }
}

// Handle download page requests - show download page with countdown
if ($is_download_page) {
    require_once 'modules/core/download.php';
    $file_path = sanitize_path($target_file, BASE_PATH);
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (is_file($file_path)) {
        // Allow automated clients to bypass the countdown page.
        $allowedAgents = ['evoxupdater', 'evox updater', 'wget', 'curl', 'aria2', 'php'];
        $userAgentLower = strtolower($userAgent);
        foreach ($allowedAgents as $agent) {
            if (strpos($userAgentLower, $agent) !== false) {
                log_action('Direct download (user agent bypass)', $target_file);
                $DownloadResult = DownloadRom($target_file, false);
                if (!$DownloadResult) {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'File not found']);
                    exit;
                }
                exit;
            }
        }

        log_action('Download page accessed', $target_file);

        $rateLimitState = enforce_download_rate_limit('download-page', $target_file);
        if (!empty($rateLimitState['blocked'])) {
            render_rate_limit_429_page($rateLimitState, 'download-page');
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

        show_download_page($target_file, $file_path);
        exit;
    } else {
        log_action('Download failed - file not found', $target_file);
        // Return JSON 404 so updater clients get a parseable error, not an HTML redirect
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
}

// Handle direct stream download requests
if ($is_download || $is_direct_download) {
   $DownloadResult = DownloadRom($target_file, false);
   if (!$DownloadResult) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
   }
}

if ($is_proxy_download) {
    $DownloadResult = DownloadRom($target_file, true);
    if (!$DownloadResult) {
          http_response_code(404);
          header('Content-Type: application/json');
          echo json_encode(['error' => 'File not found']);
          exit;
    }
}

// Handle direct download requests - immediate R2 stream-through
function DownloadRom($target_file, $proxy) {
    global $db;
    require_once 'modules/setup/r2_download.php';

    $routeName = $proxy ? 'proxy-download' : 'download';
    $rateLimitState = enforce_download_rate_limit($routeName, $target_file);
    if (!empty($rateLimitState['blocked'])) {
        render_rate_limit_429_page($rateLimitState, $routeName);
    }

    $file_path = sanitize_path($target_file, BASE_PATH);
    if (is_file($file_path)) {
        log_action('Direct download requested', $target_file);

        // Record download statistics for direct/proxy delivery paths.
        $userId = get_user_id();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $fileSize = filesize($file_path);

        try {
            $db->recordDownload($target_file, $userId, $ipAddress, $userAgent, $referer, $fileSize);
        } catch (Exception $e) {
            error_log('Failed to record download: ' . $e->getMessage());
        }

        redirect_to_r2($target_file, $proxy); // false = regular stream / true = stream with fallback marker header
        exit;
    } else {
        log_action('Direct download failed - file not found', $target_file);
	return false;
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
