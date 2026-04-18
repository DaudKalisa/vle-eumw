<?php
/**
 * ICE Server Configuration API
 * 
 * Returns STUN + TURN server configuration for WebRTC peer connections.
 * Used by the live classroom to get dynamic ICE server credentials.
 * 
 * GET /api/ice_config.php
 * Returns: { success: true, iceServers: [...], iceTransportPolicy: "all" }
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

// Require authentication
ob_start();
require_once '../includes/auth.php';
ob_end_clean();
requireLogin();

// Load TURN configuration
require_once '../includes/turn_config.php';

try {
    $config = getIceServerConfig();
    echo json_encode([
        'success' => true,
        'iceServers' => $config['iceServers'],
        'iceTransportPolicy' => $config['iceTransportPolicy']
    ]);
} catch (Exception $e) {
    error_log('ICE config error: ' . $e->getMessage());
    // Return STUN-only as fallback
    echo json_encode([
        'success' => true,
        'iceServers' => [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302']
        ],
        'iceTransportPolicy' => 'all'
    ]);
}
