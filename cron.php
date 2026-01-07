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

// Setup logging
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
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

// 3. Process hash calculation queue
log_message("Processing hash calculation queue...");
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
                            log_message("Calculating hashes for: $filePath");
                            $hashes = $hasher->getFileHashes($filePath, ['md5', 'sha256']);
                            log_message("Hash calculation completed for: $filePath");
                            $processed++;
                        }
                    } catch (Exception $e) {
                        log_message("ERROR: Hash calculation failed for $filePath: " . $e->getMessage());
                        $processed++; // Count as processed to avoid infinite retries
                    }
                } else {
                    $remaining_queue[] = $filePath;
                }
            }
            
            // Write back remaining queue
            if (!empty($remaining_queue)) {
                file_put_contents($hash_queue_file, implode("\n", $remaining_queue) . "\n", LOCK_EX);
                log_message(count($remaining_queue) . " files remaining in hash queue");
            } else {
                unlink($hash_queue_file);
                log_message("Hash queue cleared");
            }
        } else {
            unlink($hash_queue_file);
        }
    } else {
        log_message("No hash calculations pending");
    }
} catch (Exception $e) {
    log_message("ERROR: Hash queue processing failed: " . $e->getMessage());
}

// 4. Validate and update hashes from download_stat table
log_message("Processing files from download statistics...");
try {
    require_once __DIR__ . '/modules/core/file_operations.php';
    $hasher = new FileHasher();
    $db = Database::getInstance();
    
    // Get ALL files from download_stat table to check file sizes and hashes
    // Note: column is 'key', not 'key_path'
    $stmt = $db->getConnection()->prepare('
        SELECT id, `key`, file_size, md5, sha256, count
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
        $key_path = $file['key'];
        $fullPath = BASE_PATH . '/' . ltrim($key_path, '/');
        
        // Check if file still exists
        if (!file_exists($fullPath)) {
            log_message("File not found, skipping: " . $key_path);
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
            log_message("$reason, updating: " . $key_path);
            
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
                
                log_message("Updated: " . $key_path);
                $calculated++;
            } catch (Exception $e) {
                log_message("ERROR: Failed to update " . $key_path . ": " . $e->getMessage());
            }
        }
        
        $processed++;
    }
    
    // Also check for new files that have been downloaded but not yet in download_stat
    log_message("Checking for new downloaded files...");
    $stmt = $db->getConnection()->prepare('
        SELECT DISTINCT filename 
        FROM download_stats 
        WHERE filename NOT IN (SELECT `key` FROM download_stat)
    ');
    $stmt->execute();
    $new_files = $stmt->fetchAll();
    
    foreach ($new_files as $new_file) {
        $filename = $new_file['filename'];
        $fullPath = BASE_PATH . '/' . ltrim($filename, '/');
        
        if (file_exists($fullPath)) {
            log_message("Adding new file to download_stat: " . $filename);
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
                    INSERT INTO download_stat (`key`, count, md5, sha256, file_size)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$filename, $download_count, $md5, $sha256, $file_size]);
                
                log_message("Added new file: " . $filename . " (downloads: $download_count)");
                $calculated++;
            } catch (Exception $e) {
                log_message("ERROR: Failed to add new file " . $filename . ": " . $e->getMessage());
            }
        }
    }
    
    // Now scan the entire filesystem to find files not in download_stat
    log_message("Scanning filesystem for files not in download_stat...");
    
    // Get list of all files currently in download_stat
    $stmt = $db->getConnection()->prepare('SELECT `key` FROM download_stat');
    $stmt->execute();
    $existing_files = array_column($stmt->fetchAll(), 'key');
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
            log_message("Found new file: " . $relativePath);
            
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
                    INSERT INTO download_stat (`key`, count, md5, sha256, file_size)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$relativePath, $download_count, $md5, $sha256, $file_size]);
                
                log_message("Added to database: " . $relativePath . " (downloads: $download_count)");
                $added++;
            } catch (Exception $e) {
                log_message("ERROR: Failed to process " . $relativePath . ": " . $e->getMessage());
            }
        }
    }
    
    log_message("Filesystem scan completed: $scanned files scanned, $added files added");
    log_message("Hash validation completed: $calculated updated, $removed removed, $added newly added");
    
} catch (Exception $e) {
    log_message("ERROR: Hash validation failed: " . $e->getMessage());
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