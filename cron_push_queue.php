<?php
/**
 * Push Release Queue Worker
 * Run this script via crontab to process queued push releases
 * 
 * Usage: php /path/to/cron_push_queue.php
 * Crontab example (every minute): * * * * * php /home/aidan/git/php_filebrowser/cron_push_queue.php
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/database.php';
require_once __DIR__ . '/modules/api/push.php';

// Setup logging
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

$logFile = $logsDir . '/push_queue_' . date('Y-m-d') . '.log';

/**
 * Log message to both console and file
 */
function log_push_queue($message) {
    global $logFile;
    $timestamp = "[" . date('Y-m-d H:i:s') . "]";
    $logEntry = $timestamp . " " . $message . "\n";
    
    // Output to console
    echo $logEntry;
    
    // Write to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

log_push_queue("Push queue worker started");

try {
    $pushQueueResult = processNextQueuedPushReleaseJob();
    
    $status = $pushQueueResult['status'] ?? 'unknown';
    $message = $pushQueueResult['message'] ?? '';
    
    log_push_queue("Status: $status - $message");
    
    if (isset($pushQueueResult['jobId'])) {
        $jobId = $pushQueueResult['jobId'];
        $jobSuccess = ($pushQueueResult['jobSuccess'] ?? false) ? 'SUCCESS' : 'FAILED';
        log_push_queue("Job #$jobId completed: $jobSuccess");
        
        // Log callback result if present
        if (isset($pushQueueResult['callback'])) {
            $callback = $pushQueueResult['callback'];
            if ($callback['sent'] ?? false) {
                $httpCode = $callback['httpCode'] ?? 0;
                log_push_queue("Callback sent successfully (HTTP $httpCode)");
            } elseif ($callback['skipped'] ?? false) {
                log_push_queue("Callback skipped: " . ($callback['reason'] ?? 'unknown'));
            } else {
                $error = $callback['error'] ?? 'unknown error';
                log_push_queue("Callback failed: $error");
            }
        }

        if (isset($pushQueueResult['discordWebhook'])) {
            $discordWebhook = $pushQueueResult['discordWebhook'];
            if ($discordWebhook['sent'] ?? false) {
                $httpCode = $discordWebhook['httpCode'] ?? 0;
                log_push_queue("Discord failure webhook sent successfully (HTTP $httpCode)");
            } elseif ($discordWebhook['skipped'] ?? false) {
                log_push_queue("Discord failure webhook skipped: " . ($discordWebhook['reason'] ?? 'unknown'));
            } else {
                $error = $discordWebhook['error'] ?? 'unknown error';
                log_push_queue("Discord failure webhook failed: $error");
            }
        }
    }
    
} catch (Exception $e) {
    log_push_queue("ERROR: Push queue processing failed: " . $e->getMessage());
    exit(1);
}

log_push_queue("Push queue worker completed");
exit(0);
