<?php
/**
 * Add Signature Columns for Approvals
 * Allows ODL Coordinator, Dean, and Finance Officer to add signatures on claim approval
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "=== Adding Signature Approval Columns ===\n\n";

// Check if columns already exist
$result = $conn->query("DESCRIBE lecturer_finance_requests");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

$columns_to_add = [
    'odl_signature_path' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'ODL Coordinator signature image'",
    'odl_signed_at' => "DATETIME NULL DEFAULT NULL COMMENT 'When ODL Coordinator signed'",
    'dean_signature_path' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Dean signature image'",
    'dean_signed_at' => "DATETIME NULL DEFAULT NULL COMMENT 'When Dean signed'",
    'finance_signature_path' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Finance Officer signature image'",
    'finance_signed_at' => "DATETIME NULL DEFAULT NULL COMMENT 'When Finance Officer signed'",
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

echo "\n=== University Settings ===\n";
$settings = $conn->query("SELECT university_name, logo_path, address_po_box, address_street, address_city, phone FROM university_settings LIMIT 1");
if ($settings && $row = $settings->fetch_assoc()) {
    echo "University: " . $row['university_name'] . "\n";
    echo "Logo: " . $row['logo_path'] . "\n";
    echo "Address: " . $row['address_po_box'] . ", " . $row['address_street'] . ", " . $row['address_city'] . "\n";
    echo "Phone: " . $row['phone'] . "\n";
} else {
    echo "⚠ No university settings found\n";
}

echo "\n=== Summary ===\n";
echo "Columns added: $added_count\n";
echo "Migration complete!\n";

$conn->close();
?>
