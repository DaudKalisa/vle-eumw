<?php
/**
 * Add Rate Revision Columns to Lecturer Finance Requests
 * Allows finance officer to revise hourly rate and airtime rate
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "=== Adding Rate Revision Columns ===\n\n";

// Check if columns already exist
$result = $conn->query("DESCRIBE lecturer_finance_requests");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

$columns_to_add = [
    'revised_hourly_rate' => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Finance revised hourly rate'",
    'revised_airtime_rate' => "DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Finance revised airtime rate'",
    'rate_revision_reason' => "TEXT NULL DEFAULT NULL COMMENT 'Reason for rate revision'",
    'revised_by' => "INT NULL DEFAULT NULL COMMENT 'User ID who revised rates'",
    'revised_at' => "DATETIME NULL DEFAULT NULL COMMENT 'When rates were revised'",
];

$added_count = 0;

foreach ($columns_to_add as $col_name => $col_def) {
    if (!in_array($col_name, $columns)) {
        $sql = "ALTER TABLE lecturer_finance_requests ADD COLUMN $col_name $col_def";
        if ($conn->query($sql)) {
            echo "✓ Added column: $col_name\n";
            $added_count++;
        } else {
            echo "✗ Failed to add column $col_name: " . $conn->error . "\n";
        }
    } else {
        echo "~ Column already exists: $col_name\n";
    }
}

echo "\n=== Summary ===\n";
echo "Columns added: $added_count\n";
echo "Total columns in table: " . count($columns) + $added_count . "\n";
echo "\n✓ Migration complete!\n";

$conn->close();
?>
