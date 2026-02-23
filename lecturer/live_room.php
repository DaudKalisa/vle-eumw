<?php
/**
 * Live Classroom Room - WebRTC Video Conference
 * Used by both lecturers and students to join a live session.
 * URL: live_room.php?session_id=X
 */
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_id = (int)$user['user_id'];
$user_name = htmlspecialchars($user['display_name'] ?? $user['username'] ?? 'Unknown');
$user_role = $_SESSION['vle_role'] ?? 'student';
$session_id = (int)($_GET['session_id'] ?? 0);

if (!$session_id) {
    header('Location: ' . ($user_role === 'lecturer' ? 'live_classroom.php' : '../student/live_invites.php'));
    exit;
}

// Verify session exists and is active
$stmt = $conn->prepare("SELECT vls.*, vc.course_name, vc.course_code FROM vle_live_sessions vls JOIN vle_courses vc ON vls.course_id = vc.course_id WHERE vls.session_id = ? AND vls.status = 'active'");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    echo "<script>alert('This session is not active or does not exist.'); window.history.back();</script>";
    exit;
}

$is_host = ($user_role === 'lecturer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        .room-topbar .session-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .room-topbar .live-dot {
            width: 10px; height: 10px;
            background: #ff4444;
            border-radius: 50%;
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
        .room-main {
            display: flex;
            height: calc(100vh - 50px - 70px); /* topbar + controls */
        }

        /* ── VIDEO GRID ── */
        .video-grid {
            flex: 1;
            display: grid;
            gap: 8px;
            padding: 8px;
            grid-template-columns: 1fr;
            align-content: center;
            overflow: hidden;
        }
        .video-grid.grid-2 { grid-template-columns: 1fr 1fr; }
        .video-grid.grid-3, .video-grid.grid-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; }
        .video-grid.grid-5, .video-grid.grid-6 { grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; }
        .video-grid.grid-many { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }

        .video-tile {
            position: relative;
            background: #1a1a2e;
            border-radius: 12px;
            overflow: hidden;
            min-height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .video-tile video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
        }
        .video-tile .tile-label {
            position: absolute;
            bottom: 8px;
            left: 8px;
            background: rgba(0,0,0,0.6);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .video-tile .tile-label .host-badge {
            background: #e74c3c;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
        }
        .video-tile .tile-avatar {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
        }
        .video-tile.local-tile { border: 2px solid #667eea; }
        .video-tile.screen-tile { border: 2px solid #2ecc71; grid-column: 1 / -1; }

        /* ── MINIMIZED LOCAL TILE (PiP self-view) ── */
        .video-tile.local-tile.minimized {
            position: fixed;
            bottom: 84px;
            right: 16px;
            width: 160px;
            height: 120px;
            min-height: 120px;
            z-index: 500;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border: 2px solid #667eea;
            cursor: move;
            transition: width 0.3s, height 0.3s;
            resize: both;
            overflow: hidden;
        }
        .video-tile.local-tile.minimized video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .video-tile.local-tile.minimized .tile-label {
            display: none;
        }
        .video-tile.local-tile.minimized .tile-fullscreen-btn {
            display: none;
        }
        .video-tile.local-tile.minimized .tile-indicators {
            display: none;
        }
        /* Minimize/restore button on local tile */
        .tile-minimize-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            border-radius: 8px;
            width: 32px; height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s, background 0.2s;
            z-index: 11;
        }
        .video-tile.local-tile:hover .tile-minimize-btn,
        .video-tile.local-tile.minimized .tile-minimize-btn {
            opacity: 1;
        }
        .tile-minimize-btn:hover { background: rgba(102,126,234,0.8); }
        .video-tile.local-tile.minimized .tile-minimize-btn {
            top: 4px;
            left: 4px;
            width: 28px; height: 28px;
            font-size: 12px;
        }
        .video-tile .muted-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(231,76,60,0.8);
            border-radius: 50%;
            width: 28px; height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        /* ── TILE FULLSCREEN BUTTON ── */
        .video-tile .tile-fullscreen-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            border-radius: 8px;
            width: 36px; height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s, background 0.2s;
            z-index: 10;
        }
        .video-tile:hover .tile-fullscreen-btn { opacity: 1; }
        .video-tile .tile-fullscreen-btn:hover { background: rgba(102,126,234,0.8); }
        .video-tile:-webkit-full-screen,
        .video-tile:fullscreen {
            width: 100vw !important;
            height: 100vh !important;
            background: #000;
            border-radius: 0;
            z-index: 99999;
        }
        .video-tile:-webkit-full-screen video,
        .video-tile:fullscreen video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 0;
        }
        .video-tile:-webkit-full-screen .tile-fullscreen-btn,
        .video-tile:fullscreen .tile-fullscreen-btn {
            opacity: 0;
            bottom: 20px;
            right: 20px;
            width: 44px; height: 44px;
            font-size: 20px;
        }
        .video-tile:-webkit-full-screen:hover .tile-fullscreen-btn,
        .video-tile:fullscreen:hover .tile-fullscreen-btn { opacity: 1; }
        .video-tile .tile-hint {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.7);
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            z-index: 10;
        }

        /* ── Peer media-state indicator badges ── */
        .video-tile .tile-indicators {
            position: absolute;
            top: 8px;
            right: 8px;
            display: flex;
            gap: 5px;
            z-index: 5;
        }
        .video-tile .indicator-badge {
            width: 30px; height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #fff;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .indicator-badge.mic-off {
            background: rgba(231,76,60,0.85);
            animation: pulse-badge 2s infinite;
        }
        .indicator-badge.cam-off {
            background: rgba(155,89,182,0.8);
        }
        .indicator-badge.screen-on {
            background: rgba(46,204,113,0.85);
        }
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        /* Avatar overlay when remote camera is off */
        .video-tile .tile-avatar {
            position: absolute;
            z-index: 3;
        }

        /* ── SIDEBAR (CHAT) ── */
        .sidebar {
            width: 320px;
            background: #1a1a2e;
            border-left: 1px solid #2a2a4a;
            display: flex;
            flex-direction: column;
            transition: width 0.3s;
        }
        .sidebar.collapsed { width: 0; overflow: hidden; border: none; }
        .sidebar-header {
            padding: 12px 16px;
            border-bottom: 1px solid #2a2a4a;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }
        .chat-msg {
            margin-bottom: 12px;
        }
        .chat-msg .chat-author {
            font-size: 12px;
            font-weight: 600;
            color: #667eea;
        }
        .chat-msg .chat-author.host { color: #e74c3c; }
        .chat-msg .chat-text {
            font-size: 13px;
            color: #ccc;
            margin-top: 2px;
            word-break: break-word;
        }
        .chat-msg .chat-time {
            font-size: 10px;
            color: #666;
        }
        .chat-input-area {
            padding: 12px;
            border-top: 1px solid #2a2a4a;
            display: flex;
            gap: 8px;
        }
        .chat-input-area input {
            flex: 1;
            background: #0f0f0f;
            border: 1px solid #2a2a4a;
            color: #fff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
        }
        .chat-input-area button {
            background: #667eea;
            border: none;
            color: #fff;
            border-radius: 8px;
            padding: 8px 14px;
            cursor: pointer;
        }

        /* ── BOTTOM CONTROLS ── */
        .room-controls {
            height: 70px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-top: 1px solid #2a2a4a;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 0 20px;
        }
        .ctrl-btn {
            width: 48px; height: 48px;
            border-radius: 50%;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s;
            color: #fff;
        }
        .ctrl-btn:hover { transform: scale(1.1); }
        .ctrl-btn.on { background: #2a2a4a; }
        .ctrl-btn.off { background: #e74c3c; }
        .ctrl-btn.screen { background: #2ecc71; }
        .ctrl-btn.screen.off { background: #2a2a4a; }
        .ctrl-btn.chat-toggle { background: #667eea; }
        .ctrl-btn.end-call { background: #e74c3c; width: 56px; height: 56px; font-size: 24px; }
        .ctrl-btn.end-call:hover { background: #c0392b; }
        .ctrl-btn.fullscreen { background: #2a2a4a; }
        .ctrl-btn.fullscreen.active { background: #f39c12; }
        .ctrl-btn.record { background: #2a2a4a; }
        .ctrl-btn.record.active { background: #e74c3c; animation: recPulse 1.2s infinite; }
        @keyframes recPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(231,76,60,0.5); }
            50% { box-shadow: 0 0 0 8px rgba(231,76,60,0); }
        }
        .recording-indicator {
            position: fixed; top: 60px; right: 20px; z-index: 200;
            background: rgba(231,76,60,0.9); color: #fff;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
            display: none; align-items: center; gap: 6px;
        }
        .recording-indicator .rec-dot {
            width: 8px; height: 8px; background: #fff;
            border-radius: 50%; animation: recPulse 1.2s infinite;
        }
        .upload-overlay {
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(0,0,0,0.85);
            display: none; align-items: center; justify-content: center;
            flex-direction: column; gap: 16px; color: #fff;
        }
        .upload-overlay .spinner-border { width: 48px; height: 48px; }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .sidebar { width: 260px; }
            .video-grid.grid-2, .video-grid.grid-3, .video-grid.grid-4 { grid-template-columns: 1fr; }
            .room-controls { gap: 8px; }
            .ctrl-btn { width: 42px; height: 42px; font-size: 18px; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #2a2a4a; border-radius: 3px; }

        /* Media banner animations */
        @keyframes slideDown {
            from { transform: translateX(-50%) translateY(-20px); opacity: 0; }
            to { transform: translateX(-50%) translateY(0); opacity: 1; }
        }

        /* ── Enable Mic Banner for students ── */
        .enable-mic-banner {
            position: fixed;
            top: 55px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 600;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(102,126,234,0.4);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.4s ease;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .enable-mic-banner:hover { transform: translateX(-50%) scale(1.03); }
        .enable-mic-banner .mic-icon { font-size: 22px; }
        .enable-mic-banner .dismiss-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: #fff;
            border-radius: 50%;
            width: 24px; height: 24px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 12px;
            margin-left: 8px;
        }

        /* ── Two-way audio badge ── */
        .twoway-badge {
            background: rgba(46,204,113,0.15);
            border: 1px solid rgba(46,204,113,0.4);
            color: #2ecc71;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .twoway-badge .tw-dot {
            width: 6px; height: 6px;
            background: #2ecc71;
            border-radius: 50%;
            animation: livePulse 2s infinite;
        }
    </style>
</head>
<body>

<!-- RECORDING INDICATOR -->
<div class="recording-indicator" id="recordingIndicator">
    <div class="rec-dot"></div>
    <span>REC</span>
    <span id="recordingTime">00:00</span>
</div>

<!-- UPLOAD OVERLAY -->
<div class="upload-overlay" id="uploadOverlay">
    <div class="spinner-border text-light" role="status"></div>
    <h5>Saving Recording...</h5>
    <p class="text-muted" id="uploadStatus">Preparing upload...</p>
</div>

<!-- TOP BAR -->
<div class="room-topbar">
    <div class="session-info">
        <div class="live-dot"></div>
        <div>
            <div class="session-title"><?= htmlspecialchars($session['session_name']) ?></div>
            <div class="session-course"><?= htmlspecialchars($session['course_name']) ?> (<?= htmlspecialchars($session['course_code']) ?>)</div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="twoway-badge" id="twowayBadge" style="display:none;"><span class="tw-dot"></span> 2-Way Audio</span>
        <span class="peer-count" id="peerCount"><i class="bi bi-people me-1"></i> 1</span>
        <span style="font-size:12px;color:#666;" id="connectionStatus">Connecting...</span>
    </div>
</div>

<!-- MAIN AREA -->
<div class="room-main">

    <!-- VIDEO GRID -->
    <div class="video-grid" id="videoGrid">
        <!-- Local video tile (always first) -->
        <div class="video-tile local-tile" id="localTile">
            <video id="localVideo" autoplay muted playsinline></video>
            <div class="tile-label">
                <span><?= $user_name ?></span>
                <?php if ($is_host): ?><span class="host-badge">HOST</span><?php endif; ?>
            </div>
            <button class="tile-minimize-btn" id="btnMinimizeSelf" onclick="toggleMinimizeSelf()" title="Minimize your video">
                <i class="bi bi-dash-lg"></i>
            </button>
            <button class="tile-fullscreen-btn" onclick="toggleTileFullscreen(this.closest('.video-tile'))" title="Fullscreen video">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>
        </div>
    </div>

    <!-- CHAT SIDEBAR -->
    <div class="sidebar" id="chatSidebar">
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
    <?php if ($is_host): ?>
    <button class="ctrl-btn record" id="btnRecord" onclick="toggleRecording()" title="Record Session">
        <i class="bi bi-record-circle"></i>
    </button>
    <?php endif; ?>
    <?php if ($is_host): ?>
    <button class="ctrl-btn end-call" onclick="endSession()" title="End Session for Everyone">
        <i class="bi bi-telephone-x-fill"></i>
    </button>
    <?php else: ?>
    <button class="ctrl-btn end-call" onclick="leaveSession()" title="Leave Session">
        <i class="bi bi-box-arrow-right"></i>
    </button>
    <?php endif; ?>
</div>

<script src="../assets/js/webrtc-room.js"></script>
<script>
(function() {
    const SESSION_ID = <?= $session_id ?>;
    const USER_ID = <?= $user_id ?>;
    const USER_NAME = <?= json_encode($user_name) ?>;
    const USER_ROLE = <?= json_encode($user_role) ?>;
    const IS_HOST = <?= $is_host ? 'true' : 'false' ?>;

    const videoGrid = document.getElementById('videoGrid');
    const localVideo = document.getElementById('localVideo');
    const statusEl = document.getElementById('connectionStatus');
    const chatMessages = document.getElementById('chatMessages');

    // ── Init WebRTC Engine ──
    VLERoom.init({
        sessionId: SESSION_ID,
        userId: USER_ID,
        userName: USER_NAME,
        userRole: USER_ROLE,

        onPeerJoined: function(peer) {
            statusEl.textContent = 'Connected';
            statusEl.style.color = '#2ecc71';
        },

        onPeerLeft: function(peerId, info) {
            removePeerTile(peerId);
            updateGridLayout();
        },

        onRemoteStream: function(peerId, stream, info) {
            addOrUpdatePeerTile(peerId, stream, info);
            updateGridLayout();
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
            console.error(err);
            statusEl.textContent = typeof err === 'string' ? err.substring(0, 60) : 'Error';
            statusEl.style.color = '#e74c3c';
        },

        onRemoteMuteToggle: function(mediaType, isMuted, fromPeerId) {
            // Lecturer remotely muted/unmuted our mic or camera — update buttons
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
        }
    });

    // ── Join the room ──
    VLERoom.joinRoom().then(function(result) {
        const localStream = result.stream;
        const mode = result.mediaMode;

        if (localStream) {
            localVideo.srcObject = localStream;
        }
        statusEl.textContent = 'Connected';
        statusEl.style.color = '#2ecc71';

        // Show 2-way audio badge when mic is active
        if (mode === 'full' || mode === 'audio') {
            show2WayBadge();
        }

        // Update UI based on media mode
        if (mode === 'audio') {
            // Mic only — no camera, show avatar
            const localVid = document.getElementById('localVideo');
            if (localVid) localVid.style.display = 'none';
            document.getElementById('localTile').innerHTML = `
                <div class="tile-avatar">${USER_NAME.charAt(0).toUpperCase()}</div>
                <div class="tile-label"><span>${USER_NAME}</span>${IS_HOST ? '<span class="host-badge">HOST</span>' : ''}</div>
            `;
            const btnVideo = document.getElementById('btnVideo');
            btnVideo.className = 'ctrl-btn off';
            btnVideo.innerHTML = '<i class="bi bi-camera-video-off-fill"></i>';
            showMediaBanner('info', '<i class="bi bi-mic-fill me-2"></i>Two-way audio active! You can hear and speak. Camera not available.', false);
        } else if (mode === 'view-only') {
            // No camera or mic — view-only, but prompt to enable mic
            document.getElementById('localTile').innerHTML = `
                <div class="tile-avatar">${USER_NAME.charAt(0).toUpperCase()}</div>
                <div class="tile-label"><span>${USER_NAME}</span><span style="background:#ffc107;color:#000;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;">LISTENER</span></div>
            `;
            const btnVideo = document.getElementById('btnVideo');
            btnVideo.className = 'ctrl-btn off';
            btnVideo.innerHTML = '<i class="bi bi-camera-video-off-fill"></i>';
            const btnAudio = document.getElementById('btnAudio');
            btnAudio.className = 'ctrl-btn off';
            btnAudio.innerHTML = '<i class="bi bi-mic-mute-fill"></i>';
            // DON'T disable buttons — clicking them will request permission
            showMediaBanner('warning', '<i class="bi bi-mic-mute-fill me-2"></i>Your microphone is not connected. Click the <strong>mic button</strong> or the banner above to enable speaking.', true);
            // Show enable mic banner after a short delay
            setTimeout(function() { showEnableMicBanner(); }, 1500);
        } else {
            // Full mode — camera + mic
            showMediaBanner('success', '<i class="bi bi-mic-fill me-2"></i><i class="bi bi-camera-video-fill me-2"></i>Two-way audio &amp; video active! Everyone can see and hear you.', false);
        }
    }).catch(function(err) {
        statusEl.textContent = 'Failed to join session';
        statusEl.style.color = '#e74c3c';
        document.getElementById('localTile').innerHTML = `
            <div class="tile-avatar">${USER_NAME.charAt(0).toUpperCase()}</div>
            <div class="tile-label"><span>${USER_NAME}</span>${IS_HOST ? '<span class="host-badge">HOST</span>' : ''}</div>
        `;
        showMediaBanner('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i>Failed to join: ' + err.message, true);
    });

    // ── Video Tile Management ──
    function addOrUpdatePeerTile(peerId, stream, info) {
        let tile = document.getElementById('tile-' + peerId);
        if (!tile) {
            tile = document.createElement('div');
            tile.id = 'tile-' + peerId;
            tile.className = 'video-tile';
            videoGrid.appendChild(tile);
        }

        const name = (info && info.user_name) ? info.user_name : 'Participant';
        const isHost = info && info.user_role === 'lecturer';
        const hostBadge = isHost ? '<span class="host-badge">HOST</span>' : '';

        tile.innerHTML = `
            <video autoplay playsinline></video>
            <div class="tile-avatar" style="display:none;">${escapeHtml(name).charAt(0).toUpperCase()}</div>
            <div class="tile-label"><span>${escapeHtml(name)}</span>${hostBadge}</div>
            <div class="tile-indicators"></div>
            <button class="tile-fullscreen-btn" onclick="toggleTileFullscreen(this.closest('.video-tile'))" title="Fullscreen video">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>
        `;

        // Double-click on tile to toggle fullscreen
        tile.addEventListener('dblclick', function(e) {
            e.preventDefault();
            toggleTileFullscreen(tile);
        });
        const video = tile.querySelector('video');
        video.srcObject = stream;

        // Ensure remote audio is NOT muted (critical for hearing the lecturer)
        video.muted = false;
        video.volume = 1.0;

        // Handle autoplay policy — browsers may block unmuted autoplay
        const playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.catch(function(err) {
                console.warn('[VLERoom] Autoplay blocked for ' + peerId + ':', err.message);
                showAudioUnblockBanner(video);
            });
        }
    }

    // Update muted / video-off indicators on a remote peer's tile
    function updatePeerMediaIndicators(peerId, state) {
        const tile = document.getElementById('tile-' + peerId);
        if (!tile) return;

        let indicators = tile.querySelector('.tile-indicators');
        if (!indicators) {
            indicators = document.createElement('div');
            indicators.className = 'tile-indicators';
            tile.appendChild(indicators);
        }

        let html = '';
        // Mic muted indicator
        if (!state.is_audio_on) {
            html += '<div class="indicator-badge mic-off" title="Microphone muted"><i class="bi bi-mic-mute-fill"></i></div>';
        }
        // Video off indicator
        if (!state.is_video_on) {
            html += '<div class="indicator-badge cam-off" title="Camera off"><i class="bi bi-camera-video-off-fill"></i></div>';
        }
        // Screen sharing indicator
        if (state.is_screen_sharing) {
            html += '<div class="indicator-badge screen-on" title="Sharing screen"><i class="bi bi-display-fill"></i></div>';
        }
        indicators.innerHTML = html;

        // Show/hide avatar when remote video is off
        const avatar = tile.querySelector('.tile-avatar');
        const video = tile.querySelector('video');
        if (avatar && video) {
            if (!state.is_video_on && !state.is_screen_sharing) {
                avatar.style.display = 'flex';
                video.style.opacity = '0';
                video.style.position = 'absolute';
            } else {
                avatar.style.display = 'none';
                video.style.opacity = '1';
                video.style.position = '';
            }
        }
    }

    // Show a banner when browser blocks autoplay audio
    let audioUnblockBannerShown = false;
    function showAudioUnblockBanner(videoElement) {
        if (audioUnblockBannerShown) return;
        audioUnblockBannerShown = true;

        const label = IS_HOST
            ? 'Click here to enable audio — hear your students'
            : 'Click here to enable audio — hear the lecturer';

        const banner = document.createElement('div');
        banner.id = 'audioUnblockBanner';
        banner.style.cssText = 'position:fixed;top:55px;left:50%;transform:translateX(-50%);z-index:9999;background:linear-gradient(135deg,#ff6b35,#e74c3c);color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 15px rgba(0,0,0,0.4);display:flex;align-items:center;gap:10px;animation:slideDown 0.3s ease;';
        banner.innerHTML = '<i class=\"bi bi-volume-up-fill\" style=\"font-size:20px;\"></i> ' + label + ' <i class=\"bi bi-arrow-right\"></i>';

        banner.onclick = function() {
            unblockAllRemoteAudio();
            banner.remove();
            audioUnblockBannerShown = false;
        };

        document.body.appendChild(banner);

        // Also handle: user clicking anywhere on the page unmutes
        document.addEventListener('click', function unmuteHandler() {
            unblockAllRemoteAudio();
            const b = document.getElementById('audioUnblockBanner');
            if (b) b.remove();
            audioUnblockBannerShown = false;
            document.removeEventListener('click', unmuteHandler);
        }, { once: true });
    }

    // Unmute and play all remote video/audio elements
    function unblockAllRemoteAudio() {
        document.querySelectorAll('.video-tile:not(.local-tile) video').forEach(function(v) {
            v.muted = false;
            v.volume = 1.0;
            v.play().catch(function() {});
        });
    }

    // ── Enable Microphone banner for view-only/listener students ──
    function showEnableMicBanner() {
        if (document.getElementById('enableMicBanner')) return;
        const banner = document.createElement('div');
        banner.id = 'enableMicBanner';
        banner.className = 'enable-mic-banner';
        banner.innerHTML = '<i class="bi bi-mic-fill mic-icon"></i>' +
            '<span>This is a <strong>two-way</strong> class — click here to <strong>enable your microphone</strong> and speak!</span>' +
            '<button class="dismiss-btn" onclick="event.stopPropagation(); dismissEnableMicBanner();" title="Dismiss">&times;</button>';
        banner.onclick = async function() {
            try {
                showToast('Requesting microphone access...', 'info');
                const mediaResult = await VLERoom.requestMedia('audio');
                const localVid = document.getElementById('localVideo');
                if (localVid && mediaResult.stream) {
                    localVid.srcObject = mediaResult.stream;
                }
                const btnAudio = document.getElementById('btnAudio');
                btnAudio.className = 'ctrl-btn on';
                btnAudio.innerHTML = '<i class="bi bi-mic-fill"></i>';
                btnAudio.disabled = false;
                showToast('Microphone enabled! You can now speak to the class.', 'success');
                dismissEnableMicBanner();
                show2WayBadge();
            } catch (err) {
                showToast('Could not access microphone: ' + err.message, 'danger');
            }
        };
        document.body.appendChild(banner);
    }

    function dismissEnableMicBanner() {
        const b = document.getElementById('enableMicBanner');
        if (b) { b.style.opacity = '0'; b.style.transition = 'opacity 0.3s'; setTimeout(function() { b.remove(); }, 300); }
    }

    // Show 2-way audio badge when mic is active
    function show2WayBadge() {
        const badge = document.getElementById('twowayBadge');
        if (badge) badge.style.display = 'inline-flex';
    }

    function removePeerTile(peerId) {
        const tile = document.getElementById('tile-' + peerId);
        if (tile) tile.remove();
    }

    function updateGridLayout() {
        const tiles = videoGrid.querySelectorAll('.video-tile');
        const count = tiles.length;
        videoGrid.className = 'video-grid';
        if (count === 1) videoGrid.classList.add('grid-1');
        else if (count === 2) videoGrid.classList.add('grid-2');
        else if (count <= 4) videoGrid.classList.add('grid-4');
        else if (count <= 6) videoGrid.classList.add('grid-6');
        else videoGrid.classList.add('grid-many');
    }

    // ── Controls ──
    window.toggleAudio = async function() {
        const mode = VLERoom.getMediaMode();
        const result = VLERoom.toggleAudio();

        // If toggle returned false, mic isn't acquired yet — try requesting it
        if (result === false && (mode === 'view-only' || mode === 'audio')) {
            try {
                showToast('Requesting microphone access...', 'info');
                const mediaResult = await VLERoom.requestMedia('audio');
                // Update local video display if stream now exists
                const localVid = document.getElementById('localVideo');
                if (localVid && mediaResult.stream) {
                    localVid.srcObject = mediaResult.stream;
                }
                const btn = document.getElementById('btnAudio');
                btn.className = 'ctrl-btn on';
                btn.innerHTML = '<i class="bi bi-mic-fill"></i>';
                btn.disabled = false;
                showToast('Microphone enabled! You can now speak.', 'success');
                dismissEnableMicBanner();
                show2WayBadge();
                return;
            } catch (err) {
                showToast('Microphone access denied: ' + err.message, 'danger');
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

        // If toggle returned false, camera isn't acquired — try requesting it
        if (result === false && (mode === 'view-only' || mode === 'audio')) {
            try {
                showToast('Requesting camera access...', 'info');
                const mediaResult = await VLERoom.requestMedia(mode === 'view-only' ? 'both' : 'video');
                const localVid = document.getElementById('localVideo');
                if (localVid && mediaResult.stream) {
                    localVid.srcObject = mediaResult.stream;
                    localVid.style.display = '';
                }
                // Remove avatar if showing
                const localTile = document.getElementById('localTile');
                const av = localTile.querySelector('.tile-avatar');
                if (av) av.remove();
                // Remove LISTENER badge
                const listenerBadge = localTile.querySelector('.tile-label span[style]');
                if (listenerBadge && listenerBadge.textContent === 'LISTENER') listenerBadge.remove();

                const btn = document.getElementById('btnVideo');
                btn.className = 'ctrl-btn on';
                btn.innerHTML = '<i class="bi bi-camera-video-fill"></i>';
                btn.disabled = false;
                const btnAudio = document.getElementById('btnAudio');
                if (mode === 'view-only') {
                    btnAudio.className = 'ctrl-btn on';
                    btnAudio.innerHTML = '<i class="bi bi-mic-fill"></i>';
                    btnAudio.disabled = false;
                }
                showToast('Camera enabled!', 'success');
                dismissEnableMicBanner();
                show2WayBadge();
                return;
            } catch (err) {
                showToast('Camera access denied: ' + err.message, 'danger');
                return;
            }
        }

        const on = result;
        const btn = document.getElementById('btnVideo');
        btn.className = 'ctrl-btn ' + (on ? 'on' : 'off');
        btn.innerHTML = on ? '<i class="bi bi-camera-video-fill"></i>' : '<i class="bi bi-camera-video-off-fill"></i>';
        
        // Show/hide local video
        const localVid = document.getElementById('localVideo');
        if (localVid) localVid.style.display = on ? '' : 'none';
        
        const localTile = document.getElementById('localTile');
        if (!on) {
            if (!localTile.querySelector('.tile-avatar')) {
                const avatar = document.createElement('div');
                avatar.className = 'tile-avatar';
                avatar.textContent = USER_NAME.charAt(0).toUpperCase();
                localTile.insertBefore(avatar, localTile.firstChild);
            }
        } else {
            const av = localTile.querySelector('.tile-avatar');
            if (av) av.remove();
        }
    };

    window.toggleScreen = async function() {
        const on = await VLERoom.toggleScreenShare();
        const btn = document.getElementById('btnScreen');
        btn.className = 'ctrl-btn screen ' + (on ? '' : 'off');
        btn.innerHTML = on ? '<i class="bi bi-display-fill"></i>' : '<i class="bi bi-display"></i>';
    };

    window.toggleSidebar = function() {
        document.getElementById('chatSidebar').classList.toggle('collapsed');
        updateGridLayout();
    };

    // ── Fullscreen toggle (whole page) ──
    window.toggleFullscreen = function() {
        const doc = document;
        const elem = doc.documentElement;
        if (!doc.fullscreenElement && !doc.webkitFullscreenElement && !doc.msFullscreenElement) {
            if (elem.requestFullscreen) { elem.requestFullscreen(); }
            else if (elem.webkitRequestFullscreen) { elem.webkitRequestFullscreen(); }
            else if (elem.msRequestFullscreen) { elem.msRequestFullscreen(); }
        } else {
            if (doc.exitFullscreen) { doc.exitFullscreen(); }
            else if (doc.webkitExitFullscreen) { doc.webkitExitFullscreen(); }
            else if (doc.msExitFullscreen) { doc.msExitFullscreen(); }
        }
    };

    // ── Per-tile fullscreen (fullscreen a single video) ──
    window.toggleTileFullscreen = function(tile) {
        if (!tile) return;
        const doc = document;
        const fsElement = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;

        if (fsElement === tile) {
            // Exit tile fullscreen
            if (doc.exitFullscreen) { doc.exitFullscreen(); }
            else if (doc.webkitExitFullscreen) { doc.webkitExitFullscreen(); }
            else if (doc.msExitFullscreen) { doc.msExitFullscreen(); }
        } else {
            // Enter tile fullscreen
            if (tile.requestFullscreen) { tile.requestFullscreen(); }
            else if (tile.webkitRequestFullscreen) { tile.webkitRequestFullscreen(); }
            else if (tile.msRequestFullscreen) { tile.msRequestFullscreen(); }
        }
    };

    // Update tile fullscreen button icon on state change
    function updateTileFullscreenBtns() {
        const fsElement = document.fullscreenElement || document.webkitFullscreenElement;
        document.querySelectorAll('.video-tile .tile-fullscreen-btn i').forEach(function(icon) {
            const tile = icon.closest('.video-tile');
            if (fsElement === tile) {
                icon.className = 'bi bi-fullscreen-exit';
            } else {
                icon.className = 'bi bi-arrows-fullscreen';
            }
        });
    }
    document.addEventListener('fullscreenchange', updateTileFullscreenBtns);
    document.addEventListener('webkitfullscreenchange', updateTileFullscreenBtns);

    // Listen for fullscreen change to update button icon
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
        div.innerHTML = `
            <span class="chat-author ${isMe ? '' : ''}">${escapeHtml(msg.user_name)} <span class="chat-time">${time}</span></span>
            <div class="chat-text">${escapeHtml(msg.message)}</div>
        `;
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // ── Recording ──
    let recordingTimer = null;
    let recordingSeconds = 0;

    function updateRecordingTime() {
        recordingSeconds++;
        const m = String(Math.floor(recordingSeconds / 60)).padStart(2, '0');
        const s = String(recordingSeconds % 60).padStart(2, '0');
        document.getElementById('recordingTime').textContent = m + ':' + s;
    }

    window.toggleRecording = async function() {
        const btn = document.getElementById('btnRecord');
        const indicator = document.getElementById('recordingIndicator');

        if (!VLERoom.getIsRecording()) {
            // Start recording
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            try {
                const started = await VLERoom.startRecording();
                if (started) {
                    btn.classList.add('active');
                    btn.innerHTML = '<i class="bi bi-stop-circle-fill"></i>';
                    btn.title = 'Stop Recording';
                    indicator.style.display = 'flex';
                    recordingSeconds = 0;
                    recordingTimer = setInterval(updateRecordingTime, 1000);
                    showToast('Recording started', 'success');
                } else {
                    throw new Error('Could not start recording');
                }
            } catch (err) {
                console.error('[Recording] Start error:', err);
                showToast('Failed to start recording: ' + err.message, 'danger');
            }
            btn.disabled = false;
        } else {
            // Stop recording
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            clearInterval(recordingTimer);
            indicator.style.display = 'none';

            try {
                const blob = await VLERoom.stopRecording();
                if (blob && blob.size > 0) {
                    showUploadOverlay('Uploading recording (' + (blob.size / 1024 / 1024).toFixed(1) + ' MB)...');
                    const result = await VLERoom.uploadRecording(blob);
                    hideUploadOverlay();
                    if (result && result.success) {
                        showToast('Recording saved successfully! Students can now watch it.', 'success');
                    } else {
                        showToast('Recording upload failed: ' + (result?.message || 'Unknown error'), 'danger');
                        console.error('[Recording] Upload failed:', result);
                    }
                } else {
                    showToast('Recording produced no data. Try recording for a longer duration.', 'warning');
                }
            } catch (err) {
                console.error('[Recording] Stop/upload error:', err);
                hideUploadOverlay();
                showToast('Error saving recording: ' + err.message, 'danger');
            }

            btn.disabled = false;
            btn.classList.remove('active');
            btn.innerHTML = '<i class="bi bi-record-circle"></i>';
            btn.title = 'Record Session';
        }
    };

    // Toast notification helper
    function showToast(message, type) {
        type = type || 'info';
        const colors = { success: '#198754', danger: '#dc3545', warning: '#ffc107', info: '#0dcaf0' };
        const textColor = type === 'warning' ? '#000' : '#fff';
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:60px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;color:' + textColor + ';background:' + (colors[type] || colors.info) + ';box-shadow:0 4px 12px rgba(0,0,0,0.3);font-size:14px;max-width:400px;animation:fadeIn 0.3s ease;';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; }, 4000);
        setTimeout(() => { toast.remove(); }, 4500);
    }

    function showUploadOverlay(msg) {
        const ov = document.getElementById('uploadOverlay');
        document.getElementById('uploadStatus').textContent = msg || 'Uploading...';
        ov.style.display = 'flex';
    }
    function hideUploadOverlay() {
        document.getElementById('uploadOverlay').style.display = 'none';
    }

    // ── End/Leave ──
    window.endSession = async function() {
        if (!confirm('End ALL your active sessions? This will disconnect all students from every session you are hosting.')) return;

        // Auto-stop recording and upload if active
        if (VLERoom.getIsRecording()) {
            clearInterval(recordingTimer);
            const indicator = document.getElementById('recordingIndicator');
            if (indicator) indicator.style.display = 'none';
            showUploadOverlay('Saving recording before ending session...');
            try {
                const blob = await VLERoom.stopRecording();
                if (blob && blob.size > 0) {
                    document.getElementById('uploadStatus').textContent = 'Uploading recording (' + (blob.size / 1024 / 1024).toFixed(1) + ' MB)...';
                    const result = await VLERoom.uploadRecording(blob);
                    if (result && result.success) {
                        console.log('[Recording] Auto-saved on session end');
                    } else {
                        console.error('[Recording] Auto-save failed:', result);
                    }
                }
            } catch (err) {
                console.error('[Recording] Auto-save error on end:', err);
            }
            hideUploadOverlay();
        }

        await VLERoom.leaveRoom();
        // End session via API
        const fd = new FormData();
        fd.append('action', 'end_session');
        fd.append('session_id', SESSION_ID);
        await fetch('../api/live_session_api.php', { method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        window.location.href = 'live_classroom.php';
    };

    window.leaveSession = async function() {
        if (!confirm('Leave this session?')) return;
        await VLERoom.leaveRoom();
        window.location.href = '../student/live_invites.php';
    };

    // ── Cleanup on page close ──
    window.addEventListener('beforeunload', function() {
        VLERoom.leaveRoom();
    });

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    // ── Media permission banner ──
    function showMediaBanner(type, message, showRefresh) {
        const colors = { info: '#0d6efd', warning: '#ffc107', danger: '#dc3545', success: '#198754' };
        const textColors = { info: '#fff', warning: '#000', danger: '#fff', success: '#fff' };
        const banner = document.createElement('div');
        banner.className = 'media-banner';
        banner.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:500;background:' + (colors[type] || colors.info) + ';color:' + (textColors[type] || '#fff') + ';padding:10px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 15px rgba(0,0,0,0.3);display:flex;align-items:center;gap:8px;max-width:600px;text-align:center;';
        let html = message;
        if (showRefresh) {
            html += ' <button onclick=\"location.reload()\" style=\"background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);color:inherit;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin-left:8px;\"><i class=\"bi bi-arrow-clockwise me-1\"></i>Refresh</button>';
        }
        html += '<button onclick=\"this.parentElement.remove()\" style=\"background:none;border:none;color:inherit;cursor:pointer;font-size:16px;margin-left:8px;opacity:0.7;\">&times;</button>';
        banner.innerHTML = html;
        document.body.appendChild(banner);

        // Auto-dismiss info banners after 8 seconds
        if (type === 'info' || type === 'success') {
            setTimeout(function() { if (banner.parentNode) { banner.style.opacity = '0'; banner.style.transition = 'opacity 0.5s'; setTimeout(function() { banner.remove(); }, 500); } }, 8000);
        }
    }

    updateGridLayout();

    // Enable double-click fullscreen on local tile
    const localTile = document.getElementById('localTile');
    localTile.addEventListener('dblclick', function(e) {
        e.preventDefault();
        toggleTileFullscreen(localTile);
    });

    // ── Minimize / Restore self-view (Picture-in-Picture style) ──
    let selfMinimized = false;

    window.toggleMinimizeSelf = function() {
        const tile = document.getElementById('localTile');
        const btnBar = document.getElementById('btnMinimize');
        const btnTile = document.getElementById('btnMinimizeSelf');

        selfMinimized = !selfMinimized;

        if (selfMinimized) {
            // Remove from grid and make floating PiP
            tile.classList.add('minimized');
            // Move tile out of grid to body so it floats
            document.body.appendChild(tile);
            if (btnBar) {
                btnBar.className = 'ctrl-btn off';
                btnBar.innerHTML = '<i class="bi bi-pip"></i>';
                btnBar.title = 'Restore your video';
            }
            if (btnTile) {
                btnTile.innerHTML = '<i class="bi bi-arrows-angle-expand"></i>';
                btnTile.title = 'Restore your video';
            }
        } else {
            // Restore to grid
            tile.classList.remove('minimized');
            tile.style.left = '';
            tile.style.top = '';
            tile.style.right = '';
            tile.style.bottom = '';
            tile.style.width = '';
            tile.style.height = '';
            // Put back as first child in grid
            const grid = document.getElementById('videoGrid');
            grid.insertBefore(tile, grid.firstChild);
            if (btnBar) {
                btnBar.className = 'ctrl-btn on';
                btnBar.innerHTML = '<i class="bi bi-pip"></i>';
                btnBar.title = 'Minimize your video';
            }
            if (btnTile) {
                btnTile.innerHTML = '<i class="bi bi-dash-lg"></i>';
                btnTile.title = 'Minimize your video';
            }
        }
        updateGridLayout();
    };

    // ── Drag support for minimized self-view ──
    (function() {
        let isDragging = false;
        let dragOffsetX = 0, dragOffsetY = 0;

        document.addEventListener('mousedown', function(e) {
            const tile = document.getElementById('localTile');
            if (!tile || !tile.classList.contains('minimized')) return;
            if (e.target.closest('button')) return; // Don't drag when clicking buttons
            if (!tile.contains(e.target)) return;

            isDragging = true;
            const rect = tile.getBoundingClientRect();
            dragOffsetX = e.clientX - rect.left;
            dragOffsetY = e.clientY - rect.top;
            tile.style.transition = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            const tile = document.getElementById('localTile');
            if (!tile) return;

            // Switch from right/bottom to left/top positioning for drag
            tile.style.right = 'auto';
            tile.style.bottom = 'auto';
            tile.style.left = Math.max(0, Math.min(window.innerWidth - tile.offsetWidth, e.clientX - dragOffsetX)) + 'px';
            tile.style.top = Math.max(0, Math.min(window.innerHeight - tile.offsetHeight, e.clientY - dragOffsetY)) + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (!isDragging) return;
            isDragging = false;
            const tile = document.getElementById('localTile');
            if (tile) tile.style.transition = '';
        });

        // Touch support for mobile
        document.addEventListener('touchstart', function(e) {
            const tile = document.getElementById('localTile');
            if (!tile || !tile.classList.contains('minimized')) return;
            if (e.target.closest('button')) return;
            if (!tile.contains(e.target)) return;

            isDragging = true;
            const touch = e.touches[0];
            const rect = tile.getBoundingClientRect();
            dragOffsetX = touch.clientX - rect.left;
            dragOffsetY = touch.clientY - rect.top;
            tile.style.transition = 'none';
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            const tile = document.getElementById('localTile');
            if (!tile) return;
            const touch = e.touches[0];
            tile.style.right = 'auto';
            tile.style.bottom = 'auto';
            tile.style.left = Math.max(0, Math.min(window.innerWidth - tile.offsetWidth, touch.clientX - dragOffsetX)) + 'px';
            tile.style.top = Math.max(0, Math.min(window.innerHeight - tile.offsetHeight, touch.clientY - dragOffsetY)) + 'px';
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (!isDragging) return;
            isDragging = false;
            const tile = document.getElementById('localTile');
            if (tile) tile.style.transition = '';
        });
    })();
})();
</script>
</body>
</html>
