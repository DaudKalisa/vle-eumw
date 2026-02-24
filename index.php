<?php
/**
 * VLE System - Main Entry Point
 * EXPLOITS University Multi-campus (EUMW)
 * Production-Ready Index File
 */

// Security headers for production
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Create logs directory if it doesn't exist
$logs_dir = __DIR__ . '/logs';
if (!is_dir($logs_dir)) {
    @mkdir($logs_dir, 0755, true);
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', $logs_dir . '/php_errors.log');

// Display errors in development (will be overridden by config.php for production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if auth.php exists before loading
$auth_file = __DIR__ . '/includes/auth.php';
if (!file_exists($auth_file)) {
    die('<h1>Configuration Error</h1><p>Authentication file not found at: ' . htmlspecialchars($auth_file) . '</p><p>Please ensure the VLE system is properly installed.</p>');
}

// Load authentication with error handling
try {
    require_once $auth_file;
} catch (Exception $e) {
    die('<h1>System Error</h1><p>Unable to load authentication system: ' . htmlspecialchars($e->getMessage()) . '</p>');
} catch (Error $e) {
    die('<h1>System Error</h1><p>Fatal error loading authentication: ' . htmlspecialchars($e->getMessage()) . '</p><p>File: ' . $e->getFile() . ' Line: ' . $e->getLine() . '</p>');
}

// Handle authenticated users
if (function_exists('isLoggedIn') && isLoggedIn()) {
    $user = getCurrentUser();
    
    // Redirect based on role
    switch ($user['role']) {
        case 'admin':
        case 'administrator':
            header('Location: admin/dashboard.php');
            break;
        case 'finance':
            header('Location: finance/dashboard.php');
            break;
        case 'lecturer':
            header('Location: lecturer/dashboard.php');
            break;
        case 'student':
            header('Location: student/dashboard.php');
            break;
        case 'hod':
        case 'dean':
            header('Location: admin/dashboard.php');
            break;
        default:
            header('Location: dashboard.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Exploits University Malawi Virtual Learning Environment — NCHE-accredited Open, Distance and e-Learning programmes. Study online from anywhere in Malawi and beyond.">
    <meta name="keywords" content="Exploits University, Malawi, VLE, ODL, ODLE, NCHE, Virtual Learning, Online Education, University Portal, e-Learning, Distance Learning">
    <meta name="author" content="Exploits University Malawi">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="Exploits University Malawi — Virtual Learning Environment">
    <meta property="og:description" content="Access your courses, assignments, examinations and academic resources online. NCHE-accredited programmes.">
    <meta property="og:image" content="pictures/Slider-1.jpg">
    <meta property="og:url" content="https://vle.exploitsonline.com">
    
    <title>Exploits University Malawi — Virtual Learning Environment</title>
    <link rel="icon" type="image/png" href="pictures/Logo.png">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --eu-primary: #0d1b4a;
            --eu-secondary: #1b3a7b;
            --eu-accent: #e8a317;
            --eu-accent-hover: #c88b0f;
            --eu-light: #f0f4ff;
            --eu-white: #ffffff;
            --eu-text: #1f2937;
            --eu-text-muted: #6b7280;
            --eu-radius: 16px;
            --eu-shadow: 0 8px 32px rgba(0,0,0,.12);
            --eu-transition: all .35s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; overflow-x: hidden; color: var(--eu-text); }

        /* ─── Top Bar ──────────────────────────── */
        .eu-topbar {
            background: var(--eu-primary);
            color: rgba(255,255,255,.8);
            font-size: .8rem;
            padding: 6px 0;
        }
        .eu-topbar a { color: var(--eu-accent); text-decoration: none; }
        .eu-topbar a:hover { text-decoration: underline; }

        /* ─── Navbar ───────────────────────────── */
        .eu-navbar {
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            box-shadow: 0 2px 20px rgba(0,0,0,.06);
            padding: .6rem 0;
            position: sticky;
            top: 0;
            z-index: 1050;
            transition: var(--eu-transition);
        }
        .eu-navbar.scrolled { box-shadow: 0 4px 24px rgba(0,0,0,.12); }
        .eu-navbar .nav-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .eu-navbar .nav-brand img { height: 50px; width: auto; }
        .eu-navbar .nav-brand-text { line-height: 1.2; }
        .eu-navbar .nav-brand-text .uni-name { font-weight: 800; font-size: 1.05rem; color: var(--eu-primary); }
        .eu-navbar .nav-brand-text .vle-label { font-size: .72rem; color: var(--eu-accent); font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; }
        .eu-navbar .nav-link-custom { font-weight: 500; color: var(--eu-text); padding: .5rem 1rem !important; border-radius: 8px; transition: var(--eu-transition); }
        .eu-navbar .nav-link-custom:hover { background: var(--eu-light); color: var(--eu-secondary); }
        .eu-navbar .btn-login { background: var(--eu-primary); color: #fff; border: none; padding: .55rem 1.8rem; border-radius: 50px; font-weight: 600; transition: var(--eu-transition); }
        .eu-navbar .btn-login:hover { background: var(--eu-secondary); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,27,74,.3); }

        /* ─── Hero Carousel ────────────────────── */
        .eu-hero { position: relative; }
        .eu-hero .carousel-item { height: 85vh; min-height: 500px; }
        .eu-hero .carousel-item img { object-fit: cover; width: 100%; height: 100%; }
        .eu-hero .carousel-item::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(13,27,74,.25) 0%, rgba(27,58,123,.20) 50%, rgba(13,27,74,.25) 100%);
            pointer-events: none;
        }
        .eu-hero .carousel-caption {
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
            position: relative;
            z-index: 2;
        }
        .eu-hero .hero-inner { max-width: 850px; margin: 0 auto; }
        .eu-hero .hero-badge {
            display: inline-block;
            background: rgba(13,27,74,.65);
            border: 1px solid var(--eu-accent);
            color: var(--eu-accent);
            padding: 6px 20px;
            border-radius: 50px;
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 1.2rem;
            backdrop-filter: blur(6px);
        }
        .eu-hero h1 {
            font-size: clamp(2rem, 5vw, 3.8rem);
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 1rem;
            text-shadow: 0 3px 20px rgba(0,0,0,.7), 0 1px 4px rgba(0,0,0,.5);
        }
        .eu-hero .hero-sub {
            font-size: clamp(1rem, 2vw, 1.3rem);
            color: #fff;
            margin-bottom: 2rem;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
            text-shadow: 0 2px 12px rgba(0,0,0,.6), 0 1px 3px rgba(0,0,0,.4);
        }
        .eu-hero .btn-hero-primary {
            background: var(--eu-accent);
            color: var(--eu-primary);
            border: none;
            padding: .85rem 2.5rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.05rem;
            transition: var(--eu-transition);
            display: inline-flex;
            align-items: center;
            gap: .5rem;
        }
        .eu-hero .btn-hero-primary:hover { background: var(--eu-accent-hover); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(232,163,23,.4); }
        .eu-hero .btn-hero-outline {
            background: transparent;
            color: #fff;
            border: 2px solid rgba(255,255,255,.6);
            padding: .8rem 2.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1.05rem;
            transition: var(--eu-transition);
            display: inline-flex;
            align-items: center;
            gap: .5rem;
        }
        .eu-hero .btn-hero-outline:hover { background: #fff; color: var(--eu-primary); border-color: #fff; transform: translateY(-2px); }
        .carousel-indicators [data-bs-target] { width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; background: transparent; opacity: .6; transition: .3s; }
        .carousel-indicators .active { background: var(--eu-accent); border-color: var(--eu-accent); opacity: 1; width: 36px; border-radius: 6px; }

        /* ─── Stats Bar ────────────────────────── */
        .eu-stats {
            background: var(--eu-primary);
            margin-top: -60px;
            position: relative;
            z-index: 10;
            border-radius: var(--eu-radius);
            padding: 2rem 2.5rem;
            box-shadow: var(--eu-shadow);
        }
        .eu-stats .stat-item { text-align: center; color: #fff; }
        .eu-stats .stat-num { font-size: 2.2rem; font-weight: 800; color: var(--eu-accent); }
        .eu-stats .stat-label { font-size: .85rem; opacity: .8; }

        /* ─── Section Shared ───────────────────── */
        .eu-section { padding: 5rem 0; }
        .eu-section-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--eu-primary);
            margin-bottom: .5rem;
        }
        .eu-section-sub {
            color: var(--eu-text-muted);
            max-width: 600px;
            margin: 0 auto 3rem;
        }
        .eu-accent-line {
            width: 60px;
            height: 4px;
            background: var(--eu-accent);
            border-radius: 2px;
            margin: .8rem auto 1.2rem;
        }

        /* ─── Feature Cards ────────────────────── */
        .eu-feature-card {
            background: #fff;
            border-radius: var(--eu-radius);
            padding: 2.2rem 1.8rem;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            transition: var(--eu-transition);
            border: 1px solid rgba(0,0,0,.04);
            height: 100%;
        }
        .eu-feature-card:hover { transform: translateY(-6px); box-shadow: 0 12px 40px rgba(0,0,0,.1); }
        .eu-feature-icon {
            width: 72px; height: 72px;
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.2rem;
            font-size: 1.7rem; color: #fff;
        }
        .eu-feature-card h5 { font-weight: 700; color: var(--eu-primary); margin-bottom: .5rem; }
        .eu-feature-card p { font-size: .9rem; color: var(--eu-text-muted); margin: 0; }

        /* ─── Quick Action Cards (image-backed) ── */
        .eu-action-card {
            position: relative;
            border-radius: var(--eu-radius);
            overflow: hidden;
            height: 280px;
            display: flex;
            align-items: flex-end;
            text-decoration: none;
            transition: var(--eu-transition);
            box-shadow: 0 8px 30px rgba(0,0,0,.15);
        }
        .eu-action-card:hover { transform: translateY(-8px); box-shadow: 0 16px 50px rgba(0,0,0,.25); }
        .eu-action-card img {
            position: absolute;
            inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            transition: var(--eu-transition);
        }
        .eu-action-card:hover img { transform: scale(1.06); }
        .eu-action-card .action-overlay {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 2rem 1.5rem 1.5rem;
            background: linear-gradient(0deg, rgba(13,27,74,.88) 0%, rgba(13,27,74,.55) 60%, transparent 100%);
            color: #fff;
        }
        .eu-action-card .action-overlay h4 {
            font-weight: 800;
            font-size: 1.3rem;
            margin-bottom: .3rem;
            text-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .eu-action-card .action-overlay p {
            font-size: .85rem;
            opacity: .85;
            margin-bottom: .8rem;
        }
        .eu-action-card .action-btn-inner {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem 1.4rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: .85rem;
            transition: var(--eu-transition);
        }
        .eu-action-card .btn-gold { background: var(--eu-accent); color: var(--eu-primary); }
        .eu-action-card:hover .btn-gold { background: var(--eu-accent-hover); }
        .eu-action-card .btn-white { background: #fff; color: var(--eu-primary); }
        .eu-action-card:hover .btn-white { background: var(--eu-light); }
        .eu-action-card .btn-green { background: #10b981; color: #fff; }
        .eu-action-card:hover .btn-green { background: #059669; }
        @media (max-width: 767px) {
            .eu-action-card { height: 220px; }
            .eu-action-card .action-overlay h4 { font-size: 1.1rem; }
        }

        /* ─── Why Section ──────────────────────── */
        .eu-why { background: var(--eu-light); }
        .eu-why-img { border-radius: var(--eu-radius); box-shadow: var(--eu-shadow); width: 100%; height: auto; object-fit: cover; }
        .eu-check-list { list-style: none; padding: 0; }
        .eu-check-list li {
            padding: .6rem 0;
            font-size: 1rem;
            display: flex;
            align-items: flex-start;
            gap: .8rem;
        }
        .eu-check-list li i { color: var(--eu-accent); font-size: 1.2rem; margin-top: 2px; }

        /* ─── Campus Gallery ───────────────────── */
        .eu-campus-card {
            border-radius: var(--eu-radius);
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            transition: var(--eu-transition);
        }
        .eu-campus-card:hover { transform: translateY(-5px); box-shadow: 0 12px 40px rgba(0,0,0,.15); }
        .eu-campus-card img { width: 100%; height: 220px; object-fit: cover; transition: var(--eu-transition); }
        .eu-campus-card:hover img { transform: scale(1.05); }
        .eu-campus-card .campus-overlay {
            padding: 1rem 1.2rem;
            background: #fff;
        }
        .eu-campus-card .campus-overlay h6 { font-weight: 700; color: var(--eu-primary); margin: 0; }
        .eu-campus-card .campus-overlay small { color: var(--eu-text-muted); }

        /* ─── NCHE Section ─────────────────────── */
        .eu-nche { background: linear-gradient(135deg, var(--eu-primary) 0%, var(--eu-secondary) 100%); color: #fff; }
        .eu-nche .nche-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
        }
        .eu-nche .nche-badge i { font-size: 1.5rem; color: var(--eu-accent); }

        /* ─── CTA ──────────────────────────────── */
        .eu-cta {
            background: linear-gradient(135deg, var(--eu-accent) 0%, #d4940f 100%);
            color: var(--eu-primary);
            padding: 4rem 0;
            text-align: center;
        }
        .eu-cta h2 { font-weight: 800; font-size: 2rem; }
        .eu-cta .btn-cta {
            background: var(--eu-primary);
            color: #fff;
            padding: .9rem 2.8rem;
            border-radius: 50px;
            font-weight: 700;
            border: none;
            font-size: 1.05rem;
            transition: var(--eu-transition);
        }
        .eu-cta .btn-cta:hover { background: var(--eu-secondary); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,.2); }

        /* ─── Footer ───────────────────────────── */
        .eu-footer {
            background: var(--eu-primary);
            color: rgba(255,255,255,.7);
            padding: 3.5rem 0 1.5rem;
        }
        .eu-footer h6 { color: #fff; font-weight: 700; font-size: .85rem; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 1rem; }
        .eu-footer ul { list-style: none; padding: 0; }
        .eu-footer ul li { margin-bottom: .4rem; }
        .eu-footer ul li a { color: rgba(255,255,255,.6); text-decoration: none; font-size: .88rem; transition: .2s; }
        .eu-footer ul li a:hover { color: var(--eu-accent); padding-left: 4px; }
        .eu-footer-bottom {
            border-top: 1px solid rgba(255,255,255,.1);
            padding-top: 1.2rem;
            margin-top: 2rem;
            font-size: .8rem;
        }
        .eu-footer-bottom a { color: var(--eu-accent); text-decoration: none; }

        /* ─── Responsive ───────────────────────── */
        @media (max-width: 991px) {
            .eu-hero .carousel-item { height: 70vh; }
            .eu-stats { margin-top: -40px; padding: 1.5rem; }
            .eu-stats .stat-num { font-size: 1.6rem; }
        }
        @media (max-width: 767px) {
            .eu-hero .carousel-item { height: 60vh; min-height: 400px; }
            .eu-stats { margin-top: 0; border-radius: 0; }
            .eu-section { padding: 3rem 0; }
            .eu-navbar .nav-brand img { height: 40px; }
            .eu-navbar .nav-brand-text .uni-name { font-size: .9rem; }
        }
        @media (max-width: 575px) {
            .eu-hero .carousel-item { height: 55vh; min-height: 350px; }
            .eu-hero h1 { font-size: 1.6rem; }
            .eu-hero .btn-hero-primary, .eu-hero .btn-hero-outline { padding: .65rem 1.5rem; font-size: .9rem; }
            .eu-stats .stat-num { font-size: 1.3rem; }
            .eu-stats .stat-label { font-size: .7rem; }
        }

        /* Session alerts */
        .session-alert { position: fixed; top: 80px; left: 50%; transform: translateX(-50%); z-index: 2000; min-width: 320px; max-width: 90%; }
    </style>
</head>
<body>
    <?php if (isset($_GET['session_expired'])): ?>
    <div class="session-alert alert alert-warning alert-dismissible fade show shadow-lg" role="alert">
        <i class="bi bi-clock-history me-2"></i><strong>Session Expired!</strong> You have been logged out due to inactivity.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['timeout'])): ?>
    <div class="session-alert alert alert-info alert-dismissible fade show shadow-lg" role="alert">
        <i class="bi bi-info-circle me-2"></i><strong>Session Timeout!</strong> Please login again.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Top Bar -->
    <div class="eu-topbar d-none d-md-block">
        <div class="container d-flex justify-content-between align-items-center">
            <div><i class="bi bi-envelope me-1"></i> <a href="mailto:info@exploitsonline.com">info@exploitsonline.com</a> <span class="mx-2">|</span> <i class="bi bi-telephone me-1"></i> +265 999 000 000</div>
            <div><a href="https://exploitsmw.com" target="_blank"><i class="bi bi-globe me-1"></i> exploitsmw.com</a> <span class="mx-2">|</span> <a href="https://vle.exploitsonline.com"><i class="bi bi-mortarboard me-1"></i> VLE Portal</a></div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="eu-navbar" id="mainNav">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="nav-brand">
                <img src="pictures/Logo.png" alt="Exploits University Logo">
                <div class="nav-brand-text">
                    <div class="uni-name">Exploits University Malawi</div>
                    <div class="vle-label">Virtual Learning Environment</div>
                </div>
            </a>
            <div class="d-none d-lg-flex align-items-center gap-1">
                <a href="#about" class="nav-link-custom">About</a>
                <a href="#programmes" class="nav-link-custom">Programmes</a>
                <a href="#campus" class="nav-link-custom">Campus</a>
                <a href="https://exploitsmw.com" target="_blank" class="nav-link-custom">Main Website</a>
                <a href="https://apply.exploitsonline.com" target="_blank" class="btn ms-2" style="background:var(--eu-accent);color:var(--eu-primary);border:none;padding:.55rem 1.5rem;border-radius:50px;font-weight:700;transition:var(--eu-transition);"><i class="bi bi-pencil-square me-1"></i> Apply Now</a>
                <a href="login.php" class="btn btn-login ms-2"><i class="bi bi-box-arrow-in-right me-1"></i> Login</a>
            </div>
            <button class="btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" style="font-size:1.5rem"><i class="bi bi-list"></i></button>
        </div>
    </nav>

    <!-- Mobile Nav Offcanvas -->
    <div class="offcanvas offcanvas-end" id="mobileNav">
        <div class="offcanvas-header">
            <h5><img src="pictures/Logo.png" alt="Logo" style="height:35px" class="me-2"> EU Malawi</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <a href="#about" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-info-circle me-2"></i>About</a>
            <a href="#programmes" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-book me-2"></i>Programmes</a>
            <a href="#campus" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-building me-2"></i>Campus</a>
            <a href="https://exploitsmw.com" target="_blank" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-globe me-2"></i>Main Website</a>
            <hr>
            <a href="https://apply.exploitsonline.com" target="_blank" class="btn w-100 mb-2" style="background:var(--eu-accent);color:var(--eu-primary);font-weight:700;border-radius:50px;"><i class="bi bi-pencil-square me-1"></i> Apply Now</a>
            <a href="login.php" class="btn btn-login w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Login to VLE</a>
        </div>
    </div>

    <!-- Hero Carousel -->
    <section class="eu-hero">
        <div id="heroCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="5000">
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="3"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="4"></button>
                <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="5"></button>
            </div>
            <div class="carousel-inner">
                <div class="carousel-item active">
                    <img src="pictures/Slider-1.jpg" alt="Exploits University Campus">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-shield-check me-1"></i> NCHE Accredited</span>
                            <h1>Welcome to<br><strong>Exploits University Malawi</strong></h1>
                            <p class="hero-sub">Transforming higher education through innovative Open, Distance and e-Learning across Malawi and beyond.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="login.php" class="btn-hero-primary"><i class="bi bi-mortarboard"></i> Access VLE</a>
                                <a href="https://apply.exploitsonline.com" target="_blank" class="btn-hero-primary" style="background:#10b981;color:#fff;"><i class="bi bi-pencil-square"></i> Apply Now</a>
                                <a href="learn_more.php" class="btn-hero-outline"><i class="bi bi-play-circle"></i> Learn More</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="pictures/Slider-2.png" alt="Students at Exploits University">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-people me-1"></i> Student Community</span>
                            <h1>Study Anywhere,<br><strong>Achieve Everywhere</strong></h1>
                            <p class="hero-sub">Flexible online programmes designed for working professionals, school leavers and lifelong learners in Malawi.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="login.php" class="btn-hero-primary"><i class="bi bi-box-arrow-in-right"></i> Student Login</a>
                                <a href="#programmes" class="btn-hero-outline"><i class="bi bi-journal-text"></i> View Programmes</a>
                                <a href="https://apply.exploitsonline.com" target="_blank" class="btn-hero-outline"><i class="bi bi-pencil-square"></i> Apply</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="pictures/Slider-3.jpg" alt="Exploits University Learning">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-laptop me-1"></i> e-Learning</span>
                            <h1>Digital-First<br><strong>Learning Platform</strong></h1>
                            <p class="hero-sub">Access lectures, assignments, examinations and academic resources from your computer or mobile device.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="login.php" class="btn-hero-primary"><i class="bi bi-mortarboard"></i> Get Started</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="pictures/Slider-4.jpg" alt="Exploits University Campus Life">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-building me-1"></i> Multi-Campus</span>
                            <h1>Campuses Across<br><strong>Malawi</strong></h1>
                            <p class="hero-sub">With campuses in Lilongwe, Blantyre, Mzuzu and beyond — quality education is never far away.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="#campus" class="btn-hero-primary"><i class="bi bi-geo-alt"></i> Our Campuses</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="pictures/Slider-5.png" alt="Exploits University Graduation">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-award me-1"></i> Excellence</span>
                            <h1>Empowering<br><strong>Future Leaders</strong></h1>
                            <p class="hero-sub">Join thousands of graduates who are shaping Malawi's future through quality education and dedication.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="login.php" class="btn-hero-primary"><i class="bi bi-box-arrow-in-right"></i> Login Now</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="pictures/Slider-6.jpg" alt="Exploits University Resources">
                    <div class="carousel-caption">
                        <div class="hero-inner">
                            <span class="hero-badge"><i class="bi bi-book me-1"></i> Resources</span>
                            <h1>World-Class<br><strong>Academic Resources</strong></h1>
                            <p class="hero-sub">Digital library, online examinations, live classrooms and collaborative learning — all in one platform.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <a href="login.php" class="btn-hero-primary"><i class="bi bi-mortarboard"></i> Access VLE</a>
                                <a href="login.php" class="btn-hero-outline"><i class="bi bi-book"></i> Resources</a>
                                <a href="https://exploitsmw.com" target="_blank" class="btn-hero-outline"><i class="bi bi-globe"></i> Visit Website</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
            </button>
        </div>
    </section>

    <!-- Stats Bar -->
    <div class="container">
        <div class="eu-stats">
            <div class="row text-center g-3">
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-num">5,000+</div>
                        <div class="stat-label">Active Students</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-num">50+</div>
                        <div class="stat-label">Programmes</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-num">4</div>
                        <div class="stat-label">Campuses</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-num">100%</div>
                        <div class="stat-label">Online Access</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions — image-backed cards -->
    <section class="eu-section" id="about">
        <div class="container">
            <div class="text-center">
                <h2 class="eu-section-title">Get Started</h2>
                <div class="eu-accent-line"></div>
                <p class="eu-section-sub">Access your learning portal, explore our programmes, or apply for admission at Exploits University Malawi.</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <a href="login.php" class="eu-action-card">
                        <img src="pictures/Slider-3.jpg" alt="Student Login">
                        <div class="action-overlay">
                            <h4><i class="bi bi-mortarboard me-2"></i>Student Login</h4>
                            <p>Access courses, assignments, exams and academic resources</p>
                            <span class="action-btn-inner btn-gold"><i class="bi bi-box-arrow-in-right"></i> Login to VLE</span>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4 col-md-6">
                    <a href="#programmes" class="eu-action-card">
                        <img src="pictures/Slider-5.png" alt="View Programmes">
                        <div class="action-overlay">
                            <h4><i class="bi bi-journal-text me-2"></i>View Programmes</h4>
                            <p>Undergraduate, Postgraduate, Professional & Short Courses</p>
                            <span class="action-btn-inner btn-white"><i class="bi bi-arrow-down-circle"></i> Explore</span>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4 col-md-12">
                    <a href="https://apply.exploitsonline.com" target="_blank" class="eu-action-card">
                        <img src="pictures/Slider-2.png" alt="Apply Now">
                        <div class="action-overlay">
                            <h4><i class="bi bi-pencil-square me-2"></i>Apply Now</h4>
                            <p>Start your application for the upcoming intake today</p>
                            <span class="action-btn-inner btn-green"><i class="bi bi-send"></i> Apply Online</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="eu-section" style="background: var(--eu-light);">
        <div class="container">
            <div class="text-center">
                <h2 class="eu-section-title">Why Choose Our VLE?</h2>
                <div class="eu-accent-line"></div>
                <p class="eu-section-sub">A comprehensive virtual learning platform designed to deliver quality education in accordance with NCHE ODLE guidance standards.</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-3 col-md-6">
                    <div class="eu-feature-card">
                        <div class="eu-feature-icon" style="background: linear-gradient(135deg,#667eea,#764ba2)"><i class="bi bi-book"></i></div>
                        <h5>Online Courses</h5>
                        <p>Access course materials, lecture notes and learning resources 24/7 from any device.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="eu-feature-card">
                        <div class="eu-feature-icon" style="background: linear-gradient(135deg,#11998e,#38ef7d)"><i class="bi bi-clipboard-check"></i></div>
                        <h5>Examinations</h5>
                        <p>Take mid-semester exams, quizzes and end-semester examinations securely online.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="eu-feature-card">
                        <div class="eu-feature-icon" style="background: linear-gradient(135deg,#f093fb,#f5576c)"><i class="bi bi-camera-video"></i></div>
                        <h5>Live Classrooms</h5>
                        <p>Attend live lectures and interact with lecturers in real-time virtual sessions.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="eu-feature-card">
                        <div class="eu-feature-icon" style="background: linear-gradient(135deg,#fa709a,#fee140)"><i class="bi bi-graph-up"></i></div>
                        <h5>Progress Tracking</h5>
                        <p>Monitor grades, attendance and academic performance throughout the semester.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Exploits Section -->
    <section class="eu-section eu-why" id="programmes">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <img src="pictures/Slider-2.png" alt="Exploits University Students" class="eu-why-img">
                </div>
                <div class="col-lg-6">
                    <span class="hero-badge" style="color: var(--eu-primary); border-color: var(--eu-primary); background: rgba(13,27,74,.08);"><i class="bi bi-mortarboard me-1"></i> Academic Excellence</span>
                    <h2 class="eu-section-title mt-3">Accredited Programmes for Every Learner</h2>
                    <div class="eu-accent-line" style="margin: .8rem 0 1.5rem;"></div>
                    <p class="text-muted mb-4">Exploits University Malawi offers NCHE-accredited programmes through Open, Distance and e-Learning (ODLE), making quality higher education accessible to all Malawians.</p>
                    <ul class="eu-check-list">
                        <li><i class="bi bi-check-circle-fill"></i> <div><strong>Undergraduate Degrees</strong> — Business, Education, IT, Social Sciences</div></li>
                        <li><i class="bi bi-check-circle-fill"></i> <div><strong>Postgraduate Programmes</strong> — MBA, Masters, Professional Certificates</div></li>
                        <li><i class="bi bi-check-circle-fill"></i> <div><strong>Professional Courses</strong> — Short courses and industry certifications</div></li>
                        <li><i class="bi bi-check-circle-fill"></i> <div><strong>Flexible Scheduling</strong> — Study at your own pace, from anywhere</div></li>
                        <li><i class="bi bi-check-circle-fill"></i> <div><strong>NCHE ODLE Compliant</strong> — Meeting national quality assurance standards</div></li>
                    </ul>
                    <a href="https://exploitsmw.com" target="_blank" class="btn btn-login mt-3"><i class="bi bi-arrow-right me-1"></i> Explore Programmes</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Campus Gallery -->
    <section class="eu-section" id="campus">
        <div class="container">
            <div class="text-center">
                <h2 class="eu-section-title">Our Campuses & Student Life</h2>
                <div class="eu-accent-line"></div>
                <p class="eu-section-sub">Experience vibrant campus life across multiple locations in Malawi.</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="eu-campus-card">
                        <div style="overflow:hidden"><img src="pictures/Slider-1.jpg" alt="Main Campus"></div>
                        <div class="campus-overlay"><h6>Main Campus</h6><small>Lilongwe, Malawi</small></div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="eu-campus-card">
                        <div style="overflow:hidden"><img src="pictures/Slider-4.jpg" alt="Learning Centre"></div>
                        <div class="campus-overlay"><h6>Learning Centre</h6><small>Blantyre, Malawi</small></div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="eu-campus-card">
                        <div style="overflow:hidden"><img src="pictures/Slider-3.jpg" alt="Student Hub"></div>
                        <div class="campus-overlay"><h6>Northern Campus</h6><small>Mzuzu, Malawi</small></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- NCHE Compliance -->
    <section class="eu-section eu-nche">
        <div class="container text-center">
            <div class="nche-badge mx-auto mb-4"><i class="bi bi-shield-check"></i> <span>NCHE Accredited Institution</span></div>
            <h2 class="fw-bold mb-3" style="font-size:2rem;">Meeting National Quality Standards</h2>
            <p class="mx-auto mb-4" style="max-width:700px; opacity:.85;">Our Virtual Learning Environment is designed in full compliance with the National Council for Higher Education (NCHE) Open, Distance and e-Learning (ODLE) guidance standards, ensuring quality assurance in all academic programmes delivered online.</p>
            <div class="row g-4 mt-3 justify-content-center">
                <div class="col-md-4"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.08)"><i class="bi bi-patch-check fs-2 d-block mb-2" style="color:var(--eu-accent)"></i><strong>Quality Assurance</strong><br><small style="opacity:.7">Regular programme reviews and assessments</small></div></div>
                <div class="col-md-4"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.08)"><i class="bi bi-person-check fs-2 d-block mb-2" style="color:var(--eu-accent)"></i><strong>Student Support</strong><br><small style="opacity:.7">Dedicated academic and technical assistance</small></div></div>
                <div class="col-md-4"><div class="p-3 rounded-3" style="background:rgba(255,255,255,.08)"><i class="bi bi-file-earmark-check fs-2 d-block mb-2" style="color:var(--eu-accent)"></i><strong>Verified Credentials</strong><br><small style="opacity:.7">Nationally recognised qualifications</small></div></div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="eu-cta">
        <div class="container">
            <h2>Ready to Start Your Learning Journey?</h2>
            <p class="mb-4" style="max-width:500px;margin:0 auto;">Login to access your courses, submit assignments, take examinations and track your academic progress.</p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
                <a href="login.php" class="btn btn-cta"><i class="bi bi-box-arrow-in-right me-2"></i>Login to VLE</a>
                <a href="https://apply.exploitsonline.com" target="_blank" class="btn" style="background:#fff;color:var(--eu-primary);padding:.9rem 2.8rem;border-radius:50px;font-weight:700;border:none;font-size:1.05rem;"><i class="bi bi-pencil-square me-2"></i>Apply Now</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="eu-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="pictures/Logo.png" alt="Logo" style="height:40px; filter:brightness(0) invert(1);">
                        <div>
                            <div class="fw-bold text-white">Exploits University Malawi</div>
                            <div style="font-size:.72rem;color:var(--eu-accent);letter-spacing:1px;">VIRTUAL LEARNING ENVIRONMENT</div>
                        </div>
                    </div>
                    <p style="font-size:.88rem;">Transforming higher education through innovative Open, Distance and e-Learning across Malawi. NCHE accredited and committed to academic excellence.</p>
                    <div class="d-flex gap-2 mt-2">
                        <a href="https://exploitsmw.com" target="_blank" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-globe"></i></a>
                        <a href="mailto:info@exploitsonline.com" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-envelope"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h6>Study</h6>
                    <ul>
                        <li><a href="https://exploitsmw.com" target="_blank">Undergraduate</a></li>
                        <li><a href="https://exploitsmw.com" target="_blank">Postgraduate</a></li>
                        <li><a href="https://exploitsmw.com" target="_blank">Short Courses</a></li>
                        <li><a href="https://exploitsmw.com" target="_blank">Professional</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h6>Quick Links</h6>
                    <ul>
                        <li><a href="login.php">VLE Login</a></li>
                        <li><a href="learn_more.php">About VLE</a></li>
                        <li><a href="https://exploitsmw.com" target="_blank">Main Website</a></li>
                        <li><a href="forgot_password.php">Reset Password</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact Us</h6>
                    <ul>
                        <li><i class="bi bi-envelope me-2" style="color:var(--eu-accent)"></i> info@exploitsonline.com</li>
                        <li><i class="bi bi-globe me-2" style="color:var(--eu-accent)"></i> <a href="https://exploitsmw.com" target="_blank">exploitsmw.com</a></li>
                        <li><i class="bi bi-geo-alt me-2" style="color:var(--eu-accent)"></i> Lilongwe, Malawi</li>
                        <li><i class="bi bi-mortarboard me-2" style="color:var(--eu-accent)"></i> <a href="https://vle.exploitsonline.com">vle.exploitsonline.com</a></li>
                    </ul>
                </div>
            </div>
            <div class="eu-footer-bottom d-flex flex-wrap justify-content-between align-items-center">
                <div>&copy; <?php echo date('Y'); ?> Exploits University Malawi. All rights reserved.</div>
                <div>VLE v16.0.1 &bull; Powered by <a href="https://exploitsmw.com" target="_blank">Exploits University</a></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 50);
        });
        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                const t = document.querySelector(this.getAttribute('href'));
                if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                // Close offcanvas
                const oc = bootstrap.Offcanvas.getInstance(document.getElementById('mobileNav'));
                if (oc) oc.hide();
            });
        });
        // Auto-dismiss session alerts
        setTimeout(() => { document.querySelectorAll('.session-alert').forEach(el => { new bootstrap.Alert(el).close(); }); }, 5000);
    </script>
</body>
</html>