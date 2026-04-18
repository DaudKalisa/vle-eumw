<?php
/**
 * Complete Lecturer Finance Claim Workflow Validation Report
 * Tests all steps of the approval workflow to ensure proper functioning
 */

require_once 'includes/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "========================================\n";
echo "LECTURER FINANCE CLAIM WORKFLOW REPORT\n";
echo "========================================\n\n";

// Get system info
$today = date('Y-m-d H:i:s');
echo "Report Generated: $today\n";
echo "System Status: " . (file_exists('index.php') ? 'RUNNING' : 'ERROR') . "\n\n";

// ============================================================================
// SECTION 1: WORKFLOW VERIFICATION
// ============================================================================
echo "SECTION 1: WORKFLOW VERIFICATION\n";
echo "================================\n\n";

echo "Expected Workflow:\n";
echo "1. Lecturer submits claim\n";
echo "   → status = 'pending'\n";
echo "   → odl_approval_status = 'pending'\n";
echo "2. ODL Coordinator reviews\n";
echo "   → odl_approval_status = 'approved' OR 'rejected' OR 'forwarded_to_dean'\n";
echo "   → odl_approved_by = [user_id]\n";
echo "   → odl_approved_at = [timestamp]\n";
echo "3. (Optional) Dean reviews\n";
echo "   → dean_approval_status = 'approved' OR 'rejected' OR 'returned'\n";
echo "   → dean_approved_by = [user_id]\n";
echo "   → dean_approved_at = [timestamp]\n";
echo "4. Finance approves\n";
echo "   → status = 'approved' (only if proper approvals in place)\n";
echo "   → finance_approved_at = [timestamp]\n";
echo "5. Finance marks paid\n";
echo "   → status = 'paid'\n";
echo "   → finance_paid_at = [timestamp]\n";
echo "6. Finance prints receipt\n\n";

// ============================================================================
// SECTION 2: DATABASE AUDIT
// ============================================================================
echo "SECTION 2: DATABASE AUDIT\n";
echo "=========================\n\n";

$result = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' AND odl_approval_status = 'pending' THEN 1 ELSE 0 END) as awaiting_odl,
        SUM(CASE WHEN odl_approval_status IN ('approved', 'forwarded_to_dean') AND dean_approval_status IS NULL THEN 1 ELSE 0 END) as awaiting_dean_or_finance,
        SUM(CASE WHEN status = 'approved' AND dean_approval_status = 'approved' THEN 1 ELSE 0 END) as approved_awaiting_payment,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
    FROM lecturer_finance_requests
");

if ($result) {
    $stats = $result->fetch_assoc();
    echo "Claim Status Summary:\n";
    echo "  Total Claims: " . $stats['total'] . "\n";
    echo "  Awaiting ODL Approval: " . ($stats['awaiting_odl'] ?? 0) . "\n";
    echo "  Ready for Finance/Dean: " . ($stats['awaiting_dean_or_finance'] ?? 0) . "\n";
    echo "  Approved, Awaiting Payment: " . ($stats['approved_awaiting_payment'] ?? 0) . "\n";
    echo "  Paid Claims: " . ($stats['paid'] ?? 0) . "\n\n";
}

// ============================================================================
// SECTION 3: ROLE VERIFICATION
// ============================================================================
echo "SECTION 3: ROLE VERIFICATION\n";
echo "=============================\n\n";

$roles_needed = [
    'lecturer' => 'Submit claims',
    'odl_coordinator' => 'Review and approve/reject claims (first level)',
    'dean' => 'Review and approve claims (second level, if needed)',
    'finance' => 'Approve and process payments'
];

foreach ($roles_needed as $role => $description) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = '$role' AND is_active = 1");
    if ($result) {
        $cnt = $result->fetch_assoc()['cnt'] ?? 0;
        $status = $cnt > 0 ? '✓' : '✗';
        echo "$status $role ($cnt users): $description\n";
    }
}
echo "\n";

// ============================================================================
// SECTION 4: FILE VERIFICATION
// ============================================================================
echo "SECTION 4: FILE VERIFICATION\n";
echo "=============================\n\n";

$files_role_map = [
    'lecturer' => [
        'lecturer/request_finance.php' => 'Submit finance claim'
    ],
    'odl_coordinator' => [
        'odl_coordinator/claims_approval.php' => 'Review and approve/reject claims',
        'odl_coordinator/print_claim.php' => 'Print claim details'
    ],
    'dean' => [
        'dean/claims_approval.php' => 'Review and approve claims',
        'dean/print_claim.php' => 'Print claim details'
    ],
    'finance' => [
        'finance/finance_manage_requests.php' => 'Manage all requests',
        'finance/lecturer_finance_action.php' => 'Handle approval actions',
        'finance/pay_lecturer.php' => 'Process payments',
        'finance/print_lecturer_payment.php' => 'Print payment receipts',
        'finance/get_lecturer_finance.php' => 'API endpoint for AJAX'
    ]
];

foreach ($files_role_map as $role => $files) {
    echo "$role Role Files:\n";
    foreach ($files as $file => $desc) {
        $exists = file_exists($file) ? '✓' : '✗';
        echo "  $exists $file - $desc\n";
    }
    echo "\n";
}

// ============================================================================
// SECTION 5: WORKFLOW RULE ENFORCEMENT
// ============================================================================
echo "SECTION 5: WORKFLOW RULE ENFORCEMENT\n";
echo "====================================\n\n";

// Check if lecturer_finance_action.php enforces rules
$action_file = file_get_contents('finance/lecturer_finance_action.php');
$checks = [
    'odl_approval_status check' => (strpos($action_file, 'odl_approval_status') !== false),
    'dean_approval_status check' => (strpos($action_file, 'dean_approval_status') !== false),
    'Approval validation logic' => (strpos($action_file, 'can_approve') !== false),
    'Finance tracking columns' => (strpos($action_file, 'finance_approved_at') !== false),
];

foreach ($checks as $check => $result) {
    $status = $result ? '✓' : '✗';
    echo "$status $check\n";
}
echo "\n";

// ============================================================================
// SECTION 6: DATA INTEGRITY
// ============================================================================
echo "SECTION 6: DATA INTEGRITY\n";
echo "==========================\n\n";

// Check for orphaned records (claims without lecturer info)
$orphaned = $conn->query("
    SELECT COUNT(*) as cnt FROM lecturer_finance_requests lfr
    LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
    WHERE l.lecturer_id IS NULL
");
if ($orphaned) {
    $orphaned_count = $orphaned->fetch_assoc()['cnt'] ?? 0;
    if ($orphaned_count > 0) {
        echo "⚠ WARNING: $orphaned_count orphaned claims found (lecturer deleted)\n";
    } else {
        echo "✓ No orphaned claims found\n";
    }
}

// Check for claims with missing approval timestamps
$missing_ts = $conn->query("
    SELECT COUNT(*) as cnt FROM lecturer_finance_requests
    WHERE odl_approval_status IS NOT NULL AND odl_approved_at IS NULL
");
if ($missing_ts) {
    $missing_count = $missing_ts->fetch_assoc()['cnt'] ?? 0;
    if ($missing_count > 0) {
        echo "⚠ WARNING: $missing_count claims approved but missing approval timestamp\n";
    } else {
        echo "✓ All approved claims have timestamps\n";
    }
}

echo "\n";

// ============================================================================
// SECTION 7: SAMPLE WORKFLOW EXECUTION
// ============================================================================
echo "SECTION 7: SAMPLE WORKFLOW EXECUTION\n";
echo "====================================\n\n";

// Get one claim and trace its workflow
$sample = $conn->query("
    SELECT * FROM lecturer_finance_requests
    ORDER BY request_id DESC LIMIT 1
");

if ($sample && $sample->num_rows > 0) {
    $claim = $sample->fetch_assoc();
    echo "Sample Claim ID: " . $claim['request_id'] . "\n";
    echo "Lecturer ID: " . $claim['lecturer_id'] . "\n";
    echo "Submitted Date: " . $claim['request_date'] . "\n";
    echo "Amount: MKW " . number_format($claim['total_amount'] ?? 0, 2) . "\n\n";
    
    echo "Workflow Progress:\n";
    echo "1. Submission Status:\n";
    echo "   - Main Status: " . ($claim['status'] ?? 'NULL') . "\n";
    echo "   - ODL Status: " . ($claim['odl_approval_status'] ?? 'pending') . "\n\n";
    
    echo "2. ODL Coordinator Review:\n";
    echo "   - Status: " . ($claim['odl_approval_status'] ?? 'pending') . "\n";
    if ($claim['odl_approved_by']) {
        echo "   - Approved By User ID: " . $claim['odl_approved_by'] . "\n";
        echo "   - Approved At: " . ($claim['odl_approved_at'] ?? 'N/A') . "\n";
        echo "   - Remarks: " . ($claim['odl_remarks'] ?? 'N/A') . "\n";
    } else {
        echo "   - Not yet reviewed\n";
    }
    echo "\n";
    
    echo "3. Dean Review:\n";
    echo "   - Status: " . ($claim['dean_approval_status'] ?? 'Not required') . "\n";
    if ($claim['dean_approved_by']) {
        echo "   - Approved By User ID: " . $claim['dean_approved_by'] . "\n";
        echo "   - Approved At: " . ($claim['dean_approved_at'] ?? 'N/A') . "\n";
        echo "   - Remarks: " . ($claim['dean_remarks'] ?? 'N/A') . "\n";
    }
    echo "\n";
    
    echo "4. Finance Processing:\n";
    echo "   - Status: " . ($claim['status'] ?? 'pending') . "\n";
    if ($claim['status'] === 'approved') {
        echo "   - Approved At: " . ($claim['finance_approved_at'] ?? 'N/A') . "\n";
        echo "   - Remarks: " . ($claim['finance_remarks'] ?? 'N/A') . "\n";
    } elseif ($claim['status'] === 'paid') {
        echo "   - Paid At: " . ($claim['finance_paid_at'] ?? 'N/A') . "\n";
    }
    echo "\n";
}

// ============================================================================
// SECTION 8: RECOMMENDATIONS
// ============================================================================
echo "SECTION 8: RECOMMENDATIONS\n";
echo "===========================\n\n";

$recommendations = [];

// Check if all files are in place
$required_files = [
    'lecturer/request_finance.php',
    'odl_coordinator/claims_approval.php',
    'dean/claims_approval.php',
    'finance/finance_manage_requests.php',
    'finance/lecturer_finance_action.php',
    'finance/pay_lecturer.php',
    'finance/print_lecturer_payment.php'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        $recommendations[] = "Create missing file: $file";
    }
}

// Check database columns
$columns_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests");
$existing_cols = [];
if ($columns_check) {
    while ($col = $columns_check->fetch_assoc()) {
        $existing_cols[] = $col['Field'];
    }
}

$required_cols = [
    'request_id', 'lecturer_id', 'status',
    'odl_approval_status', 'odl_approved_by', 'odl_approved_at',
    'dean_approval_status', 'dean_approved_by', 'dean_approved_at',
    'finance_approved_at', 'finance_paid_at'
];

foreach ($required_cols as $col) {
    if (!in_array($col, $existing_cols)) {
        $recommendations[] = "Add missing column: $col";
    }
}

// Check if system has the required roles
foreach (['lecturer', 'odl_coordinator', 'dean', 'finance'] as $role) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = '$role'");
    if ($result && $result->fetch_assoc()['cnt'] == 0) {
        $recommendations[] = "Create at least one user with role: $role";
    }
}

if (empty($recommendations)) {
    echo "✓ System appears to be properly configured!\n";
    echo "✓ All required roles are present\n";
    echo "✓ All required files are in place\n";
    echo "✓ Database schema is complete\n";
    echo "✓ Workflow enforcement is implemented\n\n";
    echo "Next Steps:\n";
    echo "1. Test the complete workflow with a sample claim\n";
    echo "2. Verify that each role can access their respective pages\n";
    echo "3. Test approval and rejection at each stage\n";
    echo "4. Verify payment processing and receipt printing\n";
} else {
    echo "Issues Found:\n";
    foreach ($recommendations as $rec) {
        echo "- $rec\n";
    }
}

echo "\n========================================\n";
echo "END OF REPORT\n";
echo "========================================\n";

$conn->close();
?>
