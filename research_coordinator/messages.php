<?php
/**
 * Research Coordinator - Messages
 * Simple messaging for coordinator communication
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$user_id = $_SESSION['vle_user_id'] ?? 0;
$message = '';
$error = '';

// Handle send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $recipient_id = (int)($_POST['recipient_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    
    if ($recipient_id && $subject && $body) {
        // Check if messages table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'messages'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iiss", $user_id, $recipient_id, $subject, $body);
            if ($stmt->execute()) {
                $message = 'Message sent successfully.';
            } else {
                $error = 'Failed to send message.';
            }
        } else {
            // Use dissertation_notifications as fallback
            $stmt = $conn->prepare("INSERT INTO dissertation_notifications (user_id, type, title, message) VALUES (?, 'message', ?, ?)");
            $stmt->bind_param("iss", $recipient_id, $subject, $body);
            if ($stmt->execute()) {
                $message = 'Notification sent successfully.';
            }
        }
    }
}

// Get dissertation notifications sent by/to coordinator
$notifications = [];
$r = $conn->query("
    SELECT dn.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name
    FROM dissertation_notifications dn
    LEFT JOIN dissertations d ON dn.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    ORDER BY dn.created_at DESC
    LIMIT 50
");
if ($r) while ($row = $r->fetch_assoc()) $notifications[] = $row;

// Get users for compose (students with dissertations + lecturers who are supervisors)
$recipients = [];
$r = $conn->query("
    SELECT DISTINCT u.user_id, u.username, u.email, u.role,
           COALESCE(s.full_name, l.full_name, u.username) as display_name
    FROM users u
    LEFT JOIN students s ON u.related_student_id = s.student_id
    LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    WHERE u.role IN ('student','lecturer') AND u.is_active = 1
    ORDER BY u.role, display_name
    LIMIT 100
");
if ($r) while ($row = $r->fetch_assoc()) $recipients[] = $row;

$page_title = 'Messages';
$breadcrumbs = [['title' => 'Messages']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row">
        <!-- Compose -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-pencil-square text-primary me-2"></i>Send Message</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="send">
                        <div class="mb-3">
                            <label class="form-label">To</label>
                            <select name="recipient_id" class="form-select" required>
                                <option value="">Select recipient...</option>
                                <optgroup label="Students">
                                    <?php foreach ($recipients as $r): if ($r['role'] === 'student'): ?>
                                    <option value="<?= $r['user_id'] ?>"><?= htmlspecialchars($r['display_name']) ?></option>
                                    <?php endif; endforeach; ?>
                                </optgroup>
                                <optgroup label="Lecturers">
                                    <?php foreach ($recipients as $r): if ($r['role'] === 'lecturer'): ?>
                                    <option value="<?= $r['user_id'] ?>"><?= htmlspecialchars($r['display_name']) ?></option>
                                    <?php endif; endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" name="subject" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="body" class="form-control" rows="5" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-2"></i>Send</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Notifications -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-bell text-warning me-2"></i>Dissertation Notifications (<?= count($notifications) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <p class="text-muted text-center py-4">No notifications yet.</p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notifications as $n): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <h6 class="mb-1"><?= htmlspecialchars($n['title']) ?></h6>
                                <small class="text-muted"><?= $n['created_at'] ? date('M j, H:i', strtotime($n['created_at'])) : '' ?></small>
                            </div>
                            <p class="mb-1 small"><?= htmlspecialchars($n['message'] ?? '') ?></p>
                            <?php if ($n['student_name']): ?>
                            <small class="text-muted">Student: <?= htmlspecialchars($n['student_name']) ?> | <?= htmlspecialchars($n['dissertation_title'] ?? '') ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
