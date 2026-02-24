<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$result = $conn->query('DESCRIBE users');
echo "Users table structure:\n";
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Key'] . "\n";
}
?>