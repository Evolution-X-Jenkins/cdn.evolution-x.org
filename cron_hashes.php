<?php
/**
 * OTA ZIP Hash Sync Cron Worker
 *
 * Checks ZIP records in download_stat against their matching GitHub OTA release
 * entry. When the OTA timestamp changes, the stored MD5 and SHA256 are updated.
 *
 * Usage:
 *   php cron_hashes.php [--dry-run]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/database.php';
require_once __DIR__ . '/modules/core/cache.php';
require_once __DIR__ . '/modules/core/ota_hashes.php';

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

$logFile = $logsDir . '/cron_hashes_' . date('Y-m-d') . '.log';
$lockFile = $logsDir . '/cron_hashes.lock';

function hash_log($message) {
    global $logFile;
    $timestamp = '[' . date('Y-m-d H:i:s') . ']';
    $entry = $timestamp . ' ' . $message . "\n";
    echo $entry;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function acquire_hash_lock($lockFile) {
    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        return [null, 'Unable to open lock file: ' . $lockFile];
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return [null, 'Another cron_hashes process is already running'];
    }

    @ftruncate($handle, 0);
    @fwrite($handle, "pid=" . getmypid() . "\nstarted_at=" . date('c') . "\n");
    @fflush($handle);

    return [$handle, null];
}

function release_hash_lock($handle) {
    if (!is_resource($handle)) {
        return;
    }

    @ftruncate($handle, 0);
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function parse_hash_args($argv) {
    return [
        'dryRun' => in_array('--dry-run', array_slice($argv, 1), true),
    ];
}

function ensure_download_stat_table($pdo) {
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $sql = '
            CREATE TABLE IF NOT EXISTS download_stat (
                `key` VARCHAR(512) PRIMARY KEY,
                `count` INT DEFAULT 0,
                `sha256` VARCHAR(64),
                `md5` VARCHAR(32),
                `file_size` BIGINT,
                `hash_source_modified_at` BIGINT NULL,
                `ota_json_timestamp` BIGINT NULL,
                `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ';
    } else {
        $sql = '
            CREATE TABLE IF NOT EXISTS download_stat (
                key_path TEXT PRIMARY KEY,
                count INTEGER DEFAULT 0,
                sha256 TEXT,
                md5 TEXT,
                file_size INTEGER,
                hash_source_modified_at INTEGER,
                ota_json_timestamp INTEGER,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ';
    }

    $pdo->exec($sql);
}

$options = parse_hash_args($argv);

list($lockHandle, $lockError) = acquire_hash_lock($lockFile);
if ($lockHandle === null) {
    hash_log('INFO: ' . $lockError);
    exit(0);
}

hash_log('OTA ZIP hash sync started');
hash_log('Lock file: ' . $lockFile);
hash_log('Dry run: ' . ($options['dryRun'] ? 'yes' : 'no'));

$startTime = microtime(true);

try {
    $pdo = Database::getInstance()->getConnection();
    $hashCache = CacheManager::getInstance();
    ensure_download_stat_table($pdo);

    $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';
    $zipRows = $pdo->query(
        'SELECT ' . $keyColumn . ' AS file_key, md5, sha256, ota_json_timestamp
         FROM download_stat
         WHERE LOWER(' . $keyColumn . ') LIKE \'%.zip\''
    )->fetchAll();
    $updateOtaStmt = $pdo->prepare(
        'UPDATE download_stat
         SET md5 = ?, sha256 = ?, ota_json_timestamp = ?
         WHERE ' . $keyColumn . ' = ?'
    );

    $stats = [
        'checked' => 0,
        'updated' => 0,
        'wouldUpdate' => 0,
        'unchanged' => 0,
        'notFound' => 0,
        'invalidRelease' => 0,
    ];

    foreach ($zipRows as $zipRow) {
        $relativePath = ltrim(str_replace('\\', '/', (string)($zipRow['file_key'] ?? '')), '/');
        if ($relativePath === '') {
            continue;
        }

        $stats['checked']++;
        $fileName = basename($relativePath);
        $storedOtaTimestamp = isset($zipRow['ota_json_timestamp'])
            ? (int)$zipRow['ota_json_timestamp']
            : 0;
        hash_log(
            'INFO: Checking ZIP file: ' . $fileName .
            ' - Current timestamp: ' . ($storedOtaTimestamp > 0 ? $storedOtaTimestamp : 'NULL')
        );

        $pathParts = explode('/', $relativePath);
        $deviceName = $pathParts[0] ?? '';
        $otaRelease = findOtaReleaseForFile($deviceName, $fileName);

        if ($otaRelease === null) {
            $stats['notFound']++;
            hash_log('INFO: ZIP file not found in OTA; existing hashes retained: ' . $fileName);
            continue;
        }

        $otaTimestamp = isset($otaRelease['timestamp']) ? (int)$otaRelease['timestamp'] : 0;
        $otaMd5 = trim((string)($otaRelease['md5'] ?? ''));
        $otaSha256 = trim((string)($otaRelease['sha256'] ?? ''));
        if ($otaTimestamp <= 0 || $otaMd5 === '' || $otaSha256 === '') {
            $stats['invalidRelease']++;
            hash_log('WARN: OTA release has incomplete hash metadata: ' . $fileName);
            continue;
        }

        $missingHashes = empty($zipRow['md5']) || empty($zipRow['sha256']);
        if (!$missingHashes && $storedOtaTimestamp === $otaTimestamp) {
            $stats['unchanged']++;
            hash_log(
                'INFO: ZIP file up to date: ' . $fileName .
                ' - Timestamp: ' . $otaTimestamp
            );
            continue;
        }

        hash_log(
            'INFO: ' . ($options['dryRun'] ? 'Would update' : 'Updating') .
            ' ZIP file: ' . $fileName .
            ' - Reason: ' . ($missingHashes ? 'missing hashes' : 'OTA timestamp changed') .
            ' - New timestamp: ' . $otaTimestamp .
            ' - Branch: ' . ($otaRelease['branch'] ?? 'unknown')
        );

        if ($options['dryRun']) {
            $stats['wouldUpdate']++;
            continue;
        }

        $updateOtaStmt->execute([$otaMd5, $otaSha256, $otaTimestamp, $relativePath]);
        $hashCache->delete('hash:' . $relativePath);
        $stats['updated']++;
        hash_log(
            'INFO: Record updated: ZIP file: ' . $fileName .
            ' - Timestamp: ' . $otaTimestamp .
            ' - SHA256: ' . $otaSha256 .
            ' - MD5: ' . $otaMd5
        );
    }

    $duration = microtime(true) - $startTime;
    hash_log('OTA ZIP hash sync completed');
    hash_log('Checked: ' . $stats['checked']);
    hash_log('Updated: ' . $stats['updated']);
    hash_log('Would update: ' . $stats['wouldUpdate']);
    hash_log('Unchanged: ' . $stats['unchanged']);
    hash_log('Not found in OTA: ' . $stats['notFound']);
    hash_log('Invalid OTA metadata: ' . $stats['invalidRelease']);
    hash_log('Duration: ' . number_format($duration, 2) . 's');

    release_hash_lock($lockHandle);
    exit(0);
} catch (Throwable $e) {
    hash_log('ERROR: OTA ZIP hash sync failed: ' . $e->getMessage());
    release_hash_lock($lockHandle);
    exit(1);
}
