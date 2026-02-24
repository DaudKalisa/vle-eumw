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
    <meta name="theme-color" content="#0d1b4a">
    <title>Login — Exploits University Malawi VLE</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="pictures/Logo.png">

    <style>
        :root {
            --eu-primary: #0d1b4a;
            --eu-secondary: #1b3a7b;
            --eu-accent: #e8a317;
            --eu-accent-hover: #c88b0f;
            --eu-light: #f0f4ff;
            --eu-radius: 16px;
            --eu-transition: all .35s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Full-screen background image */
        .login-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
        }
        .login-bg img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .login-bg::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(13,27,74,.25) 0%, rgba(27,58,123,.20) 50%, rgba(13,27,74,.25) 100%);
        }

        /* Glass card */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
            margin: 20px;
        }

        .login-card {
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--eu-radius);
            box-shadow: 0 25px 60px rgba(0,0,0,.3), 0 0 0 1px rgba(255,255,255,.15);
            overflow: hidden;
        }

        /* Header */
        .login-header {
            background: var(--eu-primary);
            padding: 2rem 2rem 1.8rem;
            text-align: center;
            position: relative;
        }
        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--eu-accent), #f5d76e, var(--eu-accent));
        }
        .login-logo {
            width: 80px; height: 80px;
            object-fit: contain;
            margin-bottom: 14px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,.2));
        }
        .login-header h4 {
            color: #fff;
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 4px;
        }
        .login-header p {
            color: var(--eu-accent);
            font-size: .78rem;
            margin: 0;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        /* Body */
        .login-body {
            padding: 2rem;
        }
        .form-label {
            font-weight: 600;
            color: var(--eu-primary);
            font-size: .88rem;
            margin-bottom: 6px;
        }
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: .95rem;
            transition: var(--eu-transition);
            background: rgba(248,250,252,.8);
        }
        .form-control:focus {
            border-color: var(--eu-accent);
            box-shadow: 0 0 0 4px rgba(232,163,23,.15);
            background: #fff;
        }
        .form-control::placeholder { color: #94a3b8; }

        .input-group-text {
            background: rgba(248,250,252,.8);
            border: 2px solid #e2e8f0;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: var(--eu-secondary);
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .input-group:focus-within .input-group-text {
            border-color: var(--eu-accent);
            color: var(--eu-accent);
        }

        /* Login Button */
        .btn-login-main {
            background: var(--eu-primary);
            border: none;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 50px;
            color: #fff;
            width: 100%;
            transition: var(--eu-transition);
            position: relative;
            overflow: hidden;
        }
        .btn-login-main:hover {
            background: var(--eu-secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13,27,74,.4);
            color: #fff;
        }

        /* Footer links */
        .login-footer {
            text-align: center;
            padding-top: 1.2rem;
            border-top: 1px solid #e2e8f0;
            margin-top: 1.5rem;
        }
        .login-footer a {
            color: var(--eu-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: .88rem;
            transition: var(--eu-transition);
        }
        .login-footer a:hover { color: var(--eu-accent); }

        /* Portal buttons */
        .portal-links {
            display: flex;
            gap: 10px;
            margin-top: 14px;
        }
        .btn-portal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: .82rem;
            text-decoration: none;
            transition: var(--eu-transition);
            flex: 1;
            border: none;
        }
        .btn-portal-apply {
            background: var(--eu-accent);
            color: var(--eu-primary);
        }
        .btn-portal-apply:hover {
            background: var(--eu-accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(232,163,23,.4);
            color: var(--eu-primary);
        }
        .btn-portal-website {
            background: var(--eu-primary);
            color: #fff;
        }
        .btn-portal-website:hover {
            background: var(--eu-secondary);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(13,27,74,.3);
            color: #fff;
        }

        /* Alerts */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: .88rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2, #fecaca);
            color: #991b1b;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            color: #92400e;
        }

        /* Back to home */
        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,.9);
            text-decoration: none;
            font-weight: 600;
            font-size: .88rem;
            margin-bottom: 1rem;
            padding: 8px 18px;
            border-radius: 50px;
            background: rgba(13,27,74,.5);
            backdrop-filter: blur(8px);
            transition: var(--eu-transition);
        }
        .back-home:hover {
            background: rgba(13,27,74,.7);
            color: var(--eu-accent);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-body { padding: 1.5rem; }
            .login-header { padding: 1.5rem; }
            .login-logo { width: 65px; height: 65px; }
            .login-header h4 { font-size: 1.1rem; }
            .portal-links { flex-direction: column; }
        }
    </style>
</head>

<body>
    <!-- Background Image -->
    <div class="login-bg">
        <img src="pictures/Slider-1.jpg" alt="Exploits University Campus">
    </div>

    <div class="login-wrapper">
        <a href="index.php" class="back-home"><i class="bi bi-arrow-left"></i> Back to Home</a>

        <div class="login-card">
            <div class="login-header">
                <img src="pictures/Logo.png" alt="Exploits University Logo" class="login-logo">
                <h4>Exploits University Malawi</h4>
                <p>Virtual Learning Environment</p>
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

                    <button type="submit" class="btn btn-login-main">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
                    </button>
                </form>

                <div class="login-footer">
                    <a href="forgot_password.php" class="d-block mb-3">
                        <i class="bi bi-key me-1"></i> Forgot Password?
                    </a>
                    <div class="portal-links">
                        <a href="https://apply.exploitsonline.com" class="btn-portal btn-portal-apply" target="_blank" rel="noopener">
                            <i class="bi bi-pencil-square"></i> Apply Now
                        </a>
                        <a href="https://www.exploitsmw.com" class="btn-portal btn-portal-website" target="_blank" rel="noopener">
                            <i class="bi bi-globe"></i> Visit Website
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
