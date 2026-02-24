<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$result = $conn->query('SHOW TABLES LIKE "exam%"');
echo "Examination-related tables in database:\n";
$tables = [];
while($row = $result->fetch_array()) {
    echo "- " . $row[0] . "\n";
    $tables[] = $row[0];
}

echo "\nTotal examination tables: " . count($tables) . "\n";

// Check if examination_managers table exists and has data
$result = $conn->query('SELECT COUNT(*) as count FROM examination_managers');
$row = $result->fetch_assoc();
echo "Examination managers: " . $row['count'] . "\n";

// Check if default user exists
$result = $conn->query("SELECT username, email, role FROM users WHERE username LIKE '%exam%' OR email LIKE '%exam%'");
echo "\nExamination manager users:\n";
while($row = $result->fetch_assoc()) {
    echo "- " . $row['username'] . " (" . $row['email'] . ") - Role: " . $row['role'] . "\n";
}

if ($result->num_rows == 0) {
    echo "No examination manager users found.\n";
}
?>