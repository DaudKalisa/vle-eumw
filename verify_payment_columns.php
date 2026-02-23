<?php
require_once 'includes/config.php';

$conn = getDbConnection();

// Check current table structure
echo "<h3>Checking payment_submissions table structure:</h3>";
$result = $conn->query("DESCRIBE payment_submissions");
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table><br>";

// Try to add columns if they don't exist
echo "<h3>Adding missing columns:</h3>";

$sql1 = "ALTER TABLE payment_submissions ADD COLUMN IF NOT EXISTS transaction_type VARCHAR(50) DEFAULT 'Bank Deposit'";
if ($conn->query($sql1)) {
    echo "✓ transaction_type column added/verified.<br>";
} else {
    echo "Error with transaction_type: " . $conn->error . "<br>";
}

$sql2 = "ALTER TABLE payment_submissions ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT ''";
if ($conn->query($sql2)) {
    echo "✓ bank_name column added/verified.<br>";
} else {
    echo "Error with bank_name: " . $conn->error . "<br>";
}

echo "<br><h3>Updated table structure:</h3>";
$result = $conn->query("DESCRIBE payment_submissions");
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table>";

$conn->close();
?>
