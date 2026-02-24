<?php
// setup_live_sessions.php - Create live video session tables
require_once 'includes/config.php';

$conn = getDbConnection();

// Create vle_live_sessions table
$sql = "CREATE TABLE IF NOT EXISTS vle_live_sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    lecturer_id VARCHAR(50) NOT NULL,
    session_name VARCHAR(255) NOT NULL,
    session_code VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    max_participants INT DEFAULT 50,
    meeting_url VARCHAR(500),
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "✓ vle_live_sessions table created successfully<br>";
} else {
    echo "✗ Error creating vle_live_sessions table: " . $conn->error . "<br>";
}

// Create vle_session_participants table
$sql = "CREATE TABLE IF NOT EXISTS vle_session_participants (
    participant_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    joined_at TIMESTAMP NULL,
    left_at TIMESTAMP NULL,
    status ENUM('invited', 'joined', 'left') DEFAULT 'invited',
    FOREIGN KEY (session_id) REFERENCES vle_live_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_student (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "✓ vle_session_participants table created successfully<br>";
} else {
    echo "✗ Error creating vle_session_participants table: " . $conn->error . "<br>";
}

// Create vle_session_invites table
$sql = "CREATE TABLE IF NOT EXISTS vle_session_invites (
    invite_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    viewed_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    status ENUM('sent', 'viewed', 'accepted', 'declined') DEFAULT 'sent',
    FOREIGN KEY (session_id) REFERENCES vle_live_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_invite (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "✓ vle_session_invites table created successfully<br>";
} else {
    echo "✗ Error creating vle_session_invites table: " . $conn->error . "<br>";
}

echo "<br><strong>Database setup completed!</strong>";
?>
