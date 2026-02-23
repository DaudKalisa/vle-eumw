<?php
// change_password.php - Universal Change Password Page
require_once 'includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();

$success_message = '';
$error_message = '';

// Handle password change submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($current_password)) {
        $error_message = "Please enter your current password.";
    } elseif (empty($new_password)) {
        $error_message = "Please enter a new password.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        
        if (!password_verify($current_password, $user_data['password_hash'])) {
            $error_message = "Current password is incorrect.";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user['user_id']);
            
            if ($update_stmt->execute()) {
                $success_message = "Password changed successfully!";
                // Clear form fields
                $_POST = [];
            } else {
                $error_message = "Error updating password. Please try again.";
            }
        }
    }
}

// Determine redirect path based on role
$dashboard_path = 'dashboard.php';
switch ($user['role']) {
    case 'student':
        $dashboard_path = 'student/dashboard.php';
        break;
    case 'lecturer':
        $dashboard_path = 'lecturer/dashboard.php';
        break;
    case 'finance':
    case 'staff':
        $dashboard_path = 'admin/dashboard.php';
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .change-password-card {
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            border-radius: 15px;
        }
        .password-strength {
            height: 5px;
            border-radius: 3px;
            transition: all 0.3s;
        }
        .strength-weak { background: #dc3545; width: 33%; }
        .strength-medium { background: #ffc107; width: 66%; }
        .strength-strong { background: #28a745; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card change-password-card">
                    <div class="card-header bg-primary text-white text-center py-4">
                        <h4 class="mb-0"><i class="bi bi-key-fill"></i> Change Password</h4>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="mb-4 text-center">
                            <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 2rem; font-weight: bold;">
                                <?php echo strtoupper(substr($user['display_name'], 0, 2)); ?>
                            </div>
                            <h5 class="mt-3 mb-0"><?php echo htmlspecialchars($user['display_name']); ?></h5>
                            <small class="text-muted"><?php echo htmlspecialchars($user['email'] ?? ucfirst($user['role'])); ?></small>
                        </div>

                        <form method="POST" action="" id="changePasswordForm">
                            <div class="mb-3">
                                <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" name="current_password" id="current_password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                        <i class="bi bi-eye" id="current_password_icon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">New Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                                    <input type="password" class="form-control" name="new_password" id="new_password" required minlength="6" oninput="checkPasswordStrength()">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                        <i class="bi bi-eye" id="new_password_icon"></i>
                                    </button>
                                </div>
                                <div class="password-strength mt-2" id="strength_bar"></div>
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                        <i class="bi bi-eye" id="confirm_password_icon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-check-circle"></i> Change Password
                                </button>
                                <a href="<?php echo $dashboard_path; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-3 text-white">
                    <small>
                        <i class="bi bi-info-circle"></i> 
                        Use a strong password with a mix of letters, numbers, and symbols
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('strength_bar');
            
            // Remove all classes
            strengthBar.className = 'password-strength mt-2';
            
            if (password.length === 0) {
                return;
            }
            
            let strength = 0;
            
            // Length check
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            
            // Contains numbers
            if (/\d/.test(password)) strength++;
            
            // Contains uppercase and lowercase
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            
            // Contains special characters
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Apply strength class
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }

        // Form validation
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>
