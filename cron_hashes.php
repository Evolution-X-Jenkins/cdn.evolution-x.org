<?php
/**
 * Hash Sync Cron Worker
 *
 * Scans files under BASE_PATH and updates download_stat hashes when:
 * - md5 or sha256 is missing
 * - file_size in database differs from actual file size
 *
 * Usage:
 *   php cron_hashes.php [--dry-run] [--limit=1000] [--path=subdir]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/database.php';

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
    $options = [
        'dryRun' => false,
        'limit' => 0,
        'path' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $value = (int)substr($arg, 8);
            $options['limit'] = max(0, $value);
            continue;
        }

        if (strpos($arg, '--path=') === 0) {
            $options['path'] = trim(substr($arg, 7), '/');
            continue;
        }
    }

    return $options;
}

function resolve_scan_root($relativePath) {
    if ($relativePath === '') {
        return BASE_PATH;
    }

    $candidate = BASE_PATH . '/' . $relativePath;
    $resolvedBase = realpath(BASE_PATH);
    $resolvedCandidate = realpath($candidate);

    if ($resolvedBase === false || $resolvedCandidate === false) {
        return null;
    }

    if (strpos($resolvedCandidate, $resolvedBase) !== 0) {
        return null;
    }

    return $resolvedCandidate;
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
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ';
    }

    $pdo->exec($sql);
}

$options = parse_hash_args($argv);
$scanRoot = resolve_scan_root($options['path']);

if ($scanRoot === null || !is_dir($scanRoot) || !is_readable($scanRoot)) {
    hash_log('ERROR: Invalid or unreadable scan path');
    exit(1);
}

list($lockHandle, $lockError) = acquire_hash_lock($lockFile);
if ($lockHandle === null) {
    hash_log('INFO: ' . $lockError);
    exit(0);
}

hash_log('Hash sync cron started');
hash_log('Stage: initialization');
hash_log('Lock file: ' . $lockFile);
hash_log('Scan root: ' . $scanRoot);
hash_log('Dry run: ' . ($options['dryRun'] ? 'yes' : 'no'));
hash_log('Limit: ' . ($options['limit'] > 0 ? $options['limit'] : 'none'));

$startTime = microtime(true);

try {
    hash_log('Stage: database setup');
    $pdo = Database::getInstance()->getConnection();
    ensure_download_stat_table($pdo);

    $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';

    $selectStmt = $pdo->prepare(
        'SELECT count, md5, sha256, file_size FROM download_stat WHERE ' . $keyColumn . ' = ? LIMIT 1'
    );

    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $upsertSql = '
            INSERT INTO download_stat (`key`, count, md5, sha256, file_size)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                md5 = VALUES(md5),
                sha256 = VALUES(sha256),
                file_size = VALUES(file_size)
        ';
    } else {
        $upsertSql = '
            INSERT INTO download_stat (key_path, count, md5, sha256, file_size)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(key_path) DO UPDATE SET
                md5 = excluded.md5,
                sha256 = excluded.sha256,
                file_size = excluded.file_size
        ';
    }

    $upsertStmt = $pdo->prepare($upsertSql);

    $stats = [
        'scanned' => 0,
        'hashed' => 0,
        'missingHashes' => 0,
        'sizeChanged' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    $baseReal = realpath(BASE_PATH);
    if ($baseReal === false) {
        hash_log('ERROR: Unable to resolve BASE_PATH');
        exit(1);
    }

    hash_log('Stage: scan + hash sync');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        if ($options['limit'] > 0 && $stats['scanned'] >= $options['limit']) {
            hash_log('Limit reached (' . $options['limit'] . '), stopping scan');
            break;
        }

        $stats['scanned']++;

        $fullPath = $fileInfo->getPathname();
        if (!is_readable($fullPath)) {
            $stats['errors']++;
            hash_log('WARN: Unreadable file skipped: ' . $fullPath);
            continue;
        }

        $relativePath = ltrim(str_replace($baseReal, '', $fullPath), '/');
        $actualSize = filesize($fullPath);
        if ($actualSize === false) {
            $stats['errors']++;
            hash_log('WARN: Failed to read file size: ' . $relativePath);
            continue;
        }

        hash_log('File: ' . $relativePath . ' | stage=inspect | size=' . $actualSize);

        $selectStmt->execute([$relativePath]);
        $row = $selectStmt->fetch();

        $missingHashes = false;
        $sizeChanged = false;
        $storedCount = 0;
        $storedSize = null;

        if ($row) {
            $storedCount = (int)($row['count'] ?? 0);
            $missingHashes = empty($row['md5']) || empty($row['sha256']);
            $storedSize = $row['file_size'];
            $sizeChanged = ($storedSize === null || (int)$storedSize !== (int)$actualSize);
        } else {
            $missingHashes = true;
            $sizeChanged = true;
            $storedCount = 0;
        }

        hash_log(
            'File: ' . $relativePath .
            ' | stage=compare' .
            ' | db_record=' . ($row ? 'yes' : 'no') .
            ' | missing_hashes=' . ($missingHashes ? 'yes' : 'no') .
            ' | size_changed=' . ($sizeChanged ? 'yes' : 'no') .
            ' | db_size=' . ($storedSize === null ? 'null' : (string)$storedSize)
        );

        if (!$missingHashes && !$sizeChanged) {
            $stats['skipped']++;
            hash_log('File: ' . $relativePath . ' | stage=skip | reason=already_fresh');
            continue;
        }

        if ($missingHashes) {
            $stats['missingHashes']++;
        }
        if ($sizeChanged) {
            $stats['sizeChanged']++;
        }

        if ($options['dryRun']) {
            $stats['hashed']++;
            hash_log('File: ' . $relativePath . ' | stage=dry-run | action=would_update_hashes');
            continue;
        }

        hash_log('File: ' . $relativePath . ' | stage=hashing');
        $md5 = @hash_file('md5', $fullPath);
        $sha256 = @hash_file('sha256', $fullPath);

        if ($md5 === false || $sha256 === false) {
            $stats['errors']++;
            hash_log('WARN: Failed to hash file: ' . $relativePath);
            continue;
        }

        hash_log('File: ' . $relativePath . ' | stage=upsert');
        $upsertStmt->execute([$relativePath, $storedCount, $md5, $sha256, $actualSize]);
        $stats['hashed']++;
        hash_log('File: ' . $relativePath . ' | stage=done | action=updated');

        if ($stats['scanned'] % 50 === 0) {
            hash_log(
                'Progress: scanned=' . $stats['scanned'] .
                ', updated=' . $stats['hashed'] .
                ', skipped=' . $stats['skipped'] .
                ', errors=' . $stats['errors']
            );
        }
    }

    $duration = microtime(true) - $startTime;

    hash_log('Stage: final summary');
    hash_log('Hash sync cron completed');
    hash_log('Scanned: ' . $stats['scanned']);
    hash_log('Updated hashes: ' . $stats['hashed']);
    hash_log('Missing hashes matched: ' . $stats['missingHashes']);
    hash_log('File size changes matched: ' . $stats['sizeChanged']);
    hash_log('Skipped (already fresh): ' . $stats['skipped']);
    hash_log('Errors: ' . $stats['errors']);
    hash_log('Duration: ' . number_format($duration, 2) . 's');

    release_hash_lock($lockHandle);
    exit(0);
} catch (Throwable $e) {
    hash_log('ERROR: Hash sync failed: ' . $e->getMessage());
    release_hash_lock($lockHandle);
    exit(1);
}
