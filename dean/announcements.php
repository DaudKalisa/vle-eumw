<?php
/**
 * Dean Portal - Announcements
 * Send announcements to faculty members
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$message = '';
$message_type = '';

// Create announcements table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS dean_announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    dean_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_audience ENUM('all', 'lecturers', 'students') DEFAULT 'all',
    faculty_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE NULL,
    is_active TINYINT DEFAULT 1
)");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create') {
        $title = trim($_POST['title'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        $target = $_POST['target_audience'] ?? 'all';
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        
        if ($title && $msg) {
            $stmt = $conn->prepare("INSERT INTO dean_announcements (dean_id, title, message, target_audience, expires_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user['user_id'], $title, $msg, $target, $expires);
            if ($stmt->execute()) {
                $message = "Announcement created successfully.";
                $message_type = "success";
            } else {
                $message = "Failed to create announcement.";
                $message_type = "danger";
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM dean_announcements WHERE announcement_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Announcement deleted.";
            $message_type = "success";
        }
    }
}

// Get announcements
$announcements = [];
$result = $conn->query("SELECT * FROM dean_announcements ORDER BY created_at DESC LIMIT 50");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$page_title = "Announcements";
$breadcrumbs = [['title' => 'Announcements']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Create Announcement -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-megaphone me-2"></i>New Announcement</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            <div class="mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea name="message" class="form-control" rows="4" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Target Audience</label>
                                <select name="target_audience" class="form-select">
                                    <option value="all">All Faculty Members</option>
                                    <option value="lecturers">Lecturers Only</option>
                                    <option value="students">Students Only</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Expires On (Optional)</label>
                                <input type="date" name="expires_at" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Post Announcement
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Announcements List -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Recent Announcements</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($announcements)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-megaphone fs-1 d-block mb-3"></i>
                            <p>No announcements yet</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($announcements as $ann): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?= htmlspecialchars($ann['title']) ?></h6>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($ann['message'])) ?></p>
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i><?= date('M d, Y H:i', strtotime($ann['created_at'])) ?>
                                            <span class="badge bg-<?= $ann['target_audience'] === 'all' ? 'primary' : ($ann['target_audience'] === 'lecturers' ? 'info' : 'success') ?> ms-2">
                                                <?= ucfirst($ann['target_audience']) ?>
                                            </span>
                                        </small>
                                    </div>
                                    <form method="POST" class="ms-2">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $ann['announcement_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this announcement?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
