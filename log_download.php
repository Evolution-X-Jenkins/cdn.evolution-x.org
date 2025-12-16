<?php
/**
 * Download logging endpoint
 */

require_once 'modules/setup/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['file'])) {
    log_action('Download initiated', $input['file']);
}

// Return success
http_response_code(200);
echo json_encode(['status' => 'logged']);
?>