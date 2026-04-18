<?php
/**
 * Admin - Dissertation Links Manager
 * Allows admin to create and manage dissertation links with a prescribed number of uses.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Ensure dissertation_links table exists
$conn->query("CREATE TABLE IF NOT EXISTS dissertation_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_code VARCHAR(64) NOT NULL UNIQUE,
    max_uses INT NOT NULL DEFAULT 1,
    times_used INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure dissertation_link_usages table exists
$conn->query("CREATE TABLE IF NOT EXISTS dissertation_link_usages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_link_student (link_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_link'])) {
    $max_uses = max(1, (int)($_POST['max_uses'] ?? 1));
    $link_code = bin2hex(random_bytes(8));
    $created_by = $user['user_id'];
    $stmt = $conn->prepare("INSERT INTO dissertation_links (link_code, max_uses, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $link_code, $max_uses, $created_by);
    if ($stmt->execute()) {
        $success = "Dissertation link created: <span class='link-display'>" . htmlspecialchars($link_code) . "</span> (Max uses: $max_uses)";
    } else {
        $error = "Failed to create link.";
    }
}

// Fetch all links
$links = [];
$result = $conn->query("SELECT l.*, u.username as creator FROM dissertation_links l LEFT JOIN users u ON l.created_by = u.user_id ORDER BY l.created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $links[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation Links - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>.link-display { font-family: monospace; background: #f3f4f6; padding: 2px 8px; border-radius: 6px; }</style>
</head>
<body>
<div class="container mt-4">
    <h2>Dissertation Links Manager</h2>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    <form method="post" class="mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="max_uses" class="form-label">Allowed Number of Students</label>
                <input type="number" min="1" name="max_uses" id="max_uses" class="form-control" value="1" required>
            </div>
            <div class="col-auto">
                <button type="submit" name="create_link" class="btn btn-primary">Create Dissertation Link</button>
            </div>
        </div>
    </form>
    <h4>Existing Links</h4>
    <table class="table table-bordered">
        <thead><tr><th>Link Code</th><th>Max Uses</th><th>Times Used</th><th>Created By</th><th>Created At</th></tr></thead>
        <tbody>
        <?php foreach ($links as $l): ?>
            <tr>
                <td class="link-display"><?= htmlspecialchars($l['link_code']) ?></td>
                <td><?= $l['max_uses'] ?></td>
                <td><?= $l['times_used'] ?></td>
                <td><?= htmlspecialchars($l['creator'] ?? '') ?></td>
                <td><?= $l['created_at'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
