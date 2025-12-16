<?php
/**
 * Bucket Size Cache Manager
 * Handles background caching of bucket size calculations
 */

require_once __DIR__ . '/../setup/config.php';

class BucketCache {
    private $cache_file;
    private $lock_file;
    private $max_age = 3600; // 1 hour cache validity
    
    public function __construct() {
        $this->cache_file = sys_get_temp_dir() . '/filebrowser_bucket_cache.json';
        $this->lock_file = sys_get_temp_dir() . '/filebrowser_bucket_cache.lock';
    }
    
    /**
     * Get cached bucket statistics (cron job updates the cache)
     */
    public function getBucketStats() {
        $cached = $this->readCache();
        
        // If cache is valid, return it
        if ($cached && $this->isCacheValid($cached)) {
            return $cached['data'];
        }
        
        // If cache is stale but exists, return stale data (cron will update it)
        if ($cached) {
            return $cached['data'];
        }
        
        // No cache exists, do synchronous calculation (first time only)
        return $this->calculateBucketSize();
    }
    
    /**
     * Force cache invalidation (called when files are uploaded/deleted)
     * Cron job will update the cache on next run
     */
    public function invalidateCache() {
        if (file_exists($this->cache_file)) {
            unlink($this->cache_file);
        }
    }
    
    /**
     * Background update process with change detection
     */
    public function updateCacheInBackground($force = false) {
        // Prevent multiple simultaneous updates
        if (file_exists($this->lock_file)) {
            $lock_age = time() - filemtime($this->lock_file);
            if ($lock_age < 300) { // 5 minutes lock timeout
                return false;
            }
            unlink($this->lock_file);
        }
        
        // Skip if cache is recent and no changes detected (unless forced)
        if (!$force && $this->isCacheRecentAndValid()) {
            return false; // No update needed
        }
        
        // Create lock file
        touch($this->lock_file);
        
        try {
            $stats = $this->calculateBucketSize();
            $this->writeCache($stats);
            return true;
        } finally {
            // Always remove lock
            if (file_exists($this->lock_file)) {
                unlink($this->lock_file);
            }
        }
    }
    
    /**
     * Check if cache is recent and directory hasn't been modified
     */
    private function isCacheRecentAndValid() {
        $cached = $this->readCache();
        
        // No cache exists
        if (!$cached) {
            return false;
        }
        
        // Cache is older than 1 hour - needs update
        if ((time() - $cached['timestamp']) > 3600) {
            return false;
        }
        
        // Check if base directory has been modified since cache
        $base_path = BASE_PATH;
        if (!is_dir($base_path)) {
            return false;
        }
        
        $dir_modified = $this->getDirectoryLastModified($base_path);
        
        // If directory was modified after cache, need update
        if ($dir_modified > $cached['timestamp']) {
            return false;
        }
        
        return true; // Cache is still valid
    }
    
    /**
     * Get the most recent modification time in a directory tree
     */
    private function getDirectoryLastModified($path) {
        $latest = filemtime($path);
        
        try {
            // Quick check of top-level subdirectories only for performance
            $iterator = new DirectoryIterator($path);
            foreach ($iterator as $item) {
                if ($item->isDot()) continue;
                
                $mtime = $item->getMTime();
                if ($mtime > $latest) {
                    $latest = $mtime;
                }
                
                // For directories, check one level deeper but not recursive
                // This balances change detection with performance
                if ($item->isDir()) {
                    try {
                        $subIterator = new DirectoryIterator($item->getPathname());
                        foreach ($subIterator as $subItem) {
                            if ($subItem->isDot()) continue;
                            $subMtime = $subItem->getMTime();
                            if ($subMtime > $latest) {
                                $latest = $subMtime;
                            }
                        }
                    } catch (Exception $e) {
                        // Skip inaccessible subdirectories
                        continue;
                    }
                }
            }
        } catch (Exception $e) {
            // If we can't check, assume it changed
            return time();
        }
        
        return $latest;
    }
    
    private function readCache() {
        if (!file_exists($this->cache_file)) {
            return null;
        }
        
        $content = file_get_contents($this->cache_file);
        $data = json_decode($content, true);
        
        return $data ?: null;
    }
    
    private function writeCache($data) {
        $cache_data = [
            'timestamp' => time(),
            'data' => $data
        ];
        
        file_put_contents($this->cache_file, json_encode($cache_data), LOCK_EX);
    }
    
    private function isCacheValid($cached) {
        return (time() - $cached['timestamp']) < $this->max_age;
    }
    
    // Background update removed - use cron job instead
    
    private function calculateBucketSize() {
        $base_path = BASE_PATH;
        $total_files = 0;
        $total_size = 0;
        
        if (!is_dir($base_path)) {
            return [
                'status' => 'error',
                'message' => 'Bucket directory not found',
                'total_files' => 0,
                'total_size' => 0,
                'path' => $base_path
            ];
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base_path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $total_files++;
                    $total_size += $file->getSize();
                }
            }
            
            $status = 'healthy';
            if ($total_size > 5 * 1024 * 1024 * 1024 * 1024) { // 5TB warning
                $status = 'warning';
            }
            if ($total_size > 10 * 1024 * 1024 * 1024 * 1024) { // 10TB error
                $status = 'error';
            }
            
            return [
                'status' => $status,
                'message' => format_file_size($total_size) . ' in ' . $total_files . ' files',
                'total_files' => $total_files,
                'total_size' => $total_size,
                'path' => $base_path
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error reading directory: ' . $e->getMessage(),
                'total_files' => 0,
                'total_size' => 0,
                'path' => $base_path
            ];
        }
    }
}

// Note: format_file_size() function is defined in config.php
?>