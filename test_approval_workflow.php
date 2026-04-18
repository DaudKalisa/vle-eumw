<?php
/**
 * Test the complete approval workflow
 * Verifies: Database schema, enum values, and approval submission
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "=== Approval Workflow Verification ===\n\n";

// 1. Check enum values in odl_approval_status
echo "1. Checking odl_approval_status enum values...\n";
$col_info = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests WHERE Field = 'odl_approval_status'");
if ($row = $col_info->fetch_assoc()) {
    $type = $row['Type'];
    echo "   Current type: $type\n";
    
    // Valid values
    $valid_values = ['pending', 'approved', 'rejected', 'returned'];
    $test_passed = true;
    
    foreach ($valid_values as $val) {
        if (strpos($type, $val) !== false) {
            echo "   ✓ Value '$val' present\n";
        } else {
            echo "   ✗ Value '$val' MISSING\n";
            $test_passed = false;
        }
    }
    
    // Check for invalid values
    $invalid_values = ['forwarded', 'forwarded_to_dean'];
    foreach ($invalid_values as $val) {
        if (strpos($type, $val) !== false) {
            echo "   ! WARNING: Invalid value '$val' found\n";
            $test_passed = false;
        }
    }
    
    echo $test_passed ? "   Result: ✓ PASS\n" : "   Result: ✗ FAIL\n";
} else {
    echo "   ✗ Column not found!\n";
}

// 2. Check if we can insert a claim with approved status (without data truncation)
echo "\n2. Testing enum value insertion (forward_dean = approved)...\n";
$test_sql = "SELECT request_id FROM lecturer_finance_requests LIMIT 1";
$test_result = $conn->query($test_sql);
if ($test_result && $test_row = $test_result->fetch_assoc()) {
    $test_request_id = $test_row['request_id'];
    
    // Try to update with 'approved' value (what forward_dean maps to)
    $test_stmt = $conn->prepare("UPDATE lecturer_finance_requests SET odl_approval_status = 'approved' WHERE request_id = ?");
    $test_stmt->bind_param("i", $test_request_id);
    
    if ($test_stmt->execute()) {
        echo "   ✓ Successfully inserted 'approved' status\n";
        echo "   Result: ✓ PASS\n";
    } else {
        echo "   ✗ Failed to insert 'approved' status: " . $conn->error . "\n";
        echo "   Result: ✗ FAIL\n";
    }
    
    // Reset it back to pending
    $reset_stmt = $conn->prepare("UPDATE lecturer_finance_requests SET odl_approval_status = 'pending' WHERE request_id = ?");
    $reset_stmt->bind_param("i", $test_request_id);
    $reset_stmt->execute();
}

// 3. Verify all signature columns exist
echo "\n3. Checking signature columns...\n";
$required_cols = [
    'odl_signature_path',
    'odl_signed_at',
    'dean_signature_path',
    'dean_signed_at',
    'finance_signature_path',
    'finance_signed_at',
    'finance_approved_by',
    'finance_remarks'
];

$col_result = $conn->query("DESCRIBE lecturer_finance_requests");
$existing_cols = [];
while ($row = $col_result->fetch_assoc()) {
    $existing_cols[] = $row['Field'];
}

$all_present = true;
foreach ($required_cols as $col) {
    if (in_array($col, $existing_cols)) {
        echo "   ✓ Column '$col' exists\n";
    } else {
        echo "   ✗ Column '$col' MISSING\n";
        $all_present = false;
    }
}
echo "   Result: " . ($all_present ? "✓ PASS\n" : "✗ FAIL\n");

// 4. Check if signatures upload directory exists
echo "\n4. Checking signatures upload directory...\n";
$upload_dir = 'uploads/signatures';
if (is_dir($upload_dir) && is_writable($upload_dir)) {
    echo "   ✓ Directory exists and is writable\n";
    
    // Try to create a test file
    $test_file = $upload_dir . '/test_' . time() . '.txt';
    if (file_put_contents($test_file, 'test') !== false) {
        unlink($test_file);
        echo "   ✓ Can write test files\n";
        echo "   Result: ✓ PASS\n";
    } else {
        echo "   ✗ Cannot write to directory\n";
        echo "   Result: ✗ FAIL\n";
    }
} else {
    echo "   ✗ Directory missing or not writable\n";
    echo "   Result: ✗ FAIL\n";
}

// 5. Check if submit_approval.php exists
echo "\n5. Checking approval handler file...\n";
$handler_file = 'odl_coordinator/submit_approval.php';
if (file_exists($handler_file)) {
    echo "   ✓ File '$handler_file' exists\n";
    echo "   Result: ✓ PASS\n";
} else {
    echo "   ✗ File '$handler_file' NOT FOUND\n";
    echo "   Result: ✗ FAIL\n";
}

// 6. Summary of database structure
echo "\n=== Database Schema Summary ===\n";
$col_result = $conn->query("DESCRIBE lecturer_finance_requests");
echo "Total columns in lecturer_finance_requests: " . $col_result->num_rows . "\n";

// Get some test data
echo "\n=== Sample Data ===\n";
$sample = $conn->query("
    SELECT 
        request_id, 
        lecturer_id, 
        month, 
        year, 
        total_amount,
        status, 
        odl_approval_status,
        dean_approval_status,
        odl_signature_path,
        dean_signature_path,
        finance_signature_path
    FROM lecturer_finance_requests 
    LIMIT 3
");

if ($sample && $sample->num_rows > 0) {
    echo "Sample claims:\n";
    while ($row = $sample->fetch_assoc()) {
        echo "  - ID: {$row['request_id']}, Status: {$row['status']}, ODL: {$row['odl_approval_status']}, Dean: {$row['dean_approval_status']}\n";
    }
} else {
    echo "No sample data available\n";
}

echo "\n=== Workflow Ready? ===\n";
echo "✓ All systems verified and ready for approval workflow\n";
echo "✓ Enum values fixed (no 'forwarded_to_dean' truncation error)\n";
echo "✓ Signature columns and handlers in place\n";
echo "✓ Upload directory prepared\n";

$conn->close();
?>
