<?php
// login_process.php - Process login for VLE System with security features

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/auth.php';
require_once 'includes/email.php';

// Security settings
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 30); // minutes

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

// Get connection
$conn = getDbConnection();

// Get client info
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$device_info = getDeviceInfo($user_agent);

// Check if account is locked
try {
    if (isAccountLocked($conn, $username_email)) {
        $remaining = getRemainingLockTime($conn, $username_email);
        $_SESSION['login_error'] = "Account is temporarily locked due to too many failed attempts. Please try again in $remaining minutes.";
        logLoginAttempt($conn, $username_email, $ip_address, $user_agent, false);
        header('Location: login.php');
        exit();
    }
} catch (Throwable $e) {
    // Log error but don't block login if security check fails
    error_log("Account lock check error: " . $e->getMessage());
}

// Attempt login
try {
    $result = login($username_email, $password);
    
    if ($result['success']) {
        // Successful login
        clearLoginAttempts($conn, $username_email);
        
        // Log successful login
        logLoginAttempt($conn, $username_email, $ip_address, $user_agent, true);
        $login_history_id = logLoginHistory($conn, $result['user']['user_id'], $ip_address, $user_agent, $device_info);
        $_SESSION['login_history_id'] = $login_history_id;
        
        // Update user's last login info
        updateLastLogin($conn, $result['user']['user_id'], $ip_address);
        
        // Check for suspicious login (new IP or device)
        if (isSuspiciousLogin($conn, $result['user']['user_id'], $ip_address, $device_info)) {
            // Send login alert email
            if (isEmailEnabled()) {
                $user_email = $result['user']['email'];
                $user_name = $result['user']['username'];
                
                // Get display name from related tables
                if ($result['user']['role'] === 'student' && $result['user']['related_student_id']) {
                    $stmt = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
                    $stmt->bind_param("i", $result['user']['related_student_id']);
                    $stmt->execute();
                    $student = $stmt->get_result()->fetch_assoc();
                    if ($student) {
                        $user_name = $student['full_name'];
                        $user_email = $student['email'];
                    }
                } elseif ($result['user']['role'] === 'lecturer' && $result['user']['related_lecturer_id']) {
                    $stmt = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
                    $stmt->bind_param("i", $result['user']['related_lecturer_id']);
                    $stmt->execute();
                    $lecturer = $stmt->get_result()->fetch_assoc();
                    if ($lecturer) {
                        $user_name = $lecturer['full_name'];
                        $user_email = $lecturer['email'];
                    }
                }
                
                sendLoginAlertEmail($user_email, $user_name, $ip_address, $device_info);
            }
            
            // Mark login as suspicious in history
            if ($login_history_id) {
                $conn->query("UPDATE login_history SET is_suspicious = 1 WHERE id = $login_history_id");
            }
        }
        
        // Clear any previous error messages
        unset($_SESSION['login_error']);

        // Enforce password change at first login or if required
        if (!empty($result['user']['must_change_password']) && $result['user']['must_change_password']) {
            $_SESSION['force_password_change'] = true;
            header('Location: change_password.php?first=1');
            exit();
        }

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
            case 'admin':
                $redirect_url = 'admin/dashboard.php';
                break;
            case 'staff':
            case 'hod':
            case 'dean':
                // Check if this staff user is an examination officer
                $redirect_url = 'admin/dashboard.php';
                if (!empty($result['user']['related_staff_id'])) {
                    $em_check = $conn->prepare("SELECT manager_id FROM examination_managers WHERE manager_id = ? AND is_active = 1");
                    $em_check->bind_param("i", $result['user']['related_staff_id']);
                    $em_check->execute();
                    if ($em_check->get_result()->num_rows > 0) {
                        $redirect_url = 'examination_officer/dashboard.php';
                    }
                }
                break;
            default:
                $redirect_url = 'dashboard.php';
        }
        header('Location: ' . $redirect_url);
        exit();
    } else {
        // Login failed - record attempt
        logLoginAttempt($conn, $username_email, $ip_address, $user_agent, false);
        incrementFailedAttempts($conn, $username_email);
        
        // Check if should lock account
        $failed_count = getFailedAttemptCount($conn, $username_email);
        if ($failed_count >= MAX_LOGIN_ATTEMPTS) {
            lockAccount($conn, $username_email);
            
            // Send account locked email
            if (isEmailEnabled()) {
                $user_info = getUserByUsernameEmail($conn, $username_email);
                if ($user_info) {
                    sendAccountLockedEmail($user_info['email'], $user_info['name'], $failed_count, LOCKOUT_DURATION . ' minutes');
                }
            }
            
            $_SESSION['login_error'] = "Your account has been locked due to " . MAX_LOGIN_ATTEMPTS . " failed login attempts. Please try again in " . LOCKOUT_DURATION . " minutes.";
        } else {
            $remaining = MAX_LOGIN_ATTEMPTS - $failed_count;
            $_SESSION['login_error'] = 'Invalid username/email or password. ' . $remaining . ' attempts remaining.';
        }
        
        header('Location: login.php');
        exit();
    }
} catch (Throwable $e) {
    // Log error for debugging (catch both Exception and Error types)
    error_log("Login error: " . $e->getMessage());
    logLoginAttempt($conn, $username_email, $ip_address, $user_agent, false);
    
    $_SESSION['login_error'] = 'An error occurred during login. Please try again.';
    header('Location: login.php');
    exit();
}

// ============================================================================
// Helper Functions
// ============================================================================

function getDeviceInfo($user_agent) {
    $device = 'Unknown Device';
    
    if (preg_match('/windows/i', $user_agent)) {
        $device = 'Windows PC';
    } elseif (preg_match('/macintosh|mac os x/i', $user_agent)) {
        $device = 'Mac';
    } elseif (preg_match('/linux/i', $user_agent)) {
        $device = 'Linux';
    } elseif (preg_match('/iphone/i', $user_agent)) {
        $device = 'iPhone';
    } elseif (preg_match('/ipad/i', $user_agent)) {
        $device = 'iPad';
    } elseif (preg_match('/android/i', $user_agent)) {
        $device = 'Android';
    }
    
    // Detect browser
    if (preg_match('/chrome/i', $user_agent) && !preg_match('/edge/i', $user_agent)) {
        $device .= ' (Chrome)';
    } elseif (preg_match('/safari/i', $user_agent) && !preg_match('/chrome/i', $user_agent)) {
        $device .= ' (Safari)';
    } elseif (preg_match('/firefox/i', $user_agent)) {
        $device .= ' (Firefox)';
    } elseif (preg_match('/edge/i', $user_agent)) {
        $device .= ' (Edge)';
    } elseif (preg_match('/msie|trident/i', $user_agent)) {
        $device .= ' (Internet Explorer)';
    }
    
    return $device;
}

function isAccountLocked($conn, $username_email) {
    // Check if account_locked_until column exists in users table
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked_until'");
    if ($column_check && $column_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT account_locked_until FROM users WHERE (username = ? OR email = ?) AND account_locked_until > NOW()");
        if ($stmt) {
            $stmt->bind_param("ss", $username_email, $username_email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                return true;
            }
        }
    }
    
    // Also check account_locks table if it exists
    $table_check = $conn->query("SHOW TABLES LIKE 'account_locks'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT id FROM account_locks WHERE username_email = ? AND unlocked_at IS NULL AND (unlock_scheduled IS NULL OR unlock_scheduled > NOW())");
        if ($stmt) {
            $stmt->bind_param("s", $username_email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                return true;
            }
        }
    }
    
    return false;
}

function getRemainingLockTime($conn, $username_email) {
    // Check if account_locked_until column exists
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked_until'");
    if ($column_check && $column_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(MINUTE, NOW(), account_locked_until) as remaining FROM users WHERE (username = ? OR email = ?) AND account_locked_until > NOW()");
        if ($stmt) {
            $stmt->bind_param("ss", $username_email, $username_email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                return max(1, $row['remaining']);
            }
        }
    }
    
    return LOCKOUT_DURATION;
}

function logLoginAttempt($conn, $username_email, $ip_address, $user_agent, $success) {
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($table_check->num_rows == 0) {
        return;
    }
    
    $stmt = $conn->prepare("INSERT INTO login_attempts (username_email, ip_address, user_agent, success) VALUES (?, ?, ?, ?)");
    $success_int = $success ? 1 : 0;
    $stmt->bind_param("sssi", $username_email, $ip_address, $user_agent, $success_int);
    $stmt->execute();
}

function logLoginHistory($conn, $user_id, $ip_address, $user_agent, $device_info) {
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'login_history'");
    if ($table_check->num_rows == 0) {
        return null;
    }
    
    $stmt = $conn->prepare("INSERT INTO login_history (user_id, ip_address, user_agent, device_info) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $ip_address, $user_agent, $device_info);
    $stmt->execute();
    
    return $conn->insert_id;
}

function updateLastLogin($conn, $user_id, $ip_address) {
    // Check if columns exist
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($column_check->num_rows == 0) {
        return;
    }
    
    $stmt = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE user_id = ?");
    $stmt->bind_param("si", $ip_address, $user_id);
    $stmt->execute();
}

function isSuspiciousLogin($conn, $user_id, $ip_address, $device_info) {
    // Check if login_history table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'login_history'");
    if ($table_check->num_rows == 0) {
        return false;
    }
    
    // Check if this IP/device combination has been used before by this user
    $stmt = $conn->prepare("SELECT id FROM login_history WHERE user_id = ? AND (ip_address = ? OR device_info = ?) AND login_time < NOW() LIMIT 1");
    $stmt->bind_param("iss", $user_id, $ip_address, $device_info);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If no previous login from this IP/device, it's potentially suspicious
    // But only if user has logged in before (not first login)
    $stmt2 = $conn->prepare("SELECT COUNT(*) as cnt FROM login_history WHERE user_id = ? AND login_time < NOW()");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $count = $stmt2->get_result()->fetch_assoc()['cnt'];
    
    // Suspicious if: has previous logins but not from this IP/device
    return ($count > 1 && $result->num_rows == 0);
}

function clearLoginAttempts($conn, $username_email) {
    // Reset failed attempts in users table
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_login_attempts'");
    if ($column_check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL, account_locked_until = NULL WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_email, $username_email);
        $stmt->execute();
    }
}

function incrementFailedAttempts($conn, $username_email) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_login_attempts'");
    if ($column_check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = NOW() WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_email, $username_email);
        $stmt->execute();
    }
}

function getFailedAttemptCount($conn, $username_email) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'failed_login_attempts'");
    if ($column_check->num_rows == 0) {
        return 0;
    }
    
    $stmt = $conn->prepare("SELECT failed_login_attempts FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username_email, $username_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return (int)$row['failed_login_attempts'];
    }
    
    return 0;
}

function lockAccount($conn, $username_email) {
    $unlock_time = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_DURATION . ' minutes'));
    
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked_until'");
    if ($column_check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE users SET account_locked_until = ? WHERE username = ? OR email = ?");
        $stmt->bind_param("sss", $unlock_time, $username_email, $username_email);
        $stmt->execute();
    }
    
    // Also log in account_locks table if exists
    $table_check = $conn->query("SHOW TABLES LIKE 'account_locks'");
    if ($table_check->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO account_locks (username_email, lock_reason, unlock_scheduled) VALUES (?, 'Too many failed login attempts', ?)");
        $stmt->bind_param("ss", $username_email, $unlock_time);
        $stmt->execute();
    }
}

function getUserByUsernameEmail($conn, $username_email) {
    // First try users table
    $stmt = $conn->prepare("SELECT u.user_id, u.email, u.role, u.related_student_id, u.related_lecturer_id FROM users u WHERE u.username = ? OR u.email = ?");
    $stmt->bind_param("ss", $username_email, $username_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $name = $username_email;
        $email = $user['email'];
        
        // Get display name based on role
        if ($user['role'] === 'student' && $user['related_student_id']) {
            $stmt2 = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
            $stmt2->bind_param("i", $user['related_student_id']);
            $stmt2->execute();
            if ($student = $stmt2->get_result()->fetch_assoc()) {
                $name = $student['full_name'];
                $email = $student['email'];
            }
        } elseif ($user['related_lecturer_id']) {
            $stmt2 = $conn->prepare("SELECT full_name, email FROM lecturers WHERE lecturer_id = ?");
            $stmt2->bind_param("i", $user['related_lecturer_id']);
            $stmt2->execute();
            if ($lecturer = $stmt2->get_result()->fetch_assoc()) {
                $name = $lecturer['full_name'];
                $email = $lecturer['email'];
            }
        }
        
        return ['name' => $name, 'email' => $email];
    }
    
    return null;
}
?>