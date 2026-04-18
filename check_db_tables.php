<?php
// Temporary script to list all tables in the production database
// DELETE AFTER USE
require_once 'includes/config.php';
$conn = getDbConnection();

header('Content-Type: text/plain');

echo "=== DATABASE TABLES ===\n";
echo "Database: " . ($conn->query("SELECT DATABASE()")->fetch_row()[0]) . "\n\n";

$result = $conn->query("SHOW TABLES");
$tables = [];
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}
sort($tables);

echo "Total tables: " . count($tables) . "\n\n";
foreach ($tables as $t) {
    $count = $conn->query("SELECT COUNT(*) FROM `$t`");
    $cnt = $count ? $count->fetch_row()[0] : 'ERR';
    echo str_pad($t, 45) . "  rows: $cnt\n";
}

echo "\n=== USERS TABLE STRUCTURE ===\n";
$r = $conn->query("DESCRIBE users");
while ($row = $r->fetch_assoc()) {
    echo str_pad($row['Field'], 25) . str_pad($row['Type'], 40) . $row['Null'] . " " . ($row['Default'] ?? '') . "\n";
}

echo "\n=== USERS ROLE ENUM VALUES ===\n";
$r = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
$row = $r->fetch_assoc();
echo $row['Type'] . "\n";
?>
