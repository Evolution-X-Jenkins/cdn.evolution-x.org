<?php
/**
 * File listing functionality
 */

require_once 'modules/setup/config.php';
require_once 'modules/core/cache.php';
require_once 'modules/core/manifest_index.php';
require_once 'modules/setup/database.php';

function show_file_listing($clean_path) {
    $requested_path = trim((string)$clean_path);
    $relative_path = listing_normalize_relative_path($requested_path);
    $search_query = trim((string)($_GET['q'] ?? ''));
    
    $cache = CacheManager::getInstance();
    $cache_key = 'directory_listing_manifest:' . md5($relative_path);
    $cached_listing = $cache->get($cache_key);

    if ($cached_listing !== null && isset($cached_listing['items']) && is_array($cached_listing['items'])) {
        $items = $cached_listing['items'];
        $listing_source = (string)($cached_listing['source'] ?? 'cache');
        $listing_generated_at = (string)($cached_listing['generated_at'] ?? '');
    } else {
        $items = [];
        $listing_source = 'manifest_json';
        $listing_generated_at = '';

        $manifest_meta = [];
        $manifest_items = listing_load_items_from_manifest($relative_path, $manifest_meta);
        if (is_array($manifest_items)) {
            $items = $manifest_items;
            $listing_source = (string)($manifest_meta['source'] ?? 'manifest_json');
            $listing_generated_at = (string)($manifest_meta['generated_at'] ?? '');
        } else {
            $db_items = null;

            if (LISTING_ENABLE_DB_FALLBACK) {
                try {
                    $db_meta = [];
                    $pdo = Database::getInstance()->getConnection();
                    $db_items = listing_load_items_from_db($relative_path, $pdo, $db_meta);
                    if (is_array($db_items)) {
                        $items = $db_items;
                        $listing_source = (string)($db_meta['source'] ?? 'manifest_db');
                        $listing_generated_at = (string)($db_meta['generated_at'] ?? '');
                    }
                } catch (Exception $e) {
                    error_log('Listing DB fallback failed: ' . $e->getMessage());
                }
            }

            if (!is_array($db_items) && LISTING_ENABLE_LIVE_SCAN_FALLBACK) {
                $items = listing_live_scan_items($relative_path);
                $listing_source = 'live_scan';
                $listing_generated_at = gmdate('c');
            }
        }

        $cache->set($cache_key, [
            'items' => $items,
            'source' => $listing_source,
            'generated_at' => $listing_generated_at,
        ], LISTING_MANIFEST_CACHE_TTL);
    }

    if ($search_query !== '') {
        $items = array_values(array_filter($items, function($item) use ($search_query) {
            return stripos($item['name'], $search_query) !== false;
        }));
    }
    
    $breadcrumb = generate_breadcrumb($relative_path);
    
    include 'templates/list.php';
}
?>