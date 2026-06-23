<?php
/**
 * Secure Document Viewer API
 * Allows enrolled students to view lecture-uploaded content directly in browser
 * Supports: PDF, images, video, audio, Office documents, text files
 * URL: api/view_content.php?content_id=X&action=view|download
 */
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();

// Only students can view content
if (!isset($_SESSION['vle_related_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Not authorized']));
}

$student_id = $_SESSION['vle_related_id'];
$content_id = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

if (!$content_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing content_id']));
}

// Get content details with course info
$stmt = $conn->prepare("
    SELECT vwc.*, vc.course_id, vc.course_name, vc.lecturer_id
    FROM vle_weekly_content vwc
    JOIN vle_courses vc ON vwc.course_id = vc.course_id
    WHERE vwc.content_id = ?
");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    die(json_encode(['error' => 'Content not found']));
}

$content = $result->fetch_assoc();

// Verify student is enrolled in this course
$stmt = $conn->prepare("
    SELECT ve.* FROM vle_enrollments ve
    WHERE ve.course_id = ? AND ve.student_id = ?
");
$stmt->bind_param("is", $content['course_id'], $student_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    die(json_encode(['error' => 'Not enrolled in this course']));
}

// Log view action for progress tracking
$stmt = $conn->prepare("
    INSERT INTO vle_progress (enrollment_id, content_id, progress_type, completion_date)
    SELECT enrollment_id, ?, 'content_viewed', NOW() FROM vle_enrollments 
    WHERE course_id = ? AND student_id = ?
    ON DUPLICATE KEY UPDATE completion_date = NOW()
");
$stmt->bind_param("iis", $content_id, $content['course_id'], $student_id);
$stmt->execute();

// Handle different content types
$content_type = $content['content_type'];
$file_path = $content['file_path'];
$file_name = $content['file_name'];
$description = $content['description'];

// For 'text' type content, return JSON with description
if ($content_type === 'text') {
    header('Content-Type: application/json');
    echo json_encode([
        'type' => 'text',
        'title' => $content['title'],
        'description' => $description,
        'content_id' => $content_id
    ]);
    exit;
}

// For 'link' type content, return JSON redirect
if ($content_type === 'link') {
    header('Content-Type: application/json');
    echo json_encode([
        'type' => 'link',
        'title' => $content['title'],
        'url' => $description,
        'content_id' => $content_id
    ]);
    exit;
}

// For file-based content, check file exists
if (!$file_path) {
    http_response_code(404);
    die(json_encode(['error' => 'No file associated with this content']));
}

$full_path = '../uploads/' . $file_path;

if (!file_exists($full_path)) {
    http_response_code(404);
    die(json_encode(['error' => 'File not found on server']));
}

// Get file extension
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

// Define MIME types
$mimeTypes = [
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'aac' => 'audio/aac',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed',
    '7z' => 'application/x-7z-compressed'
];

$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Handle download action
if ($action === 'download') {
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($file_name ?: $file_path) . '"');
    header('Content-Length: ' . filesize($full_path));
    header('Cache-Control: public, must-revalidate');
    readfile($full_path);
    exit;
}

// Handle view action (default) - inline viewing
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($full_path));

// For PDFs and images, allow inline viewing
if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
    header('Content-Disposition: inline; filename="' . basename($file_name ?: $file_path) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . basename($file_name ?: $file_path) . '"');
}

readfile($full_path);
exit;
