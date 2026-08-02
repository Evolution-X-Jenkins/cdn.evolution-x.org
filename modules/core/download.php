<?php
/**
 * Download page functionality
 */

require_once __DIR__ . '/../setup/config.php';

function show_download_page($file_path, $full_file_path, $known_file_size = null) {
    $file_name = basename($file_path);
    if (is_int($known_file_size) && $known_file_size >= 0) {
        $bytes = $known_file_size;
    } elseif (is_file($full_file_path)) {
        $detected = filesize($full_file_path);
        $bytes = ($detected !== false) ? (int)$detected : 0;
    } else {
        $bytes = 0;
    }

    $file_size = format_size($bytes);
    $parent_dir = '/' . dirname($file_path);
    if ($parent_dir === '/.') {
        $parent_dir = '/';
    }
    
    log_action('Download countdown started', $file_path);
    include 'templates/download.php';
}
?>