<?php
// auth.php - Authentication functions for VLE System

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

function isLoggedIn() {
    if (!isset($_SESSION['vle_user_id']) || empty($_SESSION['vle_user_id'])) {
        return false;
    }
    
    // Check session timeout
    if (isset($_SESSION['vle_last_activity'])) {
        $elapsed = time() - $_SESSION['vle_last_activity'];
        if ($elapsed > SESSION_TIMEOUT) {
            logout();
            return false;
        }
    }
    
    // Update last activity
    $_SESSION['vle_last_activity'] = time();
    return true;
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT u.*, s.full_name as student_name, s.email as student_email,
               l.full_name as lecturer_name, l.email as lecturer_email,
               st.full_name as staff_name, st.email as staff_email
        FROM users u
        LEFT JOIN students s ON u.related_student_id = s.student_id
        LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
        LEFT JOIN administrative_staff st ON u.related_staff_id = st.staff_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $_SESSION['vle_user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Determine display name and email based on role
        switch ($user['role']) {
            case 'student':
                $user['display_name'] = $user['student_name'] ?: $user['username'];
                $user['user_email'] = $user['student_email'] ?: $user['email'];
                break;
            case 'lecturer':
                $user['display_name'] = $user['lecturer_name'] ?: $user['username'];
                $user['user_email'] = $user['lecturer_email'] ?: $user['email'];
                break;
            case 'staff':
            case 'hod':
            case 'dean':
            case 'finance':
                $user['display_name'] = $user['staff_name'] ?: $user['username'];
                $user['user_email'] = $user['staff_email'] ?: $user['email'];
                break;
            default:
                $user['display_name'] = $user['username'];
                $user['user_email'] = $user['email'];
        }

        return $user;
    }

    return null;
}

function login($username_email, $password) {
    $conn = getDbConnection();

    // Check if input is username or email
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username_email, $username_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['vle_user_id'] = $user['user_id'];
            $_SESSION['vle_username'] = $user['username'];
            $_SESSION['vle_role'] = $user['role'];
            $_SESSION['vle_related_id'] = getRelatedId($user);
            $_SESSION['vle_last_activity'] = time(); // Set initial activity time
            $_SESSION['vle_login_time'] = time(); // Track login time

            return ['success' => true, 'user' => $user];
        }
    }

    return ['success' => false, 'message' => 'Invalid username/email or password'];
}

function getRelatedId($user) {
    switch ($user['role']) {
        case 'student':
            return $user['related_student_id'];
        case 'lecturer':
            return $user['related_lecturer_id'];
        case 'staff':
            return $user['related_staff_id'];
        case 'hod':
            return $user['related_hod_id'];
        case 'dean':
            return $user['related_dean_id'];
        case 'finance':
            return $user['related_finance_id'];
        default:
            return null;
    }
}

function logout() {
    session_unset();
    session_destroy();
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function requireRole($allowed_roles) {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }

    if (!in_array($_SESSION['vle_role'], $allowed_roles)) {
        die('Access denied. Insufficient permissions.');
    }
}
?>