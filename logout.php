<?php
// logout.php - Logout for VLE System
require_once 'includes/auth.php';

// Check if this is an automatic logout
$auto_logout = isset($_POST['auto_logout']) || isset($_GET['auto_logout']);

logout();

// Redirect with timeout parameter if auto-logout
if ($auto_logout) {
    header('Location: login.php?timeout=1');
} else {
    header('Location: login.php');
}
exit();
?>