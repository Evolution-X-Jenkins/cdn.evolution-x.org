<?php
/**
 * Download request rate limiting.
 *
 * Rules:
 * - More than 3 download initiations in 60 seconds triggers a block.
 * - Block escalation per identity: 30m -> 2h -> 24h -> permanent ban.
 * - Applies to both IP and user ID identities; stricter result wins.
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

class DownloadRateLimiter {
    private const WINDOW_SECONDS = 60;
    private const MAX_REQUESTS = 3;

    // Escalation ladder in seconds. The final step is permanent.
    private const BLOCK_LADDER = [1800, 7200, 86400];

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

        $evaluations = [];
        $evaluations[] = $this->evaluateIdentity('ip', $ipAddress, $userId, $routeName, $filePath, $userAgent, $requestId);

        if (!empty($userId)) {
            $evaluations[] = $this->evaluateIdentity('user', $userId, $userId, $routeName, $filePath, $userAgent, $requestId);
        }

        $activeBlocks = array_values(array_filter($evaluations, static function ($result) {
            return !empty($result['blocked']);
        }));

        if (empty($activeBlocks)) {
            return [
                'blocked' => false,
                'retryAfterSeconds' => 0,
                'blockedUntil' => null,
                'isPermanent' => false,
                'reason' => null,
            ];
        }

        // Permanent block has highest priority, then the furthest blocked-until timestamp.
        usort($activeBlocks, static function ($a, $b) {
            if ($a['isPermanent'] && !$b['isPermanent']) {
                return -1;
            }
            if (!$a['isPermanent'] && $b['isPermanent']) {
                return 1;
            }

            $aUntil = isset($a['blockedUntil']) && $a['blockedUntil'] !== null ? strtotime($a['blockedUntil']) : 0;
            $bUntil = isset($b['blockedUntil']) && $b['blockedUntil'] !== null ? strtotime($b['blockedUntil']) : 0;
            return $bUntil <=> $aUntil;
        });

        $winner = $activeBlocks[0];
        return [
            'blocked' => true,
            'retryAfterSeconds' => (int)($winner['retryAfterSeconds'] ?? 0),
            'blockedUntil' => $winner['blockedUntil'] ?? null,
            'isPermanent' => !empty($winner['isPermanent']),
            'reason' => $winner['reason'] ?? 'rate_limit_exceeded',
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

        $this->setBlockState($identityKey, $blockSeconds, $isPermanent, $blockedUntil, $nextOffense);

        return [
            'blocked' => true,
            'retryAfterSeconds' => $isPermanent ? 0 : $blockSeconds,
            'blockedUntil' => $blockedUntil,
            'isPermanent' => $isPermanent,
            'reason' => $isPermanent ? 'permanent_firewall_ban' : 'rate_limit_exceeded',
        ];
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
        if ($blockedUntil <= time()) {
            return [];
        }

        return [
            'blocked' => true,
            'retryAfterSeconds' => $blockedUntil - time(),
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
}

function enforce_download_rate_limit(string $routeName, string $filePath = ''): array {
    $limiter = new DownloadRateLimiter();
    return $limiter->enforce($routeName, $filePath);
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
