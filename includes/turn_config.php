<?php
/**
 * TURN/STUN Server Configuration for Live Classroom
 * 
 * TURN servers are REQUIRED for students to connect from different networks
 * (mobile data, home WiFi, corporate networks, etc.)
 * 
 * Without TURN, WebRTC only works when both peers can establish a direct
 * connection, which fails behind symmetric NAT (~30-40% of connections).
 * 
 * ─── OPTIONS ───────────────────────────────────────────────────────
 * 
 * 1) FREE (Default): Uses Metered.ca Open Relay TURN servers
 *    - 50GB/month free tier
 *    - Works globally
 *    - Register at https://www.metered.ca/stun-turn for your own API key
 * 
 * 2) SELF-HOSTED: Set up your own coturn TURN server
 *    - Full control, unlimited bandwidth
 *    - Install: sudo apt install coturn
 *    - Guide: https://github.com/coturn/coturn
 *    - Set TURN_USE_COTURN = true and configure COTURN_* constants below
 * 
 * 3) PAID SERVICE: Use Twilio, Xirsys, or other TURN providers
 *    - Add their ICE server URLs to the $CUSTOM_TURN_SERVERS array
 */

// ─── METERED.CA FREE TURN API KEY ──────────────────────────────────
// Register at https://www.metered.ca/stun-turn to get your free API key
// Free tier: 50GB/month, supports TURN over UDP/TCP/TLS on ports 80, 443, 3478
define('METERED_API_KEY', '');  // e.g. 'abc123def456...'

// ─── SELF-HOSTED COTURN CONFIG ─────────────────────────────────────
// Set to true if you run your own coturn TURN server
define('TURN_USE_COTURN', false);
define('COTURN_HOST', '');           // e.g. 'turn.youruniversity.edu'
define('COTURN_PORT', 3478);
define('COTURN_TLS_PORT', 5349);
define('COTURN_SECRET', '');         // Shared secret for time-limited credentials
define('COTURN_CREDENTIAL_TTL', 86400); // 24 hours

// ─── CUSTOM TURN SERVERS ───────────────────────────────────────────
// Add any additional TURN servers here (Twilio, Xirsys, etc.)
// Format: ['urls' => 'turn:host:port', 'username' => '...', 'credential' => '...']
$CUSTOM_TURN_SERVERS = [
    // Example:
    // ['urls' => 'turn:your-server.com:3478', 'username' => 'user', 'credential' => 'pass'],
    // ['urls' => 'turns:your-server.com:5349', 'username' => 'user', 'credential' => 'pass'],
];

// ─── ICE TRANSPORT POLICY ──────────────────────────────────────────
// 'all'   = Try direct connection first, fall back to TURN relay (recommended)
// 'relay' = Force all traffic through TURN relay (useful for debugging)
define('ICE_TRANSPORT_POLICY', 'all');

/**
 * Generate the complete ICE server configuration
 * Returns an array suitable for RTCPeerConnection configuration
 */
function getIceServerConfig() {
    global $CUSTOM_TURN_SERVERS;
    
    $iceServers = [];
    
    // ── STUN Servers (free, for discovering public IP) ──
    // Only 2 STUN servers needed — more slows down ICE gathering
    $iceServers[] = ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']];
    
    // ── TURN Servers ──
    $turnAdded = false;
    
    // Option 1: Metered.ca API key (free tier)
    if (defined('METERED_API_KEY') && METERED_API_KEY !== '') {
        $apiKey = METERED_API_KEY;
        
        // Metered provides TURN on multiple ports/protocols for maximum compatibility
        $iceServers[] = [
            'urls' => "stun:a.relay.metered.ca:80"
        ];
        $iceServers[] = [
            'urls' => "turn:a.relay.metered.ca:80",
            'username' => $apiKey,
            'credential' => $apiKey
        ];
        $iceServers[] = [
            'urls' => "turn:a.relay.metered.ca:80?transport=tcp",
            'username' => $apiKey,
            'credential' => $apiKey
        ];
        $iceServers[] = [
            'urls' => "turn:a.relay.metered.ca:443",
            'username' => $apiKey,
            'credential' => $apiKey
        ];
        $iceServers[] = [
            'urls' => "turn:a.relay.metered.ca:443?transport=tcp",
            'username' => $apiKey,
            'credential' => $apiKey
        ];
        $iceServers[] = [
            'urls' => "turns:a.relay.metered.ca:443?transport=tcp",
            'username' => $apiKey,
            'credential' => $apiKey
        ];
        $turnAdded = true;
    }
    
    // Option 2: Self-hosted coturn with time-limited credentials (HMAC)
    if (defined('TURN_USE_COTURN') && TURN_USE_COTURN && COTURN_HOST !== '') {
        $timestamp = time() + COTURN_CREDENTIAL_TTL;
        $username = $timestamp . ':vle_user';
        $credential = base64_encode(hash_hmac('sha1', $username, COTURN_SECRET, true));
        
        $host = COTURN_HOST;
        $port = COTURN_PORT;
        $tlsPort = COTURN_TLS_PORT;
        
        // UDP (fastest, default port)
        $iceServers[] = [
            'urls' => "turn:{$host}:{$port}",
            'username' => $username,
            'credential' => $credential
        ];
        // TCP (works through more firewalls)
        $iceServers[] = [
            'urls' => "turn:{$host}:{$port}?transport=tcp",
            'username' => $username,
            'credential' => $credential
        ];
        // TLS on port 443 (works through almost any firewall)
        if ($tlsPort) {
            $iceServers[] = [
                'urls' => "turns:{$host}:{$tlsPort}?transport=tcp",
                'username' => $username,
                'credential' => $credential
            ];
        }
        $turnAdded = true;
    }
    
    // Option 3: Custom TURN servers
    if (!empty($CUSTOM_TURN_SERVERS)) {
        foreach ($CUSTOM_TURN_SERVERS as $server) {
            $iceServers[] = $server;
        }
        $turnAdded = true;
    }
    
    // Fallback: No TURN configured — use STUN-only
    // STUN works for most connections (60-70% of networks).
    // For cross-network support, configure a TURN server above.
    // NOTE: Do NOT use dead/unreliable community TURN servers as they
    // cause ICE gathering to hang for 30+ seconds waiting for timeouts.
    if (!$turnAdded) {
        // Add one more STUN server as backup (keeps it lightweight)
        $iceServers[] = ['urls' => 'stun:stun2.l.google.com:19302'];
        error_log('[VLE Live] WARNING: No TURN server configured. Cross-network connections may fail. Configure TURN in includes/turn_config.php');
    }
    
    return [
        'iceServers' => $iceServers,
        'iceTransportPolicy' => ICE_TRANSPORT_POLICY
    ];
}
