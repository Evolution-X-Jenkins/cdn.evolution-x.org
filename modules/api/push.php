<?php
/**
 * Push API Module
 */

// Enable error logging to a specific file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/push_api.log');

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/../setup/bunny_storage.php';
require_once __DIR__ . '/../core/file_operations.php';
require_once __DIR__ . '/../core/bucket_cache.php';
require_once __DIR__ . '/../core/cache.php';

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

        $allowedStatuses = ['queued', 'processing', 'completed', 'failed'];
        if (!empty($statusFilter) && !in_array($statusFilter, $allowedStatuses, true)) {
            return [
                'success' => false,
                'error' => 'Invalid status filter. Allowed: queued, processing, completed, failed',
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
            'completed' => 0,
            'failed' => 0
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
    } catch (PDOException $e) {
        if (isPushQueueDuplicateError($e)) {
            $existingJob = null;
            if (isset($db) && isset($resolved['sourcePath'])) {
                $existingJob = findPushQueueJobBySourcePath($db, $resolved['sourcePath']);
            }
            $existingStatus = $existingJob['status'] ?? 'unknown';
            $existingJobId = isset($existingJob['id']) ? (int)$existingJob['id'] : null;

            error_log("[$requestId] Duplicate push queue request rejected for source path: " . ($resolved['sourcePath'] ?? 'unknown'));

            return [
                'status' => 'duplicate',
                'APICode' => 'T-0008',
                'message' => 'Duplicate push request for this source path already exists',
                'jobId' => $existingJobId,
                'queueStatus' => $existingStatus
            ];
        }

        error_log("Unexpected PDO error in process_push_request: " . $e->getMessage());
        return [
            'status' => 'error',
            'APICode' => 'T-0006'
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
        $destPath = "/$codename/$versionInt";
    } else {
        $sourcePath = PRE_RELEASE_PATH . "/$codename/{$sourceDate}_Vanilla";
        $destPath = "/$codename/{$versionInt}_vanilla";
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

        try {
            $runResult = executeQueuedPushReleaseJob($job);
        } catch (Throwable $e) {
            $runResult = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            error_log('[push-worker] Unhandled exception while processing job #' . $jobId . ': ' . $e->getMessage());
        }

        completePushQueueJob($db, $jobId, $runResult['success'], $runResult['error'] ?? null);

        $updatedJob = getPushQueueJobById($db, $jobId) ?: $job;

        try {
            $callbackResult = triggerPushCompletionCallback($db, $updatedJob, $runResult);
        } catch (Throwable $e) {
            $callbackResult = [
                'sent' => false,
                'error' => $e->getMessage()
            ];
            error_log('[push-worker] Completion callback failed for job #' . $jobId . ': ' . $e->getMessage());
        }

        try {
            $discordWebhookResult = triggerPushFailureDiscordWebhook($updatedJob, $runResult);
        } catch (Throwable $e) {
            $discordWebhookResult = [
                'sent' => false,
                'error' => $e->getMessage()
            ];
            error_log('[push-worker] Discord failure webhook failed for job #' . $jobId . ': ' . $e->getMessage());
        }

        try {
            $successDiscordWebhookResult = triggerPushSuccessDiscordWebhook($updatedJob, $runResult);
        } catch (Throwable $e) {
            $successDiscordWebhookResult = [
                'sent' => false,
                'error' => $e->getMessage()
            ];
            error_log('[push-worker] Discord success webhook failed for job #' . $jobId . ': ' . $e->getMessage());
        }

        return [
            'success' => true,
            'status' => $runResult['success'] ? 'processed' : 'failed',
            'jobId' => $jobId,
            'jobSuccess' => (bool)$runResult['success'],
            'message' => $runResult['success'] ? 'Push job completed' : 'Push job failed',
            'callback' => $callbackResult,
            'discordWebhook' => $discordWebhookResult,
            'successDiscordWebhook' => $successDiscordWebhookResult
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
    $finalStatus = $success ? 'completed' : 'failed';
    $stmt = $db->prepare("\n        UPDATE push_release_queue\n        SET status = '$finalStatus', success = ?, error_message = ?, completed_at = $nowExpr, updated_at = $nowExpr\n        WHERE id = ?\n    ");
    $stmt->execute([$success ? 1 : 0, $errorMessage, $jobId]);
}

function getPushQueueJobById($db, $jobId) {
    $stmt = $db->prepare('SELECT * FROM push_release_queue WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    return $stmt->fetch();
}

function logPushWorkerProgress($jobId, $message) {
    error_log("[push-worker] Job #$jobId $message");
}

function executeQueuedPushReleaseJob($job) {
    $jobId = (int)$job['id'];
    $sourcePath = $job['source_path'];
    $destPath = normalizePushDestinationPath((string)$job['destination_path']);
    $codename = trim((string)($job['codename'] ?? 'unknown'));

    logPushWorkerProgress($jobId, "starting from $sourcePath to $destPath");

    if (!isPushSourcePathAccessible($sourcePath, 'job_' . $jobId)) {
        $message = "Source directory not found: $sourcePath";
        logPushWorkerProgress($jobId, $message);
        return [
            'success' => false,
            'error' => $message
        ];
    }

    logPushWorkerProgress($jobId, 'clearing destination hashes');
    clearHashesForPath($destPath);

    logPushWorkerProgress($jobId, 'beginning Bunny upload');
    $copyResult = uploadDirectoryToBunny($sourcePath, $destPath, $jobId, $codename);
    if (!$copyResult) {
        $message = "Background processing failed during Bunny upload for job #$jobId";
        logPushWorkerProgress($jobId, $message);
        return [
            'success' => false,
            'error' => $message
        ];
    }

    logPushWorkerProgress($jobId, 'upload finished, waiting 30 seconds before bucket verification');
    sleep(30);

    logPushWorkerProgress($jobId, 'verifying uploaded files');
    if (!verifyBunnyUploadIntegrity($sourcePath, $destPath, $jobId, $codename)) {
        $message = "Background processing failed Bunny upload verification for job #$jobId";
        logPushWorkerProgress($jobId, $message);
        return [
            'success' => false,
            'error' => $message
        ];
    }

    logPushWorkerProgress($jobId, 'removing source directory');
    $cleanupResult = removeDirectoryRecursively($sourcePath);
    if (!$cleanupResult['success']) {
        $cleanupMessage = "Push completed but failed to remove pre-release source directory for job #$jobId: $sourcePath";

        if (!empty($cleanupResult['errors'])) {
            $cleanupMessage .= ' | errors: ' . implode(' | ', array_slice($cleanupResult['errors'], 0, 5));
        }

        logPushWorkerProgress($jobId, $cleanupMessage);

        if (shouldFailPushOnCleanupError()) {
            return [
                'success' => false,
                'error' => $cleanupMessage
            ];
        }
    }

    logPushWorkerProgress($jobId, 'invalidating cache');
    $cache = new BucketCache();
    $cache->invalidateCache();
    invalidateBunnyListingCachesForPath($destPath);

    logPushWorkerProgress($jobId, 'completed successfully and cache invalidated');

    $result = [
        'success' => true,
        'error' => null
    ];

    if (!$cleanupResult['success']) {
        $result['warning'] = 'Pre-release cleanup failed; source files may require manual removal';
    }

    return $result;
}

function uploadDirectoryToBunny($source, $destPath, $jobId = null, $codename = null) {
    if (!is_dir($source)) {
        return false;
    }

    $client = bunny_get_storage_client();
    $destPrefix = trim(normalizePushDestinationPath((string)$destPath), '/');

    $filesToProcess = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
        $remotePath = $destPrefix === '' ? $relativePath : ($destPrefix . '/' . $relativePath);

        $filesToProcess[] = [
            'relativePath' => $relativePath,
            'sourcePath' => $item->getPathname(),
            'remotePath' => $remotePath,
        ];
    }

    if ($jobId !== null) {
        logPushWorkerProgress($jobId, 'Uploading [' . count($filesToProcess) . '] files to Bunny storage');
    }

    foreach ($filesToProcess as $file) {
        if ($jobId !== null) {
            $displayName = ($codename ?: 'unknown') . ' - ' . $file['relativePath'];
            logPushWorkerProgress($jobId, '- ' . $displayName . ' (uploading)');
        }

        try {
            $client->upload($file['sourcePath'], $file['remotePath']);
        } catch (Throwable $e) {
            if ($jobId !== null) {
                logPushWorkerProgress($jobId, 'Upload failed for ' . $file['relativePath'] . ': ' . $e->getMessage());
            }
            return false;
        }

        if ($jobId !== null) {
            $displayName = ($codename ?: 'unknown') . ' - ' . $file['relativePath'];
            logPushWorkerProgress($jobId, '- ' . $displayName . ' (completed)');
        }
    }

    return true;
}

function verifyBunnyUploadIntegrity($source, $destPath, $jobId = null, $codename = null) {
    if (!is_dir($source)) {
        return false;
    }

    $client = bunny_get_storage_client();
    $destPrefix = trim(normalizePushDestinationPath((string)$destPath), '/');

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $verifiedFiles = 0;

    foreach ($sourceIterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', $sourceIterator->getSubPathName());
        $remotePath = $destPrefix === '' ? $relativePath : ($destPrefix . '/' . $relativePath);

        try {
            $rawInfo = bunny_describe_file_raw($remotePath);
            $remoteSize = (int)($rawInfo['length'] ?? 0);
        } catch (Throwable $rawException) {
            if ($jobId !== null) {
                logPushWorkerProgress($jobId, 'Verification failed for ' . $relativePath . ': ' . $rawException->getMessage());
            }
            return false;
        }

        if ((int)$item->getSize() !== $remoteSize) {
            if ($jobId !== null) {
                logPushWorkerProgress($jobId, 'Size mismatch for ' . $relativePath . ' local=' . (int)$item->getSize() . ' remote=' . $remoteSize);
            }
            return false;
        }

        $verifiedFiles++;
        if ($jobId !== null && $verifiedFiles % 50 === 0) {
            logPushWorkerProgress($jobId, 'verification progress: checked ' . $verifiedFiles . ' Bunny files');
        }
    }

    if ($jobId !== null) {
        logPushWorkerProgress($jobId, 'verification progress: checked ' . $verifiedFiles . ' Bunny files total');
    }

    return true;
}

function invalidateBunnyListingCachesForPath($path) {
    try {
        $cache = CacheManager::getInstance();
        $normalized = bunny_normalize_relative_path(normalizePushDestinationPath((string)$path));

        $paths = ['/'];
        if ($normalized !== '/') {
            $segments = explode('/', trim($normalized, '/'));
            $current = '';
            foreach ($segments as $segment) {
                $current .= '/' . $segment;
                $paths[] = $current;
            }
        }

        foreach (array_unique($paths) as $cachePath) {
            $cache->delete('bunny_listing:' . md5($cachePath));
        }
    } catch (Throwable $e) {
        error_log('Failed to invalidate Bunny listing cache for path ' . $path . ': ' . $e->getMessage());
    }
}

function normalizePushDestinationPath($path) {
    $path = trim((string)$path);

    if ($path === '') {
        return '/';
    }

    if (strpos($path, BASE_PATH) === 0) {
        $path = '/' . ltrim(str_replace(BASE_PATH, '', $path), '/');
    }

    return bunny_normalize_relative_path($path);
}

function shouldFailPushOnCleanupError() {
    $raw = getenv('PUSH_FAIL_ON_CLEANUP_ERROR');
    if ($raw === false || trim((string)$raw) === '') {
        return false;
    }

    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
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

function triggerPushSuccessDiscordWebhook($job, $runResult) {
    if (empty($runResult['success'])) {
        return [
            'sent' => false,
            'skipped' => true,
            'reason' => 'Job failed or not successful'
        ];
    }

    $config = getPushSuccessDiscordWebhookConfig();
    if (!$config['enabled']) {
        return [
            'sent' => false,
            'skipped' => true,
            'reason' => 'Discord success webhook disabled or URL missing'
        ];
    }

    $jobId = (int)($job['id'] ?? 0);
    $codename = $job['codename'] ?? 'unknown';
    $version = $job['version'] ?? 'unknown';
    $releaseDate = $job['release_date'] ?? 'unknown';

    $messageContent = "**CDN Push Release Success**\n";
    $messageContent .= "Push completed successfully for {$codename} {$version} ({$releaseDate})";

    $payload = [
        'username' => $config['username'],
        'content' => $messageContent,
        'allowed_mentions' => [
            'parse' => ['users']
        ]
    ];

    if ($config['avatarUrl'] !== '') {
        $payload['avatar_url'] = $config['avatarUrl'];
    }

    $result = sendJsonWebhookRequest($config['url'], $payload);
    error_log('[push-worker] Discord success webhook result for job #' . $jobId . ': ' . json_encode($result));

    return $result;
}

function triggerPushFailureDiscordWebhook($job, $runResult) {
    if (!empty($runResult['success'])) {
        return [
            'sent' => false,
            'skipped' => true,
            'reason' => 'Job succeeded'
        ];
    }

    $config = getPushFailureDiscordWebhookConfig();
    if (!$config['enabled']) {
        return [
            'sent' => false,
            'skipped' => true,
            'reason' => 'Discord failure webhook disabled or URL missing'
        ];
    }

    $jobId = (int)($job['id'] ?? 0);
    $codename = $job['codename'] ?? 'unknown';
    $errorMessage = trim((string)($runResult['error'] ?? $job['error_message'] ?? 'Unknown push failure'));

    $messageContent = "**CDN Push Release Error**\n";
    $messageContent .= "Error occurred when pushing release for {$codename}\n\n";
    $messageContent .= "{$errorMessage}\n\n";
    $messageContent .= "<@180736511354863627> Please investigate ASAP";

    $payload = [
        'username' => $config['username'],
        'content' => $messageContent,
        'allowed_mentions' => [
            'parse' => ['users']
        ]
    ];

    if ($config['avatarUrl'] !== '') {
        $payload['avatar_url'] = $config['avatarUrl'];
    }

    $result = sendJsonWebhookRequest($config['url'], $payload);
    error_log('[push-worker] Discord failure webhook result for job #' . $jobId . ': ' . json_encode($result));

    return $result;
}

function sendJsonWebhookRequest($url, $payload) {
    $responseBody = '';
    $httpCode = 0;
    $error = null;
    $body = json_encode($payload);

    if ($body === false) {
        return [
            'sent' => false,
            'httpCode' => 0,
            'error' => 'Failed to encode webhook payload'
        ];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body)
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
                'ignore_errors' => true
            ]
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
        'response' => substr($responseBody, 0, 1000)
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

function getPushSuccessDiscordWebhookConfig() {
    $url = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_URL') ?: ''));
    $username = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_USERNAME') ?: 'Evolution X Push Worker'));
    $avatarUrl = trim((string)(getenv('DISCORD_PUSH_SUCCESS_WEBHOOK_AVATAR_URL') ?: ''));

    return [
        'enabled' => $url !== '',
        'url' => $url,
        'username' => $username,
        'avatarUrl' => $avatarUrl
    ];
}

function getPushFailureDiscordWebhookConfig() {
    $url = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_URL') ?: ''));
    $username = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_USERNAME') ?: 'Evolution X Push Worker'));
    $avatarUrl = trim((string)(getenv('DISCORD_PUSH_FAILURE_WEBHOOK_AVATAR_URL') ?: ''));

    return [
        'enabled' => $url !== '',
        'url' => $url,
        'username' => $username,
        'avatarUrl' => $avatarUrl
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

function isPushQueueDuplicateError(PDOException $e) {
    // MySQL duplicate key (23000/1062) and SQLite unique constraint violation (23000/19).
    $errorInfo = $e->errorInfo ?? [];
    $sqlState = $errorInfo[0] ?? $e->getCode();
    $driverCode = isset($errorInfo[1]) ? (int)$errorInfo[1] : 0;
    $message = strtolower($e->getMessage());

    if ((string)$sqlState === '23000' && ($driverCode === 1062 || $driverCode === 19)) {
        return true;
    }

    return strpos($message, 'duplicate') !== false || strpos($message, 'unique constraint') !== false;
}

function findPushQueueJobBySourcePath($db, $sourcePath) {
    $stmt = $db->prepare('SELECT id, status FROM push_release_queue WHERE source_path = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sourcePath]);
    return $stmt->fetch() ?: null;
}

function queuePushReleaseFromDirectory($releaseDirectory, $requestedBy = 'watcher') {
    $normalizedDirectory = rtrim((string)$releaseDirectory, '/');
    if ($normalizedDirectory === '') {
        return [
            'status' => 'error',
            'message' => 'Release directory is required'
        ];
    }

    $realDirectory = realpath($normalizedDirectory);
    if ($realDirectory === false || !is_dir($realDirectory)) {
        return [
            'status' => 'error',
            'message' => 'Release directory not found: ' . $normalizedDirectory
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();
        $existingJob = findPushQueueJobBySourcePath($db, $realDirectory);
        if (is_array($existingJob)) {
            return [
                'status' => 'queued',
                'jobId' => (int)($existingJob['id'] ?? 0),
                'existing' => true,
                'sourcePath' => $realDirectory,
            ];
        }
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to check existing queue entry: ' . $e->getMessage(),
        ];
    }

    $zipFiles = glob($realDirectory . '/*.zip');
    if (!$zipFiles || !is_array($zipFiles)) {
        return [
            'status' => 'error',
            'message' => 'No release zip found in ' . $realDirectory
        ];
    }

    usort($zipFiles, function($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    $zipFile = $zipFiles[0];
    $metadata = parsePushReleaseMetadataFromFilename(basename($zipFile), $realDirectory);
    if ($metadata === null) {
        return [
            'status' => 'error',
            'message' => 'Unable to parse release metadata from ' . basename($zipFile)
        ];
    }

    return processPushRequest(
        $metadata['codename'],
        $metadata['date'],
        $metadata['version'],
        $metadata['buildType'],
        $requestedBy
    );
}

function parsePushReleaseMetadataFromFilename($filename, $releaseDirectory = '') {
    $filename = basename((string)$filename);
    $releaseDirectory = rtrim((string)$releaseDirectory, '/');

    if (!preg_match('/^EvolutionX-(\d+)\.(\d+)-([0-9]{8})-([A-Za-z0-9._-]+)-([A-Za-z0-9._-]+)-(Vanilla-)?Official\.zip$/i', $filename, $matches)) {
        return null;
    }

    $version = (string)(int)$matches[1];
    $dateRaw = (string)$matches[3];
    $codename = trim((string)$matches[4]);
    $buildType = !empty($matches[6]) ? 'vanilla' : 'gapps';

    if ($releaseDirectory !== '' && preg_match('/\/([A-Za-z0-9._-]+)\/(\d{8})(?:_Vanilla)?$/', $releaseDirectory, $dirMatches)) {
        $codename = $dirMatches[1];
        $dateRaw = $dirMatches[2];
        if (str_ends_with($releaseDirectory, '_Vanilla')) {
            $buildType = 'vanilla';
        }
    }

    $date = DateTime::createFromFormat('Ymd', $dateRaw);
    if (!$date) {
        return null;
    }

    return [
        'codename' => $codename,
        'date' => $date->format('Y-m-d'),
        'version' => $version,
        'buildType' => $buildType,
    ];
}

function copyRecursively($source, $dest, $jobId = null) {
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($dest)) {
        if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
            return false;
        }
    }

    $filesToProcess = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = $iterator->getSubPathName();
        $filesToProcess[] = [
            'relativePath' => $relativePath,
            'sourcePath' => $item->getPathname(),
            'destPath' => $dest . DIRECTORY_SEPARATOR . $relativePath,
        ];
    }

    $fileCount = count($filesToProcess);
    if ($jobId !== null) {
        logPushWorkerProgress($jobId, "Processing [$fileCount] files:");
    }

    foreach ($filesToProcess as $file) {
        $relativePath = $file['relativePath'];
        $sourcePath = $file['sourcePath'];
        $destPath = $file['destPath'];
        $destDir = dirname($destPath);

        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return false;
            }
        }

        if ($jobId !== null) {
            $displayName = $codename . ' - ' . $relativePath;
            logPushWorkerProgress($jobId, "- $displayName (copying 0%)");
        }

        if (!copyFileWithProgress($sourcePath, $destPath, $jobId, $relativePath)) {
            return false;
        }

        if ($jobId !== null) {
            $displayName = $codename . ' - ' . $relativePath;
            logPushWorkerProgress($jobId, "- $displayName (completed)");
        }
    }

    return true;
}

function copyFileWithProgress($sourcePath, $destPath, $jobId = null, $relativePath = null) {
    $sourceHandle = fopen($sourcePath, 'rb');
    if ($sourceHandle === false) {
        return false;
    }

    $destHandle = fopen($destPath, 'wb');
    if ($destHandle === false) {
        fclose($sourceHandle);
        return false;
    }

    $totalBytes = @filesize($sourcePath);
    $totalBytes = $totalBytes !== false ? (int)$totalBytes : 0;
    $bytesCopied = 0;
    $lastLoggedPercent = -1;
    $bufferSize = 1024 * 1024;

    while (!feof($sourceHandle)) {
        $chunk = fread($sourceHandle, $bufferSize);
        if ($chunk === false || $chunk === '') {
            break;
        }

        if (fwrite($destHandle, $chunk) === false) {
            fclose($sourceHandle);
            fclose($destHandle);
            @unlink($destPath);
            return false;
        }

        $bytesCopied += strlen($chunk);

        if ($jobId !== null && $totalBytes > 0) {
            $percent = (int)round(($bytesCopied / $totalBytes) * 100);
            if ($percent >= $lastLoggedPercent + 5 || $percent === 100) {
                $label = $relativePath !== null ? "- $codename - $relativePath ($percent%)" : "($percent%)";
                logPushWorkerProgress($jobId, $label);
                $lastLoggedPercent = $percent;
            }
        }
    }

    fclose($sourceHandle);
    fclose($destHandle);

    return true;
}

function verifyDirectoryCopyIntegrity($source, $dest, $jobId = null) {
    if (!is_dir($source) || !is_dir($dest)) {
        return false;
    }

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $verifiedFiles = 0;

    foreach ($sourceIterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = $sourceIterator->getSubPathName();
        $destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($destPath)) {
            return false;
        }

        if (filesize($item->getPathname()) !== filesize($destPath)) {
            return false;
        }

        $verifiedFiles++;
        if ($jobId !== null && $verifiedFiles % 100 === 0) {
            logPushWorkerProgress($jobId, "verification progress: checked $verifiedFiles files");
        }
    }

    if ($jobId !== null) {
        logPushWorkerProgress($jobId, "verification progress: checked $verifiedFiles files total");
    }

    return true;
}

function removeDirectoryRecursively($path) {
    $errors = [];

    if (!is_dir($path)) {
        return [
            'success' => false,
            'errors' => ["Path is not a directory: $path"]
        ];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();

        if ($item->isLink()) {
            if (!@unlink($itemPath)) {
                $errors[] = "Failed to remove symlink: $itemPath";
            }
            continue;
        }

        if ($item->isDir()) {
            if (!@rmdir($itemPath)) {
                @chmod($itemPath, 0755);
                if (!@rmdir($itemPath)) {
                    $errors[] = "Failed to remove directory: $itemPath";
                }
            }
        } else {
            if (!@unlink($itemPath)) {
                @chmod($itemPath, 0644);
                if (!@unlink($itemPath)) {
                    $errors[] = "Failed to remove file: $itemPath";
                }
            }
        }
    }

    if (!@rmdir($path)) {
        @chmod($path, 0755);
        if (!@rmdir($path)) {
            $errors[] = "Failed to remove root directory: $path";
        }
    }

    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
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
