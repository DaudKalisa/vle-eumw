<?php
// get_submission_details.php - Fetch submission details for grading
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

header('Content-Type: application/json');

$conn = getDbConnection();
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

$response = [
    'success' => false,
    'submission' => null,
    'questions' => [],
    'answers' => []
];

if (!$submission_id || !$assignment_id) {
    echo json_encode($response);
    exit();
}

// Verify lecturer has access to this submission
$user = getCurrentUser();
$stmt = $conn->prepare("
    SELECT vs.*, va.course_id, vc.lecturer_id
    FROM vle_submissions vs
    JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
    JOIN vle_courses vc ON va.course_id = vc.course_id
    WHERE vs.submission_id = ? AND vc.lecturer_id = ?
");
$stmt->bind_param("ii", $submission_id, $user['related_lecturer_id']);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    echo json_encode($response);
    exit();
}

$response['submission'] = $submission;
$response['success'] = true;

// Get assignment questions
$stmt = $conn->prepare("
    SELECT * FROM vle_assignment_questions 
    WHERE assignment_id = ? 
    ORDER BY question_id
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $response['questions'][] = $row;
}

// Get student answers
$stmt = $conn->prepare("
    SELECT * FROM vle_assignment_answers 
    WHERE assignment_id = ? AND student_id = ?
    ORDER BY question_id
");
$stmt->bind_param("is", $assignment_id, $submission['student_id']);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $response['answers'][] = $row;
}

$conn->close();

echo json_encode($response);
?>