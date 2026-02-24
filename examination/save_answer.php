<?php
/**
 * Save Answer - AJAX Endpoint
 * Auto-saves student answers during exam
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$session_id = (int)($input['session_id'] ?? 0);
$question_id = (int)($input['question_id'] ?? 0);
$answer = $input['answer'] ?? '';

if (!$session_id || !$question_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Verify session belongs to student and is active
$stmt = $conn->prepare("SELECT session_id FROM exam_sessions WHERE session_id = ? AND student_id = ? AND status = 'in_progress'");
$stmt->bind_param("is", $session_id, $student_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}

// Upsert answer
$stmt = $conn->prepare("SELECT answer_id FROM exam_answers WHERE session_id = ? AND question_id = ?");
$stmt->bind_param("ii", $session_id, $question_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

if ($existing) {
    $stmt = $conn->prepare("UPDATE exam_answers SET answer_text = ?, answered_at = NOW() WHERE answer_id = ?");
    $stmt->bind_param("si", $answer, $existing['answer_id']);
} else {
    $stmt = $conn->prepare("INSERT INTO exam_answers (session_id, question_id, answer_text, answered_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $session_id, $question_id, $answer);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Save failed']);
}
