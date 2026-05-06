<?php
/**
 * API: Fetch OTA Hashes
 * Fetches file hashes from Evolution-X OTA repository
 * Auto-stores hashes in database for future use
 * Called asynchronously by frontend
 */

require_once __DIR__ . '/../modules/setup/config.php';
require_once __DIR__ . '/../modules/setup/database.php';

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
    
    try {
    // Fetch device.json from GitHub Evolution-X/OTA repository
    // Try multiple branches (bka, vic, udc) for compatibility
    $branches = ['bka', 'vic', 'udc'];
    $device_data = null;
    $last_error = null;
    
    foreach ($branches as $branch) {
            $url = "https://raw.githubusercontent.com/Evolution-X/OTA/refs/heads/$branch/builds/$device_name.json";
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'PHP-FileBrowser/1.0'
                ],
                'https' => [
                    'timeout' => 5,
                    'user_agent' => 'PHP-FileBrowser/1.0'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response !== false) {
                $device_data = json_decode($response, true);
                if ($device_data && isset($device_data['response'])) {
                    // Successfully fetched valid OTA data
                    break;
                }
            }
            $last_error = $url;
        }
    if (!$device_data || !isset($device_data['response'])) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Unable to fetch hashes from OTA repository',
            'device' => $device_name,
            'ota_url' => "https://github.com/Evolution-X/OTA"
        ]);
        exit;
    }
    
    // Search for matching file in releases
    $found_hashes = null;
    
    foreach ($device_data['response'] as $release) {
        if (isset($release['filename']) && $release['filename'] === $file_name) {
            $found_hashes = [
                'md5' => $release['md5'] ?? null,
                'sha256' => $release['sha256'] ?? null,
            ];
            break;
        }
    }
    
    if (!$found_hashes) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found in OTA device data']);
        exit;
    }
    
    // Store hashes in database for future use.
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $file_path_db = ltrim($file_path, '/');
        $full_path = BASE_PATH . '/' . $file_path_db;
        $file_size = is_file($full_path) ? filesize($full_path) : null;

        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $stmt = $pdo->prepare('
                INSERT INTO download_stat (`key`, count, md5, sha256, file_size)
                VALUES (?, 0, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    md5 = VALUES(md5),
                    sha256 = VALUES(sha256),
                    file_size = COALESCE(VALUES(file_size), file_size)
            ');
            $stmt->execute([
                $file_path_db,
                $found_hashes['md5'],
                $found_hashes['sha256'],
                $file_size
            ]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO download_stat (key_path, count, md5, sha256, file_size)
                VALUES (?, 0, ?, ?, ?)
                ON CONFLICT(key_path) DO UPDATE SET
                    md5 = excluded.md5,
                    sha256 = excluded.sha256,
                    file_size = COALESCE(excluded.file_size, download_stat.file_size)
            ');
            $stmt->execute([
                $file_path_db,
                $found_hashes['md5'],
                $found_hashes['sha256'],
                $file_size
            ]);
        }

        error_log("Stored hashes for $file_path_db from OTA");
    } catch (Exception $e) {
        error_log("Failed to store hashes in database: " . $e->getMessage());
        // Don't fail the request, still return the hashes
    }
        
        // Return success with hashes
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'hashes' => $found_hashes,
            'source' => 'ota'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}
?>
