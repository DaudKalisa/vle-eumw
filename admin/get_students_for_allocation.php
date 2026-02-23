<?php
// get_students_for_allocation.php - Fetch students for course allocation
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

header('Content-Type: application/json');

$conn = getDbConnection();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Get all active students with enrollment status for the specific course
$query = "SELECT s.student_id, s.full_name, s.program, s.department, 
                 s.year_of_study, s.semester,
                 CASE WHEN e.enrollment_id IS NOT NULL THEN 1 ELSE 0 END as is_enrolled
          FROM students s
          LEFT JOIN vle_enrollments e ON s.student_id = e.student_id AND e.course_id = ?
          WHERE s.is_active = TRUE
          ORDER BY s.full_name";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = [
        'student_id' => $row['student_id'],
        'full_name' => $row['full_name'],
        'program' => $row['program'],
        'department' => $row['department'],
        'year_of_study' => $row['year_of_study'],
        'semester' => $row['semester'],
        'is_enrolled' => (bool)$row['is_enrolled']
    ];
}

$stmt->close();
$conn->close();

echo json_encode(['students' => $students]);
?>
