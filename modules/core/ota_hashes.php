<?php

function findOtaReleaseForFile($deviceName, $fileName) {
    static $deviceResponses = [];

    $branches = [
        'cnb',
        'bka',
        'vic',
        'udc',
        'cnb-vanilla',
        'bka-vanilla',
        'vic-vanilla',
        'udc-vanilla',
    ];

    $deviceCandidates = [];
    foreach ([$deviceName, strtolower((string)$deviceName)] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && !in_array($candidate, $deviceCandidates, true)) {
            $deviceCandidates[] = $candidate;
        }
    }

    foreach ($deviceCandidates as $deviceCandidate) {
        foreach ($branches as $branch) {
            $cacheKey = $branch . ':' . $deviceCandidate;
            if (!array_key_exists($cacheKey, $deviceResponses)) {
                $url = 'https://raw.githubusercontent.com/Evolution-X/OTA/refs/heads/' . $branch . '/builds/' . rawurlencode($deviceCandidate) . '.json';
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'user_agent' => 'PHP-FileBrowser/1.0',
                    ],
                    'https' => [
                        'timeout' => 5,
                        'user_agent' => 'PHP-FileBrowser/1.0',
                    ],
                ]);

                $response = @file_get_contents($url, false, $context);
                if ($response === false) {
                    $deviceResponses[$cacheKey] = null;
                } else {
                    $deviceData = json_decode($response, true);
                    $deviceResponses[$cacheKey] = is_array($deviceData)
                        && isset($deviceData['response'])
                        && is_array($deviceData['response'])
                        ? $deviceData['response']
                        : null;
                }
            }

            $releases = $deviceResponses[$cacheKey];
            if (!is_array($releases)) {
                continue;
            }

            foreach ($releases as $release) {
                if (!isset($release['filename']) || (string)$release['filename'] !== (string)$fileName) {
                    continue;
                }

                $timestamp = isset($release['timestamp']) ? (int)$release['timestamp'] : 0;
                return [
                    'md5' => $release['md5'] ?? null,
                    'sha256' => $release['sha256'] ?? null,
                    'timestamp' => $timestamp > 0 ? $timestamp : null,
                    'branch' => $branch,
                ];
            }
        }
    }

    return null;
}
