<?php
// review_payments.php - Finance officer payment review and approval
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user_id = $_SESSION['vle_user_id'];

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_payment'])) {
    $submission_id = (int)$_POST['submission_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $notes = trim($_POST['review_notes'] ?? '');
    
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
                $payment_notes = $submission['transaction_type'] . ' via ' . $submission['bank_name'] . ' - Ref: ' . $submission['payment_reference'];
                $stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, amount, payment_type, payment_date, notes) VALUES (?, ?, 'payment', ?, ?)");
                $stmt->bind_param("sdss", $submission['student_id'], $submission['amount'], $submission['transaction_date'], $payment_notes);
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
                
                // Update submission status
                $stmt = $conn->prepare("UPDATE payment_submissions SET status = 'approved', reviewed_by = ?, reviewed_date = NOW(), finance_id = ?, notes = ? WHERE submission_id = ?");
                $stmt->bind_param("sisi", $user_id, $finance_id, $notes, $submission_id);
                $stmt->execute();
                
                $conn->commit();
                $success = "Payment approved and recorded successfully!";
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

// Get pending submissions
$pending_query = "SELECT ps.*, s.full_name, s.email, sf.balance 
                  FROM payment_submissions ps
                  JOIN students s ON ps.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
                  LEFT JOIN student_finances sf ON ps.student_id COLLATE utf8mb4_general_ci = sf.student_id COLLATE utf8mb4_general_ci
                  WHERE ps.status = 'pending'
                  ORDER BY ps.submission_date ASC";
$pending_submissions = $conn->query($pending_query)->fetch_all(MYSQLI_ASSOC);

// Get reviewed submissions
$reviewed_query = "SELECT ps.*, s.full_name, s.email 
                   FROM payment_submissions ps
                   JOIN students s ON ps.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
                   WHERE ps.status IN ('approved', 'rejected')
                   ORDER BY ps.reviewed_date DESC
                   LIMIT 50";
$reviewed_submissions = $conn->query($reviewed_query)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Payment Submissions - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-check2-square"></i> Review Payment Submissions</h2>
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
                                            <?php endif; ?>
                                        </td>
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
                                            
                                            if (action === 'reject') {
                                                actionInput.value = 'reject';
                                                approveBtn.style.display = 'none';
                                                rejectBtn.style.display = 'inline-block';
                                                modal.querySelector('.modal-header').classList.remove('bg-primary');
                                                modal.querySelector('.modal-header').classList.add('bg-danger');
                                            } else {
                                                actionInput.value = 'approve';
                                                approveBtn.style.display = 'inline-block';
                                                rejectBtn.style.display = 'none';
                                                modal.querySelector('.modal-header').classList.remove('bg-danger');
                                                modal.querySelector('.modal-header').classList.add('bg-primary');
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
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
