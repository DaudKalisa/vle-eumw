<?php
// attendance_register.php - Student Attendance Register
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get all courses the student is enrolled in
$courses = [];
$stmt = $conn->prepare("SELECT vc.course_id, vc.course_name, l.full_name AS lecturer_name
    FROM vle_enrollments ve
    JOIN vle_courses vc ON ve.course_id = vc.course_id
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    WHERE ve.student_id = ? AND vc.is_active = TRUE");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

// For each course, calculate attendance percentage
$attendance = [];
foreach ($courses as $course) {
    $course_id = $course['course_id'];
    // Total classes taught by lecturer
    $total_classes = 0;
    $attended_classes = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM vle_class_sessions WHERE course_id = ? AND is_completed = 1");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $stmt->bind_result($total_classes);
    $stmt->fetch();
    $stmt->close();
    if ($total_classes > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS attended FROM vle_attendance WHERE course_id = ? AND student_id = ? AND attended = 1");
        $stmt->bind_param("is", $course_id, $student_id);
        $stmt->execute();
        $stmt->bind_result($attended_classes);
        $stmt->fetch();
        $stmt->close();
        $percentage = round(($attended_classes / $total_classes) * 100, 1);
    } else {
        $percentage = 0;
    }
    $attendance[] = [
        'course_name' => $course['course_name'],
        'lecturer_name' => $course['lecturer_name'],
        'total_classes' => $total_classes,
        'attended_classes' => $attended_classes,
        'percentage' => $percentage
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>
    <div class="container py-5">
        <div class="row justify-content-center mb-4">
            <div class="col-lg-8 text-center">
                <h3 class="mb-4">Attendance Register</h3>
                <p class="lead">View your class attendance percentage for each course.</p>
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-lg border-0">
                    <div class="card-header bg-dark text-white text-center">
                        <h5 class="mb-0">Attendance Summary</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Course</th>
                                        <th>Lecturer</th>
                                        <th>Total Classes</th>
                                        <th>Attended</th>
                                        <th>Attendance (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['course_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['lecturer_name']); ?></td>
                                            <td><?php echo $row['total_classes']; ?></td>
                                            <td><?php echo $row['attended_classes']; ?></td>
                                            <td><span class="fw-bold <?php echo ($row['percentage'] >= 75 ? 'text-success' : ($row['percentage'] >= 50 ? 'text-warning' : 'text-danger')); ?>"><?php echo $row['percentage']; ?>%</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <a href="dashboard.php" class="btn btn-outline-secondary mt-3">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
