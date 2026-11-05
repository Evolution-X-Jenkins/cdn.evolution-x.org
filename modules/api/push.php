<?php
/**
 * Clean rewrite of the push API flow.
 *
 * Flow:
 * 1. Receive JSON payload
 * 2. Validate payload
 * 3. Pull source directory from BackBlaze into internal tmp_push staging
 * 4. Verify staging copy
 * 5. Upload files to Bunny
 * 6. Verify Bunny upload integrity
 * 7. Post webhook update
 *
 * Error-code compatibility remains: T-0002, T-0003, T-0005, T-0006, T-0007, T-0008
 */

ini_set('log_errors', 1);
$pushLogDir = __DIR__ . '/../../logs';
if (!is_dir($pushLogDir) && !@mkdir($pushLogDir, 0755, true) && !is_dir($pushLogDir)) {
    $pushLogDir = sys_get_temp_dir();
}
ini_set('error_log', rtrim($pushLogDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'push_new_api.log');

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/bunny_storage.php';
require_once __DIR__ . '/../core/file_operations.php';

function push_new_log($message) {
    error_log('[push_new] ' . $message);
}

function push_new_is_authorized() {
    $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strpos($userAgent, 'evoxupdater') !== false) {
        return true;
    }

    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    $token = preg_replace('/^Bearer\s+/i', '', (string)$token);
    $token = trim((string)$token);

    $allowed = array_filter(array_map('trim', [
        (string)(getenv('PUSH_API_TOKEN') ?: ''),
        (string)(getenv('BUNNY_PUSH_API_TOKEN') ?: ''),
    ]), static function ($value) {
        return $value !== '';
    });

    return $token !== '' && in_array($token, $allowed, true);
}

function push_new_json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return null;
}

function handlePushJobsApi($method, $pathParts = []) {
    if ($method !== 'GET') {
        return [
            'success' => false,
            'status' => 'error',
            'APICode' => 'T-0002',
            'message' => 'Method not allowed',
        ];
    }

    return [
        'success' => true,
        'status' => 'ok',
        'APICode' => 'T-0007',
        'message' => 'Push jobs queue is processed by cron; no direct job listing endpoint is active',
        'jobs' => [],
    ];
}

function push_new_normalize_destination_path($path) {
    $normalized = trim((string)$path, '/');
    if ($normalized === '') {
        return '/';
    }

    return '/' . str_replace('\\', '/', $normalized);
}

function push_new_get_source_roots() {
    $roots = [];
    $candidates = [
        getenv('BACKBLAZE_SOURCE_ROOT') ?: '',
        getenv('B2_SOURCE_ROOT') ?: '',
        getenv('B2_MOUNT_PATH') ?: '',
        getenv('BACKBLAZE_FETCH_ROOT') ?: '',
        getenv('PUSH_FETCH_ROOT') ?: '',
        defined('BACKBLAZE_SOURCE_ROOT') ? (string)BACKBLAZE_SOURCE_ROOT : '',
        '/mnt/backblaze',
        '/mnt/b2',
    ];

    foreach (array_unique(array_filter(array_map('trim', $candidates), static function ($root) {
        return $root !== '';
    })) as $root) {
        $resolved = realpath($root);
        $root = $resolved !== false ? $resolved : $root;
        $root = rtrim((string)$root, '/');

        if ($root !== '' && !in_array($root, $roots, true)) {
            $roots[] = $root;
        }
    }

    return $roots;
}

function push_new_match_build_directory_name($entryName, $buildType, $sourceDate) {
    $entryName = (string)$entryName;
    $isVanilla = preg_match('/_Vanilla$/', $entryName) === 1;

    if ($buildType === 'vanilla') {
        return str_starts_with($entryName, $sourceDate) && $isVanilla;
    }

    return str_starts_with($entryName, $sourceDate) && !$isVanilla;
}

function push_new_resolve_source_directory($codename, $version, $sourceDate, $buildType) {
    $codename = trim((string)$codename, '/');
    $sourceDate = trim((string)$sourceDate);
    $buildType = strtolower(trim((string)$buildType));

    if ($codename === '' || $sourceDate === '' || !in_array($buildType, ['vanilla', 'gapps'], true)) {
        return null;
    }

    foreach (push_new_get_source_roots() as $root) {
        $versionRoot = rtrim((string)$root, '/') . '/' . $codename . '/' . (string)$version;
        if (!is_dir($versionRoot)) {
            continue;
        }

        $exactCandidates = [$sourceDate];
        if ($buildType === 'vanilla') {
            $exactCandidates[] = $sourceDate . '_Vanilla';
        }

        foreach ($exactCandidates as $candidateName) {
            $candidatePath = $versionRoot . '/' . $candidateName;
            if (is_dir($candidatePath) && is_readable($candidatePath)) {
                return $candidatePath;
            }
        }

        $entries = @scandir($versionRoot);
        if (!is_array($entries)) {
            continue;
        }

        $matches = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($versionRoot . '/' . $entry)) {
                continue;
            }

            if (push_new_match_build_directory_name($entry, $buildType, $sourceDate)) {
                $matches[] = $versionRoot . '/' . $entry;
            }
        }

        if (!empty($matches)) {
            sort($matches, SORT_STRING);
            return $matches[0];
        }
    }

    return null;
}

function push_new_copy_directory_tree($sourceDir, $targetDir) {
    if (!is_dir($sourceDir) || !is_readable($sourceDir)) {
        return false;
    }

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = ltrim(str_replace($sourceDir, '', $item->getPathname()), '/');
        $targetPath = $relativePath === '' ? $targetDir : $targetDir . '/' . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                return false;
            }
            continue;
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            return false;
        }

        if (!copy($item->getPathname(), $targetPath)) {
            return false;
        }
    }

    return true;
}

function push_new_verify_staged_directory($directory) {
    if (!is_dir($directory) || !is_readable($directory)) {
        return false;
    }

    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $fileCount++;
        }
    }

    return $fileCount > 0;
}

function push_new_staging_root() {
    $root = defined('PUSH_STAGING_ROOT') && trim((string)PUSH_STAGING_ROOT) !== ''
        ? trim((string)PUSH_STAGING_ROOT)
        : __DIR__ . '/../../data/cache/tmp_push';

    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
        return null;
    }

    return rtrim($root, '/');
}

function push_new_stage_source_to_tmp($sourcePath, $codename, $version, $buildType, $sourceDate) {
    $stagingRoot = push_new_staging_root();
    if ($stagingRoot === null) {
        return null;
    }

    $targetDir = $stagingRoot . '/' . trim((string)$codename, '/') . '/' . (string)$version . '/' . strtolower((string)$buildType) . '/' . $sourceDate;

    if (is_dir($targetDir)) {
        $deleteResult = removeDirectoryRecursively($targetDir);
        if (!$deleteResult['success']) {
            push_new_log('Failed to reset staging dir: ' . $targetDir);
            return null;
        }
    }

    if (!push_new_copy_directory_tree($sourcePath, $targetDir)) {
        push_new_log('Failed to stage source: ' . $sourcePath . ' -> ' . $targetDir);
        return null;
    }

    if (!push_new_verify_staged_directory($targetDir)) {
        push_new_log('Staged directory verification failed: ' . $targetDir);
        return null;
    }

    return $targetDir;
}

function push_new_upload_directory_to_bunny($sourceDir, $destinationPath) {
    if (!is_dir($sourceDir)) {
        return false;
    }

    $client = bunny_get_storage_client();
    $targetPrefix = trim(push_new_normalize_destination_path($destinationPath), '/');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
        $remotePath = $targetPrefix === '' ? $relativePath : ($targetPrefix . '/' . $relativePath);

        try {
            $client->upload($item->getPathname(), $remotePath);
        } catch (Throwable $e) {
            push_new_log('Bunny upload failed for ' . $item->getPathname() . ': ' . $e->getMessage());
            return false;
        }
    }

    return true;
}

function push_new_verify_bunny_upload($sourceDir, $destinationPath) {
    if (!is_dir($sourceDir)) {
        return false;
    }

    $targetPrefix = trim(push_new_normalize_destination_path($destinationPath), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
        $remotePath = $targetPrefix === '' ? $relativePath : ($targetPrefix . '/' . $relativePath);
        $localSize = filesize($item->getPathname());

        try {
            $meta = bunny_describe_file_raw($remotePath);
            $remoteSize = (int)($meta['length'] ?? 0);
        } catch (Throwable $e) {
            push_new_log('Bunny describe failed for ' . $remotePath . ': ' . $e->getMessage());
            return false;
        }

        if ($localSize !== $remoteSize) {
            push_new_log('Upload verification mismatch for ' . $remotePath . ': local=' . $localSize . ' remote=' . $remoteSize);
            return false;
        }
    }

    return true;
}

function push_new_post_webhook_update($webhookUrl, array $payload) {
    if ($webhookUrl === '' || $webhookUrl === null) {
        return true;
    }

    $ch = curl_init((string)$webhookUrl);
    if ($ch === false) {
        return false;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400 || $curlErr !== '') {
        push_new_log('Webhook update failed: ' . $curlErr . ' HTTP=' . (string)$httpCode);
        return false;
    }

    return true;
}

function push_new_parse_request($rawInput, $pathParts = []) {
    if (!empty($pathParts) && count($pathParts) >= 4) {
        return [
            'success' => true,
            'payload' => [
                'codename' => (string)$pathParts[0],
                'date' => (string)$pathParts[1],
                'version' => (string)$pathParts[2],
                'buildType' => strtolower((string)$pathParts[3]),
            ],
        ];
    }

    if (trim((string)$rawInput) === '') {
        return [
            'success' => false,
            'error' => 'No JSON payload provided',
        ];
    }

    $decoded = json_decode((string)$rawInput, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'error' => 'Invalid JSON payload',
        ];
    }

    return [
        'success' => true,
        'payload' => [
            'codename' => (string)($decoded['codename'] ?? ''),
            'date' => (string)($decoded['date'] ?? ''),
            'version' => (string)($decoded['version'] ?? ''),
            'buildType' => strtolower((string)($decoded['buildType'] ?? '')),
            'callbackUrl' => (string)($decoded['callbackUrl'] ?? $decoded['webhookUrl'] ?? ''),
        ],
    ];
}

if (!function_exists('sendJsonWebhookRequest')) {
    function sendJsonWebhookRequest($url, $payload) {
        $responseBody = '';
        $httpCode = 0;
        $error = null;
        $body = json_encode($payload);

        if ($body === false) {
            return [
                'sent' => false,
                'httpCode' => 0,
                'error' => 'Failed to encode webhook payload',
            ];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
            } else {
                $responseBody = (string)$response;
            }

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                    'content' => $body,
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                $error = 'HTTP webhook failed (curl extension unavailable)';
            } else {
                $responseBody = (string)$response;
            }

            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $headerLine) {
                    if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $headerLine, $matches)) {
                        $httpCode = (int)$matches[1];
                        break;
                    }
                }
            }
        }

        if ($error === null && ($httpCode < 200 || $httpCode >= 300)) {
            $error = 'Webhook returned HTTP ' . $httpCode;
        }

        return [
            'sent' => $error === null,
            'httpCode' => $httpCode,
            'error' => $error,
            'response' => substr($responseBody, 0, 1000),
        ];
    }
}

if (!function_exists('getPushSuccessDiscordWebhookConfig')) {
    function getPushSuccessDiscordWebhookConfig() {
        $url = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_URL') ?: ''));
        $username = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_USERNAME') ?: 'Evolution X CDN'));
        $avatarUrl = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_AVATAR_URL') ?: ''));

        return [
            'enabled' => $url !== '',
            'url' => $url,
            'username' => $username,
            'avatarUrl' => $avatarUrl,
        ];
    }
}

if (!function_exists('getPushFailureDiscordWebhookConfig')) {
    function getPushFailureDiscordWebhookConfig() {
        $url = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_URL') ?: ''));
        $username = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_USERNAME') ?: 'Evolution X CDN'));
        $avatarUrl = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_AVATAR_URL') ?: ''));

        return [
            'enabled' => $url !== '',
            'url' => $url,
            'username' => $username,
            'avatarUrl' => $avatarUrl,
        ];
    }
}

if (!function_exists('triggerPushSuccessDiscordWebhook')) {
    function triggerPushSuccessDiscordWebhook($job, $runResult) {
        if (empty($runResult['success'])) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Job failed or not successful',
            ];
        }

        $config = getPushSuccessDiscordWebhookConfig();
        if (!$config['enabled']) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Discord success webhook disabled or URL missing',
            ];
        }

        $jobId = (int)($job['id'] ?? 0);
        $codename = $job['codename'] ?? 'unknown';
        $version = $job['version'] ?? 'unknown';
        $releaseDate = $job['release_date'] ?? 'unknown';
        $buildType = $job['build_type'] ?? 'unknown';

        $payload = [
            'username' => $config['username'],
            'content' => '',
            'embeds' => [[
                'title' => 'CDN Push Release - Success',
                'description' => "Device: `{$codename}`\nVersion: `{$version}`\nBuild Date: `{$releaseDate}`\nType: `{$buildType}`\n\nOTA merge can now be completed.",
                'color' => 5763719,
            ]],
            'allowed_mentions' => [
                'parse' => ['users'],
            ],
        ];

        if ($config['avatarUrl'] !== '') {
            $payload['avatar_url'] = $config['avatarUrl'];
        }

        $result = sendJsonWebhookRequest($config['url'], $payload);
        error_log('[push-worker] Discord success webhook result for job #' . $jobId . ': ' . json_encode($result));

        return $result;
    }
}

if (!function_exists('triggerPushFailureDiscordWebhook')) {
    function triggerPushFailureDiscordWebhook($job, $runResult) {
        if (!empty($runResult['success'])) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Job succeeded',
            ];
        }

        $config = getPushFailureDiscordWebhookConfig();
        if (!$config['enabled']) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Discord failure webhook disabled or URL missing',
            ];
        }

        $jobId = (int)($job['id'] ?? 0);
        $codename = $job['codename'] ?? 'unknown';
        $version = $job['version'] ?? 'unknown';
        $releaseDate = $job['release_date'] ?? 'unknown';
        $buildType = $job['build_type'] ?? 'unknown';
        $errorMessage = trim((string)($runResult['error'] ?? $job['error_message'] ?? 'Unknown push failure'));

        $payload = [
            'username' => $config['username'],
            'content' => '',
            'embeds' => [[
                'title' => 'CDN Push Release - Failed',
                'description' => "Device: `{$codename}`\nVersion: `{$version}`\nBuild Date: `{$releaseDate}`\nType: `{$buildType}`\n\nError: `{$errorMessage}`",
                'color' => 15548997,
                'footer' => [
                    'text' => 'Job #' . $jobId,
                ],
            ]],
            'allowed_mentions' => [
                'parse' => ['users'],
            ],
        ];

        if ($config['avatarUrl'] !== '') {
            $payload['avatar_url'] = $config['avatarUrl'];
        }

        $embedResult = sendJsonWebhookRequest($config['url'], $payload);

        $mentionPayload = [
            'username' => $config['username'],
            'content' => '<@180736511354863627> Please investigate ASAP',
            'allowed_mentions' => [
                'parse' => ['users'],
            ],
        ];

        if ($config['avatarUrl'] !== '') {
            $mentionPayload['avatar_url'] = $config['avatarUrl'];
        }

        $mentionResult = sendJsonWebhookRequest($config['url'], $mentionPayload);

        $result = [
            'sent' => ($embedResult['sent'] ?? false) && ($mentionResult['sent'] ?? false),
            'httpCode' => $embedResult['httpCode'] ?? 0,
            'error' => ($embedResult['error'] ?? null) ?: ($mentionResult['error'] ?? null),
            'embed' => $embedResult,
            'mention' => $mentionResult,
        ];
        error_log('[push-worker] Discord failure webhook result for job #' . $jobId . ': ' . json_encode($result));

        return $result;
    }
}

function push_new_validate_payload(array $payload) {
    if (!isset($payload['codename'], $payload['date'], $payload['version'], $payload['buildType'])) {
        return ['success' => false, 'APICode' => 'T-0002', 'error' => 'Missing required fields'];
    }

    $codename = trim((string)$payload['codename']);
    $date = trim((string)$payload['date']);
    $version = trim((string)$payload['version']);
    $buildType = strtolower(trim((string)$payload['buildType']));

    if ($codename === '' || $date === '' || $version === '' || $buildType === '') {
        return ['success' => false, 'APICode' => 'T-0002', 'error' => 'Missing required values'];
    }

    if (!in_array($buildType, ['vanilla', 'gapps'], true)) {
        return ['success' => false, 'APICode' => 'T-0002', 'error' => 'Invalid build type'];
    }

    $versionInt = (int)$version;
    if (!preg_match('/^\d+$/', $version) || $versionInt < 1 || $versionInt > 99) {
        return ['success' => false, 'APICode' => 'T-0002', 'error' => 'Invalid version'];
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        return ['success' => false, 'APICode' => 'T-0002', 'error' => 'Invalid date'];
    }

    return ['success' => true, 'codename' => $codename, 'date' => $date, 'version' => (string)$versionInt, 'buildType' => $buildType];
}

function push_new_duplicate_check($sourcePath) {
    if (!class_exists('Database')) {
        return null;
    }

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, status FROM push_release_queue WHERE source_path = ? AND status IN ('queued', 'processing') LIMIT 1");
        $stmt->execute([$sourcePath]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        push_new_log('Duplicate source lookup failed: ' . $e->getMessage());
        return null;
    }
}

function push_new_build_discord_job_context($codename, $date, $version, $buildType, $jobId = 0) {
    return [
        'id' => (int)$jobId,
        'codename' => trim((string)$codename),
        'release_date' => trim((string)$date),
        'version' => trim((string)$version),
        'build_type' => strtolower(trim((string)$buildType)),
    ];
}

function push_new_process_request(array $payload) {
    $validation = push_new_validate_payload($payload);
    if (!$validation['success']) {
        return [
            'status' => 'error',
            'APICode' => $validation['APICode'],
            'message' => $validation['error'],
        ];
    }

    $codename = $validation['codename'];
    $date = $validation['date'];
    $version = $validation['version'];
    $buildType = $validation['buildType'];
    $sourceDate = str_replace('-', '', $date);
    $callbackUrl = trim((string)($payload['callbackUrl'] ?? ''));

    $jobContext = push_new_build_discord_job_context($codename, $date, $version, $buildType);
    $sourcePath = push_new_resolve_source_directory($codename, (int)$version, $sourceDate, $buildType);
    if ($sourcePath === null) {
        $failureHook = triggerPushFailureDiscordWebhook($jobContext, ['success' => false, 'error' => 'Source directory not found']);
        push_new_log('Failure Discord webhook: ' . json_encode($failureHook));

        return [
            'status' => 'error',
            'APICode' => 'T-0005',
            'message' => 'Source directory not found',
        ];
    }

    $duplicate = push_new_duplicate_check($sourcePath);
    if ($duplicate !== null) {
        $failureHook = triggerPushFailureDiscordWebhook($jobContext, ['success' => false, 'error' => 'Duplicate push request for this source path already exists']);
        push_new_log('Failure Discord webhook: ' . json_encode($failureHook));

        return [
            'status' => 'duplicate',
            'APICode' => 'T-0008',
            'message' => 'Duplicate push request for this source path already exists',
            'jobId' => (int)($duplicate['id'] ?? 0),
            'queueStatus' => (string)($duplicate['status'] ?? 'unknown'),
        ];
    }

    $stagedPath = push_new_stage_source_to_tmp($sourcePath, $codename, (int)$version, $buildType, $sourceDate);
    if ($stagedPath === null) {
        $failureHook = triggerPushFailureDiscordWebhook($jobContext, ['success' => false, 'error' => 'Failed to stage source directory from BackBlaze']);
        push_new_log('Failure Discord webhook: ' . json_encode($failureHook));

        return [
            'status' => 'error',
            'APICode' => 'T-0005',
            'message' => 'Failed to stage source directory from BackBlaze',
        ];
    }

    $destinationPath = ($buildType === 'gapps')
        ? '/' . trim((string)$codename, '/') . '/' . (string)(int)$version
        : '/' . trim((string)$codename, '/') . '/' . (string)(int)$version . '_vanilla';

    if (!push_new_upload_directory_to_bunny($stagedPath, $destinationPath)) {
        $failureHook = triggerPushFailureDiscordWebhook($jobContext, ['success' => false, 'error' => 'Bunny upload failed']);
        push_new_log('Failure Discord webhook: ' . json_encode($failureHook));

        return [
            'status' => 'error',
            'APICode' => 'T-0006',
            'message' => 'Bunny upload failed',
        ];
    }

    if (!push_new_verify_bunny_upload($stagedPath, $destinationPath)) {
        $failureHook = triggerPushFailureDiscordWebhook($jobContext, ['success' => false, 'error' => 'Bunny upload verification failed']);
        push_new_log('Failure Discord webhook: ' . json_encode($failureHook));

        return [
            'status' => 'error',
            'APICode' => 'T-0006',
            'message' => 'Bunny upload verification failed',
        ];
    }

    $webhookPayload = [
        'status' => 'success',
        'APICode' => 'T-0007',
        'message' => 'Push completed successfully',
        'codename' => $codename,
        'date' => $date,
        'version' => $version,
        'buildType' => $buildType,
        'sourcePath' => $sourcePath,
        'destPath' => $destinationPath,
    ];

    $webhookResult = push_new_post_webhook_update($callbackUrl, $webhookPayload);
    if (!$webhookResult) {
        push_new_log('Webhook update failed for codename=' . $codename . ' destination=' . $destinationPath);
    }

    $successHook = triggerPushSuccessDiscordWebhook($jobContext, ['success' => true]);
    push_new_log('Success Discord webhook: ' . json_encode($successHook));

    if (is_dir($stagedPath)) {
        $cleanup = removeDirectoryRecursively($stagedPath);
        if (!$cleanup['success']) {
            push_new_log('Staging cleanup failed: ' . $stagedPath);
        }
    }

    return [
        'status' => 'success',
        'APICode' => 'T-0007',
        'message' => 'Push completed successfully',
        'sourcePath' => $sourcePath,
        'stagedPath' => $stagedPath,
        'destPath' => $destinationPath,
        'webhookDelivered' => $webhookResult,
        'discordWebhook' => $successHook,
    ];
}

function handlePushNewApi($method, $pathParts = []) {
    if ($method !== 'POST') {
        return push_new_json_response([
            'status' => 'error',
            'APICode' => 'T-0002',
            'message' => 'Method not allowed',
        ], 405);
    }

    if (!push_new_is_authorized()) {
        return push_new_json_response([
            'status' => 'error',
            'APICode' => 'T-0003',
            'message' => 'Unauthorized',
        ], 401);
    }

    $rawInput = file_get_contents('php://input');
    $parsed = push_new_parse_request($rawInput, $pathParts);
    if (!$parsed['success']) {
        return push_new_json_response([
            'status' => 'error',
            'APICode' => 'T-0002',
            'message' => $parsed['error'],
        ], 400);
    }

    try {
        $result = push_new_process_request($parsed['payload']);
        $code = $result['APICode'] ?? 'T-0006';

        $statusCodeMap = [
            'T-0002' => 400,
            'T-0003' => 401,
            'T-0005' => 404,
            'T-0006' => 500,
            'T-0007' => 200,
            'T-0008' => 409,
        ];

        return push_new_json_response($result, $statusCodeMap[$code] ?? 200);
    } catch (Throwable $e) {
        push_new_log('Unhandled exception: ' . $e->getMessage());
        return push_new_json_response([
            'status' => 'error',
            'APICode' => 'T-0006',
            'message' => 'Push execution failed',
        ], 500);
    }
}

if (!function_exists('normalizePushDestinationPath')) {
    function normalizePushDestinationPath($path) {
        $path = trim((string)$path);
        if ($path === '') {
            return '/';
        }

        if (strpos($path, BASE_PATH) === 0) {
            $path = '/' . ltrim(str_replace(BASE_PATH, '', $path), '/');
        }

        return '/' . trim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('getDatabaseNowExpression')) {
    function getDatabaseNowExpression() {
        return (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'NOW()' : 'CURRENT_TIMESTAMP';
    }
}

if (!function_exists('findPushQueueJobBySourcePath')) {
    function findPushQueueJobBySourcePath($db, $sourcePath) {
        $stmt = $db->prepare('SELECT id, status FROM push_release_queue WHERE source_path = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$sourcePath]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('markPushQueueJobAsProcessing')) {
    function markPushQueueJobAsProcessing($db, $jobId) {
        $nowExpr = getDatabaseNowExpression();
        $stmt = $db->prepare("UPDATE push_release_queue SET status = 'processing', started_at = $nowExpr, updated_at = $nowExpr WHERE id = ? AND status = 'queued'");
        $stmt->execute([$jobId]);
        return $stmt->rowCount() === 1;
    }
}

if (!function_exists('completePushQueueJob')) {
    function completePushQueueJob($db, $jobId, $success, $errorMessage = null) {
        $nowExpr = getDatabaseNowExpression();
        $finalStatus = $success ? 'completed' : 'failed';
        $stmt = $db->prepare("UPDATE push_release_queue SET status = '$finalStatus', success = ?, error_message = ?, completed_at = $nowExpr, updated_at = $nowExpr WHERE id = ?");
        $stmt->execute([$success ? 1 : 0, $errorMessage, $jobId]);
    }
}

if (!function_exists('getPushQueueJobById')) {
    function getPushQueueJobById($db, $jobId) {
        $stmt = $db->prepare('SELECT * FROM push_release_queue WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        return $stmt->fetch();
    }
}

if (!function_exists('executeQueuedPushReleaseJob')) {
    function executeQueuedPushReleaseJob($job) {
        $jobId = (int)($job['id'] ?? 0);
        $sourcePath = trim((string)($job['source_path'] ?? ''));
        $destPath = normalizePushDestinationPath((string)($job['destination_path'] ?? ''));
        $codename = trim((string)($job['codename'] ?? 'unknown'));
        $releaseDate = trim((string)($job['release_date'] ?? ''));
        $buildType = strtolower(trim((string)($job['build_type'] ?? '')));
        $version = trim((string)($job['version'] ?? ''));

        if ($jobId <= 0) {
            return ['success' => false, 'error' => 'Invalid push job'];
        }

        foreach (['codename', 'release_date', 'version', 'build_type'] as $field) {
            if (!isset($job[$field])) {
                return ['success' => false, 'error' => 'Missing queue field: ' . $field];
            }
        }

        $workingSourcePath = $sourcePath;
        $stagedFromRemote = false;

        if (!is_dir($workingSourcePath) || !is_readable($workingSourcePath)) {
            if ($codename !== '' && $releaseDate !== '' && $version !== '' && in_array($buildType, ['vanilla', 'gapps'], true)) {
                $dateValue = str_replace('-', '', $releaseDate);
                $workingSourcePath = push_new_stage_source_to_tmp(
                    push_new_resolve_source_directory($codename, (int)$version, $dateValue, $buildType) ?? $sourcePath,
                    $codename,
                    (int)$version,
                    $buildType,
                    $dateValue
                );
                $stagedFromRemote = true;

                if ($workingSourcePath === null) {
                    return ['success' => false, 'error' => 'Source directory not found: ' . $sourcePath];
                }
            } else {
                return ['success' => false, 'error' => 'Source directory not found: ' . $sourcePath];
            }
        }

        if (!push_new_verify_staged_directory($workingSourcePath)) {
            return ['success' => false, 'error' => 'Source directory verification failed: ' . $workingSourcePath];
        }

        $uploadResult = push_new_upload_directory_to_bunny($workingSourcePath, $destPath);
        if (!$uploadResult) {
            return ['success' => false, 'error' => 'Bunny upload failed for job #'.$jobId];
        }

        if (!push_new_verify_bunny_upload($workingSourcePath, $destPath)) {
            return ['success' => false, 'error' => 'Bunny upload verification failed for job #'.$jobId];
        }

        if ($stagedFromRemote && is_dir($workingSourcePath)) {
            $cleanup = removeDirectoryRecursively($workingSourcePath);
            if (!$cleanup['success']) {
                push_new_log('Queue cleanup failed for ' . $workingSourcePath);
            }
        }

        return ['success' => true, 'error' => null];
    }
}

if (!function_exists('processNextQueuedPushReleaseJob')) {
    function processNextQueuedPushReleaseJob() {
        $lockPath = sys_get_temp_dir() . '/php_filebrowser_push_queue.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false) {
            return ['success' => false, 'status' => 'lock_error', 'message' => 'Unable to create worker lock file'];
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return ['success' => true, 'status' => 'worker_busy', 'message' => 'Push queue worker already running'];
        }

        try {
            $db = Database::getInstance()->getConnection();

            $processingStmt = $db->query("SELECT id FROM push_release_queue WHERE status = 'processing' ORDER BY started_at ASC, id ASC LIMIT 1");
            $processingJob = $processingStmt->fetch();
            if ($processingJob) {
                return ['success' => true, 'status' => 'already_processing', 'jobId' => (int)$processingJob['id'], 'message' => 'A push job is already processing'];
            }

            $queuedStmt = $db->query("SELECT id FROM push_release_queue WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
            $queuedJob = $queuedStmt->fetch();
            if (!$queuedJob) {
                return ['success' => true, 'status' => 'idle', 'message' => 'No queued push jobs'];
            }

            $jobId = (int)$queuedJob['id'];
            if (!markPushQueueJobAsProcessing($db, $jobId)) {
                return ['success' => true, 'status' => 'race_lost', 'message' => 'Queued job was claimed by another worker'];
            }

            $job = getPushQueueJobById($db, $jobId);
            if (!$job) {
                return ['success' => false, 'status' => 'missing_job', 'message' => 'Failed to load claimed queue job'];
            }

            try {
                $runResult = executeQueuedPushReleaseJob($job);
            } catch (Throwable $e) {
                $runResult = ['success' => false, 'error' => $e->getMessage()];
            }

            completePushQueueJob($db, $jobId, (bool)($runResult['success'] ?? false), $runResult['error'] ?? null);

            $discordWebhookResult = ['sent' => false, 'skipped' => true, 'reason' => 'Discord failure webhook disabled or URL missing'];
            $successDiscordWebhookResult = ['sent' => false, 'skipped' => true, 'reason' => 'Discord success webhook disabled or URL missing'];

            try {
                $discordWebhookResult = triggerPushFailureDiscordWebhook($job, $runResult);
            } catch (Throwable $e) {
                $discordWebhookResult = [
                    'sent' => false,
                    'error' => $e->getMessage(),
                    'skipped' => false,
                ];
                push_new_log('Discord failure webhook failed for job #' . $jobId . ': ' . $e->getMessage());
            }

            try {
                $successDiscordWebhookResult = triggerPushSuccessDiscordWebhook($job, $runResult);
            } catch (Throwable $e) {
                $successDiscordWebhookResult = [
                    'sent' => false,
                    'error' => $e->getMessage(),
                    'skipped' => false,
                ];
                push_new_log('Discord success webhook failed for job #' . $jobId . ': ' . $e->getMessage());
            }

            return [
                'success' => true,
                'status' => ($runResult['success'] ?? false) ? 'processed' : 'failed',
                'jobId' => $jobId,
                'jobSuccess' => (bool)($runResult['success'] ?? false),
                'message' => ($runResult['success'] ?? false) ? 'Push job completed' : 'Push job failed',
                'callback' => ['sent' => false, 'skipped' => true, 'reason' => 'Callback disabled'],
                'discordWebhook' => $discordWebhookResult,
                'successDiscordWebhook' => $successDiscordWebhookResult,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'status' => 'error', 'message' => $e->getMessage()];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

if (!function_exists('removeDirectoryRecursively')) {
    function removeDirectoryRecursively($directory) {
        if (!is_dir($directory)) {
            return ['success' => true, 'errors' => []];
        }

        $errors = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            try {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        try {
            @rmdir($directory);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return ['success' => empty($errors), 'errors' => $errors];
    }
}
