<?php
/**
 * Bunny Download Handler
 * Streams downloads from Bunny Storage through the PHP SDK.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bunny_storage.php';

function redirect_to_download_backend($file_path, $use_fallback = false) {
    log_action('Download initiated', $file_path);

    $downloadUrl = generate_download_url($file_path, DOWNLOAD_URL_EXPIRY, false);
    if (empty($downloadUrl)) {
        error_log("No presigned S3/Bunny URL available for {$file_path}");
        log_action('Download failed - no presigned URL', $file_path);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No presigned download URL available']);
        exit;
    }

    header('Location: ' . $downloadUrl, true, 302);
    exit;
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