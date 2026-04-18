<?php
/**
 * Research Coordinator - Profile
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $display_name = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $uid = $_SESSION['vle_user_id'] ?? 0;
    
    if ($display_name && $email) {
        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $display_name, $email, $uid);
        if ($stmt->execute()) $message = 'Profile updated successfully.';
    }
}

$uid = $_SESSION['vle_user_id'] ?? 0;
$user_data = null;
$r = $conn->query("SELECT * FROM users WHERE user_id = $uid");
if ($r) $user_data = $r->fetch_assoc();

$page_title = 'My Profile';
$breadcrumbs = [['title' => 'My Profile']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h4 class="mb-0"><i class="bi bi-person-circle me-2"></i>My Profile</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;margin:0 auto;color:#fff;font-size:2rem;font-weight:700;">
                            <?= strtoupper(substr($user['display_name'] ?? 'R', 0, 1)) ?>
                        </div>
                        <h5 class="mt-2 mb-0"><?= htmlspecialchars($user['display_name'] ?? '') ?></h5>
                        <span class="badge bg-primary">Research Coordinator</span>
                    </div>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Display Name</label>
                            <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($user_data['username'] ?? $user['display_name'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user_data['email'] ?? $user['email'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="Research Coordinator" readonly>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-2"></i>Update Profile</button>
                    </form>
                    
                    <hr>
                    <a href="../change_password.php" class="btn btn-outline-secondary w-100"><i class="bi bi-key me-2"></i>Change Password</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
