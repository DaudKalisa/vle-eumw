<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$conn->query("DELETE FROM programs WHERE program_name = 'Bachelor of Information Technology'");
echo "Deleted 'Bachelor of Information Technology': " . $conn->affected_rows . " rows\n";

$conn->close();
?>
