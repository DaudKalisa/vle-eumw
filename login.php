<?php
// Session must be started before any output
define('LOGIN_PAGE', true);
session_start();

// Clear cache to prevent login issues after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Capture any messages before output
$timeout_message = '';
$error_message = '';

if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $timeout_message = 'Your session has expired due to inactivity. Please login again.';
}

if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3c72">
    <title>VLE System – Login</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #667eea 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Background decorative elements */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 150%;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -40%;
            left: -20%;
            width: 60%;
            height: 120%;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 50%;
            pointer-events: none;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .login-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 32px 28px;
            text-align: center;
            position: relative;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
        }

        .login-logo {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 16px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }

        .login-header h4 {
            color: white;
            font-weight: 700;
            font-size: 1.4rem;
            margin-bottom: 6px;
        }

        .login-header p {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.9rem;
            margin: 0;
        }

        .login-body {
            padding: 32px 28px;
        }

        .form-label {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-control:focus {
            border-color: #1e3c72;
            box-shadow: 0 0 0 4px rgba(30, 60, 114, 0.1);
            background: white;
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .input-group-text {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #64748b;
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }

        .input-group:focus-within .input-group-text {
            border-color: #1e3c72;
            color: #1e3c72;
        }

        .btn-login {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 10px;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -8px rgba(30, 60, 114, 0.5);
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            margin-top: 24px;
        }

        .login-footer a {
            color: #1e3c72;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .login-footer a:hover {
            color: #667eea;
            text-decoration: underline;
        }

        .btn-portal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.3s ease;
            flex: 1;
        }

        .btn-portal-student {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border: none;
        }

        .btn-portal-student:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            color: white;
        }

        .btn-portal-website {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .btn-portal-website:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 14px 18px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #92400e;
        }

        .portal-links {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-body {
                padding: 24px 20px;
            }

            .login-header {
                padding: 24px 20px;
            }

            .login-logo {
                width: 80px;
                height: 80px;
            }

            .login-header h4 {
                font-size: 1.2rem;
            }

            .portal-links {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="assets/img/logo.png" alt="VLE Logo" class="login-logo">
                <h4>Virtual Learning Environment</h4>
                <p>Exploits University of Malawi</p>
            </div>

            <div class="login-body">
                <?php if ($timeout_message): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-clock-history"></i>
                        <span><?php echo htmlspecialchars($timeout_message); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login_process.php">
                    <div class="mb-3">
                        <label for="username_email" class="form-label">
                            <i class="bi bi-person me-1"></i> Username or Email
                        </label>
                        <input type="text" class="form-control" id="username_email" name="username_email" 
                               placeholder="Enter your username or email" required>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock me-1"></i> Password
                        </label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password" required>
                    </div>

                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
                    </button>
                </form>

                <div class="login-footer">
                    <a href="forgot_password.php" class="d-block mb-3">
                        <i class="bi bi-key me-1"></i> Forgot Password?
                    </a>
                    <div class="portal-links">
                        <a href="https://unisoft.idias.mw/" class="btn-portal btn-portal-student" target="_blank" rel="noopener">
                            <i class="bi bi-mortarboard"></i> Student Portal
                        </a>
                        <a href="https://www.exploitsmw.com" class="btn-portal btn-portal-website" target="_blank" rel="noopener">
                            <i class="bi bi-globe"></i> Visit Us
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
