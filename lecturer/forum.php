
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forums - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'forum';
    $pageTitle = 'Forums';
    include 'header_nav.php'; 
    ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
        <div class="mb-3">
            <button class="btn btn-outline-secondary" onclick="window.history.back();">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-header py-3" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <h4 class="mb-0 text-white"><i class="bi bi-chat-dots me-2"></i>Forums for <?php echo htmlspecialchars($course['course_name']); ?></h4>
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
    <script src="../assets/js/global-theme.js"></script>
</body>
</html>