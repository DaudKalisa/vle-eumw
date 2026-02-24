<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$result = $conn->query('DESCRIBE exams');
echo "Exams table structure:\n";
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Key'] . "\n";
}
?>