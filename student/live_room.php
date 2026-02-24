<?php
/**
 * Student Live Room - Redirects to shared live_room.php
 * URL: student/live_room.php?session_id=X
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$session_id = (int)($_GET['session_id'] ?? 0);
if (!$session_id) {
    header('Location: live_invites.php');
    exit;
}

// Use the shared room page in lecturer/ folder 
// (it handles both lecturer and student roles)
include __DIR__ . '/../lecturer/live_room.php';
