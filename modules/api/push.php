<?php
/**
 * Push API Module
 */

// Enable error logging to a specific file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/push_api.log');

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../core/file_operations.php';
require_once __DIR__ . '/../core/bucket_cache.php';

function logPushApiExit($result, $requestId = null, $context = '') {
    $timestamp = date('Y-m-d H:i:s');
    $id = $requestId ?: 'unknown';

    error_log("[$timestamp] PUSH API EXIT - ID: $id");
    error_log("[$timestamp] Exit Context: $context");
    error_log("[$timestamp] Result: " . json_encode($result));
    error_log("[$timestamp] PUSH API REQUEST END - ID: $id");
    error_log('');
}

function handlePushApi($method, $pathParts) {
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $requestId = uniqid('push_', true);

    error_log("[$timestamp] PUSH API REQUEST START - ID: $requestId");
    error_log("[$timestamp] Method: $method, IP: $clientIP");
    error_log("[$timestamp] User-Agent: $userAgentRaw");
    error_log("[$timestamp] Path Parts: " . json_encode($pathParts));

    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        error_log("[$timestamp] Raw POST data: " . substr($rawInput, 0, 500));
    } else {
        error_log("[$timestamp] No POST data received");
    }

    if ($method !== 'POST') {
        $result = [
            'status' => 'error',
            'APICode' => 'T-0002'
        ];
        logPushApiExit($result, $requestId, 'Invalid HTTP method');
        return $result;
    }

    $identity = resolvePushApiIdentity();
    if (!$identity['authorized']) {
        $result = [
            'status' => 'error',
            'APICode' => 'T-0003'
        ];
        logPushApiExit($result, $requestId, 'Authentication failed');
        return $result;
    }

    $username = $identity['username'];

    try {
        $parsed = parsePushRequestPayload($pathParts, $rawInput);
        if (!$parsed['success']) {
            $result = [
                'status' => 'error',
                'APICode' => 'T-0002'
            ];
            logPushApiExit($result, $requestId, $parsed['error']);
            return $result;
        }

        $payload = $parsed['payload'];

        $result = processPushRequest(
            $payload['codename'],
            $payload['date'],
            $payload['version'],
            $payload['buildType'],
            $username
        );

        logPushApiExit($result, $requestId, 'Queued request');
        return $result;
    } catch (Exception $e) {
        error_log("Unexpected error in push API: " . $e->getMessage());
        $result = [
            'status' => 'error',
            'APICode' => 'T-0006'
        ];
        logPushApiExit($result, $requestId, 'Exception caught: ' . $e->getMessage());
        return $result;
    }
}

function handlePushJobsApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'success' => false,
            'error' => 'Method not allowed',
            'APICode' => 'T-0002'
        ];
    }

    $identity = resolvePushApiIdentity();
    if (!$identity['authorized']) {
        return [
            'success' => false,
            'error' => 'Unauthorized',
            'APICode' => 'T-0003'
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();
        $statusFilter = strtolower(trim($_GET['status'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 100);
        $limit = max(1, min(500, $limit));

        $allowedStatuses = ['queued', 'processing', 'completed'];
        if (!empty($statusFilter) && !in_array($statusFilter, $allowedStatuses, true)) {
            return [
                'success' => false,
                'error' => 'Invalid status filter. Allowed: queued, processing, completed',
                'APICode' => 'T-0002'
            ];
        }

        $singleJobId = null;
        if (!empty($pathParts)) {
            $candidate = (string)$pathParts[0];
            if (ctype_digit($candidate)) {
                $singleJobId = (int)$candidate;
            }
        }

        if ($singleJobId !== null) {
            $stmt = $db->prepare('SELECT * FROM push_release_queue WHERE id = ? LIMIT 1');
            $stmt->execute([$singleJobId]);
            $job = $stmt->fetch();

            return [
                'success' => true,
                'data' => [
                    'job' => $job ? normalizePushJobRow($job) : null
                ]
            ];
        }

        $params = [];
        $where = '';
        if (!empty($statusFilter)) {
            $where = 'WHERE status = ?';
            $params[] = $statusFilter;
        }

        $stmt = $db->prepare("SELECT * FROM push_release_queue $where ORDER BY id DESC LIMIT $limit");
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();

        $countStmt = $db->query('SELECT status, COUNT(*) as total FROM push_release_queue GROUP BY status');
        $counts = [
            'queued' => 0,
            'processing' => 0,
            'completed' => 0
        ];

        foreach ($countStmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['total'];
            }
        }

        return [
            'success' => true,
            'data' => [
                'summary' => $counts,
                'jobs' => array_map('normalizePushJobRow', $jobs)
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Failed to fetch push jobs: ' . $e->getMessage(),
            'APICode' => 'T-0006'
        ];
    }
}

function resolvePushApiIdentity() {
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isEvoxUpdater = strpos($userAgent, 'evoxupdater') !== false;

    if ($isEvoxUpdater) {
        return [
            'authorized' => true,
            'username' => 'evoxupdater'
        ];
    }

    $authToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authToken = str_replace('Bearer ', '', $authToken);

    if (!validatePushToken($authToken)) {
        return [
            'authorized' => false,
            'username' => 'unknown'
        ];
    }

    return [
        'authorized' => true,
        'username' => 'api_user'
    ];
}

function parsePushRequestPayload($pathParts, $rawInput) {
    if (!empty($pathParts) && count($pathParts) >= 4) {
        return [
            'success' => true,
            'payload' => [
                'codename' => $pathParts[0],
                'date' => $pathParts[1],
                'version' => strval($pathParts[2]),
                'buildType' => $pathParts[3]
            ]
        ];
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'application/x-www-form-urlencoded') === 0) {
        $data = $_POST;
    } else {
        $data = json_decode($rawInput, true);
    }

    if (!$data || !is_array($data)) {
        return [
            'success' => false,
            'error' => 'Invalid request payload'
        ];
    }

    return [
        'success' => true,
        'payload' => [
            'codename' => $data['codename'] ?? '',
            'date' => $data['date'] ?? '',
            'version' => strval($data['version'] ?? ''),
            'buildType' => $data['buildType'] ?? ''
        ]
    ];
}

function validatePushToken($token) {
    $validTokens = [
        getenv('PUSH_API_TOKEN')
    ];

    return in_array($token, array_filter($validTokens));
}

function processPushRequest($codename, $date, $version, $buildType, $username = 'unknown') {
    $requestId = (int)(microtime(true) * 1000) . '_' . getmypid() . '_' . $codename . '_' . $date;
    error_log(">>> PROCESS_PUSH_REQUEST START (QUEUE MODE) - ID: $requestId");

    try {
        $resolved = validateAndResolvePushRequest($codename, $date, $version, $buildType, $requestId);
        if (!$resolved['success']) {
            return [
                'status' => 'error',
                'APICode' => $resolved['APICode']
            ];
        }

        $callbackConfig = getPushCompletionCallbackConfig();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('
            INSERT INTO push_release_queue
                (codename, release_date, version, build_type, requested_by, source_path, destination_path, status, callback_enabled, callback_url)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $resolved['codename'],
            $resolved['date'],
            $resolved['version'],
            $resolved['buildType'],
            $username,
            $resolved['sourcePath'],
            $resolved['destPath'],
            'queued',
            $callbackConfig['enabled'] ? 1 : 0,
            $callbackConfig['url']
        ]);

        $jobId = (int)$db->lastInsertId();

        error_log("[$requestId] Push job queued successfully with ID: $jobId");

        return [
            'status' => 'queued',
            'APICode' => 'T-0007',
            'jobId' => $jobId,
            'queueStatus' => 'queued'
        ];
    } catch (Exception $e) {
        error_log("Unexpected error in process_push_request: " . $e->getMessage());
        return [
            'status' => 'error',
            'APICode' => 'T-0006'
        ];
    } finally {
        error_log(">>> PROCESS_PUSH_REQUEST END (QUEUE MODE) - ID: $requestId");
    }
}

function validateAndResolvePushRequest($codename, $date, $version, $buildType, $requestId = 'unknown') {
    $codename = trim((string)$codename);
    $date = trim((string)$date);
    $version = trim((string)$version);
    $buildType = strtolower(trim((string)$buildType));

    if ($codename === '' || $date === '' || $version === '' || $buildType === '') {
        error_log("[$requestId] VALIDATION ERROR: Missing required parameters");
        return ['success' => false, 'APICode' => 'T-0002'];
    }

    if (!in_array($buildType, ['vanilla', 'gapps'], true)) {
        error_log("[$requestId] VALIDATION ERROR: Invalid build type '$buildType'");
        return ['success' => false, 'APICode' => 'T-0002'];
    }

    if (!preg_match('/^\d+$/', $version)) {
        error_log("[$requestId] VALIDATION ERROR: Version contains non-numeric characters '$version'");
        return ['success' => false, 'APICode' => 'T-0002'];
    }

    $versionInt = (int)$version;
    if (!in_array($versionInt, [14, 15, 16], true)) {
        error_log("[$requestId] VALIDATION ERROR: Invalid version '$version'");
        return ['success' => false, 'APICode' => 'T-0002'];
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        error_log("[$requestId] VALIDATION ERROR: Invalid date format '$date'");
        return ['success' => false, 'APICode' => 'T-0002'];
    }

    $sourceDate = str_replace('-', '', $date);
    if ($buildType === 'gapps') {
        $sourcePath = PRE_RELEASE_PATH . "/$codename/$sourceDate";
        $destPath = BASE_PATH . "/$codename/$versionInt";
    } else {
        $sourcePath = PRE_RELEASE_PATH . "/$codename/{$sourceDate}_Vanilla";
        $destPath = BASE_PATH . "/$codename/{$versionInt}_vanilla";
    }

    if (!isPushSourcePathAccessible($sourcePath, $requestId)) {
        return ['success' => false, 'APICode' => 'T-0005'];
    }

    return [
        'success' => true,
        'codename' => $codename,
        'date' => $date,
        'version' => (string)$versionInt,
        'buildType' => $buildType,
        'sourcePath' => $sourcePath,
        'destPath' => $destPath
    ];
}

function isPushSourcePathAccessible($sourcePath, $requestId = 'unknown') {
    $pathExists = is_dir($sourcePath);
    if ($pathExists) {
        return true;
    }

    $contents = @scandir($sourcePath);
    if ($contents !== false) {
        error_log("[$requestId] Source directory accessible via scandir despite is_dir=false: $sourcePath");
        return true;
    }

    error_log("[$requestId] SOURCE ERROR: Directory not found at $sourcePath");
    return false;
}

function processNextQueuedPushReleaseJob() {
    $lockPath = sys_get_temp_dir() . '/php_filebrowser_push_queue.lock';
    $lockHandle = fopen($lockPath, 'c');

    if ($lockHandle === false) {
        return [
            'success' => false,
            'status' => 'lock_error',
            'message' => 'Unable to create worker lock file'
        ];
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return [
            'success' => true,
            'status' => 'worker_busy',
            'message' => 'Push queue worker already running'
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();

        $processingStmt = $db->query("SELECT id FROM push_release_queue WHERE status = 'processing' ORDER BY started_at ASC, id ASC LIMIT 1");
        $processingJob = $processingStmt->fetch();

        if ($processingJob) {
            return [
                'success' => true,
                'status' => 'already_processing',
                'jobId' => (int)$processingJob['id'],
                'message' => 'A push job is already processing'
            ];
        }

        $queuedStmt = $db->query("SELECT id FROM push_release_queue WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
        $queuedJob = $queuedStmt->fetch();

        if (!$queuedJob) {
            return [
                'success' => true,
                'status' => 'idle',
                'message' => 'No queued push jobs'
            ];
        }

        $jobId = (int)$queuedJob['id'];
        if (!markPushQueueJobAsProcessing($db, $jobId)) {
            return [
                'success' => true,
                'status' => 'race_lost',
                'message' => 'Queued job was claimed by another worker'
            ];
        }

        $job = getPushQueueJobById($db, $jobId);
        if (!$job) {
            return [
                'success' => false,
                'status' => 'missing_job',
                'message' => 'Failed to load claimed queue job'
            ];
        }

        $runResult = executeQueuedPushReleaseJob($job);
        completePushQueueJob($db, $jobId, $runResult['success'], $runResult['error'] ?? null);

        $callbackResult = triggerPushCompletionCallback($db, $job, $runResult);

        return [
            'success' => true,
            'status' => 'processed',
            'jobId' => $jobId,
            'jobSuccess' => (bool)$runResult['success'],
            'message' => $runResult['success'] ? 'Push job completed' : 'Push job completed with errors',
            'callback' => $callbackResult
        ];
    } catch (Exception $e) {
        error_log('Push queue worker error: ' . $e->getMessage());
        return [
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function markPushQueueJobAsProcessing($db, $jobId) {
    $nowExpr = getDatabaseNowExpression();
    $stmt = $db->prepare("\n        UPDATE push_release_queue\n        SET status = 'processing', started_at = $nowExpr, updated_at = $nowExpr\n        WHERE id = ? AND status = 'queued'\n    ");
    $stmt->execute([$jobId]);
    return $stmt->rowCount() === 1;
}

function completePushQueueJob($db, $jobId, $success, $errorMessage = null) {
    $nowExpr = getDatabaseNowExpression();
    $stmt = $db->prepare("\n        UPDATE push_release_queue\n        SET status = 'completed', success = ?, error_message = ?, completed_at = $nowExpr, updated_at = $nowExpr\n        WHERE id = ?\n    ");
    $stmt->execute([$success ? 1 : 0, $errorMessage, $jobId]);
}

function getPushQueueJobById($db, $jobId) {
    $stmt = $db->prepare('SELECT * FROM push_release_queue WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    return $stmt->fetch();
}

function executeQueuedPushReleaseJob($job) {
    $jobId = (int)$job['id'];
    $sourcePath = $job['source_path'];
    $destPath = $job['destination_path'];

    error_log("[push-worker] Starting push job #$jobId from $sourcePath to $destPath");

    if (!isPushSourcePathAccessible($sourcePath, 'job_' . $jobId)) {
        $message = "Source directory not found: $sourcePath";
        error_log("[push-worker] $message");
        return [
            'success' => false,
            'error' => $message
        ];
    }

    clearHashesForPath($destPath);

    if (!is_dir($destPath) && !mkdir($destPath, 0755, true)) {
        $message = "Failed to create destination directory: $destPath";
        error_log("[push-worker] $message");
        return [
            'success' => false,
            'error' => $message
        ];
    }

    $copyResult = copyRecursively($sourcePath, $destPath);
    if (!$copyResult) {
        $message = "Background processing failed during file copy for job #$jobId";
        error_log("[push-worker] $message");
        return [
            'success' => false,
            'error' => $message
        ];
    }

    $cache = new BucketCache();
    $cache->invalidateCache();

    error_log("[push-worker] Push job #$jobId completed successfully and cache invalidated");

    return [
        'success' => true,
        'error' => null
    ];
}

function triggerPushCompletionCallback($db, $job, $runResult) {
    $enabled = isset($job['callback_enabled']) && (int)$job['callback_enabled'] === 1;
    $url = trim((string)($job['callback_url'] ?? ''));

    if (!$enabled || $url === '') {
        return [
            'sent' => false,
            'skipped' => true,
            'reason' => 'Callback disabled or URL missing'
        ];
    }

    $responseBody = '';
    $httpCode = 0;
    $error = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
                'method' => 'GET',
                'timeout' => 20
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = 'HTTP callback failed (curl extension unavailable)';
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

    if ($error !== null && $responseBody === '') {
        $responseBody = $error;
    }

    $stmt = $db->prepare('UPDATE push_release_queue SET callback_http_code = ?, callback_response = ? WHERE id = ?');
    $stmt->execute([$httpCode, substr($responseBody, 0, 2000), (int)$job['id']]);

    return [
        'sent' => $error === null,
        'httpCode' => $httpCode,
        'error' => $error,
        'jobSuccess' => (bool)$runResult['success']
    ];
}

function getPushCompletionCallbackConfig() {
    $sendUpdateRaw = getenv('PUSH_SEND_UPDATE');
    $sendUpdate = false;

    if ($sendUpdateRaw !== false) {
        $sendUpdate = filter_var($sendUpdateRaw, FILTER_VALIDATE_BOOLEAN);
    }

    $url = trim((string)(getenv('PUSH_UPDATE_URL') ?: getenv('PUSH_READY_CALLBACK_URL') ?: ''));

    return [
        'enabled' => $sendUpdate && $url !== '',
        'url' => $url
    ];
}

function getDatabaseNowExpression() {
    return (defined('DB_TYPE') && DB_TYPE === 'mysql') ? 'NOW()' : 'CURRENT_TIMESTAMP';
}

function normalizePushJobRow($job) {
    return [
        'id' => isset($job['id']) ? (int)$job['id'] : null,
        'codename' => $job['codename'] ?? null,
        'date' => $job['release_date'] ?? null,
        'version' => $job['version'] ?? null,
        'buildType' => $job['build_type'] ?? null,
        'requestedBy' => $job['requested_by'] ?? null,
        'sourcePath' => $job['source_path'] ?? null,
        'destinationPath' => $job['destination_path'] ?? null,
        'status' => $job['status'] ?? null,
        'success' => isset($job['success']) ? (bool)$job['success'] : null,
        'errorMessage' => $job['error_message'] ?? null,
        'callbackEnabled' => isset($job['callback_enabled']) ? ((int)$job['callback_enabled'] === 1) : false,
        'callbackUrl' => $job['callback_url'] ?? null,
        'callbackHttpCode' => isset($job['callback_http_code']) ? (int)$job['callback_http_code'] : null,
        'callbackResponse' => $job['callback_response'] ?? null,
        'createdAt' => $job['created_at'] ?? null,
        'startedAt' => $job['started_at'] ?? null,
        'completedAt' => $job['completed_at'] ?? null,
        'updatedAt' => $job['updated_at'] ?? null
    ];
}

function copyRecursively($source, $dest) {
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $destPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
        } else {
            copy($item, $destPath);
        }
    }

    return true;
}

function clearHashesForPath($path) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        if (strpos($path, BASE_PATH) === 0) {
            $relativePath = '/' . ltrim(str_replace(BASE_PATH, '', $path), '/');
        } else {
            $relativePath = ltrim($path, '/');
        }

        $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';

        $stmt = $pdo->prepare("UPDATE download_stat SET `sha256` = NULL, `md5` = NULL WHERE $keyColumn LIKE ?");
        $stmt->execute([$relativePath . '/%']);

        $clearedCount = $stmt->rowCount();
        error_log("Cleared hashes for $clearedCount entries in path: $relativePath");

        return $clearedCount;
    } catch (Exception $e) {
        error_log('Error clearing hashes for path ' . $path . ': ' . $e->getMessage());
        return false;
    }
}
