<?php
// examination_manager/security/update_security_settings.php - Update security settings
require_once '../../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $snapshotInterval = (int)$_POST['snapshot_interval'];
    $maxTabChanges = (int)$_POST['max_tab_changes'];
    $enableFullscreenAlert = isset($_POST['enable_fullscreen_alert']) ? 1 : 0;

    // Validate inputs
    if ($snapshotInterval < 10 || $snapshotInterval > 300) {
        $_SESSION['error'] = 'Snapshot interval must be between 10 and 300 seconds';
        header('Location: index.php');
        exit;
    }

    if ($maxTabChanges < 1 || $maxTabChanges > 10) {
        $_SESSION['error'] = 'Maximum tab changes must be between 1 and 10';
        header('Location: index.php');
        exit;
    }

    // In a real application, you might store these in a settings table
    // For now, we'll just set session variables or use a simple config approach
    $settings = [
        'snapshot_interval' => $snapshotInterval,
        'max_tab_changes' => $maxTabChanges,
        'enable_fullscreen_alert' => $enableFullscreenAlert
    ];

    // Store settings in database (you might want to create a settings table)
    // For now, we'll use a simple approach with file storage
    $settingsFile = __DIR__ . '/security_settings.json';
    file_put_contents($settingsFile, json_encode($settings));

    $_SESSION['success'] = 'Security settings updated successfully';
} else {
    $_SESSION['error'] = 'Invalid request method';
}

header('Location: index.php');
exit;
?>