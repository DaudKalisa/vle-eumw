<?php
// student/forum.php - Student forum for course topics
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = $_SESSION['vle_related_id'];

// Get course info
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ?");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die('Course not found.');
}
$course = $result->fetch_assoc();

// Get forum topics for this course
$forums = [];
$result = $conn->query("SELECT * FROM vle_forums WHERE course_id = $course_id ORDER BY created_date DESC");
while ($row = $result->fetch_assoc()) {
    $forums[] = $row;
}

// Handle new post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_forum'])) {
    $forum_id = (int)$_POST['forum_id'];
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $conn->prepare("INSERT INTO vle_forum_posts (forum_id, student_id, message, posted_date) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $forum_id, $student_id, $message);
        $stmt->execute();
        header("Location: forum.php?course_id=$course_id");
        exit();
    }
}

// Get posts for each forum
$forum_posts = [];
foreach ($forums as $forum) {
    $fid = $forum['forum_id'];
    $posts = [];
    $result = $conn->query("SELECT fp.*, u.username FROM vle_forum_posts fp JOIN users u ON fp.user_id = u.user_id WHERE fp.forum_id = $fid ORDER BY post_date ASC");
    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }
    $forum_posts[$fid] = $posts;
}
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Forum - <?php echo htmlspecialchars($course['course_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #002147; position: sticky; top: 0; z-index: 1050; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center fw-bold text-white me-4" href="dashboard.php">
                <img src="../assets/img/Logo.png" alt="Logo" style="height:38px;width:auto;margin-right:10px;">
                <span>VLE-EUMW</span>
            </a>
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="courses.php">My Courses</a></li>
            </ul>
            <ul class="navbar-nav align-items-center mb-2 mb-lg-0 ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span><?php echo htmlspecialchars($user['display_name'] ?? $student_id); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
    <div class="container mt-4">
        <h2 class="mb-4"><i class="bi bi-chat-dots"></i> Course Forum: <?php echo htmlspecialchars($course['course_name']); ?></h2>
        <?php if (empty($forums)): ?>
            <div class="alert alert-info">No forum topics have been provided by the lecturer yet.</div>
        <?php else: ?>
            <?php foreach ($forums as $forum): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <strong><?php echo htmlspecialchars($forum['title']); ?></strong>
                        <span class="badge bg-light text-dark ms-2">Week <?php echo $forum['week_number']; ?></span>
                    </div>
                    <div class="card-body">
                        <p><?php echo htmlspecialchars($forum['description']); ?></p>
                        <h6 class="mt-3">Discussion:</h6>
                        <?php if (!empty($forum_posts[$forum['forum_id']])): ?>
                            <ul class="list-group mb-3">
                                <?php foreach ($forum_posts[$forum['forum_id']] as $post): ?>
                                    <li class="list-group-item">
                                        <strong><?php echo htmlspecialchars($post['username'] ?? ''); ?>:</strong>
                                        <span><?php echo htmlspecialchars($post['message'] ?? ''); ?></span>
                                        <br><small class="text-muted">Posted: <?php echo !empty($post['posted_date']) ? $post['posted_date'] : 'N/A'; ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">No posts yet. Be the first to contribute!</p>
                        <?php endif; ?>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="forum_id" value="<?php echo $forum['forum_id']; ?>">
                            <div class="mb-2">
                                <textarea name="message" class="form-control" rows="2" placeholder="Write your message..." required></textarea>
                            </div>
                            <button type="submit" name="post_forum" class="btn btn-success btn-sm">Post</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
