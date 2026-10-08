<?php
/**
 * Hash Sync Cron Worker
 *
 * Scans files under BASE_PATH and updates download_stat hashes when:
 * - md5 or sha256 is missing
 * - file_size in database differs from actual file size
 * - a ZIP file's timestamp in its GitHub OTA release entry has changed
 *
 * Usage:
 *   php cron_hashes.php [--dry-run] [--limit=1000] [--path=subdir]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/database.php';
require_once __DIR__ . '/modules/core/ota_hashes.php';

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
        'allowRemoteScan' => false,
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

        if ($arg === '--allow-remote-scan') {
            $options['allowRemoteScan'] = true;
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

$remoteMount = cron_is_remote_mount_path($scanRoot);
$allowRemoteScan = $options['allowRemoteScan'] || cron_env_bool('ENABLE_HASH_SCAN_ON_REMOTE', false);

if ($remoteMount && !$allowRemoteScan) {
    hash_log('SKIP: Remote mount detected; refusing full-tree hash scan. Set ENABLE_HASH_SCAN_ON_REMOTE=true or pass --allow-remote-scan to override.');
    release_hash_lock($lockHandle);
    exit(0);
}

$effectiveLimit = (int)$options['limit'];
if ($effectiveLimit <= 0) {
    if ($remoteMount) {
        // Sensible safety cap for remote mounts to avoid accidental full scans.
        $effectiveLimit = (int)getenv('CRON_HASHES_DEFAULT_LIMIT');
        if ($effectiveLimit <= 0) {
            $effectiveLimit = 2000;
        }
    } else {
        $effectiveLimit = 0;
    }
}

hash_log('Limit: ' . ($effectiveLimit > 0 ? $effectiveLimit : 'none'));
hash_log('Remote mount: ' . ($remoteMount ? 'yes' : 'no'));
hash_log('Remote scan override: ' . ($allowRemoteScan ? 'yes' : 'no'));

$startTime = microtime(true);

try {
    hash_log('Stage: database setup');
    $pdo = Database::getInstance()->getConnection();
    require_once __DIR__ . '/modules/core/cache.php';
    $hashCache = CacheManager::getInstance();
    ensure_download_stat_table($pdo);

    $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';

    $selectStmt = $pdo->prepare(
        'SELECT count, md5, sha256, file_size, ota_json_timestamp FROM download_stat WHERE ' . $keyColumn . ' = ? LIMIT 1'
    );

    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $upsertSql = '
            INSERT INTO download_stat (`key`, count, md5, sha256, file_size, ota_json_timestamp)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                md5 = VALUES(md5),
                sha256 = VALUES(sha256),
                file_size = VALUES(file_size),
                ota_json_timestamp = COALESCE(VALUES(ota_json_timestamp), ota_json_timestamp)
        ';
    } else {
        $upsertSql = '
            INSERT INTO download_stat (key_path, count, md5, sha256, file_size, ota_json_timestamp)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(key_path) DO UPDATE SET
                md5 = excluded.md5,
                sha256 = excluded.sha256,
                file_size = excluded.file_size,
                ota_json_timestamp = COALESCE(excluded.ota_json_timestamp, download_stat.ota_json_timestamp)
        ';
    }

    $upsertStmt = $pdo->prepare($upsertSql);

    $stats = [
        'scanned' => 0,
        'hashed' => 0,
        'missingHashes' => 0,
        'sizeChanged' => 0,
        'otaTimestampChanged' => 0,
        'otaHashes' => 0,
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

        if ($effectiveLimit > 0 && $stats['scanned'] >= $effectiveLimit) {
            hash_log('Limit reached (' . $effectiveLimit . '), stopping scan');
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
        $timestampChanged = false;
        $storedCount = 0;
        $storedSize = null;
        $storedOtaTimestamp = null;
        $fileExtension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $isImgFile = $fileExtension === 'img';
        $isZipFile = $fileExtension === 'zip';
        $otaRelease = null;
        $otaTimestamp = null;

        if ($isZipFile) {
            $normalizedRelativePath = str_replace('\\', '/', $relativePath);
            $pathParts = explode('/', $normalizedRelativePath);
            $deviceName = $pathParts[0] ?? '';
            $otaRelease = findOtaReleaseForFile($deviceName, basename($normalizedRelativePath));
            if ($otaRelease !== null && isset($otaRelease['timestamp'])) {
                $otaTimestamp = (int)$otaRelease['timestamp'];
            }
        }

        if ($row) {
            $storedCount = (int)($row['count'] ?? 0);
            $missingHashes = empty($row['md5']) || empty($row['sha256']);
            $storedSize = $row['file_size'];
            $sizeChanged = ($storedSize === null || (int)$storedSize !== (int)$actualSize);
            $storedOtaTimestamp = isset($row['ota_json_timestamp'])
                ? (int)$row['ota_json_timestamp']
                : null;
            $timestampChanged = $otaTimestamp !== null
                && $storedOtaTimestamp !== $otaTimestamp;
        } else {
            $missingHashes = true;
            $sizeChanged = true;
            $timestampChanged = $otaTimestamp !== null;
            $storedCount = 0;
        }

        hash_log(
            'File: ' . $relativePath .
            ' | stage=compare' .
            ' | db_record=' . ($row ? 'yes' : 'no') .
            ' | missing_hashes=' . ($missingHashes ? 'yes' : 'no') .
            ' | size_changed=' . ($sizeChanged ? 'yes' : 'no') .
            ' | ota_timestamp_changed=' . ($timestampChanged ? 'yes' : 'no') .
            ' | db_size=' . ($storedSize === null ? 'null' : (string)$storedSize) .
            ' | db_ota_timestamp=' . ($storedOtaTimestamp === null ? 'null' : (string)$storedOtaTimestamp) .
            ' | ota_timestamp=' . ($otaTimestamp === null ? 'null' : (string)$otaTimestamp)
        );

        if (!$missingHashes && !$sizeChanged && !$timestampChanged && !$isImgFile) {
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
        if ($timestampChanged) {
            $stats['otaTimestampChanged']++;
        }

        if ($options['dryRun']) {
            $stats['hashed']++;
            hash_log('File: ' . $relativePath . ' | stage=dry-run | action=would_update_hashes');
            continue;
        }

        $useOtaHashes = $isZipFile
            && $otaRelease !== null
            && !empty($otaRelease['md5'])
            && !empty($otaRelease['sha256']);

        if ($useOtaHashes) {
            hash_log(
                'File: ' . $relativePath .
                ' | stage=hashing | source=ota | branch=' . ($otaRelease['branch'] ?? 'unknown')
            );
            $md5 = $otaRelease['md5'];
            $sha256 = $otaRelease['sha256'];
            $stats['otaHashes']++;
        } else {
            hash_log('File: ' . $relativePath . ' | stage=hashing | source=file');
            $md5 = @hash_file('md5', $fullPath);
            $sha256 = @hash_file('sha256', $fullPath);
        }

        if ($md5 === false || $sha256 === false) {
            $stats['errors']++;
            hash_log('WARN: Failed to hash file: ' . $relativePath);
            continue;
        }

        hash_log('File: ' . $relativePath . ' | stage=upsert');
        $upsertStmt->execute([
            $relativePath,
            $storedCount,
            $md5,
            $sha256,
            $actualSize,
            $otaTimestamp
        ]);
        $hashCache->delete('hash:' . $relativePath);
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
    hash_log('ZIP OTA timestamp changes matched: ' . $stats['otaTimestampChanged']);
    hash_log('ZIP hashes sourced from OTA: ' . $stats['otaHashes']);
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
