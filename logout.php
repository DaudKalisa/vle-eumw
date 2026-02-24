<?php
// logout.php - Logout for VLE System
require_once 'includes/auth.php';

// Check if this is an automatic logout
$auto_logout = isset($_POST['auto_logout']) || isset($_GET['auto_logout']);

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