<?php
// get_module_count.php - Get module count for a student in a specific semester
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

header('Content-Type: application/json');

$conn = getDbConnection();

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';

if ($student_id && $semester) {
    $stmt = $conn->prepare("SELECT COUNT(*) as module_count 
                            FROM vle_enrollments e 
                            JOIN modules m ON e.course_id = m.module_id 
                            WHERE e.student_id = ? AND m.semester = ?");
    $stmt->bind_param("is", $student_id, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    echo json_encode(['count' => $row['module_count']]);
} else {
    echo json_encode(['count' => 0]);
}

$conn->close();
