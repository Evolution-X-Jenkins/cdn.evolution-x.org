<?php
/**
 * Download request rate limiting.
 *
 * Rules:
 * - More than 5 download initiations in 30 seconds triggers a block.
 * - Block escalation per identity: 30m -> 2h -> 24h -> permanent ban.
 * - User-level abuse is blocked per user ID.
 * - IP-level abuse is blocked only when multiple distinct users abuse from one IP.
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

class DownloadRateLimiter {
    private const WINDOW_SECONDS = 30;
    private const MAX_REQUESTS = 5;
    private const MULTI_USER_IP_THRESHOLD = 2;

    // Escalation ladder in seconds. The final step is permanent.
    private const BLOCK_LADDER = [1800, 7200, 86400];
    private const IP_USER_ACTIVITY_TTL = self::WINDOW_SECONDS * 3;
    private const IDENTITY_ASSOC_RETENTION_SECONDS = 2592000;
    private const IDENTITY_ASSOC_FILE = __DIR__ . '/../../data/cache/rate_limit_identity_associations.json';

    private $db;
    private $redis;
    private $redisAvailable = false;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->initializeRedis();
    }

    private function initializeRedis() {
        try {
            if (!class_exists('Redis')) {
                return;
            }

            $this->redis = new Redis();
            if (defined('CACHE_REDIS_SOCKET') && CACHE_REDIS_SOCKET) {
                $this->redis->connect(CACHE_REDIS_SOCKET);
            } else {
                $this->redis->connect(CACHE_REDIS_HOST, CACHE_REDIS_PORT, 1);
            }

            if (defined('CACHE_REDIS_PASSWORD') && CACHE_REDIS_PASSWORD) {
                $this->redis->auth(CACHE_REDIS_PASSWORD);
            }

            $this->redisAvailable = true;
        } catch (Exception $e) {
            error_log('Rate limiter Redis initialization failed: ' . $e->getMessage());
            $this->redis = null;
            $this->redisAvailable = false;
        }
    }

    public function enforce(string $routeName, string $filePath = ''): array {
        $userId = get_user_id();
        $ipAddress = get_client_ip();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $requestId = $this->recordRequest($userId, $ipAddress, $routeName, $filePath, $userAgent);
        $this->recordIdentityAssociation($ipAddress, $userId);
        $this->registerIpUserActivity($ipAddress, $userId);

        if ($userId !== '') {
            $existingUserBlock = $this->getActiveBlock($this->identityKey('user', $userId));
            if (!empty($existingUserBlock)) {
                return $existingUserBlock;
            }
        }

        if ($ipAddress !== '') {
            $existingIpBlock = $this->getActiveBlock($this->identityKey('ip', $ipAddress));
            if (!empty($existingIpBlock)) {
                return $existingIpBlock;
            }
        }

        if ($userId !== '') {
            $userEvaluation = $this->evaluateIdentity('user', $userId, $userId, $routeName, $filePath, $userAgent, $requestId);
            if (!empty($userEvaluation['blocked'])) {
                return $userEvaluation;
            }
        }

        if ($ipAddress !== '') {
            $ipEvaluation = $this->evaluateIpIdentity($ipAddress, $userId, $routeName, $filePath, $userAgent, $requestId);
            if (!empty($ipEvaluation['blocked'])) {
                return $ipEvaluation;
            }
        }

        return [
            'blocked' => false,
            'retryAfterSeconds' => 0,
            'blockedUntil' => null,
            'isPermanent' => false,
            'reason' => null,
        ];
    }

    public function getCurrentStatus(): array {
        $userId = get_user_id();
        $ipAddress = get_client_ip();

        if ($userId !== '') {
            $userBlock = $this->getActiveBlock($this->identityKey('user', $userId));
            if (!empty($userBlock)) {
                return [
                    'blocked' => true,
                    'retryAfterSeconds' => (int)($userBlock['retryAfterSeconds'] ?? 0),
                    'blockedUntil' => $userBlock['blockedUntil'] ?? null,
                    'isPermanent' => !empty($userBlock['isPermanent']),
                    'reason' => $userBlock['reason'] ?? 'rate_limit_exceeded',
                    'identityType' => 'user',
                ];
            }
        }

        if ($ipAddress !== '') {
            $ipBlock = $this->getActiveBlock($this->identityKey('ip', $ipAddress));
            if (!empty($ipBlock)) {
                return [
                    'blocked' => true,
                    'retryAfterSeconds' => (int)($ipBlock['retryAfterSeconds'] ?? 0),
                    'blockedUntil' => $ipBlock['blockedUntil'] ?? null,
                    'isPermanent' => !empty($ipBlock['isPermanent']),
                    'reason' => $ipBlock['reason'] ?? 'rate_limit_exceeded',
                    'identityType' => 'ip',
                ];
            }
        }

        return [
            'blocked' => false,
            'retryAfterSeconds' => 0,
            'blockedUntil' => null,
            'isPermanent' => false,
            'reason' => null,
            'identityType' => null,
        ];
    }

    public function getIdentityStatus(string $identityType, string $identityValue): array {
        $identityType = strtolower(trim($identityType));
        $identityValue = trim($identityValue);

        if (!in_array($identityType, ['ip', 'user'], true) || $identityValue === '') {
            return [
                'identityType' => $identityType,
                'identityValue' => $identityValue,
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'offenseLevel' => 0,
            ];
        }

        $identityKey = $this->identityKey($identityType, $identityValue);
        $active = $this->getActiveBlock($identityKey);
        $offenseLevel = $this->db->getRateLimitOffenseLevel($identityKey);
        $latestIncident = $this->db->getLatestRateLimitIncident($identityKey);

        return [
            'identityType' => $identityType,
            'identityValue' => $identityValue,
            'blocked' => !empty($active['blocked']),
            'retryAfterSeconds' => (int)($active['retryAfterSeconds'] ?? 0),
            'blockedUntil' => $active['blockedUntil'] ?? null,
            'isPermanent' => !empty($active['isPermanent']),
            'offenseLevel' => (int)$offenseLevel,
            'lastIncidentAt' => $latestIncident['created_at'] ?? null,
        ];
    }

    public function unblockIdentity(string $identityType, string $identityValue): array {
        $identityType = strtolower(trim($identityType));
        $identityValue = trim($identityValue);

        if (!in_array($identityType, ['ip', 'user'], true) || $identityValue === '') {
            throw new InvalidArgumentException('identityType must be ip or user, and identityValue is required');
        }

        $identityKey = $this->identityKey($identityType, $identityValue);
        $clearedRows = $this->db->clearActiveRateLimitIncidents($identityKey);

        if ($this->redisAvailable) {
            try {
                $this->redis->del('rl:block:' . $identityKey);
                $this->redis->del('rl:offense:' . $identityKey);
                $this->redis->del('rl:req:' . $identityKey);
                if ($identityType === 'ip') {
                    $this->redis->del('rl:ipusers:' . hash('sha256', $identityValue));
                }
            } catch (Exception $e) {
                error_log('Rate limiter unblock redis cleanup failed: ' . $e->getMessage());
            }
        }

        return [
            'identityType' => $identityType,
            'identityValue' => $identityValue,
            'unblocked' => true,
            'clearedIncidentRows' => $clearedRows,
        ];
    }

    private function evaluateIdentity(
        string $identityType,
        string $identityValue,
        string $userId,
        string $routeName,
        string $filePath,
        string $userAgent,
        int $requestId
    ): array {
        if ($identityValue === '') {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        $identityKey = $this->identityKey($identityType, $identityValue);

        // Existing block check first.
        $existingBlock = $this->getActiveBlock($identityKey);
        if (!empty($existingBlock)) {
            return $existingBlock;
        }

        $requestCount = $this->registerAndCountRecentRequests($identityKey);
        if ($requestCount <= self::MAX_REQUESTS) {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        $nextOffense = $this->incrementOffenseCount($identityKey);
        $isPermanent = $nextOffense > count(self::BLOCK_LADDER);
        $blockSeconds = $isPermanent ? 0 : self::BLOCK_LADDER[$nextOffense - 1];
        $blockedUntil = $isPermanent ? null : date('Y-m-d H:i:s', time() + $blockSeconds);

        $incidentId = $this->db->createRateLimitIncident([
            'identity_key' => $identityKey,
            'identity_type' => $identityType,
            'identity_value' => $identityValue,
            'user_id' => $userId,
            'ip_address' => get_client_ip(),
            'route_name' => $routeName,
            'file_path' => $filePath,
            'request_id' => $requestId,
            'window_seconds' => self::WINDOW_SECONDS,
            'request_count' => $requestCount,
            'offense_level' => $nextOffense,
            'block_seconds' => $blockSeconds,
            'blocked_until' => $blockedUntil,
            'is_permanent' => $isPermanent ? 1 : 0,
            'action_taken' => $isPermanent ? 'permanent_ban' : 'temporary_block',
            'csf_status' => 'pending',
            'notes' => $isPermanent ? 'Escalated to permanent ban threshold' : 'Rate limit threshold exceeded',
            'user_agent' => $userAgent,
        ]);

        if ($isPermanent) {
            $csfStatus = $this->applyCsfBan($identityType, $identityValue);
            $this->db->updateRateLimitIncidentCsfStatus((int)$incidentId, $csfStatus);
        }

        $this->notifyDiscordRateLimitIncident([
            'incidentId' => (int)$incidentId,
            'identityType' => $identityType,
            'identityValue' => $identityValue,
            'userId' => $userId,
            'ipAddress' => get_client_ip(),
            'routeName' => $routeName,
            'filePath' => $filePath,
            'windowSeconds' => self::WINDOW_SECONDS,
            'requestCount' => $requestCount,
            'offenseLevel' => $nextOffense,
            'blockSeconds' => $blockSeconds,
            'blockedUntil' => $blockedUntil,
            'blockedAtUnix' => time(),
            'isPermanent' => $isPermanent,
            'csfStatus' => $csfStatus ?? 'not_applicable',
            'userAgent' => $userAgent,
        ]);

        $this->setBlockState($identityKey, $blockSeconds, $isPermanent, $blockedUntil, $nextOffense);

        return [
            'blocked' => true,
            'retryAfterSeconds' => $isPermanent ? 0 : $blockSeconds,
            'blockedUntil' => $blockedUntil,
            'isPermanent' => $isPermanent,
            'reason' => $isPermanent ? 'permanent_firewall_ban' : 'rate_limit_exceeded',
        ];
    }

    private function evaluateIpIdentity(
        string $ipAddress,
        string $userId,
        string $routeName,
        string $filePath,
        string $userAgent,
        int $requestId
    ): array {
        if ($ipAddress === '') {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        $identityKey = $this->identityKey('ip', $ipAddress);

        $existingBlock = $this->getActiveBlock($identityKey);
        if (!empty($existingBlock)) {
            return $existingBlock;
        }

        $requestCount = $this->registerAndCountRecentRequests($identityKey);
        if ($requestCount <= self::MAX_REQUESTS) {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        $distinctUsers = $this->countRecentDistinctUsersForIp($ipAddress);
        if ($distinctUsers < self::MULTI_USER_IP_THRESHOLD) {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        $nextOffense = $this->incrementOffenseCount($identityKey);
        $isPermanent = $nextOffense > count(self::BLOCK_LADDER);
        $blockSeconds = $isPermanent ? 0 : self::BLOCK_LADDER[$nextOffense - 1];
        $blockedUntil = $isPermanent ? null : date('Y-m-d H:i:s', time() + $blockSeconds);

        $incidentId = $this->db->createRateLimitIncident([
            'identity_key' => $identityKey,
            'identity_type' => 'ip',
            'identity_value' => $ipAddress,
            'user_id' => $userId,
            'ip_address' => get_client_ip(),
            'route_name' => $routeName,
            'file_path' => $filePath,
            'request_id' => $requestId,
            'window_seconds' => self::WINDOW_SECONDS,
            'request_count' => $requestCount,
            'offense_level' => $nextOffense,
            'block_seconds' => $blockSeconds,
            'blocked_until' => $blockedUntil,
            'is_permanent' => $isPermanent ? 1 : 0,
            'action_taken' => $isPermanent ? 'permanent_ban' : 'temporary_block',
            'csf_status' => 'pending',
            'notes' => $isPermanent
                ? 'Escalated to permanent ban threshold (multi-user IP abuse)'
                : 'Rate limit threshold exceeded (multi-user IP abuse)',
            'user_agent' => $userAgent,
        ]);

        if ($isPermanent) {
            $csfStatus = $this->applyCsfBan('ip', $ipAddress);
            $this->db->updateRateLimitIncidentCsfStatus((int)$incidentId, $csfStatus);
        }

        $this->notifyDiscordRateLimitIncident([
            'incidentId' => (int)$incidentId,
            'identityType' => 'ip',
            'identityValue' => $ipAddress,
            'userId' => $userId,
            'ipAddress' => get_client_ip(),
            'routeName' => $routeName,
            'filePath' => $filePath,
            'windowSeconds' => self::WINDOW_SECONDS,
            'requestCount' => $requestCount,
            'offenseLevel' => $nextOffense,
            'blockSeconds' => $blockSeconds,
            'blockedUntil' => $blockedUntil,
            'blockedAtUnix' => time(),
            'isPermanent' => $isPermanent,
            'csfStatus' => $csfStatus ?? 'not_applicable',
            'userAgent' => $userAgent,
        ]);

        $this->setBlockState($identityKey, $blockSeconds, $isPermanent, $blockedUntil, $nextOffense);

        return [
            'blocked' => true,
            'retryAfterSeconds' => $isPermanent ? 0 : $blockSeconds,
            'blockedUntil' => $blockedUntil,
            'isPermanent' => $isPermanent,
            'reason' => $isPermanent ? 'permanent_firewall_ban' : 'rate_limit_exceeded',
        ];
    }

    private function countRecentDistinctUsersForIp(string $ipAddress): int {
        if ($ipAddress === '') {
            return 0;
        }

        if ($this->redisAvailable) {
            try {
                $now = time();
                $windowStart = $now - self::WINDOW_SECONDS;
                $ipUserKey = 'rl:ipusers:' . hash('sha256', $ipAddress);
                $this->redis->zRemRangeByScore($ipUserKey, 0, $windowStart - 1);
                return (int)$this->redis->zCard($ipUserKey);
            } catch (Exception $e) {
                error_log('Rate limiter IP distinct-user read failed: ' . $e->getMessage());
            }
        }

        return (int)$this->db->countRecentDistinctUsersByIpIdentity($this->identityKey('ip', $ipAddress), self::WINDOW_SECONDS);
    }

    private function registerIpUserActivity(string $ipAddress, string $userId): void {
        if (!$this->redisAvailable || $ipAddress === '' || $userId === '') {
            return;
        }

        try {
            $now = time();
            $windowStart = $now - self::WINDOW_SECONDS;
            $ipUserKey = 'rl:ipusers:' . hash('sha256', $ipAddress);
            $this->redis->zAdd($ipUserKey, $now, $userId);
            $this->redis->zRemRangeByScore($ipUserKey, 0, $windowStart - 1);
            $this->redis->expire($ipUserKey, self::IP_USER_ACTIVITY_TTL);
        } catch (Exception $e) {
            error_log('Rate limiter IP distinct-user track failed: ' . $e->getMessage());
        }
    }

    private function recordIdentityAssociation(string $ipAddress, string $userId): void {
        if ($ipAddress === '') {
            return;
        }

        $path = self::IDENTITY_ASSOC_FILE;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = json_decode($raw ?: '', true);
            if (!is_array($data)) {
                $data = ['ips' => []];
            }
            if (!isset($data['ips']) || !is_array($data['ips'])) {
                $data['ips'] = [];
            }

            $now = time();
            $cutoff = $now - self::IDENTITY_ASSOC_RETENTION_SECONDS;

            foreach ($data['ips'] as $storedIp => $ipInfo) {
                if (!is_array($ipInfo)) {
                    unset($data['ips'][$storedIp]);
                    continue;
                }

                if (!isset($ipInfo['users']) || !is_array($ipInfo['users'])) {
                    $ipInfo['users'] = [];
                }

                foreach ($ipInfo['users'] as $storedUserId => $lastSeen) {
                    if ((int)$lastSeen < $cutoff) {
                        unset($ipInfo['users'][$storedUserId]);
                    }
                }

                $ipLastSeen = isset($ipInfo['last_seen']) ? (int)$ipInfo['last_seen'] : 0;
                if ($ipLastSeen < $cutoff && empty($ipInfo['users'])) {
                    unset($data['ips'][$storedIp]);
                    continue;
                }

                $data['ips'][$storedIp] = [
                    'last_seen' => $ipLastSeen,
                    'users' => $ipInfo['users'],
                ];
            }

            if (!isset($data['ips'][$ipAddress]) || !is_array($data['ips'][$ipAddress])) {
                $data['ips'][$ipAddress] = [
                    'last_seen' => $now,
                    'users' => [],
                ];
            }

            $data['ips'][$ipAddress]['last_seen'] = $now;
            if ($userId !== '') {
                if (!isset($data['ips'][$ipAddress]['users']) || !is_array($data['ips'][$ipAddress]['users'])) {
                    $data['ips'][$ipAddress]['users'] = [];
                }
                $data['ips'][$ipAddress]['users'][$userId] = $now;
            }

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                flock($handle, LOCK_UN);
                return;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function registerAndCountRecentRequests(string $identityKey): int {
        if (!$this->redisAvailable) {
            // DB fallback approximation when Redis is unavailable.
            return $this->db->countRecentRequestsByIdentity($identityKey, self::WINDOW_SECONDS);
        }

        $now = time();
        $windowStart = $now - self::WINDOW_SECONDS;
        $requestKey = 'rl:req:' . $identityKey;

        try {
            $member = $now . '-' . bin2hex(random_bytes(4));
            $this->redis->zAdd($requestKey, $now, $member);
            $this->redis->zRemRangeByScore($requestKey, 0, $windowStart - 1);
            $this->redis->expire($requestKey, self::WINDOW_SECONDS * 3);
            return (int)$this->redis->zCard($requestKey);
        } catch (Exception $e) {
            error_log('Rate limiter request counter failed: ' . $e->getMessage());
            return $this->db->countRecentRequestsByIdentity($identityKey, self::WINDOW_SECONDS);
        }
    }

    private function getActiveBlock(string $identityKey): array {
        if ($this->redisAvailable) {
            try {
                $raw = $this->redis->get('rl:block:' . $identityKey);
                if ($raw !== false && $raw !== null) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        if (!empty($decoded['isPermanent'])) {
                            return [
                                'blocked' => true,
                                'retryAfterSeconds' => 0,
                                'blockedUntil' => null,
                                'isPermanent' => true,
                                'reason' => 'permanent_firewall_ban',
                            ];
                        }

                        $blockedUntilTs = isset($decoded['blockedUntil']) ? (int)$decoded['blockedUntil'] : 0;
                        if ($blockedUntilTs > time()) {
                            return [
                                'blocked' => true,
                                'retryAfterSeconds' => $blockedUntilTs - time(),
                                'blockedUntil' => date('Y-m-d H:i:s', $blockedUntilTs),
                                'isPermanent' => false,
                                'reason' => 'rate_limit_exceeded',
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Rate limiter block read failed: ' . $e->getMessage());
            }
        }

        $incident = $this->db->getActiveRateLimitIncident($identityKey);
        if (!$incident) {
            return [];
        }

        $isPermanent = !empty($incident['is_permanent']);
        if ($isPermanent) {
            return [
                'blocked' => true,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => true,
                'reason' => 'permanent_firewall_ban',
            ];
        }

        $blockedUntil = isset($incident['blocked_until']) ? strtotime((string)$incident['blocked_until']) : 0;
        $now = time();

        // Handle clock skew between PHP and DB by falling back to created_at + block_seconds.
        if ($blockedUntil <= $now) {
            $createdAtTs = isset($incident['created_at']) ? strtotime((string)$incident['created_at']) : 0;
            $blockSeconds = isset($incident['block_seconds']) ? (int)$incident['block_seconds'] : 0;
            if ($createdAtTs > 0 && $blockSeconds > 0) {
                $derivedUntil = $createdAtTs + $blockSeconds;
                if ($derivedUntil > $now) {
                    $blockedUntil = $derivedUntil;
                }
            }
        }

        if ($blockedUntil <= $now) {
            return [];
        }

        return [
            'blocked' => true,
            'retryAfterSeconds' => $blockedUntil - $now,
            'blockedUntil' => date('Y-m-d H:i:s', $blockedUntil),
            'isPermanent' => false,
            'reason' => 'rate_limit_exceeded',
        ];
    }

    private function setBlockState(string $identityKey, int $blockSeconds, bool $isPermanent, ?string $blockedUntil, int $offenseLevel): void {
        if (!$this->redisAvailable) {
            return;
        }

        $payload = [
            'isPermanent' => $isPermanent,
            'blockedUntil' => $blockedUntil ? strtotime($blockedUntil) : null,
            'offenseLevel' => $offenseLevel,
        ];

        try {
            $blockKey = 'rl:block:' . $identityKey;
            if ($isPermanent) {
                $this->redis->set($blockKey, json_encode($payload, JSON_UNESCAPED_SLASHES));
            } else {
                $this->redis->setex($blockKey, max($blockSeconds, 1), json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
        } catch (Exception $e) {
            error_log('Rate limiter block write failed: ' . $e->getMessage());
        }
    }

    private function incrementOffenseCount(string $identityKey): int {
        if ($this->redisAvailable) {
            try {
                $offenseKey = 'rl:offense:' . $identityKey;
                $offenseCount = (int)$this->redis->incr($offenseKey);
                // Keep history for 90 days to preserve escalation trend.
                $this->redis->expire($offenseKey, 90 * 24 * 3600);
                return max($offenseCount, 1);
            } catch (Exception $e) {
                error_log('Rate limiter offense increment failed: ' . $e->getMessage());
            }
        }

        return $this->db->getRateLimitOffenseLevel($identityKey) + 1;
    }

    private function applyCsfBan(string $identityType, string $identityValue): string {
        if ($identityType !== 'ip') {
            return 'skipped_non_ip_identity';
        }

        $cleanIp = trim($identityValue);
        if (!filter_var($cleanIp, FILTER_VALIDATE_IP)) {
            return 'invalid_ip';
        }

        $csfPath = trim((string)shell_exec('command -v csf 2>/dev/null'));
        if ($csfPath === '') {
            return 'csf_not_found';
        }

        $message = escapeshellarg('php_filebrowser_v2 permanent rate-limit escalation');
        $ipArg = escapeshellarg($cleanIp);
        $command = $csfPath . ' -d ' . $ipArg . ' ' . $message . ' 2>&1';

        $output = shell_exec($command);
        if ($output === null) {
            return 'csf_exec_failed';
        }

        return 'csf_banned';
    }

    private function recordRequest(string $userId, string $ipAddress, string $routeName, string $filePath, string $userAgent): int {
        $identityIp = $this->identityKey('ip', $ipAddress);
        $identityUser = $this->identityKey('user', $userId);

        return $this->db->recordRateLimitRequest([
            'identity_ip_key' => $identityIp,
            'identity_user_key' => $identityUser,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'route_name' => $routeName,
            'file_path' => $filePath,
            'user_agent' => $userAgent,
        ]);
    }

    private function identityKey(string $type, string $value): string {
        return $type . ':' . hash('sha256', $value);
    }

    private function notifyDiscordRateLimitIncident(array $incident): void {
        $config = $this->getDiscordRateLimitWebhookConfig();
        $isPermanent = !empty($incident['isPermanent']);
        $offenseLevel = (int)($incident['offenseLevel'] ?? 0);
        $severity = $isPermanent ? 'PERMANENT BAN' : 'Temporary Block';
        $color = 3447003; // Blue default

        if ($offenseLevel >= 4 || $isPermanent) {
            $color = 15158332; // Red
        } elseif ($offenseLevel === 3) {
            $color = 15105570; // Orange
        } elseif ($offenseLevel === 2) {
            $color = 16776960; // Yellow
        }

        $durationLabel = $isPermanent
            ? 'Permanent'
            : $this->formatDurationLabel((int)($incident['blockSeconds'] ?? 0));
        $blockedAtUnix = (int)($incident['blockedAtUnix'] ?? time());
        $blockedAtLabel = '<t:' . $blockedAtUnix . ':F> (<t:' . $blockedAtUnix . ':R>)';
        $blockedUntilRaw = (string)($incident['blockedUntil'] ?? '');
        $blockedUntilUnix = $blockedUntilRaw !== '' ? strtotime($blockedUntilRaw) : false;
        $blockedUntilLabel = ($blockedUntilUnix !== false && $blockedUntilUnix > 0)
            ? '<t:' . $blockedUntilUnix . ':F> (<t:' . $blockedUntilUnix . ':R>)'
            : 'n/a';
        $maskedIpAddress = $this->maskIpForInternalWebhook((string)($incident['ipAddress'] ?? 'unknown'));
        $maskedUserId = $this->maskUserIdForInternalWebhook((string)($incident['userId'] ?? 'unknown'));

        $title = 'Download Rate Limit Incident - ' . $severity;
        $summaryLines = [
            '**Incident ID:** `' . (string)($incident['incidentId'] ?? 0) . '`',
            '**Offense Level:** `' . (string)$offenseLevel . '`',
            '**Identity Type:** `' . (string)($incident['identityType'] ?? 'unknown') . '`',
            '**IP Address:** `' . (string)($incident['ipAddress'] ?? 'unknown') . '`',
            '**User ID:** `' . (string)($incident['userId'] ?? 'unknown') . '`',
            '**File Path:** `' . (string)($incident['filePath'] ?? 'n/a') . '`',
            '**Blocked At:** ' . $blockedAtLabel,
            '**Block Duration:** `' . $durationLabel . '`',
            '**Blocked Until:** ' . $blockedUntilLabel,
            '**CSF Status:** `' . (string)($incident['csfStatus'] ?? 'not_applicable') . '`',
        ];
        $description = implode("\n\n", $summaryLines);

        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'footer' => [
                'text' => 'Evolution X Rate Limit Guard'
            ],
            'timestamp' => gmdate('c'),
        ];

        if ($config['enabled']) {
            $payload = [
                'username' => $config['username'],
                'embeds' => [$embed],
            ];

            if ($config['avatarUrl'] !== '') {
                $payload['avatar_url'] = $config['avatarUrl'];
            }

            if ($config['mention'] !== '') {
                $payload['content'] = $config['mention'];
                $payload['allowed_mentions'] = [
                    'parse' => ['users', 'roles'],
                ];
            }

            $result = $this->sendJsonWebhookRequest($config['url'], $payload);
            if (empty($result['sent'])) {
                error_log('Rate limit Discord webhook failed: ' . ($result['error'] ?? 'unknown error'));
            } else {
                error_log('Rate limit Discord webhook sent (HTTP ' . (int)($result['httpCode'] ?? 0) . ') for incident #' . (int)($incident['incidentId'] ?? 0));
            }
        }

        $internalConfig = $this->getDiscordRateLimitInternalWebhookConfig();
        if ($internalConfig['enabled']) {
            $internalSummaryLines = [
                '**Incident ID:** `' . (string)($incident['incidentId'] ?? 0) . '`',
                '**Severity:** `' . $severity . '`',
                '**Offense Level:** `' . (string)$offenseLevel . '`',
                '**Identity Type:** `' . (string)($incident['identityType'] ?? 'unknown') . '`',
                '**IP Address:** `' . $maskedIpAddress . '`',
                '**User ID:** `' . $maskedUserId . '`',
                '**File Path:** `' . (string)($incident['filePath'] ?? 'n/a') . '`',
                '**Blocked At:** ' . $blockedAtLabel,
                '**Block Duration:** `' . $durationLabel . '`',
                '**Blocked Until:** ' . $blockedUntilLabel,
                '_Additional identity details intentionally omitted._',
            ];

            $internalEmbed = [
                'title' => 'Download Rate Limit Incident - Internal',
                'description' => implode("\n\n", $internalSummaryLines),
                'color' => $color,
                'footer' => [
                    'text' => 'Evolution X Rate Limit Internal'
                ],
                'timestamp' => gmdate('c'),
            ];

            $internalPayload = [
                'username' => $internalConfig['username'],
                'embeds' => [$internalEmbed],
            ];

            if ($internalConfig['avatarUrl'] !== '') {
                $internalPayload['avatar_url'] = $internalConfig['avatarUrl'];
            }

            if ($internalConfig['mention'] !== '') {
                $internalPayload['content'] = $internalConfig['mention'];
                $internalPayload['allowed_mentions'] = [
                    'parse' => ['users', 'roles'],
                ];
            }

            $internalResult = $this->sendJsonWebhookRequest($internalConfig['url'], $internalPayload);
            if (empty($internalResult['sent'])) {
                error_log('Rate limit internal Discord webhook failed: ' . ($internalResult['error'] ?? 'unknown error'));
            } else {
                error_log('Rate limit internal Discord webhook sent (HTTP ' . (int)($internalResult['httpCode'] ?? 0) . ') for incident #' . (int)($incident['incidentId'] ?? 0));
            }
        }
    }

    private function getDiscordRateLimitWebhookConfig(): array {
        return [
            'enabled' => defined('DISCORD_RATE_LIMIT_WEBHOOK_URL') && DISCORD_RATE_LIMIT_WEBHOOK_URL !== '',
            'url' => defined('DISCORD_RATE_LIMIT_WEBHOOK_URL') ? DISCORD_RATE_LIMIT_WEBHOOK_URL : '',
            'username' => defined('DISCORD_RATE_LIMIT_WEBHOOK_USERNAME') ? DISCORD_RATE_LIMIT_WEBHOOK_USERNAME : 'Evolution X Rate Limit Guard',
            'avatarUrl' => defined('DISCORD_RATE_LIMIT_WEBHOOK_AVATAR_URL') ? DISCORD_RATE_LIMIT_WEBHOOK_AVATAR_URL : '',
            'mention' => defined('DISCORD_RATE_LIMIT_WEBHOOK_MENTION') ? DISCORD_RATE_LIMIT_WEBHOOK_MENTION : '',
        ];
    }

    private function getDiscordRateLimitInternalWebhookConfig(): array {
        return [
            'enabled' => defined('DISCORD_GDPR_WEBHOOK_URL') && DISCORD_GDPR_WEBHOOK_URL !== '',
            'url' => defined('DISCORD_GDPR_WEBHOOK_URL') ? DISCORD_GDPR_WEBHOOK_URL : '',
            'username' => defined('DISCORD_RATE_LIMIT_WEBHOOK_USERNAME') ? DISCORD_RATE_LIMIT_WEBHOOK_USERNAME : 'Evolution X Rate Limit Guard',
            'avatarUrl' => defined('DISCORD_RATE_LIMIT_WEBHOOK_AVATAR_URL') ? DISCORD_RATE_LIMIT_WEBHOOK_AVATAR_URL : '',
            'mention' => defined('DISCORD_RATE_LIMIT_WEBHOOK_MENTION') ? DISCORD_RATE_LIMIT_WEBHOOK_MENTION : '',
        ];
    }

    private function sendJsonWebhookRequest(string $url, array $payload): array {
        $responseBody = '';
        $httpCode = 0;
        $error = null;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

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
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);

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
                    'timeout' => 8,
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

    private function formatDurationLabel(int $seconds): string {
        if ($seconds <= 0) {
            return 'n/a';
        }

        if ($seconds % 3600 === 0) {
            $hours = (int)($seconds / 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }

        if ($seconds % 60 === 0) {
            $minutes = (int)($seconds / 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        return $seconds . ' seconds';
    }

    private function maskIpForInternalWebhook(string $ipAddress): string {
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '') {
            return 'unknown';
        }

        if (strpos($ipAddress, '.') !== false) {
            $parts = explode('.', $ipAddress);
            foreach ($parts as $index => $part) {
                if ($index >= 2) {
                    $parts[$index] = '-';
                }
            }
            return implode('.', $parts);
        }

        if (strpos($ipAddress, ':') !== false) {
            $parts = explode(':', $ipAddress);
            foreach ($parts as $index => $part) {
                if ($index >= 2) {
                    $parts[$index] = '-';
                }
            }
            return implode(':', $parts);
        }

        return '-';
    }

    private function maskUserIdForInternalWebhook(string $userId): string {
        $userId = trim($userId);
        if ($userId === '') {
            return 'unknown';
        }

        $visible = substr($userId, 0, 12);
        $length = strlen($userId);
        if ($length <= 12) {
            return $visible;
        }

        return $visible . str_repeat('-', $length - 12);
    }
}

function enforce_download_rate_limit(string $routeName, string $filePath = ''): array {
    $limiter = new DownloadRateLimiter();
    return $limiter->enforce($routeName, $filePath);
}

function get_download_rate_limit_status(): array {
    $limiter = new DownloadRateLimiter();
    return $limiter->getCurrentStatus();
}

function get_rate_limit_identity_status(string $identityType, string $identityValue): array {
    $limiter = new DownloadRateLimiter();
    return $limiter->getIdentityStatus($identityType, $identityValue);
}

function unblock_rate_limit_identity(string $identityType, string $identityValue): array {
    $limiter = new DownloadRateLimiter();
    return $limiter->unblockIdentity($identityType, $identityValue);
}

function render_rate_limit_429_page(array $limitState, string $routeName = ''): void {
    $retrySeconds = (int)($limitState['retryAfterSeconds'] ?? 0);
    $isPermanent = !empty($limitState['isPermanent']);
    $blockedUntil = $limitState['blockedUntil'] ?? null;

    http_response_code(429);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($isPermanent) {
        header('Retry-After: 315360000');
    } elseif ($retrySeconds > 0) {
        header('Retry-After: ' . $retrySeconds);
    }

    log_action('Rate limit blocked request', $routeName . ' :: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

    $page_title = 'Too Many Requests';
    include __DIR__ . '/../../templates/429.php';
    exit;
}
