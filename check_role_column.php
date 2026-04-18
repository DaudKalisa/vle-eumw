<?php
require_once 'includes/config.php';
$conn = getDbConnection();

// Show users table columns
$r = $conn->query("SHOW COLUMNS FROM users");
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . " => " . $row['Type'] . "\n";
}
