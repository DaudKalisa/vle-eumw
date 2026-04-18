<?php
/**
 * Verify System Tool Password (File Manager & Database Manager)
 * AJAX endpoint — returns JSON. Sets session flag on success.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);

header('Content-Type: application/json');

$password = $_POST['password'] ?? '';
$tool     = $_POST['tool'] ?? '';

// Only allow known tools
$allowed_tools = ['file_manager.php', 'database_manager.php'];
if (!in_array($tool, $allowed_tools, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid tool.']);
    exit;
}

// Hardcoded password — same for both tools
$correct_password = 'Adm!n@FileManager2024';

if ($password === $correct_password) {
    // Set session flags so the tool pages accept the user
    $_SESSION['file_manager_authorized'] = true;
    $_SESSION['file_manager_auth_time']  = time();
    $_SESSION['db_manager_authorized']   = true;
    $_SESSION['db_manager_auth_time']    = time();

    $user = getCurrentUser();
    error_log("System Tools: Access granted to {$tool} by user {$user['username']} from {$_SERVER['REMOTE_ADDR']}");

    echo json_encode(['success' => true]);
} else {
    $user = getCurrentUser();
    error_log("System Tools: Failed password attempt for {$tool} by user {$user['username']} from {$_SERVER['REMOTE_ADDR']}");
    echo json_encode(['success' => false, 'error' => 'Invalid password. Access denied.']);
}
