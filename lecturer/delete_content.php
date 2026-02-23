<?php
// delete_content.php - Delete Course Content
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];
$content_id = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Verify lecturer owns the content
$stmt = $conn->prepare("SELECT c.content_id FROM vle_weekly_content c JOIN vle_courses vc ON c.course_id = vc.course_id WHERE c.content_id = ? AND vc.lecturer_id = ?");
$stmt->bind_param("is", $content_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo '<div class="alert alert-danger">Content not found or you do not have permission to delete it.</div>';
    exit();
}

// Delete content
$stmt = $conn->prepare("DELETE FROM vle_weekly_content WHERE content_id = ?");
$stmt->bind_param("i", $content_id);
if ($stmt->execute()) {
    $success = "Content deleted successfully!";
    header("Location: dashboard.php?course_id=$course_id&deleted_content=1");
    exit();
} else {
    $error = "Failed to delete content: " . $conn->error;
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Content</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-danger text-white">
                    <h4>Delete Content</h4>
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
