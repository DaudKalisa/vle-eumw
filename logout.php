<?php
// logout.php - Logout for VLE System
require_once 'includes/auth.php';

// Check if this is an automatic logout
$auto_logout = isset($_POST['auto_logout']) || isset($_GET['auto_logout']);

// End all active live sessions for this lecturer before logging out
if (isLoggedIn() && isset($_SESSION['vle_role']) && $_SESSION['vle_role'] === 'lecturer') {
    try {
        $conn = getDbConnection();
        $lecturer_user_id = (string)$_SESSION['vle_user_id'];
        $stmt = $conn->prepare("UPDATE vle_live_sessions SET status = 'completed', ended_at = NOW() WHERE lecturer_id = ? AND status IN ('active', 'pending')");
        if ($stmt) {
            $stmt->bind_param("s", $lecturer_user_id);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Logout session cleanup error: ' . $e->getMessage());
    }
}

// Record student logout attendance (time spent)
if (isLoggedIn() && isset($_SESSION['vle_role']) && $_SESSION['vle_role'] === 'student' && !empty($_SESSION['vle_login_attendance_id'])) {
    try {
        $conn = getDbConnection();
        $att_id = (int)$_SESSION['vle_login_attendance_id'];
        $stmt = $conn->prepare("UPDATE student_login_attendance SET logout_time = NOW(), duration_minutes = TIMESTAMPDIFF(MINUTE, login_time, NOW()) WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $att_id);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Logout attendance error: ' . $e->getMessage());
    }
}

logout();

// Clear cache headers to prevent back button login issues
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Redirect with timeout parameter if auto-logout
if ($auto_logout) {
    header('Location: login.php?timeout=1');
} else {
    header('Location: login.php');
}
exit();
?>