<?php
/**
 * R2 Download Handler
 * Streams files from R2 through this server
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
    // Keep this function name for backwards compatibility with existing routes.
    log_action('Download initiated', $file_path);

    $object_key = ltrim($file_path, '/');

    try {
        $s3Client = get_s3_client();
        $result = $s3Client->getObject([
            'Bucket' => R2_BUCKET_NAME,
            'Key' => $object_key,
            // Force network streaming so large files are not fully buffered first.
            '@http' => ['stream' => true],
        ]);

        $file_name = basename($file_path);
        $safe_file_name = str_replace(["\r", "\n", '"'], '', $file_name);
        if ($safe_file_name === '') {
            $safe_file_name = 'download.bin';
        }

        $content_type = isset($result['ContentType']) ? (string) $result['ContentType'] : 'application/octet-stream';
        $content_length = isset($result['ContentLength']) ? (int) $result['ContentLength'] : null;

        // Prevent stale/proxy caching and buffering while streaming.
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $safe_file_name . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        if ($content_length !== null) {
            header('Content-Length: ' . $content_length);
        }

        // For fallback mode, keep the indicator header expected by existing clients.
        if ($use_fallback) {
            header('X-Fallback-Mode: 1');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $body = $result['Body'];

        while (!$body->eof()) {
            echo $body->read(1024 * 1024); // 1MB chunks
            flush();

            if (connection_aborted()) {
                break;
            }
        }

        if (is_object($body) && method_exists($body, 'close')) {
            $body->close();
        }

        log_action('Download stream completed', $file_path);
        exit;
    } catch (\Throwable $e) {
        error_log("Failed to stream download for {$file_path}: " . $e->getMessage());
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
        redirect_to_r2($file_path, $use_fallback);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'File parameter required']);
        exit;
    }
}
?>