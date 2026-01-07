<?php
/**
 * File Stats Handler
 * Shows detailed file statistics and download information
 */

require_once 'modules/setup/config.php';
require_once 'modules/setup/database.php';
require_once 'modules/core/file_operations.php';

function show_info_page($relative_file_path, $full_file_path) {
    $db = Database::getInstance();
    $hasher = new FileHasher();
    // Get file information
    $file_name = basename($relative_file_path);
    $file_size = filesize($full_file_path);
    $file_size_formatted = format_file_size($file_size);
    $file_modified = filemtime($full_file_path);
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
    
    // Get all-time total downloads
    $total_downloads_stats = $db->getDownloadStats($relative_file_path); // All time
    $total_downloads = $total_downloads_stats['total_downloads'] ?? 0;
    
    // Try to load 7-day breakdown from JSON cache file
    $chart_data = [];
    $daily_downloads = [];
    $cache_file = __DIR__ . '/data/stats_cache/download_stats.json';
    
    if (file_exists($cache_file)) {
        try {
            $cache_data = json_decode(file_get_contents($cache_file), true);
            if (isset($cache_data[$relative_file_path])) {
                // Use cached daily breakdown
                $daily_data = $cache_data[$relative_file_path];
                foreach ($daily_data as $date => $count) {
                    $chart_data[$date] = (int)$count;
                }
            }
        } catch (Exception $e) {
            // Cache file read failed, fall back to DB query
        }
    }
    
    // If no cache, fall back to live query
    if (empty($chart_data)) {
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
    } else {
        // Fill in missing dates in cache data
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            if (!isset($chart_data[$date])) {
                $chart_data[$date] = 0;
            }
        }
    }
    
    // Get file hashes from download_stat table
    $hashes = [];
    $md5_hash = '';
    $sha256_hash = '';
    $hashes_calculating = false;
    
    try {
        // Normalize path for database lookup (remove leading slash)
        $db_path = ltrim($relative_file_path, '/');
        
        // Get hashes from download_stat table
        $stmt = $db->getConnection()->prepare('SELECT md5, sha256, file_size FROM download_stat WHERE `key` = ?');
        $stmt->execute([$db_path]);
        $stat_data = $stmt->fetch();
        
        if ($stat_data && !empty($stat_data['md5']) && !empty($stat_data['sha256'])) {
            // We have hashes cached in database
            $md5_hash = $stat_data['md5'];
            $sha256_hash = $stat_data['sha256'];
            $hashes = array_filter([
                'md5' => $md5_hash,
                'sha256' => $sha256_hash
            ]);
        }
        // If no hashes found, JavaScript will attempt to load from OTA
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