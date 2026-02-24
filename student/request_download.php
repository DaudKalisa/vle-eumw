<?php
// request_download.php - Student request download approval
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$content_id = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;

// Get content details
$stmt = $conn->prepare("
    SELECT vwc.*, vc.course_name, vc.course_id, vc.lecturer_id
    FROM vle_weekly_content vwc
    JOIN vle_courses vc ON vwc.course_id = vc.course_id
    WHERE vwc.content_id = ?
");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$content = $result->fetch_assoc();

// Check if student is enrolled
$stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
$stmt->bind_param("is", $content['course_id'], $student_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

// Check if already requested
$stmt = $conn->prepare("SELECT * FROM vle_download_requests WHERE content_id = ? AND student_id = ?");
$stmt->bind_param("is", $content_id, $student_id);
$stmt->execute();
$existing_request = $stmt->get_result()->fetch_assoc();

// Handle request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_request) {
    $stmt = $conn->prepare("INSERT INTO vle_download_requests (student_id, content_id, lecturer_id, file_path, file_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("siiss", $student_id, $content_id, $content['lecturer_id'], $content['file_path'], $content['file_name']);
    $stmt->execute();
    header("Location: request_download.php?content_id=$content_id");
    exit();
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Download - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h4>Request Download</h4>
                        <a href="dashboard.php?course_id=<?php echo $content['course_id']; ?>" class="btn btn-secondary btn-sm">Back to Course</a>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h5><?php echo htmlspecialchars($content['title']); ?></h5>
                            <p><?php echo htmlspecialchars($content['description']); ?></p>
                            <p><strong>Course:</strong> <?php echo htmlspecialchars($content['course_name']); ?></p>
                            <p><strong>File:</strong> <?php echo htmlspecialchars($content['file_name']); ?></p>
                        </div>

                        <?php if ($existing_request): ?>
                            <div class="alert alert-info">
                                <h6>Request Status: <?php echo ucfirst($existing_request['status']); ?></h6>
                                <p>Requested: <?php echo date('Y-m-d H:i', strtotime($existing_request['requested_at'])); ?></p>
                                <?php if ($existing_request['status'] === 'approved'): ?>
                                    <a href="../uploads/<?php echo htmlspecialchars($existing_request['file_path']); ?>" class="btn btn-success" download>Download File</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p>You need lecturer approval to download this file.</p>
                            <form method="POST">
                                <button type="submit" class="btn btn-primary">Request Download Approval</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>