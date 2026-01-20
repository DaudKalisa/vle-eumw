<?php
// login_process.php - Process login for VLE System

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/auth.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

// Get and sanitize input
$username_email = trim($_POST['username_email'] ?? '');
$password = trim($_POST['password'] ?? '');

// Validate input
if (empty($username_email) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter both username/email and password.';
    header('Location: login.php');
    exit();
}

// Attempt login
try {
    $result = login($username_email, $password);
    
    if ($result['success']) {
        // Clear any previous error messages
        unset($_SESSION['login_error']);
        
        // Redirect based on role
        switch ($_SESSION['vle_role']) {
            case 'student':
                $redirect_url = 'student/dashboard.php';
                break;
            case 'lecturer':
                $redirect_url = 'lecturer/dashboard.php';
                break;
            case 'finance':
                $redirect_url = 'finance/dashboard.php';
                break;
            case 'staff':
            case 'hod':
            case 'dean':
                $redirect_url = 'admin/dashboard.php';
                break;
            default:
                $redirect_url = 'dashboard.php';
        }
        
        header('Location: ' . $redirect_url);
        exit();
    } else {
        // Login failed
        $_SESSION['login_error'] = $result['message'];
        header('Location: login.php');
        exit();
    }
} catch (Exception $e) {
    // Log error for debugging
    error_log("Login error: " . $e->getMessage());
    
    $_SESSION['login_error'] = 'An error occurred during login. Please try again.';
    header('Location: login.php');
    exit();
}
?>