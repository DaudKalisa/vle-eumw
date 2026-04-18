<?php
/**
 * Add finance tracking columns to lecturer_finance_requests table
 * This ensures the database schema supports the complete approval workflow
 */

require_once 'includes/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "=== ADDING FINANCE TRACKING COLUMNS ===\n\n";

// Columns to add for finance tracking
$columns_to_add = [
    'finance_approved_at DATETIME NULL',
    'finance_remarks TEXT NULL',
    'finance_rejected_at DATETIME NULL',
    'finance_paid_at DATETIME NULL',
    'submission_date DATETIME DEFAULT CURRENT_TIMESTAMP' // For consistency
];

// Check and add columns
foreach ($columns_to_add as $column_def) {
    // Extract column name from definition
    $col_name = explode(' ', $column_def)[0];
    
    // Check if column exists
    $check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE '$col_name'");
    
    if ($check && $check->num_rows === 0) {
        // Column doesn't exist, add it
        $alter_sql = "ALTER TABLE lecturer_finance_requests ADD COLUMN $column_def";
        if ($conn->query($alter_sql)) {
            echo "✓ Added column: $col_name\n";
        } else {
            echo "✗ Failed to add column $col_name: " . $conn->error . "\n";
        }
    } else {
        echo "~ Column already exists: $col_name\n";
    }
}

echo "\n=== COLUMN REVIEW ===\n";
$result = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests");
if ($result) {
    echo "All columns in lecturer_finance_requests:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}

echo "\n=== COMPLETE ===\n";

$conn->close();
?>
