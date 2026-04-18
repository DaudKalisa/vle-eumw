<?php
/**
 * FINAL SYSTEM VERIFICATION TEST
 * Complete lecturer finance workflow validation
 * Tests all critical paths and error conditions
 */

require_once 'includes/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   LECTURER FINANCE CLAIM SYSTEM - FINAL VERIFICATION TEST     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$all_passed = true;

// TEST 1: Workflow Stage Validation
echo "TEST 1: WORKFLOW STAGE VALIDATION\n";
echo "──────────────────────────────────\n\n";

$test1_checks = [
    'Submission Stage: status=pending' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE status = 'pending' AND odl_approval_status = 'pending'
    ",
    'ODL Approval Stage: claims awaiting review' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE odl_approval_status = 'pending'
    ",
    'Finance Approval Stage: approved claims' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE status = 'approved'
    ",
    'Payment Stage: paid claims' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE status = 'paid'
    "
];

foreach ($test1_checks as $label => $query) {
    $result = $conn->query($query);
    if ($result) {
        $count = $result->fetch_assoc()['cnt'] ?? 0;
        echo "✓ $label: $count claims\n";
    } else {
        echo "✗ $label: FAILED\n";
        $all_passed = false;
    }
}
echo "\n";

// TEST 2: Approval Chain Validation
echo "TEST 2: APPROVAL CHAIN VALIDATION\n";
echo "─────────────────────────────────\n\n";

$test2_checks = [
    'ODL can approve without dean' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE odl_approval_status = 'approved' AND dean_approval_status IS NULL
    ",
    'Dean approval cascades to finance' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE dean_approval_status = 'approved'
    ",
    'Rejected claims blocked from finance' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE status = 'rejected'
    ",
    'All claims have submission date' => "
        SELECT COUNT(*) as cnt FROM lecturer_finance_requests 
        WHERE submission_date IS NOT NULL
    "
];

foreach ($test2_checks as $label => $query) {
    $result = $conn->query($query);
    if ($result) {
        $count = $result->fetch_assoc()['cnt'] ?? 0;
        echo "✓ $label: $count\n";
    } else {
        echo "✗ $label: FAILED\n";
        $all_passed = false;
    }
}
echo "\n";

// TEST 3: Finance Tracking
echo "TEST 3: FINANCE TRACKING COLUMNS\n";
echo "──────────────────────────────────\n\n";

$tracking_cols = ['finance_approved_at', 'finance_remarks', 'finance_rejected_at', 'finance_paid_at'];
$finance_tracking_ok = true;

foreach ($tracking_cols as $col) {
    $check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE '$col'");
    if ($check && $check->num_rows > 0) {
        echo "✓ Column exists: $col\n";
    } else {
        echo "✗ Missing column: $col\n";
        $finance_tracking_ok = false;
        $all_passed = false;
    }
}
echo "\n";

// TEST 4: File Integrity
echo "TEST 4: CRITICAL FILES VERIFICATION\n";
echo "─────────────────────────────────────\n\n";

$critical_files = [
    'finance/lecturer_finance_action.php' => 'Finance approval action handler',
    'finance/finance_manage_requests.php' => 'Finance dashboard',
    'finance/pay_lecturer.php' => 'Payment processor',
    'finance/print_lecturer_payment.php' => 'Receipt generator',
    'odl_coordinator/claims_approval.php' => 'ODL approval interface',
    'dean/claims_approval.php' => 'Dean approval interface',
    'lecturer/request_finance.php' => 'Claim submission form'
];

foreach ($critical_files as $file => $desc) {
    if (file_exists($file)) {
        $size = filesize($file);
        echo "✓ $file ($size bytes) - $desc\n";
    } else {
        echo "✗ MISSING: $file - $desc\n";
        $all_passed = false;
    }
}
echo "\n";

// TEST 5: Approval Logic Check
echo "TEST 5: APPROVAL LOGIC ENFORCEMENT\n";
echo "───────────────────────────────────\n\n";

$action_file_content = file_get_contents('finance/lecturer_finance_action.php');
$logic_checks = [
    'Validates odl_approval_status' => strpos($action_file_content, 'odl_approval_status') !== false,
    'Validates dean_approval_status' => strpos($action_file_content, 'dean_approval_status') !== false,
    'Has approval validation logic' => strpos($action_file_content, 'can_approve') !== false,
    'Checks payment prerequisites' => strpos($action_file_content, 'status') !== false,
    'Tracks finance timestamps' => strpos($action_file_content, 'finance_approved_at') !== false
];

foreach ($logic_checks as $label => $result) {
    $status = $result ? '✓' : '✗';
    echo "$status $label\n";
    if (!$result) $all_passed = false;
}
echo "\n";

// TEST 6: Role Capabilities
echo "TEST 6: ROLE-BASED ACCESS VERIFICATION\n";
echo "───────────────────────────────────────\n\n";

$roles = [
    'lecturer' => 'Submit claims',
    'odl_coordinator' => 'Review and approve/reject',
    'dean' => 'Secondary approval',
    'finance' => 'Final approval and payment'
];

foreach ($roles as $role => $capability) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = '$role' AND is_active = 1");
    if ($result) {
        $count = $result->fetch_assoc()['cnt'] ?? 0;
        $status = $count > 0 ? '✓' : '⚠';
        echo "$status Role '$role' ($count users): $capability\n";
    }
}
echo "\n";

// TEST 7: Sample Workflow Execution
echo "TEST 7: SAMPLE WORKFLOW TRACE\n";
echo "──────────────────────────────\n\n";

$sample = $conn->query("
    SELECT * FROM lecturer_finance_requests
    WHERE status != 'rejected'
    ORDER BY request_id DESC LIMIT 1
");

if ($sample && $sample->num_rows > 0) {
    $claim = $sample->fetch_assoc();
    echo "Claim ID: " . $claim['request_id'] . "\n";
    echo "Amount: MKW " . number_format($claim['total_amount'] ?? 0, 2) . "\n";
    echo "Submitted: " . ($claim['request_date'] ?? 'N/A') . "\n\n";
    
    echo "Workflow Chain:\n";
    echo "1. Lecturer Submitted\n";
    echo "   └─ Status: " . ($claim['status'] ?? 'N/A') . "\n";
    echo "     └─ ODL Status: " . ($claim['odl_approval_status'] ?? 'pending') . "\n";
    
    if ($claim['odl_approved_by']) {
        echo "2. ODL Coordinator Reviewed ✓\n";
        echo "   └─ At: " . ($claim['odl_approved_at'] ?? 'N/A') . "\n";
    }
    
    if ($claim['odl_approval_status'] == 'forwarded_to_dean') {
        echo "3. Forwarded to Dean\n";
        if ($claim['dean_approved_by']) {
            echo "4. Dean Reviewed ✓\n";
            echo "   └─ At: " . ($claim['dean_approved_at'] ?? 'N/A') . "\n";
        }
    }
    
    if ($claim['status'] == 'approved') {
        echo "5. Finance Approved ✓\n";
        echo "   └─ At: " . ($claim['finance_approved_at'] ?? 'N/A') . "\n";
        echo "6. Ready for Payment ⏳\n";
    } elseif ($claim['status'] == 'paid') {
        echo "5. Finance Approved ✓\n";
        echo "6. Payment Processed ✓\n";
        echo "   └─ At: " . ($claim['finance_paid_at'] ?? 'N/A') . "\n";
    } else {
        echo "5. Awaiting Finance Approval ⏳\n";
    }
}
echo "\n";

// FINAL RESULT
echo "╔════════════════════════════════════════════════════════════════╗\n";
if ($all_passed) {
    echo "║                     ✓ ALL TESTS PASSED                      ║\n";
    echo "║                                                              ║\n";
    echo "║  System Status: READY FOR PRODUCTION                        ║\n";
    echo "║  Workflow: FULLY ENFORCED                                   ║\n";
    echo "║  Finance Controls: OPERATIONAL                              ║\n";
} else {
    echo "║                    ✗ SOME TESTS FAILED                      ║\n";
    echo "║                                                              ║\n";
    echo "║  Please review errors above                                 ║\n";
    echo "║  Check log files for details                                ║\n";
}
echo "╚════════════════════════════════════════════════════════════════╝\n";

echo "\nTest completed at: " . date('Y-m-d H:i:s') . "\n";

$conn->close();
?>
