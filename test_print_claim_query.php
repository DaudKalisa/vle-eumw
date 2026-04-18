<?php
/**
 * Test script to verify the print_claim.php SQL query works
 */

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'university_portal';

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "✓ Database connection successful\n";

// Test the problematic query (with a sample request_id)
$request_id = 1;
$stmt = $conn->prepare("
    SELECT r.*, l.full_name, l.email, l.phone, l.department, l.position, l.bank_name, l.account_number, l.staff_id
    FROM lecturer_finance_requests r
    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
    WHERE r.request_id = ?
");

if (!$stmt) {
    die('Prepare failed: ' . $conn->error);
}

$stmt->bind_param("i", $request_id);
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}

$result = $stmt->get_result();
$claim = $result->fetch_assoc();

if ($claim) {
    echo "✓ Query executed successfully\n";
    echo "✓ Claim found - ID: " . $claim['request_id'] . "\n";
    echo "✓ Lecturer: " . $claim['full_name'] . "\n";
    echo "✓ Bank Name: " . ($claim['bank_name'] ?? 'NULL') . "\n";
    echo "✓ Account Number: " . ($claim['account_number'] ?? 'NULL') . "\n";
    echo "✓ Staff ID: " . ($claim['staff_id'] ?? 'NULL') . "\n";
} else {
    echo "✓ Query executed successfully (no results for request_id=1, this is expected if no records exist)\n";
    echo "✓ The columns are accessible - no SQL errors!\n";
}

$stmt->close();
$conn->close();

echo "\n✓ All tests passed! The print_claim.php query should now work.\n";
?>
