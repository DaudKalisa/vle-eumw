<?php
/**
 * Upload Snapshot - AJAX Endpoint
 * Receives webcam snapshots during exam for invigilation
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$session_id = (int)($input['session_id'] ?? 0);
$image_data = $input['image'] ?? '';

if (!$session_id || !$image_data) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Verify session
$stmt = $conn->prepare("SELECT session_id FROM exam_sessions WHERE session_id = ? AND student_id = ? AND status = 'in_progress'");
$stmt->bind_param("is", $session_id, $student_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

// Decode base64 image
$image_data = preg_replace('/^data:image\/\w+;base64,/', '', $image_data);
$image_data = base64_decode($image_data);

if (!$image_data) {
    echo json_encode(['success' => false, 'message' => 'Invalid image data']);
    exit;
}

// Save to disk
$upload_dir = '../uploads/exam_snapshots/' . $session_id . '/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$filename = 'snap_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
$filepath = $upload_dir . $filename;
$relative_path = 'uploads/exam_snapshots/' . $session_id . '/' . $filename;

if (file_put_contents($filepath, $image_data)) {
    // Log to monitoring table
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO exam_monitoring (session_id, event_type, snapshot_path, ip_address) VALUES (?, 'camera_snapshot', ?, ?)");
    $stmt->bind_param("iss", $session_id, $relative_path, $ip);
    $stmt->execute();
    echo json_encode(['success' => true, 'path' => $relative_path]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save snapshot']);
}
