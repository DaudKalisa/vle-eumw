/**
 * VLE Live Classroom - WebRTC Engine
 * 
 * Handles: camera, microphone, screen sharing, peer connections,
 * signaling via PHP polling, chat, and UI updates.
 * 
 * No external API needed - pure WebRTC with PHP-based signaling.
 * Compatible with: Chrome, Firefox, Safari, Edge, iOS Safari, Android
 * 
 * TURN server support enables connections from ANY network:
 * - Mobile data (4G/5G)
 * - Home WiFi behind NAT
 * - Corporate/university networks with firewalls
 * - Different ISPs and countries
 */
(function () {
    'use strict';

    const SIGNAL_API = '../api/webrtc_signal.php';
    const ICE_CONFIG_API = '../api/ice_config.php';
    const POLL_INTERVAL = 3000;    // Signal polling (ms) — 3s to stay under free hosting rate limits
    const HEARTBEAT_INTERVAL = 10000;  // 10s heartbeat — gentler on server
    const CHAT_POLL_INTERVAL = 4000;   // 4s chat poll
    const ICE_RESTART_TIMEOUT = 10000;  // Restart ICE after 10s of failure
    const ICE_CONFIG_CACHE_TTL = 300000; // Cache ICE config for 5 minutes
    const MEDIA_TIMEOUT = 15000;   // Camera/mic acquisition timeout (ms)
    const FETCH_TIMEOUT = 15000;   // API fetch timeout (ms)
    const JOIN_TIMEOUT = 45000;    // Overall join timeout (ms)

    // Track consecutive API failures for exponential backoff
    let apiFailCount = 0;
    const MAX_BACKOFF = 15000;

    // Detect iOS/Safari for special handling
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    const isIOSSafari = isIOS && isSafari;

    // Default STUN-only fallback (used if ICE config API fails)
    const DEFAULT_ICE_SERVERS = [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
    ];

    // Dynamic ICE configuration (fetched from server, includes TURN)
    let ICE_SERVERS = DEFAULT_ICE_SERVERS;
    let iceTransportPolicy = 'all';
    let iceConfigCachedAt = 0;

    // ─── STATE ────────────────────────────────────────────────────
    let sessionId = 0;
    let myPeerId = '';
    let myUserId = 0;
    let myUserName = '';
    let myRole = 'student'; // 'lecturer' or 'student'

    let localStream = null;
    let screenStream = null;
    let peerConnections = {};   // peerId -> RTCPeerConnection
    let remoteStreams = {};     // peerId -> MediaStream
    let peerInfo = {};          // peerId -> { user_name, user_role, ... }

    let isAudioOn = true;
    let isVideoOn = true;
    let isScreenSharing = false;
    let isInRoom = false;
    let isRecording = false;
    let mediaRecorder = null;
    let recordedChunks = [];

    let pollTimer = null;
    let heartbeatTimer = null;
    let chatPollTimer = null;
    let lastChatId = 0;

    // Callbacks for UI updates
    let onPeerJoined = null;
    let onPeerLeft = null;
    let onRemoteStream = null;
    let onChatMessage = null;
    let onPeerCountUpdate = null;
    let onPeerMediaStateUpdate = null;
    let onSessionEnded = null;
    let onError = null;
    let onStatusUpdate = null;  // Status callback for loading overlay

    // ─── INITIALIZATION ───────────────────────────────────────────
    function init(config) {
        sessionId = config.sessionId;
        myUserId = config.userId;
        myUserName = config.userName;
        myRole = config.userRole || 'student';
        myPeerId = myRole + '_' + myUserId + '_' + Date.now();

        onPeerJoined = config.onPeerJoined || function () {};
        onPeerLeft = config.onPeerLeft || function () {};
        onRemoteStream = config.onRemoteStream || function () {};
        onChatMessage = config.onChatMessage || function () {};
        onPeerCountUpdate = config.onPeerCountUpdate || function () {};
        onPeerMediaStateUpdate = config.onPeerMediaStateUpdate || function () {};
        onSessionEnded = config.onSessionEnded || function () {};
        onError = config.onError || function (e) { console.error('VLERoom error:', e); };
        onStatusUpdate = config.onStatusUpdate || function () {};
    }

    // ─── HELPER: FETCH WITH TIMEOUT ──────────────────────────────
    /**
     * Wrapper around fetch() with AbortController timeout.
     * Prevents API calls from hanging indefinitely on slow servers.
     */
    function fetchWithTimeout(url, options, timeout) {
        timeout = timeout || FETCH_TIMEOUT;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        options = options || {};
        options.signal = controller.signal;
        // Always send credentials (cookies) so InfinityFree anti-bot cookie works
        options.credentials = options.credentials || 'same-origin';
        return fetch(url, options).finally(() => clearTimeout(timer));
    }

    /**
     * Safely parse JSON from a fetch response.
     * InfinityFree (and similar free hosts) sometimes return HTML challenge
     * pages instead of JSON. This detects that and returns a fallback.
     */
    async function safeJsonParse(response, fallback) {
        fallback = fallback || { success: false, error: 'Invalid server response' };
        try {
            const text = await response.text();
            // Check if response looks like HTML (InfinityFree anti-bot page)
            if (text.trim().startsWith('<') || text.trim().startsWith('<!')) {
                console.warn('[VLERoom] Server returned HTML instead of JSON (possible anti-bot challenge). Retrying...');
                apiFailCount++;
                return fallback;
            }
            apiFailCount = Math.max(0, apiFailCount - 1); // Decay on success
            return JSON.parse(text);
        } catch (e) {
            console.warn('[VLERoom] JSON parse error:', e.message);
            apiFailCount++;
            return fallback;
        }
    }

    /**
     * Get current polling interval with exponential backoff on failures.
     * Reduces request rate when server is struggling.
     */
    function getBackoffInterval(baseInterval) {
        if (apiFailCount <= 0) return baseInterval;
        const backoff = Math.min(baseInterval * Math.pow(1.5, apiFailCount), MAX_BACKOFF);
        return Math.round(backoff);
    }

    /**
     * Wrapper around getUserMedia with a timeout.
     * If camera/mic don't respond within the timeout, rejects.
     */
    function getUserMediaWithTimeout(constraints, timeout) {
        timeout = timeout || MEDIA_TIMEOUT;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                reject(new Error('Camera/microphone request timed out. Please check your device permissions.'));
            }, timeout);
            navigator.mediaDevices.getUserMedia(constraints)
                .then(stream => { clearTimeout(timer); resolve(stream); })
                .catch(err => { clearTimeout(timer); reject(err); });
        });
    }

    // ─── ICE SERVER CONFIGURATION ────────────────────────────────
    /**
     * Fetch TURN/STUN server configuration from the backend.
     * This is critical for cross-network connectivity.
     * Falls back to STUN-only if the API is unavailable.
     */
    async function fetchIceConfig() {
        // Use cached config if still valid
        if (iceConfigCachedAt > 0 && (Date.now() - iceConfigCachedAt) < ICE_CONFIG_CACHE_TTL) {
            console.log('[VLERoom] Using cached ICE config');
            return;
        }

        try {
            const res = await fetchWithTimeout(ICE_CONFIG_API, { 
                method: 'GET',
                credentials: 'same-origin'
            }, 8000);
            const data = await safeJsonParse(res, { success: false });
            
            if (data.success && data.iceServers && data.iceServers.length > 0) {
                ICE_SERVERS = data.iceServers;
                iceTransportPolicy = data.iceTransportPolicy || 'all';
                iceConfigCachedAt = Date.now();
                
                const turnCount = ICE_SERVERS.filter(s => {
                    const u = Array.isArray(s.urls) ? s.urls.join(',') : (s.urls || '');
                    return u.includes('turn:') || u.includes('turns:');
                }).length;
                console.log('[VLERoom] ICE config loaded: ' + ICE_SERVERS.length + ' servers (' + turnCount + ' TURN relays)');
                
                if (turnCount === 0) {
                    console.warn('[VLERoom] WARNING: No TURN servers configured! Cross-network connections may fail.');
                    console.warn('[VLERoom] Configure TURN servers in includes/turn_config.php');
                }
            } else {
                console.warn('[VLERoom] ICE config API returned no servers, using defaults');
            }
        } catch (e) {
            console.warn('[VLERoom] Could not fetch ICE config, using STUN-only fallback:', e.message);
        }
    }

    /**
     * Allow passing ICE config directly (from PHP-injected JSON)
     * This avoids an extra API call when config is embedded in the page.
     */
    function setIceConfig(config) {
        if (config && config.iceServers && config.iceServers.length > 0) {
            ICE_SERVERS = config.iceServers;
            iceTransportPolicy = config.iceTransportPolicy || 'all';
            iceConfigCachedAt = Date.now();
            console.log('[VLERoom] ICE config set directly: ' + ICE_SERVERS.length + ' servers');
        }
    }

    // ─── JOIN ROOM ────────────────────────────────────────────────
    let mediaMode = 'full'; // 'full' | 'audio' | 'view-only'

    async function joinRoom() {
        // Wrap the entire join in a timeout to prevent infinite hangs
        const joinPromise = _doJoinRoom();
        const timeoutPromise = new Promise((_, reject) => {
            setTimeout(() => reject(new Error('Connection timed out. The server may be slow or unreachable. Please try again.')), JOIN_TIMEOUT);
        });
        return Promise.race([joinPromise, timeoutPromise]);
    }

    /**
     * Check camera/mic permission state BEFORE calling getUserMedia.
     * Returns 'granted', 'denied', 'prompt', or 'unknown'.
     */
    async function checkPermission(name) {
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const result = await navigator.permissions.query({ name: name });
                return result.state; // 'granted' | 'denied' | 'prompt'
            }
        } catch (e) {
            // Firefox doesn't support camera/microphone permission query
        }
        return 'unknown';
    }

    /**
     * Helper: call the signaling join API with automatic retry on anti-bot HTML.
     * InfinityFree serves an HTML challenge page on first contact;
     * once the browser sets the cookie, subsequent calls work.
     */
    async function signalJoinWithRetry(maxRetries) {
        maxRetries = maxRetries || 3;
        for (let attempt = 1; attempt <= maxRetries; attempt++) {
            const fd = new FormData();
            fd.append('action', 'join');
            fd.append('session_id', sessionId);
            fd.append('peer_id', myPeerId);

            try {
                const res = await fetchWithTimeout(SIGNAL_API, { method: 'POST', body: fd });
                const data = await safeJsonParse(res);

                if (data.success) return data;

                // If error is "Session not active" don't retry — it's a real error
                if (data.error && (data.error.indexOf('not active') !== -1 || data.error.indexOf('Missing') !== -1)) {
                    throw new Error(data.error);
                }

                // Anti-bot or transient error — retry after delay
                if (attempt < maxRetries) {
                    console.warn('[VLERoom] Join attempt ' + attempt + ' failed (' + (data.error || 'unknown') + '), retrying in 2s...');
                    onStatusUpdate('Server warming up... (attempt ' + attempt + '/' + maxRetries + ')');
                    await new Promise(r => setTimeout(r, 2000));
                } else {
                    throw new Error(data.error || 'Failed to join after ' + maxRetries + ' attempts. Please retry.');
                }
            } catch (err) {
                if (err.name === 'AbortError' && attempt < maxRetries) {
                    console.warn('[VLERoom] Join attempt ' + attempt + ' timed out, retrying...');
                    onStatusUpdate('Retrying connection... (attempt ' + attempt + '/' + maxRetries + ')');
                    await new Promise(r => setTimeout(r, 2000));
                } else {
                    throw err;
                }
            }
        }
    }

    async function _doJoinRoom() {
        // Step 1: Fetch TURN/STUN server config (critical for cross-network)
        onStatusUpdate('Configuring network...');
        await fetchIceConfig();

        // Step 2: Pre-check permissions (skip prompt wait if denied)
        onStatusUpdate('Checking permissions...');
        const camPerm = await checkPermission('camera');
        const micPerm = await checkPermission('microphone');
        console.log('[VLERoom] Permission state — camera: ' + camPerm + ', mic: ' + micPerm);

        // Step 3: Get camera + mic with graceful fallback
        if (camPerm === 'denied' && micPerm === 'denied') {
            // Both denied — skip straight to view-only (no prompt wait)
            console.log('[VLERoom] Both camera & mic denied — joining view-only');
            onStatusUpdate('Permissions denied — joining as viewer...');
            localStream = null;
            mediaMode = 'view-only';
            isAudioOn = false;
            isVideoOn = false;
        } else {
            onStatusUpdate('Requesting camera & microphone...');
            localStream = await acquireMediaStream();
        }

        // Step 4: Register with signaling server (auto-retry for anti-bot)
        onStatusUpdate('Joining session...');
        try {
            const data = await signalJoinWithRetry(3);

            isInRoom = true;
            onStatusUpdate('Connected! Setting up peers...');

            // Connect to existing peers
            if (data.peers && data.peers.length > 0) {
                for (const peer of data.peers) {
                    peerInfo[peer.peer_id] = peer;
                    onPeerJoined(peer);
                    await createPeerConnection(peer.peer_id, true);
                }
            }

            // Start polling with adaptive intervals
            schedulePolling();

            return { stream: localStream, mediaMode: mediaMode };
        } catch (err) {
            if (err.name === 'AbortError') {
                throw new Error('Server connection timed out. Please check your internet and try again.');
            }
            onError('Failed to join room: ' + err.message);
            throw err;
        }
    }

    /**
     * Graceful media acquisition: camera+mic → mic-only → view-only
     * Ensures the student can ALWAYS join and hear the lecturer
     * Supports iOS Safari, Android Chrome, and all desktop browsers
     */
    async function acquireMediaStream() {
        // If permissions are already granted, proceed silently.
        // If denied, skip directly to view-only (no blocking prompt).
        const camState = await checkPermission('camera');
        const micState = await checkPermission('microphone');

        if (camState === 'denied' && micState === 'denied') {
            console.log('[VLERoom] Both permissions denied — view-only');
            mediaMode = 'view-only';
            isAudioOn = false;
            isVideoOn = false;
            onError('Camera and microphone access denied. You can see and hear the class but cannot share audio/video. Check browser site settings to allow access.');
            return null;
        }

        // iOS Safari requires user interaction before media capture
        if (isIOS) {
            console.log('[VLERoom] iOS detected - using iOS-optimized media constraints');
        }

        // Get optimal video constraints based on device
        function getVideoConstraints() {
            if (isIOS) {
                // iOS Safari works better with simpler constraints
                return {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                };
            }
            return {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user'
            };
        }

        // Get optimal audio constraints based on device
        function getAudioConstraints() {
            if (isIOS) {
                // iOS Safari has limited audio constraint support
                return true; // Simple true works best on iOS
            }
            return {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            };
        }

        // Attempt 1: Camera + Microphone (full experience)
        try {
            const stream = await getUserMediaWithTimeout({
                video: getVideoConstraints(),
                audio: getAudioConstraints()
            });
            mediaMode = 'full';
            isAudioOn = true;
            isVideoOn = true;
            console.log('[VLERoom] Media acquired: camera + microphone');
            return stream;
        } catch (e) {
            console.warn('[VLERoom] Camera+Mic failed:', e.name, e.message, '- trying mic-only...');
        }

        // Attempt 2: Microphone only (can talk and hear, no video)
        try {
            const stream = await getUserMediaWithTimeout({
                video: false,
                audio: getAudioConstraints()
            });
            mediaMode = 'audio';
            isAudioOn = true;
            isVideoOn = false;
            console.log('[VLERoom] Media acquired: microphone only');
            onError('Camera not available — joined with microphone only. You can still hear and talk.');
            return stream;
        } catch (e) {
            console.warn('[VLERoom] Mic-only failed:', e.name, e.message, '- joining view-only...');
        }

        // Attempt 3: View-only (can see and hear the lecturer, but can't share own audio/video)
        mediaMode = 'view-only';
        isAudioOn = false;
        isVideoOn = false;
        console.log('[VLERoom] Joining as view-only (no local media)');
        onError('Camera and microphone not available — joined as listener. You can see and hear the class.');
        return null;
    }

    function getMediaMode() { return mediaMode; }

    /**
     * Request additional media after initial join.
     * type: 'audio' | 'video' | 'both'
     * Used when student initially joins view-only / audio-only and later wants to upgrade.
     */
    async function requestMedia(type) {
        const constraints = {};
        if (type === 'audio' || type === 'both') {
            constraints.audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        }
        if (type === 'video' || type === 'both') {
            constraints.video = { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' };
        }

        const newStream = await navigator.mediaDevices.getUserMedia(constraints);

        if (!localStream) {
            localStream = newStream;
        } else {
            // Merge new tracks into existing stream
            newStream.getTracks().forEach(function(track) {
                localStream.addTrack(track);
            });
        }

        // Update tracks in all peer connections
        for (const peerId in peerConnections) {
            const pc = peerConnections[peerId];
            const senders = pc.getSenders();
            newStream.getTracks().forEach(function(track) {
                const existing = senders.find(s => s.track && s.track.kind === track.kind);
                if (existing) {
                    existing.replaceTrack(track);
                } else {
                    pc.addTrack(track, localStream);
                }
            });
        }

        // Update state
        if (constraints.audio) { isAudioOn = true; mediaMode = mediaMode === 'view-only' ? 'audio' : mediaMode; notifyMediaState('audio', 1); }
        if (constraints.video) { isVideoOn = true; mediaMode = 'full'; notifyMediaState('video', 1); }
        if (mediaMode === 'audio' && constraints.video) mediaMode = 'full';

        return { stream: localStream, mediaMode: mediaMode };
    }

    // ─── LEAVE ROOM ──────────────────────────────────────────────
    async function leaveRoom() {
        isInRoom = false;

        clearTimeout(pollTimer);
        clearTimeout(heartbeatTimer);
        clearTimeout(chatPollTimer);

        // Close all peer connections
        for (const peerId in peerConnections) {
            peerConnections[peerId].close();
        }
        peerConnections = {};
        remoteStreams = {};

        // Stop local streams
        if (localStream) {
            localStream.getTracks().forEach(t => t.stop());
            localStream = null;
        }
        if (screenStream) {
            screenStream.getTracks().forEach(t => t.stop());
            screenStream = null;
        }

        // Notify server
        const fd = new FormData();
        fd.append('action', 'leave');
        fd.append('session_id', sessionId);
        fd.append('peer_id', myPeerId);
        try { await fetch(SIGNAL_API, { method: 'POST', body: fd }); } catch (e) {}
    }

    // ─── ADAPTIVE POLLING SCHEDULER ───────────────────────────────
    /**
     * Start polling loops using setTimeout (not setInterval) so each poll
     * waits for the previous one to finish. Includes exponential backoff
     * when the server is returning errors (e.g. InfinityFree rate limiting).
     */
    function schedulePolling() {
        // Use setTimeout-based polling — each call reschedules itself
        // This prevents overlapping requests that overwhelm free hosting
        pollTimer = setTimeout(pollSignals, POLL_INTERVAL);
        heartbeatTimer = setTimeout(sendHeartbeat, HEARTBEAT_INTERVAL);
        chatPollTimer = setTimeout(pollChat, CHAT_POLL_INTERVAL);
    }

    // ─── PEER CONNECTION ──────────────────────────────────────────
    async function createPeerConnection(remotePeerId, isInitiator) {
        if (peerConnections[remotePeerId]) {
            peerConnections[remotePeerId].close();
        }

        const pc = new RTCPeerConnection({ 
            iceServers: ICE_SERVERS,
            iceTransportPolicy: iceTransportPolicy
        });
        peerConnections[remotePeerId] = pc;

        // Add local tracks (if available — view-only users have no local stream)
        if (localStream) {
            localStream.getTracks().forEach(track => {
                pc.addTrack(track, localStream);
            });
        } else {
            // For view-only mode: add a transceiver to receive audio and video
            // This is required for the peer to send us their tracks
            try {
                pc.addTransceiver('audio', { direction: 'recvonly' });
                pc.addTransceiver('video', { direction: 'recvonly' });
            } catch (e) {
                console.warn('[VLERoom] Could not add receive-only transceivers:', e.message);
            }
        }

        // Add screen share track if active
        if (screenStream) {
            screenStream.getTracks().forEach(track => {
                pc.addTrack(track, screenStream);
            });
        }

        // ICE candidates
        pc.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal(remotePeerId, 'ice', JSON.stringify(event.candidate));
            }
        };

        // ICE connection state monitoring with auto-restart
        let iceRestartTimer = null;
        pc.oniceconnectionstatechange = () => {
            const state = pc.iceConnectionState;
            console.log('[VLERoom] ICE connection state [' + remotePeerId.substring(0, 15) + ']: ' + state);
            
            if (state === 'connected' || state === 'completed') {
                // Connection successful — clear any restart timer
                if (iceRestartTimer) { clearTimeout(iceRestartTimer); iceRestartTimer = null; }
                logConnectionType(pc, remotePeerId);
            } else if (state === 'failed') {
                // Connection failed — attempt ICE restart
                console.warn('[VLERoom] ICE failed for peer ' + remotePeerId + ', attempting ICE restart...');
                attemptIceRestart(pc, remotePeerId);
            } else if (state === 'disconnected') {
                // Temporarily disconnected — wait before restarting
                console.warn('[VLERoom] ICE disconnected for peer ' + remotePeerId + ', will restart if not recovered...');
                if (iceRestartTimer) clearTimeout(iceRestartTimer);
                iceRestartTimer = setTimeout(() => {
                    if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'failed') {
                        console.warn('[VLERoom] ICE still disconnected, restarting...');
                        attemptIceRestart(pc, remotePeerId);
                    }
                }, ICE_RESTART_TIMEOUT);
            }
        };

        // ICE gathering state (for diagnostics)
        pc.onicegatheringstatechange = () => {
            console.log('[VLERoom] ICE gathering state [' + remotePeerId.substring(0, 15) + ']: ' + pc.iceGatheringState);
        };

        // Remote stream — ensure audio and video tracks are always received
        pc.ontrack = (event) => {
            if (!remoteStreams[remotePeerId]) {
                remoteStreams[remotePeerId] = new MediaStream();
            }
            // Add track only if not already present (avoid duplicates)
            const existing = remoteStreams[remotePeerId].getTracks();
            if (!existing.find(t => t.id === event.track.id)) {
                remoteStreams[remotePeerId].addTrack(event.track);
            }
            console.log('[VLERoom] Remote track received:', event.track.kind, 'from', remotePeerId,
                '(total tracks:', remoteStreams[remotePeerId].getTracks().length + ')');
            onRemoteStream(remotePeerId, remoteStreams[remotePeerId], peerInfo[remotePeerId]);

            // Monitor track unmute (tracks may arrive muted and unmute later)
            event.track.onunmute = () => {
                console.log('[VLERoom] Track unmuted:', event.track.kind, 'from', remotePeerId);
                onRemoteStream(remotePeerId, remoteStreams[remotePeerId], peerInfo[remotePeerId]);
            };
        };

        // Connection state
        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'disconnected' || pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                handlePeerDisconnect(remotePeerId);
            }
        };

        // If we're the initiator, create and send offer
        if (isInitiator) {
            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                sendSignal(remotePeerId, 'offer', JSON.stringify(offer));
            } catch (err) {
                console.error('Error creating offer:', err);
            }
        }

        return pc;
    }

    function handlePeerDisconnect(peerId) {
        if (peerConnections[peerId]) {
            peerConnections[peerId].close();
            delete peerConnections[peerId];
        }
        delete remoteStreams[peerId];
        const info = peerInfo[peerId];
        delete peerInfo[peerId];
        onPeerLeft(peerId, info);
    }

    // ─── ICE RESTART & CONNECTION DIAGNOSTICS ─────────────────────
    /**
     * Attempt ICE restart when connection fails.
     * Creates a new offer with iceRestart: true, which generates new ICE
     * candidates and can recover from network changes or NAT rebinding.
     */
    async function attemptIceRestart(pc, remotePeerId) {
        if (!isInRoom) return;
        if (pc.signalingState === 'closed') return;
        
        try {
            const offer = await pc.createOffer({ iceRestart: true });
            await pc.setLocalDescription(offer);
            sendSignal(remotePeerId, 'offer', JSON.stringify(offer));
            console.log('[VLERoom] ICE restart offer sent to', remotePeerId);
        } catch (e) {
            console.error('[VLERoom] ICE restart failed:', e.message);
            // Last resort: completely rebuild the connection
            console.log('[VLERoom] Attempting full reconnection...');
            try {
                await createPeerConnection(remotePeerId, true);
            } catch (e2) {
                console.error('[VLERoom] Full reconnection failed:', e2.message);
            }
        }
    }

    /**
     * Log the connection type (direct vs relayed) for diagnostics.
     * Helps administrators know if TURN relay is being used.
     */
    async function logConnectionType(pc, remotePeerId) {
        try {
            const stats = await pc.getStats();
            stats.forEach(report => {
                if (report.type === 'candidate-pair' && report.state === 'succeeded') {
                    const localId = report.localCandidateId;
                    const remoteId = report.remoteCandidateId;
                    
                    let localType = 'unknown', remoteType = 'unknown';
                    stats.forEach(s => {
                        if (s.id === localId) localType = s.candidateType || 'unknown';
                        if (s.id === remoteId) remoteType = s.candidateType || 'unknown';
                    });
                    
                    const isRelayed = localType === 'relay' || remoteType === 'relay';
                    console.log('[VLERoom] Connection to ' + remotePeerId.substring(0, 15) + ': ' +
                        (isRelayed ? '🔄 RELAYED (via TURN)' : '✅ DIRECT (peer-to-peer)') +
                        ' | local=' + localType + ' remote=' + remoteType);
                }
            });
        } catch (e) {
            // Stats not available on all browsers
        }
    }

    /**
     * Get connection diagnostics for all peers.
     * Returns an object with connection status, type (direct/relayed), etc.
     */
    async function getConnectionDiagnostics() {
        const diagnostics = {
            iceServerCount: ICE_SERVERS.length,
            turnConfigured: ICE_SERVERS.some(s => {
                const u = Array.isArray(s.urls) ? s.urls.join(',') : (s.urls || '');
                return u.includes('turn:') || u.includes('turns:');
            }),
            iceTransportPolicy: iceTransportPolicy,
            peers: {}
        };
        
        for (const peerId in peerConnections) {
            const pc = peerConnections[peerId];
            const peerDiag = {
                connectionState: pc.connectionState,
                iceConnectionState: pc.iceConnectionState,
                iceGatheringState: pc.iceGatheringState,
                connectionType: 'unknown'
            };
            
            try {
                const stats = await pc.getStats();
                stats.forEach(report => {
                    if (report.type === 'candidate-pair' && report.state === 'succeeded') {
                        stats.forEach(s => {
                            if (s.id === report.localCandidateId) {
                                peerDiag.localCandidateType = s.candidateType;
                                peerDiag.connectionType = s.candidateType === 'relay' ? 'RELAYED' : 'DIRECT';
                            }
                            if (s.id === report.remoteCandidateId) {
                                peerDiag.remoteCandidateType = s.candidateType;
                                if (s.candidateType === 'relay') peerDiag.connectionType = 'RELAYED';
                            }
                        });
                    }
                });
            } catch (e) {}
            
            diagnostics.peers[peerId] = peerDiag;
        }
        
        return diagnostics;
    }

    // ─── SIGNALING ────────────────────────────────────────────────
    async function sendSignal(toPeer, type, data) {
        const fd = new FormData();
        fd.append('action', 'signal');
        fd.append('session_id', sessionId);
        fd.append('peer_id', myPeerId);
        fd.append('to_peer', toPeer);
        fd.append('signal_type', type);
        fd.append('signal_data', data);
        try {
            await fetchWithTimeout(SIGNAL_API, { method: 'POST', body: fd }, 8000);
        } catch (e) {
            console.error('Signal send error:', e);
        }
    }

    async function pollSignals() {
        if (!isInRoom) return;
        try {
            const res = await fetchWithTimeout(`${SIGNAL_API}?action=poll_signals&session_id=${sessionId}&peer_id=${encodeURIComponent(myPeerId)}`, {}, 10000);
            const data = await safeJsonParse(res, { success: false, signals: [] });
            if (data.success && data.signals) {
                for (const sig of data.signals) {
                    await handleSignal(sig);
                }
            }
        } catch (e) {
            console.error('Poll signals error:', e);
        }
        // Reschedule with backoff
        if (isInRoom) {
            pollTimer = setTimeout(pollSignals, getBackoffInterval(POLL_INTERVAL));
        }
    }

    async function handleSignal(signal) {
        const fromPeer = signal.from_peer;
        const type = signal.signal_type;
        const payload = JSON.parse(signal.signal_data);

        switch (type) {
            case 'offer':
                // New peer wants to connect - create connection and answer
                if (!peerInfo[fromPeer]) {
                    peerInfo[fromPeer] = { peer_id: fromPeer, user_name: 'Connecting...', user_role: 'student' };
                    // Fetch peer info if not known
                    refreshPeerList();
                }
                onPeerJoined(peerInfo[fromPeer]);
                
                const pc = await createPeerConnection(fromPeer, false);
                await pc.setRemoteDescription(new RTCSessionDescription(payload));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                sendSignal(fromPeer, 'answer', JSON.stringify(answer));
                break;

            case 'answer':
                if (peerConnections[fromPeer]) {
                    await peerConnections[fromPeer].setRemoteDescription(new RTCSessionDescription(payload));
                }
                break;

            case 'ice':
                if (peerConnections[fromPeer]) {
                    try {
                        await peerConnections[fromPeer].addIceCandidate(new RTCIceCandidate(payload));
                    } catch (e) {
                        console.error('ICE candidate error:', e);
                    }
                }
                break;
        }
    }

    // ─── HEARTBEAT & PEER LIST ────────────────────────────────────
    async function sendHeartbeat() {
        if (!isInRoom) return;
        const fd = new FormData();
        fd.append('action', 'heartbeat');
        fd.append('session_id', sessionId);
        fd.append('peer_id', myPeerId);
        try {
            const res = await fetchWithTimeout(SIGNAL_API, { method: 'POST', body: fd }, 10000);
            const data = await safeJsonParse(res, { success: false });
            if (data.success) {
                // Check if session has been ended by the host
                if (data.session_status && data.session_status !== 'active') {
                    onSessionEnded(data.session_status);
                    await leaveRoom();
                    return;
                }
                onPeerCountUpdate(data.peer_count);
                // Update peer media states (mute/video indicators)
                if (data.peer_states && data.peer_states.length > 0) {
                    data.peer_states.forEach(function(ps) {
                        // Update peerInfo cache
                        if (peerInfo[ps.peer_id]) {
                            peerInfo[ps.peer_id].is_audio_on = ps.is_audio_on;
                            peerInfo[ps.peer_id].is_video_on = ps.is_video_on;
                            peerInfo[ps.peer_id].is_screen_sharing = ps.is_screen_sharing;
                        }
                        // Notify UI to update indicators
                        onPeerMediaStateUpdate(ps.peer_id, {
                            is_audio_on: parseInt(ps.is_audio_on),
                            is_video_on: parseInt(ps.is_video_on),
                            is_screen_sharing: parseInt(ps.is_screen_sharing),
                            user_name: ps.user_name,
                            user_role: ps.user_role
                        });
                    });
                }
            }
        } catch (e) {}
        // Reschedule with backoff
        if (isInRoom) {
            heartbeatTimer = setTimeout(sendHeartbeat, getBackoffInterval(HEARTBEAT_INTERVAL));
        }
    }

    async function refreshPeerList() {
        try {
            const res = await fetchWithTimeout(`${SIGNAL_API}?action=get_peers&session_id=${sessionId}`, {}, 10000);
            const data = await safeJsonParse(res, { success: false, peers: [] });
            if (data.success) {
                data.peers.forEach(p => { peerInfo[p.peer_id] = p; });
            }
        } catch (e) {}
    }

    // ─── MEDIA CONTROLS ──────────────────────────────────────────
    /**
     * Toggle audio. If no localStream (view-only), auto-request mic.
     * Returns: boolean (new state), or 'upgrading' if async request started.
     */
    function toggleAudio() {
        if (!localStream) {
            // No stream yet — signal caller to request media upgrade
            return 'needs-upgrade';
        }
        const audioTracks = localStream.getAudioTracks();
        if (audioTracks.length === 0) {
            return 'needs-upgrade';
        }
        isAudioOn = !isAudioOn;
        audioTracks.forEach(t => { t.enabled = isAudioOn; });
        notifyMediaState('audio', isAudioOn ? 1 : 0);
        return isAudioOn;
    }

    /**
     * Toggle video. If no localStream (view-only), auto-request camera.
     * Returns: boolean (new state), or 'needs-upgrade' if async request needed.
     */
    function toggleVideo() {
        if (!localStream) {
            return 'needs-upgrade';
        }
        const videoTracks = localStream.getVideoTracks();
        if (videoTracks.length === 0) {
            return 'needs-upgrade';
        }
        isVideoOn = !isVideoOn;
        videoTracks.forEach(t => { t.enabled = isVideoOn; });
        notifyMediaState('video', isVideoOn ? 1 : 0);
        return isVideoOn;
    }

    async function toggleScreenShare() {
        if (isScreenSharing) {
            // Stop screen sharing
            if (screenStream) {
                screenStream.getTracks().forEach(t => t.stop());
                screenStream = null;
            }
            isScreenSharing = false;

            // Replace screen track with camera track in all connections
            const videoTrack = localStream ? localStream.getVideoTracks()[0] : null;
            if (videoTrack) {
                for (const peerId in peerConnections) {
                    const senders = peerConnections[peerId].getSenders();
                    const videoSender = senders.find(s => s.track && s.track.kind === 'video');
                    if (videoSender) {
                        await videoSender.replaceTrack(videoTrack);
                    }
                }
            }
            notifyMediaState('screen', 0);
            return false;
        } else {
            try {
                screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: { cursor: 'always' },
                    audio: false
                });

                const screenTrack = screenStream.getVideoTracks()[0];

                // Replace camera video with screen in all connections
                for (const peerId in peerConnections) {
                    const senders = peerConnections[peerId].getSenders();
                    const videoSender = senders.find(s => s.track && s.track.kind === 'video');
                    if (videoSender) {
                        await videoSender.replaceTrack(screenTrack);
                    }
                }

                // Handle user stopping screen share via browser UI
                screenTrack.onended = () => {
                    toggleScreenShare(); // Will stop sharing
                };

                isScreenSharing = true;
                notifyMediaState('screen', 1);
                return true;
            } catch (err) {
                onError('Screen sharing failed: ' + err.message);
                return false;
            }
        }
    }

    function notifyMediaState(media, state) {
        const fd = new FormData();
        fd.append('action', 'toggle_media');
        fd.append('session_id', sessionId);
        fd.append('peer_id', myPeerId);
        fd.append('media_type', media);
        fd.append('state', state);
        fetch(SIGNAL_API, { method: 'POST', body: fd }).catch(() => {});
    }

    // ─── RECORDING ──────────────────────────────────────────────
    async function startRecording() {
        if (isRecording) throw new Error('Recording is already in progress');

        // If no local stream yet, try to get one
        if (!localStream) {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                    audio: { echoCancellation: true, noiseSuppression: true }
                });
            } catch (e) {
                throw new Error('No camera/microphone available. Please allow access and try again.');
            }
        }

        recordedChunks = [];

        // Build a combined stream: local audio + local/screen video
        const combinedStream = new MediaStream();

        // Add video tracks (screen share if active, otherwise camera)
        const videoSource = (isScreenSharing && screenStream) ? screenStream : localStream;
        const videoTracks = videoSource.getVideoTracks();
        videoTracks.forEach(t => combinedStream.addTrack(t));

        // Add audio tracks from local stream
        const audioTracks = localStream.getAudioTracks();
        audioTracks.forEach(t => combinedStream.addTrack(t));

        // Must have at least audio or video
        if (combinedStream.getTracks().length === 0) {
            throw new Error('No audio or video tracks available for recording');
        }

        // Pick best supported mime type based on available tracks
        const hasVideo = videoTracks.length > 0;
        let mimeTypes;
        if (hasVideo) {
            mimeTypes = [
                'video/webm;codecs=vp9,opus',
                'video/webm;codecs=vp8,opus',
                'video/webm',
                'video/mp4'
            ];
        } else {
            // Audio-only fallback
            mimeTypes = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'video/webm' // some browsers need video mime even for audio-only
            ];
        }

        let mimeType = '';
        for (const mt of mimeTypes) {
            if (MediaRecorder.isTypeSupported(mt)) { mimeType = mt; break; }
        }
        if (!mimeType) {
            throw new Error('Recording is not supported in this browser. Try using Chrome or Edge.');
        }

        console.log('[VLERoom] Starting recording with mime:', mimeType, 'tracks:', combinedStream.getTracks().length);

        try {
            const options = { mimeType: mimeType };
            if (hasVideo) options.videoBitsPerSecond = 1500000;

            mediaRecorder = new MediaRecorder(combinedStream, options);

            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) {
                    recordedChunks.push(e.data);
                }
            };

            mediaRecorder.onerror = (e) => {
                console.error('[VLERoom] MediaRecorder error:', e);
                isRecording = false;
                onError('Recording error: ' + (e.error?.message || 'Unknown error'));
            };

            mediaRecorder.onstop = () => {
                isRecording = false;
            };

            mediaRecorder.start(1000); // Collect data every second
            isRecording = true;
            console.log('[VLERoom] Recording started successfully');
            return true;
        } catch (err) {
            isRecording = false;
            mediaRecorder = null;
            throw new Error('Failed to start recording: ' + err.message);
        }
    }

    function stopRecording() {
        if (!isRecording || !mediaRecorder) return Promise.resolve(null);
        return new Promise((resolve, reject) => {
            mediaRecorder.onstop = () => {
                isRecording = false;
                const blob = new Blob(recordedChunks, { type: mediaRecorder.mimeType || 'video/webm' });
                recordedChunks = [];
                console.log('[VLERoom] Recording stopped. Blob size:', (blob.size / 1024 / 1024).toFixed(2), 'MB');
                if (blob.size === 0) {
                    reject(new Error('Recording produced empty file'));
                } else {
                    resolve(blob);
                }
            };
            try {
                mediaRecorder.stop();
            } catch (err) {
                isRecording = false;
                reject(new Error('Failed to stop recording: ' + err.message));
            }
        });
    }

    async function uploadRecording(blob) {
        if (!blob || blob.size === 0) return { success: false, message: 'No recording data to upload' };

        console.log('[VLERoom] Uploading recording...', (blob.size / 1024 / 1024).toFixed(2), 'MB');

        const fd = new FormData();
        fd.append('action', 'upload_recording');
        fd.append('session_id', sessionId);
        fd.append('recording', blob, 'recording_' + sessionId + '_' + Date.now() + '.webm');

        try {
            const res = await fetch('../api/live_session_api.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            
            if (!res.ok) {
                const errorText = await res.text();
                console.error('[VLERoom] Upload HTTP error:', res.status, errorText);
                return { success: false, message: 'Upload failed with status ' + res.status };
            }
            
            const result = await res.json();
            console.log('[VLERoom] Upload result:', result);
            return result;
        } catch (err) {
            console.error('[VLERoom] Upload error:', err);
            return { success: false, message: 'Upload failed: ' + err.message };
        }
    }

    function getIsRecording() { return isRecording; }

    // ─── CHAT ─────────────────────────────────────────────────────
    async function sendChatMessage(message) {
        const fd = new FormData();
        fd.append('action', 'send_chat');
        fd.append('session_id', sessionId);
        fd.append('message', message);
        try {
            const res = await fetchWithTimeout(SIGNAL_API, { method: 'POST', body: fd }, 8000);
            const data = await safeJsonParse(res, { success: false });
            return data.success;
        } catch (e) {
            return false;
        }
    }

    async function pollChat() {
        if (!isInRoom) return;
        try {
            const res = await fetchWithTimeout(`${SIGNAL_API}?action=get_chat&session_id=${sessionId}&after_id=${lastChatId}`, {}, 10000);
            const data = await safeJsonParse(res, { success: false, messages: [] });
            if (data.success && data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    lastChatId = Math.max(lastChatId, parseInt(msg.chat_id));
                    onChatMessage(msg);
                });
            }
        } catch (e) {}
        // Reschedule with backoff
        if (isInRoom) {
            chatPollTimer = setTimeout(pollChat, getBackoffInterval(CHAT_POLL_INTERVAL));
        }
    }

    // ─── GETTERS ──────────────────────────────────────────────────
    function getLocalStream() { return localStream; }
    function getScreenStream() { return screenStream; }
    function getMyPeerId() { return myPeerId; }
    function getIsAudioOn() { return isAudioOn; }
    function getIsVideoOn() { return isVideoOn; }
    function getIsScreenSharing() { return isScreenSharing; }
    function getPeerInfo() { return peerInfo; }
    function getIsRecording() { return isRecording; }

    // ─── PUBLIC API ───────────────────────────────────────────────
    window.VLERoom = {
        init,
        joinRoom,
        leaveRoom,
        toggleAudio,
        toggleVideo,
        toggleScreenShare,
        requestMedia,
        startRecording,
        stopRecording,
        uploadRecording,
        sendChatMessage,
        getLocalStream,
        getScreenStream,
        getMyPeerId,
        getIsAudioOn,
        getIsVideoOn,
        getIsScreenSharing,
        getIsRecording,
        getMediaMode,
        getPeerInfo,
        refreshPeerList,
        // TURN/ICE configuration
        setIceConfig,
        fetchIceConfig,
        getConnectionDiagnostics
    };
})();
