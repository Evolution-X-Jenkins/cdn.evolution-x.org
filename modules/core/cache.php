<?php
/**
 * Cache Manager
 * Handles Redis → File → Database fallback hierarchy
 * No TTL - caches persist until cleared or system reboot
 */

require_once __DIR__ . '/../setup/config.php';

class CacheManager {
    private static $instance = null;
    private $redis = null;
    private $redis_available = false;
    private $file_cache_dir = null;
    
    private function __construct() {
        // Initialize Redis if enabled
        if (CACHE_REDIS_ENABLED) {
            $this->initializeRedis();
        }
        
        // Initialize file cache directory
        if (CACHE_FILE_ENABLED) {
            $this->file_cache_dir = CACHE_FILE_DIR;
            if (!is_dir($this->file_cache_dir)) {
                @mkdir($this->file_cache_dir, 0755, true);
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initializeRedis() {
        try {
            // Check if Redis extension is available
            if (!class_exists('Redis')) {
                error_log('Redis extension not available');
                $this->redis_available = false;
                return;
            }
            
            $this->redis = new Redis();
            if (defined('CACHE_REDIS_SOCKET') && CACHE_REDIS_SOCKET) {
                $this->redis->connect(CACHE_REDIS_SOCKET); // Unix socket
            } else {
                $this->redis->connect(CACHE_REDIS_HOST, CACHE_REDIS_PORT, 1); // TCP, 1 second timeout
            }
            
            if (CACHE_REDIS_PASSWORD) {
                $this->redis->auth(CACHE_REDIS_PASSWORD);
            }
            
            $this->redis_available = true;
            error_log('Redis cache initialized');
        } catch (Exception $e) {
            error_log('Redis initialization failed: ' . $e->getMessage());
            $this->redis_available = false;
            $this->redis = null;
        }
    }
    
    /**
     * Get value from cache (tries: Redis → File → APCu → null)
     */
    public function get($key) {
        // Try Redis first
        if ($this->redis_available) {
            try {
                $value = $this->redis->get($key);
                if ($value !== false) {
                    return json_decode($value, true);
                }
            } catch (Exception $e) {
                error_log('Redis get failed: ' . $e->getMessage());
            }
        }
        
        // Try file cache
        if (CACHE_FILE_ENABLED && $this->file_cache_dir) {
            $file_path = $this->getFileCachePath($key);
            if (file_exists($file_path)) {
                $value = @file_get_contents($file_path);
                if ($value !== false) {
                    $decoded = json_decode($value, true);
                    
                    // If we got it from file, try to push to Redis for next time
                    if ($this->redis_available && $decoded !== null) {
                        try {
                            $this->redis->set($key, $value);
                        } catch (Exception $e) {
                            error_log('Failed to cache to Redis: ' . $e->getMessage());
                        }
                    }
                    
                    return $decoded;
                }
            }
        }
        
        // Try APCu
        if (CACHE_APCU_ENABLED) {
            $success = false;
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Set value in cache (writes to: Redis + File + APCu)
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl  Seconds until expiry; 0 = no expiry
     */
    public function set($key, $value, $ttl = 0) {
        $json_value = json_encode($value, JSON_UNESCAPED_SLASHES);
        
        // Write to Redis
        if ($this->redis_available) {
            try {
                if ($ttl > 0) {
                    $this->redis->setex($key, $ttl, $json_value);
                } else {
                    $this->redis->set($key, $json_value);
                }
            } catch (Exception $e) {
                error_log('Failed to write to Redis: ' . $e->getMessage());
            }
        }
        
        // Write to file
        if (CACHE_FILE_ENABLED && $this->file_cache_dir) {
            $file_path = $this->getFileCachePath($key);
            $temp_file = $file_path . '.tmp';
            
            if (@file_put_contents($temp_file, $json_value, LOCK_EX)) {
                @rename($temp_file, $file_path);
            } else {
                error_log('Failed to write cache file: ' . $file_path);
            }
        }
        
        // Write to APCu
        if (CACHE_APCU_ENABLED) {
            try {
                apcu_store($key, $value);
            } catch (Exception $e) {
                error_log('Failed to write to APCu: ' . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Delete value from all caches
     */
    public function delete($key) {
        // Delete from Redis
        if ($this->redis_available) {
            try {
                $this->redis->del($key);
            } catch (Exception $e) {
                error_log('Failed to delete from Redis: ' . $e->getMessage());
            }
        }
        
        // Delete from file
        if (CACHE_FILE_ENABLED && $this->file_cache_dir) {
            $file_path = $this->getFileCachePath($key);
            @unlink($file_path);
        }
        
        // Delete from APCu
        if (CACHE_APCU_ENABLED) {
            try {
                apcu_delete($key);
            } catch (Exception $e) {
                error_log('Failed to delete from APCu: ' . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Clear all caches (dangerous - use with caution)
     */
    public function clear() {
        // Clear Redis (only our stats pattern to avoid affecting other apps)
        if ($this->redis_available) {
            try {
                $this->redis->del($this->redis->keys('stats:*'));
            } catch (Exception $e) {
                error_log('Failed to clear Redis: ' . $e->getMessage());
            }
        }
        
        // Clear file cache
        if (CACHE_FILE_ENABLED && $this->file_cache_dir) {
            $files = @glob($this->file_cache_dir . '/*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
        
        // Clear APCu (entire cache as selective clearing is complex)
        if (CACHE_APCU_ENABLED) {
            try {
                apcu_clear_cache();
            } catch (Exception $e) {
                error_log('Failed to clear APCu: ' . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Get file cache path for a key
     */
    private function getFileCachePath($key) {
        // Create safe filename from key
        $safe_key = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->file_cache_dir . '/' . $safe_key . '.cache';
    }
    
    /**
     * Get cache health/status
     */
    public function getStatus() {
        return [
            'redis_available' => $this->redis_available,
            'file_cache_enabled' => CACHE_FILE_ENABLED && is_writable($this->file_cache_dir),
            'apcu_enabled' => CACHE_APCU_ENABLED,
        ];
    }
}
?>
