<?php
/**
 * API endpoint to extend user session
 * Called by JavaScript when user clicks "Stay Logged In"
 */

require_once '../includes/config.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set content type
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['vle_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Update last activity timestamp
$_SESSION['vle_last_activity'] = time();

// Return success
echo json_encode([
    'success' => true,
    'message' => 'Session extended',
    'expires_in' => SESSION_TIMEOUT
]);
