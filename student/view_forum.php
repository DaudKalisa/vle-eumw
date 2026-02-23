<?php
// view_forum.php - View course forums for students
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = $_SESSION['vle_related_id'];

// Verify student is enrolled
$stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
$stmt->bind_param("is", $course_id, $student_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

// Get course info
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ?");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();

// Get forums
$forums = [];
$result = $conn->query("SELECT * FROM vle_forums WHERE course_id = $course_id ORDER BY created_date DESC");
while ($row = $result->fetch_assoc()) {
    $forums[] = $row;
}

$user = getCurrentUser();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Forums - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header">
                <h4>Forums for <?php echo htmlspecialchars($course['course_name']); ?></h4>
                <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">Back to Course</a>
            </div>
            <div class="card-body">
                <?php if (empty($forums)): ?>
                    <p class="text-muted">No forums available for this course.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($forums as $forum): ?>
                            <a href="../lecturer/view_forum.php?forum_id=<?php echo $forum['forum_id']; ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1"><?php echo htmlspecialchars($forum['title']); ?></h5>
                                    <small><?php echo $forum['week_number'] ? "Week {$forum['week_number']}" : 'General'; ?></small>
                                </div>
                                <p class="mb-1"><?php echo htmlspecialchars($forum['description']); ?></p>
                                <small>Created: <?php echo date('Y-m-d', strtotime($forum['created_date'])); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>