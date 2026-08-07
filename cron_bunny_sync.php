<?php
/**
 * Bunny Mount Sync Cron Worker
 *
 * Mirrors new or changed files from the local mount into Bunny storage.
 * Intended for workflows where users still SCP into the local directory.
 *
 * Usage:
 *   php cron_bunny_sync.php [--path=subdir] [--state-file=/path/to/state.json] [--dry-run] [--limit=1000]
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/bunny_storage.php';
require_once __DIR__ . '/modules/core/cache.php';

function bunny_sync_log($message) {
    global $bunnySyncLogFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $entry;
    @file_put_contents($bunnySyncLogFile, $entry, FILE_APPEND | LOCK_EX);
}

function bunny_sync_parse_args(array $argv) {
    $options = [
        'path' => '',
        'stateFile' => __DIR__ . '/data/cache/bunny_mount_sync_state.json',
        'dryRun' => false,
        'limit' => 0,
        'includeHidden' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }

        if ($arg === '--include-hidden') {
            $options['includeHidden'] = true;
            continue;
        }

        if (strpos($arg, '--path=') === 0) {
            $options['path'] = trim(substr($arg, 7), '/');
            continue;
        }

        if (strpos($arg, '--state-file=') === 0) {
            $options['stateFile'] = trim(substr($arg, 13));
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $options['limit'] = max(0, (int)substr($arg, 8));
            continue;
        }
    }

    return $options;
}

function bunny_sync_resolve_source_root($relativePath) {
    $relativePath = trim((string)$relativePath, '/');
    $candidate = $relativePath === '' ? BASE_PATH : BASE_PATH . '/' . $relativePath;

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

function bunny_sync_load_state($stateFile) {
    if (!file_exists($stateFile)) {
        return [
            'updated_at' => null,
            'files' => [],
        ];
    }

    $raw = @file_get_contents($stateFile);
    if ($raw === false || $raw === '') {
        return [
            'updated_at' => null,
            'files' => [],
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'updated_at' => null,
            'files' => [],
        ];
    }

    if (!isset($decoded['files']) || !is_array($decoded['files'])) {
        $decoded['files'] = [];
    }

    return $decoded;
}

function bunny_sync_save_state($stateFile, array $state) {
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $state['updated_at'] = date('c');
    $tempFile = $stateFile . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tempFile, $stateFile);
}

function bunny_sync_is_hidden_name($name, $includeHidden) {
    if ($includeHidden) {
        return false;
    }

    if (function_exists('bunny_should_hide_name')) {
        return bunny_should_hide_name($name);
    }

    return $name === '' || $name === '.' || $name === '..';
}

function bunny_sync_invalidate_listing_cache($relativePath) {
    try {
        $cache = CacheManager::getInstance();
        $normalized = bunny_normalize_relative_path('/' . ltrim((string)$relativePath, '/'));

        $paths = ['/'];
        if ($normalized !== '/') {
            $segments = explode('/', trim($normalized, '/'));
            $current = '';
            foreach ($segments as $segment) {
                $current .= '/' . $segment;
                $paths[] = $current;
            }
        }

        foreach (array_unique($paths) as $path) {
            $cache->delete('bunny_listing:' . md5($path));
        }
    } catch (Throwable $e) {
        error_log('Bunny sync cache invalidation failed for ' . $relativePath . ': ' . $e->getMessage());
    }
}

function bunny_sync_upload_file($client, $sourceRoot, $fullPath, &$state, $dryRun, $includeHidden) {
    $relativePath = ltrim(str_replace($sourceRoot, '', $fullPath), '/');
    $name = basename($fullPath);

    if (bunny_sync_is_hidden_name($name, $includeHidden)) {
        return ['status' => 'skipped', 'reason' => 'hidden'];
    }

    $fileSize = @filesize($fullPath);
    $fileMTime = @filemtime($fullPath);
    if ($fileSize === false || $fileMTime === false) {
        return ['status' => 'error', 'reason' => 'stat_failed'];
    }

    $stored = $state['files'][$relativePath] ?? null;
    if (is_array($stored)
        && (int)($stored['size'] ?? -1) === (int)$fileSize
        && (int)($stored['mtime'] ?? -1) === (int)$fileMTime) {
        return ['status' => 'skipped', 'reason' => 'unchanged'];
    }

    if ($dryRun) {
        return ['status' => 'would_upload'];
    }

    $remotePath = str_replace('\\', '/', $relativePath);
    $client->upload($fullPath, $remotePath);

    $state['files'][$relativePath] = [
        'size' => (int)$fileSize,
        'mtime' => (int)$fileMTime,
        'synced_at' => date('c'),
    ];

    bunny_sync_invalidate_listing_cache(dirname($relativePath));

    return ['status' => 'uploaded'];
}

$options = bunny_sync_parse_args($argv);
$bunnySyncLogFile = __DIR__ . '/logs/cron_bunny_sync_' . date('Y-m-d') . '.log';
$lockFile = __DIR__ . '/logs/cron_bunny_sync.lock';

if (!is_dir(dirname($bunnySyncLogFile))) {
    @mkdir(dirname($bunnySyncLogFile), 0755, true);
}

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    bunny_sync_log('ERROR: Unable to open lock file: ' . $lockFile);
    exit(1);
}

if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    bunny_sync_log('INFO: Another bunny sync process is already running');
    @fclose($lockHandle);
    exit(0);
}

try {
    $sourceRoot = bunny_sync_resolve_source_root($options['path']);
    if ($sourceRoot === null || !is_dir($sourceRoot) || !is_readable($sourceRoot)) {
        bunny_sync_log('ERROR: Invalid or unreadable source root');
        exit(1);
    }

    $client = bunny_get_storage_client();
    $stateFile = $options['stateFile'];
    $state = bunny_sync_load_state($stateFile);

    $stats = [
        'scanned' => 0,
        'uploaded' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    bunny_sync_log('Bunny mount sync started');
    bunny_sync_log('Source root: ' . $sourceRoot);
    bunny_sync_log('Dry run: ' . ($options['dryRun'] ? 'yes' : 'no'));
    bunny_sync_log('State file: ' . $stateFile);

    $baseReal = realpath(BASE_PATH);
    if ($baseReal === false) {
        bunny_sync_log('ERROR: Unable to resolve BASE_PATH');
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $client = bunny_get_storage_client();

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        if ($options['limit'] > 0 && $stats['scanned'] >= $options['limit']) {
            bunny_sync_log('Limit reached (' . $options['limit'] . '), stopping scan');
            break;
        }

        $stats['scanned']++;

        $fullPath = $fileInfo->getPathname();
        if (!is_readable($fullPath)) {
            $stats['errors']++;
            bunny_sync_log('WARN: Unreadable file skipped: ' . $fullPath);
            continue;
        }

        $relativePath = ltrim(str_replace($baseReal, '', $fullPath), '/');
        if ($relativePath === '') {
            continue;
        }

        try {
            $result = bunny_sync_upload_file($client, $baseReal, $fullPath, $state, $options['dryRun'], $options['includeHidden']);

            if ($result['status'] === 'uploaded') {
                $stats['uploaded']++;
                bunny_sync_log('Uploaded: ' . $relativePath);
                bunny_sync_save_state($stateFile, $state);
            } elseif ($result['status'] === 'would_upload') {
                $stats['uploaded']++;
                bunny_sync_log('Would upload: ' . $relativePath);
            } else {
                $stats['skipped']++;
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            bunny_sync_log('ERROR: Upload failed for ' . $relativePath . ': ' . $e->getMessage());
        }
    }

    bunny_sync_save_state($stateFile, $state);

    bunny_sync_log('Bunny mount sync completed');
    bunny_sync_log('Scanned: ' . $stats['scanned']);
    bunny_sync_log('Uploaded: ' . $stats['uploaded']);
    bunny_sync_log('Skipped: ' . $stats['skipped']);
    bunny_sync_log('Errors: ' . $stats['errors']);

    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
    exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    bunny_sync_log('ERROR: Bunny mount sync failed: ' . $e->getMessage());
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
    exit(1);
}
