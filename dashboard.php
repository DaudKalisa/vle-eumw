<?php
// dashboard.php - Main dashboard for VLE System
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

// Redirect based on role
switch ($user['role']) {
    case 'student':
        header('Location: student/dashboard.php');
        break;
    case 'lecturer':
        header('Location: lecturer/dashboard.php');
        break;
    case 'staff':
        header('Location: admin/dashboard.php');
        break;
    default:
        // For other roles, show a general dashboard or access denied
        header('Location: access_denied.php');
        break;
}
exit();
?>