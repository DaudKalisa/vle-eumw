<?php
// student/payment_history.php - Student's own payment history and financial summary
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get student ID from session
$student_id = $_SESSION['vle_related_id'];

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
                  LEFT JOIN student_finances sf ON s.student_id COLLATE utf8mb4_unicode_ci = sf.student_id COLLATE utf8mb4_unicode_ci 
                  LEFT JOIN departments d ON s.department = d.department_id 
                  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id 
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: dashboard.php');
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
        break;
    case 'masters':
        $tuition = 1100000;
        break;
    case 'doctorate':
        $tuition = 2200000;
        break;
    case 'degree':
    default:
        $tuition = 500000;
        break;
}

$correct_expected_total = $application_fee + $registration_fee + $tuition;
$total_paid = $student['total_paid'] ?? 0;
$correct_balance = $correct_expected_total - $total_paid;
$correct_payment_percentage = $correct_expected_total > 0 ? round(($total_paid / $correct_expected_total) * 100) : 0;

// Get payment transactions history
$transactions_query = "SELECT * FROM payment_transactions 
                      WHERE student_id = ? 
                      ORDER BY payment_date DESC, created_at DESC";
$stmt = $conn->prepare($transactions_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$transactions = $stmt->get_result();

// Get payment submissions (pending/approved/rejected)
$submissions_query = "SELECT * FROM payment_submissions 
                     WHERE student_id = ? 
                     ORDER BY submission_date DESC";
$stmt = $conn->prepare($submissions_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$submissions = $stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payment History - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .payment-badge-0 { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        .payment-badge-25 { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); }
        .payment-badge-50 { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); }
        .payment-badge-75 { background: linear-gradient(135deg, #20c997 0%, #1aa179 100%); }
        .payment-badge-100 { background: linear-gradient(135deg, #28a745 0%, #218838 100%); }
        .finance-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3 text-white">
                    <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($student['full_name']); ?>
                </span>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2><i class="bi bi-receipt"></i> My Payment History & Financial Summary</h2>
        <p class="text-muted">View your payment status and transaction history</p>

        <!-- Financial Summary Card -->
        <div class="finance-summary-card">
            <h4 class="mb-4"><i class="bi bi-wallet2"></i> Financial Summary</h4>
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Total Fees</h6>
                        <h3>K<?php echo number_format($correct_expected_total); ?></h3>
                        <small><?php echo ucfirst($program_type); ?> Program</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Total Paid</h6>
                        <h3>K<?php echo number_format($total_paid); ?></h3>
                        <small>
                            <span class="badge payment-badge-<?php echo $correct_payment_percentage; ?>">
                                <?php echo $correct_payment_percentage; ?>%
                            </span>
                        </small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Balance Due</h6>
                        <h3>K<?php echo number_format($correct_balance); ?></h3>
                        <small>
                            <?php if ($correct_balance > 0): ?>
                                <i class="bi bi-exclamation-circle"></i> Outstanding
                            <?php else: ?>
                                <i class="bi bi-check-circle"></i> Paid in Full
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Content Access</h6>
                        <h3><?php echo $student['content_access_weeks'] ?? 0; ?> Weeks</h3>
                        <small>
                            <?php 
                            $weeks = $student['content_access_weeks'] ?? 0;
                            if ($weeks == 0) {
                                echo 'No access';
                            } elseif ($weeks >= 52) {
                                echo 'Full access';
                            } else {
                                echo 'Weeks 1-' . $weeks;
                            }
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <!-- Fee Breakdown -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Fee Breakdown</h5>
                    </div>
                    <div class="card-body">
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
                                - This amount represents an overpayment and can be applied to future fees.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Transactions History -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Payment Transaction History</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($transactions->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Payment Type</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $count = 1;
                                        while ($transaction = $transactions->fetch_assoc()): 
                                        ?>
                                            <tr>
                                                <td><?php echo $count++; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($transaction['payment_date'])); ?></td>
                                                <td class="text-success"><strong>K<?php echo number_format($transaction['amount']); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $type_labels = [
                                                        'payment' => 'General Payment',
                                                        'application' => 'Application Fee',
                                                        'registration_fee' => 'Registration Fee',
                                                        'installment_1' => '1st Installment',
                                                        'installment_2' => '2nd Installment',
                                                        'installment_3' => '3rd Installment',
                                                        'installment_4' => '4th Installment',
                                                        'tuition' => 'Tuition Fee'
                                                    ];
                                                    echo $type_labels[$transaction['payment_type']] ?? ucfirst($transaction['payment_type']);
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['payment_method'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['reference_number'] ?? '-'); ?></td>
                                                <td><small><?php echo htmlspecialchars($transaction['recorded_by'] ?? 'System'); ?></small></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No payment transactions recorded yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Submissions (Pending Review) -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Payment Submissions Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($submissions->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Submission Date</th>
                                            <th>Amount</th>
                                            <th>Reference</th>
                                            <th>Bank/Method</th>
                                            <th>Status</th>
                                            <th>Reviewed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $count = 1;
                                        while ($submission = $submissions->fetch_assoc()): 
                                        ?>
                                            <tr>
                                                <td><?php echo $count++; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($submission['submission_date'])); ?></td>
                                                <td><strong>K<?php echo number_format($submission['amount']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($submission['payment_reference']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($submission['bank_name'] ?? $submission['transaction_type']); ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $status = $submission['status'];
                                                    $badge_class = 'secondary';
                                                    if ($status == 'approved') $badge_class = 'success';
                                                    elseif ($status == 'rejected') $badge_class = 'danger';
                                                    elseif ($status == 'pending') $badge_class = 'warning';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (isset($submission['reviewed_at']) && $submission['reviewed_at']): ?>
                                                        <small><?php echo date('M d, Y', strtotime($submission['reviewed_at'])); ?></small>
                                                        <?php if (isset($submission['rejection_reason']) && $submission['rejection_reason']): ?>
                                                            <br><small class="text-danger">Reason: <?php echo htmlspecialchars($submission['rejection_reason']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small class="text-muted">Pending</small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No payment submissions found. You can submit payment proof from the dashboard.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="text-center mb-4">
                    <a href="submit_payment.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-upload"></i> Submit Payment Proof
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary btn-lg">
                        <i class="bi bi-house"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// ALTER TABLE statement to add the new column for marked file notification
// This should be run once as a migration, not on every page load
$conn = getDbConnection();
$alter_table_query = "ALTER TABLE vle_submissions ADD COLUMN marked_file_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER marked_file_name";
$conn->query($alter_table_query);
$conn->close();
?>
