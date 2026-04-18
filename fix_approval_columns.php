<?php
/**
 * Ensure all approval columns exist in lecturer_finance_requests table
 * Fixes data truncation and adds missing Finance approval columns
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "=== Fixing lecturer_finance_requests Table ===\n\n";

// 1. Check current column structure
$result = $conn->query("DESCRIBE lecturer_finance_requests");
$columns = [];
$column_types = [];

while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
    $column_types[$row['Field']] = $row['Type'];
}

// 2. Fix odl_approval_status and dean_approval_status enum values
$enum_fixes = [
    'odl_approval_status' => "ENUM('pending','approved','rejected','returned')",
    'dean_approval_status' => "ENUM('pending','approved','rejected','returned')"
];

foreach ($enum_fixes as $col_name => $enum_def) {
    if (in_array($col_name, $columns)) {
        // Check if it needs fixing (if it has 'forwarded_to_dean' in the enum)
        $current_type = $column_types[$col_name] ?? '';
        
        if (strpos($current_type, 'forwarded_to_dean') !== false || 
            strpos($current_type, 'forwarded') !== false) {
            echo "Fixing column $col_name enum values...\n";
            $sql = "ALTER TABLE lecturer_finance_requests MODIFY COLUMN $col_name $enum_def DEFAULT 'pending'";
            if ($conn->query($sql)) {
                echo "✓ Fixed enum values for $col_name\n";
            } else {
                echo "✗ Failed to fix $col_name: " . $conn->error . "\n";
            }
        } else {
            echo "~ Column $col_name enum is already correct\n";
        }
    }
}

// 3. Add missing Finance columns
$finance_columns = [
    'finance_approved_by' => "INT(11) DEFAULT NULL COMMENT 'Finance Officer user ID'",
    'finance_remarks' => "TEXT DEFAULT NULL COMMENT 'Finance Officer remarks'",
];

foreach ($finance_columns as $col_name => $col_def) {
    if (!in_array($col_name, $columns)) {
        $sql = "ALTER TABLE lecturer_finance_requests ADD COLUMN $col_name $col_def";
        if ($conn->query($sql)) {
            echo "✓ Added column: $col_name\n";
        } else {
            echo "✗ Failed to add $col_name: " . $conn->error . "\n";
        }
    } else {
        echo "~ Column already exists: $col_name\n";
    }
}

// 4. Verify signature columns from add_signature_columns.php
$signature_columns = [
    'odl_signature_path' => "VARCHAR(255) NULL DEFAULT NULL",
    'odl_signed_at' => "DATETIME NULL DEFAULT NULL",
    'dean_signature_path' => "VARCHAR(255) NULL DEFAULT NULL",
    'dean_signed_at' => "DATETIME NULL DEFAULT NULL",
    'finance_signature_path' => "VARCHAR(255) NULL DEFAULT NULL",
    'finance_signed_at' => "DATETIME NULL DEFAULT NULL",
];

echo "\n=== Checking Signature Columns ===\n";

// Refresh columns list
$result = $conn->query("DESCRIBE lecturer_finance_requests");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

foreach ($signature_columns as $col_name => $col_def) {
    if (!in_array($col_name, $columns)) {
        $sql = "ALTER TABLE lecturer_finance_requests ADD COLUMN $col_name $col_def";
        if ($conn->query($sql)) {
            echo "✓ Added column: $col_name\n";
        } else {
            echo "✗ Failed to add $col_name: " . $conn->error . "\n";
        }
    } else {
        echo "~ Column already exists: $col_name\n";
    }
}

// 5. Create signatures upload directory
$upload_dir = 'uploads/signatures';
if (!is_dir($upload_dir)) {
    if (mkdir($upload_dir, 0755, true)) {
        echo "\n✓ Created signatures upload directory: $upload_dir\n";
    } else {
        echo "\n✗ Failed to create signatures directory\n";
    }
} else {
    echo "\n~ Signatures directory already exists\n";
}

// 6. Final verification
echo "\n=== Final Verification ===\n";
$result = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests");
$final_columns = [];
while ($row = $result->fetch_assoc()) {
    $final_columns[] = $row['Field'];
}

echo "Total columns: " . count($final_columns) . "\n";
echo "Key approval columns present:\n";
echo "  - odl_approval_status: " . (in_array('odl_approval_status', $final_columns) ? '✓' : '✗') . "\n";
echo "  - dean_approval_status: " . (in_array('dean_approval_status', $final_columns) ? '✓' : '✗') . "\n";
echo "  - odl_signature_path: " . (in_array('odl_signature_path', $final_columns) ? '✓' : '✗') . "\n";
echo "  - dean_signature_path: " . (in_array('dean_signature_path', $final_columns) ? '✓' : '✗') . "\n";
echo "  - finance_signature_path: " . (in_array('finance_signature_path', $final_columns) ? '✓' : '✗') . "\n";
echo "  - finance_approved_by: " . (in_array('finance_approved_by', $final_columns) ? '✓' : '✗') . "\n";
echo "  - finance_remarks: " . (in_array('finance_remarks', $final_columns) ? '✓' : '✗') . "\n";

echo "\n=== Summary ===\n";
echo "Database schema has been updated successfully!\n";
echo "The approval workflow is now ready for use.\n";

$conn->close();
?>
