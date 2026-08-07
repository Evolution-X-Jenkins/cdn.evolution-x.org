<?php
/**
 * Health Status Handler
 * Shows system status including Jenkins, R2, and other services
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/manifest_index.php';
require_once __DIR__ . '/../setup/database.php';
require_once __DIR__ . '/rate_limit.php';

function show_health_page() {
    // Check various system components
    $health_checks = [
        'local_identifiers' => check_local_identifiers(),
        'r2' => check_r2_status(),
        'jenkins' => check_jenkins_status(),
        'bucket' => check_bucket_status(),
        'push_queue' => check_push_queue_status()
    ];
    
    // Overall system status
    $overall_status = 'healthy';
    foreach ($health_checks as $check) {
        if ($check['status'] === 'error') {
            $overall_status = 'error';
            break;
        } elseif ($check['status'] === 'warning' && $overall_status === 'healthy') {
            $overall_status = 'warning';
        }
    }
    
    include 'templates/health.php';
}

function check_local_identifiers() {
    $ipAddress = (string)(get_client_ip() ?? 'unknown');
    $userId = (string)(get_user_id() ?? 'unknown');
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($userAgent === '') {
        $userAgent = 'unknown';
    }

    $status = 'healthy';
    $message = 'Request identity and download metadata';
    $restrictionLabel = 'None';
    $timeRemaining = '0 seconds';
    $allowedAgain = 'Now';
    $ruleTriggeredBy = 'n/a';

    try {
        $rateLimitState = get_download_rate_limit_status();
        $isBlocked = !empty($rateLimitState['blocked']);
        $isPermanent = !empty($rateLimitState['isPermanent']);
        $retryAfterSeconds = max(0, (int)($rateLimitState['retryAfterSeconds'] ?? 0));
        $blockedUntil = $rateLimitState['blockedUntil'] ?? null;
        $identityType = $rateLimitState['identityType'] ?? null;

        if ($isPermanent) {
            $status = 'error';
            $message = 'Downloads are permanently restricted';
            $restrictionLabel = 'Permanent ban';
            $timeRemaining = 'Permanent';
            $allowedAgain = 'Never';
            $ruleTriggeredBy = $identityType === 'user' ? 'userID' : 'IP';
        } elseif ($isBlocked) {
            $status = 'warning';
            $message = 'Downloads are temporarily restricted';
            $restrictionLabel = 'Temporary block';
            $timeRemaining = format_duration_for_health($retryAfterSeconds);
            $allowedAgain = $blockedUntil ?: 'unknown';
            $ruleTriggeredBy = $identityType === 'user' ? 'userID' : 'IP';
        }
    } catch (Exception $e) {
        $status = 'warning';
        $message = 'Download restriction status unavailable';
        $restrictionLabel = 'Unknown';
        $timeRemaining = 'Unknown';
        $allowedAgain = 'Unknown';
    }

    return [
        'name' => 'Local Debug Info',
        'status' => $status,
        'message' => $message,
        'details' => [
            'IP' => $ipAddress,
            'userID' => $userId,
            'UserAgent' => $userAgent,
            'Download Restrictions' => $restrictionLabel,
            'Time Remaining' => $timeRemaining,
            'Allowed Again' => $allowedAgain,
            'Rule Triggered By' => $ruleTriggeredBy
        ]
    ];
}

function format_duration_for_health(int $seconds): string {
    if ($seconds <= 0) {
        return 'n/a';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }
    if ($remainingSeconds > 0 || empty($parts)) {
        $parts[] = $remainingSeconds . 's';
    }

    return implode(' ', $parts);
}

function format_time_ago_for_health($epoch): string {
    $ts = listing_parse_epoch($epoch);
    if ($ts <= 0) {
        return 'unknown';
    }

    $now = time();
    $delta = $now - $ts;

    if ($delta < 0) {
        $delta = abs($delta);
        if ($delta < 60) {
            return 'in ' . $delta . ' seconds';
        }
        if ($delta < 3600) {
            return 'in ' . intdiv($delta, 60) . ' minutes';
        }
        if ($delta < 86400) {
            return 'in ' . intdiv($delta, 3600) . ' hours';
        }
        return 'in ' . intdiv($delta, 86400) . ' days';
    }

    if ($delta < 60) {
        return $delta . ' seconds ago';
    }
    if ($delta < 3600) {
        return intdiv($delta, 60) . ' minutes ago';
    }
    if ($delta < 86400) {
        return intdiv($delta, 3600) . ' hours ago';
    }

    return intdiv($delta, 86400) . ' days ago';
}

function check_r2_status() {
    try {
        // Test download URL generation
        $test_file = 'test-file.txt'; // This doesn't need to exist for URL generation
        $primary_url = generate_presigned_url($test_file, false);
        $proxy_url = generate_presigned_url($test_file, true);
        
        $using_proxy = false;
        // Simple connectivity test - in real usage this would be more sophisticated
        $context = stream_context_create([
            'http' => ['timeout' => 3, 'method' => 'HEAD']
        ]);
        
        // Test primary URL connectivity
        $primary_works = @get_headers(get_r2_endpoint(), 1, $context) !== false;
        if (!$primary_works) {
            $using_proxy = true;
        }
        
        return [
            'name' => 'CloudFlare R2 Downloads',
            'status' => 'healthy',
            'message' => $using_proxy ? 'Using proxy URLs' : 'Direct URLs working',
            'details' => [
                'Connection' => $using_proxy ? 'Proxy' : 'Direct',
                'Bucket' => R2_BUCKET_NAME,
                'Fallback Available' => 'Yes'
            ]
        ];
    } catch (Exception $e) {
        return [
            'name' => 'CloudFlare R2 Downloads',
            'status' => 'error',
            'message' => 'Download URL generation failed: ' . $e->getMessage(),
            'details' => []
        ];
    }
}

function check_jenkins_status() {
    // Check if Jenkins URL is configured
    $jenkins_url = JENKINS_URL;
    
    if (!$jenkins_url || $jenkins_url === false) {
        return [
            'name' => 'Jenkins CI/CD',
            'status' => 'warning',
            'message' => 'Jenkins monitoring disabled',
            'details' => ['Note' => 'Set JENKINS_URL in config.php to enable']
        ];
    }
    
    $jenkins_api = $jenkins_url . '/api/json';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($jenkins_api, false, $context);
    
    if ($response === false) {
        return [
            'name' => 'Jenkins CI/CD',
            'status' => 'error',
            'message' => 'Jenkins not accessible',
            'details' => ['URL' => $jenkins_url]
        ];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return [
            'name' => 'Jenkins CI/CD',
            'status' => 'warning',
            'message' => 'Jenkins responding but data invalid',
            'details' => ['URL' => $jenkins_url]
        ];
    }
    
    // Check for active builds
    $building_jobs = 0;
    if (isset($data['jobs'])) {
        foreach ($data['jobs'] as $job) {
            if (isset($job['color']) && strpos($job['color'], '_anime') !== false) {
                $building_jobs++;
            }
        }
    }
    
    if ($building_jobs > 0) {
        return [
            'name' => 'Jenkins CI/CD',
            'status' => 'building',
            'message' => "Building ({$building_jobs} active jobs)",
            'details' => [
                'Active Builds' => $building_jobs
            ]
        ];
    } else {
        return [
            'name' => 'Jenkins CI/CD', 
            'status' => 'idle',
            'message' => 'Idle (no active builds)',
            'details' => []
        ];
    }
}

function check_bucket_status() {
    $manifestStatus = listing_read_status_file();
    $manifestGenerated = 0;
    $manifestState = 'Unknown';
    $manifestUpdatedDisplay = 'unknown';
    $totalFiles = null;
    $totalSize = null;
    $source = 'manifest_status';

    if (is_array($manifestStatus)) {
        $manifestGenerated = listing_parse_epoch($manifestStatus['generated_at'] ?? 0);
        $manifestState = (string)($manifestStatus['status'] ?? 'Unknown');
        $manifestUpdatedDisplay = format_time_ago_for_health($manifestGenerated);

        if (isset($manifestStatus['total_files'])) {
            $totalFiles = (int)$manifestStatus['total_files'];
        }
        if (isset($manifestStatus['total_size'])) {
            $totalSize = (int)$manifestStatus['total_size'];
        }
    }

    if ($totalFiles === null || $totalSize === null) {
        $tree = listing_load_manifest_tree();
        if (is_array($tree)) {
            $source = 'manifest_json';
            if ($manifestGenerated <= 0) {
                $manifestGenerated = listing_parse_epoch($tree['generated_at'] ?? 0);
                $manifestUpdatedDisplay = format_time_ago_for_health($manifestGenerated);
            }

            if (isset($tree['totals']) && is_array($tree['totals'])) {
                $totalFiles = isset($tree['totals']['total_files']) ? (int)$tree['totals']['total_files'] : $totalFiles;
                $totalSize = isset($tree['totals']['total_size']) ? (int)$tree['totals']['total_size'] : $totalSize;
            }

            if ($totalFiles === null || $totalSize === null) {
                $counts = listing_compute_manifest_totals_for_health($tree);
                if ($totalFiles === null) {
                    $totalFiles = $counts['total_files'];
                }
                if ($totalSize === null) {
                    $totalSize = $counts['total_size'];
                }
            }
        }
    }

    if ($totalFiles === null || $totalSize === null) {
        return [
            'name' => 'Bucket Size',
            'status' => 'warning',
            'message' => 'Manifest totals unavailable',
            'details' => [
                'Manifest Status' => $manifestState,
                'Manifest Updated' => $manifestUpdatedDisplay,
                'Source' => $source,
            ]
        ];
    }

    $status = 'healthy';
    $message = format_file_size($totalSize) . ' in ' . number_format($totalFiles) . ' files';

    if ($manifestState !== 'ok') {
        $status = 'warning';
        $message = 'Manifest state: ' . $manifestState;
    }

    if ($manifestGenerated > 0 && (time() - $manifestGenerated) > 7200 && $status === 'healthy') {
        $status = 'warning';
        $message = 'Manifest data is stale';
    }

    return [
        'name' => 'Bucket Size',
        'status' => $status,
        'message' => $message,
        'details' => [
            'Total Files' => number_format($totalFiles),
            'Total Size' => format_file_size($totalSize),
            'Manifest Status' => $manifestState,
            'Manifest Updated' => $manifestUpdatedDisplay,
            'Source' => $source,
        ]
    ];
}

function listing_compute_manifest_totals_for_health($node) {
    $totalFiles = 0;
    $totalSize = 0;

    $walker = function($entry) use (&$walker, &$totalFiles, &$totalSize) {
        if (!is_array($entry)) {
            return;
        }

        if (!empty($entry['is_dir'])) {
            $children = $entry['contents'] ?? [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    $walker($child);
                }
            }
            return;
        }

        $totalFiles++;
        $totalSize += max(0, (int)($entry['size'] ?? 0));
    };

    $walker($node);

    return [
        'total_files' => $totalFiles,
        'total_size' => $totalSize,
    ];
}
function check_push_queue_status() {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get counts by status
        $stmt = $db->query('SELECT status, COUNT(*) as count FROM push_release_queue GROUP BY status');
        $counts = [
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['count'];
            }
        }
        
        // Determine simple status
        $status = 'healthy';
        $message = 'Waiting';
        
        if ($counts['processing'] > 0) {
            $status = 'building';
            $message = 'Processing';
        } elseif ($counts['failed'] > 0) {
            $status = 'warning';
            $message = 'Failures detected';
        }
        
        return [
            'name' => 'Push Release Queue',
            'status' => $status,
            'message' => "Status: {$message}",
            'details' => [
                'Queued' => $counts['queued'],
                'Processing' => $counts['processing'],
                'Completed' => $counts['completed'],
                'Failed' => $counts['failed']
            ]
        ];
    } catch (Exception $e) {
        return [
            'name' => 'Push Release Queue',
            'status' => 'error',
            'message' => 'Queue status unavailable',
            'details' => []
        ];
    }
}?>