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

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/core/bucket_cache.php';
require_once __DIR__ . '/modules/setup/database.php';
require_once __DIR__ . '/modules/core/cache.php';

function cron_env_bool($name, $default = false) {
    if (function_exists('env_bool')) {
        return env_bool($name, $default);
    }

    $raw = getenv($name);
    if ($raw === false || trim((string)$raw) === '') {
        return $default;
    }

    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function cron_is_remote_mount_path($path) {
    if (function_exists('is_remote_mount_path')) {
        return is_remote_mount_path($path);
    }

    $resolvedPath = realpath($path);
    if ($resolvedPath === false) {
        $resolvedPath = $path;
    }

    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($mounts === false) {
        return false;
    }

    $bestMatch = null;
    $bestLength = -1;

    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 3) {
            continue;
        }

        $mountPoint = str_replace('\\040', ' ', $parts[1]);
        $fsType = strtolower($parts[2]);

        if ($mountPoint === '/') {
            continue;
        }

        $matches = ($resolvedPath === $mountPoint) || (strpos($resolvedPath, rtrim($mountPoint, '/') . '/') === 0);
        if (!$matches) {
            continue;
        }

        $len = strlen($mountPoint);
        if ($len > $bestLength) {
            $bestLength = $len;
            $bestMatch = $fsType;
        }
    }

    if ($bestMatch === null) {
        return false;
    }

    return (
        strpos($bestMatch, 'fuse.rclone') !== false ||
        strpos($bestMatch, 'rclone') !== false ||
        strpos($bestMatch, 's3fs') !== false ||
        strpos($bestMatch, 'goofys') !== false ||
        strpos($bestMatch, 'fuse.s3fs') !== false
    );
}

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
log_message("Checking bucket cache...");
try {
    $allowRemoteScan = cron_env_bool('ENABLE_BUCKET_CACHE_SCAN_ON_REMOTE', false);
    $remoteMount = cron_is_remote_mount_path(BASE_PATH);

    if ($remoteMount && !$allowRemoteScan) {
        log_message("Bucket cache update skipped (remote mount detected; set ENABLE_BUCKET_CACHE_SCAN_ON_REMOTE=true to override)");
    } else {
        $cache = new BucketCache();
        $result = $cache->updateCacheInBackground();

        if ($result) {
            log_message("Bucket cache updated successfully");
        } else {
            log_message("Bucket cache update skipped (no changes detected or already running)");
        }
    }
} catch (Exception $e) {
    log_message("ERROR: Bucket cache update failed: " . $e->getMessage());
}

// 2. Clean up old database entries
log_message("Cleaning up database...");
try {
    $db = Database::getInstance();

    // Check if cache_entries table exists before trying to clean it.
    if (tableExists($db->getConnection(), 'cache_entries')) {
        $stmt = $db->getConnection()->prepare('DELETE FROM cache_entries WHERE expires_at < CURRENT_TIMESTAMP');
        $stmt->execute();
        $cleaned = $stmt->rowCount();
        log_message("Cleaned $cleaned expired cache entries");
    }
} catch (Exception $e) {
    log_message("WARNING: Database cleanup had issues: " . $e->getMessage());
}

// 3. Build 7-day download stats cache for ALL files (Redis + File + JSON)
log_message("Building 7-day download stats cache for all files...");
try {
    $db = Database::getInstance();
    $cache = CacheManager::getInstance();

    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $dateFormat = "DATE_FORMAT(download_time, '%Y-%m-%d')";
        $recentWindow = 'download_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } else {
        $dateFormat = "DATE(download_time)";
        $recentWindow = "download_time >= datetime('now', '-7 day')";
    }

    $statsStmt = $db->getConnection()->prepare('
        SELECT filename, ' . $dateFormat . ' as download_date, COUNT(*) as downloads
        FROM download_stats
        WHERE ' . $recentWindow . '
        GROUP BY filename, ' . $dateFormat . '
        ORDER BY filename ASC, download_date DESC
    ');
    $statsStmt->execute();
    $dailyRows = $statsStmt->fetchAll();

    $stats_cache = []; // For JSON file
    foreach ($dailyRows as $row) {
        $filename = (string)($row['filename'] ?? '');
        $downloadDate = (string)($row['download_date'] ?? '');

        if ($filename === '' || $downloadDate === '') {
            continue;
        }

        if (!isset($stats_cache[$filename])) {
            $stats_cache[$filename] = [];
        }

        $stats_cache[$filename][$downloadDate] = (int)$row['downloads'];
    }

    $cached_count = count($stats_cache);
    log_message("Processing $cached_count files with downloads in the last 7 days...");

    foreach ($stats_cache as $filename => $daily_breakdown) {
        $cache_key = 'stats:' . $filename;
        $cache->set($cache_key, $daily_breakdown);
    }
    
    // Write to JSON file atomically (for backward compatibility and persistence)
    $cache_file = $statsDir . '/download_stats.json';
    $temp_file = $cache_file . '.tmp';
    
    if (file_put_contents($temp_file, json_encode($stats_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        rename($temp_file, $cache_file);
        log_message("Cached 7-day stats for $cached_count files to Redis, File, and JSON");
    } else {
        log_message("ERROR: Failed to write stats cache JSON file (but Redis+File cache was updated)");
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
    }
} catch (Exception $e) {
    log_message("WARNING: Download stats caching failed: " . $e->getMessage());
}

// 3.5 Verify all file total downloads
log_message("Verifying file download totals...");
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';

    // Single query: aggregate actual counts for every filename+folder combination
    $actualStmt = $pdo->query('
        SELECT CONCAT(CASE WHEN folder = \'\' THEN \'\' ELSE CONCAT(folder, \'/\') END, filename) AS file_key,
               COUNT(*) AS actual_count
        FROM download_stats
        GROUP BY folder, filename
    ');
    $actualCounts = [];
    foreach ($actualStmt->fetchAll() as $row) {
        $actualCounts[$row['file_key']] = (int)$row['actual_count'];
    }

    // Load all stored counts in one query
    $storedStmt = $pdo->query('SELECT ' . $keyColumn . ' AS file_key, count FROM download_stat');
    $storedFiles = $storedStmt->fetchAll();

    $verified_count = 0;
    $corrected_count = 0;
    $errors = [];

    $updateStmt = $pdo->prepare('UPDATE download_stat SET count = ? WHERE ' . $keyColumn . ' = ?');

    foreach ($storedFiles as $file) {
        $fileKey = $file['file_key'];
        $stored_count = (int)$file['count'];
        $actual_count = $actualCounts[ltrim($fileKey, '/')] ?? 0;

        if ($actual_count !== $stored_count) {
            $errors[] = "Mismatch for '{$fileKey}': stored={$stored_count}, actual={$actual_count}";
            $updateStmt->execute([$actual_count, $fileKey]);
            $corrected_count++;
            log_message("Corrected download count for '{$fileKey}': {$stored_count} → {$actual_count}");
        }
        $verified_count++;
    }

    log_message("Verified $verified_count files, corrected $corrected_count mismatches");

    if (!empty($errors)) {
        log_message("Download total mismatches found: " . implode("; ", array_slice($errors, 0, 5)) . (count($errors) > 5 ? "..." : ""));
    }

} catch (Exception $e) {
    log_message("WARNING: Download total verification failed: " . $e->getMessage());
}

// 3.6 Build stats dashboard snapshot (JSON only, consumed by /api/stats-dashboard)
log_message("Building stats dashboard snapshot...");
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $dashboardCacheFile = defined('STATS_DASHBOARD_CACHE_FILE')
        ? STATS_DASHBOARD_CACHE_FILE
        : ($statsDir . '/stats_dashboard.json');
    $dashboardTmpFile = $dashboardCacheFile . '.tmp';

    if (!tableExists($pdo, 'download_stats')) {
        $emptySnapshot = [
            'generated_at' => date('c'),
            'summary' => [
                'total_downloads' => 0,
                'since_date' => null,
            ],
            'daily_totals' => [],
            'device_daily' => [],
            'device_totals_all' => [],
            'devices_available' => [],
        ];

        if (file_put_contents($dashboardTmpFile, json_encode($emptySnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
            rename($dashboardTmpFile, $dashboardCacheFile);
            log_message("Stats dashboard snapshot updated (empty): " . $dashboardCacheFile);
        }

        log_message("download_stats table not available; using empty dashboard snapshot");
    } else {
        $dateExpr = (defined('DB_TYPE') && DB_TYPE === 'mysql')
            ? "DATE_FORMAT(download_time, '%Y-%m-%d')"
            : "DATE(download_time)";

        $summaryStmt = $pdo->query('SELECT COUNT(*) as total_downloads, MIN(download_time) as oldest_download FROM download_stats');
        $summaryRow = $summaryStmt->fetch();

        $totalDownloads = (int)($summaryRow['total_downloads'] ?? 0);
        $oldestRaw = $summaryRow['oldest_download'] ?? null;
        $sinceDate = $oldestRaw ? date('Y-m-d', strtotime((string)$oldestRaw)) : null;

        $dailyStmt = $pdo->prepare('SELECT ' . $dateExpr . ' as download_date, COUNT(*) as downloads FROM download_stats GROUP BY ' . $dateExpr . ' ORDER BY download_date ASC');
        $dailyStmt->execute();
        $dailyRows = $dailyStmt->fetchAll();

        $dailyTotals = [];
        foreach ($dailyRows as $row) {
            $date = (string)($row['download_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $dailyTotals[$date] = (int)$row['downloads'];
        }

        $deviceStmt = $pdo->prepare('SELECT folder, ' . $dateExpr . ' as download_date, COUNT(*) as downloads FROM download_stats GROUP BY folder, ' . $dateExpr . ' ORDER BY download_date ASC');
        $deviceStmt->execute();
        $deviceRows = $deviceStmt->fetchAll();

        $deviceDaily = [];
        $deviceAll = [];
        foreach ($deviceRows as $row) {
            $folder = (string)($row['folder'] ?? '');
            $date = (string)($row['download_date'] ?? '');
            $count = (int)$row['downloads'];

            $device = extractDeviceCodeFromFolder($folder);
            if (!isset($deviceDaily[$device])) {
                $deviceDaily[$device] = [];
            }
            if (!isset($deviceDaily[$device][$date])) {
                $deviceDaily[$device][$date] = 0;
            }
            $deviceDaily[$device][$date] += $count;

            if (!isset($deviceAll[$device])) {
                $deviceAll[$device] = 0;
            }
            $deviceAll[$device] += $count;
        }

        ksort($dailyTotals);
        foreach ($deviceDaily as $device => $map) {
            ksort($map);
            $deviceDaily[$device] = $map;
        }

        $devicesAvailable = array_values(array_filter(array_keys($deviceAll), function ($device) {
            return $device !== 'root';
        }));
        sort($devicesAvailable, SORT_NATURAL | SORT_FLAG_CASE);

        $dashboardSnapshot = [
            'generated_at' => date('c'),
            'summary' => [
                'total_downloads' => $totalDownloads,
                'since_date' => $sinceDate,
            ],
            'daily_totals' => $dailyTotals,
            'device_daily' => $deviceDaily,
            'device_totals_all' => $deviceAll,
            'devices_available' => $devicesAvailable,
        ];

        if (file_put_contents($dashboardTmpFile, json_encode($dashboardSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
            rename($dashboardTmpFile, $dashboardCacheFile);
            log_message("Stats dashboard snapshot updated: " . $dashboardCacheFile);
        } else {
            log_message("WARNING: Failed writing stats dashboard snapshot");
            if (file_exists($dashboardTmpFile)) {
                unlink($dashboardTmpFile);
            }
        }
    }
} catch (Exception $e) {
    log_message("WARNING: Stats dashboard snapshot build failed: " . $e->getMessage());
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

function tableExists($pdo, $tableName) {
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function extractDeviceCodeFromFolder($folder) {
    $cleanFolder = trim((string)$folder, '/');
    if ($cleanFolder === '') {
        return 'root';
    }

    $parts = explode('/', $cleanFolder);
    $device = trim((string)$parts[0]);
    return $device === '' ? 'root' : $device;
}
?>