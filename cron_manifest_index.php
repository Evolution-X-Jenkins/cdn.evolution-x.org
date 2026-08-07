<?php
/**
 * Listing Manifest Builder Cron
 *
 * Builds a full directory tree manifest from BASE_PATH, writes JSON atomically,
 * archives the previous JSON snapshot, and mirrors entries into database table.
 *
 * Usage:
 *   php cron_manifest_index.php
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/setup/database.php';
require_once __DIR__ . '/modules/setup/bunny_storage.php';
require_once __DIR__ . '/modules/core/manifest_index.php';

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

$logFile = $logsDir . '/cron_manifest_index_' . date('Y-m-d') . '.log';
$lockFile = $logsDir . '/cron_manifest_index.lock';

function manifest_log($message) {
    global $logFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $entry;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function acquire_manifest_lock($lockFile) {
    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        return [null, 'Unable to open lock file: ' . $lockFile];
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return [null, 'Another manifest build process is already running'];
    }

    @ftruncate($handle, 0);
    @fwrite($handle, 'pid=' . getmypid() . "\nstarted_at=" . date('c') . "\n");
    @fflush($handle);

    return [$handle, null];
}

function release_manifest_lock($handle) {
    if (!is_resource($handle)) {
        return;
    }

    @ftruncate($handle, 0);
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function build_manifest_node_from_bunny($relativePath, $firstSeenMap, $generatedAt, $directoryModifiedAt = 0) {
    $relativePath = bunny_normalize_relative_path($relativePath);
    $name = $relativePath === '/' ? '/' : basename($relativePath);
    $modifiedAt = (int)$directoryModifiedAt;

    $firstSeenAt = isset($firstSeenMap[$relativePath]) ? $firstSeenMap[$relativePath] : $generatedAt;

    $node = [
        'name' => $name,
        'path' => $relativePath,
        'is_dir' => true,
        'size' => 0,
        'modified_at' => $modifiedAt,
        'first_seen_at' => $firstSeenAt,
        'contents' => [],
    ];

    $items = bunny_list_directory_items($relativePath);
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $childName = (string)($item['name'] ?? '');
        if ($childName === '' || listing_should_hide_name($childName)) {
            continue;
        }

        $childRelative = listing_normalize_relative_path((string)($item['path'] ?? ''));
        $childFirstSeenAt = isset($firstSeenMap[$childRelative]) ? $firstSeenMap[$childRelative] : $generatedAt;
        $childModifiedAt = (int)($item['modified_at'] ?? 0);
        $childIsDir = !empty($item['is_dir']);

        if ($childIsDir) {
            $node['contents'][] = build_manifest_node_from_bunny($childRelative, $firstSeenMap, $generatedAt, $childModifiedAt);
            continue;
        }

        $node['contents'][] = [
            'name' => $childName,
            'path' => $childRelative,
            'is_dir' => false,
            'size' => (int)($item['size'] ?? 0),
            'modified_at' => $childModifiedAt,
            'first_seen_at' => $childFirstSeenAt,
        ];
    }

    listing_sort_items($node['contents']);
    return $node;
}

function count_manifest_nodes($node, &$dirs, &$files, &$totalSize) {
    if (!is_array($node)) {
        return;
    }

    if (!empty($node['is_dir'])) {
        $dirs++;
        $children = $node['contents'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                count_manifest_nodes($child, $dirs, $files, $totalSize);
            }
        }
        return;
    }

    $files++;
    $totalSize += max(0, (int)($node['size'] ?? 0));
}

manifest_log('Listing manifest build started');
list($lockHandle, $lockError) = acquire_manifest_lock($lockFile);
if ($lockHandle === null) {
    manifest_log('INFO: ' . $lockError);
    exit(0);
}

$start = microtime(true);
$generatedAt = time();

try {
    if (trim((string)BUNNY_STORAGE_ZONE) === '' || trim((string)BUNNY_STORAGE_ZONE) === 'your-storage-zone') {
        throw new RuntimeException('BUNNY_STORAGE_ZONE is missing');
    }

    if (trim((string)BUNNY_STORAGE_ACCESS_KEY) === '' || trim((string)BUNNY_STORAGE_ACCESS_KEY) === 'your-bunny-storage-access-key') {
        throw new RuntimeException('BUNNY_STORAGE_ACCESS_KEY is missing');
    }

    if (!is_dir(LISTING_MANIFEST_DIR)) {
        @mkdir(LISTING_MANIFEST_DIR, 0755, true);
    }
    if (!is_dir(LISTING_MANIFEST_ARCHIVE_DIR)) {
        @mkdir(LISTING_MANIFEST_ARCHIVE_DIR, 0755, true);
    }

    $firstSeenMap = [];
    $existing = listing_load_manifest_tree();
    if ($existing !== null) {
        listing_collect_first_seen_map($existing, $firstSeenMap);
    }

    manifest_log('Building manifest tree from Bunny storage');
    $tree = build_manifest_node_from_bunny('/', $firstSeenMap, $generatedAt, $generatedAt);
    $tree['generated_at'] = $generatedAt;

    $dirCount = 0;
    $fileCount = 0;
    $totalSize = 0;
    count_manifest_nodes($tree, $dirCount, $fileCount, $totalSize);

    $tree['totals'] = [
        'total_files' => $fileCount,
        'total_size' => $totalSize,
    ];
    $tree['total_files'] = $fileCount;
    $tree['total_size'] = [
        'bytes' => $totalSize,
        'human' => format_file_size($totalSize),
    ];

    $tmpFile = LISTING_MANIFEST_FILE . '.tmp';
    $encoded = json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode manifest JSON');
    }

    if (@file_put_contents($tmpFile, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Failed writing temp manifest file: ' . $tmpFile);
    }

    if (file_exists(LISTING_MANIFEST_FILE)) {
        $archiveName = 'manifest-' . gmdate('Ymd-His') . '.json';
        $archivePath = LISTING_MANIFEST_ARCHIVE_DIR . '/' . $archiveName;
        if (!@copy(LISTING_MANIFEST_FILE, $archivePath)) {
            manifest_log('WARNING: Failed to archive previous manifest snapshot');
        }
    }

    if (!@rename($tmpFile, LISTING_MANIFEST_FILE)) {
        throw new RuntimeException('Failed publishing manifest file: ' . LISTING_MANIFEST_FILE);
    }

    manifest_log('Manifest JSON published: ' . LISTING_MANIFEST_FILE);

    manifest_log('Mirroring manifest to database');
    $pdo = Database::getInstance()->getConnection();
    listing_store_tree_in_db($pdo, $tree, $generatedAt);

    $duration = microtime(true) - $start;
    $status = [
        'status' => 'ok',
        'generated_at' => $generatedAt,
        'duration_seconds' => round($duration, 3),
        'directories_indexed' => max(0, $dirCount - 1),
        'files_indexed' => $fileCount,
        'total_files' => $fileCount,
        'total_size' => $totalSize,
        'source' => 'bunny_storage',
        'strict_mode' => true,
    ];

    listing_write_status_file($status);
    manifest_log('Manifest build complete in ' . number_format($duration, 2) . 's');
    manifest_log('Directories indexed: ' . max(0, $dirCount - 1));
    manifest_log('Files indexed: ' . $fileCount);
    manifest_log('Total size: ' . format_file_size($totalSize));

    release_manifest_lock($lockHandle);
    exit(0);
} catch (Throwable $e) {
    $duration = microtime(true) - $start;
    manifest_log('ERROR: ' . $e->getMessage());

    listing_write_status_file([
        'status' => 'error',
        'generated_at' => $generatedAt,
        'duration_seconds' => round($duration, 3),
        'error' => $e->getMessage(),
        'strict_mode' => true,
    ]);

    release_manifest_lock($lockHandle);
    exit(1);
}
