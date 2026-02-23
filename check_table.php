<?php
require_once 'includes/config.php';
$conn = getDbConnection();
$result = $conn->query('DESCRIBE vle_download_requests');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . PHP_EOL;
}
$conn->close();
?>