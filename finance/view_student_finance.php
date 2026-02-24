<?php
// finance/view_student_finance.php - View individual student finance details
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$student_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($student_id)) {
    header('Location: student_finances.php');
    exit;
}

// Get student information
$student_query = "SELECT s.student_id, s.full_name, s.email, s.gender, s.program_type,
                         sf.expected_total, sf.total_paid, sf.balance, sf.payment_percentage, sf.content_access_weeks,
                         sf.application_fee_paid, sf.application_fee_date,
                         sf.registration_paid, sf.registration_paid_date,
                         sf.installment_1, sf.installment_1_date,
                         sf.installment_2, sf.installment_2_date,
                         sf.installment_3, sf.installment_3_date,
                         sf.installment_4, sf.installment_4_date,
                         d.department_name, d.department_code as program_code, d.department_id,
                         f.faculty_name, f.faculty_id
                  FROM students s 
                  LEFT JOIN student_finances sf ON s.student_id = sf.student_id 
                  LEFT JOIN departments d ON s.department = d.department_id 
                  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id 
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: student_finances.php');
    exit;
}

$student = $result->fetch_assoc();

// Calculate correct total fees based on program type
$application_fee = 5500;
$registration_fee = 39500;
$program_type = $student['program_type'] ?? 'degree';

switch ($program_type) {
    case 'professional':
        $tuition = 200000;
        $program_label = 'Professional';
        break;
    case 'masters':
        $tuition = 1100000;
        $program_label = 'Masters';
        break;
    case 'doctorate':
        $tuition = 2200000;
        $program_label = 'Doctorate';
        break;
    case 'degree':
    default:
        $tuition = 500000;
        $program_label = 'Degree';
        break;
}

$correct_expected_total = $application_fee + $registration_fee + $tuition;
$total_paid = $student['total_paid'] ?? 0;
$correct_balance = $correct_expected_total - $total_paid;
$correct_payment_percentage = $correct_expected_total > 0 ? round(($total_paid / $correct_expected_total) * 100) : 0;

// Get payment history
$history_query = "SELECT * FROM payment_transactions WHERE student_id = ? ORDER BY payment_date DESC";
$stmt = $conn->prepare($history_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$history = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Finance Details - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .payment-badge-0 { background-color: var(--vle-danger); }
        .payment-badge-25 { background-color: var(--vle-warning); }
        .payment-badge-50 { background-color: var(--vle-info); }
        .payment-badge-75 { background-color: var(--vle-accent); }
        .payment-badge-100 { background-color: var(--vle-success); }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'view_student_finance';
    $pageTitle = 'Student Finance Details';
    include 'header_nav.php'; 
    ?>
        <div class="container-fluid">
            <a class="navbar-brand" href="student_finances.php">
                <i class="bi bi-arrow-left-circle"></i> Back to Student Accounts
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3 text-white">Welcome, <?php echo htmlspecialchars($user['display_name']); ?></span>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <!-- Student Information -->
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Student Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($student['full_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></p>
                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></p>
                        <p><strong>Program:</strong> <?php echo htmlspecialchars($student['program_code'] ?? 'N/A'); ?></p>
                        <p><strong>Faculty:</strong> <?php echo htmlspecialchars($student['faculty_name'] ?? 'N/A'); ?></p>
                        <p><strong>Entry Type:</strong> <?php echo htmlspecialchars($student['entry_type'] ?? 'N/A'); ?></p>
                        <p><strong>Year of Registration:</strong> <?php echo htmlspecialchars($student['year_of_registration'] ?? 'N/A'); ?></p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="record_payment.php?student_id=<?php echo urlencode($student_id); ?>" class="btn btn-success w-100 mb-2">
                            <i class="bi bi-plus-circle"></i> Record Payment
                        </a>
                        <button onclick="window.print()" class="btn btn-primary w-100">
                            <i class="bi bi-printer"></i> Print Statement
                        </button>
                    </div>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="col-md-8">
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-wallet2"></i> Financial Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <h6 class="text-muted">Total Fees (<?php echo $program_label; ?>)</h6>
                                <h4>K<?php echo number_format($correct_expected_total); ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Total Paid</h6>
                                <h4 class="text-success">K<?php echo number_format($total_paid); ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Balance</h6>
                                <h4 class="text-danger">K<?php echo number_format($correct_balance); ?></h4>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-muted">Payment Status</h6>
                                <h4>
                                    <span class="badge payment-badge-<?php echo $correct_payment_percentage; ?>">
                                        <?php echo $correct_payment_percentage; ?>%
                                    </span>
                                </h4>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <strong><i class="bi bi-unlock"></i> Content Access:</strong> 
                            <?php 
                            // Calculate access weeks using payment percentage logic, max 16 weeks
                            $total_paid = $student['total_paid'] ?? 0;
                            $expected_total = $student['expected_total'] ?? 1;
                            $payment_percentage = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
                            if ($payment_percentage >= 1) {
                                $weeks = 16;
                            } elseif ($payment_percentage >= 0.75) {
                                $weeks = 12;
                            } elseif ($payment_percentage >= 0.5) {
                                $weeks = 8;
                            } elseif ($payment_percentage >= 0.25) {
                                $weeks = 4;
                            } else {
                                $weeks = 0;
                            }
                            if ($weeks == 0) {
                                echo 'No access to course materials';
                            } else {
                                echo 'Access to ' . $weeks . ' weeks of course materials (Weeks 1-' . $weeks . ')';
                            }
                            echo '<br><span style="font-size:0.9em;color:#888;">(  25% = 4w, 50% = 8w,75% = 12w, 100% fees = 16w, max 16w)</span>';
                            ?>
                        </div>

                        <h6>Detailed Breakdown</h6>
                        <?php
                        // Use already calculated values based on program type
                        $remaining_after_fees = $correct_expected_total - $application_fee - $registration_fee;
                        $installment_amount = $remaining_after_fees / 4;
                        
                        // Distribute total paid amount across fee types
                        $remaining_to_distribute = $total_paid;
                        
                        // 1. Application Fee
                        $app_paid = min($remaining_to_distribute, $application_fee);
                        $remaining_to_distribute -= $app_paid;
                        
                        // 2. Registration Fee
                        $reg_paid = min($remaining_to_distribute, $registration_fee);
                        $remaining_to_distribute -= $reg_paid;
                        
                        // 3. 1st Installment
                        $inst1_paid = min($remaining_to_distribute, $installment_amount);
                        $remaining_to_distribute -= $inst1_paid;
                        
                        // 4. 2nd Installment
                        $inst2_paid = min($remaining_to_distribute, $installment_amount);
                        $remaining_to_distribute -= $inst2_paid;
                        
                        // 5. 3rd Installment
                        $inst3_paid = min($remaining_to_distribute, $installment_amount);
                        $remaining_to_distribute -= $inst3_paid;
                        
                        // 6. 4th Installment
                        $inst4_paid = min($remaining_to_distribute, $installment_amount);
                        $remaining_to_distribute -= $inst4_paid;
                        
                        // 7. Credit Available (overpayment)
                        $credit_available = $remaining_to_distribute;
                        ?>
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Payment Type</th>
                                    <th>Amount Due</th>
                                    <th>Amount Paid</th>
                                    <th>Balance</th>
                                    <th>Date Paid</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Application Fee</strong></td>
                                    <td>K<?php echo number_format($application_fee); ?></td>
                                    <td class="text-success">K<?php echo number_format($app_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($application_fee - $app_paid); ?></td>
                                    <td><?php echo ($student['application_fee_date']) ? date('M d, Y', strtotime($student['application_fee_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($app_paid >= $application_fee): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($app_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Registration Fee</strong></td>
                                    <td>K<?php echo number_format($registration_fee); ?></td>
                                    <td class="text-success">K<?php echo number_format($reg_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($registration_fee - $reg_paid); ?></td>
                                    <td><?php echo ($student['registration_paid_date']) ? date('M d, Y', strtotime($student['registration_paid_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($reg_paid >= $registration_fee): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($reg_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>1st Installment</strong></td>
                                    <td>K<?php echo number_format($installment_amount); ?></td>
                                    <td class="text-success">K<?php echo number_format($inst1_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($installment_amount - $inst1_paid); ?></td>
                                    <td><?php echo ($student['installment_1_date']) ? date('M d, Y', strtotime($student['installment_1_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($inst1_paid >= $installment_amount): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($inst1_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                </tr>
                                <tr>
                                    <td><strong>2nd Installment</strong></td>
                                    <td>K<?php echo number_format($installment_amount); ?></td>
                                    <td class="text-success">K<?php echo number_format($inst2_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($installment_amount - $inst2_paid); ?></td>
                                    <td><?php echo ($student['installment_2_date']) ? date('M d, Y', strtotime($student['installment_2_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($inst2_paid >= $installment_amount): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($inst2_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>3rd Installment</strong></td>
                                    <td>K<?php echo number_format($installment_amount); ?></td>
                                    <td class="text-success">K<?php echo number_format($inst3_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($installment_amount - $inst3_paid); ?></td>
                                    <td><?php echo ($student['installment_3_date']) ? date('M d, Y', strtotime($student['installment_3_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($inst3_paid >= $installment_amount): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($inst3_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>4th Installment</strong></td>
                                    <td>K<?php echo number_format($installment_amount); ?></td>
                                    <td class="text-success">K<?php echo number_format($inst4_paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($installment_amount - $inst4_paid); ?></td>
                                    <td><?php echo ($student['installment_4_date']) ? date('M d, Y', strtotime($student['installment_4_date'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($inst4_paid >= $installment_amount): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($inst4_paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <?php if ($credit_available > 0): ?>
                        <div class="alert alert-success">
                            <h5><i class="bi bi-cash-stack"></i> Credit Available</h5>
                            <p class="mb-0">
                                <strong>K<?php echo number_format($credit_available, 2); ?></strong> 
                                - This amount represents an overpayment and can be applied to future fees or refunded.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Payment History</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($history->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($payment = $history->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                                <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_type'])); ?></td>
                                                <td><strong>K<?php echo number_format($payment['amount']); ?></strong></td>
                                                <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                                <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($payment['recorded_by']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No payment history found</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php  ?>
