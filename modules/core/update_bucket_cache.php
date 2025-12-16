<?php
/**
 * Background cache update script
 * This script runs in the background to update bucket size cache
 */

require_once __DIR__ . '/bucket_cache.php';

// Create cache instance and update
$cache = new BucketCache();
$result = $cache->updateCacheInBackground();

if ($result) {
    error_log("Bucket cache updated successfully");
} else {
    error_log("Bucket cache update skipped (already running)");
}
?>