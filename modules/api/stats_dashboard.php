<?php
/**
 * Stats Dashboard API Module
 * Provides data for /stats page widgets and charts.
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../setup/database.php';

function handleStatsDashboardApi($method, $pathParts) {
    if ($method !== 'GET') {
        return [
            'error' => 'Method not allowed',
            'allowed_methods' => ['GET']
        ];
    }

    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $breakdownTimeframeInput = strtolower(trim((string)($_GET['breakdownTimeframe'] ?? '7d')));
        $devicesTimeframeInput = strtolower(trim((string)($_GET['devicesTimeframe'] ?? '7d')));

        $breakdownTimeframe = normalizeStatsTimeframe($breakdownTimeframeInput);
        $devicesTimeframe = normalizeStatsTimeframe($devicesTimeframeInput);

        if ($breakdownTimeframe === null) {
            return [
                'error' => 'Invalid breakdown timeframe. Use one of: today, 7d, 30d, all',
                'provided' => $breakdownTimeframeInput
            ];
        }

        if ($devicesTimeframe === null) {
            return [
                'error' => 'Invalid top devices timeframe. Use one of: today, 7d, 30d, all',
                'provided' => $devicesTimeframeInput
            ];
        }

        $selectedDevice = normalizeStatsDeviceFilter((string)($_GET['device'] ?? ''));
        if ($selectedDevice === false) {
            return [
                'error' => 'Invalid device filter. Device names may only contain letters, numbers, dot, underscore, and dash.'
            ];
        }

        $topLimit = isset($_GET['topLimit']) ? (int)$_GET['topLimit'] : 25;
        $topLimit = max(1, min($topLimit, 100));

        if (!statsTableExists($pdo, 'download_stats')) {
            return [
                'success' => true,
                'has_data' => false,
                'table_available' => false,
                'message' => 'Download stats table not available in this environment.',
                'filters' => [
                    'breakdown_timeframe' => $breakdownTimeframe,
                    'devices_timeframe' => $devicesTimeframe,
                    'device' => $selectedDevice === '' ? 'all' : $selectedDevice,
                    'top_limit' => $topLimit
                ],
                'summary' => [
                    'total_downloads' => 0,
                    'since_date' => null
                ],
                'download_breakdown' => [
                    'timeframe' => $breakdownTimeframe,
                    'series' => buildEmptyBreakdownSeries($breakdownTimeframe)
                ],
                'top_devices' => [
                    'timeframe' => $devicesTimeframe,
                    'rows' => [],
                    'top_device' => null
                ],
                'devices_available' => [],
                'selected_device' => [
                    'device' => $selectedDevice === '' ? 'all' : $selectedDevice,
                    'downloads' => 0,
                    'exists' => false
                ]
            ];
        }

        $summary = buildTotalSummary($pdo);
        $breakdownSeries = buildDownloadBreakdownSeries($pdo, $breakdownTimeframe, '');
        $devicesAvailable = buildAvailableDevices($pdo);
        $topDevices = buildTopDevices($pdo, $devicesTimeframe, $selectedDevice, $topLimit);

        return [
            'success' => true,
            'has_data' => $summary['total_downloads'] > 0,
            'table_available' => true,
            'filters' => [
                'breakdown_timeframe' => $breakdownTimeframe,
                'devices_timeframe' => $devicesTimeframe,
                'device' => $selectedDevice === '' ? 'all' : $selectedDevice,
                'top_limit' => $topLimit
            ],
            'summary' => $summary,
            'download_breakdown' => [
                'timeframe' => $breakdownTimeframe,
                'series' => $breakdownSeries
            ],
            'top_devices' => [
                'timeframe' => $devicesTimeframe,
                'rows' => $topDevices['rows'],
                'top_device' => $topDevices['top_device']
            ],
            'devices_available' => $devicesAvailable,
            'selected_device' => [
                'device' => $selectedDevice === '' ? 'all' : $selectedDevice,
                'downloads' => $topDevices['selected_device_downloads'],
                'exists' => $topDevices['selected_device_exists']
            ]
        ];
    } catch (Exception $e) {
        error_log('Stats Dashboard API Error: ' . $e->getMessage());
        return [
            'error' => 'Internal server error',
            'message' => 'Failed to retrieve stats dashboard data'
        ];
    }
}

function normalizeStatsTimeframe($value) {
    $map = [
        'today' => 'today',
        '1d' => 'today',
        '7' => '7d',
        '7d' => '7d',
        'last7' => '7d',
        'last-7-days' => '7d',
        'week' => '7d',
        '30' => '30d',
        '30d' => '30d',
        'last30' => '30d',
        'last-30-days' => '30d',
        'month' => '30d',
        'all' => 'all',
        'alltime' => 'all',
        'all-time' => 'all'
    ];

    return $map[$value] ?? null;
}

function normalizeStatsDeviceFilter($rawValue) {
    $value = trim($rawValue);

    if ($value === '' || strtolower($value) === 'all' || $value === '*') {
        return '';
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
        return false;
    }

    return $value;
}

function statsTableExists($pdo, $tableName) {
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$tableName]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function buildTotalSummary($pdo) {
    $totalDownloads = 0;
    $sinceDate = null;

    $stmt = $pdo->query('SELECT COUNT(*) FROM download_stats');
    $totalDownloads = (int)$stmt->fetchColumn();

    $oldestStmt = $pdo->query('SELECT MIN(download_time) FROM download_stats');
    $oldest = $oldestStmt->fetchColumn();
    if (!empty($oldest)) {
        $sinceDate = date('Y-m-d', strtotime((string)$oldest));
    }

    return [
        'total_downloads' => $totalDownloads,
        'since_date' => $sinceDate
    ];
}

function resolveStatsDateRange($timeframe) {
    $now = new DateTime();

    switch ($timeframe) {
        case 'today':
            $start = new DateTime('today');
            return [$start->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];

        case '7d':
            $start = new DateTime('today');
            $start->modify('-6 days');
            return [$start->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];

        case '30d':
            $start = new DateTime('today');
            $start->modify('-29 days');
            return [$start->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];

        case 'all':
        default:
            return [null, null];
    }
}

function buildStatsWhereClause($startAt, $endAt, $selectedDevice = '') {
    $where = ['1=1'];
    $params = [];

    if ($startAt !== null) {
        $where[] = 'download_time >= ?';
        $params[] = $startAt;
    }

    if ($endAt !== null) {
        $where[] = 'download_time <= ?';
        $params[] = $endAt;
    }

    if ($selectedDevice !== '') {
        $where[] = '(folder = ? OR folder LIKE ?)';
        $params[] = $selectedDevice;
        $params[] = $selectedDevice . '/%';
    }

    return [
        'where' => implode(' AND ', $where),
        'params' => $params
    ];
}

function buildDownloadBreakdownSeries($pdo, $timeframe, $selectedDevice = '') {
    [$startAt, $endAt] = resolveStatsDateRange($timeframe);
    $clause = buildStatsWhereClause($startAt, $endAt, $selectedDevice);

    $stmt = $pdo->prepare('
        SELECT DATE(download_time) as download_date, COUNT(*) as downloads
        FROM download_stats
        WHERE ' . $clause['where'] . '
        GROUP BY DATE(download_time)
        ORDER BY download_date ASC
    ');
    $stmt->execute($clause['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byDate = [];
    foreach ($rows as $row) {
        $date = (string)$row['download_date'];
        $byDate[$date] = (int)$row['downloads'];
    }

    $fixedDays = ($timeframe === 'today') ? 1 : (($timeframe === '7d') ? 7 : (($timeframe === '30d') ? 30 : null));

    if ($fixedDays !== null) {
        $series = [];
        for ($i = $fixedDays - 1; $i >= 0; $i--) {
            $dateObj = new DateTime('today');
            $dateObj->modify('-' . $i . ' days');
            $date = $dateObj->format('Y-m-d');

            $series[] = [
                'date' => $date,
                'day' => $dateObj->format('D'),
                'downloads' => $byDate[$date] ?? 0
            ];
        }

        return $series;
    }

    $series = [];
    foreach ($rows as $row) {
        $date = (string)$row['download_date'];
        $dateObj = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);

        $series[] = [
            'date' => $date,
            'day' => $dateObj->format('D'),
            'downloads' => (int)$row['downloads']
        ];
    }

    return $series;
}

function buildEmptyBreakdownSeries($timeframe) {
    $fixedDays = ($timeframe === 'today') ? 1 : (($timeframe === '7d') ? 7 : (($timeframe === '30d') ? 30 : 0));
    if ($fixedDays === 0) {
        return [];
    }

    $series = [];
    for ($i = $fixedDays - 1; $i >= 0; $i--) {
        $dateObj = new DateTime('today');
        $dateObj->modify('-' . $i . ' days');

        $series[] = [
            'date' => $dateObj->format('Y-m-d'),
            'day' => $dateObj->format('D'),
            'downloads' => 0
        ];
    }

    return $series;
}

function buildAvailableDevices($pdo) {
    $stmt = $pdo->query('SELECT folder FROM download_stats');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $set = [];
    foreach ($rows as $row) {
        $device = extractDeviceFromFolder((string)($row['folder'] ?? ''));
        if ($device === 'root') {
            continue;
        }
        $set[$device] = true;
    }

    $devices = array_keys($set);
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);

    return $devices;
}

function buildTopDevices($pdo, $timeframe, $selectedDevice, $limit) {
    [$startAt, $endAt] = resolveStatsDateRange($timeframe);
    $clause = buildStatsWhereClause($startAt, $endAt, $selectedDevice);

    $stmt = $pdo->prepare('
        SELECT folder, COUNT(*) as downloads
        FROM download_stats
        WHERE ' . $clause['where'] . '
        GROUP BY folder
    ');
    $stmt->execute($clause['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deviceCounts = [];
    foreach ($rows as $row) {
        $device = extractDeviceFromFolder((string)($row['folder'] ?? ''));
        if (!isset($deviceCounts[$device])) {
            $deviceCounts[$device] = 0;
        }
        $deviceCounts[$device] += (int)$row['downloads'];
    }

    $all = [];
    foreach ($deviceCounts as $device => $downloads) {
        $all[] = [
            'device' => $device,
            'display_name' => formatDeviceName($device),
            'downloads' => $downloads
        ];
    }

    usort($all, function ($a, $b) {
        if ($a['downloads'] === $b['downloads']) {
            return strcasecmp($a['display_name'], $b['display_name']);
        }
        return $b['downloads'] <=> $a['downloads'];
    });

    $rowsLimited = array_slice($all, 0, $limit);
    $topDevice = $rowsLimited[0] ?? null;

    $selectedDownloads = 0;
    $selectedExists = false;
    if ($selectedDevice !== '') {
        foreach ($all as $row) {
            if ($row['device'] === $selectedDevice) {
                $selectedDownloads = (int)$row['downloads'];
                $selectedExists = true;
                break;
            }
        }
    }

    return [
        'rows' => $rowsLimited,
        'top_device' => $topDevice,
        'selected_device_downloads' => $selectedDownloads,
        'selected_device_exists' => $selectedExists
    ];
}

function extractDeviceFromFolder($folder) {
    $cleanFolder = trim($folder, '/');
    if ($cleanFolder === '') {
        return 'root';
    }

    $parts = explode('/', $cleanFolder);
    $device = trim((string)$parts[0]);

    return $device === '' ? 'root' : $device;
}

function formatDeviceName($device) {
    if ($device === 'root') {
        return 'Root';
    }

    $normalized = str_replace(['_', '-', '.'], ' ', trim($device));
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    return ucwords(strtolower($normalized));
}
?>
