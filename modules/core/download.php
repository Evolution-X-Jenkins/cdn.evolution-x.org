<?php
/**
 * Download page functionality
 */

require_once __DIR__ . '/../setup/config.php';

function show_download_page($file_path, $full_file_path) {
    $file_name = basename($file_path);
    $file_size = format_size(filesize($full_file_path));
    $parent_dir = '/' . dirname($file_path);
    if ($parent_dir === '/.') {
        $parent_dir = '/';
    }
    
    log_action('Download countdown started', $file_path);
    include 'templates/download.php';
}
?>