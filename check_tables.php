<?php
require_once 'includes/config.php';
$conn = getDbConnection();
$result = $conn->query('SHOW TABLES');
echo "Available tables:\n";
while($row = $result->fetch_array()) {
    echo $row[0] . "\n";
}

// Check students table structure
echo "\nStudents table structure:\n";
$result = $conn->query('DESCRIBE students');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Key'] . "\n";
}
?>