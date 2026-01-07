<?php
// Router for PHP development server
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Handle API routes - route all API calls to api.php
if (strpos($path, '/api/') === 0) {
    $_SERVER['PATH_INFO'] = $path;
    include_once 'api.php';
    return true;
}

// If it's a real file (like CSS, JS, images), serve it normally
if (file_exists($_SERVER['DOCUMENT_ROOT'] . $path) && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false; // Let PHP serve the file
}

// Otherwise, route everything to index.php
include_once 'index.php';
?>