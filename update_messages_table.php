<?php
// update_messages_table.php - Update vle_messages table to support admin and finance messaging
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Updating VLE Messages Table for Admin and Finance Support</h2>";

// Check current ENUM values
$result = $conn->query("SHOW COLUMNS FROM vle_messages LIKE 'sender_type'");
$row = $result->fetch_assoc();
echo "<p>Current sender_type definition: " . htmlspecialchars($row['Type']) . "</p>";

// Alter sender_type to include 'admin' and 'finance'
$sql1 = "ALTER TABLE vle_messages MODIFY COLUMN sender_type ENUM('student', 'lecturer', 'admin', 'finance') NOT NULL";
if ($conn->query($sql1)) {
    echo "<p style='color:green;'>✓ sender_type updated to include 'admin' and 'finance'</p>";
} else {
    echo "<p style='color:red;'>✗ Failed to update sender_type: " . $conn->error . "</p>";
}

// Alter recipient_type to include 'admin' and 'finance'
$sql2 = "ALTER TABLE vle_messages MODIFY COLUMN recipient_type ENUM('student', 'lecturer', 'admin', 'finance') NOT NULL";
if ($conn->query($sql2)) {
    echo "<p style='color:green;'>✓ recipient_type updated to include 'admin' and 'finance'</p>";
} else {
    echo "<p style='color:red;'>✗ Failed to update recipient_type: " . $conn->error . "</p>";
}

// Verify the changes
$result = $conn->query("SHOW COLUMNS FROM vle_messages LIKE 'sender_type'");
$row = $result->fetch_assoc();
echo "<p>New sender_type definition: " . htmlspecialchars($row['Type']) . "</p>";

$result = $conn->query("SHOW COLUMNS FROM vle_messages LIKE 'recipient_type'");
$row = $result->fetch_assoc();
echo "<p>New recipient_type definition: " . htmlspecialchars($row['Type']) . "</p>";

echo "<h3>Done!</h3>";
echo "<p><a href='admin/messages.php'>Go to Admin Messages</a></p>";

?>
