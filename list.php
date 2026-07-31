<?php
/**
 * File listing functionality
 */

require_once 'modules/setup/config.php';
require_once 'modules/core/cache.php';

function show_file_listing($clean_path) {
    $full_path = sanitize_path($clean_path, BASE_PATH);
    $search_query = trim((string)($_GET['q'] ?? ''));
    
    // Get relative path for display
    $relative_path = str_replace(BASE_PATH, '', $full_path);
    if (empty($relative_path)) {
        $relative_path = '/';
    }
    
    $cache = CacheManager::getInstance();
    $cache_key = 'directory_listing:' . md5($full_path . '|' . $search_query);
    $cached_listing = $cache->get($cache_key);

    if ($cached_listing !== null && isset($cached_listing['items'])) {
        $items = $cached_listing['items'];
    } else {
        // Read directory contents
        $items = array();
        if (is_dir($full_path)) {
            $files = scandir($full_path);
            foreach ($files as $file) {
                if ($file == '.' || $file == '..' || $file == '.bash_history' || $file == '.cache' || $file == '.config') {
                    continue;
                }
                
                $file_path = $full_path . '/' . $file;
                $stat = @stat($file_path);
                $is_dir = $stat !== false && (($stat['mode'] & 0170000) === 0040000);
                $is_file = $stat !== false && (($stat['mode'] & 0170000) === 0100000);
                $file_size = $is_file ? (int)$stat['size'] : 0;
                $file_modified = $stat !== false ? (int)$stat['mtime'] : 0;
                
                // Build clean URL path
                $url_path = $relative_path === '/' ? $file : ltrim($relative_path . '/' . $file, '/');
                
                $item = array(
                    'name' => $file,
                    'path' => '/' . $url_path,
                    'is_dir' => $is_dir,
                    'size' => $file_size,
                    'modified' => $file_modified,
                    'icon' => get_file_icon($file, $is_dir)
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

        // Cache the result for 4 hours (hours * minutes * seconds)
        $cache->set($cache_key, ['items' => $items], 4 * 60 * 60);
    }
    
    $breadcrumb = generate_breadcrumb($relative_path);
    
    include 'templates/list.php';
}
?>