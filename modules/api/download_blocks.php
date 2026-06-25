<?php
/**
 * Download Blocks API Module
 * Exposes rate-limit incidents for monitoring and external notifications.
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

function handleDownloadBlocksApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'error' => 'Method not allowed',
            'allowed_methods' => ['GET']
        ];
    }

    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $scopeRaw = strtolower((string)($pathParts[0] ?? 'active'));
        $scope = normalizeDownloadBlocksScope($scopeRaw);
        if ($scope === null) {
            return [
                'error' => 'Invalid scope. Use one of: all, active, previous',
                'provided_scope' => $scopeRaw
            ];
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);

        $identityTypeRaw = strtolower(trim((string)($_GET['identityType'] ?? '')));
        $userIdFilter = trim((string)($_GET['userId'] ?? ''));
        $ipAddressFilter = trim((string)($_GET['ipAddress'] ?? ''));

        $identityTypeFilter = '';
        if ($identityTypeRaw !== '') {
            if (!in_array($identityTypeRaw, ['ip', 'user'], true)) {
                return [
                    'error' => 'Invalid identityType. Use one of: ip, user',
                    'provided_identityType' => $identityTypeRaw
                ];
            }
            $identityTypeFilter = $identityTypeRaw;
        }

        if ($ipAddressFilter !== '' && filter_var($ipAddressFilter, FILTER_VALIDATE_IP) === false) {
            return [
                'error' => 'Invalid ipAddress format',
                'provided_ipAddress' => $ipAddressFilter
            ];
        }

        $isMysql = defined('DB_TYPE') && DB_TYPE === 'mysql';
        $nowExpr = $isMysql ? 'NOW()' : "datetime('now')";

        $whereParts = [];
        if ($scope === 'active') {
            $whereParts[] = "(is_permanent = 1 OR blocked_until > $nowExpr)";
        } elseif ($scope === 'previous') {
            $whereParts[] = "(is_permanent = 0 AND blocked_until IS NOT NULL AND blocked_until <= $nowExpr)";
        }

        $params = [];
        if ($identityTypeFilter !== '') {
            $whereParts[] = 'identity_type = :identityType';
            $params[':identityType'] = $identityTypeFilter;
        }
        if ($userIdFilter !== '') {
            $whereParts[] = 'user_id = :userId';
            $params[':userId'] = $userIdFilter;
        }
        if ($ipAddressFilter !== '') {
            $whereParts[] = 'ip_address = :ipAddress';
            $params[':ipAddress'] = $ipAddressFilter;
        }

        $whereClause = empty($whereParts) ? '1=1' : implode(' AND ', $whereParts);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rateLimitedIncidents WHERE $whereClause");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT
                id,
                identity_type,
                identity_value,
                user_id,
                ip_address,
                route_name,
                file_path,
                window_seconds,
                request_count,
                offense_level,
                block_seconds,
                blocked_until,
                is_permanent,
                action_taken,
                csf_status,
                notes,
                user_agent,
                created_at
            FROM rateLimitedIncidents
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $isPermanent = !empty($row['is_permanent']);
            $blockedUntil = $row['blocked_until'] ?? null;

            $items[] = [
                'incidentId' => (int)$row['id'],
                'offenseLevel' => (int)$row['offense_level'],
                'windowCount' => (int)$row['request_count'] . ' requests / ' . (int)$row['window_seconds'] . 's',
                'identityType' => (string)$row['identity_type'],
                'identityValue' => (string)$row['identity_value'],
                'ipAddress' => (string)$row['ip_address'],
                'userId' => (string)$row['user_id'],
                'route' => (string)$row['route_name'],
                'file' => (string)$row['file_path'],
                'blockDuration' => $isPermanent ? 'Permanent' : formatDownloadBlocksDuration((int)$row['block_seconds']),
                'blockedUntil' => $blockedUntil,
                'isPermanent' => $isPermanent,
                'csfStatus' => (string)$row['csf_status'],
                'actionTaken' => (string)$row['action_taken'],
                'notes' => $row['notes'],
                'userAgent' => (string)$row['user_agent'],
                'createdAt' => (string)$row['created_at']
            ];
        }

        return [
            'success' => true,
            'scope' => $scope,
            'count' => count($items),
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'filters' => [
                'identityType' => $identityTypeFilter === '' ? null : $identityTypeFilter,
                'userId' => $userIdFilter === '' ? null : $userIdFilter,
                'ipAddress' => $ipAddressFilter === '' ? null : $ipAddressFilter,
            ],
            'generated_at' => gmdate('c'),
            'items' => $items,
        ];
    } catch (Exception $e) {
        error_log('Download Blocks API Error: ' . $e->getMessage());
        return [
            'error' => 'Internal server error',
            'message' => 'Failed to retrieve download block incidents'
        ];
    }
}

function normalizeDownloadBlocksScope($scope) {
    $allowed = ['all', 'active', 'previous'];
    return in_array($scope, $allowed, true) ? $scope : null;
}

function formatDownloadBlocksDuration($seconds) {
    $seconds = (int)$seconds;
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
