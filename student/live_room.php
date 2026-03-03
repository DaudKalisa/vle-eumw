<?php
/**
 * Student Live Room - Standalone WebRTC Video Conference
 * Independent student-side application for joining live sessions.
 * Connects to the same signaling server as the lecturer portal.
 * Works across different networks, locations, and devices.
 * URL: student/live_room.php?session_id=X
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

// Load TURN server configuration for cross-network connectivity
require_once '../includes/turn_config.php';

$conn = getDbConnection();
$user = getCurrentUser();
$user_id = (int)$user['user_id'];
$user_name = htmlspecialchars($user['display_name'] ?? $user['username'] ?? 'Unknown');
$user_role = 'student';
$session_id = (int)($_GET['session_id'] ?? 0);

if (!$session_id) {
    header('Location: live_invites.php');
    exit;
}

// Verify session exists and is active
$stmt = $conn->prepare("SELECT vls.*, vc.course_name, vc.course_code,
    CONCAT(l.first_name, ' ', l.last_name) as lecturer_name
    FROM vle_live_sessions vls
    JOIN vle_courses vc ON vls.course_id = vc.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    WHERE vls.session_id = ? AND vls.status = 'active'");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Session Not Available</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:#0f0f0f;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;font-family:"Segoe UI",system-ui,sans-serif;}</style>
    </head><body><div class="text-center"><i class="bi bi-camera-video-off" style="font-size:4rem;color:#666;"></i>
    <h3 class="mt-3">Session Not Available</h3>
    <p class="text-muted">This live session has ended or does not exist.</p>
    <a href="live_invites.php" class="btn btn-primary mt-2"><i class="bi bi-arrow-left me-1"></i> Back to Sessions</a>
    </div></body></html>';
    exit;
}

$lecturer_name = $session['lecturer_name'] ?? 'Lecturer';
$is_host = false;

// Generate ICE server configuration (includes TURN for cross-network support)
$iceConfig = getIceServerConfig();
$iceConfigJson = json_encode($iceConfig);

// Get peer count
$stmt2 = $conn->prepare("SELECT COUNT(*) as cnt FROM vle_session_peers WHERE session_id = ?");
$stmt2->bind_param("i", $session_id);
$stmt2->execute();
$peer_count = $stmt2->get_result()->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Live Class: <?= htmlspecialchars($session['session_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f0f; color: #fff; font-family: 'Segoe UI', system-ui, sans-serif; overflow: hidden; height: 100vh; }

        /* ── TOP BAR ── */
        .room-topbar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 8px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #2a2a4a;
            height: 50px;
            z-index: 100;
        }
        .room-topbar .session-info { display: flex; align-items: center; gap: 12px; }
        .room-topbar .live-dot {
            width: 10px; height: 10px; background: #ff4444; border-radius: 50%;
            animation: livePulse 1.5s infinite;
        }
        @keyframes livePulse {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(255,68,68,0.7); }
            50% { opacity: 0.7; box-shadow: 0 0 0 6px rgba(255,68,68,0); }
        }
        .room-topbar .session-title { font-weight: 600; font-size: 14px; }
        .room-topbar .session-course { font-size: 12px; color: #8888aa; }
        .room-topbar .peer-count {
            background: #2a2a4a; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; color: #aaa;
        }

        /* ── MAIN LAYOUT ── */
        .room-main { display: flex; height: calc(100vh - 50px - 70px); }

        /* Speaker area */
        .speaker-area {
            flex: 1; display: flex; align-items: center; justify-content: center;
            padding: 10px; position: relative; background: #0a0a1a; min-width: 0;
        }
        .speaker-area .video-tile { width: 100%; height: 100%; max-width: 100%; max-height: 100%; }
        .speaker-area .video-tile video {
            width: 100%; height: 100%; object-fit: contain; border-radius: 12px; background: #000;
        }
        .speaker-area .speaker-placeholder {
            text-align: center; color: #555; font-size: 18px;
        }
        .speaker-area .speaker-placeholder i { font-size: 64px; display: block; margin-bottom: 16px; color: #333; }

        /* Filmstrip */
        .filmstrip {
            width: 220px; min-width: 220px; background: #12122a; border-left: 1px solid #2a2a4a;
            display: flex; flex-direction: column; gap: 6px; padding: 8px 6px;
            overflow-y: auto; overflow-x: hidden;
        }
        .filmstrip .video-tile {
            width: 100%; height: 130px; min-height: 110px; flex-shrink: 0;
            cursor: pointer; border: 2px solid transparent; transition: border-color 0.2s, transform 0.15s;
        }
        .filmstrip .video-tile:hover { border-color: #667eea; transform: scale(1.02); }
        .filmstrip .video-tile.active-speaker { border-color: #2ecc71; }
        .filmstrip .video-tile video { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }

        /* Shared tile styles */
        .video-tile {
            position: relative; background: #1a1a2e; border-radius: 12px; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
        }
        .video-tile .tile-label {
            position: absolute; bottom: 8px; left: 8px; background: rgba(0,0,0,0.6);
            padding: 4px 10px; border-radius: 6px; font-size: 12px;
            display: flex; align-items: center; gap: 6px;
        }
        .video-tile .tile-label .host-badge {
            background: #e74c3c; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 700;
        }
        .video-tile .tile-avatar {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 32px; font-weight: 700; position: absolute; z-index: 3;
        }
        .filmstrip .video-tile .tile-avatar { width: 48px; height: 48px; font-size: 20px; }

        /* Tile fullscreen button */
        .video-tile .tile-fullscreen-btn {
            position: absolute; bottom: 8px; right: 8px;
            background: rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; border-radius: 8px; width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
            cursor: pointer; opacity: 0; transition: opacity 0.2s, background 0.2s; z-index: 10;
        }
        .video-tile:hover .tile-fullscreen-btn { opacity: 1; }
        .video-tile .tile-fullscreen-btn:hover { background: rgba(102,126,234,0.8); }
        .video-tile:-webkit-full-screen, .video-tile:fullscreen {
            width: 100vw !important; height: 100vh !important; background: #000; border-radius: 0; z-index: 99999;
        }
        .video-tile:-webkit-full-screen video, .video-tile:fullscreen video {
            width: 100%; height: 100%; object-fit: contain; border-radius: 0;
        }
        .video-tile:-webkit-full-screen .tile-fullscreen-btn, .video-tile:fullscreen .tile-fullscreen-btn {
            opacity: 0; bottom: 20px; right: 20px; width: 44px; height: 44px; font-size: 20px;
        }
        .video-tile:-webkit-full-screen:hover .tile-fullscreen-btn, .video-tile:fullscreen:hover .tile-fullscreen-btn { opacity: 1; }

        /* Peer indicators */
        .video-tile .tile-indicators {
            position: absolute; top: 8px; right: 8px; display: flex; gap: 5px; z-index: 5;
        }
        .video-tile .indicator-badge {
            width: 30px; height: 30px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: #fff; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        }
        .indicator-badge.mic-off { background: rgba(231,76,60,0.85); }
        .indicator-badge.cam-off { background: rgba(155,89,182,0.8); }
        .indicator-badge.screen-on { background: rgba(46,204,113,0.85); }

        /* Self-view PiP */
        .self-pip {
            position: fixed; bottom: 84px; right: 16px; width: 180px; height: 135px;
            z-index: 500; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border: 2px solid #667eea; cursor: move; overflow: hidden; background: #1a1a2e;
        }
        .self-pip video { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
        .self-pip .self-pip-label {
            position: absolute; bottom: 4px; left: 6px; background: rgba(0,0,0,0.6);
            padding: 2px 8px; border-radius: 4px; font-size: 10px;
        }
        .self-pip .tile-avatar { width: 48px; height: 48px; font-size: 20px; }
        .self-pip .self-pip-close {
            position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.6);
            border: none; color: #fff; border-radius: 50%; width: 24px; height: 24px;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            font-size: 12px; opacity: 0; transition: opacity 0.2s; z-index: 10;
        }
        .self-pip:hover .self-pip-close { opacity: 1; }

        /* Chat sidebar */
        .sidebar {
            width: 320px; background: #1a1a2e; border-left: 1px solid #2a2a4a;
            display: flex; flex-direction: column; transition: width 0.3s;
        }
        .sidebar.collapsed { width: 0; overflow: hidden; border: none; }
        .sidebar-header {
            padding: 12px 16px; border-bottom: 1px solid #2a2a4a; font-weight: 600; font-size: 14px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .chat-messages { flex: 1; overflow-y: auto; padding: 12px; }
        .chat-msg { margin-bottom: 12px; }
        .chat-msg .chat-author { font-size: 12px; font-weight: 600; color: #667eea; }
        .chat-msg .chat-author.host { color: #e74c3c; }
        .chat-msg .chat-text { font-size: 13px; color: #ccc; margin-top: 2px; word-break: break-word; }
        .chat-msg .chat-time { font-size: 10px; color: #666; }
        .chat-input-area {
            padding: 12px; border-top: 1px solid #2a2a4a; display: flex; gap: 8px;
        }
        .chat-input-area input {
            flex: 1; background: #0f0f0f; border: 1px solid #2a2a4a; color: #fff;
            border-radius: 8px; padding: 8px 12px; font-size: 13px;
        }
        .chat-input-area button {
            background: #667eea; border: none; color: #fff; border-radius: 8px;
            padding: 8px 14px; cursor: pointer;
        }

        /* Bottom controls */
        .room-controls {
            height: 70px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-top: 1px solid #2a2a4a;
            display: flex; align-items: center; justify-content: center; gap: 16px;
            padding: 0 20px; position: relative; z-index: 9999;
        }
        .ctrl-btn {
            width: 48px; height: 48px; border-radius: 50%; border: none;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; cursor: pointer; transition: all 0.2s; color: #fff; z-index: 10000;
        }
        .ctrl-btn:hover { transform: scale(1.1); }
        .ctrl-btn.on { background: #2a2a4a; }
        .ctrl-btn.off { background: #e74c3c; }
        .ctrl-btn.screen { background: #2ecc71; }
        .ctrl-btn.screen.off { background: #2a2a4a; }
        .ctrl-btn.chat-toggle { background: #667eea; }
        .ctrl-btn.leave-call { background: #e74c3c; width: 56px; height: 56px; font-size: 24px; }
        .ctrl-btn.leave-call:hover { background: #c0392b; }
        .ctrl-btn.fullscreen { background: #2a2a4a; }
        .ctrl-btn.fullscreen.active { background: #f39c12; }

        /* Two-way audio badge */
        .twoway-badge {
            background: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.4);
            color: #2ecc71; padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;
        }
        .twoway-badge .tw-dot {
            width: 6px; height: 6px; background: #2ecc71; border-radius: 50%;
            animation: livePulse 2s infinite;
        }

        /* Network quality indicator */
        .network-badge {
            font-size: 11px; padding: 3px 10px; border-radius: 20px; font-weight: 600;
        }
        .network-badge.good { background: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.4); color: #2ecc71; }
        .network-badge.fair { background: rgba(243,156,18,0.15); border: 1px solid rgba(243,156,18,0.4); color: #f39c12; }
        .network-badge.poor { background: rgba(231,76,60,0.15); border: 1px solid rgba(231,76,60,0.4); color: #e74c3c; }

        /* Fullscreen fixes */
        body:fullscreen .room-controls, body:-webkit-full-screen .room-controls,
        :fullscreen .room-controls, :-webkit-full-screen .room-controls {
            position: fixed !important; bottom: 0 !important; left: 0 !important; right: 0 !important;
            z-index: 99999 !important; height: 70px !important;
        }
        body:fullscreen .room-main, body:-webkit-full-screen .room-main,
        :-webkit-full-screen .room-main, :fullscreen .room-main {
            height: calc(100vh - 50px - 70px) !important;
        }
        body:fullscreen .self-pip, body:-webkit-full-screen .self-pip,
        :-webkit-full-screen .self-pip, :fullscreen .self-pip {
            z-index: 99998 !important; bottom: 84px !important;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .filmstrip { width: 140px; min-width: 140px; }
            .filmstrip .video-tile { height: 100px; min-height: 90px; }
            .sidebar { width: 260px; }
        }
        @media (max-width: 600px) {
            .filmstrip { width: 0; min-width: 0; display: none; }
            .sidebar { width: 100%; max-width: 100%; }
            .room-controls {
                gap: 8px; padding: 0 10px; flex-wrap: wrap; height: auto; min-height: 60px;
                padding-top: 8px; padding-bottom: 8px;
                position: fixed !important; bottom: 0 !important; left: 0 !important; right: 0 !important;
                z-index: 99999 !important;
            }
            .room-main { height: calc(100vh - 50px - 70px) !important; padding-bottom: 70px; }
            .ctrl-btn { width: 44px; height: 44px; font-size: 18px; }
            .ctrl-btn.leave-call { width: 48px; height: 48px; font-size: 20px; }
            #btnAudio, #btnVideo { background: #2ecc71; }
            #btnAudio.off, #btnVideo.off { background: #e74c3c; }
            .self-pip { bottom: 84px !important; z-index: 99998 !important; }
            .room-topbar { padding: 8px 10px; }
            .room-topbar .session-title { font-size: 12px; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2a2a4a; border-radius: 3px; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>

<!-- CONNECTING OVERLAY -->
<div id="connectingOverlay" style="position:fixed;inset:0;background:rgba(15,15,15,0.95);z-index:10000;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#fff;font-family:'Segoe UI',system-ui,sans-serif;">
    <div style="text-align:center;max-width:420px;padding:20px;">
        <div id="connectingSpinner" class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
        <h4 id="connectingTitle" style="margin-bottom:8px;">Joining Live Class</h4>
        <p style="color:#8888aa;font-size:13px;margin-bottom:4px;"><?= htmlspecialchars($session['course_name']) ?> &mdash; <?= htmlspecialchars($lecturer_name) ?></p>
        <p id="connectingStatus" style="color:#aab;font-size:14px;margin-bottom:4px;">Initializing...</p>
        <p id="connectingHint" style="color:#667;font-size:12px;margin-bottom:20px;">Please allow camera and microphone access when prompted</p>
        <div id="connectingError" style="display:none;">
            <div style="background:#2a1a1a;border:1px solid #dc3545;border-radius:10px;padding:15px;margin-bottom:15px;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#dc3545;font-size:20px;"></i>
                <p id="connectingErrorMsg" style="color:#f88;font-size:13px;margin:8px 0 0;"></p>
            </div>
            <button id="btnRetryConnect" onclick="retryConnection()" style="background:#6c63ff;color:#fff;border:none;padding:10px 30px;border-radius:8px;font-size:14px;cursor:pointer;margin-right:8px;">
                <i class="bi bi-arrow-clockwise me-1"></i> Retry
            </button>
            <button onclick="retryWithoutCamera()" style="background:#2a2a4a;color:#fff;border:1px solid #444;padding:10px 20px;border-radius:8px;font-size:14px;cursor:pointer;">
                <i class="bi bi-eye me-1"></i> Join as viewer
            </button>
        </div>
    </div>
</div>

<!-- TOP BAR -->
<div class="room-topbar">
    <div class="session-info">
        <div class="live-dot"></div>
        <div>
            <div class="session-title"><?= htmlspecialchars($session['session_name']) ?></div>
            <div class="session-course"><?= htmlspecialchars($session['course_name']) ?> (<?= htmlspecialchars($session['course_code']) ?>) &mdash; <?= htmlspecialchars($lecturer_name) ?></div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="twoway-badge" id="twowayBadge" style="display:none;"><span class="tw-dot"></span> 2-Way Audio</span>
        <span class="peer-count" id="peerCount"><i class="bi bi-people me-1"></i> <?= $peer_count + 1 ?></span>
        <span style="font-size:12px;color:#666;" id="connectionStatus">Connecting...</span>
    </div>
</div>

<!-- MAIN AREA -->
<div class="room-main">
    <div class="speaker-area" id="speakerArea">
        <div class="speaker-placeholder" id="speakerPlaceholder">
            <i class="bi bi-camera-video"></i>
            Waiting for lecturer's video...
        </div>
    </div>

    <div class="filmstrip" id="filmstrip"></div>

    <div class="sidebar collapsed" id="chatSidebar">
        <div class="sidebar-header">
            <span><i class="bi bi-chat-dots me-2"></i>Chat</span>
            <button class="btn btn-sm btn-outline-light" onclick="toggleSidebar()" style="font-size:12px;"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="text-center text-muted py-3" style="font-size:12px;">
                <i class="bi bi-chat-dots" style="font-size:24px;"></i><br>
                Chat messages appear here
            </div>
        </div>
        <div class="chat-input-area">
            <input type="text" id="chatInput" placeholder="Type a message..." autocomplete="off"
                   onkeydown="if(event.key==='Enter')sendChat()">
            <button onclick="sendChat()"><i class="bi bi-send"></i></button>
        </div>
    </div>
</div>

<!-- SELF-VIEW PiP -->
<div class="self-pip" id="selfPip" style="display:none;">
    <video id="localVideo" autoplay muted playsinline webkit-playsinline></video>
    <div class="tile-avatar" id="selfAvatar" style="display:none;"><?= strtoupper(substr($user_name,0,1)) ?></div>
    <div class="self-pip-label"><?= $user_name ?> <span style="color:#667eea;font-weight:600;">YOU</span></div>
    <button class="self-pip-close" onclick="toggleSelfPip()" title="Hide self view"><i class="bi bi-x"></i></button>
</div>

<!-- BOTTOM CONTROLS -->
<div class="room-controls">
    <button class="ctrl-btn on" id="btnAudio" onclick="toggleAudio()" title="Toggle Microphone">
        <i class="bi bi-mic-fill"></i>
    </button>
    <button class="ctrl-btn on" id="btnVideo" onclick="toggleVideoCtrl()" title="Toggle Camera">
        <i class="bi bi-camera-video-fill"></i>
    </button>
    <button class="ctrl-btn screen off" id="btnScreen" onclick="toggleScreen()" title="Share Screen">
        <i class="bi bi-display"></i>
    </button>
    <button class="ctrl-btn chat-toggle" id="btnChat" onclick="toggleSidebar()" title="Toggle Chat">
        <i class="bi bi-chat-dots"></i>
    </button>
    <button class="ctrl-btn on" id="btnMinimize" onclick="toggleMinimizeSelf()" title="Minimize/Restore your video">
        <i class="bi bi-pip"></i>
    </button>
    <button class="ctrl-btn fullscreen" id="btnFullscreen" onclick="toggleFullscreen()" title="Full Screen">
        <i class="bi bi-arrows-fullscreen"></i>
    </button>
    <button class="ctrl-btn leave-call" onclick="leaveSession()" title="Leave Session">
        <i class="bi bi-box-arrow-right"></i>
    </button>
</div>

<script src="../assets/js/webrtc-room.js?v=<?= time() ?>"></script>
<script>
(function() {
    'use strict';

    const SESSION_ID = <?= $session_id ?>;
    const USER_ID = <?= $user_id ?>;
    const USER_NAME = <?= json_encode($user_name) ?>;
    const USER_ROLE = 'student';
    const IS_HOST = false;

    // Inject TURN/STUN server config for cross-network connectivity
    const ICE_CONFIG = <?= $iceConfigJson ?>;
    VLERoom.setIceConfig(ICE_CONFIG);

    // Device detection
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    const isAndroid = /Android/i.test(navigator.userAgent);
    const isMobile = isIOS || isAndroid;

    const speakerArea = document.getElementById('speakerArea');
    const filmstrip = document.getElementById('filmstrip');
    const selfPip = document.getElementById('selfPip');
    const localVideo = document.getElementById('localVideo');
    const selfAvatar = document.getElementById('selfAvatar');
    const statusEl = document.getElementById('connectionStatus');
    const chatMessages = document.getElementById('chatMessages');

    let pinnedSpeakerId = null;

    // ── Init WebRTC Engine ──
    VLERoom.init({
        sessionId: SESSION_ID,
        userId: USER_ID,
        userName: USER_NAME,
        userRole: USER_ROLE,

        onStatusUpdate: function(status) {
            const el = document.getElementById('connectingStatus');
            if (el) el.textContent = status;
            console.log('[StudentRoom] Status:', status);
        },

        onPeerJoined: function(peer) {
            statusEl.textContent = 'Connected';
            statusEl.style.color = '#2ecc71';
        },

        onPeerLeft: function(peerId, info) {
            removePeerTile(peerId);
        },

        onRemoteStream: function(peerId, stream, info) {
            addOrUpdatePeerTile(peerId, stream, info);
        },

        onChatMessage: function(msg) {
            appendChat(msg);
        },

        onPeerCountUpdate: function(count) {
            document.getElementById('peerCount').innerHTML = '<i class="bi bi-people me-1"></i> ' + count;
        },

        onPeerMediaStateUpdate: function(peerId, state) {
            updatePeerMediaIndicators(peerId, state);
        },

        onError: function(err) {
            console.error('[StudentRoom]', err);
            statusEl.textContent = typeof err === 'string' ? err.substring(0, 60) : 'Error';
            statusEl.style.color = '#e74c3c';
        },

        onRemoteMuteToggle: function(mediaType, isMuted, fromPeerId) {
            if (mediaType === 'audio') {
                const btn = document.getElementById('btnAudio');
                btn.className = 'ctrl-btn ' + (isMuted ? 'off' : 'on');
                btn.innerHTML = isMuted ? '<i class="bi bi-mic-mute-fill"></i>' : '<i class="bi bi-mic-fill"></i>';
                showToast(isMuted ? 'Your microphone was muted by the host' : 'Your microphone was unmuted by the host', isMuted ? 'warning' : 'success');
            } else if (mediaType === 'video') {
                const btn = document.getElementById('btnVideo');
                btn.className = 'ctrl-btn ' + (isMuted ? 'off' : 'on');
                btn.innerHTML = isMuted ? '<i class="bi bi-camera-video-off-fill"></i>' : '<i class="bi bi-camera-video-fill"></i>';
            }
        },

        onSessionEnded: function(status) {
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:99999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#fff;font-family:"Segoe UI",system-ui,sans-serif;';
            overlay.innerHTML = '<i class="bi bi-check-circle" style="font-size:4rem;color:#2ecc71;margin-bottom:1rem"></i>' +
                '<h2 style="margin-bottom:0.5rem">Class Ended</h2>' +
                '<p style="opacity:0.7;margin-bottom:0.5rem"><?= htmlspecialchars($session['course_name']) ?></p>' +
                '<p style="opacity:0.5;margin-bottom:1.5rem">The lecturer has ended this live session.</p>' +
                '<p style="opacity:0.6">Redirecting in <span id="endCountdown">5</span> seconds...</p>' +
                '<a href="live_invites.php" class="btn btn-outline-light mt-3" style="font-size:14px;"><i class="bi bi-arrow-left me-1"></i> Back to Sessions</a>';
            document.body.appendChild(overlay);
            let countdown = 5;
            const timer = setInterval(function() {
                countdown--;
                const el = document.getElementById('endCountdown');
                if (el) el.textContent = countdown;
                if (countdown <= 0) { clearInterval(timer); window.location.href = 'live_invites.php'; }
            }, 1000);
        }
    });

    // ── Join the room — auto-start ──
    let joinAttempts = 0;
    let joinWithCamera = true;
    const MAX_AUTO_RETRIES = 3;

    function startJoin() {
        joinAttempts++;
        const overlay = document.getElementById('connectingOverlay');
        const spinner = document.getElementById('connectingSpinner');
        const title = document.getElementById('connectingTitle');
        const hint = document.getElementById('connectingHint');
        const errorDiv = document.getElementById('connectingError');

        if (overlay) overlay.style.display = 'flex';
        if (spinner) spinner.style.display = '';
        if (title) title.textContent = joinAttempts > 1 ? 'Reconnecting... (attempt ' + joinAttempts + ')' : 'Joining Live Class';
        if (hint) { hint.style.display = ''; hint.textContent = joinWithCamera ? 'Please allow camera and microphone access when prompted' : 'Joining as viewer...'; }
        if (errorDiv) errorDiv.style.display = 'none';

        VLERoom.joinRoom().then(function(result) {
            const localStream = result.stream;
            const mode = result.mediaMode;

            // Hide overlay with fade
            if (overlay) {
                overlay.style.opacity = '0';
                overlay.style.transition = 'opacity 0.4s ease';
                setTimeout(function() { overlay.style.display = 'none'; overlay.style.opacity = ''; }, 400);
            }

            // Self-view
            selfPip.style.display = '';
            if (localStream) {
                localVideo.srcObject = localStream;
                localVideo.style.display = '';
                selfAvatar.style.display = 'none';
                localVideo.setAttribute('playsinline', '');
                localVideo.setAttribute('webkit-playsinline', '');
                localVideo.play().catch(function(){});
            } else {
                localVideo.style.display = 'none';
                selfAvatar.style.display = 'flex';
            }

            statusEl.textContent = 'Connected';
            statusEl.style.color = '#2ecc71';

            if (mode === 'full' || mode === 'audio') show2WayBadge();

            if (mode === 'audio') {
                document.getElementById('btnVideo').className = 'ctrl-btn off';
                document.getElementById('btnVideo').innerHTML = '<i class="bi bi-camera-video-off-fill"></i>';
                localVideo.style.display = 'none';
                selfAvatar.style.display = 'flex';
            } else if (mode === 'view-only') {
                document.getElementById('btnVideo').className = 'ctrl-btn off';
                document.getElementById('btnVideo').innerHTML = '<i class="bi bi-camera-video-off-fill"></i>';
                document.getElementById('btnAudio').className = 'ctrl-btn off';
                document.getElementById('btnAudio').innerHTML = '<i class="bi bi-mic-mute-fill"></i>';
                localVideo.style.display = 'none';
                selfAvatar.style.display = 'flex';
            }

            // iOS audio unblock
            if (isIOS) {
                setTimeout(function() { showAudioUnblockBanner(document.createElement('video')); }, 500);
            }

        }).catch(function(err) {
            console.error('[StudentRoom] Join failed (attempt ' + joinAttempts + '):', err);

            var isFatal = err.message && (err.message.indexOf('not active') !== -1 || err.message.indexOf('does not exist') !== -1);
            if (!isFatal && joinAttempts < MAX_AUTO_RETRIES) {
                if (title) title.textContent = 'Retrying... (attempt ' + (joinAttempts + 1) + '/' + MAX_AUTO_RETRIES + ')';
                if (hint) { hint.style.display = ''; hint.textContent = 'Connection failed, retrying automatically...'; }
                setTimeout(function() { startJoin(); }, 2000);
                return;
            }

            if (overlay) overlay.style.display = 'flex';
            if (spinner) spinner.style.display = 'none';
            if (title) title.textContent = 'Connection Failed';
            if (hint) hint.style.display = 'none';
            if (errorDiv) {
                errorDiv.style.display = '';
                document.getElementById('connectingErrorMsg').textContent = err.message || 'Could not connect to the live session.';
            }
            statusEl.textContent = 'Failed to join';
            statusEl.style.color = '#e74c3c';
        });
    }

    window.retryConnection = function() { joinAttempts = 0; joinWithCamera = true; startJoin(); };
    window.retryWithoutCamera = function() { joinAttempts = 0; joinWithCamera = false; startJoin(); };

    // Auto-start
    startJoin();

    // ── Speaker view ──
    function pinSpeaker(id) {
        pinnedSpeakerId = id;
        const placeholder = document.getElementById('speakerPlaceholder');

        if (id === 'local') {
            if (placeholder) placeholder.style.display = 'none';
            let speakerTile = document.getElementById('speakerTile');
            if (!speakerTile) {
                speakerTile = document.createElement('div');
                speakerTile.id = 'speakerTile';
                speakerTile.className = 'video-tile';
                speakerTile.innerHTML = '<video autoplay muted playsinline webkit-playsinline></video>' +
                    '<div class="tile-avatar" style="display:none;">' + USER_NAME.charAt(0).toUpperCase() + '</div>' +
                    '<div class="tile-label"><span>' + escapeHtml(USER_NAME) + '</span> <span style="color:#667eea;font-weight:600;">YOU</span></div>' +
                    '<button class="tile-fullscreen-btn" onclick="toggleTileFullscreen(this.closest(\'.video-tile\'))" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>';
                speakerTile.addEventListener('dblclick', function(e) { e.preventDefault(); toggleTileFullscreen(speakerTile); });
                speakerArea.appendChild(speakerTile);
            }
            const vid = speakerTile.querySelector('video');
            vid.setAttribute('playsinline', '');
            vid.setAttribute('webkit-playsinline', '');
            if (localVideo.srcObject) vid.srcObject = localVideo.srcObject;
            const av = speakerTile.querySelector('.tile-avatar');
            if (localVideo.srcObject && localVideo.srcObject.getVideoTracks().length > 0) {
                av.style.display = 'none'; vid.style.display = '';
            } else {
                av.style.display = 'flex'; vid.style.display = 'none';
            }
            filmstrip.querySelectorAll('.video-tile').forEach(t => t.classList.remove('active-speaker'));
        } else {
            // Pin a remote peer (usually the lecturer)
            if (placeholder) placeholder.style.display = 'none';
            const filmTile = document.getElementById('film-' + id);
            let speakerTile = document.getElementById('speakerTile');
            if (!speakerTile) {
                speakerTile = document.createElement('div');
                speakerTile.id = 'speakerTile';
                speakerTile.className = 'video-tile';
                speakerArea.appendChild(speakerTile);
            }
            if (filmTile) {
                const srcVid = filmTile.querySelector('video');
                const srcLabel = filmTile.querySelector('.tile-label');
                const srcAvatar = filmTile.querySelector('.tile-avatar');
                const name = srcLabel ? srcLabel.querySelector('span').textContent : 'Participant';

                speakerTile.innerHTML = '<video autoplay playsinline webkit-playsinline></video>' +
                    '<div class="tile-avatar" style="display:none;">' + name.charAt(0).toUpperCase() + '</div>' +
                    (srcLabel ? '<div class="tile-label">' + srcLabel.innerHTML + '</div>' : '') +
                    '<div class="tile-indicators"></div>' +
                    '<button class="tile-fullscreen-btn" onclick="toggleTileFullscreen(this.closest(\'.video-tile\'))" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>';

                const vid = speakerTile.querySelector('video');
                vid.setAttribute('playsinline', '');
                vid.setAttribute('webkit-playsinline', '');
                if (srcVid && srcVid.srcObject) {
                    vid.srcObject = srcVid.srcObject;
                    vid.muted = true;
                    vid.play().then(function() { vid.muted = false; vid.volume = 1.0; }).catch(function(err) {
                        console.log('[StudentRoom] Speaker video play failed:', err.message);
                        showAudioUnblockBanner(vid);
                    });
                }
                const speakerAvatar = speakerTile.querySelector('.tile-avatar');
                if (srcAvatar && srcAvatar.style.display === 'flex') {
                    speakerAvatar.style.display = 'flex'; vid.style.display = 'none';
                }
                speakerTile.addEventListener('dblclick', function(e) { e.preventDefault(); toggleTileFullscreen(speakerTile); });
                filmstrip.querySelectorAll('.video-tile').forEach(t => t.classList.remove('active-speaker'));
                filmTile.classList.add('active-speaker');
            }
        }
    }

    // ── Peer tile management ──
    function addOrUpdatePeerTile(peerId, stream, info) {
        const name = (info && info.user_name) ? info.user_name : 'Participant';
        const isLecturer = info && info.user_role === 'lecturer';
        const hostBadge = isLecturer ? '<span class="host-badge">HOST</span>' : '';

        let tile = document.getElementById('film-' + peerId);
        let video;
        if (!tile) {
            tile = document.createElement('div');
            tile.id = 'film-' + peerId;
            tile.className = 'video-tile';
            tile.addEventListener('click', function() { pinSpeaker(peerId); });
            tile.addEventListener('dblclick', function(e) { e.preventDefault(); toggleTileFullscreen(tile); });
            tile.innerHTML = '<video autoplay muted playsinline webkit-playsinline></video>' +
                '<div class="tile-avatar" style="display:none;">' + escapeHtml(name).charAt(0).toUpperCase() + '</div>' +
                '<div class="tile-label"><span>' + escapeHtml(name) + '</span>' + hostBadge + '</div>' +
                '<div class="tile-indicators"></div>' +
                '<button class="tile-fullscreen-btn" onclick="event.stopPropagation();toggleTileFullscreen(this.closest(\'.video-tile\'))" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>';
            filmstrip.appendChild(tile);
        }

        video = tile.querySelector('video');
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('autoplay', '');

        if (video.srcObject !== stream) video.srcObject = stream;

        video.muted = true;
        const playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.then(function() { video.muted = false; video.volume = 1.0; }).catch(function(err) {
                console.warn('[StudentRoom] Autoplay blocked for ' + peerId + ':', err.message);
                showAudioUnblockBanner(video);
            });
        }

        // Auto-pin lecturer as main speaker
        if (isLecturer) {
            pinSpeaker(peerId);
        } else if (!pinnedSpeakerId) {
            pinSpeaker(peerId);
        }
        if (pinnedSpeakerId === peerId) pinSpeaker(peerId);
    }

    function updatePeerMediaIndicators(peerId, state) {
        const tile = document.getElementById('film-' + peerId);
        if (tile) {
            let indicators = tile.querySelector('.tile-indicators');
            if (!indicators) { indicators = document.createElement('div'); indicators.className = 'tile-indicators'; tile.appendChild(indicators); }
            let html = '';
            if (!state.is_audio_on) html += '<div class="indicator-badge mic-off" title="Mic muted"><i class="bi bi-mic-mute-fill"></i></div>';
            if (!state.is_video_on) html += '<div class="indicator-badge cam-off" title="Camera off"><i class="bi bi-camera-video-off-fill"></i></div>';
            if (state.is_screen_sharing) html += '<div class="indicator-badge screen-on" title="Sharing screen"><i class="bi bi-display-fill"></i></div>';
            indicators.innerHTML = html;

            const avatar = tile.querySelector('.tile-avatar');
            const video = tile.querySelector('video');
            if (avatar && video) {
                if (!state.is_video_on && !state.is_screen_sharing) {
                    avatar.style.display = 'flex'; video.style.opacity = '0'; video.style.position = 'absolute';
                } else {
                    avatar.style.display = 'none'; video.style.opacity = '1'; video.style.position = '';
                }
            }
        }
        // Update speaker tile if pinned
        if (pinnedSpeakerId === peerId) {
            const speakerTile = document.getElementById('speakerTile');
            if (speakerTile) {
                let indicators = speakerTile.querySelector('.tile-indicators');
                if (!indicators) { indicators = document.createElement('div'); indicators.className = 'tile-indicators'; speakerTile.appendChild(indicators); }
                let html = '';
                if (!state.is_audio_on) html += '<div class="indicator-badge mic-off"><i class="bi bi-mic-mute-fill"></i></div>';
                if (!state.is_video_on) html += '<div class="indicator-badge cam-off"><i class="bi bi-camera-video-off-fill"></i></div>';
                if (state.is_screen_sharing) html += '<div class="indicator-badge screen-on"><i class="bi bi-display-fill"></i></div>';
                indicators.innerHTML = html;
                const avatar = speakerTile.querySelector('.tile-avatar');
                const video = speakerTile.querySelector('video');
                if (avatar && video) {
                    if (!state.is_video_on && !state.is_screen_sharing) {
                        avatar.style.display = 'flex'; video.style.display = 'none';
                    } else {
                        avatar.style.display = 'none'; video.style.display = '';
                    }
                }
            }
        }
    }

    // ── iOS Audio Unblock ──
    function resumeAudioContext() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
        } catch(e) {}
    }

    function showAudioUnblockBanner(videoEl) {
        if (document.getElementById('audioUnblockBanner')) return;
        const banner = document.createElement('div');
        banner.id = 'audioUnblockBanner';
        banner.style.cssText = 'position:fixed;top:55px;left:50%;transform:translateX(-50%);z-index:9999;background:linear-gradient(135deg,#667eea,#764ba2);padding:10px 24px;border-radius:24px;font-size:13px;cursor:pointer;box-shadow:0 4px 15px rgba(102,126,234,0.4);display:flex;align-items:center;gap:8px;animation:fadeIn 0.3s ease;';
        banner.innerHTML = '<i class="bi bi-volume-up-fill"></i> Tap to enable audio';
        banner.onclick = function() {
            resumeAudioContext();
            document.querySelectorAll('.video-tile video').forEach(function(v) {
                if (v.id === 'localVideo') return;
                v.muted = false; v.volume = 1.0;
                v.setAttribute('playsinline', '');
                v.setAttribute('webkit-playsinline', '');
                v.play().catch(function(){});
            });
            const st = document.getElementById('speakerTile');
            if (st) { const v = st.querySelector('video'); if (v) { v.muted = false; v.volume = 1.0; v.play().catch(function(){}); } }
            banner.remove();
        };
        document.body.appendChild(banner);
        setTimeout(function() { if (banner.parentNode) banner.remove(); }, 15000);
    }

    function show2WayBadge() {
        const badge = document.getElementById('twowayBadge');
        if (badge) badge.style.display = 'inline-flex';
    }

    function removePeerTile(peerId) {
        const tile = document.getElementById('film-' + peerId);
        if (tile) tile.remove();
        if (pinnedSpeakerId === peerId) {
            pinnedSpeakerId = null;
            const speakerTile = document.getElementById('speakerTile');
            if (speakerTile) speakerTile.remove();
            const remaining = filmstrip.querySelector('.video-tile');
            if (remaining) {
                pinSpeaker(remaining.id.replace('film-', ''));
            } else {
                const placeholder = document.getElementById('speakerPlaceholder');
                if (placeholder) placeholder.style.display = '';
            }
        }
    }

    // ── Controls ──
    window.toggleAudio = async function() {
        const result = VLERoom.toggleAudio();
        if (result === 'needs-upgrade') {
            try {
                showToast('Requesting microphone access...', 'info');
                const mediaResult = await VLERoom.requestMedia('audio');
                if (mediaResult.stream) {
                    localVideo.srcObject = mediaResult.stream;
                    localVideo.style.display = '';
                    selfAvatar.style.display = 'none';
                    if (pinnedSpeakerId === 'local') pinSpeaker('local');
                }
                document.getElementById('btnAudio').className = 'ctrl-btn on';
                document.getElementById('btnAudio').innerHTML = '<i class="bi bi-mic-fill"></i>';
                showToast('Microphone enabled!', 'success');
                show2WayBadge();
                return;
            } catch (err) {
                showToast('Microphone access denied. Please allow in browser settings and try again.', 'danger');
                return;
            }
        }
        const on = result;
        const btn = document.getElementById('btnAudio');
        btn.className = 'ctrl-btn ' + (on ? 'on' : 'off');
        btn.innerHTML = on ? '<i class="bi bi-mic-fill"></i>' : '<i class="bi bi-mic-mute-fill"></i>';
    };

    window.toggleVideoCtrl = async function() {
        const mode = VLERoom.getMediaMode();
        const result = VLERoom.toggleVideo();
        if (result === 'needs-upgrade') {
            try {
                showToast('Requesting camera access...', 'info');
                const mediaResult = await VLERoom.requestMedia(mode === 'view-only' ? 'both' : 'video');
                if (mediaResult.stream) {
                    localVideo.srcObject = mediaResult.stream;
                    localVideo.style.display = '';
                    selfAvatar.style.display = 'none';
                    if (pinnedSpeakerId === 'local') pinSpeaker('local');
                }
                document.getElementById('btnVideo').className = 'ctrl-btn on';
                document.getElementById('btnVideo').innerHTML = '<i class="bi bi-camera-video-fill"></i>';
                if (mode === 'view-only') {
                    document.getElementById('btnAudio').className = 'ctrl-btn on';
                    document.getElementById('btnAudio').innerHTML = '<i class="bi bi-mic-fill"></i>';
                }
                showToast('Camera enabled!', 'success');
                show2WayBadge();
                return;
            } catch (err) {
                showToast('Camera access denied. Please allow in browser settings and try again.', 'danger');
                return;
            }
        }
        const on = result;
        const btn = document.getElementById('btnVideo');
        btn.className = 'ctrl-btn ' + (on ? 'on' : 'off');
        btn.innerHTML = on ? '<i class="bi bi-camera-video-fill"></i>' : '<i class="bi bi-camera-video-off-fill"></i>';
        if (on) { localVideo.style.display = ''; selfAvatar.style.display = 'none'; }
        else { localVideo.style.display = 'none'; selfAvatar.style.display = 'flex'; }
        if (pinnedSpeakerId === 'local') {
            const st = document.getElementById('speakerTile');
            if (st) {
                const v = st.querySelector('video'), a = st.querySelector('.tile-avatar');
                if (on) { v.style.display = ''; a.style.display = 'none'; }
                else { v.style.display = 'none'; a.style.display = 'flex'; }
            }
        }
    };

    window.toggleScreen = async function() {
        const on = await VLERoom.toggleScreenShare();
        const btn = document.getElementById('btnScreen');
        btn.className = 'ctrl-btn screen ' + (on ? '' : 'off');
        btn.innerHTML = on ? '<i class="bi bi-display-fill"></i>' : '<i class="bi bi-display"></i>';
    };

    window.toggleSidebar = function() { document.getElementById('chatSidebar').classList.toggle('collapsed'); };

    window.toggleFullscreen = function() {
        const doc = document, elem = doc.documentElement;
        if (!doc.fullscreenElement && !doc.webkitFullscreenElement) {
            if (elem.requestFullscreen) elem.requestFullscreen();
            else if (elem.webkitRequestFullscreen) elem.webkitRequestFullscreen();
        } else {
            if (doc.exitFullscreen) doc.exitFullscreen();
            else if (doc.webkitExitFullscreen) doc.webkitExitFullscreen();
        }
    };

    window.toggleTileFullscreen = function(tile) {
        if (!tile) return;
        const fs = document.fullscreenElement || document.webkitFullscreenElement;
        if (fs === tile) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); }
        else { (tile.requestFullscreen || tile.webkitRequestFullscreen).call(tile); }
    };

    document.addEventListener('fullscreenchange', updateFullscreenBtn);
    document.addEventListener('webkitfullscreenchange', updateFullscreenBtn);
    function updateFullscreenBtn() {
        const btn = document.getElementById('btnFullscreen');
        const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        btn.className = 'ctrl-btn fullscreen' + (isFs ? ' active' : '');
        btn.innerHTML = isFs ? '<i class="bi bi-fullscreen-exit"></i>' : '<i class="bi bi-arrows-fullscreen"></i>';
    }

    // ── Chat ──
    window.sendChat = function() {
        const input = document.getElementById('chatInput');
        const msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        VLERoom.sendChatMessage(msg);
    };

    function appendChat(msg) {
        const isMe = parseInt(msg.user_id) === USER_ID;
        const div = document.createElement('div');
        div.className = 'chat-msg';
        const time = msg.created_at ? new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
        div.innerHTML = '<span class="chat-author">' + escapeHtml(msg.user_name) + ' <span class="chat-time">' + time + '</span></span>' +
            '<div class="chat-text">' + escapeHtml(msg.message) + '</div>';
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // ── Leave ──
    window.leaveSession = async function() {
        if (!confirm('Leave this live class?')) return;
        await VLERoom.leaveRoom();
        window.location.href = 'live_invites.php';
    };

    window.toggleSelfPip = function() { selfPip.style.display = selfPip.style.display === 'none' ? '' : 'none'; };
    window.toggleMinimizeSelf = function() { toggleSelfPip(); };

    // ── Cleanup ──
    window.addEventListener('beforeunload', function() { VLERoom.leaveRoom(); });

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    // Toast
    function showToast(message, type) {
        type = type || 'info';
        const colors = { success: '#198754', danger: '#dc3545', warning: '#ffc107', info: '#0dcaf0' };
        const textColor = type === 'warning' ? '#000' : '#fff';
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:60px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;color:' + textColor + ';background:' + (colors[type] || colors.info) + ';box-shadow:0 4px 12px rgba(0,0,0,0.3);font-size:14px;max-width:400px;animation:fadeIn 0.3s ease;';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; }, 4000);
        setTimeout(function() { toast.remove(); }, 4500);
    }

    // ── Drag support for self-view PiP ──
    (function() {
        let isDragging = false, dragOffsetX = 0, dragOffsetY = 0;
        selfPip.addEventListener('mousedown', function(e) {
            if (e.target.closest('button')) return;
            isDragging = true;
            const rect = selfPip.getBoundingClientRect();
            dragOffsetX = e.clientX - rect.left; dragOffsetY = e.clientY - rect.top;
            selfPip.style.transition = 'none'; e.preventDefault();
        });
        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            selfPip.style.right = 'auto'; selfPip.style.bottom = 'auto';
            selfPip.style.left = Math.max(0, Math.min(window.innerWidth - selfPip.offsetWidth, e.clientX - dragOffsetX)) + 'px';
            selfPip.style.top = Math.max(0, Math.min(window.innerHeight - selfPip.offsetHeight, e.clientY - dragOffsetY)) + 'px';
        });
        document.addEventListener('mouseup', function() { if (isDragging) { isDragging = false; selfPip.style.transition = ''; } });
        selfPip.addEventListener('touchstart', function(e) {
            if (e.target.closest('button')) return;
            isDragging = true;
            const touch = e.touches[0], rect = selfPip.getBoundingClientRect();
            dragOffsetX = touch.clientX - rect.left; dragOffsetY = touch.clientY - rect.top;
            selfPip.style.transition = 'none';
        }, { passive: true });
        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            const touch = e.touches[0];
            selfPip.style.right = 'auto'; selfPip.style.bottom = 'auto';
            selfPip.style.left = Math.max(0, Math.min(window.innerWidth - selfPip.offsetWidth, touch.clientX - dragOffsetX)) + 'px';
            selfPip.style.top = Math.max(0, Math.min(window.innerHeight - selfPip.offsetHeight, touch.clientY - dragOffsetY)) + 'px';
        }, { passive: true });
        document.addEventListener('touchend', function() { if (isDragging) { isDragging = false; selfPip.style.transition = ''; } });
    })();

})();
</script>
</body>
</html>
