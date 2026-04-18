<?php
// Direct database connection for testing
$conn = mysqli_connect('localhost', 'root', '', 'university_portal');
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

echo "=== Finance Claims in Database ===\n\n";

// First check table structure
echo "Columns in lecturer_finance_requests table:\n";
$result = mysqli_query($conn, "DESCRIBE lecturer_finance_requests");
while($row = mysqli_fetch_assoc($result)) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n\nFirst 5 Claims:\n";
echo str_repeat("-", 120) . "\n";

$result = mysqli_query($conn, "SELECT request_id, status, odl_approval_status, dean_approval_status FROM lecturer_finance_requests LIMIT 5");
if (!$result) {
    echo "Error: " . mysqli_error($conn) . "\n";
} else {
    $count = 0;
    while($r = mysqli_fetch_assoc($result)) {
        $count++;
        echo "[$count] ID: " . $r['request_id'] . 
             " | Status: " . $r['status'] .
             " | ODL Approval: " . $r['odl_approval_status'] .
             " | Dean Approval: " . $r['dean_approval_status'] . "\n";
    }
    if ($count == 0) {
        echo "No claims found.\n";
    }
}

echo "\n\nWorkflow Test URLs:\n";
echo "http://localhost/vle-eumw/odl_coordinator/print_claim.php?id=<request_id>\n";
echo "http://localhost/vle-eumw/dean/print_claim.php?id=<request_id>\n";
?>
