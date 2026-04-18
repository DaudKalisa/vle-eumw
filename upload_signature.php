<?php
/**
 * Handle Signature Upload for Users
 * Accepts signature file uploads from profile pages
 */

require_once 'includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$user = getCurrentUser();
$type = $_POST['type'] ?? '';

// Validate file upload
if (!isset($_FILES['signature']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['signature'];

// Validate file type
$allowed_types = ['image/png', 'image/jpeg'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only PNG and JPG are allowed.']);
    exit;
}

// Validate file size (2MB max)
if ($file['size'] > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File size exceeds 2MB limit']);
    exit;
}

// Create upload directory if needed
$upload_dir = 'uploads/signatures';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

// Determine signature filename based on type
$filename = '';
switch ($type) {
    case 'dean':
        $filename = 'dean_' . $user['user_id'] . '.png';
        break;
    case 'finance':
        $filename = 'finance_' . $user['user_id'] . '.png';
        break;
    case 'coordinator':
        $filename = 'coordinator_' . $user['user_id'] . '.png';
        break;
    default:
        $filename = 'sig_' . $user['user_id'] . '_' . time() . '.png';
}

$filepath = $upload_dir . '/' . $filename;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    // Set proper permissions
    chmod($filepath, 0644);
    
    echo json_encode([
        'success' => true,
        'message' => 'Signature uploaded successfully',
        'filepath' => $filepath
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save signature file']);
}
?>
