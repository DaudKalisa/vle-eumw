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
    <meta name="description" content="Exploits University Multi-campus Virtual Learning Environment - Access your courses, assignments, and academic resources online.">
    <meta name="keywords" content="EUMW, Virtual Learning, Online Education, University Portal">
    <meta name="author" content="Exploits University Multi-campus">
    <meta name="robots" content="index, follow">
    
    <title>Welcome to EUMW Virtual Learning Environment</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom Styles -->
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1e3a8a;
            --secondary-color: #3b82f6;
            --accent-color: #f59e0b;
            --text-dark: #1f2937;
            --text-light: #6b7280;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
        }

        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(30, 58, 138, 0.85);
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            color: white;
            text-align: center;
            padding: 2rem;
        }

        .logo-container {
            margin-bottom: 2rem;
        }

        .logo-container img {
            max-width: 150px;
            height: auto;
            filter: brightness(0) invert(1);
        }

        h1.main-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .subtitle {
            font-size: 1.5rem;
            margin-bottom: 3rem;
            color: #e0e7ff;
        }

        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-custom {
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-custom {
            background: white;
            color: var(--primary-color);
            border: 2px solid white;
        }

        .btn-primary-custom:hover {
            background: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary-custom {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .btn-secondary-custom:hover {
            background: white;
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .features {
            margin-top: 4rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            transition: transform 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .feature-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.15);
        }

        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--accent-color);
        }

        .feature-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .feature-description {
            color: #e0e7ff;
            font-size: 0.95rem;
        }

        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .shape {
            position: absolute;
            opacity: 0.1;
            animation: float 20s infinite ease-in-out;
        }

        .shape1 {
            top: 10%;
            left: 10%;
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            animation-delay: 0s;
        }

        .shape2 {
            top: 60%;
            right: 15%;
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            animation-delay: 5s;
        }

        .shape3 {
            bottom: 20%;
            left: 20%;
            width: 100px;
            height: 100px;
            background: white;
            clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            50% {
                transform: translateY(-30px) rotate(180deg);
            }
        }

        @media (max-width: 768px) {
            h1.main-title {
                font-size: 2.5rem;
            }
            .subtitle {
                font-size: 1.2rem;
            }
            .features {
                grid-template-columns: 1fr;
            }
        }

        footer {
            position: relative;
            z-index: 2;
            background: rgba(0, 0, 0, 0.3);
            color: white;
            text-align: center;
            padding: 1.5rem;
            margin-top: 3rem;
        }

        footer a {
            color: var(--accent-color);
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="hero-section">
        <div class="floating-shapes">
            <div class="shape shape1"></div>
            <div class="shape shape2"></div>
            <div class="shape shape3"></div>
        </div>
        
        <div class="hero-overlay"></div>
        
        <div class="hero-content">
            <div class="container">
                <?php if (isset($_GET['session_expired'])): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert" style="max-width: 600px; margin: 0 auto 20px;">
                    <i class="fas fa-clock me-2"></i>
                    <strong>Session Expired!</strong> You have been logged out due to inactivity.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['timeout'])): ?>
                <div class="alert alert-info alert-dismissible fade show mb-4" role="alert" style="max-width: 600px; margin: 0 auto 20px;">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Session Timeout!</strong> Your session has expired. Please login again.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="logo-container">
                    <i class="fas fa-graduation-cap" style="font-size: 5rem;"></i>
                </div>
                
                <h1 class="main-title">EXPLOITS UNIVERSITY MALAWI</h1>
                <p class="subtitle">Virtual Learning Environment</p>
                
                <div class="cta-buttons">
                    <a href="login.php" class="btn-custom btn-primary-custom">
                        <i class="fas fa-sign-in-alt"></i>
                        Login to VLE
                    </a>
                    <a href="learn_more.php" class="btn-custom btn-secondary-custom">
                        <i class="fas fa-info-circle"></i>
                        Learn More
                    </a>
                </div>

                <div class="features" id="about">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-book-reader"></i>
                        </div>
                        <div class="feature-title">Online Courses</div>
                        <div class="feature-description">
                            Access course materials, lectures, and resources anytime, anywhere
                        </div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="feature-title">Assignment Management</div>
                        <div class="feature-description">
                            Submit assignments and track your academic progress in real-time
                        </div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="feature-title">Collaborative Learning</div>
                        <div class="feature-description">
                            Interact with peers and instructors through discussion forums
                        </div>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-title">Performance Tracking</div>
                        <div class="feature-description">
                            Monitor your grades and academic performance throughout the semester
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-light mt-5 pt-4 pb-2">
        <div class="container">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <h6 class="text-uppercase">Study at Exploits</h6>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-light text-decoration-none">Postgraduate</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Undergraduate</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Short courses</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Professional education</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-3">
                    <h6 class="text-uppercase">About the University</h6>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-light text-decoration-none">Research</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Campus Life</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Visiting the University</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Faculties</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Departments</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Vacancies</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-3">
                    <h6 class="text-uppercase">Resources</h6>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-light text-decoration-none">Library</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Campus Map</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Directory</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Teacher Profiles</a></li>
                        <li><a href="#" class="text-light text-decoration-none">Discussion</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-3 d-flex flex-column justify-content-between">
                    <div>
                        <h6 class="text-uppercase">&nbsp;</h6>
                        <p class="small">&copy; <?php echo date('Y'); ?> EXPLOITS University Multi-campus.<br>All rights reserved.</p>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Performance optimization - defer non-critical resources
        window.addEventListener('load', function() {
            console.log('VLE System loaded successfully');
        });
    </script>
</body>
</html>