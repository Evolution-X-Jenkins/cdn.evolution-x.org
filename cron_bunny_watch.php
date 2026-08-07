<?php
/**
 * Bunny Release Scanner (cron mode)
 *
 * Runs for a finite window, snapshots release files to JSON, verifies stability,
 * queues stable releases to push queue, and removes queued entries from snapshot.
 *
 * Suggested cron: every 5 minutes.
 *
 * Usage:
 *   php cron_bunny_watch.php --roots=/mnt/evolution-x --interval=5 --stable-seconds=30 --run-seconds=30
 *   php cron_bunny_watch.php --dry-run
 */

if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/modules/setup/config.php';
require_once __DIR__ . '/modules/api/push.php';

function bunny_watch_log($message) {
    global $bunnyWatchLogFile;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $entry;
    @file_put_contents($bunnyWatchLogFile, $entry, FILE_APPEND | LOCK_EX);
}

function bunny_watch_parse_args(array $argv) {
    $options = [
        'roots' => [],
        'interval' => 5,
        'stableSeconds' => 30,
        'runSeconds' => 30,
        'stateFile' => __DIR__ . '/data/cache/bunny_watch_pending.json',
        'dryRun' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--roots=') === 0) {
            $rawRoots = array_map('trim', explode(',', substr($arg, 8)));
            $options['roots'] = array_values(array_filter($rawRoots, static function($root) {
                return $root !== '';
            }));
            continue;
        }

        if (strpos($arg, '--interval=') === 0) {
            $options['interval'] = max(1, (int)substr($arg, 11));
            continue;
        }

        if (strpos($arg, '--stable-seconds=') === 0) {
            $options['stableSeconds'] = max(1, (int)substr($arg, 17));
            continue;
        }

        if (strpos($arg, '--run-seconds=') === 0) {
            $options['runSeconds'] = max(1, (int)substr($arg, 14));
            continue;
        }

        if (strpos($arg, '--state-file=') === 0) {
            $options['stateFile'] = trim(substr($arg, 13));
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
        }
    }

    if (empty($options['roots'])) {
        $envRoots = trim((string)(getenv('BUNNY_WATCH_ROOTS') ?: ''));
        if ($envRoots !== '') {
            $options['roots'] = array_values(array_filter(array_map('trim', explode(',', $envRoots)), static function($root) {
                return $root !== '';
            }));
        }
    }

    if (empty($options['roots'])) {
        $options['roots'] = [BASE_PATH];
    }

    return $options;
}

function bunny_watch_load_state($stateFile) {
    if (!file_exists($stateFile)) {
        return ['items' => []];
    }

    $raw = @file_get_contents($stateFile);
    if ($raw === false || $raw === '') {
        return ['items' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['items' => []];
    }

    if (!isset($decoded['items']) || !is_array($decoded['items'])) {
        $decoded['items'] = [];
    }

    return $decoded;
}

function bunny_watch_save_state($stateFile, array $state) {
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $tmpFile = $stateFile . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmpFile, $stateFile);
}

function bunny_watch_discover_release_directories($rootPath) {
    $resolvedRoot = realpath($rootPath);
    if ($resolvedRoot === false || !is_dir($resolvedRoot) || !is_readable($resolvedRoot)) {
        return [];
    }

    $result = [];
    $firstLevel = @scandir($resolvedRoot);
    if (!is_array($firstLevel)) {
        return [];
    }

    foreach ($firstLevel as $codename) {
        if ($codename === '.' || $codename === '..') {
            continue;
        }

        $codenamePath = $resolvedRoot . '/' . $codename;
        if (!is_dir($codenamePath)) {
            continue;
        }

        $secondLevel = @scandir($codenamePath);
        if (!is_array($secondLevel)) {
            continue;
        }

        foreach ($secondLevel as $release) {
            if ($release === '.' || $release === '..') {
                continue;
            }

            $releasePath = $codenamePath . '/' . $release;
            if (!is_dir($releasePath) || !is_readable($releasePath)) {
                continue;
            }

            $result[] = $releasePath;
        }
    }

    sort($result, SORT_STRING);
    return $result;
}

function bunny_watch_collect_release_files($releasePath) {
    $resolved = realpath($releasePath);
    if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
        return null;
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        $relativePath = str_replace('\\', '/', ltrim(str_replace($resolved, '', $path), '/'));

        $files[] = [
            'path' => $relativePath,
            'file_size' => (int)@filesize($path),
            'last_modified' => (int)@filemtime($path),
        ];
    }

    usort($files, static function($a, $b) {
        return strcmp($a['path'], $b['path']);
    });

    if (empty($files)) {
        return null;
    }

    $signatureSource = [];
    foreach ($files as $file) {
        $signatureSource[] = $file['path'] . ':' . $file['file_size'] . ':' . $file['last_modified'];
    }

    return [
        'path' => $resolved,
        'files' => $files,
        'signature' => hash('sha256', implode('|', $signatureSource)),
    ];
}

function bunny_watch_queue_mirror_job($sourceDirectory, $requestedBy = 'watcher') {
    $sourceDirectory = realpath($sourceDirectory) ?: rtrim((string)$sourceDirectory, '/');
    if ($sourceDirectory === '' || !is_dir($sourceDirectory)) {
        return [
            'status' => 'error',
            'message' => 'Source directory not found: ' . $sourceDirectory,
        ];
    }

    $destinationPath = bunny_normalize_relative_path(str_replace(BASE_PATH, '', $sourceDirectory));
    if ($destinationPath === '/') {
        return [
            'status' => 'error',
            'message' => 'Refusing to queue the storage root',
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();
        $existingJob = findPushQueueJobBySourcePath($db, $sourceDirectory);
        if (is_array($existingJob)) {
            $existingStatus = strtolower((string)($existingJob['status'] ?? ''));
            if (in_array($existingStatus, ['queued', 'processing'], true)) {
                return [
                    'status' => 'queued',
                    'jobId' => (int)($existingJob['id'] ?? 0),
                    'sourcePath' => $sourceDirectory,
                    'destinationPath' => $destinationPath,
                    'existing' => true,
                    'existingStatus' => $existingStatus,
                ];
            }

            $nowExpr = getDatabaseNowExpression();
            $requeueSql = "UPDATE push_release_queue
                SET status = 'queued',
                    success = NULL,
                    error_message = NULL,
                    callback_http_code = NULL,
                    callback_response = NULL,
                    started_at = NULL,
                    completed_at = NULL,
                    requested_by = ?,
                    updated_at = $nowExpr
                WHERE id = ?";
            $requeueStmt = $db->prepare($requeueSql);
            $requeueStmt->execute([$requestedBy, (int)$existingJob['id']]);

            return [
                'status' => 'queued',
                'jobId' => (int)($existingJob['id'] ?? 0),
                'sourcePath' => $sourceDirectory,
                'destinationPath' => $destinationPath,
                'existing' => true,
                'requeued' => true,
                'existingStatus' => $existingStatus,
            ];
        }

        $stmt = $db->prepare('
            INSERT INTO push_release_queue
                (codename, release_date, version, build_type, requested_by, source_path, destination_path, status, callback_enabled, callback_url)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            basename(dirname($sourceDirectory)) ?: basename($sourceDirectory),
            date('Y-m-d'),
            basename($sourceDirectory) ?: 'mirror',
            'mirror',
            $requestedBy,
            $sourceDirectory,
            $destinationPath,
            'queued',
            0,
            null,
        ]);

        return [
            'status' => 'queued',
            'jobId' => (int)$db->lastInsertId(),
            'sourcePath' => $sourceDirectory,
            'destinationPath' => $destinationPath,
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

$options = bunny_watch_parse_args($argv);
$bunnyWatchLogFile = __DIR__ . '/logs/cron_bunny_watch_' . date('Y-m-d') . '.log';
$lockFile = __DIR__ . '/logs/cron_bunny_watch.lock';

if (!is_dir(dirname($bunnyWatchLogFile))) {
    @mkdir(dirname($bunnyWatchLogFile), 0755, true);
}

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    bunny_watch_log('ERROR: Unable to open lock file: ' . $lockFile);
    exit(1);
}

if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    bunny_watch_log('INFO: Another bunny scanner process is already running');
    @fclose($lockHandle);
    exit(0);
}

register_shutdown_function(function() use ($lockHandle) {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

$state = bunny_watch_load_state($options['stateFile']);
$state['items'] = is_array($state['items']) ? $state['items'] : [];

bunny_watch_log('Bunny scanner started');
bunny_watch_log('Roots: ' . implode(', ', $options['roots']));
bunny_watch_log('Interval: ' . $options['interval'] . 's');
bunny_watch_log('Stable window: ' . $options['stableSeconds'] . 's');
bunny_watch_log('Run window: ' . $options['runSeconds'] . 's');
bunny_watch_log('Snapshot file: ' . $options['stateFile']);
bunny_watch_log('Dry run: ' . ($options['dryRun'] ? 'yes' : 'no'));

$startedAt = time();
$pass = 0;

while (true) {
    $pass++;
    $now = time();
    $seen = [];
    $scannedReleases = 0;
    $queuedThisPass = 0;

    foreach ($options['roots'] as $root) {
        $releaseDirs = bunny_watch_discover_release_directories($root);
        foreach ($releaseDirs as $releaseDir) {
            $collected = bunny_watch_collect_release_files($releaseDir);
            if ($collected === null) {
                continue;
            }

            $scannedReleases++;
            $pathKey = $collected['path'];
            $seen[$pathKey] = true;
            $entry = $state['items'][$pathKey] ?? null;

            if (!is_array($entry)) {
                $state['items'][$pathKey] = [
                    'path' => $collected['path'],
                    'files' => $collected['files'],
                    'signature' => $collected['signature'],
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ];
                bunny_watch_log('Discovered release: ' . $collected['path'] . ' files=' . count($collected['files']));
                continue;
            }

            if ((string)($entry['signature'] ?? '') !== $collected['signature']) {
                $state['items'][$pathKey] = [
                    'path' => $collected['path'],
                    'files' => $collected['files'],
                    'signature' => $collected['signature'],
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ];
                bunny_watch_log('Change detected: ' . $collected['path'] . ' files=' . count($collected['files']) . ' restarting stability timer');
                continue;
            }

            $state['items'][$pathKey]['files'] = $collected['files'];
            $state['items'][$pathKey]['last_seen_at'] = $now;

            $stableFor = $now - (int)($entry['first_seen_at'] ?? $now);
            if ($stableFor < $options['stableSeconds']) {
                continue;
            }

            if ($options['dryRun']) {
                bunny_watch_log('DRY RUN: stable release would be queued: ' . $collected['path'] . ' stable_for=' . $stableFor . 's');
                unset($state['items'][$pathKey]);
                continue;
            }

            $result = bunny_watch_queue_mirror_job($collected['path'], 'watcher');
            if (!empty($result['status']) && $result['status'] === 'queued') {
                $queuedThisPass++;
                if (!empty($result['requeued'])) {
                    bunny_watch_log('Re-queued release: ' . $collected['path'] . ' job=' . ($result['jobId'] ?? 'n/a') . ' previous_status=' . ($result['existingStatus'] ?? 'unknown'));
                } elseif (!empty($result['existing'])) {
                    bunny_watch_log('Release already active in queue: ' . $collected['path'] . ' job=' . ($result['jobId'] ?? 'n/a') . ' status=' . ($result['existingStatus'] ?? 'unknown'));
                } else {
                    bunny_watch_log('Queued release: ' . $collected['path'] . ' job=' . ($result['jobId'] ?? 'n/a'));
                }

                // Once handed to queue, remove this item from snapshot file.
                unset($state['items'][$pathKey]);
            } else {
                bunny_watch_log('Queue skipped for ' . $collected['path'] . ': ' . json_encode($result));
            }
        }
    }

    foreach (array_keys($state['items']) as $trackedPath) {
        if (!isset($seen[$trackedPath]) && !is_dir($trackedPath)) {
            unset($state['items'][$trackedPath]);
        }
    }

    bunny_watch_save_state($options['stateFile'], $state);

    bunny_watch_log('Pass ' . $pass . ' complete: scanned_releases=' . $scannedReleases . ' pending=' . count($state['items']) . ' queued=' . $queuedThisPass);

    if ((time() - $startedAt) >= $options['runSeconds']) {
        break;
    }

    sleep($options['interval']);
}

bunny_watch_log('Bunny scanner completed after ' . (time() - $startedAt) . 's');
