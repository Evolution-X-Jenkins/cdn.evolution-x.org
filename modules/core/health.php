<?php
/**
 * Health Status Handler
 * Shows system status including Jenkins, R2, and other services
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/bucket_cache.php';

function show_health_page() {
    // Check various system components
    $health_checks = [
        'r2' => check_r2_status(),
        'jenkins' => check_jenkins_status(),
        'bucket' => check_bucket_status()
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
    // Use cached bucket statistics for fast loading
    $cache = new BucketCache();
    $stats = $cache->getBucketStats();
    
    return [
        'name' => 'Bucket Size',
        'status' => $stats['status'],
        'message' => $stats['message'],
        'details' => [
            'Total Files' => number_format($stats['total_files']),
            'Total Size' => format_file_size($stats['total_size']),
            'Directory' => $stats['path']
        ]
    ];
}
?>