<?php
/**
 * Cron Job Script
 * Run this script periodically via crontab to maintain cache and perform maintenance tasks
 * 
 * Usage: php /path/to/cron.php
 * Crontab example: 0,15,30,45 * * * * php /home/aidan/git/php_filebrowser/cron.php >> /var/log/filebrowser_cron.log 2>&1
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/core/bucket_cache.php';
require_once __DIR__ . '/modules/setup/database.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting cron job\n";

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
echo "[" . date('Y-m-d H:i:s') . "] Cleaning up database...\n";
try {
    $db = Database::getInstance();
    
    // Clean expired cache entries
    $stmt = $db->getConnection()->prepare('DELETE FROM cache_entries WHERE expires_at < CURRENT_TIMESTAMP');
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    echo "[" . date('Y-m-d H:i:s') . "] Cleaned $cleaned expired cache entries\n";
    
    // Clean old upload sessions
    $db->cleanupExpiredUploadSessions();
    echo "[" . date('Y-m-d H:i:s') . "] Expired upload sessions cleaned\n";
    
    // Clean old log files (keep last 1000 lines)
    if (defined('LOG_FILE') && file_exists(LOG_FILE)) {
        $lines = file(LOG_FILE);
        if (count($lines) > 1000) {
            $keep_lines = array_slice($lines, -1000);
            file_put_contents(LOG_FILE, implode('', $keep_lines), LOCK_EX);
            $cleaned = count($lines) - 1000;
            echo "[" . date('Y-m-d H:i:s') . "] Cleaned $cleaned old log entries\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Log file size OK (" . count($lines) . " lines)\n";
        }
    }
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Database cleanup failed: " . $e->getMessage() . "\n";
}

// 3. Process hash calculation queue
echo "[" . date('Y-m-d H:i:s') . "] Processing hash calculation queue...\n";
try {
    $hash_queue_file = sys_get_temp_dir() . '/filebrowser_hash_queue.txt';
    
    if (file_exists($hash_queue_file)) {
        $queue = file($hash_queue_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($queue)) {
            require_once __DIR__ . '/modules/core/file_operations.php';
            $hasher = new FileHasher();
            
            // Process up to 3 files per cron run to avoid timeouts
            $processed = 0;
            $remaining_queue = [];
            
            foreach ($queue as $filePath) {
                if ($processed < 3) {
                    try {
                        $fullPath = BASE_PATH . '/' . ltrim($filePath, '/');
                        if (file_exists($fullPath)) {
                            echo "[" . date('Y-m-d H:i:s') . "] Calculating hashes for: $filePath\n";
                            $hashes = $hasher->getFileHashes($filePath, ['md5', 'sha1', 'sha256']);
                            echo "[" . date('Y-m-d H:i:s') . "] Hash calculation completed for: $filePath\n";
                            $processed++;
                        }
                    } catch (Exception $e) {
                        echo "[" . date('Y-m-d H:i:s') . "] ERROR: Hash calculation failed for $filePath: " . $e->getMessage() . "\n";
                        $processed++; // Count as processed to avoid infinite retries
                    }
                } else {
                    $remaining_queue[] = $filePath;
                }
            }
            
            // Write back remaining queue
            if (!empty($remaining_queue)) {
                file_put_contents($hash_queue_file, implode("\n", $remaining_queue) . "\n", LOCK_EX);
                echo "[" . date('Y-m-d H:i:s') . "] " . count($remaining_queue) . " files remaining in hash queue\n";
            } else {
                unlink($hash_queue_file);
                echo "[" . date('Y-m-d H:i:s') . "] Hash queue cleared\n";
            }
        } else {
            unlink($hash_queue_file);
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No hash calculations pending\n";
    }
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Hash queue processing failed: " . $e->getMessage() . "\n";
}

// 4. Validate and update hashes from download_stat table
echo "[" . date('Y-m-d H:i:s') . "] Processing files from download statistics...\n";
try {
    require_once __DIR__ . '/modules/core/file_operations.php';
    $hasher = new FileHasher();
    $db = Database::getInstance();
    
    // Get ALL files from download_stat table to check file sizes and hashes
    $stmt = $db->getConnection()->prepare('
        SELECT id, key_path, file_size, md5, sha256, count
        FROM download_stat 
        ORDER BY count DESC
    ');
    $stmt->execute();
    $files = $stmt->fetchAll();
    
    $processed = 0;
    $removed = 0;
    $calculated = 0;
    $updated = 0;
    
    foreach ($files as $file) {
        $key_path = $file['key_path'];
        $fullPath = BASE_PATH . '/' . ltrim($key_path, '/');
        
        // Check if file still exists
        if (!file_exists($fullPath)) {
            echo "[" . date('Y-m-d H:i:s') . "] File not found, skipping: " . $key_path . "\n";
            $removed++;
            continue;
        }
        
        // Get current file size to check for changes (detect mid-upload)
        $currentSize = filesize($fullPath);
        
        // Check if file size doesn't match OR if hashes are missing/empty
        $needs_update = false;
        $reason = "";
        
        if ($file['file_size'] && $currentSize != $file['file_size']) {
            $needs_update = true;
            $reason = "File size mismatch (DB: {$file['file_size']}, current: {$currentSize})";
        } else if (empty($file['md5']) || empty($file['sha256']) || $file['md5'] === '' || $file['sha256'] === '') {
            $needs_update = true;
            $reason = "Missing hashes";
        } else if (!$file['file_size']) {
            $needs_update = true;
            $reason = "Missing file size";
        }
        
        if ($needs_update) {
            echo "[" . date('Y-m-d H:i:s') . "] $reason, updating: " . $key_path . "\n";
            
            try {
                // Show progress bar before calculation
                echo "MD5 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                
                $md5 = hash_file('md5', $fullPath);
                
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                // Show progress bar for SHA256
                echo "SHA256 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                
                $sha256 = hash_file('sha256', $fullPath);
                
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                $stmt = $db->getConnection()->prepare('
                    UPDATE download_stat 
                    SET md5 = ?, sha256 = ?, file_size = ?
                    WHERE id = ?
                ');
                $stmt->execute([$md5, $sha256, $currentSize, $file['id']]);
                
                echo "[" . date('Y-m-d H:i:s') . "] Updated: " . $key_path . "\n\n";
                $calculated++;
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to update " . $key_path . ": " . $e->getMessage() . "\n";
            }
        }
        
        $processed++;
    }
    
    // Also check for new files that have been downloaded but not yet in download_stat
    echo "[" . date('Y-m-d H:i:s') . "] Checking for new downloaded files...\n";
    $stmt = $db->getConnection()->prepare('
        SELECT DISTINCT filename 
        FROM download_stats 
        WHERE filename NOT IN (SELECT key_path FROM download_stat)
    ');
    $stmt->execute();
    $new_files = $stmt->fetchAll();
    
    foreach ($new_files as $new_file) {
        $filename = $new_file['filename'];
        $fullPath = BASE_PATH . '/' . ltrim($filename, '/');
        
        if (file_exists($fullPath)) {
            echo "[" . date('Y-m-d H:i:s') . "] Adding new file to download_stat: " . $filename . "\n";
            try {
                $file_size = filesize($fullPath);
                
                echo "MD5 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                $md5 = hash_file('md5', $fullPath);
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                echo "SHA256 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                $sha256 = hash_file('sha256', $fullPath);
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                // Get download count
                $count_stmt = $db->getConnection()->prepare('
                    SELECT COUNT(*) as downloads FROM download_stats WHERE filename = ?
                ');
                $count_stmt->execute([$filename]);
                $download_count = $count_stmt->fetchColumn();
                
                $stmt = $db->getConnection()->prepare('
                    INSERT INTO download_stat (key_path, count, md5, sha256, file_size)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$filename, $download_count, $md5, $sha256, $file_size]);
                
                echo "[" . date('Y-m-d H:i:s') . "] Added new file: " . $filename . " (downloads: $download_count)\n";
                $calculated++;
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to add new file " . $filename . ": " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Now scan the entire filesystem to find files not in download_stat
    echo "[" . date('Y-m-d H:i:s') . "] Scanning filesystem for files not in download_stat...\n";
    
    // Get list of all files currently in download_stat
    $stmt = $db->getConnection()->prepare('SELECT key_path FROM download_stat');
    $stmt->execute();
    $existing_files = array_column($stmt->fetchAll(), 'key_path');
    $existing_files_set = array_flip($existing_files); // For faster lookup
    
    // Recursively scan BASE_PATH
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE_PATH, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    $scanned = 0;
    $added = 0;
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = str_replace(BASE_PATH . '/', '', $file->getPathname());
            
            // Skip if already exists in download_stat
            if (isset($existing_files_set[$relativePath])) {
                continue;
            }
            
            $scanned++;
            echo "[" . date('Y-m-d H:i:s') . "] Found new file: " . $relativePath . "\n";
            
            try {
                $file_size = $file->getSize();
                
                echo "MD5 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                $md5 = hash_file('md5', $file->getPathname());
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                echo "SHA256 ";
                for ($i = 0; $i < 20; $i++) { echo "."; usleep(10000); }
                echo " ";
                $sha256 = hash_file('sha256', $file->getPathname());
                for ($i = 0; $i < 10; $i++) { echo "|"; usleep(5000); }
                echo " Complete\n";
                
                // Get download count if any
                $count_stmt = $db->getConnection()->prepare('
                    SELECT COUNT(*) as downloads FROM download_stats WHERE filename = ?
                ');
                $count_stmt->execute([$relativePath]);
                $download_count = $count_stmt->fetchColumn() ?: 0;
                
                $stmt = $db->getConnection()->prepare('
                    INSERT INTO download_stat (key_path, count, md5, sha256, file_size)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$relativePath, $download_count, $md5, $sha256, $file_size]);
                
                echo "[" . date('Y-m-d H:i:s') . "] Added to database: " . $relativePath . " (downloads: $download_count)\n\n";
                $added++;
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to process " . $relativePath . ": " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Filesystem scan completed: $scanned files scanned, $added files added\n";
    echo "[" . date('Y-m-d H:i:s') . "] Hash validation completed: $calculated updated, $removed removed, $added newly added\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Hash validation failed: " . $e->getMessage() . "\n";
}

// 5. Health check
echo "[" . date('Y-m-d H:i:s') . "] Performing health checks...\n";
try {
    // Test R2 connectivity
    if (function_exists('generate_presigned_url')) {
        $test_url = generate_presigned_url('health-test.txt', 60);
        if ($test_url) {
            echo "[" . date('Y-m-d H:i:s') . "] R2 connectivity: OK\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] WARNING: R2 URL generation failed\n";
        }
    }
    
    // Test base path accessibility
    if (defined('BASE_PATH') && is_readable(BASE_PATH)) {
        echo "[" . date('Y-m-d H:i:s') . "] Base path accessibility: OK\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: Base path not accessible\n";
    }
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Health check failed: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cron job completed\n\n";
?>