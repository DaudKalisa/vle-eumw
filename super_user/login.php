<?php
/**
 * Super User Login Page
 * Separate login for system administrators with file management access
 */
session_start();

// Clear cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../includes/config.php';

$error = '';
$success = '';

// Check if already logged in as super user
if (isset($_SESSION['super_user_logged_in']) && $_SESSION['super_user_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $conn = getDbConnection();
        
        // Check super_users table
        $stmt = $conn->prepare("SELECT id, username, password_hash, full_name, is_active FROM super_users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (!$user['is_active']) {
                $error = 'This account has been disabled.';
            } elseif (password_verify($password, $user['password_hash'])) {
                // Successful login
                $_SESSION['super_user_logged_in'] = true;
                $_SESSION['super_user_id'] = $user['id'];
                $_SESSION['super_user_username'] = $user['username'];
                $_SESSION['super_user_name'] = $user['full_name'];
                $_SESSION['super_user_login_time'] = time();
                
                // Update last login
                $update = $conn->prepare("UPDATE super_users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?");
                $update->bind_param('i', $user['id']);
                $update->execute();
                
                // Log the login
                error_log("Super User Login: {$user['username']} from {$_SERVER['REMOTE_ADDR']}");
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
                error_log("Super User Failed Login: $username from {$_SERVER['REMOTE_ADDR']}");
            }
        } else {
            $error = 'Invalid username or password.';
            error_log("Super User Failed Login (user not found): $username from {$_SERVER['REMOTE_ADDR']}");
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
    <title>Super User Login - System Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #e94560;
            --accent-dark: #c73a52;
            --text-light: #edf2f4;
        }
        
        * { box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
        }
        
        .login-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(233, 69, 96, 0.3);
        }
        
        .logo-icon i {
            font-size: 2.5rem;
            color: white;
        }
        
        .login-title {
            color: var(--text-light);
            font-weight: 700;
            font-size: 1.5rem;
            margin: 0;
        }
        
        .login-subtitle {
            color: rgba(255,255,255,0.5);
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .form-floating {
            margin-bottom: 15px;
        }
        
        .form-floating > .form-control {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            color: var(--text-light);
            padding: 1rem 0.75rem;
            height: auto;
        }
        
        .form-floating > .form-control:focus {
            background: rgba(255,255,255,0.12);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(233, 69, 96, 0.2);
            color: var(--text-light);
        }
        
        .form-floating > label {
            color: rgba(255,255,255,0.6);
        }
        
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            color: var(--accent);
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(233, 69, 96, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 12px 16px;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
        }
        
        .back-link a {
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s;
        }
        
        .back-link a:hover {
            color: var(--accent);
        }
        
        .security-notice {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .security-notice p {
            color: rgba(255,255,255,0.4);
            font-size: 0.75rem;
            margin: 0;
        }
        
        .security-notice i {
            color: var(--accent);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <div class="logo-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h1 class="login-title">Super User Access</h1>
                <p class="login-subtitle">System Administration Portal</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" autocomplete="off">
                <div class="form-floating">
                    <input type="text" class="form-control" id="username" name="username" placeholder="Username" required autofocus>
                    <label for="username"><i class="bi bi-person me-2"></i>Username</label>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="bi bi-key me-2"></i>Password</label>
                </div>
                
                <button type="submit" class="btn-login mt-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
            </form>
            
            <div class="back-link">
                <a href="../login.php"><i class="bi bi-arrow-left me-1"></i>Back to Main Login</a>
            </div>
            
            <div class="security-notice">
                <p><i class="bi bi-shield-check me-1"></i> This is a restricted area. All access attempts are logged.</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
