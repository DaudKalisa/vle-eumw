<?php
// finance/charge_exam_fees.php - Charge Supplementary and Deferred Examination Fees
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$success_message = '';
$error_message = '';

// Get fee settings
$fee_result = $conn->query("SELECT * FROM fee_settings LIMIT 1");
$fee_settings = $fee_result ? $fee_result->fetch_assoc() : null;
$supplementary_fee = $fee_settings['supplementary_exam_fee'] ?? 50000;
$deferred_fee = $fee_settings['deferred_exam_fee'] ?? 50000;

// Get all active students for dropdown
$students_query = "SELECT s.student_id, s.full_name, d.department_code as program_code 
                   FROM students s 
                   LEFT JOIN departments d ON s.department = d.department_id 
                   WHERE s.is_active = TRUE 
                   ORDER BY s.full_name";
$students = $conn->query($students_query);

// Get students with outstanding exam fees
$outstanding_query = "SELECT pt.*, s.full_name, s.email, d.department_code
                      FROM payment_transactions pt
                      JOIN students s ON pt.student_id = s.student_id
                      LEFT JOIN departments d ON s.department = d.department_id
                      WHERE pt.payment_type IN ('supplementary_exam', 'deferred_exam') 
                      AND pt.amount < 0
                      ORDER BY pt.created_at DESC";
$outstanding_fees = $conn->query($outstanding_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'charge_fee') {
        $student_id = $_POST['student_id'] ?? '';
        $fee_type = $_POST['fee_type'] ?? '';
        $module_name = $_POST['module_name'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Determine fee amount
        $amount = ($fee_type === 'supplementary_exam') ? $supplementary_fee : $deferred_fee;
        $fee_label = ($fee_type === 'supplementary_exam') ? 'Supplementary Exam' : 'Deferred Exam';
        
        if (empty($student_id)) {
            $error_message = "Please select a student.";
        } elseif (empty($fee_type)) {
            $error_message = "Please select a fee type.";
        } elseif (empty($module_name)) {
            $error_message = "Please enter the module/course name.";
        } else {
            $conn->begin_transaction();
            try {
                // Record the charge as a negative transaction (amount owed)
                $notes_full = $fee_label . " Fee for: " . $module_name . ($notes ? " | " . $notes : "");
                $recorded_by = $user['display_name'];
                $payment_date = date('Y-m-d');
                
                // First, record the charge (fee owed)
                $stmt = $conn->prepare("INSERT INTO payment_transactions 
                    (student_id, amount, payment_type, payment_method, reference_number, payment_date, notes, recorded_by) 
                    VALUES (?, ?, ?, 'exam_fee_charge', ?, ?, ?, ?)");
                $negative_amount = -$amount; // Negative to show as charge/debt
                $ref_number = strtoupper($fee_type) . '-' . date('Ymd') . '-' . rand(1000, 9999);
                $stmt->bind_param("sdsssss", $student_id, $negative_amount, $fee_type, $ref_number, $payment_date, $notes_full, $recorded_by);
                $stmt->execute();
                
                // Update student_finances - increase expected_total and balance
                $update_stmt = $conn->prepare("UPDATE student_finances 
                    SET expected_total = expected_total + ?,
                        balance = expected_total + ? - total_paid,
                        payment_percentage = ROUND((total_paid / (expected_total + ?)) * 100)
                    WHERE student_id = ?");
                $update_stmt->bind_param("ddds", $amount, $amount, $amount, $student_id);
                $update_stmt->execute();
                
                $conn->commit();
                $success_message = "$fee_label fee of K" . number_format($amount) . " has been charged to student $student_id for module: $module_name";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error charging fee: " . $e->getMessage();
            }
        }
    } elseif ($action === 'record_payment') {
        $student_id = $_POST['student_id'] ?? '';
        $fee_type = $_POST['fee_type'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? '';
        $reference_number = $_POST['reference_number'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        $fee_label = ($fee_type === 'supplementary_exam') ? 'Supplementary Exam' : 'Deferred Exam';
        
        if (empty($student_id) || $amount <= 0 || empty($payment_method)) {
            $error_message = "Please fill all required fields.";
        } else {
            $conn->begin_transaction();
            try {
                // Record the payment
                $notes_full = $fee_label . " Fee Payment" . ($notes ? " | " . $notes : "");
                $recorded_by = $user['display_name'];
                $payment_date = date('Y-m-d');
                
                $stmt = $conn->prepare("INSERT INTO payment_transactions 
                    (student_id, amount, payment_type, payment_method, reference_number, payment_date, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sdssssss", $student_id, $amount, $fee_type, $payment_method, $reference_number, $payment_date, $notes_full, $recorded_by);
                $stmt->execute();
                
                $transaction_id = $conn->insert_id;
                
                // Update student_finances
                $update_stmt = $conn->prepare("UPDATE student_finances 
                    SET total_paid = total_paid + ?,
                        balance = expected_total - (total_paid + ?),
                        payment_percentage = ROUND(((total_paid + ?) / expected_total) * 100),
                        last_payment_date = ?
                    WHERE student_id = ?");
                $update_stmt->bind_param("dddss", $amount, $amount, $amount, $payment_date, $student_id);
                $update_stmt->execute();
                
                $conn->commit();
                
                // Redirect to receipt
                header("Location: payment_receipt.php?id=" . $transaction_id . "&type=transaction&new=1");
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error recording payment: " . $e->getMessage();
            }
        }
    }
}

// Get recent exam fee transactions
$recent_query = "SELECT pt.*, s.full_name
                 FROM payment_transactions pt
                 JOIN students s ON pt.student_id = s.student_id
                 WHERE pt.payment_type IN ('supplementary_exam', 'deferred_exam')
                 ORDER BY pt.created_at DESC
                 LIMIT 20";
$recent_transactions = $conn->query($recent_query);

// Get outstanding exam fee totals
$outstanding_totals = $conn->query("SELECT 
    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_charged,
    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_paid,
    SUM(amount) as net_balance
    FROM payment_transactions 
    WHERE payment_type IN ('supplementary_exam', 'deferred_exam')")->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Fees - VLE Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .fee-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .fee-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .fee-type-supp { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; }
        .fee-type-def { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); border-left: 4px solid #8b5cf6; }
        .stat-card { border-radius: 10px; }
        .nav-pills .nav-link { border-radius: 8px; }
        .nav-pills .nav-link.active { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); }
    </style>
</head>
<body class="bg-light">
    <?php 
    $currentPage = 'exam_fees';
    $pageTitle = 'Exam Fees';
    include 'header_nav.php'; 
    ?>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2 class="fw-bold mb-1"><i class="bi bi-journal-x me-2"></i>Examination Fees</h2>
                        <p class="text-muted mb-0">Charge and manage supplementary & deferred exam fees</p>
                    </div>
                    <div>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card stat-card border-0 shadow-sm" style="background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);">
                    <div class="card-body text-center">
                        <i class="bi bi-receipt fs-2 mb-2" style="color: #dc2626;"></i>
                        <h3 class="fw-bold mb-0" style="color: #7f1d1d;">K<?php echo number_format(abs($outstanding_totals['total_charged'] ?? 0)); ?></h3>
                        <small style="color: #dc2626;">Total Charged</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card border-0 shadow-sm" style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);">
                    <div class="card-body text-center">
                        <i class="bi bi-cash-stack fs-2 mb-2" style="color: #10b981;"></i>
                        <h3 class="fw-bold mb-0" style="color: #065f46;">K<?php echo number_format($outstanding_totals['total_paid'] ?? 0); ?></h3>
                        <small style="color: #10b981;">Total Collected</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card border-0 shadow-sm" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
                    <div class="card-body text-center">
                        <i class="bi bi-hourglass-split fs-2 mb-2" style="color: #f59e0b;"></i>
                        <h3 class="fw-bold mb-0" style="color: #b45309;">K<?php echo number_format(abs($outstanding_totals['net_balance'] ?? 0)); ?></h3>
                        <small style="color: #f59e0b;">Outstanding Balance</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Type Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card fee-card fee-type-supp">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-1" style="color: #b45309;">
                                    <i class="bi bi-journal-plus me-2"></i>Supplementary Exam Fee
                                </h5>
                                <p class="mb-0" style="color: #92400e;">For students retaking failed exams</p>
                            </div>
                            <div class="text-end">
                                <h3 class="fw-bold mb-0" style="color: #b45309;">K<?php echo number_format($supplementary_fee); ?></h3>
                                <small style="color: #92400e;">Per module</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card fee-card fee-type-def">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-1" style="color: #5b21b6;">
                                    <i class="bi bi-calendar-x me-2"></i>Deferred Exam Fee
                                </h5>
                                <p class="mb-0" style="color: #6b21a8;">For students with approved deferrals</p>
                            </div>
                            <div class="text-end">
                                <h3 class="fw-bold mb-0" style="color: #5b21b6;">K<?php echo number_format($deferred_fee); ?></h3>
                                <small style="color: #6b21a8;">Per module</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-pills mb-4" id="examFeeTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="charge-tab" data-bs-toggle="pill" data-bs-target="#charge" type="button">
                    <i class="bi bi-plus-circle me-1"></i> Charge Fee
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payment-tab" data-bs-toggle="pill" data-bs-target="#payment" type="button">
                    <i class="bi bi-cash me-1"></i> Record Payment
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="pill" data-bs-target="#history" type="button">
                    <i class="bi bi-clock-history me-1"></i> Transaction History
                </button>
            </li>
        </ul>

        <div class="tab-content" id="examFeeTabsContent">
            <!-- Charge Fee Tab -->
            <div class="tab-pane fade show active" id="charge" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Charge Exam Fee to Student</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="charge_fee">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Select Student *</label>
                                    <select name="student_id" class="form-select" required>
                                        <option value="">-- Choose Student --</option>
                                        <?php 
                                        $students->data_seek(0);
                                        while ($s = $students->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $s['student_id']; ?>">
                                                <?php echo htmlspecialchars($s['student_id'] . ' - ' . $s['full_name'] . ' (' . ($s['program_code'] ?? 'N/A') . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Fee Type *</label>
                                    <select name="fee_type" class="form-select" required id="feeTypeSelect">
                                        <option value="">-- Select Fee Type --</option>
                                        <option value="supplementary_exam">Supplementary Exam (K<?php echo number_format($supplementary_fee); ?>)</option>
                                        <option value="deferred_exam">Deferred Exam (K<?php echo number_format($deferred_fee); ?>)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Module/Course Name *</label>
                                    <input type="text" name="module_name" class="form-control" placeholder="e.g., ICT101 - Introduction to Computing" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Fee Amount</label>
                                    <input type="text" class="form-control bg-light" id="feeAmountDisplay" readonly value="Select fee type">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Additional Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning btn-lg">
                                        <i class="bi bi-plus-circle me-2"></i>Charge Fee to Student
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Record Payment Tab -->
            <div class="tab-pane fade" id="payment" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-cash me-2"></i>Record Exam Fee Payment</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="record_payment">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Select Student *</label>
                                    <select name="student_id" class="form-select" required>
                                        <option value="">-- Choose Student --</option>
                                        <?php 
                                        $students->data_seek(0);
                                        while ($s = $students->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $s['student_id']; ?>">
                                                <?php echo htmlspecialchars($s['student_id'] . ' - ' . $s['full_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Fee Type *</label>
                                    <select name="fee_type" class="form-select" required>
                                        <option value="">-- Select Fee Type --</option>
                                        <option value="supplementary_exam">Supplementary Exam</option>
                                        <option value="deferred_exam">Deferred Exam</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Amount (K) *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Payment Method *</label>
                                    <select name="payment_method" class="form-select" required>
                                        <option value="">-- Select --</option>
                                        <option value="cash">Cash</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Reference Number</label>
                                    <input type="text" name="reference_number" class="form-control" placeholder="Transaction reference">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-check-circle me-2"></i>Record Payment & Print Receipt
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Transaction History Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Exam Fee Transactions</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th class="text-end">Charge</th>
                                        <th class="text-end">Payment</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_transactions && $recent_transactions->num_rows > 0): ?>
                                        <?php while ($trans = $recent_transactions->fetch_assoc()): ?>
                                            <tr>
                                                <td><small><?php echo date('M d, Y', strtotime($trans['payment_date'])); ?></small></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($trans['full_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($trans['student_id']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $trans['payment_type'] === 'supplementary_exam' ? 'bg-warning text-dark' : 'bg-purple text-white'; ?>" style="<?php echo $trans['payment_type'] === 'deferred_exam' ? 'background: #8b5cf6;' : ''; ?>">
                                                        <?php echo $trans['payment_type'] === 'supplementary_exam' ? 'Supplementary' : 'Deferred'; ?>
                                                    </span>
                                                </td>
                                                <td><small><?php echo htmlspecialchars($trans['notes']); ?></small></td>
                                                <td class="text-end">
                                                    <?php if ($trans['amount'] < 0): ?>
                                                        <span class="text-danger fw-bold">K<?php echo number_format(abs($trans['amount'])); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($trans['amount'] > 0): ?>
                                                        <span class="text-success fw-bold">K<?php echo number_format($trans['amount']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($trans['amount'] > 0): ?>
                                                        <a href="payment_receipt.php?id=<?php echo $trans['transaction_id']; ?>&type=transaction" 
                                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No exam fee transactions yet
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update fee amount display based on selection
        document.getElementById('feeTypeSelect').addEventListener('change', function() {
            const display = document.getElementById('feeAmountDisplay');
            if (this.value === 'supplementary_exam') {
                display.value = 'K<?php echo number_format($supplementary_fee); ?>';
            } else if (this.value === 'deferred_exam') {
                display.value = 'K<?php echo number_format($deferred_fee); ?>';
            } else {
                display.value = 'Select fee type';
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
