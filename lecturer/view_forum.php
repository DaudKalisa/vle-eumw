    <?php include 'lecturer_navbar.php'; ?>
<?php
// view_forum.php - View forum posts
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();

$conn = getDbConnection();
$forum_id = isset($_GET['forum_id']) ? (int)$_GET['forum_id'] : 0;

// Get forum details
$stmt = $conn->prepare("
    SELECT f.*, vc.course_name, vc.course_id
    FROM vle_forums f
    JOIN vle_courses vc ON f.course_id = vc.course_id
    WHERE f.forum_id = ?
");
$stmt->bind_param("i", $forum_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$forum = $result->fetch_assoc();
$user = getCurrentUser();

// Check permissions - lecturer or enrolled student
$can_post = false;
if ($user['role'] === 'lecturer') {
    $stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
    $stmt->bind_param("ii", $forum['course_id'], $user['related_lecturer_id']);
    $stmt->execute();
    $can_post = $stmt->get_result()->num_rows > 0;
} elseif ($user['role'] === 'student') {
    $stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
    $stmt->bind_param("is", $forum['course_id'], $user['related_student_id']);
    $stmt->execute();
    $can_post = $stmt->get_result()->num_rows > 0;
}

if (!$can_post) {
    header('Location: dashboard.php');
    exit();
}

// Get posts
function getPosts($parent_id = null) {
    global $conn, $forum_id;
    $posts = [];

    $query = "SELECT p.*, u.username, u.role,
                     CASE WHEN u.role = 'student' THEN s.full_name
                          WHEN u.role = 'lecturer' THEN l.full_name
                          ELSE 'Admin' END as display_name
              FROM vle_forum_posts p
              LEFT JOIN users u ON p.user_id = u.user_id
              LEFT JOIN students s ON u.related_student_id = s.student_id
              LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
              WHERE p.forum_id = ? AND p.parent_post_id " . ($parent_id ? "= $parent_id" : "IS NULL") . "
              ORDER BY p.post_date ASC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $forum_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['replies'] = getPosts($row['post_id']);
        $posts[] = $row;
    }

    return $posts;
}

$posts = getPosts();

// Handle new post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_post'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if (!empty($content)) {
        $stmt = $conn->prepare("INSERT INTO vle_forum_posts (forum_id, parent_post_id, user_id, title, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $forum_id, $parent_id, $user['user_id'], $title, $content);
        $stmt->execute();
        
        // Send notification if this is a reply
        if ($parent_id && isEmailEnabled()) {
            // Get parent post author
            $parent_stmt = $conn->prepare("
                SELECT p.*, u.email, 
                       CASE WHEN u.role = 'student' THEN s.full_name WHEN u.role = 'lecturer' THEN l.full_name ELSE u.username END as author_name,
                       CASE WHEN u.role = 'student' THEN s.email WHEN u.role = 'lecturer' THEN l.email ELSE u.email END as author_email
                FROM vle_forum_posts p
                JOIN users u ON p.user_id = u.user_id
                LEFT JOIN students s ON u.related_student_id = s.student_id
                LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
                WHERE p.post_id = ?
            ");
            $parent_stmt->bind_param("i", $parent_id);
            $parent_stmt->execute();
            $parent_post = $parent_stmt->get_result()->fetch_assoc();
            
            if ($parent_post && $parent_post['author_email'] && $parent_post['user_id'] != $user['user_id']) {
                $replier_name = $user['display_name'] ?? $user['username'];
                sendDiscussionReplyEmail(
                    $parent_post['author_email'],
                    $parent_post['author_name'],
                    $replier_name,
                    $forum['course_name'],
                    $forum['title'],
                    $content,
                    $forum_id
                );
            }
        }
        
        header("Location: view_forum.php?forum_id=$forum_id");
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($forum['title']); ?> - VLE Forum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header">
                <h4><?php echo htmlspecialchars($forum['title']); ?></h4>
                <p class="mb-0"><?php echo htmlspecialchars($forum['description']); ?></p>
                <small>Course: <?php echo htmlspecialchars($forum['course_name']); ?></small>
                <div class="mt-2">
                    <a href="<?php echo $user['role'] === 'lecturer' ? 'forum.php' : 'dashboard.php'; ?>?course_id=<?php echo $forum['course_id']; ?>" class="btn btn-secondary btn-sm">Back</a>
                </div>
            </div>
            <div class="card-body">
                <!-- New Post Form -->
                <div class="mb-4">
                    <h5>Start a Discussion</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <input type="text" class="form-control" name="title" placeholder="Discussion Title" required>
                        </div>
                        <div class="mb-3">
                            <textarea class="form-control" name="content" rows="4" placeholder="Your message..." required></textarea>
                        </div>
                        <button type="submit" name="new_post" class="btn btn-primary">Post</button>
                    </form>
                </div>

                <!-- Posts -->
                <h5>Discussions</h5>
                <?php if (empty($posts)): ?>
                    <p class="text-muted">No discussions started yet.</p>
                <?php else: ?>
                    <div class="forum-posts">
                        <?php
                        function displayPost($post, $level = 0) {
                            $margin = $level * 40;
                            ?>
                            <div class="card mb-3" style="margin-left: <?php echo $margin; ?>px;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <h6><?php echo htmlspecialchars($post['title'] ?: 'Re: Discussion'); ?></h6>
                                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($post['post_date'])); ?></small>
                                    </div>
                                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                                    <small class="text-muted">By: <?php echo htmlspecialchars($post['display_name']); ?> (<?php echo ucfirst($post['role']); ?>)</small>

                                    <?php if ($level < 3): // Limit nesting ?>
                                        <div class="mt-3">
                                            <button class="btn btn-sm btn-outline-primary" onclick="toggleReply(<?php echo $post['post_id']; ?>)">Reply</button>
                                            <div id="reply-form-<?php echo $post['post_id']; ?>" style="display: none;" class="mt-2">
                                                <form method="POST">
                                                    <input type="hidden" name="parent_id" value="<?php echo $post['post_id']; ?>">
                                                    <div class="mb-2">
                                                        <input type="text" class="form-control form-control-sm" name="title" placeholder="Reply Title (optional)">
                                                    </div>
                                                    <div class="mb-2">
                                                        <textarea class="form-control form-control-sm" name="content" rows="3" placeholder="Your reply..." required></textarea>
                                                    </div>
                                                    <button type="submit" name="new_post" class="btn btn-sm btn-primary">Reply</button>
                                                    <button type="button" class="btn btn-sm btn-secondary" onclick="toggleReply(<?php echo $post['post_id']; ?>)">Cancel</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($post['replies'])): ?>
                                        <?php foreach ($post['replies'] as $reply): ?>
                                            <?php displayPost($reply, $level + 1); ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                        }

                        foreach ($posts as $post) {
                            displayPost($post);
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleReply(postId) {
            const form = document.getElementById('reply-form-' + postId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>