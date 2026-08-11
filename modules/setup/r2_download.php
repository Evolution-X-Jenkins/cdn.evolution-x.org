<?php
/**
 * Bunny Download Handler
 * Streams downloads from Bunny Storage through the PHP SDK.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bunny_storage.php';

function redirect_to_download_backend($file_path, $use_fallback = false) {
    log_action('Download initiated', $file_path);

    try {
        bunny_stream_download_file(ltrim($file_path, '/'), $file_path, $use_fallback);
        log_action('Download stream completed', $file_path);
        exit;
    } catch (\Throwable $e) {
        error_log("Failed to stream Bunny download for {$file_path}: " . $e->getMessage());
        log_action('Download failed - stream error', $file_path);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to stream file']);
        exit;
    }
}

// Handle direct access to this file
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    if (isset($_GET['file'])) {
        $file_path = $_GET['file'];
        $use_fallback = isset($_GET['fallback']) && $_GET['fallback'] == '1';
        redirect_to_download_backend($file_path, $use_fallback);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'File parameter required']);
        exit;
    }
}
?>