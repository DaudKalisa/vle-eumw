/**
 * VLE Live Classroom - WebRTC Engine
 * 
 * Handles: camera, microphone, screen sharing, peer connections,
 * signaling via PHP polling, chat, and UI updates.
 * 
 * No external API needed - pure WebRTC with PHP-based signaling.
 */
(function () {
    'use strict';

    const SIGNAL_API = '../api/webrtc_signal.php';
    const POLL_INTERVAL = 1500;    // Signal polling (ms)
    const HEARTBEAT_INTERVAL = 5000;
    const CHAT_POLL_INTERVAL = 2000;

    const ICE_SERVERS = [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' }
    ];

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
    }

    // ─── JOIN ROOM ────────────────────────────────────────────────
    let mediaMode = 'full'; // 'full' | 'audio' | 'view-only'

    async function joinRoom() {
        // Try to get camera + mic with graceful fallback
        localStream = await acquireMediaStream();

        // Register with signaling server
        const fd = new FormData();
        fd.append('action', 'join');
        fd.append('session_id', sessionId);
        fd.append('peer_id', myPeerId);

        try {
            const res = await fetch(SIGNAL_API, { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.success) throw new Error(data.error || 'Failed to join room');

            isInRoom = true;

            // Connect to existing peers
            if (data.peers && data.peers.length > 0) {
                for (const peer of data.peers) {
                    peerInfo[peer.peer_id] = peer;
                    onPeerJoined(peer);
                    await createPeerConnection(peer.peer_id, true);
                }
            }

            // Start polling
            pollTimer = setInterval(pollSignals, POLL_INTERVAL);
            heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL);
            chatPollTimer = setInterval(pollChat, CHAT_POLL_INTERVAL);

            return { stream: localStream, mediaMode: mediaMode };
        } catch (err) {
            onError('Failed to join room: ' + err.message);
            throw err;
        }
    }

    /**
     * Graceful media acquisition: camera+mic → mic-only → view-only
     * Ensures the student can ALWAYS join and hear the lecturer
     */
    async function acquireMediaStream() {
        // Attempt 1: Camera + Microphone (full experience)
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
            });
            mediaMode = 'full';
            isAudioOn = true;
            isVideoOn = true;
            console.log('[VLERoom] Media acquired: camera + microphone');
            return stream;
        } catch (e) {
            console.warn('[VLERoom] Camera+Mic failed:', e.message, '- trying mic-only...');
        }

        // Attempt 2: Microphone only (can talk and hear, no video)
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: false,
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
            });
            mediaMode = 'audio';
            isAudioOn = true;
            isVideoOn = false;
            console.log('[VLERoom] Media acquired: microphone only');
            onError('Camera not available — joined with microphone only. You can still hear and talk.');
            return stream;
        } catch (e) {
            console.warn('[VLERoom] Mic-only failed:', e.message, '- joining view-only...');
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

        clearInterval(pollTimer);
        clearInterval(heartbeatTimer);
        clearInterval(chatPollTimer);

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

    // ─── PEER CONNECTION ──────────────────────────────────────────
    async function createPeerConnection(remotePeerId, isInitiator) {
        if (peerConnections[remotePeerId]) {
            peerConnections[remotePeerId].close();
        }

        const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
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

        // Remote stream — ensure audio tracks are always received and played
        pc.ontrack = (event) => {
            if (!remoteStreams[remotePeerId]) {
                remoteStreams[remotePeerId] = new MediaStream();
            }
            // Add track (both audio and video)
            remoteStreams[remotePeerId].addTrack(event.track);
            console.log('[VLERoom] Remote track received:', event.track.kind, 'from', remotePeerId);
            onRemoteStream(remotePeerId, remoteStreams[remotePeerId], peerInfo[remotePeerId]);
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
            await fetch(SIGNAL_API, { method: 'POST', body: fd });
        } catch (e) {
            console.error('Signal send error:', e);
        }
    }

    async function pollSignals() {
        if (!isInRoom) return;
        try {
            const res = await fetch(`${SIGNAL_API}?action=poll_signals&session_id=${sessionId}&peer_id=${encodeURIComponent(myPeerId)}`);
            const data = await res.json();
            if (data.success && data.signals) {
                for (const sig of data.signals) {
                    await handleSignal(sig);
                }
            }
        } catch (e) {
            console.error('Poll signals error:', e);
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
            const res = await fetch(SIGNAL_API, { method: 'POST', body: fd });
            const data = await res.json();
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
    }

    async function refreshPeerList() {
        try {
            const res = await fetch(`${SIGNAL_API}?action=get_peers&session_id=${sessionId}`);
            const data = await res.json();
            if (data.success) {
                data.peers.forEach(p => { peerInfo[p.peer_id] = p; });
            }
        } catch (e) {}
    }

    // ─── MEDIA CONTROLS ──────────────────────────────────────────
    function toggleAudio() {
        if (!localStream) {
            onError('Microphone is not available. Please allow microphone access and refresh.');
            return false;
        }
        const audioTracks = localStream.getAudioTracks();
        if (audioTracks.length === 0) {
            onError('No microphone detected.');
            return false;
        }
        isAudioOn = !isAudioOn;
        audioTracks.forEach(t => { t.enabled = isAudioOn; });
        notifyMediaState('audio', isAudioOn ? 1 : 0);
        return isAudioOn;
    }

    function toggleVideo() {
        if (!localStream) {
            onError('Camera is not available. Please allow camera access and refresh.');
            return false;
        }
        const videoTracks = localStream.getVideoTracks();
        if (videoTracks.length === 0) {
            onError('No camera detected. You joined with audio only.');
            return false;
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
            const res = await fetch(SIGNAL_API, { method: 'POST', body: fd });
            return (await res.json()).success;
        } catch (e) {
            return false;
        }
    }

    async function pollChat() {
        if (!isInRoom) return;
        try {
            const res = await fetch(`${SIGNAL_API}?action=get_chat&session_id=${sessionId}&after_id=${lastChatId}`);
            const data = await res.json();
            if (data.success && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    lastChatId = Math.max(lastChatId, parseInt(msg.chat_id));
                    onChatMessage(msg);
                });
            }
        } catch (e) {}
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
        refreshPeerList
    };
})();
