<?php
/**
 * R2 Download Handler
 * Generates presigned URLs and redirects to R2
 */

require_once __DIR__ . '/config.php';

function handle_r2_download($file_path, $use_fallback = false) {
    // Log the download attempt
    log_action('Download initiated', $file_path);
    
    // Generate object key (remove leading slash for R2)
    $object_key = ltrim($file_path, '/');
    
    // Check if user should use fallback domain based on connectivity test or explicit request
    $use_fallback_domain = $use_fallback || should_use_fallback_domain();
    
    try {
        // Generate presigned URL (valid for 1 hour)
        $presigned_url = generate_presigned_url($object_key, PRESIGNED_URL_EXPIRY, $use_fallback_domain);
        
        // Log successful URL generation
        log_action('Presigned URL generated', $file_path);
        
        // Return the URL for redirect
        return $presigned_url;
        
    } catch (Exception $e) {
        error_log("Failed to generate presigned URL for {$file_path}: " . $e->getMessage());
        log_action('Download failed - URL generation error', $file_path);
        return null;
    }
}

function redirect_to_r2($file_path, $use_fallback = false) {
    $download_url = handle_r2_download($file_path, $use_fallback);
    
    if ($download_url) {
        // Add some headers for better download experience
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // For fallback mode, add additional headers to help with proxies
        if ($use_fallback) {
            header('X-Fallback-Mode: 1');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
        }
        
        // Redirect to the presigned URL
        header('Location: ' . $download_url, true, 302);
        exit;
    } else {
        // Fallback error
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate download link']);
        exit;
    }
}

// Handle direct access to this file
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    if (isset($_GET['file'])) {
        $file_path = $_GET['file'];
        $use_fallback = isset($_GET['fallback']) && $_GET['fallback'] == '1';
        redirect_to_r2($file_path, $use_fallback);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'File parameter required']);
        exit;
    }
}
?>