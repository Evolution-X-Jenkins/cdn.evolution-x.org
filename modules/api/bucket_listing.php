<?php
/**
 * Bunny bucket listing API module.
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/bunny_storage.php';
require_once __DIR__ . '/../core/cache.php';

if (!defined('BUNNY_LISTING_CACHE_TTL')) {
    define('BUNNY_LISTING_CACHE_TTL', 60);
}

if (!defined('BUNNY_LISTING_BACKGROUND_WARM_DIR_LIMIT')) {
    define('BUNNY_LISTING_BACKGROUND_WARM_DIR_LIMIT', 10);
}

function bunny_listing_cache_key($relativePath) {
    return 'bunny_listing:' . md5($relativePath);
}

function bunny_listing_refresh_lock_path($relativePath) {
    $lockDir = __DIR__ . '/../../data/cache';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    return $lockDir . '/bunny_refresh_' . md5($relativePath) . '.lock';
}

function bunny_listing_try_acquire_refresh_lock($relativePath) {
    $lockPath = bunny_listing_refresh_lock_path($relativePath);
    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return null;
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return null;
    }

    @ftruncate($handle, 0);
    @fwrite($handle, (string)time());
    @fflush($handle);

    return $handle;
}

function bunny_listing_release_refresh_lock($handle) {
    if (!is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function bunny_listing_warm_missing_directory_caches(CacheManager $cache, array $items) {
    $warmed = 0;

    foreach ($items as $item) {
        if (!is_array($item) || empty($item['is_dir'])) {
            continue;
        }

        $path = bunny_normalize_relative_path((string)($item['path'] ?? '/'));
        $cacheKey = bunny_listing_cache_key($path);
        $cached = $cache->get($cacheKey);
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            continue;
        }

        try {
            $dirItems = bunny_list_directory_items($path);
            $cache->set($cacheKey, ['items' => $dirItems], BUNNY_LISTING_CACHE_TTL);
            $warmed++;
        } catch (Throwable $e) {
            error_log('Bunny warm-cache failed for ' . $path . ': ' . $e->getMessage());
        }

        if ($warmed >= BUNNY_LISTING_BACKGROUND_WARM_DIR_LIMIT) {
            break;
        }
    }
}

function bunny_listing_warm_missing_directory_caches_from_seed(CacheManager $cache, array $items, $allowRecursion) {
    if (!$allowRecursion) {
        return;
    }

    bunny_listing_warm_missing_directory_caches($cache, $items);
}

function bunny_listing_refresh_cache_in_background($relativePath, array $seedItems = []) {
    register_shutdown_function(function() use ($relativePath, $seedItems) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $lockHandle = bunny_listing_try_acquire_refresh_lock($relativePath);
        if ($lockHandle === null) {
            return;
        }

        try {
            $cache = CacheManager::getInstance();
            $freshResult = bunny_list_directory_items_with_source($relativePath);
            $freshItems = $freshResult['items'];
            $cache->set(bunny_listing_cache_key($relativePath), ['items' => $freshItems], BUNNY_LISTING_CACHE_TTL);

            $allowRecursion = ($freshResult['source'] ?? 'bunny') === 'bunny';
            if (!empty($freshItems)) {
                bunny_listing_warm_missing_directory_caches_from_seed($cache, $freshItems, $allowRecursion);
            } elseif (!empty($seedItems)) {
                bunny_listing_warm_missing_directory_caches_from_seed($cache, $seedItems, $allowRecursion);
            }
        } catch (Throwable $e) {
            error_log('Background Bunny refresh failed for ' . $relativePath . ': ' . $e->getMessage());
        } finally {
            bunny_listing_release_refresh_lock($lockHandle);
        }
    });
}

function handleBucketListingApi($method) {
    if ($method !== 'GET') {
        return [
            'success' => false,
            'error' => 'Method not allowed',
        ];
    }

    $requestedPath = isset($_GET['path']) ? (string)$_GET['path'] : '/';
    $relativePath = bunny_normalize_relative_path($requestedPath);

    $cache = CacheManager::getInstance();
    $cacheKey = bunny_listing_cache_key($relativePath);
    $cached = $cache->get($cacheKey);
    if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
        bunny_listing_refresh_cache_in_background($relativePath, $cached['items']);

        return [
            'success' => true,
            'data' => [
                'path' => $relativePath,
                'items' => $cached['items'],
                'source' => 'cache',
            ],
        ];
    }

    try {
        $result = bunny_list_directory_items_with_source($relativePath);
        $items = $result['items'];
        $cache->set($cacheKey, ['items' => $items], BUNNY_LISTING_CACHE_TTL);
        bunny_listing_refresh_cache_in_background($relativePath, $items);

        return [
            'success' => true,
            'data' => [
                'path' => $relativePath,
                'items' => $items,
                'source' => 'bunny',
            ],
        ];
    } catch (Throwable $e) {
        error_log('Bunny listing failed for ' . $relativePath . ': ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'Failed to list files from storage bucket',
            'details' => $e->getMessage(),
        ];
    }
}
