<?php
/**
 * File listing functionality
 */

require_once 'modules/setup/config.php';
require_once 'modules/core/manifest_index.php';
require_once 'modules/core/cache.php';

function show_file_listing($clean_path) {
    $requested_path = trim((string)$clean_path);
    $relative_path = listing_normalize_relative_path($requested_path);
    $search_query = trim((string)($_GET['q'] ?? ''));

    $cache = CacheManager::getInstance();
    $cache_key = 'bunny_listing:' . md5($relative_path);
    $cached_listing = $cache->get($cache_key);

    $initial_items = [];
    if (is_array($cached_listing) && isset($cached_listing['items']) && is_array($cached_listing['items'])) {
        $initial_items = $cached_listing['items'];
    } else {
        $manifest_meta = [];
        $manifest_items = listing_load_items_from_manifest($relative_path, $manifest_meta);
        if (is_array($manifest_items)) {
            $initial_items = $manifest_items;
        }
    }

    if ($search_query !== '' && !empty($initial_items)) {
        $initial_items = array_values(array_filter($initial_items, function($item) use ($search_query) {
            return stripos((string)($item['name'] ?? ''), $search_query) !== false;
        }));
    }

    $breadcrumb = generate_breadcrumb($relative_path);

    include 'templates/list.php';
}
?>