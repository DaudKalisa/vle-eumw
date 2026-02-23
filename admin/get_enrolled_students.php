<?php
// get_enrolled_students.php - Get list of enrolled students for a course
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

header('Content-Type: application/json');

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if ($course_id <= 0) {
    echo json_encode(['error' => 'Invalid course ID']);
    exit;
}

$conn = getDbConnection();

$stmt = $conn->prepare("
    SELECT 
        ve.enrollment_id,
        ve.student_id,
        ve.enrollment_date,
        s.full_name,
        s.program,
        s.year_of_study
    FROM vle_enrollments ve
    INNER JOIN students s ON ve.student_id = s.student_id
    WHERE ve.course_id = ?
    ORDER BY s.full_name
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = [
        'enrollment_id' => $row['enrollment_id'],
        'student_id' => $row['student_id'],
        'full_name' => $row['full_name'],
        'program' => $row['program'],
        'year_of_study' => $row['year_of_study'],
        'enrollment_date' => date('Y-m-d', strtotime($row['enrollment_date']))
    ];
}

$stmt->close();
$conn->close();

echo json_encode(['students' => $students]);
?>
