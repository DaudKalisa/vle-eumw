<?php
/**
 * Super User Logout
 */
session_start();

// Log the logout
if (isset($_SESSION['super_user_username'])) {
    error_log("Super User Logout: {$_SESSION['super_user_username']} logged out from {$_SERVER['REMOTE_ADDR']}");
}

// Clear super user session variables
unset($_SESSION['super_user_logged_in']);
unset($_SESSION['super_user_id']);
unset($_SESSION['super_user_username']);
unset($_SESSION['super_user_name']);
unset($_SESSION['super_user_login_time']);

// Redirect to login
header('Location: login.php?logged_out=1');
exit;
