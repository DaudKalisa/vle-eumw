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
    <link rel="icon" type="image/png" href="assets/img/Logo.png">

    <style>
        :root {
            --eu-primary: #0d1b4a;
            --eu-secondary: #1b3a7b;
            --eu-accent: #e8a317;
            --eu-accent-hover: #c88b0f;
            --eu-light: #f0f4ff;
            --eu-transition: all .35s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
        }

        /* ─── Background Slideshow ─────────────── */
        .bg-slideshow {
            position: fixed;
            inset: 0;
            z-index: 0;
        }
        .bg-slideshow img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1.5s ease-in-out;
        }
        .bg-slideshow img.active { opacity: 1; }
        .bg-slideshow::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(13,27,74,.30) 0%, rgba(0,0,0,.15) 40%, rgba(13,27,74,.35) 100%);
            z-index: 1;
        }

        /* ─── Top Bar ──────────────────────────── */
        .eu-topbar {
            background: rgba(13,27,74,.85);
            backdrop-filter: blur(8px);
            color: rgba(255,255,255,.8);
            font-size: .78rem;
            padding: 6px 0;
            position: relative;
            z-index: 10;
        }
        .eu-topbar a { color: var(--eu-accent); text-decoration: none; }
        .eu-topbar a:hover { text-decoration: underline; }

        /* ─── Navbar ───────────────────────────── */
        .eu-navbar {
            background: rgba(255,255,255,.95);
            backdrop-filter: blur(12px);
            box-shadow: 0 2px 20px rgba(0,0,0,.08);
            padding: .5rem 0;
            position: relative;
            z-index: 10;
        }
        .eu-navbar .nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .eu-navbar .nav-brand img { height: 44px; width: auto; }
        .eu-navbar .nav-brand .uni-name { font-weight: 800; font-size: .95rem; color: var(--eu-primary); line-height: 1.2; }
        .eu-navbar .nav-brand .vle-label { font-size: .68rem; color: var(--eu-accent); font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; }
        .eu-navbar .nav-link-custom { font-weight: 500; color: #1f2937; padding: .4rem .8rem !important; border-radius: 8px; font-size: .9rem; transition: var(--eu-transition); text-decoration: none; }
        .eu-navbar .nav-link-custom:hover { background: var(--eu-light); color: var(--eu-secondary); }
        .eu-navbar .btn-apply { background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; padding: .45rem 1.4rem; border-radius: 50px; font-weight: 600; font-size: .85rem; transition: var(--eu-transition); }
        .eu-navbar .btn-apply:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(16,185,129,.3); color: #fff; }

        /* ─── Login Section ────────────────────── */
        .login-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            z-index: 5;
        }
        .login-container {
            width: 100%;
            max-width: 440px;
        }
        .login-card {
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,.25), 0 0 0 1px rgba(255,255,255,.3);
            overflow: hidden;
        }
        .login-header {
            background: var(--eu-primary);
            padding: 28px 28px 24px;
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
            background: linear-gradient(90deg, var(--eu-accent), #10b981, var(--eu-accent));
        }
        .login-logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 12px;
            filter: brightness(0) invert(1) drop-shadow(0 2px 8px rgba(0,0,0,.3));
        }
        .login-header h4 {
            color: #fff;
            font-weight: 800;
            font-size: 1.25rem;
            margin-bottom: 4px;
        }
        .login-header p {
            color: var(--eu-accent);
            font-size: .82rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 0;
        }
        .login-body {
            padding: 28px;
        }
        .form-label {
            font-weight: 600;
            color: #1e293b;
            font-size: .88rem;
            margin-bottom: 6px;
        }
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: .95rem;
            transition: var(--eu-transition);
            background: #f8fafc;
        }
        .form-control:focus {
            border-color: var(--eu-primary);
            box-shadow: 0 0 0 4px rgba(13,27,74,.08);
            background: #fff;
        }
        .form-control::placeholder { color: #94a3b8; }

        .btn-login {
            background: var(--eu-primary);
            border: none;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 10px;
            color: #fff;
            width: 100%;
            transition: var(--eu-transition);
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent);
            transition: left .5s ease;
        }
        .btn-login:hover {
            background: var(--eu-secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13,27,74,.35);
            color: #fff;
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:active { transform: translateY(0); }

        .login-footer {
            text-align: center;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            margin-top: 20px;
        }
        .login-footer a {
            color: var(--eu-primary);
            text-decoration: none;
            font-weight: 500;
            font-size: .88rem;
            transition: .2s;
        }
        .login-footer a:hover { color: var(--eu-accent); text-decoration: underline; }

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
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: .82rem;
            text-decoration: none;
            transition: var(--eu-transition);
            flex: 1;
        }
        .btn-portal-apply {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
        }
        .btn-portal-apply:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(16,185,129,.4); color: #fff; }
        .btn-portal-website {
            background: var(--eu-light);
            color: var(--eu-primary);
            border: 2px solid #e2e8f0;
        }
        .btn-portal-website:hover { border-color: var(--eu-primary); transform: translateY(-2px); box-shadow: 0 4px 14px rgba(13,27,74,.1); color: var(--eu-primary); }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: .88rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-danger { background: #fef2f2; color: #991b1b; }
        .alert-warning { background: #fffbeb; color: #92400e; }

        /* ─── Footer ───────────────────────────── */
        .eu-footer-mini {
            background: rgba(13,27,74,.85);
            backdrop-filter: blur(8px);
            color: rgba(255,255,255,.6);
            text-align: center;
            padding: .8rem;
            font-size: .75rem;
            position: relative;
            z-index: 10;
        }
        .eu-footer-mini a { color: var(--eu-accent); text-decoration: none; }

        @media (max-width: 480px) {
            .login-body { padding: 22px 18px; }
            .login-header { padding: 22px 18px; }
            .login-logo { width: 64px; height: 64px; }
            .login-header h4 { font-size: 1.1rem; }
            .portal-links { flex-direction: column; }
            .eu-navbar .nav-brand img { height: 36px; }
            .eu-navbar .nav-brand .uni-name { font-size: .82rem; }
        }
    </style>
</head>

<body>
    <!-- Background Slideshow -->
    <div class="bg-slideshow">
        <img src="pictures/Slider-1.jpg" alt="" class="active">
        <img src="pictures/Slider-2.png" alt="">
        <img src="pictures/Slider-3.jpg" alt="">
        <img src="pictures/Slider-4.jpg" alt="">
        <img src="pictures/Slider-5.png" alt="">
        <img src="pictures/Slider-6.jpg" alt="">
    </div>

    <!-- Top Bar -->
    <div class="eu-topbar d-none d-md-block">
        <div class="container d-flex justify-content-between align-items-center">
            <div><i class="bi bi-envelope me-1"></i> <a href="mailto:info@exploitsonline.com">info@exploitsonline.com</a> <span class="mx-2">|</span> <i class="bi bi-telephone me-1"></i> +265 999 000 000</div>
            <div><a href="https://exploitsmw.com" target="_blank"><i class="bi bi-globe me-1"></i> exploitsmw.com</a></div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="eu-navbar">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="nav-brand">
                <img src="assets/img/Logo.png" alt="Exploits University Logo">
                <div>
                    <div class="uni-name">Exploits University Malawi</div>
                    <div class="vle-label">Virtual Learning Environment</div>
                </div>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a href="index.php" class="nav-link-custom d-none d-sm-inline-block"><i class="bi bi-house me-1"></i> Home</a>
                <a href="https://exploitsmw.com" target="_blank" class="nav-link-custom d-none d-md-inline-block"><i class="bi bi-globe me-1"></i> Website</a>
                <a href="https://apply.exploitsonline.com" target="_blank" class="btn btn-apply"><i class="bi bi-pencil-square me-1"></i> Apply Now</a>
            </div>
        </div>
    </nav>

    <!-- Login Form -->
    <div class="login-section">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <img src="assets/img/Logo.png" alt="Exploits University Logo" class="login-logo">
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

                        <button type="submit" class="btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
                        </button>
                    </form>

                    <div class="login-footer">
                        <a href="forgot_password.php" class="d-block mb-3">
                            <i class="bi bi-key me-1"></i> Forgot Password?
                        </a>
                        <div class="portal-links">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-portal btn-portal-apply">
                                <i class="bi bi-pencil-square"></i> Apply Now
                            </a>
                            <a href="https://exploitsmw.com" target="_blank" class="btn-portal btn-portal-website">
                                <i class="bi bi-globe"></i> Visit Website
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="eu-footer-mini">
        &copy; <?php echo date('Y'); ?> Exploits University Malawi. All rights reserved. &bull;
        <a href="https://vle.exploitsonline.com">vle.exploitsonline.com</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Background slideshow
        (function() {
            const imgs = document.querySelectorAll('.bg-slideshow img');
            let current = 0;
            setInterval(() => {
                imgs[current].classList.remove('active');
                current = (current + 1) % imgs.length;
                imgs[current].classList.add('active');
            }, 5000);
        })();
    </script>
</body>
</html>
