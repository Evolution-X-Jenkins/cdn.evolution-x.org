<?php
/**
 * API: Fetch OTA Hashes
 * Fetches file hashes from Evolution-X OTA repository
 * Auto-stores hashes in database for future use
 * Called asynchronously by frontend
 */

require_once __DIR__ . '/../modules/setup/config.php';
require_once __DIR__ . '/../modules/setup/database.php';
require_once __DIR__ . '/../modules/setup/bunny_storage.php';
require_once __DIR__ . '/../modules/core/manifest_index.php';
require_once __DIR__ . '/../modules/core/ota_hashes.php';

function handleFetchOTAHashes() {
    header('Content-Type: application/json');
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['file_path']) || !isset($input['device_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'file_path and device_name parameters required']);
        exit;
    }
    
    $file_path = $input['file_path'];
    $device_name = $input['device_name'];
    $file_name = basename($file_path);
    $normalized_file_path = ltrim((string)$file_path, '/');
    $manifestModifiedAt = shouldValidateHashAgainstManifest($normalized_file_path)
        ? getManifestModifiedAtForFile($normalized_file_path)
        : null;

    $storedHashes = fetchStoredHashesForFile($normalized_file_path, $manifestModifiedAt);
    if ($storedHashes !== null) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'hashes' => $storedHashes,
            'source' => 'database'
        ]);
        exit;
    }
    
    try {
        $otaRelease = findOtaReleaseForFile($device_name, $file_name);
        if ($otaRelease !== null) {
            $found_hashes = [
                'md5' => $otaRelease['md5'] ?? null,
                'sha256' => $otaRelease['sha256'] ?? null,
            ];
            $isZipFile = strtolower((string)pathinfo($normalized_file_path, PATHINFO_EXTENSION)) === 'zip';
            storeFetchedHashes(
                $normalized_file_path,
                $found_hashes,
                null,
                $isZipFile ? null : $manifestModifiedAt,
                $isZipFile ? ($otaRelease['timestamp'] ?? null) : null
            );

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'hashes' => $found_hashes,
                'source' => 'ota'
            ]);
            exit;
        }

        $computedHashes = computeBunnyHashesForFile($normalized_file_path);
        if ($computedHashes !== null) {
            storeFetchedHashes($normalized_file_path, $computedHashes['hashes'], $computedHashes['file_size'], $manifestModifiedAt);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'hashes' => $computedHashes['hashes'],
                'source' => 'bunny'
            ]);
            exit;
        }

        http_response_code(404);
        echo json_encode([
            'error' => 'Unable to resolve hashes for file',
            'device' => $device_name,
            'file' => $file_name,
            'ota_url' => 'https://github.com/Evolution-X/OTA'
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}

function fetchStoredHashesForFile($normalizedFilePath, $manifestModifiedAt = null) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $keyColumn = (defined('DB_TYPE') && DB_TYPE === 'mysql') ? '`key`' : 'key_path';
        $stmt = $pdo->prepare('SELECT md5, sha256, hash_source_modified_at FROM download_stat WHERE ' . $keyColumn . ' = ? LIMIT 1');
        $stmt->execute([$normalizedFilePath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $md5 = $row['md5'] ?? null;
        $sha256 = $row['sha256'] ?? null;
        if (empty($md5) && empty($sha256)) {
            return null;
        }

        if (shouldValidateHashAgainstManifest($normalizedFilePath) && $manifestModifiedAt !== null && $manifestModifiedAt > 0) {
            $storedModifiedAt = isset($row['hash_source_modified_at']) ? (int)$row['hash_source_modified_at'] : 0;
            if ($storedModifiedAt !== (int)$manifestModifiedAt) {
                return null;
            }
        }

        return [
            'md5' => $md5 ?: null,
            'sha256' => $sha256 ?: null,
        ];
    } catch (Exception $e) {
        error_log('Failed to load stored hashes for ' . $normalizedFilePath . ': ' . $e->getMessage());
        return null;
    }
}

function computeBunnyHashesForFile($normalizedFilePath) {
    $normalizedPath = bunny_normalize_relative_path('/' . ltrim((string)$normalizedFilePath, '/'));
    if ($normalizedPath === '/') {
        return null;
    }

    set_time_limit(0);

    $zone = trim((string)BUNNY_STORAGE_ZONE);
    $accessKey = trim((string)BUNNY_STORAGE_ACCESS_KEY);
    if ($zone === '' || $accessKey === '' || $zone === 'your-storage-zone' || $accessKey === 'your-bunny-storage-access-key') {
        return null;
    }

    $relativeSegments = array_values(array_filter(explode('/', trim($normalizedPath, '/')), 'strlen'));
    $encodedPath = implode('/', array_map('rawurlencode', $relativeSegments));
    $baseUrl = \Bunny\Storage\Region::getBaseUrl(bunny_normalize_region(BUNNY_STORAGE_REGION));
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($zone) . '/' . $encodedPath;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "AccessKey: {$accessKey}\r\n",
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);

    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        error_log('Failed to open Bunny object stream for hashing: ' . $normalizedPath);
        return null;
    }

    $md5Context = hash_init('md5');
    $sha256Context = hash_init('sha256');
    $fileSize = 0;

    while (!feof($stream)) {
        $chunk = fread($stream, 1024 * 1024 * 4);
        if ($chunk === false) {
            fclose($stream);
            throw new RuntimeException('Failed while reading Bunny object stream for ' . $normalizedPath);
        }

        if ($chunk === '') {
            continue;
        }

        $fileSize += strlen($chunk);
        hash_update($md5Context, $chunk);
        hash_update($sha256Context, $chunk);
    }

    fclose($stream);

    return [
        'hashes' => [
            'md5' => hash_final($md5Context),
            'sha256' => hash_final($sha256Context),
        ],
        'file_size' => $fileSize,
    ];
}

function storeFetchedHashes(
    $normalizedFilePath,
    array $hashes,
    $fileSize = null,
    $manifestModifiedAt = null,
    $otaJsonTimestamp = null
) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        if ($fileSize === null) {
            $fullPath = BASE_PATH . '/' . $normalizedFilePath;
            $fileSize = is_file($fullPath) ? filesize($fullPath) : null;
        }

        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare('
                INSERT INTO download_stat (`key`, count, md5, sha256, file_size, hash_source_modified_at, ota_json_timestamp)
                VALUES (?, 0, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    md5 = VALUES(md5),
                    sha256 = VALUES(sha256),
                    file_size = COALESCE(VALUES(file_size), file_size),
                    hash_source_modified_at = COALESCE(VALUES(hash_source_modified_at), hash_source_modified_at),
                    ota_json_timestamp = COALESCE(VALUES(ota_json_timestamp), ota_json_timestamp)
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO download_stat (key_path, count, md5, sha256, file_size, hash_source_modified_at, ota_json_timestamp)
                VALUES (?, 0, ?, ?, ?, ?, ?)
                ON CONFLICT(key_path) DO UPDATE SET
                    md5 = excluded.md5,
                    sha256 = excluded.sha256,
                    file_size = COALESCE(excluded.file_size, download_stat.file_size),
                    hash_source_modified_at = COALESCE(excluded.hash_source_modified_at, download_stat.hash_source_modified_at),
                    ota_json_timestamp = COALESCE(excluded.ota_json_timestamp, download_stat.ota_json_timestamp)
            ');
        }

        $stmt->execute([
            $normalizedFilePath,
            $hashes['md5'] ?? null,
            $hashes['sha256'] ?? null,
            $fileSize,
            $manifestModifiedAt,
            $otaJsonTimestamp
        ]);

        error_log('Stored hashes for ' . $normalizedFilePath);
    } catch (Exception $e) {
        error_log('Failed to store hashes in database: ' . $e->getMessage());
    }
}

function shouldValidateHashAgainstManifest($normalizedFilePath) {
    return strtolower((string)pathinfo($normalizedFilePath, PATHINFO_EXTENSION)) === 'img';
}

function getManifestModifiedAtForFile($normalizedFilePath) {
    try {
        $db = Database::getInstance();
        $lookupMeta = [];
        $entry = listing_lookup_path_metadata('/' . ltrim((string)$normalizedFilePath, '/'), $db->getConnection(), $lookupMeta);
        if (!is_array($entry) || !empty($entry['is_dir'])) {
            return null;
        }

        $modifiedAt = (int)($entry['modified_at'] ?? 0);
        return $modifiedAt > 0 ? $modifiedAt : null;
    } catch (Exception $e) {
        error_log('Failed to load manifest modified_at for ' . $normalizedFilePath . ': ' . $e->getMessage());
        return null;
    }
}
?>
