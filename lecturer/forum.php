<?php
// forum.php - Course forums for lecturers
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Get forums for this course
$forums = [];
$result = $conn->query("SELECT * FROM vle_forums WHERE course_id = $course_id ORDER BY created_date DESC");
while ($row = $result->fetch_assoc()) {
    $forums[] = $row;
}

// Handle new forum creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_forum'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $week_number = (int)$_POST['week_number'];

    if (!empty($title)) {
        $stmt = $conn->prepare("INSERT INTO vle_forums (course_id, week_number, title, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $course_id, $week_number, $title, $description);
        $stmt->execute();
        header("Location: forum.php?course_id=$course_id");
        exit();
    }
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
                <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">Back to Dashboard</a>
            </div>
            <div class="card-body">
                <!-- Create New Forum -->
                <div class="mb-4">
                    <h5>Create New Forum</h5>
                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="title" placeholder="Forum Title" required>
                        </div>
                        <div class="col-md-3">
                            <input type="number" class="form-control" name="week_number" placeholder="Week (optional)" min="0">
                        </div>
                        <div class="col-md-9">
                            <textarea class="form-control" name="description" rows="2" placeholder="Forum Description"></textarea>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="create_forum" class="btn btn-primary">Create Forum</button>
                        </div>
                    </form>
                </div>

                <!-- Existing Forums -->
                <h5>Existing Forums</h5>
                <?php if (empty($forums)): ?>
                    <p class="text-muted">No forums created yet.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($forums as $forum): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">
                                        <a href="view_forum.php?forum_id=<?php echo $forum['forum_id']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($forum['title']); ?>
                                        </a>
                                    </h5>
                                    <small><?php echo $forum['week_number'] ? "Week {$forum['week_number']}" : 'General'; ?></small>
                                </div>
                                <p class="mb-1"><?php echo htmlspecialchars($forum['description']); ?></p>
                                <small>Created: <?php echo date('Y-m-d', strtotime($forum['created_date'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>