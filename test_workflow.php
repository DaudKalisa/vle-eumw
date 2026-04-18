<?php
/**
 * Test the complete lecturer finance claim workflow
 * Checks database schema, column existence, and workflow logic
 */

require_once 'includes/config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "=== LECTURER FINANCE WORKFLOW TEST ===\n\n";

// 1. Check if lecturer_finance_requests table exists
echo "1. Checking lecturer_finance_requests table...\n";
$result = $conn->query("SHOW TABLES LIKE 'lecturer_finance_requests'");
if ($result && $result->num_rows > 0) {
    echo "   ✓ Table exists\n";
    
    // 2. Check columns
    echo "\n2. Checking required columns...\n";
    $columns = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests");
    $col_names = [];
    $required_columns = [
        'request_id', 'lecturer_id', 'request_date', 'status',
        'odl_approval_status', 'odl_approved_by', 'odl_approved_at', 'odl_remarks',
        'dean_approval_status', 'dean_approved_by', 'dean_approved_at', 'dean_remarks'
    ];
    
    if ($columns) {
        while ($col = $columns->fetch_assoc()) {
            $col_names[] = $col['Field'];
        }
    }
    
    $missing = [];
    foreach ($required_columns as $col) {
        if (in_array($col, $col_names)) {
            echo "   ✓ $col\n";
        } else {
            echo "   ✗ MISSING: $col\n";
            $missing[] = $col;
        }
    }
    
    if (!empty($missing)) {
        echo "\n   ACTION NEEDED: Add missing columns\n";
        echo "   Missing columns: " . implode(", ", $missing) . "\n";
    }
    
    // 3. Check data
    echo "\n3. Checking sample data...\n";
    $count_result = $conn->query("SELECT COUNT(*) as cnt FROM lecturer_finance_requests");
    if ($count_result) {
        $count = $count_result->fetch_assoc()['cnt'];
        echo "   Total requests: $count\n";
        
        if ($count > 0) {
            $status_result = $conn->query("
                SELECT 
                    status,
                    odl_approval_status,
                    dean_approval_status,
                    COUNT(*) as cnt
                FROM lecturer_finance_requests
                GROUP BY status, odl_approval_status, dean_approval_status
            ");
            
            if ($status_result) {
                echo "   Status breakdown:\n";
                while ($row = $status_result->fetch_assoc()) {
                    echo "     - status: " . $row['status'];
                    echo ", ODL: " . ($row['odl_approval_status'] ?? 'NULL');
                    echo ", Dean: " . ($row['dean_approval_status'] ?? 'NULL');
                    echo " [Count: " . $row['cnt'] . "]\n";
                }
            }
        }
    }
    
} else {
    echo "   ✗ Table does not exist!\n";
}

// 4. Check for ODL coordinator role
echo "\n4. Checking ODL Coordinator role...\n";
$odl_check = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'odl_coordinator'");
if ($odl_check) {
    $odl_count = $odl_check->fetch_assoc()['cnt'];
    echo "   ODL Coordinators in system: $odl_count\n";
    if ($odl_count == 0) {
        echo "   ⚠ WARNING: No ODL coordinators created!\n";
    }
}

// 5. Check dean capabilities
echo "\n5. Checking Dean users...\n";
$dean_check = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'dean'");
if ($dean_check) {
    $dean_count = $dean_check->fetch_assoc()['cnt'];
    echo "   Deans in system: $dean_count\n";
    if ($dean_count == 0) {
        echo "   ⚠ WARNING: No deans created!\n";
    }
}

// 6. Check finance users
echo "\n6. Checking Finance users...\n";
$fin_check = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role = 'finance'");
if ($fin_check) {
    $fin_count = $fin_check->fetch_assoc()['cnt'];
    echo "   Finance users in system: $fin_count\n";
    if ($fin_count == 0) {
        echo "   ⚠ WARNING: No finance users created!\n";
    }
}

// 7. Check lecturer stats
echo "\n7. Checking Lecturers...\n";
$lec_check = $conn->query("SELECT COUNT(*) as cnt FROM lecturers");
if ($lec_check) {
    $lec_count = $lec_check->fetch_assoc()['cnt'];
    echo "   Lecturers in system: $lec_count\n";
    if ($lec_count == 0) {
        echo "   ⚠ WARNING: No lecturers created!\n";
    }
}

// 8. Check required files
echo "\n8. Checking required files...\n";
$required_files = [
    'lecturer/request_finance.php' => 'Lecturer claim submission',
    'odl_coordinator/claims_approval.php' => 'ODL coordinator approval',
    'dean/claims_approval.php' => 'Dean approval',
    'finance/finance_manage_requests.php' => 'Finance dashboard',
    'finance/pay_lecturer.php' => 'Payment processing',
    'finance/print_lecturer_payment.php' => 'Payment receipt printing'
];

foreach ($required_files as $file => $desc) {
    if (file_exists($file)) {
        echo "   ✓ $file - $desc\n";
    } else {
        echo "   ✗ MISSING: $file - $desc\n";
    }
}

// 9. Workflow simulation
echo "\n9. Workflow Simulation...\n";
echo "   Step 1: Lecturer submits claim → status='pending', odl_approval_status='pending'\n";
echo "   Step 2: ODL reviews → odl_approval_status='approved'/'rejected'/'forwarded_to_dean'\n";
echo "   Step 3: Dean reviews (if forwarded) → dean_approval_status='approved'/'rejected'/'returned'\n";
echo "   Step 4: Finance marks as approved → status='approved'\n";
echo "   Step 5: Finance processes payment → status='paid'\n";
echo "   Step 6: Finance prints receipt\n";

echo "\n=== TEST COMPLETE ===\n";

$conn->close();
?>
