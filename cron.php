<?php
/**
 * Cron Job Script
 * Run this script periodically via crontab to maintain cache and perform maintenance tasks
 * 
 * Usage: php /path/to/cron.php
 * Crontab example: 0,15,30,45 * * * * php /home/aidan/git/php_filebrowser/cron.php
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/core/bucket_cache.php';
require_once __DIR__ . '/modules/setup/database.php';

// Setup logging and cache directory
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

$statsDir = __DIR__ . '/data/stats_cache';
if (!is_dir($statsDir)) {
    mkdir($statsDir, 0755, true);
}

$logFile = $logsDir . '/cron_' . date('Y-m-d') . '.log';

/**
 * Log message to both console and file
 */
function log_message($message) {
    global $logFile;
    $timestamp = "[" . date('Y-m-d H:i:s') . "]";
    $logEntry = $timestamp . " " . $message . "\n";
    
    // Output to console
    echo $logEntry;
    
    // Write to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

log_message("Starting cron job");

// 1. Update bucket cache
echo "[" . date('Y-m-d H:i:s') . "] Checking bucket cache...\n";
try {
    $cache = new BucketCache();
    $result = $cache->updateCacheInBackground();
    
    if ($result) {
        echo "[" . date('Y-m-d H:i:s') . "] Bucket cache updated successfully\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Bucket cache update skipped (no changes detected or already running)\n";
    }
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Bucket cache update failed: " . $e->getMessage() . "\n";
}

// 2. Clean up old database entries
log_message("Cleaning up database...");
try {
    $db = Database::getInstance();
    
    // Check if cache_entries table exists before trying to clean it
    $tables = $db->getConnection()->query("SHOW TABLES LIKE 'cache_entries'")->fetchAll();
    if (!empty($tables)) {
        $stmt = $db->getConnection()->prepare('DELETE FROM cache_entries WHERE expires_at < CURRENT_TIMESTAMP');
        $stmt->execute();
        $cleaned = $stmt->rowCount();
        log_message("Cleaned $cleaned expired cache entries");
    } else {
        log_message("cache_entries table does not exist, skipping cleanup");
    }
    
    // Clean old upload sessions if method exists
    if (method_exists($db, 'cleanupExpiredUploadSessions')) {
        $db->cleanupExpiredUploadSessions();
        log_message("Expired upload sessions cleaned");
    }
    
} catch (Exception $e) {
    log_message("WARNING: Database cleanup had issues: " . $e->getMessage());
}

// 3. Build 7-day download stats cache as JSON file
log_message("Building 7-day download stats cache...");
try {
    $db = Database::getInstance();
    
    // Get top downloaded files (last 7 days)
    $stmt = $db->getConnection()->prepare('
        SELECT DISTINCT filename 
        FROM download_stats 
        ORDER BY download_time DESC 
        LIMIT 50
    ');
    $stmt->execute();
    $popular_files = array_column($stmt->fetchAll(), 'filename');
    
    $stats_cache = [];
    $cached_count = 0;
    
    foreach ($popular_files as $filename) {
        try {
            // Get daily breakdown for the last 7 days
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $dateFormat = "DATE_FORMAT(download_time, '%Y-%m-%d')";
            } else {
                $dateFormat = "DATE(download_time)";
            }
            
            $stmt = $db->getConnection()->prepare('
                SELECT ' . $dateFormat . ' as download_date, COUNT(*) as downloads
                FROM download_stats 
                WHERE filename = ? 
                AND download_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY ' . $dateFormat . '
                ORDER BY download_date DESC
            ');
            $stmt->execute([$filename]);
            $daily_stats = $stmt->fetchAll();
            
            // Build daily breakdown (newest first)
            $daily_breakdown = [];
            foreach ($daily_stats as $day) {
                $daily_breakdown[$day['download_date']] = (int)$day['downloads'];
            }
            
            if (!empty($daily_breakdown)) {
                $stats_cache[$filename] = $daily_breakdown;
                $cached_count++;
            }
        } catch (Exception $e) {
            log_message("WARNING: Failed to cache stats for $filename: " . $e->getMessage());
        }
    }
    
    // Write to JSON file atomically
    $cache_file = $statsDir . '/download_stats.json';
    $temp_file = $cache_file . '.tmp';
    
    if (file_put_contents($temp_file, json_encode($stats_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        rename($temp_file, $cache_file);
        log_message("Cached 7-day stats for $cached_count files to JSON");
    } else {
        log_message("ERROR: Failed to write stats cache file");
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
    }
} catch (Exception $e) {
    log_message("WARNING: Download stats caching failed: " . $e->getMessage());
}

// 4. Clean up old JSON cache files and stale database entries
log_message("Cleaning cache...");
try {
    // Clean old JSON cache files (keep last 7 days worth of updates)
    $cache_file = $statsDir . '/download_stats.json';
    if (file_exists($cache_file)) {
        $file_age = time() - filemtime($cache_file);
        if ($file_age > 604800) { // 7 days in seconds
            unlink($cache_file);
            log_message("Removed stale JSON cache file");
        }
    }
    
    // Also clean temporary files
    foreach (glob($statsDir . '/*.tmp') as $tmp_file) {
        if (file_exists($tmp_file) && (time() - filemtime($tmp_file)) > 3600) {
            unlink($tmp_file);
        }
    }
    
    $db = Database::getInstance();
    
    // Clean old database cache entries if using DB caching
    try {
        $stmt = $db->getConnection()->prepare('
            DELETE FROM download_stats_cache 
            WHERE cached_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');
        $stmt->execute();
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            log_message("Cleaned $deleted old cache entries from database");
        }
    } catch (Exception $e) {
        // Cache table may not exist, that's fine
    }
} catch (Exception $e) {
    log_message("WARNING: Cache cleanup had issues: " . $e->getMessage());
}

// 5. Health check
log_message("Performing health checks...");
try {
    // Test R2 connectivity
    if (function_exists('generate_presigned_url')) {
        $test_url = generate_presigned_url('health-test.txt', 60);
        if ($test_url) {
            log_message("R2 connectivity: OK");
        } else {
            log_message("WARNING: R2 URL generation failed");
        }
    }
    
    // Test base path accessibility
    if (defined('BASE_PATH') && is_readable(BASE_PATH)) {
        log_message("Base path accessibility: OK");
    } else {
        log_message("ERROR: Base path not accessible");
    }
    
} catch (Exception $e) {
    log_message("ERROR: Health check failed: " . $e->getMessage());
}

log_message("Cron job completed");
?>