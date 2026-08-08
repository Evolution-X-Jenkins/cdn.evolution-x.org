<?php
/**
 * Bunny Download Handler
 * Redirects normal downloads to Bunny and streams through this server in proxy mode
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bunny_storage.php';

function handle_download_redirect($file_path, $use_fallback = false) {
    // Log the download attempt
    log_action('Download initiated', $file_path);
    
    // Generate object key
    $object_key = ltrim($file_path, '/');
    
    // Check if user should use fallback domain based on connectivity test or explicit request
    $use_fallback_domain = $use_fallback || should_use_fallback_domain();
    
    // Prefer Bunny public URL generation.
    $download_url = generate_download_url($object_key, DOWNLOAD_URL_EXPIRY, $use_fallback_domain);
    if (!empty($download_url)) {
        log_action('Download URL generated', $file_path);
        return $download_url;
    }

    log_action('Download failed - URL generation error', $file_path);
    return null;
}

function redirect_to_download_backend($file_path, $use_fallback = false) {
    log_action('Download initiated', $file_path);

    if (!$use_fallback) {
        $download_url = handle_download_redirect($file_path, false);

        if ($download_url) {
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Location: ' . $download_url, true, 302);
            exit;
        }

        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to generate download link']);
        exit;
    }

    // Proxy path uses Bunny SDK so restricted clients can still download through this server.
    $object_key = ltrim($file_path, '/');

    try {
        $client = bunny_get_storage_client();
        $tempFile = tempnam(sys_get_temp_dir(), 'bunny_proxy_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to allocate temp file for proxy download');
        }

        $cleanupTempFile = static function() use ($tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        };

        $client->download($object_key, $tempFile);

        $fileName = basename($file_path);
        $safeFileName = str_replace(["\r", "\n", '"'], '', $fileName);
        if ($safeFileName === '') {
            $safeFileName = 'download.bin';
        }

        $contentLength = @filesize($tempFile);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        header('X-Fallback-Mode: 1');
        if ($contentLength !== false) {
            header('Content-Length: ' . (int)$contentLength);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($tempFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temp file for proxy stream');
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                break;
            }

            echo $chunk;
            flush();

            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
        $cleanupTempFile();
        log_action('Download proxy stream completed', $file_path);
        exit;
    } catch (\Throwable $e) {
        error_log("Failed to stream Bunny proxy download for {$file_path}: " . $e->getMessage());
        log_action('Download failed - proxy stream error', $file_path);
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