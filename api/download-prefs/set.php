<?php
/**
 * Download Preference API
 * Handles setting user preference for download method (direct vs fallback)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['method']) || !in_array($input['method'], ['presigned', 'proxy', 'fallback'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid method. Must be presigned, proxy, or fallback']);
    exit;
}

$method = $input['method'];

// Set cookie for fallback domain preference
// This will be read by should_use_fallback_domain() function
if ($method === 'proxy' || $method === 'fallback') {
    setcookie('download_method_preference', 'fallback', time() + (24 * 60 * 60), '/'); // 24 hours
} else {
    setcookie('download_method_preference', 'direct', time() + (24 * 60 * 60), '/'); // 24 hours  
}

echo json_encode([
    'success' => true,
    'method' => $method,
    'preference_set' => $method === 'proxy' ? 'fallback' : 'direct',
    'timestamp' => date('c')
]);
?>