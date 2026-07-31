<?php
/**
 * File listing functionality
 */

require_once 'modules/setup/config.php';

function show_file_listing($clean_path) {
    $full_path = sanitize_path($clean_path, BASE_PATH);
    $search_query = trim((string)($_GET['q'] ?? ''));
    
    // Get relative path for display
    $relative_path = str_replace(BASE_PATH, '', $full_path);
    if (empty($relative_path)) {
        $relative_path = '/';
    }
    
    // Read directory contents
    $items = array();
    if (is_dir($full_path)) {
        $files = scandir($full_path);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..' || $file == '.bash_history' || $file == '.cache' || $file == '.config') {
                continue;
            }
            
            $file_path = $full_path . '/' . $file;
            
            // Build clean URL path
            $url_path = $relative_path === '/' ? $file : ltrim($relative_path . '/' . $file, '/');
            
            $item = array(
                'name' => $file,
                'path' => '/' . $url_path,
                'is_dir' => is_dir($file_path),
                'size' => is_file($file_path) ? filesize($file_path) : 0,
                'modified' => filemtime($file_path),
                'icon' => get_file_icon($file, is_dir($file_path))
            );
            
            if ($search_query !== '' && stripos($file, $search_query) === false) {
                continue;
            }
            
            $items[] = $item;
        }
        
        // Sort: directories first, then files, both alphabetically
        usort($items, function($a, $b) {
            if ($a['is_dir'] != $b['is_dir']) {
                return $b['is_dir'] - $a['is_dir'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
    }
    
    $breadcrumb = generate_breadcrumb($relative_path);
    
    include 'templates/list.php';
}
?>