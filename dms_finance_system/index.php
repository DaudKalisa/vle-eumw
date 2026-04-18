<?php
require_once __DIR__ . '/includes/auth.php';

if (!dmsIsLoggedIn()) {
    header('Location: ' . dmsBaseUrl() . '/login.php');
    exit;
}

$role = $_SESSION['dms_role'] ?? '';
header('Location: ' . dmsBaseUrl() . '/' . dmsRoleDashboard($role));
exit;
