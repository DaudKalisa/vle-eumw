<?php
/**
 * WebRTC Signaling API for VLE Live Classroom
 * 
 * Endpoints (all require auth):
 *   POST ?action=join          - Register peer in session
 *   POST ?action=leave         - Remove peer from session
 *   POST ?action=heartbeat     - Keep-alive ping
 *   POST ?action=signal        - Send WebRTC signal (offer/answer/ice)
 *   GET  ?action=poll_signals  - Poll for incoming signals
 *   GET  ?action=get_peers     - Get all active peers in session
 *   POST ?action=send_chat     - Send chat message
 *   GET  ?action=get_chat      - Get recent chat messages
 *   POST ?action=toggle_media  - Toggle audio/video/screen state
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Cache-Control: no-cache');

ob_start();
require_once '../includes/auth.php';
ob_end_clean();
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_id = (int)$user['user_id'];
$user_name = $user['display_name'] ?? $user['username'] ?? 'Unknown';
$user_role = $_SESSION['vle_role'] ?? 'student';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$session_id = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if (!$session_id && $action !== '') {
    echo json_encode(['success' => false, 'error' => 'Missing session_id']);
    exit;
}

// Clean up stale peers (no heartbeat for 15 seconds)
$conn->query("DELETE FROM vle_session_peers WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 15 SECOND)");
// Clean up old signals (older than 60 seconds)
$conn->query("DELETE FROM vle_webrtc_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)");

switch ($action) {

    // ─── JOIN SESSION ─────────────────────────────────────────────
    case 'join':
        $peer_id = trim($_POST['peer_id'] ?? '');
        if (!$peer_id) {
            echo json_encode(['success' => false, 'error' => 'Missing peer_id']);
            exit;
        }
        
        // Verify session is active
        $stmt = $conn->prepare("SELECT session_id FROM vle_live_sessions WHERE session_id = ? AND status = 'active'");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Session not active']);
            exit;
        }
        
        // Register peer (replace if reconnecting)
        $role = ($user_role === 'lecturer') ? 'lecturer' : 'student';
        $stmt = $conn->prepare("REPLACE INTO vle_session_peers (peer_id, session_id, user_id, user_name, user_role, last_heartbeat, joined_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("siiss", $peer_id, $session_id, $user_id, $user_name, $role);
        $stmt->execute();
        
        // Also update participant record
        $uid_str = (string)$user_id;
        $stmt = $conn->prepare("UPDATE vle_session_participants SET status = 'joined', joined_at = NOW() WHERE session_id = ? AND student_id = ?");
        $stmt->bind_param("is", $session_id, $uid_str);
        $stmt->execute();
        
        // Get existing peers
        $stmt = $conn->prepare("SELECT peer_id, user_id, user_name, user_role, is_audio_on, is_video_on, is_screen_sharing FROM vle_session_peers WHERE session_id = ? AND peer_id != ?");
        $stmt->bind_param("is", $session_id, $peer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $peers = [];
        while ($row = $result->fetch_assoc()) $peers[] = $row;
        
        echo json_encode(['success' => true, 'peers' => $peers]);
        break;

    // ─── LEAVE SESSION ────────────────────────────────────────────
    case 'leave':
        $peer_id = trim($_POST['peer_id'] ?? '');
        if ($peer_id) {
            $stmt = $conn->prepare("DELETE FROM vle_session_peers WHERE peer_id = ?");
            $stmt->bind_param("s", $peer_id);
            $stmt->execute();
            
            // Clean up signals
            $stmt = $conn->prepare("DELETE FROM vle_webrtc_signals WHERE from_peer = ? OR to_peer = ?");
            $stmt->bind_param("ss", $peer_id, $peer_id);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        break;

    // ─── HEARTBEAT ────────────────────────────────────────────────
    case 'heartbeat':
        $peer_id = trim($_POST['peer_id'] ?? '');
        if ($peer_id) {
            $stmt = $conn->prepare("UPDATE vle_session_peers SET last_heartbeat = NOW() WHERE peer_id = ?");
            $stmt->bind_param("s", $peer_id);
            $stmt->execute();
        }
        
        // Check if session is still active
        $status_stmt = $conn->prepare("SELECT status FROM vle_live_sessions WHERE session_id = ?");
        $status_stmt->bind_param("i", $session_id);
        $status_stmt->execute();
        $session_row = $status_stmt->get_result()->fetch_assoc();
        $session_status = $session_row ? $session_row['status'] : 'completed';
        
        // Return current peer count + all peer media states
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vle_session_peers WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
        
        // Get peer media states for all peers in this session
        $peer_states = [];
        $stmt2 = $conn->prepare("SELECT peer_id, user_name, user_role, is_audio_on, is_video_on, is_screen_sharing FROM vle_session_peers WHERE session_id = ?");
        $stmt2->bind_param("i", $session_id);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        while ($row = $result2->fetch_assoc()) {
            $peer_states[] = $row;
        }
        
        echo json_encode(['success' => true, 'peer_count' => (int)$cnt, 'peer_states' => $peer_states, 'session_status' => $session_status]);
        break;

    // ─── SEND SIGNAL (offer/answer/ICE) ───────────────────────────
    case 'signal':
        $peer_id = trim($_POST['peer_id'] ?? '');
        $to_peer = trim($_POST['to_peer'] ?? '');
        $signal_type = trim($_POST['signal_type'] ?? '');
        $signal_data = trim($_POST['signal_data'] ?? '');
        
        if (!$peer_id || !$to_peer || !$signal_type || !$signal_data) {
            echo json_encode(['success' => false, 'error' => 'Missing fields']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO vle_webrtc_signals (session_id, from_peer, to_peer, signal_type, signal_data) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $session_id, $peer_id, $to_peer, $signal_type, $signal_data);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        break;

    // ─── POLL FOR SIGNALS ─────────────────────────────────────────
    case 'poll_signals':
        $peer_id = trim($_GET['peer_id'] ?? '');
        if (!$peer_id) {
            echo json_encode(['success' => false, 'error' => 'Missing peer_id']);
            exit;
        }
        
        // Get unconsumed signals for this peer
        $stmt = $conn->prepare("SELECT signal_id, from_peer, signal_type, signal_data FROM vle_webrtc_signals WHERE to_peer = ? AND session_id = ? AND is_consumed = 0 ORDER BY created_at ASC");
        $stmt->bind_param("si", $peer_id, $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $signals = [];
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $signals[] = $row;
            $ids[] = $row['signal_id'];
        }
        
        // Mark as consumed
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $conn->prepare("UPDATE vle_webrtc_signals SET is_consumed = 1 WHERE signal_id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
        }
        
        echo json_encode(['success' => true, 'signals' => $signals]);
        break;

    // ─── GET ACTIVE PEERS ─────────────────────────────────────────
    case 'get_peers':
        $stmt = $conn->prepare("SELECT peer_id, user_id, user_name, user_role, is_audio_on, is_video_on, is_screen_sharing FROM vle_session_peers WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $peers = [];
        while ($row = $result->fetch_assoc()) $peers[] = $row;
        
        echo json_encode(['success' => true, 'peers' => $peers]);
        break;

    // ─── TOGGLE MEDIA STATE ───────────────────────────────────────
    case 'toggle_media':
        $peer_id = trim($_POST['peer_id'] ?? '');
        $media_type = trim($_POST['media_type'] ?? ''); // audio, video, screen
        $state = (int)($_POST['state'] ?? 0);
        
        $col_map = ['audio' => 'is_audio_on', 'video' => 'is_video_on', 'screen' => 'is_screen_sharing'];
        $col = $col_map[$media_type] ?? null;
        
        if ($peer_id && $col) {
            $stmt = $conn->prepare("UPDATE vle_session_peers SET $col = ? WHERE peer_id = ?");
            $stmt->bind_param("is", $state, $peer_id);
            $stmt->execute();
        }
        
        echo json_encode(['success' => true]);
        break;

    // ─── SEND CHAT MESSAGE ────────────────────────────────────────
    case 'send_chat':
        $message = trim($_POST['message'] ?? '');
        if (!$message) {
            echo json_encode(['success' => false, 'error' => 'Empty message']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO vle_session_chat (session_id, user_id, user_name, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $session_id, $user_id, $user_name, $message);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'chat_id' => $conn->insert_id]);
        break;

    // ─── GET CHAT MESSAGES ────────────────────────────────────────
    case 'get_chat':
        $after_id = (int)($_GET['after_id'] ?? 0);
        
        $stmt = $conn->prepare("SELECT chat_id, user_id, user_name, message, created_at FROM vle_session_chat WHERE session_id = ? AND chat_id > ? ORDER BY created_at ASC LIMIT 100");
        $stmt->bind_param("ii", $session_id, $after_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) $messages[] = $row;
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
