<?php
/**
 * Publish/Unpublish Exam Results - AJAX Endpoint
 * Allows examination officer to control whether students can see their grades
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$exam_id = (int)($input['exam_id'] ?? 0);
$publish = (int)($input['publish'] ?? 0);

if (!$exam_id) {
    echo json_encode(['success' => false, 'message' => 'Missing exam ID']);
    exit();
}

$conn = getDbConnection();

// Verify exam exists
$stmt = $conn->prepare("SELECT exam_id, exam_name, exam_code FROM exams WHERE exam_id = ?");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();

if (!$exam) {
    echo json_encode(['success' => false, 'message' => 'Exam not found']);
    exit();
}

// Update results_published
$published = $publish ? 1 : 0;
$stmt = $conn->prepare("UPDATE exams SET results_published = ? WHERE exam_id = ?");
$stmt->bind_param("ii", $published, $exam_id);

if ($stmt->execute()) {
    $action = $published ? 'published' : 'unpublished';
    echo json_encode([
        'success' => true,
        'message' => "Results for {$exam['exam_code']} have been {$action} successfully.",
        'results_published' => $published
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
