<?php
// submit_payment.php - Student payment submission
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get student info
$stmt = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get student's financial summary
$finance_query = "SELECT * FROM student_finances WHERE student_id = ?";
$stmt = $conn->prepare($finance_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$finance_data = $stmt->get_result()->fetch_assoc();

// Get pending submissions
$pending_query = "SELECT * FROM payment_submissions WHERE student_id = ? ORDER BY submission_date DESC";
$stmt = $conn->prepare($pending_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $amount = floatval($_POST['amount']);
    $payment_reference = trim($_POST['payment_reference']);
    $transaction_date = trim($_POST['transaction_date']);
    $transaction_type = trim($_POST['transaction_type'] ?? 'Bank Deposit');
    $bank_name = trim($_POST['bank_name'] ?? '');
    if ($bank_name === 'other' && !empty($_POST['bank_name_custom'])) {
        $bank_name = trim($_POST['bank_name_custom']);
    }
    $notes = trim($_POST['notes'] ?? '');
    
    // Handle file upload
    $proof_filename = null;
    if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/payments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($file_ext, $allowed_exts)) {
            if ($_FILES['proof_of_payment']['size'] <= 5242880) { // 5MB max
                // Sanitize student_id for filename (replace slashes with underscores)
                $safe_student_id = str_replace('/', '_', $student_id);
                $proof_filename = 'payment_' . $safe_student_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $proof_filename;
                
                if (!move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $target_path)) {
                    $error = "Failed to upload proof of payment.";
                    $proof_filename = null;
                }
            } else {
                $error = "File size exceeds 5MB limit.";
            }
        } else {
            $error = "Invalid file format. Only JPG, PNG, and PDF allowed.";
        }
    }
    
    if (!isset($error)) {
        try {
            $stmt = $conn->prepare("INSERT INTO payment_submissions (student_id, amount, payment_reference, transaction_date, proof_of_payment, transaction_type, bank_name, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sdssssss", $student_id, $amount, $payment_reference, $transaction_date, $proof_filename, $transaction_type, $bank_name, $notes);
            $stmt->execute();
            
            $success = "Payment submission successful! Your payment is pending approval by the finance officer.";
            
            // Refresh submissions
            $pending_query = "SELECT * FROM payment_submissions WHERE student_id = ? ORDER BY submission_date DESC";
            $stmt = $conn->prepare($pending_query);
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            $error = "Failed to submit payment: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Payment - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-cash-coin"></i> Submit Payment</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Payment Summary -->
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-wallet2"></i> Your Balance</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($finance_data): ?>
                            <div class="mb-3">
                                <label class="text-muted small">Total Expected</label>
                                <h4>K<?php echo number_format($finance_data['expected_total'] ?? 0); ?></h4>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Total Paid</label>
                                <h4 class="text-success">K<?php echo number_format($finance_data['total_paid'] ?? 0); ?></h4>
                            </div>
                            <div>
                                <label class="text-muted small">Outstanding Balance</label>
                                <h3 class="text-danger">K<?php echo number_format($finance_data['balance'] ?? 0); ?></h3>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No financial data available</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-warning">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Instructions</h6>
                    </div>
                    <div class="card-body">
                        <ol class="small mb-0">
                            <li>Make payment to the university account</li>
                            <li>Upload proof of payment (receipt/screenshot)</li>
                            <li>Enter payment details accurately</li>
                            <li>Wait for finance officer approval</li>
                            <li>Your balance will update once approved</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Payment Submission Form -->
            <div class="col-md-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-upload"></i> Submit Payment Proof</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Amount Paid (MWK) *</label>
                                    <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Transaction Date *</label>
                                    <input type="date" class="form-control" name="transaction_date" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Type of Transaction *</label>
                                    <select class="form-select" name="transaction_type" required>
                                        <option value="Bank Deposit">Bank Deposit</option>
                                        <option value="Electronic Transfer">Electronic Transfer</option>
                                        <option value="Mobile Money">Mobile Money</option>
                                        <option value="Cash Payment">Cash Payment</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Name *</label>
                                    <select class="form-select" id="bank_name" name="bank_name" required onchange="toggleCustomBank()">
                                        <option value="">Select Bank</option>
                                        <option value="National Bank of Malawi">National Bank of Malawi</option>
                                        <option value="FDH Bank">FDH Bank</option>
                                        <option value="Standard Bank">Standard Bank</option>
                                        <option value="NBS Bank">NBS Bank</option>
                                        <option value="Ecobank">Ecobank</option>
                                        <option value="Airtel Money">Airtel Money</option>
                                        <option value="TNM Mpamba">TNM Mpamba</option>
                                        <option value="other">Other (Specify)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3" id="custom_bank_field" style="display: none;">
                                <label class="form-label">Specify Bank Name *</label>
                                <input type="text" class="form-control" id="bank_name_custom" name="bank_name_custom" placeholder="Enter bank name">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Payment Reference/Transaction ID *</label>
                                <input type="text" class="form-control" name="payment_reference" placeholder="e.g., TXN123456789" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Proof of Payment (Receipt/Screenshot) *</label>
                                <input type="file" class="form-control" name="proof_of_payment" accept=".jpg,.jpeg,.png,.pdf" required>
                                <div class="form-text">Accepted: JPG, PNG, PDF (Max 5MB)</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Additional Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Any additional information about this payment..."></textarea>
                            </div>

                            <button type="submit" name="submit_payment" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-send-fill"></i> Submit for Approval
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Submission History -->
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Submission History</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submissions)): ?>
                            <p class="text-muted">No payment submissions yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date Submitted</th>
                                            <th>Amount</th>
                                            <th>Type</th>
                                            <th>Bank</th>
                                            <th>Reference</th>
                                            <th>Status</th>
                                            <th>Proof</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $sub): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($sub['submission_date'])); ?></td>
                                                <td>K<?php echo number_format($sub['amount']); ?></td>
                                                <td><small><?php echo htmlspecialchars($sub['transaction_type'] ?? 'N/A'); ?></small></td>
                                                <td><small><?php echo htmlspecialchars($sub['bank_name'] ?? 'N/A'); ?></small></td>
                                                <td><?php echo htmlspecialchars($sub['payment_reference']); ?></td>
                                                <td>
                                                    <?php if ($sub['status'] == 'pending'): ?>
                                                        <span class="badge bg-warning">Pending Review</span>
                                                    <?php elseif ($sub['status'] == 'approved'): ?>
                                                        <span class="badge bg-success">Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Rejected</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($sub['proof_of_payment']): ?>
                                                        <a href="../uploads/payments/<?php echo $sub['proof_of_payment']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-file-earmark-image"></i> View
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleCustomBank() {
            const bankSelect = document.getElementById('bank_name');
            const customField = document.getElementById('custom_bank_field');
            const customInput = document.getElementById('bank_name_custom');
            
            if (bankSelect.value === 'other') {
                customField.style.display = 'block';
                customInput.required = true;
            } else {
                customField.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
            }
        }
    </script>
</body>
</html>
