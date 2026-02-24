<?php
// create_messages_table.php - Create vle_messages table
require_once 'includes/config.php';

$conn = getDbConnection();

$sql = "
CREATE TABLE IF NOT EXISTS vle_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('student', 'lecturer') NOT NULL,
    sender_id VARCHAR(50) NOT NULL,
    recipient_type ENUM('student', 'lecturer') NOT NULL,
    recipient_id VARCHAR(50) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_date DATETIME NULL,
    INDEX (recipient_type, recipient_id),
    INDEX (sender_type, sender_id)
);
";

if ($conn->query($sql)) {
    echo "<h2>Success!</h2>";
    echo "<p>The vle_messages table has been created successfully.</p>";
    echo "<p><a href='student/messages.php'>Go to Messages</a></p>";
} else {
    echo "<h2>Error!</h2>";
    echo "<p>Failed to create table: " . $conn->error . "</p>";
}

?>
