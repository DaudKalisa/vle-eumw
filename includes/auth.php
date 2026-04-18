<?php
// auth.php - Authentication functions for VLE System

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Ensure additional_roles column exists
try {
    $__conn = getDbConnection();
    $__col_check = $__conn->query("SHOW COLUMNS FROM users LIKE 'additional_roles'");
    if ($__col_check && $__col_check->num_rows === 0) {
        $__conn->query("ALTER TABLE users ADD COLUMN additional_roles VARCHAR(255) DEFAULT NULL AFTER role");
    }
    unset($__conn, $__col_check);
} catch (Throwable $e) {
    // Table may not exist yet during setup
}

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
            case 'examination_manager':
            case 'examination_officer':
                // Use examination manager name if available, else fall back to staff name
                if (!empty($user['exam_manager_name'])) {
                    $user['display_name'] = $user['exam_manager_name'];
                    $user['user_email'] = $user['exam_manager_email'] ?: $user['email'];
                } else {
                    $user['display_name'] = $user['staff_name'] ?: $user['username'];
                    $user['user_email'] = $user['staff_email'] ?: $user['email'];
                }
                break;
            case 'odl_coordinator':
                $user['display_name'] = $user['lecturer_name'] ?: $user['username'];
                $user['user_email'] = $user['lecturer_email'] ?: $user['email'];
                break;
            case 'research_coordinator':
                $user['display_name'] = $user['staff_name'] ?: $user['username'];
                $user['user_email'] = $user['staff_email'] ?: $user['email'];
                break;
            case 'exam_clearance_student':
                $user['display_name'] = $user['username'];
                $user['user_email'] = $user['email'];
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

    // Check if input is username, email, or student ID number
    // First try username or email match
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    if (!$stmt) {
        error_log("Login prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'Login service error. DB: ' . $conn->error];
    }
    $stmt->bind_param("ss", $username_email, $username_email);
    $stmt->execute();
    $result = $stmt->get_result();

    // If no match, try looking up by student_id in students table
    if ($result->num_rows === 0) {
        $sid_stmt = $conn->prepare(
            "SELECT u.* FROM users u
             INNER JOIN students s ON u.related_student_id = s.student_id
             WHERE s.student_id = ?
             LIMIT 1"
        );
        if ($sid_stmt) {
            $sid_stmt->bind_param("s", $username_email);
            $sid_stmt->execute();
            $result = $sid_stmt->get_result();
            $sid_stmt->close();
        }
    } else {
        $stmt->close();
    }

    // If still no match, try looking up by student_id in exam_clearance_students table
    if ($result->num_rows === 0) {
        $ec_stmt = $conn->prepare(
            "SELECT u.* FROM users u
             INNER JOIN exam_clearance_students ecs ON u.email = ecs.email
             WHERE ecs.student_id = ? AND u.role = 'exam_clearance_student'
             LIMIT 1"
        );
        if ($ec_stmt) {
            $ec_stmt->bind_param("s", $username_email);
            $ec_stmt->execute();
            $result = $ec_stmt->get_result();
            $ec_stmt->close();
        }
    }

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Check if account is active
        if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
            return ['success' => false, 'message' => 'Your account is pending approval. Please wait for administrator activation.'];
        }

        if (password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['vle_user_id'] = $user['user_id'];
            $_SESSION['vle_username'] = $user['username'];
            $_SESSION['vle_role'] = $user['role'];
            $_SESSION['vle_additional_roles'] = !empty($user['additional_roles']) ? $user['additional_roles'] : '';
            $_SESSION['vle_related_id'] = getRelatedId($user);
            $_SESSION['vle_last_activity'] = time(); // Set initial activity time
            $_SESSION['vle_login_time'] = time(); // Track login time

            return ['success' => true, 'user' => $user];
        }
    }

    return ['success' => false, 'message' => 'Invalid username/email or password'];
}

function getRelatedId($user) {
    switch ($user['role'] ?? '') {
        case 'student':
        case 'dissertation_student':
        case 'graduation_student':
        case 'exam_clearance_student':
            return $user['related_student_id'] ?? null;
        case 'lecturer':
        case 'odl_coordinator':
            return $user['related_lecturer_id'] ?? null;
        case 'admin':
        case 'staff':
            return $user['related_staff_id'] ?? null;
        case 'hod':
            return $user['related_hod_id'] ?? ($user['related_staff_id'] ?? null);
        case 'dean':
            return $user['related_dean_id'] ?? ($user['related_staff_id'] ?? null);
        case 'finance':
            return $user['related_finance_id'] ?? null;
        case 'examination_manager':
        case 'examination_officer':
            // examination_managers joined via related_staff_id = em.manager_id
            return $user['related_staff_id'] ?? null;
        case 'research_coordinator':
            return $user['related_staff_id'] ?? null;
        default:
            return null;
    }
}

function logout() {
    // Mark that session expired (used by requireLogin to detect timeout vs first visit)
    if (isset($_SESSION['vle_user_id'])) {
        $_SESSION['vle_session_expired'] = true;
    }
    session_unset();
    session_destroy();
}


function requireLogin() {
    if (!isLoggedIn()) {
        // Get the base path
        $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
        
        // Capture the requested page for redirect after login
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        // Extract relative path (e.g., "student/messages.php?message_id=5")
        $path = preg_replace('#^.*/vle-eumw/#', '', $request_uri);
        $redirect_param = !empty($path) ? '?redirect_to=' . urlencode($path) : '';
        
        // Check if this was a session timeout (session was active before)
        if (isset($_SESSION['vle_session_expired']) || (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['vle_user_id']))) {
            header('Location: ' . $base . 'login.php?timeout=1' . (!empty($path) ? '&redirect_to=' . urlencode($path) : ''));
        } else {
            header('Location: ' . $base . 'login.php' . $redirect_param);
        }
        exit();
    }
}

/**
 * Get the dashboard URL for the current user's role
 */
function getUserDashboardUrl() {
    $role = $_SESSION['vle_role'] ?? '';
    $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';

    // Graduation students have a dedicated portal.
    if (hasRole('graduation_student')) {
        return $base . 'graduation_student/dashboard.php';
    }

    // Dissertation students land on the student dashboard.
    if (hasRole('dissertation_student')) {
        return $base . 'student/dashboard.php';
    }

    // Exam clearance students land on the student dashboard.
    if (hasRole('exam_clearance_student')) {
        return $base . 'student/dashboard.php';
    }

    switch ($role) {
        case 'student': return $base . 'student/dashboard.php';
        case 'lecturer': return $base . 'lecturer/dashboard.php';
        case 'finance': return $base . 'finance/dashboard.php';
        case 'admin': return $base . 'admin/dashboard.php';
        case 'staff': return $base . 'admin/dashboard.php';
        case 'hod': return $base . 'hod/dashboard.php';
        case 'dean': return $base . 'dean/dashboard.php';
        case 'odl_coordinator': return $base . 'odl_coordinator/dashboard.php';
        case 'examination_manager': return $base . 'examination_manager/dashboard.php';
        case 'examination_officer': return $base . 'examination_officer/dashboard.php';
        case 'research_coordinator': return $base . 'research_coordinator/dashboard.php';
        default: return $base . 'dashboard.php';
    }
}

function requireRole($allowed_roles) {
    if (!isLoggedIn()) {
        $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
        // Capture requested page for redirect after login
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = preg_replace('#^.*/vle-eumw/#', '', $request_uri);
        $redirect_param = !empty($path) ? '?redirect_to=' . urlencode($path) : '';
        header('Location: ' . $base . 'login.php' . $redirect_param);
        exit();
    }

    // Graduation-only students can only access graduation_student/ pages.
    if (hasRole('graduation_student')) {
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $is_grad_page = strpos($script_name, '/graduation_student/') !== false;
        $is_profile_page = basename($script_name) === 'profile.php';
        if (!$is_grad_page && !$is_profile_page) {
            $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
            header('Location: ' . $base . 'graduation_student/dashboard.php');
            exit();
        }
    }

    // Exam clearance students can access exam clearance + shared pages.
    if (hasRole('exam_clearance_student')) {
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $page = basename($script_name);
        $is_examination_page = strpos($script_name, '/examination/') !== false;
        $allowed_ec_pages = [
            'dashboard.php',
            'exam_clearance.php',
            'exam_clearance_certificate.php',
            'profile.php',
            'change_password.php',
            'payment_history.php',
            'announcements.php',
            'messages.php',
            'resources.php',
            'help.php'
        ];
        if (!in_array($page, $allowed_ec_pages, true) && !$is_examination_page) {
            $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
            header('Location: ' . $base . 'student/dashboard.php');
            exit();
        }
    }

    // Dissertation-only students can access dissertation workflow + shared pages.
    if (hasRole('dissertation_student')) {
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $is_student_page = strpos($script_name, '/student/') !== false;
        $is_examination_page = strpos($script_name, '/examination/') !== false;
        if ($is_student_page) {
            $student_page = basename($script_name);
            $allowed_dissertation_pages = [
                'dashboard.php',
                'dissertation.php',
                'dissertation_guidelines.php',
                'ethics_form_online.php',
                'exam_clearance.php',
                'exam_clearance_certificate.php',
                'profile.php',
                'help.php',
                'change_password.php',
                'payment_history.php',
                'announcements.php',
                'messages.php',
                'resources.php'
            ];
            if (!in_array($student_page, $allowed_dissertation_pages, true)) {
                $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
                header('Location: ' . $base . 'student/dashboard.php');
                exit();
            }
        } elseif (!$is_examination_page) {
            // Allow examination pages, redirect others
            $base = str_contains($_SERVER['SCRIPT_NAME'], '/') ? '../' : '';
            header('Location: ' . $base . 'student/dashboard.php');
            exit();
        }
    }

    if (!hasRole($allowed_roles)) {
        // Redirect to user's dashboard instead of access_denied page
        // User is logged in but doesn't have the right role — send to their dashboard
        $dashboard_url = getUserDashboardUrl();
        header('Location: ' . $dashboard_url);
        exit();
    }
}

/**
 * Check if the current user has any of the specified roles.
 * Checks both primary role and additional_roles.
 * @param string|array $roles Role or array of roles to check
 * @return bool
 */
function hasRole($roles) {
    if (!isset($_SESSION['vle_role'])) return false;
    if (is_string($roles)) $roles = [$roles];
    
    // Check primary role
    if (in_array($_SESSION['vle_role'], $roles)) return true;
    
    // Check additional roles
    $additional = $_SESSION['vle_additional_roles'] ?? '';
    if (!empty($additional)) {
        $extra_roles = array_map('trim', explode(',', $additional));
        foreach ($extra_roles as $extra) {
            if (in_array($extra, $roles)) return true;
        }
    }
    return false;
}

/**
 * Get all roles for the current user (primary + additional).
 * @return array
 */
function getAllUserRoles() {
    $roles = [];
    if (isset($_SESSION['vle_role'])) {
        $roles[] = $_SESSION['vle_role'];
    }
    $additional = $_SESSION['vle_additional_roles'] ?? '';
    if (!empty($additional)) {
        $extra = array_map('trim', explode(',', $additional));
        $roles = array_unique(array_merge($roles, $extra));
    }
    return $roles;
}

/**
 * Get the related ID for a specific role context.
 * Useful for multi-role users accessing dashboards for their secondary roles.
 * 
 * @param string $role_context The role to get the related ID for (e.g., 'lecturer', 'student', 'staff')
 * @return string|int|null The related ID or null if not found
 */
function getRelatedIdForRole($role_context) {
    $user = getCurrentUser();
    if (!$user) return null;
    
    switch ($role_context) {
        case 'student':
            return $user['related_student_id'] ?? null;
        case 'lecturer':
            return $user['related_lecturer_id'] ?? null;
        case 'admin':
        case 'staff':
            return $user['related_staff_id'] ?? null;
        case 'hod':
            return $user['related_hod_id'] ?? ($user['related_staff_id'] ?? null);
        case 'dean':
            return $user['related_dean_id'] ?? ($user['related_staff_id'] ?? null);
        case 'finance':
            return $user['related_finance_id'] ?? ($user['related_lecturer_id'] ?? null);
        case 'examination_manager':
        case 'examination_officer':
            // Examination managers/officers joined via related_staff_id = em.manager_id
            return $user['related_staff_id'] ?? ($user['related_lecturer_id'] ?? null);
        case 'odl_coordinator':
            // ODL coordinators are typically lecturers
            return $user['related_lecturer_id'] ?? null;
        case 'research_coordinator':
            return $user['related_staff_id'] ?? null;
        default:
            return null;
    }
}
?>