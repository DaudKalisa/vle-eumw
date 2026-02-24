<?php
// delete_assignment.php - Delete Assignment
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Verify lecturer owns the assignment
$stmt = $conn->prepare("SELECT a.assignment_id FROM vle_assignments a JOIN vle_courses c ON a.course_id = c.course_id WHERE a.assignment_id = ? AND c.lecturer_id = ?");
$stmt->bind_param("is", $assignment_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo '<div class="alert alert-danger">Assignment not found or you do not have permission to delete it.</div>';
    exit();
}

// Delete assignment
$stmt = $conn->prepare("DELETE FROM vle_assignment_answers WHERE assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();

$stmt = $conn->prepare("DELETE FROM vle_assignment_questions WHERE assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();

$stmt = $conn->prepare("DELETE FROM vle_submissions WHERE assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();

$stmt = $conn->prepare("DELETE FROM vle_assignments WHERE assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
if ($stmt->execute()) {
    $success = "Assignment deleted successfully!";
    header("Location: dashboard.php?course_id=$course_id&deleted=1");
    exit();
} else {
    $error = "Failed to delete assignment: " . $conn->error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Assignment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header_nav.php'; ?>
<div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-danger text-white">
                    <h4>Delete Assignment</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary">Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
