<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/config.php';

if (!isLecturer()) {
    header('Location: ../access_denied.php');
    exit();
}

$lecturer_id = $_SESSION['user_id'];

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_id']) && isset($_POST['action'])) {
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    
    if ($action == 'approve') {
        $status = 'approved';
    } elseif ($action == 'reject') {
        $status = 'rejected';
    } else {
        $status = 'pending';
    }
    
    $stmt = $conn->prepare("UPDATE vle_download_requests SET status = ?, approved_at = NOW() WHERE request_id = ? AND lecturer_id = ?");
    $stmt->bind_param("sii", $status, $request_id, $lecturer_id);
    $stmt->execute();
    $stmt->close();
    
    header('Location: approve_downloads.php');
    exit();
}

// Fetch pending download requests for this lecturer's courses
$query = "
    SELECT 
        dr.request_id,
        dr.requested_at,
        dr.status,
        s.student_id,
        u.first_name,
        u.last_name,
        c.title as course_title,
        wc.title as content_title,
        wc.file_path
    FROM vle_download_requests dr
    JOIN students s ON dr.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    JOIN vle_weekly_content wc ON dr.content_id = wc.content_id
    JOIN vle_courses c ON wc.course_id = c.course_id
    WHERE dr.lecturer_id = ? AND dr.status = 'pending'
    ORDER BY dr.requested_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Download Requests - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'approve_downloads';
    $pageTitle = 'Download Requests';
    include 'header_nav.php'; 
    ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
        <div class="mb-3">
            <button class="btn btn-outline-secondary" onclick="window.history.back();"><i class="bi bi-arrow-left"></i> Back</button>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-header py-3" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h4 class="mb-0 text-white"><i class="bi bi-download me-2"></i>Approve Download Requests</h4>
            </div>
            <div class="card-body">
                <?php if (empty($requests)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>No pending download requests.
                    </div>
                <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Content</th>
                            <th>Requested At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($request['course_title']); ?></td>
                                <td><?php echo htmlspecialchars($request['content_title']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($request['requested_at'])); ?></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/global-theme.js"></script>
</body>
</html>