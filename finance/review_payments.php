<?php
// review_payments.php - Finance officer payment review and approval
require_once '../includes/auth.php';
require_once '../includes/email.php';
require_once '../includes/notifications.php';
requireLogin();
requireRole(['finance', 'staff']);


$conn = getDbConnection();
$user_id = $_SESSION['vle_user_id'];

// Handle admin upload of missing proof file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_upload_proof'])) {
    $submission_id = (int)$_POST['submission_id'];
    if (isset($_FILES['admin_proof_file']) && $_FILES['admin_proof_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/payments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['admin_proof_file']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($file_ext, $allowed_exts)) {
            if ($_FILES['admin_proof_file']['size'] <= 5242880) { // 5MB max
                $proof_filename = 'admin_upload_' . $submission_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $proof_filename;
                if (move_uploaded_file($_FILES['admin_proof_file']['tmp_name'], $target_path)) {
                    // Update DB
                    $stmt = $conn->prepare("UPDATE payment_submissions SET proof_of_payment = ? WHERE submission_id = ?");
                    $stmt->bind_param("si", $proof_filename, $submission_id);
                    if ($stmt->execute()) {
                        $success = "Proof of payment uploaded successfully.";
                    } else {
                        $error = "Failed to update proof in database.";
                    }
                } else {
                    $error = "Failed to upload file.";
                }
            } else {
                $error = "File size exceeds 5MB limit.";
            }
        } else {
            $error = "Invalid file format. Only JPG, PNG, and PDF allowed.";
        }
    } else {
        $error = "No file selected or upload error.";
    }
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_payment'])) {
    $submission_id = (int)$_POST['submission_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $notes = trim($_POST['review_notes'] ?? '');
    $bank_deposited_to = trim($_POST['bank_deposited_to'] ?? '');
    
    if ($action === 'approve') {
        try {
            $conn->begin_transaction();
            
            // Get submission details
            $stmt = $conn->prepare("SELECT * FROM payment_submissions WHERE submission_id = ?");
            $stmt->bind_param("i", $submission_id);
            $stmt->execute();
            $submission = $stmt->get_result()->fetch_assoc();
            
            if ($submission && $submission['status'] === 'pending') {
                // Check if student_finances record exists, if not create it
                $check_finance = $conn->prepare("SELECT student_id FROM student_finances WHERE student_id = ?");
                $check_finance->bind_param("s", $submission['student_id']);
                $check_finance->execute();
                $finance_exists = $check_finance->get_result();
                
                if ($finance_exists->num_rows == 0) {
                    // Create new finance record with default expected_total of 539500
                    $create_finance = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, 539500, 0, 539500, 0, 0)");
                    $create_finance->bind_param("s", $submission['student_id']);
                    $create_finance->execute();
                }
                
                // Record payment in payment_transactions table
                $bank_info = !empty($bank_deposited_to) ? $bank_deposited_to : ($submission['bank_name'] ?? 'N/A');
                $payment_notes = $submission['transaction_type'] . ' via ' . $bank_info . ' - Ref: ' . $submission['payment_reference'];
                if (!empty($notes)) {
                    $payment_notes .= ' | Notes: ' . $notes;
                }
                $stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, amount, payment_type, payment_date, notes, recorded_by) VALUES (?, ?, 'payment', ?, ?, ?)");
                $stmt->bind_param("sdsss", $submission['student_id'], $submission['amount'], $submission['transaction_date'], $payment_notes, $user_id);
                $stmt->execute();
                $finance_id = $conn->insert_id;
                
                // Update student_finances - add to total_paid and recalculate balance
                $update_finance = "UPDATE student_finances 
                                  SET total_paid = total_paid + ?,
                                      balance = expected_total - (total_paid + ?),
                                      payment_percentage = ROUND(((total_paid + ?) / expected_total) * 100),
                                      content_access_weeks = CASE 
                                          WHEN ((total_paid + ?) / expected_total) >= 1.0 THEN 52
                                          WHEN ((total_paid + ?) / expected_total) >= 0.75 THEN 13
                                          WHEN ((total_paid + ?) / expected_total) >= 0.50 THEN 9
                                          WHEN ((total_paid + ?) / expected_total) >= 0.25 THEN 4
                                          ELSE 0
                                      END
                                  WHERE student_id = ?";
                $stmt = $conn->prepare($update_finance);
                $amt = $submission['amount'];
                $stmt->bind_param("ddddddds", $amt, $amt, $amt, $amt, $amt, $amt, $amt, $submission['student_id']);
                $stmt->execute();
                
                // Update submission status and bank deposited to
                $review_notes_combined = $notes;
                if (!empty($bank_deposited_to)) {
                    $review_notes_combined = 'EU Bank Acc At: ' . $bank_deposited_to . (!empty($notes) ? ' | ' . $notes : '');
                }
                $stmt = $conn->prepare("UPDATE payment_submissions SET status = 'approved', reviewed_by = ?, reviewed_date = NOW(), finance_id = ?, notes = ?, bank_name = COALESCE(?, bank_name) WHERE submission_id = ?");
                $stmt->bind_param("sissi", $user_id, $finance_id, $review_notes_combined, $bank_deposited_to, $submission_id);
                $stmt->execute();
                
                $conn->commit();
                
                // Send payment confirmation email + in-app notification
                // Get student info
                $student_stmt = $conn->prepare("SELECT s.full_name, s.email, u.user_id, sf.balance, sf.payment_percentage 
                    FROM students s 
                    LEFT JOIN student_finances sf ON s.student_id = sf.student_id 
                    LEFT JOIN users u ON u.related_student_id = s.student_id 
                    WHERE s.student_id = ?");
                $student_stmt->bind_param("s", $submission['student_id']);
                $student_stmt->execute();
                $student_info = $student_stmt->get_result()->fetch_assoc();
                
                // Create in-app notification for the student
                if ($student_info && $student_info['user_id']) {
                    createNotification(
                        (int)$student_info['user_id'],
                        'finance',
                        'Payment Approved - K' . number_format($submission['amount'], 2),
                        'Your payment of K' . number_format($submission['amount'], 2) . ' (Ref: ' . $submission['payment_reference'] . ') has been approved. Your new balance is K' . number_format(max(0, $student_info['balance']), 2) . '.',
                        '../finance/payment_receipt.php?id=' . $submission_id,
                        (string)$submission_id,
                        'payment'
                    );
                }
                
                // Send email with receipt link
                if (isEmailEnabled() && $student_info && $student_info['email']) {
                    sendPaymentApprovedWithReceiptEmail(
                        $student_info['email'],
                        $student_info['full_name'],
                        $submission['amount'],
                        $submission['transaction_type'] . ' (' . $submission['bank_name'] . ')',
                        $submission['payment_reference'],
                        max(0, $student_info['balance']),
                        $student_info['payment_percentage'] ?? 0,
                        $submission_id
                    );
                }
                
                $success = "Payment approved and recorded successfully! <a href='payment_receipt.php?id=$submission_id' target='_blank' class='btn btn-sm btn-success ms-2'><i class='bi bi-printer'></i> Print Receipt</a>";
            } else {
                $conn->rollback();
                $error = "Invalid submission or already processed.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to approve payment: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE payment_submissions SET status = 'rejected', reviewed_by = ?, reviewed_date = NOW(), notes = ? WHERE submission_id = ?");
        $stmt->bind_param("ssi", $user_id, $notes, $submission_id);
        if ($stmt->execute()) {
            $success = "Payment submission rejected.";
        } else {
            $error = "Failed to reject payment.";
        }
    }
}

// Handle lecturer claim payment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lecturer_payment_action'])) {
    $request_id = (int)$_POST['request_id'];
    $lect_action = $_POST['lect_action']; // 'pay' or 'reject' or 'delete'
    $lect_notes = trim($_POST['lect_notes'] ?? '');
    
    if ($lect_action === 'pay') {
        $stmt = $conn->prepare("UPDATE lecturer_finance_requests SET status = 'paid', finance_paid_at = NOW() WHERE request_id = ? AND status = 'approved'");
        $stmt->bind_param("i", $request_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = "Lecturer payment processed successfully! <a href='print_lecturer_payment.php?id=$request_id' target='_blank' class='btn btn-sm btn-success ms-2'><i class='bi bi-printer'></i> Print Receipt</a>";
        } else {
            $error = "Failed to process lecturer payment. Claim must be approved first.";
        }
    } elseif ($lect_action === 'reject') {
        $stmt = $conn->prepare("UPDATE lecturer_finance_requests SET status = 'rejected', finance_remarks = ?, finance_rejected_at = NOW() WHERE request_id = ?");
        $stmt->bind_param("si", $lect_notes, $request_id);
        if ($stmt->execute()) {
            $success = "Lecturer claim rejected.";
        } else {
            $error = "Failed to reject lecturer claim.";
        }
    } elseif ($lect_action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM lecturer_finance_requests WHERE request_id = ? AND status IN ('pending', 'rejected')");
        $stmt->bind_param("i", $request_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = "Lecturer claim deleted successfully.";
        } else {
            $error = "Failed to delete claim. Only pending or rejected claims can be deleted.";
        }
    }
}

// Get pending submissions
$pending_query = "SELECT ps.*, s.full_name, s.email, sf.balance 
                  FROM payment_submissions ps
                  JOIN students s ON ps.student_id = s.student_id
                  LEFT JOIN student_finances sf ON ps.student_id = sf.student_id
                  WHERE ps.status = 'pending'
                  ORDER BY ps.submission_date ASC";
$pending_submissions = $conn->query($pending_query)->fetch_all(MYSQLI_ASSOC);

// Get reviewed submissions
$reviewed_query = "SELECT ps.*, s.full_name, s.email 
                   FROM payment_submissions ps
                   JOIN students s ON ps.student_id = s.student_id
                   WHERE ps.status IN ('approved', 'rejected')
                   ORDER BY ps.reviewed_date DESC
                   LIMIT 50";
$reviewed_submissions = $conn->query($reviewed_query)->fetch_all(MYSQLI_ASSOC);

// Get lecturer claims ready for payment (approved by finance)
$lect_approved_query = "SELECT r.*, l.full_name AS lecturer_name, l.email AS lecturer_email, l.department, l.phone, l.position
                        FROM lecturer_finance_requests r
                        JOIN lecturers l ON r.lecturer_id = l.lecturer_id
                        WHERE r.status = 'approved'
                        ORDER BY r.submission_date ASC";
$lect_approved_result = $conn->query($lect_approved_query);
$lect_approved_claims = $lect_approved_result ? $lect_approved_result->fetch_all(MYSQLI_ASSOC) : [];

// Get lecturer claims pending review (all non-paid, non-approved for overview)
$lect_pending_query = "SELECT r.*, l.full_name AS lecturer_name, l.email AS lecturer_email, l.department, l.phone, l.position
                       FROM lecturer_finance_requests r
                       JOIN lecturers l ON r.lecturer_id = l.lecturer_id
                       WHERE r.status = 'pending'
                       ORDER BY r.submission_date ASC";
$lect_pending_result = $conn->query($lect_pending_query);
$lect_pending_claims = $lect_pending_result ? $lect_pending_result->fetch_all(MYSQLI_ASSOC) : [];

// Get recently paid lecturer claims
$lect_paid_query = "SELECT r.*, l.full_name AS lecturer_name, l.email AS lecturer_email, l.department
                    FROM lecturer_finance_requests r
                    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
                    WHERE r.status = 'paid'
                    ORDER BY r.finance_paid_at DESC
                    LIMIT 50";
$lect_paid_result = $conn->query($lect_paid_query);
$lect_paid_claims = $lect_paid_result ? $lect_paid_result->fetch_all(MYSQLI_ASSOC) : [];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Payment Submissions - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'review_payments';
    $pageTitle = 'Review Payment Submissions';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-check2-square me-2"></i>Review Payment Submissions</h1>
            <p class="text-muted mb-0">Approve or reject student and lecturer payment submissions</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="paymentTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="student-tab" data-bs-toggle="tab" data-bs-target="#studentPayments" type="button" role="tab">
                    <i class="bi bi-mortarboard me-1"></i>Student Payments 
                    <span class="badge bg-warning text-dark"><?php echo count($pending_submissions); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="lecturer-tab" data-bs-toggle="tab" data-bs-target="#lecturerPayments" type="button" role="tab">
                    <i class="bi bi-person-workspace me-1"></i>Lecturer Payments
                    <span class="badge bg-success"><?php echo count($lect_approved_claims); ?></span>
                    <?php if (count($lect_pending_claims) > 0): ?>
                    <span class="badge bg-warning text-dark"><?php echo count($lect_pending_claims); ?> pending</span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="paymentTabsContent">
        <!-- Student Payments Tab -->
        <div class="tab-pane fade show active" id="studentPayments" role="tabpanel">

        <!-- Pending Submissions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="bi bi-hourglass-split"></i> Pending Approvals 
                    <span class="badge bg-dark"><?php echo count($pending_submissions); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($pending_submissions)): ?>
                    <p class="text-muted">No pending payment submissions.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Submitted</th>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Type</th>
                                    <th>Bank</th>
                                    <th>Reference</th>
                                    <th>Trans. Date</th>
                                    <th>Balance</th>
                                    <th>Proof</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_submissions as $sub): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i', strtotime($sub['submission_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($sub['full_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($sub['student_id']); ?></small>
                                        </td>
                                        <td><strong class="text-success">K<?php echo number_format($sub['amount']); ?></strong></td>
                                        <td><small><?php echo htmlspecialchars($sub['transaction_type'] ?? 'N/A'); ?></small></td>
                                        <td><small><?php echo htmlspecialchars($sub['bank_name'] ?? 'N/A'); ?></small></td>
                                        <td><?php echo htmlspecialchars($sub['payment_reference']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($sub['transaction_date'])); ?></td>
                                        <td><span class="text-danger">K<?php echo number_format($sub['balance'] ?? 0); ?></span></td>
                                        <td>
                                            <?php if ($sub['proof_of_payment']): ?>
                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#proofModal<?php echo $sub['submission_id']; ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#uploadProofModal<?php echo $sub['submission_id']; ?>">
                                                    <i class="bi bi-upload"></i> Upload
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                                                            <!-- Admin Upload Proof Modal -->
                                                                            <div class="modal fade" id="uploadProofModal<?php echo $sub['submission_id']; ?>" tabindex="-1">
                                                                                <div class="modal-dialog">
                                                                                    <div class="modal-content">
                                                                                        <div class="modal-header bg-warning">
                                                                                            <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Missing Proof</h5>
                                                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                                        </div>
                                                                                        <form method="POST" enctype="multipart/form-data">
                                                                                            <div class="modal-body">
                                                                                                <input type="hidden" name="submission_id" value="<?php echo $sub['submission_id']; ?>">
                                                                                                <div class="mb-3">
                                                                                                    <label class="form-label">Select Proof File (JPG, PNG, PDF, max 5MB)</label>
                                                                                                    <input type="file" class="form-control" name="admin_proof_file" accept=".jpg,.jpeg,.png,.pdf" required>
                                                                                                </div>
                                                                                            </div>
                                                                                            <div class="modal-footer">
                                                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                                                <button type="submit" name="admin_upload_proof" class="btn btn-warning">
                                                                                                    <i class="bi bi-upload"></i> Upload
                                                                                                </button>
                                                                                            </div>
                                                                                        </form>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $sub['submission_id']; ?>" data-action="approve">
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $sub['submission_id']; ?>" data-action="reject">
                                                <i class="bi bi-x-circle"></i> Reject
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Proof Modal -->
                                    <div class="modal fade" id="proofModal<?php echo $sub['submission_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Proof of Payment</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <?php
                                                    $file_ext = strtolower(pathinfo($sub['proof_of_payment'], PATHINFO_EXTENSION));
                                                    if ($file_ext === 'pdf'):
                                                    ?>
                                                        <iframe src="../uploads/payments/<?php echo $sub['proof_of_payment']; ?>" width="100%" height="600px"></iframe>
                                                    <?php else: ?>
                                                        <img src="../uploads/payments/<?php echo $sub['proof_of_payment']; ?>" class="img-fluid" alt="Proof of Payment">
                                                    <?php endif; ?>
                                                    <div class="mt-3">
                                                        <a href="../uploads/payments/<?php echo $sub['proof_of_payment']; ?>" download class="btn btn-primary">
                                                            <i class="bi bi-download"></i> Download
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Review Modal -->
                                    <div class="modal fade" id="reviewModal<?php echo $sub['submission_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">Review Payment</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="submission_id" value="<?php echo $sub['submission_id']; ?>">
                                                        <input type="hidden" name="action" id="action<?php echo $sub['submission_id']; ?>" value="approve">
                                                        
                                                        <div class="alert alert-info">
                                                            <strong>Student:</strong> <?php echo htmlspecialchars($sub['full_name']); ?> (<?php echo $sub['student_id']; ?>)<br>
                                                            <strong>Amount:</strong> K<?php echo number_format($sub['amount']); ?><br>
                                                            <strong>Type:</strong> <?php echo htmlspecialchars($sub['transaction_type'] ?? 'N/A'); ?><br>
                                                            <strong>Bank:</strong> <?php echo htmlspecialchars($sub['bank_name'] ?? 'N/A'); ?><br>
                                                            <strong>Reference:</strong> <?php echo htmlspecialchars($sub['payment_reference']); ?><br>
                                                            <strong>Trans. Date:</strong> <?php echo date('M d, Y', strtotime($sub['transaction_date'])); ?><br>
                                                            <strong>Current Balance:</strong> K<?php echo number_format($sub['balance'] ?? 0); ?>
                                                        </div>

                                                        <div class="mb-3 bank-deposited-field" id="bankField<?php echo $sub['submission_id']; ?>">
                                                            <label class="form-label fw-bold"><i class="bi bi-bank me-1"></i>EU BANK ACC AT <span class="text-danger">*</span></label>
                                                            <select class="form-select" name="bank_deposited_to" id="bankSelect<?php echo $sub['submission_id']; ?>">
                                                                <option value="">-- Select Bank --</option>
                                                                <option value="National Bank of Malawi" <?= ($sub['bank_name'] ?? '') === 'National Bank of Malawi' ? 'selected' : '' ?>>National Bank of Malawi</option>
                                                                <option value="Standard Bank" <?= ($sub['bank_name'] ?? '') === 'Standard Bank' ? 'selected' : '' ?>>Standard Bank</option>
                                                                <option value="FDH Bank" <?= ($sub['bank_name'] ?? '') === 'FDH Bank' ? 'selected' : '' ?>>FDH Bank</option>
                                                            </select>
                                                            <small class="text-muted">Select the EU bank account the payment was deposited to</small>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label">Review Notes</label>
                                                            <textarea class="form-control" name="review_notes" rows="3" placeholder="Add any notes about this review..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="review_payment" class="btn btn-success" id="approveBtn<?php echo $sub['submission_id']; ?>">
                                                            <i class="bi bi-check-circle"></i> Approve
                                                        </button>
                                                        <button type="submit" name="review_payment" class="btn btn-danger" id="rejectBtn<?php echo $sub['submission_id']; ?>" style="display: none;">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <script>
                                        document.getElementById('reviewModal<?php echo $sub['submission_id']; ?>').addEventListener('show.bs.modal', function (event) {
                                            var button = event.relatedTarget;
                                            var action = button.getAttribute('data-action');
                                            var modal = this;
                                            
                                            var actionInput = modal.querySelector('#action<?php echo $sub['submission_id']; ?>');
                                            var approveBtn = modal.querySelector('#approveBtn<?php echo $sub['submission_id']; ?>');
                                            var rejectBtn = modal.querySelector('#rejectBtn<?php echo $sub['submission_id']; ?>');
                                            var bankField = modal.querySelector('#bankField<?php echo $sub['submission_id']; ?>');
                                            var bankSelect = modal.querySelector('#bankSelect<?php echo $sub['submission_id']; ?>');
                                            
                                            if (action === 'reject') {
                                                actionInput.value = 'reject';
                                                approveBtn.style.display = 'none';
                                                rejectBtn.style.display = 'inline-block';
                                                modal.querySelector('.modal-header').classList.remove('bg-primary');
                                                modal.querySelector('.modal-header').classList.add('bg-danger');
                                                // Hide bank field for rejection
                                                if (bankField) bankField.style.display = 'none';
                                                if (bankSelect) bankSelect.required = false;
                                            } else {
                                                actionInput.value = 'approve';
                                                approveBtn.style.display = 'inline-block';
                                                rejectBtn.style.display = 'none';
                                                modal.querySelector('.modal-header').classList.remove('bg-danger');
                                                modal.querySelector('.modal-header').classList.add('bg-primary');
                                                // Show bank field for approval
                                                if (bankField) bankField.style.display = 'block';
                                                if (bankSelect) bankSelect.required = true;
                                            }
                                        });
                                    </script>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reviewed Submissions -->
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recently Reviewed</h5>
            </div>
            <div class="card-body">
                <?php if (empty($reviewed_submissions)): ?>
                    <p class="text-muted">No reviewed submissions yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Reviewed</th>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Reviewed By</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviewed_submissions as $sub): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($sub['reviewed_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($sub['full_name']); ?></td>
                                        <td>K<?php echo number_format($sub['amount']); ?></td>
                                        <td><?php echo htmlspecialchars($sub['payment_reference']); ?></td>
                                        <td>
                                            <?php if ($sub['status'] == 'approved'): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($sub['reviewed_by']); ?></td>
                                        <td>
                                            <?php if ($sub['status'] == 'approved'): ?>
                                                <a href="payment_receipt.php?id=<?php echo $sub['submission_id']; ?>" 
                                                   class="btn btn-sm btn-primary" target="_blank" title="Print Receipt">
                                                    <i class="bi bi-printer"></i> Receipt
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
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
        </div><!-- End Student Payments Tab -->

        <!-- Lecturer Payments Tab -->
        <div class="tab-pane fade" id="lecturerPayments" role="tabpanel">
        
        <!-- Approved Claims Ready for Payment -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-cash-coin"></i> Ready for Payment 
                    <span class="badge bg-light text-dark"><?php echo count($lect_approved_claims); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($lect_approved_claims)): ?>
                    <p class="text-muted text-center py-3">No lecturer claims ready for payment.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Submitted</th>
                                    <th>Lecturer</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th>Hours</th>
                                    <th>Amount</th>
                                    <th>Workflow</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lect_approved_claims as $lc): ?>
                                <tr>
                                    <td><small><?php echo date('M d, Y', strtotime($lc['submission_date'])); ?></small></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($lc['lecturer_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($lc['lecturer_email']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($lc['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M Y', mktime(0,0,0,$lc['month'],1,$lc['year'])); ?></td>
                                    <td><?php echo number_format($lc['total_hours'], 1); ?></td>
                                    <td><strong class="text-success">K<?php echo number_format($lc['total_amount']); ?></strong></td>
                                    <td>
                                        <small>
                                            <span class="badge bg-success">ODL &#10003;</span>
                                            <?php if (!empty($lc['dean_approval_status'])): ?>
                                            <span class="badge bg-<?php echo $lc['dean_approval_status'] === 'approved' ? 'success' : 'warning'; ?>">
                                                Dean <?php echo $lc['dean_approval_status'] === 'approved' ? '&#10003;' : '&#8987;'; ?>
                                            </span>
                                            <?php endif; ?>
                                            <span class="badge bg-success">Finance &#10003;</span>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="finance_request_pdf.php?id=<?php echo $lc['request_id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="View PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#lectPayModal<?php echo $lc['request_id']; ?>">
                                            <i class="bi bi-cash-coin"></i> Pay
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#lectRejectModal<?php echo $lc['request_id']; ?>">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Pay Lecturer Modal -->
                                <div class="modal fade" id="lectPayModal<?php echo $lc['request_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-success text-white">
                                                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Process Lecturer Payment</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="request_id" value="<?php echo $lc['request_id']; ?>">
                                                    <input type="hidden" name="lect_action" value="pay">
                                                    <div class="alert alert-info">
                                                        <strong>Lecturer:</strong> <?php echo htmlspecialchars($lc['lecturer_name']); ?><br>
                                                        <strong>Department:</strong> <?php echo htmlspecialchars($lc['department'] ?? 'N/A'); ?><br>
                                                        <strong>Period:</strong> <?php echo date('M Y', mktime(0,0,0,$lc['month'],1,$lc['year'])); ?><br>
                                                        <strong>Hours:</strong> <?php echo number_format($lc['total_hours'], 1); ?><br>
                                                        <strong>Amount:</strong> <span class="text-success fw-bold">K<?php echo number_format($lc['total_amount']); ?></span>
                                                    </div>
                                                    <p>Are you sure you want to mark this claim as <strong>paid</strong>?</p>
                                                    <div class="mb-3">
                                                        <label class="form-label">Notes (optional)</label>
                                                        <textarea class="form-control" name="lect_notes" rows="2" placeholder="Payment reference or notes..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="lecturer_payment_action" class="btn btn-success">
                                                        <i class="bi bi-check-circle"></i> Confirm Payment
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Reject Lecturer Modal -->
                                <div class="modal fade" id="lectRejectModal<?php echo $lc['request_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Lecturer Claim</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="request_id" value="<?php echo $lc['request_id']; ?>">
                                                    <input type="hidden" name="lect_action" value="reject">
                                                    <p>Are you sure you want to reject this claim from <strong><?php echo htmlspecialchars($lc['lecturer_name']); ?></strong>?</p>
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason for rejection</label>
                                                        <textarea class="form-control" name="lect_notes" rows="3" placeholder="Enter reason..." required></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="lecturer_payment_action" class="btn btn-danger">
                                                        <i class="bi bi-x-circle"></i> Reject Claim
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Lecturer Claims -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="bi bi-hourglass-split"></i> Pending Lecturer Claims 
                    <span class="badge bg-dark"><?php echo count($lect_pending_claims); ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($lect_pending_claims)): ?>
                    <p class="text-muted text-center py-3">No pending lecturer claims.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Submitted</th>
                                    <th>Lecturer</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th>Hours</th>
                                    <th>Amount</th>
                                    <th>ODL Status</th>
                                    <th>Dean Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lect_pending_claims as $lc): ?>
                                <tr>
                                    <td><small><?php echo date('M d, Y', strtotime($lc['submission_date'])); ?></small></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($lc['lecturer_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($lc['lecturer_email']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($lc['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M Y', mktime(0,0,0,$lc['month'],1,$lc['year'])); ?></td>
                                    <td><?php echo number_format($lc['total_hours'], 1); ?></td>
                                    <td><strong>K<?php echo number_format($lc['total_amount']); ?></strong></td>
                                    <td>
                                        <?php 
                                        $odl_st = $lc['odl_approval_status'] ?? 'pending';
                                        $odl_cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','returned'=>'secondary','forwarded_to_dean'=>'info'][$odl_st] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $odl_cls; ?>"><?php echo ucfirst(str_replace('_',' ',$odl_st)); ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $dean_st = $lc['dean_approval_status'] ?? '';
                                        if ($dean_st) {
                                            $dean_cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$dean_st] ?? 'secondary';
                                            echo '<span class="badge bg-'.$dean_cls.'">'.ucfirst($dean_st).'</span>';
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="finance_request_pdf.php?id=<?php echo $lc['request_id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="View PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this pending claim permanently?');">
                                            <input type="hidden" name="request_id" value="<?php echo $lc['request_id']; ?>">
                                            <input type="hidden" name="lect_action" value="delete">
                                            <button type="submit" name="lecturer_payment_action" class="btn btn-sm btn-outline-danger" title="Delete Claim">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recently Paid Lecturer Claims -->
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recently Paid Lecturers</h5>
            </div>
            <div class="card-body">
                <?php if (empty($lect_paid_claims)): ?>
                    <p class="text-muted text-center py-3">No paid lecturer claims yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Paid Date</th>
                                    <th>Lecturer</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th>Amount</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lect_paid_claims as $lc): ?>
                                <tr>
                                    <td><?php echo $lc['finance_paid_at'] ? date('M d, Y', strtotime($lc['finance_paid_at'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($lc['lecturer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lc['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M Y', mktime(0,0,0,$lc['month'],1,$lc['year'])); ?></td>
                                    <td><strong class="text-success">K<?php echo number_format($lc['total_amount']); ?></strong></td>
                                    <td>
                                        <a href="print_lecturer_payment.php?id=<?php echo $lc['request_id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                            <i class="bi bi-printer"></i> Receipt
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- End Lecturer Payments Tab -->
        </div><!-- End Tab Content -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
