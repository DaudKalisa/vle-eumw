<?php
// student/payment_history.php - Student's own payment history and financial summary
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get student ID from session
$student_id = $_SESSION['vle_related_id'];

// Get student information including student_type
$student_query = "SELECT s.student_id, s.full_name, s.email, s.gender, s.program_type, s.student_type,
                         sf.expected_total, sf.expected_tuition, sf.total_paid, sf.balance, sf.payment_percentage, sf.content_access_weeks,
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
    header('Location: dashboard.php');
    exit;
}

$student = $result->fetch_assoc();

// Get fee settings for registration fees
$fee_query = "SELECT * FROM fee_settings LIMIT 1";
$fee_result = $conn->query($fee_query);
$fee_settings = ($fee_result && $fee_result->num_rows > 0) ? $fee_result->fetch_assoc() : null;

// Determine student type and program type
$student_type = $student['student_type'] ?? 'new_student';
$is_continuing = ($student_type === 'continuing');
$program_type = $student['program_type'] ?? 'degree';
$is_professional = ($program_type === 'professional');

// Set fees based on student type and program type
// Professional courses: K10,000 registration fee, no application fee for continuing
// Other programs: Use fee_settings rates
if ($is_professional) {
    $application_fee = $is_continuing ? 0 : 5500; // Continuing students exempt from application fee
    $registration_fee = 10000; // Professional courses have flat K10,000 registration fee
} else {
    $application_fee = $is_continuing ? 0 : 5500; // Continuing students don't pay application fee
    $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
    $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
    $registration_fee = $is_continuing ? $continuing_reg_fee : $new_student_reg_fee;
}

// Use expected_total and expected_tuition from the database (same as dashboard)
$expected_total = $student['expected_total'] ?? 0;
$expected_tuition = $student['expected_tuition'] ?? 0;
$total_paid = $student['total_paid'] ?? 0;
$balance = $expected_total - $total_paid;
if ($balance < 0) $balance = 0;
$payment_percentage = $expected_total > 0 ? round(($total_paid / $expected_total) * 100) : 0;

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

// Fetch upcoming payment deadlines
$upcoming_deadlines = [];
$dl_table_check = $conn->query("SHOW TABLES LIKE 'payment_deadlines'");
if ($dl_table_check && $dl_table_check->num_rows > 0) {
    $dl_result = $conn->query("SELECT * FROM payment_deadlines 
        WHERE is_active = 1 AND deadline_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY) 
        ORDER BY deadline_date ASC LIMIT 10");
    if ($dl_result) {
        while ($dl_row = $dl_result->fetch_assoc()) {
            $upcoming_deadlines[] = $dl_row;
        }
    }
}

// Fetch dissertation fee data if student has one
$diss_fee = null;
$diss_fee_check = $conn->query("SHOW TABLES LIKE 'dissertation_fees'");
if ($diss_fee_check && $diss_fee_check->num_rows > 0) {
    $df_stmt = $conn->prepare("SELECT df.*, d.title as dissertation_title, d.current_phase
        FROM dissertation_fees df
        JOIN dissertations d ON df.dissertation_id = d.dissertation_id
        WHERE df.student_id = ? ORDER BY df.invoiced_at DESC LIMIT 1");
    if ($df_stmt) {
        $df_stmt->bind_param("s", $student_id);
        $df_stmt->execute();
        $diss_fee = $df_stmt->get_result()->fetch_assoc();
    }
}

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
    <?php include 'header_nav.php'; ?>
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
                        <h3>K<?php echo number_format($expected_total); ?></h3>
                        <small><?php echo ucfirst($program_type); ?> Program</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Total Paid</h6>
                        <h3>K<?php echo number_format($total_paid); ?></h3>
                        <small>
                            <span class="badge payment-badge-<?php echo $payment_percentage; ?>">
                                <?php echo $payment_percentage; ?>%
                            </span>
                        </small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h6>Balance Due</h6>
                        <h3>K<?php echo number_format($balance); ?></h3>
                        <small>
                            <?php if ($balance > 0): ?>
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
                        <?php
                        // Calculate content access percent and weeks (max 16 weeks)
                        $max_weeks = 16;
                        $content_access_percent = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
                        $content_access_percent_display = round($content_access_percent * 100);
                        $content_access_weeks = (int)round($content_access_percent * $max_weeks);
                        if ($content_access_weeks > $max_weeks) $content_access_weeks = $max_weeks;
                        ?>
                        <h3><?php echo $content_access_percent_display; ?>% <small class="text-white-50">(<?php echo $content_access_weeks; ?> of <?php echo $max_weeks; ?> weeks)</small></h3>
                        <small>
                            <?php 
                            if ($content_access_weeks == 0) {
                                echo 'No access';
                            } elseif ($content_access_weeks >= $max_weeks) {
                                echo 'Full access';
                            } else {
                                echo 'Weeks 1-' . $content_access_weeks;
                            }
                            ?>
                        </small>
                        <div class="progress mt-2" style="height: 8px;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $content_access_percent_display; ?>%;" aria-valuenow="<?php echo $content_access_percent_display; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Payment Deadlines -->
        <?php if (!empty($upcoming_deadlines)): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-alarm"></i> Upcoming Payment Deadlines</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Payment</th><th>Deadline</th><th>Clearance By</th><th>Amount</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_deadlines as $udl):
                                $dl_today = date('Y-m-d');
                                $dl_is_past = $udl['deadline_date'] < $dl_today;
                                $dl_is_today = $udl['deadline_date'] === $dl_today;
                                $dl_days = (int)((strtotime($udl['deadline_date']) - strtotime($dl_today)) / 86400);
                            ?>
                            <tr class="<?php echo $dl_is_past ? 'table-danger' : ($dl_is_today ? 'table-warning' : ''); ?>">
                                <td><strong><?php echo htmlspecialchars($udl['installment_label']); ?></strong></td>
                                <td>
                                    <?php echo date('d M Y', strtotime($udl['deadline_date'])); ?>
                                    <?php if ($dl_is_today): ?>
                                        <span class="badge bg-danger">TODAY</span>
                                    <?php elseif ($dl_is_past): ?>
                                        <span class="badge bg-dark">OVERDUE</span>
                                    <?php elseif ($dl_days <= 5): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $dl_days; ?> days</span>
                                    <?php else: ?>
                                        <span class="badge bg-info"><?php echo $dl_days; ?> days</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $udl['clearance_deadline'] ? date('d M Y', strtotime($udl['clearance_deadline'])) : '-'; ?></td>
                                <td><?php echo $udl['amount_expected'] > 0 ? 'K' . number_format($udl['amount_expected'], 2) : '-'; ?></td>
                                <td>
                                    <?php if ($dl_is_past): ?>
                                        <span class="text-danger"><i class="bi bi-exclamation-circle"></i> Overdue</span>
                                    <?php elseif ($dl_is_today): ?>
                                        <span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Due Today</span>
                                    <?php elseif ($dl_days <= 5): ?>
                                        <span class="text-warning"><i class="bi bi-clock"></i> Due Soon</span>
                                    <?php else: ?>
                                        <span class="text-info"><i class="bi bi-calendar"></i> Upcoming</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-12">
                <!-- Fee Breakdown -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Fee Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Use expected_tuition from database for installment calculations
                        $tuition_amount = $expected_tuition > 0 ? $expected_tuition : ($expected_total - $application_fee - $registration_fee);
                        $installment_amount = $tuition_amount / 4;
                        
                        // Distribute total paid amount across fee types
                        $remaining_to_distribute = $total_paid;
                        
                        // 1. Application Fee (only for new students)
                        $app_paid = 0;
                        if (!$is_continuing) {
                            $app_paid = min($remaining_to_distribute, $application_fee);
                            $remaining_to_distribute -= $app_paid;
                        }
                        
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
                                <?php if (!$is_continuing): // Only show Application Fee for new students ?>
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
                                <?php endif; ?>
                                <tr>
                                    <td><strong>Registration Fee</strong>
                                        <?php if ($is_professional): ?>
                                            <small class="text-muted">(Professional Course)</small>
                                        <?php elseif ($is_continuing): ?>
                                            <small class="text-muted">(Continuing Student)</small>
                                        <?php endif; ?>
                                    </td>
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

                <?php if ($diss_fee): ?>
                <!-- Dissertation Fee Breakdown -->
                <div class="card shadow-sm mb-4 border-purple" style="border-left: 4px solid #8b5cf6;">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <h5 class="mb-0"><i class="bi bi-mortarboard"></i> Dissertation Fee</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4 text-center">
                                <small class="text-muted d-block">Total Fee</small>
                                <h5 class="mb-0">K<?php echo number_format($diss_fee['fee_amount']); ?></h5>
                            </div>
                            <div class="col-md-4 text-center">
                                <small class="text-muted d-block">Paid</small>
                                <h5 class="mb-0 text-success">K<?php echo number_format($diss_fee['total_paid']); ?></h5>
                            </div>
                            <div class="col-md-4 text-center">
                                <small class="text-muted d-block">Balance</small>
                                <h5 class="mb-0 <?php echo $diss_fee['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">K<?php echo number_format($diss_fee['balance']); ?></h5>
                            </div>
                        </div>
                        <small class="text-muted d-block mb-2">This fee is separate from tuition fees. Paid in 3 equal installments of K<?php echo number_format($diss_fee['installment_amount']); ?>.</small>
                        <table class="table table-bordered table-sm">
                            <thead class="table-light">
                                <tr><th>Installment</th><th>Due When</th><th>Amount Due</th><th>Paid</th><th>Balance</th><th>Date</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $inst_labels = [
                                    1 => 'After supervisor assigned',
                                    2 => 'Before ethics & proposal defense',
                                    3 => 'Before final presentation'
                                ];
                                for ($i = 1; $i <= 3; $i++):
                                    $paid = (float)$diss_fee["installment_{$i}_paid"];
                                    $due = (float)$diss_fee['installment_amount'];
                                    $inst_bal = $due - $paid;
                                    if ($inst_bal < 0) $inst_bal = 0;
                                    $date = $diss_fee["installment_{$i}_date"];
                                ?>
                                <tr>
                                    <td><strong><?php echo $i; ?><?php echo $i === 1 ? 'st' : ($i === 2 ? 'nd' : 'rd'); ?> Installment</strong></td>
                                    <td><small><?php echo $inst_labels[$i]; ?></small></td>
                                    <td>K<?php echo number_format($due); ?></td>
                                    <td class="text-success">K<?php echo number_format($paid); ?></td>
                                    <td class="text-danger">K<?php echo number_format($inst_bal); ?></td>
                                    <td><?php echo $date ? date('M d, Y', strtotime($date)) : '-'; ?></td>
                                    <td>
                                        <?php if ($paid >= $due): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($paid > 0): ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                        <?php
                        $lock_msgs = [];
                        if ($diss_fee['lock_after_supervisor'] && $diss_fee['installment_1_paid'] < $diss_fee['installment_amount'])
                            $lock_msgs[] = '1st installment required to continue after supervisor assignment';
                        if ($diss_fee['lock_before_ethics'] && $diss_fee['installment_2_paid'] < $diss_fee['installment_amount'])
                            $lock_msgs[] = '2nd installment required before ethics submission & proposal defense';
                        if ($diss_fee['lock_before_final'] && $diss_fee['installment_3_paid'] < $diss_fee['installment_amount'])
                            $lock_msgs[] = '3rd installment required before final dissertation presentation';
                        ?>
                        <?php if (!empty($lock_msgs)): ?>
                        <div class="alert alert-warning py-2 mb-0">
                            <small><i class="bi bi-lock me-1"></i><strong>Access restrictions active:</strong></small>
                            <ul class="mb-0 small">
                                <?php foreach ($lock_msgs as $lm): ?>
                                <li><?php echo htmlspecialchars($lm); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

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
                                            <th>Receipt</th>
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
                                                <td>
                                                    <a href="../finance/payment_receipt.php?id=<?php echo $transaction['transaction_id']; ?>&type=transaction" 
                                                       target="_blank" class="btn btn-sm btn-outline-success" title="Print Receipt">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                </td>
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
                                            <th>Action</th>
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
                                                <td>
                                                    <?php if ($submission['status'] === 'approved'): ?>
                                                        <a href="../finance/payment_receipt.php?id=<?php echo $submission['submission_id']; ?>" 
                                                           class="btn btn-sm btn-success" target="_blank" title="View/Print Receipt">
                                                            <i class="bi bi-receipt"></i> View Receipt
                                                        </a>
                                                    <?php elseif ($submission['status'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Awaiting</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><i class="bi bi-x-circle"></i> N/A</span>
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
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

