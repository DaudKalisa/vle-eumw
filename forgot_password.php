<?php
// forgot_password.php - User password reset request
require_once 'includes/config.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email) {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT user_id, username, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            // Log request for admin review (insert into password_reset_requests table)
            $conn->query("CREATE TABLE IF NOT EXISTS password_reset_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                role VARCHAR(50) NOT NULL,
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) DEFAULT 'pending')");
            $insert = $conn->prepare("INSERT INTO password_reset_requests (user_id, username, email, role) VALUES (?, ?, ?, ?)");
            $insert->bind_param("isss", $user['user_id'], $user['username'], $email, $user['role']);
            $insert->execute();
            // Optionally, notify admin by email (simple mail)
            $admin_result = $conn->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
            if ($admin_row = $admin_result->fetch_assoc()) {
                $admin_email = $admin_row['email'];
                $subject = 'Password Reset Request (Admin Action Required)';
                $body = 'User "' . htmlspecialchars($user['username']) . '" (' . htmlspecialchars($email) . ', role: ' . htmlspecialchars($user['role']) . ') has requested a password reset. Please review in the admin panel.';
                @mail($admin_email, $subject, $body);
            }
            $message = 'Your password reset request has been sent to the administrator. You will be contacted if approved.';
        } else {
            $message = 'No user found with that email address.';
        }
            } else {
        $message = 'Please enter your email address.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h4>Forgot Password</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-info"> <?php echo $message; ?> </div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <button type="submit" class="btn btn-warning">Send Request</button>
                            <a href="login.php" class="btn btn-link">Back to Login</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
