<?php
/**
 * Remote Bunny Uploader (standalone)
 *
 * Single-file cron worker for a remote source server.
 * - No DB required
 * - No project module dependencies
 * - Maintains JSON state/queue locally
 * - Uploads stable .zip/.img files to Bunny Storage
 * - Sends Discord success/failure notifications with source host context
 *
 * Example cron (every minute):
 * * * * * /usr/bin/php /opt/scripts/cron_remote_bunny_uploader.php >> /var/log/remote_bunny_uploader.log 2>&1
 *
 * Optional args:
 *   --dry-run
 *   --roots=/mnt/pre-release
 *   --stable-seconds=30
 *   --state-file=/var/lib/remote_bunny_uploader/state.json
 *   --log-file=/var/log/remote_bunny_uploader.log
 */

if (isset($_SERVER['HTTP_HOST'])) {
    fwrite(STDERR, "This script can only run from CLI\n");
    exit(1);
}

function ru_load_env($file = '.env') {
    $candidateFiles = [];

    if ($file !== '') {
        $candidateFiles[] = $file;
    }

    $candidateFiles[] = __DIR__ . '/.env';
    $candidateFiles[] = getcwd() . '/.env';

    foreach (array_unique($candidateFiles) as $candidateFile) {
        if ($candidateFile === '' || !file_exists($candidateFile)) {
            continue;
        }

        $lines = @file($candidateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }

        return true;
    }

    return false;
}

ru_load_env();

date_default_timezone_set(getenv('TZ') ?: 'UTC');

$config = [
    'watch_roots' => array_values(array_filter(array_map('trim', explode(',', (string)(getenv('REMOTE_UPLOAD_WATCH_ROOTS') ?: '/mnt/pre-release'))))),
    'stable_seconds' => max(5, (int)(getenv('REMOTE_UPLOAD_STABLE_SECONDS') ?: 30)),
    'state_file' => (string)(getenv('REMOTE_UPLOAD_STATE_FILE') ?: __DIR__ . '/data/cache/remote_bunny_uploader_state.json'),
    'lock_file' => (string)(getenv('REMOTE_UPLOAD_LOCK_FILE') ?: __DIR__ . '/data/cache/remote_bunny_uploader.lock'),
    'log_file' => (string)(getenv('REMOTE_UPLOAD_LOG_FILE') ?: __DIR__ . '/logs/remote_bunny_uploader_' . date('Y-m-d') . '.log'),
    'upload_extensions' => ['zip', 'img'],
    'delete_source_after_success' => filter_var((string)(getenv('REMOTE_UPLOAD_DELETE_SOURCE') ?: 'false'), FILTER_VALIDATE_BOOLEAN),
    'max_jobs_per_run' => max(1, (int)(getenv('REMOTE_UPLOAD_MAX_JOBS_PER_RUN') ?: 5)),
    'dry_run' => false,
    'source_label' => trim((string)(getenv('REMOTE_UPLOAD_SOURCE_LABEL') ?: 'Remote Source Server')),
    'bunny' => [
        'zone' => trim((string)(getenv('BUNNY_STORAGE_ZONE') ?: '')),
        'access_key' => trim((string)(getenv('BUNNY_STORAGE_ACCESS_KEY') ?: '')),
        'region' => strtolower(trim((string)(getenv('BUNNY_STORAGE_REGION') ?: 'falkenstein'))),
        'remote_base_prefix' => trim((string)(getenv('REMOTE_UPLOAD_BUNNY_PREFIX') ?: ''), '/'),
    ],
    'discord' => [
        'success_url' => trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_URL') ?: '')),
        'failure_url' => trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_URL') ?: '')),
        'username' => trim((string)(getenv('DISCORD_REMOTE_UPLOAD_WEBHOOK_USERNAME') ?: 'Remote Upload Worker')),
        'avatar_url' => trim((string)(getenv('DISCORD_REMOTE_UPLOAD_WEBHOOK_AVATAR_URL') ?: '')),
        'mention_user_id' => trim((string)(getenv('DISCORD_REMOTE_UPLOAD_MENTION_USER_ID') ?: '')),
    ],
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $config['dry_run'] = true;
        continue;
    }

    if (strpos($arg, '--roots=') === 0) {
        $roots = array_values(array_filter(array_map('trim', explode(',', substr($arg, 8)))));
        if (!empty($roots)) {
            $config['watch_roots'] = $roots;
        }
        continue;
    }

    if (strpos($arg, '--stable-seconds=') === 0) {
        $config['stable_seconds'] = max(5, (int)substr($arg, 17));
        continue;
    }

    if (strpos($arg, '--state-file=') === 0) {
        $config['state_file'] = trim(substr($arg, 13));
        continue;
    }

    if (strpos($arg, '--log-file=') === 0) {
        $config['log_file'] = trim(substr($arg, 11));
        continue;
    }
}

function ru_now_iso() {
    return gmdate('c');
}

function ru_host() {
    return gethostname() ?: php_uname('n') ?: 'unknown-host';
}

function ru_log($message) {
    global $config;

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;

    $logFile = $config['log_file'];
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function ru_region_base_url($region) {
    $map = [
        'falkenstein' => 'https://storage.bunnycdn.com',
        'de' => 'https://storage.bunnycdn.com',
        'uk' => 'https://uk.storage.bunnycdn.com',
        'london' => 'https://uk.storage.bunnycdn.com',
        'ny' => 'https://ny.storage.bunnycdn.com',
        'newyork' => 'https://ny.storage.bunnycdn.com',
        'la' => 'https://la.storage.bunnycdn.com',
        'losangeles' => 'https://la.storage.bunnycdn.com',
        'sg' => 'https://sg.storage.bunnycdn.com',
        'singapore' => 'https://sg.storage.bunnycdn.com',
        'se' => 'https://se.storage.bunnycdn.com',
        'stockholm' => 'https://se.storage.bunnycdn.com',
        'br' => 'https://br.storage.bunnycdn.com',
        'saopaulo' => 'https://br.storage.bunnycdn.com',
    ];

    $key = strtolower(trim((string)$region));
    return $map[$key] ?? 'https://storage.bunnycdn.com';
}

function ru_load_state($stateFile) {
    if (!file_exists($stateFile)) {
        return ['jobs' => [], 'updated_at' => null];
    }

    $raw = @file_get_contents($stateFile);
    if ($raw === false || $raw === '') {
        return ['jobs' => [], 'updated_at' => null];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['jobs' => [], 'updated_at' => null];
    }

    if (!isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
        $decoded['jobs'] = [];
    }

    return $decoded;
}

function ru_save_state($stateFile, array $state) {
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $state['updated_at'] = ru_now_iso();
    $tmp = $stateFile . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, $stateFile);
}

function ru_is_candidate_file($path) {
    global $config;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $config['upload_extensions'], true);
}

function ru_discover_release_dirs(array $roots) {
    $result = [];

    foreach ($roots as $root) {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot) || !is_readable($resolvedRoot)) {
            ru_log('WARN: Skipping unreadable root: ' . $root);
            continue;
        }

        $firstLevel = @scandir($resolvedRoot);
        if (!is_array($firstLevel)) {
            continue;
        }

        foreach ($firstLevel as $codename) {
            if ($codename === '.' || $codename === '..' || strpos($codename, '.') === 0) {
                continue;
            }

            $codenamePath = $resolvedRoot . '/' . $codename;
            if (!is_dir($codenamePath) || !is_readable($codenamePath)) {
                continue;
            }

            $secondLevel = @scandir($codenamePath);
            if (!is_array($secondLevel)) {
                continue;
            }

            foreach ($secondLevel as $release) {
                if ($release === '.' || $release === '..' || strpos($release, '.') === 0) {
                    continue;
                }

                $releasePath = $codenamePath . '/' . $release;
                if (!is_dir($releasePath) || !is_readable($releasePath)) {
                    continue;
                }

                $result[] = [
                    'root' => $resolvedRoot,
                    'codename' => $codename,
                    'release' => $release,
                    'path' => $releasePath,
                ];
            }
        }
    }

    usort($result, static function($a, $b) {
        return strcmp($a['path'], $b['path']);
    });

    return $result;
}

function ru_collect_release_snapshot($releasePath) {
    $resolved = realpath($releasePath);
    if ($resolved === false || !is_dir($resolved)) {
        return null;
    }

    $files = [];
    $signatureSource = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $fullPath = $entry->getPathname();
        $relativePath = str_replace('\\', '/', ltrim(str_replace($resolved, '', $fullPath), '/'));
        if (!ru_is_candidate_file($relativePath)) {
            continue;
        }

        $size = (int)@filesize($fullPath);
        $mtime = (int)@filemtime($fullPath);

        $files[] = [
            'relative_path' => $relativePath,
            'full_path' => $fullPath,
            'size' => $size,
            'mtime' => $mtime,
        ];

        $signatureSource[] = $relativePath . ':' . $size . ':' . $mtime;
    }

    usort($files, static function($a, $b) {
        return strcmp($a['relative_path'], $b['relative_path']);
    });

    if (empty($files)) {
        return null;
    }

    return [
        'files' => $files,
        'signature' => hash('sha256', implode('|', $signatureSource)),
        'file_count' => count($files),
        'total_size' => array_sum(array_column($files, 'size')),
    ];
}

function ru_job_key($releasePath) {
    return hash('sha256', (string)$releasePath);
}

function ru_relative_destination($codename, $release, $relativePath) {
    global $config;

    $parts = [];
    if ($config['bunny']['remote_base_prefix'] !== '') {
        $parts[] = trim($config['bunny']['remote_base_prefix'], '/');
    }
    $parts[] = trim((string)$codename, '/');
    $parts[] = trim((string)$release, '/');
    $parts[] = ltrim((string)$relativePath, '/');

    return implode('/', array_filter($parts, static function($part) {
        return $part !== '';
    }));
}

function ru_bunny_upload_file($localFile, $remotePath) {
    global $config;

    $zone = $config['bunny']['zone'];
    $accessKey = $config['bunny']['access_key'];
    $baseUrl = ru_region_base_url($config['bunny']['region']);

    if ($zone === '' || $accessKey === '') {
        throw new RuntimeException('Missing BUNNY_STORAGE_ZONE or BUNNY_STORAGE_ACCESS_KEY');
    }

    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($zone) . '/' . str_replace('%2F', '/', rawurlencode($remotePath));

    $fh = @fopen($localFile, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Unable to open file for upload: ' . $localFile);
    }

    $size = (int)@filesize($localFile);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'AccessKey: ' . $accessKey,
            'Content-Type: application/octet-stream',
            'Content-Length: ' . $size,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = null;
        if ($response === false) {
            $error = curl_error($ch);
        }

        curl_close($ch);
        fclose($fh);

        if ($error !== null) {
            throw new RuntimeException('Upload failed: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Upload failed with HTTP ' . $httpCode . ' for ' . $remotePath);
        }

        return true;
    }

    fclose($fh);
    $body = @file_get_contents($localFile);
    if ($body === false) {
        throw new RuntimeException('Unable to read file for upload: ' . $localFile);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => "AccessKey: {$accessKey}\r\nContent-Type: application/octet-stream\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Upload failed with HTTP ' . $httpCode . ' for ' . $remotePath);
    }

    return true;
}

function ru_bunny_describe_file($remotePath) {
    global $config;

    $zone = $config['bunny']['zone'];
    $accessKey = $config['bunny']['access_key'];
    $baseUrl = ru_region_base_url($config['bunny']['region']);

    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($zone) . '/' . str_replace('%2F', '/', rawurlencode($remotePath));
    $context = stream_context_create([
        'http' => [
            'method' => 'DESCRIBE',
            'header' => "AccessKey: {$accessKey}\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('DESCRIBE failed for ' . $remotePath);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid DESCRIBE response for ' . $remotePath);
    }

    return [
        'length' => isset($decoded['Length']) ? (int)$decoded['Length'] : null,
        'checksum' => strtolower((string)($decoded['Checksum'] ?? '')),
        'is_directory' => !empty($decoded['IsDirectory']),
    ];
}

function ru_send_discord($url, $title, $bodyLines, $isFailure) {
    global $config;

    if ($url === '') {
        return ['sent' => false, 'skipped' => true, 'reason' => 'webhook_url_missing'];
    }

    $content = '**' . $title . "**\n" . implode("\n", $bodyLines);
    if ($isFailure && $config['discord']['mention_user_id'] !== '') {
        $content .= "\n\n<@" . $config['discord']['mention_user_id'] . '> Please investigate ASAP';
    }

    $payload = [
        'username' => $config['discord']['username'] ?: 'Remote Upload Worker',
        'content' => $content,
        'allowed_mentions' => [
            'parse' => ['users'],
        ],
    ];

    if ($config['discord']['avatar_url'] !== '') {
        $payload['avatar_url'] = $config['discord']['avatar_url'];
    }

    $json = json_encode($payload);
    if ($json === false) {
        return ['sent' => false, 'error' => 'failed_to_encode_payload'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = null;
        if ($response === false) {
            $error = curl_error($ch);
        }
        curl_close($ch);

        if ($error !== null) {
            return ['sent' => false, 'httpCode' => $httpCode, 'error' => $error];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['sent' => false, 'httpCode' => $httpCode, 'error' => 'webhook_http_' . $httpCode];
        }

        return ['sent' => true, 'httpCode' => $httpCode];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
            'content' => $json,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return ['sent' => false, 'httpCode' => $httpCode, 'error' => 'webhook_http_' . $httpCode];
    }

    return ['sent' => true, 'httpCode' => $httpCode];
}

function ru_notify_success($job, $uploadedCount, $durationSeconds) {
    global $config;

    $lines = [
        'Status: Upload completed',
        'Source: ' . $config['source_label'] . ' (' . ru_host() . ')',
        'Codename: ' . $job['codename'],
        'Release: ' . $job['release'],
        'Source path: ' . $job['release_path'],
        'Destination prefix: /' . trim((string)$job['destination_prefix'], '/'),
        'Files uploaded: ' . $uploadedCount,
        'Duration: ' . $durationSeconds . 's',
    ];

    return ru_send_discord($config['discord']['success_url'], 'Remote Bunny Upload Success', $lines, false);
}

function ru_notify_failure($job, $errorMessage) {
    global $config;

    $lines = [
        'Status: Upload failed',
        'Source: ' . $config['source_label'] . ' (' . ru_host() . ')',
        'Codename: ' . ($job['codename'] ?? 'unknown'),
        'Release: ' . ($job['release'] ?? 'unknown'),
        'Source path: ' . ($job['release_path'] ?? 'unknown'),
        'Destination prefix: /' . trim((string)($job['destination_prefix'] ?? ''), '/'),
        'Error: ' . trim((string)$errorMessage),
    ];

    return ru_send_discord($config['discord']['failure_url'], 'Remote Bunny Upload Error', $lines, true);
}

function ru_remove_directory($path) {
    if (!is_dir($path)) {
        return true;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        if (!$ok) {
            return false;
        }
    }

    return @rmdir($path);
}

$lockDir = dirname($config['lock_file']);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockHandle = @fopen($config['lock_file'], 'c+');
if ($lockHandle === false) {
    ru_log('ERROR: Cannot open lock file: ' . $config['lock_file']);
    exit(1);
}

if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    ru_log('INFO: Another uploader process is already running');
    @fclose($lockHandle);
    exit(0);
}

$exitCode = 0;

try {
    ru_log('Remote Bunny uploader started');
    ru_log('Source label: ' . $config['source_label'] . ' (' . ru_host() . ')');
    ru_log('Watch roots: ' . implode(', ', $config['watch_roots']));
    ru_log('Stable seconds: ' . $config['stable_seconds']);
    ru_log('Dry run: ' . ($config['dry_run'] ? 'yes' : 'no'));

    if (!$config['dry_run'] && ($config['bunny']['zone'] === '' || $config['bunny']['access_key'] === '')) {
        throw new RuntimeException('Missing Bunny configuration (BUNNY_STORAGE_ZONE or BUNNY_STORAGE_ACCESS_KEY)');
    }

    $state = ru_load_state($config['state_file']);
    $now = time();

    $releaseDirs = ru_discover_release_dirs($config['watch_roots']);
    $activeKeys = [];

    foreach ($releaseDirs as $releaseDir) {
        $snapshot = ru_collect_release_snapshot($releaseDir['path']);
        if ($snapshot === null) {
            continue;
        }

        $key = ru_job_key($releaseDir['path']);
        $activeKeys[$key] = true;
        $existing = $state['jobs'][$key] ?? null;

        $destinationPrefixParts = [];
        if ($config['bunny']['remote_base_prefix'] !== '') {
            $destinationPrefixParts[] = $config['bunny']['remote_base_prefix'];
        }
        $destinationPrefixParts[] = $releaseDir['codename'];
        $destinationPrefixParts[] = $releaseDir['release'];
        $destinationPrefix = implode('/', $destinationPrefixParts);

        if (!is_array($existing)) {
            $state['jobs'][$key] = [
                'release_path' => $releaseDir['path'],
                'codename' => $releaseDir['codename'],
                'release' => $releaseDir['release'],
                'destination_prefix' => $destinationPrefix,
                'status' => 'queued',
                'attempts' => 0,
                'last_error' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'stable_since' => $now,
                'signature' => $snapshot['signature'],
                'snapshot' => $snapshot,
            ];
            ru_log('Queued new release: ' . $releaseDir['path']);
            continue;
        }

        $state['jobs'][$key]['last_seen_at'] = $now;
        $state['jobs'][$key]['codename'] = $releaseDir['codename'];
        $state['jobs'][$key]['release'] = $releaseDir['release'];
        $state['jobs'][$key]['release_path'] = $releaseDir['path'];
        $state['jobs'][$key]['destination_prefix'] = $destinationPrefix;

        if (($existing['signature'] ?? '') !== $snapshot['signature']) {
            $state['jobs'][$key]['signature'] = $snapshot['signature'];
            $state['jobs'][$key]['snapshot'] = $snapshot;
            $state['jobs'][$key]['stable_since'] = $now;
            $state['jobs'][$key]['status'] = 'queued';
            $state['jobs'][$key]['last_error'] = null;
            ru_log('Detected changes, re-queued: ' . $releaseDir['path']);
        } else {
            $state['jobs'][$key]['snapshot'] = $snapshot;
        }
    }

    foreach (array_keys($state['jobs']) as $jobKey) {
        if (!isset($activeKeys[$jobKey])) {
            $job = $state['jobs'][$jobKey];
            if (in_array(($job['status'] ?? ''), ['completed'], true)) {
                unset($state['jobs'][$jobKey]);
                continue;
            }

            $state['jobs'][$jobKey]['status'] = 'missing';
            $state['jobs'][$jobKey]['last_error'] = 'Source release no longer found on disk';
        }
    }

    $processedJobs = 0;

    foreach ($state['jobs'] as $jobKey => $job) {
        if ($processedJobs >= $config['max_jobs_per_run']) {
            break;
        }

        if (($job['status'] ?? '') !== 'queued') {
            continue;
        }

        $stableSince = (int)($job['stable_since'] ?? $now);
        if (($now - $stableSince) < $config['stable_seconds']) {
            continue;
        }

        $processedJobs++;
        $state['jobs'][$jobKey]['status'] = 'uploading';
        $state['jobs'][$jobKey]['attempts'] = (int)($state['jobs'][$jobKey]['attempts'] ?? 0) + 1;
        $state['jobs'][$jobKey]['last_error'] = null;
        ru_save_state($config['state_file'], $state);

        $startedAt = time();
        $uploadedCount = 0;

        try {
            $snapshot = $state['jobs'][$jobKey]['snapshot'] ?? null;
            if (!is_array($snapshot) || empty($snapshot['files'])) {
                throw new RuntimeException('No snapshot files available for upload');
            }

            ru_log('Uploading release: ' . $job['release_path'] . ' (' . count($snapshot['files']) . ' files)');

            foreach ($snapshot['files'] as $file) {
                $localPath = $file['full_path'];
                $relativePath = $file['relative_path'];
                $expectedSize = (int)$file['size'];

                if (!file_exists($localPath) || !is_readable($localPath)) {
                    throw new RuntimeException('File missing or unreadable: ' . $localPath);
                }

                $remotePath = ru_relative_destination($job['codename'], $job['release'], $relativePath);

                if ($config['dry_run']) {
                    ru_log('DRY RUN upload: ' . $localPath . ' -> /' . $remotePath);
                    $uploadedCount++;
                    continue;
                }

                ru_bunny_upload_file($localPath, $remotePath);

                $remoteInfo = ru_bunny_describe_file($remotePath);
                $remoteSize = (int)($remoteInfo['length'] ?? -1);
                if ($remoteSize !== $expectedSize) {
                    throw new RuntimeException('Size mismatch for ' . $relativePath . ' local=' . $expectedSize . ' remote=' . $remoteSize);
                }

                $uploadedCount++;
                ru_log('Uploaded: ' . $relativePath . ' -> /' . $remotePath);
            }

            $state['jobs'][$jobKey]['status'] = 'completed';
            $state['jobs'][$jobKey]['completed_at'] = time();
            $state['jobs'][$jobKey]['last_error'] = null;
            ru_save_state($config['state_file'], $state);

            $duration = time() - $startedAt;
            ru_log('Completed release: ' . $job['release_path'] . ' in ' . $duration . 's');

            $notifyResult = ru_notify_success($state['jobs'][$jobKey], $uploadedCount, $duration);
            ru_log('Discord success webhook: ' . json_encode($notifyResult));

            if (!$config['dry_run'] && $config['delete_source_after_success']) {
                if (ru_remove_directory($job['release_path'])) {
                    ru_log('Removed source release directory: ' . $job['release_path']);
                } else {
                    ru_log('WARN: Failed to remove source release directory: ' . $job['release_path']);
                }
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $state['jobs'][$jobKey]['status'] = 'failed';
            $state['jobs'][$jobKey]['last_error'] = $errorMessage;
            $state['jobs'][$jobKey]['failed_at'] = time();
            ru_save_state($config['state_file'], $state);

            ru_log('ERROR: Upload failed for ' . ($job['release_path'] ?? 'unknown') . ': ' . $errorMessage);
            $notifyResult = ru_notify_failure($state['jobs'][$jobKey], $errorMessage);
            ru_log('Discord failure webhook: ' . json_encode($notifyResult));
            $exitCode = 1;
        }
    }

    ru_save_state($config['state_file'], $state);
    ru_log('Remote Bunny uploader finished');
} catch (Throwable $e) {
    ru_log('FATAL: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}

exit($exitCode);
