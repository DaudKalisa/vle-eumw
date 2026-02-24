<?php
/**
 * Setup Live Classroom Signaling Table
 * Required for the built-in WebRTC live classroom feature.
 * Run once: http://localhost/vle-eumw/setup_live_classroom.php
 */
require_once 'includes/config.php';
$conn = getDbConnection();

// Signaling table for WebRTC offer/answer/ICE exchange
$sql1 = "CREATE TABLE IF NOT EXISTS vle_webrtc_signals (
    signal_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    from_peer VARCHAR(100) NOT NULL,
    to_peer VARCHAR(100) NOT NULL,
    signal_type ENUM('offer','answer','ice') NOT NULL,
    signal_data LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_consumed TINYINT(1) DEFAULT 0,
    INDEX idx_to_peer (to_peer, session_id, is_consumed),
    INDEX idx_session (session_id),
    INDEX idx_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Chat messages for live sessions
$sql2 = "CREATE TABLE IF NOT EXISTS vle_session_chat (
    chat_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id INT NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session_time (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Peer presence tracking
$sql3 = "CREATE TABLE IF NOT EXISTS vle_session_peers (
    peer_id VARCHAR(100) NOT NULL,
    session_id INT NOT NULL,
    user_id INT NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    user_role ENUM('lecturer','student') NOT NULL,
    is_audio_on TINYINT(1) DEFAULT 1,
    is_video_on TINYINT(1) DEFAULT 1,
    is_screen_sharing TINYINT(1) DEFAULT 0,
    last_heartbeat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (peer_id),
    INDEX idx_session (session_id),
    INDEX idx_heartbeat (last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$ok = true;
foreach ([$sql1, $sql2, $sql3] as $sql) {
    if (!$conn->query($sql)) {
        echo "<p style='color:red;'>Error: " . $conn->error . "</p>";
        $ok = false;
    }
}

if ($ok) {
    echo "<h3 style='color:green;'>✅ Live classroom tables created successfully!</h3>";
    echo "<ul><li>vle_webrtc_signals - WebRTC signaling exchange</li>";
    echo "<li>vle_session_chat - Live session chat messages</li>";
    echo "<li>vle_session_peers - Peer presence & status tracking</li></ul>";
}

echo "<br><a href='lecturer/live_classroom.php'>Go to Live Classroom</a>";
?>
