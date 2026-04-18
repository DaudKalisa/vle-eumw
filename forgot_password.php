<?php
// forgot_password.php - Auto password reset with email
require_once 'includes/config.php';
require_once 'includes/email.php';

$message = '';
$msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email)) {
        $message = 'Please enter your email address.';
        $msg_type = 'warning';
    } else {
        $conn = getDbConnection();

        // Ensure table exists with all columns
        $conn->query("CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL,
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending',
            resolved_at DATETIME NULL,
            resolved_by INT NULL,
            notes TEXT NULL,
            temp_password VARCHAR(100) NULL,
            auto_reset TINYINT(1) DEFAULT 0
        )");
        // Add columns if missing
        $cols = [];
        $cr = $conn->query("SHOW COLUMNS FROM password_reset_requests");
        if ($cr) while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
        if (!in_array('temp_password', $cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN temp_password VARCHAR(100) NULL");
        if (!in_array('auto_reset', $cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN auto_reset TINYINT(1) DEFAULT 0");

        $stmt = $conn->prepare("SELECT user_id, username, email, role FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Rate limit: max 3 requests per hour
            $rate = $conn->prepare("SELECT COUNT(*) as cnt FROM password_reset_requests WHERE user_id = ? AND requested_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $rate->bind_param("i", $user['user_id']);
            $rate->execute();
            $rate_count = $rate->get_result()->fetch_assoc()['cnt'];
            $rate->close();

            if ($rate_count >= 3) {
                $message = 'Too many reset requests. Please try again later or contact an administrator.';
                $msg_type = 'warning';
            } else {
                // Generate temporary password
                $temp_password = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 8);
                $hash = password_hash($temp_password, PASSWORD_DEFAULT);

                // Update user password
                $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
                $upd->bind_param("si", $hash, $user['user_id']);

                if ($upd->execute()) {
                    // Log the reset request
                    $ins = $conn->prepare("INSERT INTO password_reset_requests (user_id, username, email, role, status, resolved_at, notes, temp_password, auto_reset) VALUES (?, ?, ?, ?, 'resolved', NOW(), 'Auto-reset: temporary password sent via email', ?, 1)");
                    $ins->bind_param("issss", $user['user_id'], $user['username'], $user['email'], $user['role'], $temp_password);
                    $ins->execute();
                    $ins->close();

                    // Build change password URL
                    $change_url = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/change_password.php';

                    // Send email with temp password and link
                    $email_body = "
                        <h2 style='color:#4f46e5;'>Password Reset</h2>
                        <p>Hello <strong>" . htmlspecialchars($user['username']) . "</strong>,</p>
                        <p>Your password has been reset. Use the temporary password below to log in:</p>
                        <div style='background:#f0f4f8;border-radius:8px;padding:16px;margin:16px 0;text-align:center;'>
                            <p style='margin:0 0 4px;color:#64748b;font-size:13px;'>Your temporary password:</p>
                            <p style='margin:0;font-size:24px;font-weight:700;color:#1e293b;letter-spacing:2px;font-family:monospace;'>" . htmlspecialchars($temp_password) . "</p>
                        </div>
                        <p><strong>You must change your password immediately after logging in.</strong></p>
                        <p>
                            <a href='" . htmlspecialchars($change_url) . "' style='display:inline-block;background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>
                                Log In &amp; Change Password
                            </a>
                        </p>
                        <p style='color:#94a3b8;font-size:12px;margin-top:20px;'>If you did not request this reset, please contact the administrator immediately.</p>
                    ";
                    $email_sent = sendEmail($user['email'], $user['username'], 'Password Reset - VLE', $email_body);

                    if ($email_sent) {
                        $message = 'A temporary password has been sent to your email address. Please check your inbox (and spam folder) and log in to change your password.';
                        $msg_type = 'success';
                    } else {
                        $message = 'Your password has been reset but we could not send the email. Please contact an administrator for your temporary password.';
                        $msg_type = 'warning';
                    }
                } else {
                    $message = 'An error occurred. Please try again or contact the administrator.';
                    $msg_type = 'danger';
                }
                $upd->close();
            }
        } else {
            // Don't reveal whether the email exists (security)
            $message = 'If an account with that email exists, a password reset has been sent.';
            $msg_type = 'success';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .reset-card { background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.15); max-width:440px; width:100%; overflow:hidden; }
        .reset-header { background:linear-gradient(135deg,#f59e0b,#d97706); padding:2rem; text-align:center; color:#fff; }
        .reset-header i { font-size:2.5rem; margin-bottom:.5rem; }
        .reset-body { padding:2rem; }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-header">
            <i class="bi bi-key-fill d-block"></i>
            <h4 class="mb-1 fw-bold">Forgot Password?</h4>
            <p class="mb-0 opacity-75" style="font-size:.9rem;">Enter your email and we'll send you a temporary password</p>
        </div>
        <div class="reset-body">
            <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?> small" style="border-radius:10px;">
                <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle' : ($msg_type === 'danger' ? 'x-circle' : 'info-circle') ?> me-1"></i>
                <?= $message ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" name="email" required placeholder="Enter your registered email">
                    </div>
                </div>
                <button type="submit" class="btn btn-warning w-100 fw-bold py-2">
                    <i class="bi bi-send me-1"></i>Send Temporary Password
                </button>
            </form>
            <div class="text-center mt-3">
                <a href="login.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
