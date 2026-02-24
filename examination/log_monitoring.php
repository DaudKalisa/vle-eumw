<?php
/**
 * Log Monitoring Event - AJAX Endpoint
 * Records tab changes, violations, copy attempts, etc.
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$session_id = (int)($input['session_id'] ?? 0);
$event_type = $input['event_type'] ?? '';
$event_data = $input['event_data'] ?? null;

$valid_events = ['camera_snapshot', 'tab_change', 'window_blur', 'window_focus', 'fullscreen_exit', 'copy_attempt', 'right_click', 'violation'];

if (!$session_id || !in_array($event_type, $valid_events)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Verify session
$stmt = $conn->prepare("SELECT session_id FROM exam_sessions WHERE session_id = ? AND student_id = ?");
$stmt->bind_param("is", $session_id, $student_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$data_json = $event_data ? json_encode($event_data) : null;

$stmt = $conn->prepare("INSERT INTO exam_monitoring (session_id, event_type, event_data, ip_address) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $session_id, $event_type, $data_json, $ip);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Log failed']);
}
