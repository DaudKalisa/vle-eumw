<?php
/**
 * Finance Clearance Review - Review individual student clearance
 * Finance officer reviews payment proof, checks balance, clears or rejects
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

$clearance_id = (int)($_GET['id'] ?? 0);
if (!$clearance_id) {
    header('Location: Finance_clearence_students.php');
    exit;
}

// Load student
$stmt = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ?");
$stmt->bind_param("i", $clearance_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header('Location: Finance_clearence_students.php?error=notfound');
    exit;
}

// Load payments
$payments = [];
$pstmt = $conn->prepare("SELECT fcp.*, u.username as reviewer_name FROM finance_clearance_payments fcp LEFT JOIN users u ON fcp.reviewed_by = u.user_id WHERE fcp.clearance_id = ? ORDER BY fcp.submitted_at DESC");
$pstmt->bind_param("i", $clearance_id);
$pstmt->execute();
$payments = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Approve/reject a payment
    if ($_POST['action'] === 'review_payment') {
        $payment_id = (int)$_POST['payment_id'];
        $decision = $_POST['decision']; // approved or rejected
        $review_notes = trim($_POST['review_notes'] ?? '');
        
        if (!in_array($decision, ['approved', 'rejected'])) {
            $error = 'Invalid decision.';
        } else {
            $uid = (int)$user['user_id'];
            $stmt = $conn->prepare("UPDATE finance_clearance_payments SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE payment_id = ? AND clearance_id = ?");
            $stmt->bind_param("sisii", $decision, $uid, $review_notes, $payment_id, $clearance_id);
            $stmt->execute();
            
            // Recalculate balance
            $sum_rs = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM finance_clearance_payments WHERE clearance_id = $clearance_id AND status = 'approved'");
            $total_approved = $sum_rs->fetch_assoc()['total'];
            $new_balance = $student['invoiced_amount'] - $total_approved;
            $conn->query("UPDATE finance_clearance_students SET balance = $new_balance, amount_claimed = $total_approved WHERE clearance_id = $clearance_id");
            
            $success = "Payment $decision successfully.";
            
            // Reload student and payments
            $stmt2 = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ?");
            $stmt2->bind_param("i", $clearance_id);
            $stmt2->execute();
            $student = $stmt2->get_result()->fetch_assoc();
            
            $pstmt2 = $conn->prepare("SELECT fcp.*, u.username as reviewer_name FROM finance_clearance_payments fcp LEFT JOIN users u ON fcp.reviewed_by = u.user_id WHERE fcp.clearance_id = ? ORDER BY fcp.submitted_at DESC");
            $pstmt2->bind_param("i", $clearance_id);
            $pstmt2->execute();
            $payments = $pstmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
    
    // Clear student
    if ($_POST['action'] === 'clear_student') {
        $finance_notes = trim($_POST['finance_notes'] ?? '');
        $uid = (int)$user['user_id'];
        
        // Generate certificate number
        $year = date('Y');
        $cnt_rs = $conn->query("SELECT COUNT(*) as cnt FROM finance_clearance_students WHERE certificate_number IS NOT NULL AND certificate_number LIKE 'FC-$year%'");
        $cnt = ($cnt_rs->fetch_assoc()['cnt'] ?? 0) + 1;
        $cert_number = 'FC-' . $year . '-' . str_pad($cnt, 5, '0', STR_PAD_LEFT);
        
        $stmt = $conn->prepare("UPDATE finance_clearance_students SET status = 'cleared', cleared_by = ?, cleared_at = NOW(), certificate_number = ?, finance_notes = ? WHERE clearance_id = ?");
        $stmt->bind_param("issi", $uid, $cert_number, $finance_notes, $clearance_id);
        
        if ($stmt->execute()) {
            $success = "Student cleared! Certificate Number: $cert_number";
            // Reload
            $stmt2 = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ?");
            $stmt2->bind_param("i", $clearance_id);
            $stmt2->execute();
            $student = $stmt2->get_result()->fetch_assoc();
        } else {
            $error = 'Failed to clear student.';
        }
    }
    
    // Reject student
    if ($_POST['action'] === 'reject_student') {
        $finance_notes = trim($_POST['finance_notes'] ?? '');
        $uid = (int)$user['user_id'];
        
        $stmt = $conn->prepare("UPDATE finance_clearance_students SET status = 'rejected', cleared_by = ?, finance_notes = ? WHERE clearance_id = ?");
        $stmt->bind_param("isi", $uid, $finance_notes, $clearance_id);
        $stmt->execute();
        $success = 'Student clearance rejected.';
        
        // Reload
        $stmt2 = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ?");
        $stmt2->bind_param("i", $clearance_id);
        $stmt2->execute();
        $student = $stmt2->get_result()->fetch_assoc();
    }
    
    // Convert to full student
    if ($_POST['action'] === 'convert_to_student') {
        // Check if already converted
        if ($student['converted_to_student']) {
            $error = 'This student has already been converted to a full student.';
        } else {
            // Check if student_id already exists in students table
            $check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $check->bind_param("s", $student['student_id']);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'A student with ID ' . htmlspecialchars($student['student_id']) . ' already exists in the system.';
            } else {
                $ins = $conn->prepare("INSERT INTO students (student_id, full_name, email, phone, department, program_type, program, year_of_study, campus, gender, national_id, address, entry_type, semester, year_of_registration, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $yr = $student['year_of_registration'] ?? date('Y');
                $ins->bind_param("sssssssississsi",
                    $student['student_id'],
                    $student['full_name'],
                    $student['email'],
                    $student['phone'],
                    $student['department'],
                    $student['program_type'],
                    $student['program'],
                    $student['year_of_study'],
                    $student['campus'],
                    $student['gender'],
                    $student['national_id'],
                    $student['address'],
                    $student['entry_type'],
                    $student['semester'],
                    $yr
                );
                
                if ($ins->execute()) {
                    $conn->query("UPDATE finance_clearance_students SET converted_to_student = 1, converted_at = NOW() WHERE clearance_id = $clearance_id");
                    $success = 'Student successfully converted to a full student record! They can now be found in the main Students list.';
                    
                    // Reload
                    $stmt2 = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ?");
                    $stmt2->bind_param("i", $clearance_id);
                    $stmt2->execute();
                    $student = $stmt2->get_result()->fetch_assoc();
                } else {
                    $error = 'Failed to convert student: ' . $conn->error;
                }
            }
        }
    }
}

// Calculate totals
$total_approved = 0;
$total_pending = 0;
foreach ($payments as $p) {
    if ($p['status'] === 'approved') $total_approved += $p['amount'];
    if ($p['status'] === 'pending') $total_pending += $p['amount'];
}
$balance = $student['invoiced_amount'] - $total_approved;

$page_title = 'Review Clearance: ' . $student['full_name'];
$breadcrumbs = [
    ['title' => 'Finance Clearance Students', 'url' => 'Finance_clearence_students.php'],
    ['title' => $student['full_name']]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - VLE Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/finance-dashboard.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .proof-image { max-width: 100%; max-height: 400px; border-radius: 8px; border: 1px solid #e2e8f0; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Student Info -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-person me-2"></i>Student Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Student ID</td><td><strong><?= htmlspecialchars($student['student_id']) ?></strong></td></tr>
                        <tr><td class="text-muted">Full Name</td><td><strong><?= htmlspecialchars($student['full_name']) ?></strong></td></tr>
                        <tr><td class="text-muted">Email</td><td><?= htmlspecialchars($student['email'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Phone</td><td><?= htmlspecialchars($student['phone'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Program</td><td><?= htmlspecialchars($student['program'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Program Type</td><td><span class="badge bg-<?= $student['program_type'] === 'masters' ? 'info' : 'primary' ?>"><?= ucfirst($student['program_type']) ?></span></td></tr>
                        <tr><td class="text-muted">Department</td><td><?= htmlspecialchars($student['department'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Campus</td><td><?= htmlspecialchars($student['campus'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Year</td><td>Year <?= $student['year_of_study'] ?></td></tr>
                        <tr><td class="text-muted">Registered</td><td><?= date('M j, Y H:i', strtotime($student['registered_at'])) ?></td></tr>
                        <tr><td class="text-muted">Status</td><td>
                            <?php
                            $sc = ['registered'=>'secondary','invoiced'=>'info','proof_submitted'=>'warning','proof_requested'=>'info','cleared'=>'success','rejected'=>'danger'];
                            ?>
                            <span class="badge bg-<?= $sc[$student['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $student['status'])) ?></span>
                        </td></tr>
                        <?php if ($student['certificate_number']): ?>
                        <tr><td class="text-muted">Certificate</td><td><span class="badge bg-success"><?= htmlspecialchars($student['certificate_number']) ?></span></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <!-- Financial Summary -->
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Financial Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Invoiced Amount:</span>
                        <strong>MWK <?= number_format($student['invoiced_amount'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Approved Payments:</span>
                        <strong class="text-success">MWK <?= number_format($total_approved, 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Pending Payments:</span>
                        <strong class="text-warning">MWK <?= number_format($total_pending, 2) ?></strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span class="fw-bold">Outstanding Balance:</span>
                        <strong class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?> fs-5">MWK <?= number_format($balance, 2) ?></strong>
                    </div>
                    <?php if ($balance <= 0): ?>
                        <div class="alert alert-success mt-3 mb-0 py-2"><i class="bi bi-check-circle me-2"></i>No outstanding balance - eligible for clearance.</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Clearance Actions -->
            <?php if ($student['status'] !== 'cleared'): ?>
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Clearance Decision</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="clearanceForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Finance Notes</label>
                            <textarea name="finance_notes" class="form-control" rows="3" placeholder="Notes about the clearance decision..."><?= htmlspecialchars($student['finance_notes'] ?? '') ?></textarea>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="clear_student" class="btn btn-success" onclick="return confirm('Clear this student for finance? A certificate will be generated.')"><i class="bi bi-check-circle me-2"></i>Clear Student</button>
                            <button type="submit" name="action" value="reject_student" class="btn btn-outline-danger" onclick="return confirm('Reject this student\'s clearance?')"><i class="bi bi-x-circle me-2"></i>Reject Clearance</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-body text-center">
                    <div class="text-success mb-2"><i class="bi bi-patch-check-fill" style="font-size:3rem"></i></div>
                    <h5 class="text-success">Student Cleared</h5>
                    <p class="text-muted">Certificate: <strong><?= htmlspecialchars($student['certificate_number']) ?></strong></p>
                    <p class="text-muted small">Cleared on <?= date('M j, Y H:i', strtotime($student['cleared_at'])) ?></p>
                    <a href="finance_clearance_certificate.php?id=<?= $student['clearance_id'] ?>" class="btn btn-success"><i class="bi bi-printer me-2"></i>Print Certificate</a>
                    
                    <?php if (empty($student['converted_to_student'])): ?>
                    <hr>
                    <form method="POST" class="mt-2">
                        <p class="text-muted small mb-2">Convert this clearance student to a full student record in the main system.</p>
                        <button type="submit" name="action" value="convert_to_student" class="btn btn-primary" onclick="return confirm('Convert this student to a full student record? This will create an entry in the main Students table.')">
                            <i class="bi bi-person-plus me-2"></i>Convert to Full Student
                        </button>
                    </form>
                    <?php else: ?>
                    <hr>
                    <div class="alert alert-info small mb-0 mt-2">
                        <i class="bi bi-check-circle me-1"></i>Converted to full student on <?= date('M j, Y H:i', strtotime($student['converted_at'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Payments / Proof -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Payment Submissions (<?= count($payments) ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox" style="font-size:2rem"></i>
                            <p class="mt-2">No payment proofs submitted yet.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($payments as $pay): ?>
                    <div class="border rounded-3 p-3 mb-3 <?= $pay['status'] === 'approved' ? 'border-success' : ($pay['status'] === 'rejected' ? 'border-danger' : 'border-warning') ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold">Payment #<?= $pay['payment_id'] ?></h6>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><td class="text-muted" style="width:120px">Amount</td><td><strong>MWK <?= number_format($pay['amount'], 2) ?></strong></td></tr>
                                    <tr><td class="text-muted">Reference</td><td><?= htmlspecialchars($pay['payment_reference'] ?: '—') ?></td></tr>
                                    <tr><td class="text-muted">Date</td><td><?= $pay['payment_date'] ? date('M j, Y', strtotime($pay['payment_date'])) : '—' ?></td></tr>
                                    <tr><td class="text-muted">Bank</td><td><?= htmlspecialchars($pay['bank_name'] ?: '—') ?></td></tr>
                                    <tr><td class="text-muted">Submitted</td><td><?= date('M j, Y H:i', strtotime($pay['submitted_at'])) ?></td></tr>
                                    <tr><td class="text-muted">Status</td><td>
                                        <span class="badge bg-<?= $pay['status'] === 'approved' ? 'success' : ($pay['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= ucfirst($pay['status']) ?></span>
                                        <?php if ($pay['reviewer_name']): ?> <small class="text-muted">by <?= htmlspecialchars($pay['reviewer_name']) ?></small><?php endif; ?>
                                    </td></tr>
                                    <?php if ($pay['notes']): ?>
                                    <tr><td class="text-muted">Student Notes</td><td><small><?= htmlspecialchars($pay['notes']) ?></small></td></tr>
                                    <?php endif; ?>
                                    <?php if ($pay['review_notes']): ?>
                                    <tr><td class="text-muted">Review Notes</td><td><small class="text-info"><?= htmlspecialchars($pay['review_notes']) ?></small></td></tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <?php if ($pay['proof_file']): ?>
                                    <?php 
                                    $proof_path = '../uploads/clearance_payments/' . $pay['proof_file'];
                                    $ext = strtolower(pathinfo($pay['proof_file'], PATHINFO_EXTENSION));
                                    ?>
                                    <p class="fw-semibold mb-2">Proof of Payment:</p>
                                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                        <a href="<?= htmlspecialchars($proof_path) ?>" target="_blank">
                                            <img src="<?= htmlspecialchars($proof_path) ?>" class="proof-image" alt="Proof of payment">
                                        </a>
                                    <?php elseif ($ext === 'pdf'): ?>
                                        <a href="<?= htmlspecialchars($proof_path) ?>" target="_blank" class="btn btn-outline-primary"><i class="bi bi-file-pdf me-2"></i>View PDF Proof</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($pay['status'] === 'pending'): ?>
                                <hr>
                                <form method="POST">
                                    <input type="hidden" name="action" value="review_payment">
                                    <input type="hidden" name="payment_id" value="<?= $pay['payment_id'] ?>">
                                    <div class="mb-2">
                                        <textarea name="review_notes" class="form-control form-control-sm" rows="2" placeholder="Review notes (optional)"></textarea>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm flex-fill"><i class="bi bi-check me-1"></i>Approve</button>
                                        <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm flex-fill"><i class="bi bi-x me-1"></i>Reject</button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
