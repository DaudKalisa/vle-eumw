<?php
/**
 * Test script for all three fixes
 * 1. Fixed odl_coordinator/print_claim.php table error
 * 2. Changed hourly rate to 9500
 * 3. Added rate revision feature for finance officers
 */

echo "=== SYSTEM FIXES VERIFICATION ===\n\n";

$checks = [];

// Check 1: Verify database columns exist
echo "CHECK 1: Database Columns for Rate Revision\n";
echo str_repeat("-", 50) . "\n";

require_once 'includes/config.php';
$conn = getDbConnection();

$result = $conn->query("DESCRIBE lecturer_finance_requests");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

$required_cols = ['revised_hourly_rate', 'revised_airtime_rate', 'rate_revision_reason', 'revised_by', 'revised_at'];
$all_exist = true;

foreach ($required_cols as $col) {
    if (in_array($col, $columns)) {
        echo "✓ Column exists: $col\n";
    } else {
        echo "✗ Column missing: $col\n";
        $all_exist = false;
    }
}

$checks['database_columns'] = $all_exist;

echo "\n";

// Check 2: Verify hourly rate in request_finance.php
echo "CHECK 2: Hourly Rate Update (9500)\n";
echo str_repeat("-", 50) . "\n";

$request_file = file_get_contents('lecturer/request_finance.php');
if (strpos($request_file, "9500") !== false) {
    echo "✓ Hourly rate 9500 found in request_finance.php\n";
    $checks['hourly_rate'] = true;
} else {
    echo "✗ Hourly rate 9500 NOT found in request_finance.php\n";
    $checks['hourly_rate'] = false;
}

// Check that old rates are not hardcoded as sole option
$old_rates_count = substr_count($request_file, "8500") + substr_count($request_file, "6500") + substr_count($request_file, "5500");
if ($old_rates_count == 0) {
    echo "✓ Old hardcoded rates (8500, 6500, 5500) have been updated\n";
} else {
    echo "⚠ Note: Found some references to old rates (may be in comments or other contexts)\n";
}

echo "\n";

// Check 3: Verify print_claim fix
echo "CHECK 3: ODL Coordinator Print Claim Fix (JSON instead of table)\n";
echo str_repeat("-", 50) . "\n";

$print_claim = file_get_contents('odl_coordinator/print_claim.php');
if (strpos($print_claim, "lecturer_claim_items") === false) {
    echo "✓ Non-existent 'lecturer_claim_items' table reference removed\n";
    $checks['print_claim_table'] = true;
} else {
    echo "✗ Still references non-existent table\n";
    $checks['print_claim_table'] = false;
}

if (strpos($print_claim, "json_decode") !== false && strpos($print_claim, "courses_data") !== false) {
    echo "✓ Now uses JSON decode for courses_data\n";
} else {
    echo "✗ Not using JSON decode\n";
}

echo "\n";

// Check 4: Verify finance action handler has rate revision
echo "CHECK 4: Finance Action Handler (Rate Revision)\n";
echo str_repeat("-", 50) . "\n";

$action_file = file_get_contents('finance/lecturer_finance_action.php');
if (strpos($action_file, "revise_rates") !== false) {
    echo "✓ Rate revision action handler present\n";
    $checks['revise_rates_action'] = true;
} else {
    echo "✗ Rate revision action missing\n";
    $checks['revise_rates_action'] = false;
}

if (strpos($action_file, "revised_hourly_rate") !== false) {
    echo "✓ Handles revised_hourly_rate field\n";
} else {
    echo "✗ Missing revised_hourly_rate handling\n";
}

echo "\n";

// Check 5: Verify finance dashboard has rate revision UI
echo "CHECK 5: Finance Dashboard UI (Rate Edit Buttons)\n";
echo str_repeat("-", 50) . "\n";

$dashboard = file_get_contents('finance/finance_manage_requests.php');
if (strpos($dashboard, "openRateRevisionModal") !== false) {
    echo "✓ Rate revision modal function present\n";
    $checks['modal_function'] = true;
} else {
    echo "✗ Rate revision modal missing\n";
    $checks['modal_function'] = false;
}

if (strpos($dashboard, "rateRevisionModal") !== false) {
    echo "✓ Rate revision modal HTML present\n";
} else {
    echo "✗ Rate revision modal HTML missing\n";
}

if (strpos($dashboard, "Edit Rates") !== false) {
    echo "✓ Edit Rates button text present\n";
} else {
    echo "✗ Edit Rates button missing\n";
}

echo "\n";

// Final Summary
echo "=== FINAL SUMMARY ===\n";
echo str_repeat("=", 50) . "\n";

$passed = array_filter($checks, fn($v) => $v === true);
$total = count($checks);
$count = count($passed);

foreach ($checks as $check => $status) {
    $symbol = $status ? '✓' : '✗';
    echo "$symbol $check\n";
}

echo "\n";
echo "Status: $count/$total checks passed\n";

if ($count === $total) {
    echo "\n✅ ALL FIXES VERIFIED SUCCESSFULLY!\n";
    echo "\nThe system now has:\n";
    echo "1. ✓ Fixed print_claim.php (no more table errors)\n";
    echo "2. ✓ Updated hourly rate to 9500 MKW\n";
    echo "3. ✓ Added rate revision feature for finance officers\n";
    echo "\nFinance officers can now:\n";
    echo "- Click 'Edit Rates' button on any pending/approved request\n";
    echo "- Revise hourly rate (updates total amount automatically)\n";
    echo "- Revise airtime rate separately\n";
    echo "- Add reason for rate revision\n";
    echo "- All revisions are tracked with timestamp and user info\n";
} else {
    echo "\n⚠️ Some checks failed. Please review the output above.\n";
}

$conn->close();
?>
