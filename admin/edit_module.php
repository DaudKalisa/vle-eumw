<?php
// edit_module.php - Redirect to manage_modules.php for editing
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin']);

// Redirect to module management with the module ID if provided
$module_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
header('Location: manage_modules.php' . ($module_id ? '?edit=' . $module_id : ''));
exit;
