<?php
/**
 * File Stats Handler
 * Shows detailed file statistics and download information
 */

require_once 'modules/setup/config.php';
require_once 'modules/setup/database.php';
require_once 'modules/setup/bunny_storage.php';
require_once 'modules/core/file_operations.php';
require_once 'modules/core/cache.php';
require_once 'modules/core/manifest_index.php';

function show_info_page($relative_file_path, $full_file_path) {
    $db = Database::getInstance();
    $hasher = new FileHasher();
    // Get file information
    $file_name = basename($relative_file_path);
    $file_size = 0;
    $file_modified = 0;

    if (is_file($full_file_path)) {
        $file_size = (int)filesize($full_file_path);
        $file_modified = (int)filemtime($full_file_path);
    } else {
        try {
            $lookupMeta = [];
            $manifestEntry = listing_lookup_path_metadata('/' . ltrim($relative_file_path, '/'), $db->getConnection(), $lookupMeta);
            if (is_array($manifestEntry) && empty($manifestEntry['is_dir'])) {
                $file_size = (int)($manifestEntry['size'] ?? 0);
                $file_modified = (int)($manifestEntry['modified_at'] ?? 0);
            }
        } catch (Exception $e) {
            error_log('Stats metadata lookup failed for manifest entry ' . $relative_file_path . ': ' . $e->getMessage());
        }

        // If manifest is stale/missing, query Bunny parent listing for basic metadata.
        if ($file_size <= 0 || $file_modified <= 0) {
            try {
                $normalizedPath = bunny_normalize_relative_path('/' . ltrim((string)$relative_file_path, '/'));
                $parentPath = dirname($normalizedPath);
                if ($parentPath === '.' || $parentPath === '') {
                    $parentPath = '/';
                }
                if ($parentPath[0] !== '/') {
                    $parentPath = '/' . $parentPath;
                }

                $targetName = basename($normalizedPath);
                $items = bunny_list_directory_items($parentPath);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if ((string)($item['name'] ?? '') === $targetName && empty($item['is_dir'])) {
                        $file_size = (int)($item['size'] ?? $file_size);
                        $file_modified = (int)($item['modified_at'] ?? $file_modified);
                        break;
                    }
                }
            } catch (Throwable $e) {
                error_log('Stats metadata lookup failed for Bunny item ' . $relative_file_path . ': ' . $e->getMessage());
            }
        }
    }

    if ($file_modified <= 0) {
        $file_modified = time();
    }

    $file_size_formatted = format_file_size($file_size);
    $file_modified_formatted = date('Y-m-d H:i:s', $file_modified);
    
    // Get parent directory for back navigation
    $parent_dir = dirname($relative_file_path);
    if ($parent_dir === '.' || $parent_dir === '') {
        $parent_dir = '/';
    } else {
        $parent_dir = '/' . ltrim($parent_dir, '/');
    }
    
    // Get file extension and type
    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $file_type = get_file_type($file_extension);
    
    // Get all-time total downloads (cached 5 min)
    $cache_dl_key = 'dlcount:' . ltrim($relative_file_path, '/');
    $cache_for_info = CacheManager::getInstance();
    $cached_dl = $cache_for_info->get($cache_dl_key);
    if ($cached_dl !== null) {
        $total_downloads = (int)$cached_dl;
    } else {
        $total_downloads_stats = $db->getDownloadStats($relative_file_path);
        $total_downloads = $total_downloads_stats['total_downloads'] ?? 0;
        $cache_for_info->set($cache_dl_key, $total_downloads, 300);
    }
    
    // Try to load 7-day breakdown from cache (Redis → File → DB query)
    $chart_data = [];
    $daily_downloads = [];
    $cache = CacheManager::getInstance();
    $cache_key = 'stats7d:' . ltrim($relative_file_path, '/');
    
    // Try cache first
    $cached_data = $cache->get($cache_key);
    
    if ($cached_data !== null) {
        // Use cached daily breakdown
        foreach ($cached_data as $date => $count) {
            $chart_data[$date] = (int)$count;
        }
    } else {
        // Fall back to live database query
        $download_stats = $db->getDownloadStats($relative_file_path, 7); // 7 days
        $daily_downloads = $download_stats['daily_downloads'] ?? [];
        
        // Create complete 7-day chart data (fill missing days with 0)
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chart_data[$date] = 0;
        }
        
        // Fill in actual download data
        foreach ($daily_downloads as $day) {
            if (isset($chart_data[$day['download_date']])) {
                $chart_data[$day['download_date']] = (int)$day['downloads'];
            }
        }

        // Cache per-file 7-day series to avoid repeated aggregate queries.
        $cache->set($cache_key, $chart_data, DOWNLOAD_STATS_CACHE_TTL);
    }
    
    // Ensure we have all 7 days even if cache was partial
    if (empty($chart_data)) {
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chart_data[$date] = 0;
        }
    } else {
        // Fill in missing dates
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            if (!isset($chart_data[$date])) {
                $chart_data[$date] = 0;
            }
        }
    }

    // Ensure chronological order for chart rendering (oldest -> newest)
    ksort($chart_data);
    
    // Get file hashes from download_stat table
    $hashes = [];
    $md5_hash = '';
    $sha256_hash = '';
    $hashes_calculating = false;
    
    try {
        // Normalize path for database lookup (remove leading slash)
        $db_path = ltrim($relative_file_path, '/');
        $hash_cache_key = 'hash:' . $db_path;
        $hash_cache = CacheManager::getInstance();
        $cached_hashes = $hash_cache->get($hash_cache_key);

        if ($cached_hashes !== null) {
            $md5_hash    = $cached_hashes['md5']    ?? '';
            $sha256_hash = $cached_hashes['sha256'] ?? '';
            $hashes = array_filter(['md5' => $md5_hash, 'sha256' => $sha256_hash]);
        } else {
            // Get hashes from download_stat table
            $stmt = $db->getConnection()->prepare('SELECT md5, sha256, file_size FROM download_stat WHERE `key` = ?');
            $stmt->execute([$db_path]);
            $stat_data = $stmt->fetch();

            if ($stat_data && !empty($stat_data['md5']) && !empty($stat_data['sha256'])) {
                $md5_hash    = $stat_data['md5'];
                $sha256_hash = $stat_data['sha256'];
                $hashes = array_filter(['md5' => $md5_hash, 'sha256' => $sha256_hash]);
                // Cache for 24 hours — hashes rarely change
                $hash_cache->set($hash_cache_key, ['md5' => $md5_hash, 'sha256' => $sha256_hash], 86400);
            }
            // If no hashes found, JavaScript will attempt to load from OTA
        }
    } catch (Exception $e) {
        error_log('Failed to get file hashes: ' . $e->getMessage());
    }
    
    // Pass variables to template
    include 'templates/info.php';
}

function get_download_count($file_path) {
    // Simple implementation - count download entries in log file
    $log_file = LOG_FILE;
    if (!file_exists($log_file)) {
        return 0;
    }
    
    $count = 0;
    $search_terms = [
        "Download initiated - $file_path",
        "Download page accessed - $file_path"
    ];
    
    $handle = fopen($log_file, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            foreach ($search_terms as $term) {
                if (strpos($line, $term) !== false) {
                    $count++;
                    break; // Only count once per line
                }
            }
        }
        fclose($handle);
    }
    
    return $count;
}

function get_file_type($extension) {
    $types = [
        'zip' => 'ROM zip',
        'rar' => 'ROM zip',
        '7z' => 'ROM zip',
        'tar' => 'ROM zip',
        'gz' => 'ROM zip',
        'img' => 'Install Image',
        'iso' => 'Disk Image',
        'apk' => 'Android Package',
        'txt' => 'Text File',
        'log' => 'Log File',
        'md' => 'Markdown',
        'json' => 'JSON Data',
        'xml' => 'XML Data',
        'pdf' => 'PDF Document',
        'jpg' => 'JPEG Image',
        'jpeg' => 'JPEG Image',
        'png' => 'PNG Image',
        'gif' => 'GIF Image',
        'mp4' => 'MP4 Video',
        'mkv' => 'Matroska Video',
        'avi' => 'AVI Video',
        'mp3' => 'MP3 Audio',
        'flac' => 'FLAC Audio',
        'wav' => 'WAV Audio',
    ];
    
    return $types[$extension] ?? 'Unknown';
}
?>