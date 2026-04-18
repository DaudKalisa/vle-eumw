<?php
/**
 * Get File Content - AJAX endpoint for Super User File Manager
 * Returns raw file content for editing
 */
session_start();

// Check if logged in as super user
if (!isset($_SESSION['super_user_logged_in']) || $_SESSION['super_user_logged_in'] !== true) {
    http_response_code(403);
    echo 'Access denied. Please login as super user.';
    exit;
}

// Check session timeout
if (isset($_SESSION['super_user_login_time']) && (time() - $_SESSION['super_user_login_time'] > 7200)) {
    http_response_code(401);
    echo 'Session expired. Please login again.';
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
define('EDITABLE_EXTENSIONS', ['php', 'html', 'css', 'js', 'json', 'txt', 'md', 'sql', 'xml', 'htaccess', 'env', 'ini', 'yml', 'yaml']);

$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(400);
    echo 'No file path provided';
    exit;
}

// Validate path
$real_path = realpath($path);
if (!$real_path || strpos($real_path, ROOT_PATH) !== 0) {
    http_response_code(403);
    echo 'Invalid file path';
    exit;
}

// Check if file exists and is readable
if (!is_file($real_path) || !is_readable($real_path)) {
    http_response_code(404);
    echo 'File not found or not readable';
    exit;
}

// Check extension
$ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
if (!in_array($ext, EDITABLE_EXTENSIONS)) {
    http_response_code(403);
    echo 'This file type cannot be edited';
    exit;
}

// Check file size (max 5MB for super user)
if (filesize($real_path) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo 'File too large to edit (max 5MB)';
    exit;
}

// Output file content
header('Content-Type: text/plain; charset=utf-8');
echo file_get_contents($real_path);
