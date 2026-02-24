<?php
// finance/record_payment.php - Record Student Payment
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$success_message = '';
$error_message = '';

// Get student ID from query string if provided
$pre_selected_student = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$student_info = null;

// If student ID is provided, get their information
if ($pre_selected_student) {
    $stmt = $conn->prepare("SELECT s.student_id, s.full_name, s.email, s.program_type,
                                   sf.expected_total, sf.total_paid, sf.balance, sf.payment_percentage, sf.content_access_weeks,
                                   d.department_name, d.department_code as program_code, d.department_id
                            FROM students s 
                            LEFT JOIN student_finances sf ON s.student_id = sf.student_id 
                            LEFT JOIN departments d ON s.department = d.department_id 
                            WHERE s.student_id = ?");
    $stmt->bind_param("s", $pre_selected_student);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_info = $result->fetch_assoc();
}

// Get all active students for dropdown
$students_query = "SELECT s.student_id, s.full_name, d.department_code as program_code 
                   FROM students s 
                   LEFT JOIN departments d ON s.department = d.department_id 
                   WHERE s.is_active = TRUE 
                   ORDER BY s.student_id";
$students = $conn->query($students_query);

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = $_POST['student_id'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_type = $_POST['payment_type'] ?? 'payment';
    $payment_method = $_POST['payment_method'] ?? '';
    $bank_name = $_POST['bank_name'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $payment_date = $_POST['payment_date'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Add bank name to notes if provided
    if (!empty($bank_name)) {
        $notes = "Bank: " . $bank_name . (empty($notes) ? "" : " | " . $notes);
    }
    
    // Validation
    if (empty($student_id)) {
        $error_message = "Please select a student.";
    } elseif ($amount <= 0) {
        $error_message = "Please enter a valid amount greater than 0.";
    } elseif (empty($payment_method)) {
        $error_message = "Please select a payment method.";
    } elseif (empty($payment_date)) {
        $error_message = "Please select a payment date.";
    } else {
        $conn->begin_transaction();
        
        try {
            // Get student's program type and current finance data
            $student_query = $conn->prepare("SELECT s.program_type, sf.* 
                                             FROM students s 
                                             LEFT JOIN student_finances sf ON s.student_id COLLATE utf8mb4_general_ci = sf.student_id COLLATE utf8mb4_general_ci 
                                             WHERE s.student_id = ?");
            $student_query->bind_param("s", $student_id);
            $student_query->execute();
            $student_data = $student_query->get_result()->fetch_assoc();
            
            // Get fee settings
            $fee_settings_result = $conn->query("SELECT * FROM fee_settings LIMIT 1");
            $fee_settings = $fee_settings_result->fetch_assoc();
            
            $application_fee = $fee_settings['application_fee'] ?? 5500;
            $registration_fee = $fee_settings['registration_fee'] ?? 39500;
            
            // Calculate tuition based on program type
            $program_type = $student_data['program_type'] ?? 'degree';
            switch ($program_type) {
                case 'professional':
                    $tuition_fee = $fee_settings['tuition_professional'] ?? 200000;
                    break;
                case 'masters':
                    $tuition_fee = $fee_settings['tuition_masters'] ?? 1100000;
                    break;
                case 'doctorate':
                    $tuition_fee = $fee_settings['tuition_doctorate'] ?? 2200000;
                    break;
                case 'degree':
                default:
                    $tuition_fee = $fee_settings['tuition_degree'] ?? 500000;
                    break;
            }
            
            $expected_total = $application_fee + $registration_fee + $tuition_fee;
            $installment_amount = $tuition_fee / 4;
            
            // Check if student_finances record exists, if not create it
            if (!$student_data || !isset($student_data['finance_id'])) {
                $create_finance = $conn->prepare("INSERT INTO student_finances 
                    (student_id, application_fee_paid, registration_paid, installment_1, installment_2, installment_3, installment_4, 
                     total_paid, balance, payment_percentage, content_access_weeks, expected_total, registration_fee, tuition_fee) 
                    VALUES (?, 0, 0, 0, 0, 0, 0, 0, ?, 0, 0, ?, ?, ?)");
                $create_finance->bind_param("sdddd", $student_id, $expected_total, $expected_total, $registration_fee, $tuition_fee);
                $create_finance->execute();
                
                // Reload student data
                $student_query->execute();
                $student_data = $student_query->get_result()->fetch_assoc();
            }
            
            // Get current paid amounts
            $current_app_paid = $student_data['application_fee_paid'] ?? 0;
            $current_reg_paid = $student_data['registration_paid'] ?? 0;
            $current_inst1 = $student_data['installment_1'] ?? 0;
            $current_inst2 = $student_data['installment_2'] ?? 0;
            $current_inst3 = $student_data['installment_3'] ?? 0;
            $current_inst4 = $student_data['installment_4'] ?? 0;
            $current_total_paid = $student_data['total_paid'] ?? 0;
            
            // Distribute the new payment amount
            $remaining_to_distribute = $amount;
            
            // Priority 1: Application Fee Outstanding
            $app_outstanding = $application_fee - $current_app_paid;
            $app_payment = min($remaining_to_distribute, max(0, $app_outstanding));
            $new_app_paid = $current_app_paid + $app_payment;
            $remaining_to_distribute -= $app_payment;
            
            // Priority 2: Registration Fee Outstanding
            $reg_outstanding = $registration_fee - $current_reg_paid;
            $reg_payment = min($remaining_to_distribute, max(0, $reg_outstanding));
            $new_reg_paid = $current_reg_paid + $reg_payment;
            $remaining_to_distribute -= $reg_payment;
            
            // Priority 3: 1st Installment
            $inst1_outstanding = $installment_amount - $current_inst1;
            $inst1_payment = min($remaining_to_distribute, max(0, $inst1_outstanding));
            $new_inst1 = $current_inst1 + $inst1_payment;
            $remaining_to_distribute -= $inst1_payment;
            
            // Priority 4: 2nd Installment
            $inst2_outstanding = $installment_amount - $current_inst2;
            $inst2_payment = min($remaining_to_distribute, max(0, $inst2_outstanding));
            $new_inst2 = $current_inst2 + $inst2_payment;
            $remaining_to_distribute -= $inst2_payment;
            
            // Priority 5: 3rd Installment
            $inst3_outstanding = $installment_amount - $current_inst3;
            $inst3_payment = min($remaining_to_distribute, max(0, $inst3_outstanding));
            $new_inst3 = $current_inst3 + $inst3_payment;
            $remaining_to_distribute -= $inst3_payment;
            
            // Priority 6: 4th Installment
            $inst4_outstanding = $installment_amount - $current_inst4;
            $inst4_payment = min($remaining_to_distribute, max(0, $inst4_outstanding));
            $new_inst4 = $current_inst4 + $inst4_payment;
            $remaining_to_distribute -= $inst4_payment;
            
            // Any remaining amount is credit/overpayment
            $credit_available = $remaining_to_distribute;
            
            $new_total_paid = $current_total_paid + $amount;
            $new_balance = $expected_total - $new_total_paid;
            $payment_percentage = round(($new_total_paid / $expected_total) * 100);
            
            // Determine content access weeks
            if ($payment_percentage >= 100) {
                $content_access_weeks = 52;
            } elseif ($payment_percentage >= 75) {
                $content_access_weeks = 13;
            } elseif ($payment_percentage >= 50) {
                $content_access_weeks = 9;
            } elseif ($payment_percentage >= 25) {
                $content_access_weeks = 4;
            } else {
                $content_access_weeks = 0;
            }
            
            // Record payment in payment_transactions table
            $stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, amount, payment_type, payment_method, reference_number, payment_date, notes, recorded_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $recorded_by = $user['display_name'];
            $stmt->bind_param("sdssssss", $student_id, $amount, $payment_type, $payment_method, $reference_number, $payment_date, $notes, $recorded_by);
            $stmt->execute();
            
            // Get the transaction ID for the receipt
            $transaction_id = $conn->insert_id;
            
            // Set payment dates for newly paid or partially paid items
            $app_fee_date = ($app_payment > 0) ? $payment_date : $student_data['application_fee_date'];
            $reg_paid_date = ($reg_payment > 0) ? $payment_date : $student_data['registration_paid_date'];
            $inst1_date = ($inst1_payment > 0) ? $payment_date : $student_data['installment_1_date'];
            $inst2_date = ($inst2_payment > 0) ? $payment_date : $student_data['installment_2_date'];
            $inst3_date = ($inst3_payment > 0) ? $payment_date : $student_data['installment_3_date'];
            $inst4_date = ($inst4_payment > 0) ? $payment_date : $student_data['installment_4_date'];
            
            // Update student_finances with distributed amounts
            $update_finance = "UPDATE student_finances 
                              SET application_fee_paid = ?,
                                  application_fee_date = ?,
                                  registration_paid = ?,
                                  registration_paid_date = ?,
                                  installment_1 = ?,
                                  installment_1_date = ?,
                                  installment_2 = ?,
                                  installment_2_date = ?,
                                  installment_3 = ?,
                                  installment_3_date = ?,
                                  installment_4 = ?,
                                  installment_4_date = ?,
                                  total_paid = ?,
                                  balance = ?,
                                  payment_percentage = ?,
                                  content_access_weeks = ?,
                                  expected_total = ?,
                                  last_payment_date = ?
                              WHERE student_id = ?";
            $stmt = $conn->prepare($update_finance);
            $stmt->bind_param("dsdsdsdsdsdsdddidss", 
                $new_app_paid, $app_fee_date,
                $new_reg_paid, $reg_paid_date,
                $new_inst1, $inst1_date,
                $new_inst2, $inst2_date,
                $new_inst3, $inst3_date,
                $new_inst4, $inst4_date,
                $new_total_paid, $new_balance, $payment_percentage, $content_access_weeks,
                $expected_total, $payment_date, $student_id
            );
            $stmt->execute();
            
            $conn->commit();
            
            $success_parts = ["Payment of K" . number_format($amount) . " recorded successfully for student $student_id."];
            if ($credit_available > 0) {
                $success_parts[] = "Credit Available: K" . number_format($credit_available) . " (overpayment will be shown on student account).";
            }
            $success_message = implode(" ", $success_parts);
            
            // Redirect to print receipt page for the recorded transaction
            header("Location: payment_receipt.php?id=" . $transaction_id . "&type=transaction&new=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error recording payment: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .info-card {
            background: var(--vle-gradient-primary);
            color: white;
            border-radius: var(--vle-radius);
            padding: 20px;
            margin-bottom: 20px;
        }
        .balance-display {
            font-size: 2rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'record_payment';
    $pageTitle = 'Record Payment';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0"><i class="bi bi-cash-coin"></i> Record Student Payment</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($student_info): 
                            // Calculate correct total fees based on program type
                            $application_fee = 5500;
                            $registration_fee = 39500;
                            $program_type = $student_info['program_type'] ?? 'degree';
                            
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
                            
                            $correct_total_fees = $application_fee + $registration_fee + $tuition;
                            $total_paid = $student_info['total_paid'] ?? 0;
                            $balance = $correct_total_fees - $total_paid;
                            
                            // Get fee payment details
                            $app_fee_paid = $student_info['application_fee_paid'] ?? 0;
                            $reg_fee_paid = $student_info['registration_paid'] ?? 0;
                            
                            $app_fee_outstanding = $application_fee - $app_fee_paid;
                            $reg_fee_outstanding = $registration_fee - $reg_fee_paid;
                            
                            $app_fee_percentage = ($application_fee > 0) ? ($app_fee_paid / $application_fee * 100) : 0;
                            $reg_fee_percentage = ($registration_fee > 0) ? ($reg_fee_paid / $registration_fee * 100) : 0;
                        ?>
                            <div class="info-card">
                                <h5><i class="bi bi-person-badge"></i> Student Financial Information</h5>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Student ID:</strong> <?php echo htmlspecialchars($student_info['student_id']); ?></p>
                                        <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($student_info['full_name']); ?></p>
                                        <p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($student_info['program_code'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <p class="mb-1"><strong>Total Fees:</strong> K<?php echo number_format($correct_total_fees); ?></p>
                                        <p class="mb-1"><strong>Total Paid:</strong> <span class="text-warning">K<?php echo number_format($total_paid); ?></span></p>
                                        <p class="mb-0"><strong>Balance:</strong></p>
                                        <p class="balance-display text-warning">K<?php echo number_format($balance); ?></p>
                                    </div>
                                </div>
                                
                                <hr class="my-3" style="border-color: rgba(255,255,255,0.3);">
                                
                                <!-- Fee Breakdown -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="card bg-white bg-opacity-10 border-0">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <h6 class="mb-0"><i class="bi bi-file-earmark-text"></i> Application Fee</h6>
                                                        <small style="opacity: 0.9;">Required: K<?php echo number_format($application_fee); ?></small>
                                                    </div>
                                                    <?php if ($app_fee_percentage >= 100): ?>
                                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid</span>
                                                    <?php elseif ($app_fee_paid > 0): ?>
                                                        <span class="badge bg-warning"><i class="bi bi-hourglass-split"></i> Partial</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Unpaid</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="progress mb-2" style="height: 8px; background: rgba(255,255,255,0.2);">
                                                    <div class="progress-bar <?php echo ($app_fee_percentage >= 100) ? 'bg-success' : (($app_fee_paid > 0) ? 'bg-warning' : 'bg-danger'); ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo min(100, $app_fee_percentage); ?>%" 
                                                         aria-valuenow="<?php echo $app_fee_percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <small><strong>Paid:</strong> K<?php echo number_format($app_fee_paid); ?></small>
                                                    <small><strong>Remaining:</strong> K<?php echo number_format($app_fee_outstanding); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <div class="card bg-white bg-opacity-10 border-0">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Registration Fee</h6>
                                                        <small style="opacity: 0.9;">Required: K<?php echo number_format($registration_fee); ?></small>
                                                    </div>
                                                    <?php if ($reg_fee_percentage >= 100): ?>
                                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid</span>
                                                    <?php elseif ($reg_fee_paid > 0): ?>
                                                        <span class="badge bg-warning"><i class="bi bi-hourglass-split"></i> Partial</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Unpaid</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="progress mb-2" style="height: 8px; background: rgba(255,255,255,0.2);">
                                                    <div class="progress-bar <?php echo ($reg_fee_percentage >= 100) ? 'bg-success' : (($reg_fee_paid > 0) ? 'bg-warning' : 'bg-danger'); ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo min(100, $reg_fee_percentage); ?>%" 
                                                         aria-valuenow="<?php echo $reg_fee_percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <small><strong>Paid:</strong> K<?php echo number_format($reg_fee_paid); ?></small>
                                                    <small><strong>Remaining:</strong> K<?php echo number_format($reg_fee_outstanding); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Student <span class="text-danger">*</span></label>
                                    <select class="form-select" name="student_id" id="student_select" required onchange="loadStudentInfo(this.value)">
                                        <option value="">-- Select Student --</option>
                                        <?php 
                                        $students->data_seek(0); // Reset pointer
                                        while ($student = $students->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo htmlspecialchars($student['student_id']); ?>" 
                                                    <?php echo ($pre_selected_student == $student['student_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($student['student_id'] . ' - ' . $student['full_name'] . ' (' . ($student['program_code'] ?? 'N/A') . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">K</span>
                                        <input type="number" class="form-control" name="amount" required min="1" step="0.01" placeholder="0.00">
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                                    <select class="form-select" name="payment_type" required>
                                        <option value="payment" selected>General Payment</option>
                                        <option value="registration_fee">Registration Fee</option>
                                        <option value="installment_1">1st Installment</option>
                                        <option value="installment_2">2nd Installment</option>
                                        <option value="installment_3">3rd Installment</option>
                                        <option value="installment_4">4th Installment</option>
                                        <option value="tuition">Tuition Fee</option>
                                        <option value="application">Application Fee</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-select" name="payment_method" id="payment_method" required onchange="toggleBankField()">
                                        <option value="">-- Select Method --</option>
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="bank_deposit">Bank Deposit</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="airtel_money">Airtel Money</option>
                                        <option value="tnm_mpamba">TNM Mpamba</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="card">Card Payment</option>
                                        <option value="electronic_transfer">Electronic Transfer</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row" id="bank_field" style="display: none;">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                                    <select class="form-select" name="bank_name" id="bank_name">
                                        <option value="">-- Select Bank --</option>
                                        <option value="National Bank of Malawi">National Bank of Malawi</option>
                                        <option value="Standard Bank">Standard Bank</option>
                                        <option value="FDH Bank">FDH Bank</option>
                                        <option value="NBS Bank">NBS Bank</option>
                                        <option value="CDH Investment Bank">CDH Investment Bank</option>
                                        <option value="FMB Bank">FMB Bank (First Merchant Bank)</option>
                                        <option value="Ecobank">Ecobank Malawi</option>
                                        <option value="Nedbank">Nedbank Malawi</option>
                                        <option value="MyBucks Banking Corporation">MyBucks Banking Corporation</option>
                                        <option value="Opportunity International Bank">Opportunity International Bank</option>
                                        <option value="Mukuru">Mukuru</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Reference Number / Transaction ID</label>
                                <input type="text" class="form-control" name="reference_number" placeholder="e.g., TRX123456, CHQ789">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Additional payment information or remarks"></textarea>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="student_finances.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Record Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card mt-3 border-info">
                    <div class="card-body">
                        <h6 class="text-info"><i class="bi bi-info-circle"></i> Quick Tips</h6>
                        <ul class="mb-0">
                            <li>Payments are automatically applied to student's account</li>
                            <li>Content access is updated based on payment percentage (25%, 50%, 75%, 100%)</li>
                            <li>All transactions are recorded with timestamp and user information</li>
                            <li>Students can view their payment history in their dashboard</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()

        // Load student info when selected
        function loadStudentInfo(studentId) {
            if (studentId) {
                window.location.href = 'record_payment.php?student_id=' + encodeURIComponent(studentId);
            }
        }

        // Toggle bank field based on payment method
        function toggleBankField() {
            const paymentMethod = document.getElementById('payment_method').value;
            const bankField = document.getElementById('bank_field');
            const bankSelect = document.getElementById('bank_name');
            
            // Show bank field for bank transfer, bank deposit, and electronic transfer
            if (paymentMethod === 'bank_transfer' || paymentMethod === 'bank_deposit' || paymentMethod === 'electronic_transfer') {
                bankField.style.display = 'block';
                bankSelect.required = true;
            } else {
                bankField.style.display = 'none';
                bankSelect.required = false;
                bankSelect.value = '';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleBankField();
        });
    </script>
</body>
</html>

<?php  ?>
