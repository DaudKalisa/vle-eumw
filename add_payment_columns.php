<?php
require_once 'includes/config.php';

$conn = getDbConnection();

// Add transaction_type column
$sql1 = "ALTER TABLE payment_submissions ADD COLUMN transaction_type VARCHAR(50) AFTER proof_of_payment";
if ($conn->query($sql1)) {
    echo "Column 'transaction_type' added successfully.<br>";
} else {
    echo "Note: " . $conn->error . "<br>";
}

// Add bank_name column
$sql2 = "ALTER TABLE payment_submissions ADD COLUMN bank_name VARCHAR(100) AFTER transaction_type";
if ($conn->query($sql2)) {
    echo "Column 'bank_name' added successfully.<br>";
} else {
    echo "Note: " . $conn->error . "<br>";
}

echo "Database update complete!";
$conn->close();
?>
