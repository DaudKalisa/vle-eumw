<?php
/**
 * Image Upload API for TinyMCE Editor
 * Handles image uploads from TinyMCE and returns the URL.
 */
session_start();
if (empty($_SESSION['vle_user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode(['error' => 'No file uploaded or upload error']));
}

$file = $_FILES['file'];
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid image type. Allowed: JPG, PNG, GIF, WebP, SVG']));
}

$max_size = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $max_size) {
    http_response_code(400);
    exit(json_encode(['error' => 'File too large. Maximum 5MB.']));
}

$ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
$ext = $ext_map[$mime] ?? 'png';
$filename = 'img_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;

$upload_dir = __DIR__ . '/../uploads/editor_images/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$dest = $upload_dir . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save file']));
}

echo json_encode(['location' => 'uploads/editor_images/' . $filename]);
