<?php
/**
 * Setup VLE Notifications Table
 * Run this once to create the vle_notifications table
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$sql = "CREATE TABLE IF NOT EXISTS vle_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'The user who receives this notification',
    type VARCHAR(50) NOT NULL COMMENT 'submission, message, enrollment, announcement, grade, forum, finance, system',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(500) DEFAULT NULL COMMENT 'URL the notification links to',
    is_read TINYINT(1) DEFAULT 0,
    is_emailed TINYINT(1) DEFAULT 0 COMMENT 'Whether this was forwarded to email',
    related_id INT DEFAULT NULL COMMENT 'ID of related entity (course_id, submission_id, etc.)',
    related_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of related entity',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "<h3 style='color:green;'>✅ vle_notifications table created successfully!</h3>";
} else {
    echo "<h3 style='color:red;'>❌ Error creating table: " . $conn->error . "</h3>";
}

echo "<br><a href='lecturer/dashboard.php'>Go to Lecturer Dashboard</a>";
?>
