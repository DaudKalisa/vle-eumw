<?php
/**
 * Update Session - AJAX Endpoint
 * Keeps session alive and updates time remaining
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$session_id = (int)($input['session_id'] ?? 0);
$time_remaining = (int)($input['time_remaining'] ?? 0);

if (!$session_id) {
    echo json_encode(['success' => false, 'message' => 'Missing session ID']);
    exit;
}

$stmt = $conn->prepare("UPDATE exam_sessions SET time_remaining = ? WHERE session_id = ? AND student_id = ? AND status = 'in_progress'");
$stmt->bind_param("iis", $time_remaining, $session_id, $student_id);

if ($stmt->execute() && $stmt->affected_rows >= 0) {
    echo json_encode(['success' => true, 'time_remaining' => $time_remaining]);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}
