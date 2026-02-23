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
    
    // Check if optional tables exist
    $finance_table_exists = $conn->query("SHOW TABLES LIKE 'finance_users'")->num_rows > 0;
    $exam_mgr_table_exists = $conn->query("SHOW TABLES LIKE 'examination_managers'")->num_rows > 0;
    
    // Build JOIN fragments based on available tables
    $em_join = $exam_mgr_table_exists
        ? "LEFT JOIN examination_managers em ON u.related_staff_id = em.manager_id"
        : "";
    $em_cols = $exam_mgr_table_exists
        ? ", em.full_name as exam_manager_name, em.email as exam_manager_email"
        : "";
    
    // Build query based on available tables
    if ($finance_table_exists) {
        $stmt = $conn->prepare("
            SELECT u.*, s.full_name as student_name, s.email as student_email,
                   l.full_name as lecturer_name, l.email as lecturer_email,
                   st.full_name as staff_name, st.email as staff_email,
                   f.full_name as finance_name, f.email as finance_email
                   $em_cols
            FROM users u
            LEFT JOIN students s ON u.related_student_id = s.student_id
            LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
            LEFT JOIN administrative_staff st ON u.related_staff_id = st.staff_id
            LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id
            $em_join
            WHERE u.user_id = ?
        ");
    } else {
        // Fallback query without finance_users table - use lecturers for finance users
        $stmt = $conn->prepare("
            SELECT u.*, s.full_name as student_name, s.email as student_email,
                   l.full_name as lecturer_name, l.email as lecturer_email,
                   st.full_name as staff_name, st.email as staff_email,
                   fl.full_name as finance_name, fl.email as finance_email
                   $em_cols
            FROM users u
            LEFT JOIN students s ON u.related_student_id = s.student_id
            LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
            LEFT JOIN administrative_staff st ON u.related_staff_id = st.staff_id
            LEFT JOIN lecturers fl ON u.related_lecturer_id = fl.lecturer_id AND fl.role = 'finance'
            $em_join
            WHERE u.user_id = ?
        ");
    }
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
            case 'admin':
                $user['display_name'] = $user['staff_name'] ?: $user['username'];
                $user['user_email'] = $user['staff_email'] ?: $user['email'];
                break;
            case 'staff':
            case 'hod':
            case 'dean':
                // Check if this is an examination manager
                if (!empty($user['exam_manager_name'])) {
                    $user['display_name'] = $user['exam_manager_name'];
                    $user['user_email'] = $user['exam_manager_email'] ?: $user['email'];
                    $user['role'] = 'examination_manager'; // Override role for examination managers
                } else {
                    $user['display_name'] = $user['staff_name'] ?: $user['username'];
                    $user['user_email'] = $user['staff_email'] ?: $user['email'];
                }
                break;
            case 'finance':
                $user['display_name'] = $user['finance_name'] ?: $user['username'];
                $user['user_email'] = $user['finance_email'] ?: $user['email'];
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
        case 'admin':
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
        header('Location: ../index.php');
        exit();
    }
}

function requireRole($allowed_roles) {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
    if (!in_array($_SESSION['vle_role'], $allowed_roles)) {
        die('Access denied. Insufficient permissions.');
    }
}
?>