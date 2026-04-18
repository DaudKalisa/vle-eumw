<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . dmsBaseUrl() . '/login.php');
    exit;
}

$usernameEmail = trim($_POST['username_email'] ?? '');
$password = $_POST['password'] ?? '';

$result = dmsLogin($usernameEmail, $password);
if (!$result['success']) {
    $_SESSION['dms_flash_error'] = $result['message'] ?? 'Login failed.';
    header('Location: ' . dmsBaseUrl() . '/login.php');
    exit;
}

$role = $_SESSION['dms_role'] ?? '';
header('Location: ' . dmsBaseUrl() . '/' . dmsRoleDashboard($role));
exit;
