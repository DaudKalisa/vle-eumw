<?php
// announcements.php - Lecturer course announcements
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];
$user = getCurrentUser();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Verify lecturer owns this course
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    
    if (!empty($title) && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO vle_announcements (course_id, lecturer_id, title, content) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $course_id, $lecturer_id, $title, $content);
        
        if ($stmt->execute()) {
            $success = "Announcement posted successfully!";
            
            // Get lecturer details
            $lecturer_query = $conn->prepare("SELECT full_name FROM lecturers WHERE lecturer_id = ?");
            $lecturer_query->bind_param("i", $lecturer_id);
            $lecturer_query->execute();
            $lecturer_data = $lecturer_query->get_result()->fetch_assoc();
            
            // Get all enrolled students and send emails
            $students_query = $conn->prepare("
                SELECT s.email, s.full_name
                FROM vle_enrollments ve
                JOIN students s ON ve.student_id = s.student_id
                WHERE ve.course_id = ? AND s.email IS NOT NULL AND s.email != ''
            ");
            $students_query->bind_param("i", $course_id);
            $students_query->execute();
            $students_result = $students_query->get_result();
            
            $email_count = 0;
            while ($student = $students_result->fetch_assoc()) {
                if (sendAnnouncementEmail(
                    $student['email'],
                    $student['full_name'],
                    $lecturer_data['full_name'],
                    $course['course_name'],
                    $title,
                    $content,
                    $course_id
                )) {
                    $email_count++;
                }
            }
            
            $success .= " Emails sent to $email_count students.";
        } else {
            $error = "Failed to post announcement.";
        }
    }
}

// Get all announcements for this course
$announcements = [];
$result = $conn->query("
    SELECT a.*, l.full_name as lecturer_name
    FROM vle_announcements a
    LEFT JOIN lecturers l ON a.lecturer_id = l.lecturer_id
    WHERE a.course_id = $course_id
    ORDER BY a.created_date DESC
");
while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Announcements - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container-fluid mt-5">
        <div class="row">
            <div class="col-md-12">
                <div class="card shadow">
                    <div class="card-header bg-warning">
                        <h4><i class="bi bi-megaphone"></i> Announcements - <?php echo htmlspecialchars($course['course_name']); ?></h4>
                        <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">Back to Dashboard</a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Create Announcement Form -->
                        <div class="card mb-4 border-warning">
                            <div class="card-header bg-warning bg-opacity-25">
                                <h5><i class="bi bi-plus-circle"></i> Create New Announcement</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Title</label>
                                        <input type="text" class="form-control" id="title" name="title" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="content" class="form-label">Announcement Content</label>
                                        <textarea class="form-control" id="content" name="content" rows="5" required></textarea>
                                    </div>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> This announcement will be emailed to all enrolled students in this course.
                                    </div>
                                    <button type="submit" name="create_announcement" class="btn btn-warning">
                                        <i class="bi bi-send"></i> Post & Email Announcement
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Previous Announcements -->
                        <h5 class="mb-3">Previous Announcements</h5>
                        <?php if (empty($announcements)): ?>
                            <p class="text-muted">No announcements posted yet.</p>
                        <?php else: ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">
                                            <i class="bi bi-megaphone-fill text-warning"></i>
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            Posted by <?php echo htmlspecialchars($announcement['lecturer_name']); ?> 
                                            on <?php echo date('F j, Y g:i A', strtotime($announcement['created_date'])); ?>
                                        </small>
                                    </div>
                                    <div class="card-body">
                                        <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
