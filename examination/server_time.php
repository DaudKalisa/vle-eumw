<?php
/**
 * Server Time Endpoint - Returns current server timestamp
 * Used by exam timer to stay synced with server clock
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once '../includes/auth.php';
requireLogin();

echo json_encode(['server_time' => time()]);
