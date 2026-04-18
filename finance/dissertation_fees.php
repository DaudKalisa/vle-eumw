<?php
/**
 * Finance - Dissertation Fee Management
 * Manage dissertation fees separate from tuition:
 * - Auto-invoice students with active dissertations
 * - Record installment payments (3 equal installments)
 * - Lock/unlock dissertation access based on payments
 * Installment schedule:
 *   1st: After supervisor assigned
 *   2nd: Before ethics submission & proposal defense
 *   3rd: Before final dissertation presentation
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$message = '';
$error = '';

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS dissertation_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    dissertation_id INT NOT NULL,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 250000.00,
    installment_amount DECIMAL(12,2) NOT NULL DEFAULT 83333.33,
    installment_1_paid DECIMAL(12,2) DEFAULT 0.00,
    installment_1_date DATE DEFAULT NULL,
    installment_2_paid DECIMAL(12,2) DEFAULT 0.00,
    installment_2_date DATE DEFAULT NULL,
    installment_3_paid DECIMAL(12,2) DEFAULT 0.00,
    installment_3_date DATE DEFAULT NULL,
    total_paid DECIMAL(12,2) DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 250000.00,
    lock_after_supervisor TINYINT(1) DEFAULT 0,
    lock_before_ethics TINYINT(1) DEFAULT 0,
    lock_before_final TINYINT(1) DEFAULT 0,
    invoiced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_diss (student_id, dissertation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add proof_file and approval_status columns to payment_transactions if missing
$conn->query("ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS proof_file VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected') DEFAULT 'pending'");

// Ensure dissertation fee setting exists (adds column if missing)
$conn->query("ALTER TABLE fee_settings ADD COLUMN IF NOT EXISTS dissertation_fee DECIMAL(12,2) DEFAULT 250000.00");

// Get dissertation fee from settings
$fee_settings = $conn->query("SELECT dissertation_fee FROM fee_settings LIMIT 1")->fetch_assoc();
$default_fee = (float)($fee_settings['dissertation_fee'] ?? 250000);
$installment_per = round($default_fee / 3, 2);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'invoice_all') {
        // Auto-invoice all students with active dissertations who don't have a fee record
        $result = $conn->query("
            SELECT d.dissertation_id, d.student_id
            FROM dissertations d
            WHERE d.is_active = 1
            AND NOT EXISTS (
                SELECT 1 FROM dissertation_fees df WHERE df.student_id = d.student_id AND df.dissertation_id = d.dissertation_id
            )
        ");
        $count = 0;
        if ($result) {
            $ins = $conn->prepare("INSERT INTO dissertation_fees (student_id, dissertation_id, fee_amount, installment_amount, balance) VALUES (?, ?, ?, ?, ?)");
            while ($row = $result->fetch_assoc()) {
                $ins->bind_param("siddd", $row['student_id'], $row['dissertation_id'], $default_fee, $installment_per, $default_fee);
                if ($ins->execute()) $count++;
            }
        }
        $message = $count > 0 ? "$count student(s) invoiced for dissertation fee (MKW " . number_format($default_fee) . ")." : "No new students to invoice. All dissertation students already have fee records.";

    } elseif ($action === 'invoice_student') {
        $sid = trim($_POST['student_id'] ?? '');
        $did = (int)($_POST['dissertation_id'] ?? 0);
        if ($sid && $did) {
            $check = $conn->prepare("SELECT id FROM dissertation_fees WHERE student_id = ? AND dissertation_id = ?");
            $check->bind_param("si", $sid, $did);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'This student already has a dissertation fee record.';
            } else {
                $ins = $conn->prepare("INSERT INTO dissertation_fees (student_id, dissertation_id, fee_amount, installment_amount, balance) VALUES (?, ?, ?, ?, ?)");
                $ins->bind_param("siddd", $sid, $did, $default_fee, $installment_per, $default_fee);
                if ($ins->execute()) {
                    $message = "Student $sid invoiced for dissertation fee.";
                } else {
                    $error = 'Failed to create fee record.';
                }
            }
        }

    } elseif ($action === 'record_payment') {
        $fee_id = (int)($_POST['fee_id'] ?? 0);
        $installment_num = (int)($_POST['installment_num'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_ref = trim($_POST['payment_ref'] ?? '');

        if ($fee_id && $installment_num >= 1 && $installment_num <= 3 && $amount > 0) {
            $fee = $conn->prepare("SELECT * FROM dissertation_fees WHERE id = ?");
            $fee->bind_param("i", $fee_id);
            $fee->execute();
            $fee_row = $fee->get_result()->fetch_assoc();

            if ($fee_row) {
                $col_paid = "installment_{$installment_num}_paid";
                $col_date = "installment_{$installment_num}_date";
                $new_paid = (float)$fee_row[$col_paid] + $amount;
                $new_total = (float)$fee_row['total_paid'] + $amount;
                $new_balance = (float)$fee_row['fee_amount'] - $new_total;
                if ($new_balance < 0) $new_balance = 0;

                $upd = $conn->prepare("UPDATE dissertation_fees SET {$col_paid} = ?, {$col_date} = CURDATE(), total_paid = ?, balance = ? WHERE id = ?");
                $upd->bind_param("dddi", $new_paid, $new_total, $new_balance, $fee_id);
                if ($upd->execute()) {
                    // Also record in payment_transactions
                    $pt = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_method, reference_number, payment_date, recorded_by, notes) VALUES (?, ?, ?, 'bank_transfer', ?, CURDATE(), ?, ?)");
                    $ptype = "dissertation_installment_{$installment_num}";
                    $recorder = $user['display_name'] ?? $user['username'] ?? 'Finance';
                    $notes = "Dissertation fee installment {$installment_num}";
                    $pt->bind_param("ssdsss", $fee_row['student_id'], $ptype, $amount, $payment_ref, $recorder, $notes);
                    $pt->execute();
                    $message = "Payment of MKW " . number_format($amount) . " recorded for installment {$installment_num}.";
                } else {
                    $error = 'Failed to record payment.';
                }
            } else {
                $error = 'Fee record not found.';
            }
        } else {
            $error = 'Please provide valid payment details.';
        }

    } elseif ($action === 'update_locks') {
        $fee_id = (int)($_POST['fee_id'] ?? 0);
        $lock1 = isset($_POST['lock_after_supervisor']) ? 1 : 0;
        $lock2 = isset($_POST['lock_before_ethics']) ? 1 : 0;
        $lock3 = isset($_POST['lock_before_final']) ? 1 : 0;

        $upd = $conn->prepare("UPDATE dissertation_fees SET lock_after_supervisor = ?, lock_before_ethics = ?, lock_before_final = ? WHERE id = ?");
        $upd->bind_param("iiii", $lock1, $lock2, $lock3, $fee_id);
        if ($upd->execute()) {
            $message = 'Access lock settings updated.';
        } else {
            $error = 'Failed to update lock settings.';
        }

    } elseif ($action === 'bulk_lock') {
        $lock_type = $_POST['lock_type'] ?? '';
        $lock_val = (int)($_POST['lock_val'] ?? 0);
        $valid_cols = ['lock_after_supervisor', 'lock_before_ethics', 'lock_before_final'];
        if (in_array($lock_type, $valid_cols)) {
            $stmt = $conn->prepare("UPDATE dissertation_fees SET {$lock_type} = ?");
            $stmt->bind_param("i", $lock_val);
            $stmt->execute();
            $label = $lock_val ? 'enabled' : 'disabled';
            $message = ucfirst(str_replace('_', ' ', $lock_type)) . " {$label} for all students.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proof_action'])) {
    // Handle proof approval/rejection (outside main action check)
    $proof_id = (int)($_POST['proof_id'] ?? 0);
    $decision = $_POST['proof_action'] ?? '';
    $installment_no = (int)($_POST['installment_no'] ?? 0);
    $fee_id = (int)($_POST['fee_id'] ?? 0);
    if ($proof_id && in_array($decision, ['approve','reject']) && $installment_no >= 1 && $installment_no <= 3 && $fee_id) {
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $upd_proof = $conn->prepare("UPDATE payment_transactions SET approval_status = ? WHERE id = ?");
        $upd_proof->bind_param("si", $status, $proof_id);
        $upd_proof->execute();
        if ($status === 'approved') {
            // Update installment paid in dissertation_fees
            $pt_stmt = $conn->prepare("SELECT amount FROM payment_transactions WHERE id = ?");
            $pt_stmt->bind_param("i", $proof_id);
            $pt_stmt->execute();
            $pt_row = $pt_stmt->get_result()->fetch_assoc();
            $amt = (float)($pt_row['amount'] ?? 0);
            
            $col_paid = "installment_{$installment_no}_paid";
            $fee_stmt = $conn->prepare("SELECT {$col_paid}, total_paid, fee_amount FROM dissertation_fees WHERE id = ?");
            $fee_stmt->bind_param("i", $fee_id);
            $fee_stmt->execute();
            $fee_row = $fee_stmt->get_result()->fetch_assoc();
            
            $new_inst = (float)$fee_row[$col_paid] + $amt;
            $new_total = (float)$fee_row['total_paid'] + $amt;
            $new_balance = (float)$fee_row['fee_amount'] - $new_total;
            if ($new_balance < 0) $new_balance = 0;
            
            $col_date = "installment_{$installment_no}_date";
            $upd_fee = $conn->prepare("UPDATE dissertation_fees SET {$col_paid} = ?, total_paid = ?, balance = ?, {$col_date} = CURDATE() WHERE id = ?");
            $upd_fee->bind_param("dddi", $new_inst, $new_total, $new_balance, $fee_id);
            $upd_fee->execute();
        }
        $message = $status === 'approved' ? 'Proof approved and installment marked as paid.' : 'Proof rejected.';
    }
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = "1=1";
if ($filter === 'unpaid') $where = "df.balance > 0";
elseif ($filter === 'paid') $where = "df.balance <= 0";
elseif ($filter === 'locked') $where = "(df.lock_after_supervisor = 1 OR df.lock_before_ethics = 1 OR df.lock_before_final = 1)";

if ($search) {
    $safe = $conn->real_escape_string($search);
    $where .= " AND (df.student_id LIKE '%{$safe}%' OR s.full_name LIKE '%{$safe}%')";
}

// Get all dissertation fee records
$records = $conn->query("
    SELECT df.*, s.full_name, s.email, d.title as dissertation_title, d.current_phase, d.supervisor_id,
           l.full_name as supervisor_name
    FROM dissertation_fees df
    JOIN students s ON df.student_id = s.student_id
    JOIN dissertations d ON df.dissertation_id = d.dissertation_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE {$where}
    ORDER BY df.invoiced_at DESC
");

// Get uninvoiced students (have dissertation but no fee record)
$uninvoiced = $conn->query("
    SELECT d.dissertation_id, d.student_id, d.title, d.current_phase, s.full_name,
           l.full_name as supervisor_name
    FROM dissertations d
    JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE d.is_active = 1
    AND NOT EXISTS (SELECT 1 FROM dissertation_fees df WHERE df.student_id = d.student_id AND df.dissertation_id = d.dissertation_id)
    ORDER BY s.full_name
");

// Stats
$stats = $conn->query("SELECT 
    COUNT(*) as total_invoiced,
    SUM(fee_amount) as total_expected,
    SUM(total_paid) as total_collected,
    SUM(balance) as total_outstanding,
    SUM(CASE WHEN balance <= 0 THEN 1 ELSE 0 END) as fully_paid,
    SUM(CASE WHEN lock_after_supervisor = 1 OR lock_before_ethics = 1 OR lock_before_final = 1 THEN 1 ELSE 0 END) as locked_count
    FROM dissertation_fees
")->fetch_assoc();

// Fetch pending payment proofs for dissertation fees
$pending_proofs = $conn->query("SELECT pt.*, s.full_name, df.dissertation_id, df.installment_amount FROM payment_transactions pt JOIN students s ON pt.student_id = s.student_id JOIN dissertation_fees df ON pt.fee_id = df.id WHERE pt.payment_type LIKE 'dissertation_installment_%' AND pt.approval_status = 'pending' ORDER BY pt.payment_date DESC");

$page_title = 'Dissertation Fees';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation Fees - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card { border-radius: 12px; padding: 20px; color: white; }
        .stat-card .stat-value { font-size: 1.6rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.85rem; opacity: 0.9; }
        .lock-badge { font-size: 0.7rem; }
        .installment-bar { height: 6px; border-radius: 3px; background: #e9ecef; }
        .installment-bar .fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
    </style>
</head>
<body class="bg-light">
    <?php include 'header_nav.php'; ?>

    <div class="container-fluid px-3 px-lg-4 mt-3">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Dissertation Fee Management</h4>
                <small class="text-muted">Fee: MKW <?= number_format($default_fee) ?> in 3 installments of MKW <?= number_format($installment_per) ?></small>
            </div>
            <div class="d-flex gap-2">
                <form method="POST" class="d-inline" onsubmit="return confirm('Invoice all uninvoiced dissertation students?')">
                    <input type="hidden" name="action" value="invoice_all">
                    <button class="btn btn-primary"><i class="bi bi-receipt me-1"></i>Invoice All</button>
                </form>
                <a href="fee_settings.php" class="btn btn-outline-secondary"><i class="bi bi-gear me-1"></i>Fee Settings</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <div class="stat-value"><?= (int)($stats['total_invoiced'] ?? 0) ?></div>
                    <div class="stat-label">Invoiced</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="stat-value">MKW <?= number_format($stats['total_expected'] ?? 0) ?></div>
                    <div class="stat-label">Expected</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-value">MKW <?= number_format($stats['total_collected'] ?? 0) ?></div>
                    <div class="stat-label">Collected</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <div class="stat-value">MKW <?= number_format($stats['total_outstanding'] ?? 0) ?></div>
                    <div class="stat-label">Outstanding</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                    <div class="stat-value"><?= (int)($stats['fully_paid'] ?? 0) ?></div>
                    <div class="stat-label">Fully Paid</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="stat-value"><?= (int)($stats['locked_count'] ?? 0) ?></div>
                    <div class="stat-label">Locked</div>
                </div>
            </div>
        </div>

        <!-- Bulk Lock Controls -->
        <div class="card mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-lock me-1"></i>Bulk Access Lock Controls</h6>
                <small class="text-muted">Lock dissertation phases for all students who haven't paid</small>
            </div>
            <div class="card-body py-2">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <span class="small"><strong>1st Installment</strong> <br><small class="text-muted">Lock after supervisor assigned</small></span>
                            <div>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_after_supervisor">
                                    <input type="hidden" name="lock_val" value="1">
                                    <button class="btn btn-sm btn-outline-danger me-1"><i class="bi bi-lock"></i> Lock All</button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_after_supervisor">
                                    <input type="hidden" name="lock_val" value="0">
                                    <button class="btn btn-sm btn-outline-success"><i class="bi bi-unlock"></i> Unlock All</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <span class="small"><strong>2nd Installment</strong> <br><small class="text-muted">Lock before ethics & defense</small></span>
                            <div>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_before_ethics">
                                    <input type="hidden" name="lock_val" value="1">
                                    <button class="btn btn-sm btn-outline-danger me-1"><i class="bi bi-lock"></i> Lock All</button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_before_ethics">
                                    <input type="hidden" name="lock_val" value="0">
                                    <button class="btn btn-sm btn-outline-success"><i class="bi bi-unlock"></i> Unlock All</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <span class="small"><strong>3rd Installment</strong> <br><small class="text-muted">Lock before final presentation</small></span>
                            <div>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_before_final">
                                    <input type="hidden" name="lock_val" value="1">
                                    <button class="btn btn-sm btn-outline-danger me-1"><i class="bi bi-lock"></i> Lock All</button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="bulk_lock">
                                    <input type="hidden" name="lock_type" value="lock_before_final">
                                    <input type="hidden" name="lock_val" value="0">
                                    <button class="btn btn-sm btn-outline-success"><i class="bi bi-unlock"></i> Unlock All</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Uninvoiced Students -->
        <?php if ($uninvoiced && $uninvoiced->num_rows > 0): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning text-dark py-2">
                <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Uninvoiced Dissertation Students (<?= $uninvoiced->num_rows ?>)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Student ID</th><th>Name</th><th>Dissertation</th><th>Phase</th><th>Supervisor</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php while ($u = $uninvoiced->fetch_assoc()): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($u['student_id']) ?></code></td>
                                <td><?= htmlspecialchars($u['full_name']) ?></td>
                                <td><small><?= htmlspecialchars(substr($u['title'] ?? 'Untitled', 0, 50)) ?></small></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($u['current_phase'] ?? 'topic') ?></span></td>
                                <td><?= htmlspecialchars($u['supervisor_name'] ?? 'Not Assigned') ?></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="invoice_student">
                                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($u['student_id']) ?>">
                                        <input type="hidden" name="dissertation_id" value="<?= $u['dissertation_id'] ?>">
                                        <button class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i>Invoice</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter & Search -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form class="row g-2 align-items-center" method="GET">
                    <div class="col-auto">
                        <select name="filter" class="form-select form-select-sm">
                            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Students</option>
                            <option value="unpaid" <?= $filter === 'unpaid' ? 'selected' : '' ?>>Outstanding Balance</option>
                            <option value="paid" <?= $filter === 'paid' ? 'selected' : '' ?>>Fully Paid</option>
                            <option value="locked" <?= $filter === 'locked' ? 'selected' : '' ?>>Locked Access</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search student..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                        <a href="dissertation_fees.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Fee Records -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Phase</th>
                                <th class="text-center">Inst. 1<br><small class="text-muted fw-normal">After Supervisor</small></th>
                                <th class="text-center">Inst. 2<br><small class="text-muted fw-normal">Before Ethics</small></th>
                                <th class="text-center">Inst. 3<br><small class="text-muted fw-normal">Before Final</small></th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Balance</th>
                                <th class="text-center">Locks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($records && $records->num_rows > 0): ?>
                            <?php while ($r = $records->fetch_assoc()):
                                $inst_amt = (float)$r['installment_amount'];
                                $pct1 = $inst_amt > 0 ? min(100, round(($r['installment_1_paid'] / $inst_amt) * 100)) : 0;
                                $pct2 = $inst_amt > 0 ? min(100, round(($r['installment_2_paid'] / $inst_amt) * 100)) : 0;
                                $pct3 = $inst_amt > 0 ? min(100, round(($r['installment_3_paid'] / $inst_amt) * 100)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($r['full_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['student_id']) ?></small>
                                </td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($r['current_phase'] ?? '-') ?></span></td>
                                <td class="text-center">
                                    <small>MKW <?= number_format($r['installment_1_paid']) ?>/<?= number_format($inst_amt) ?></small>
                                    <div class="installment-bar mt-1"><div class="fill bg-<?= $pct1 >= 100 ? 'success' : ($pct1 > 0 ? 'warning' : 'danger') ?>" style="width:<?= $pct1 ?>%"></div></div>
                                </td>
                                <td class="text-center">
                                    <small>MKW <?= number_format($r['installment_2_paid']) ?>/<?= number_format($inst_amt) ?></small>
                                    <div class="installment-bar mt-1"><div class="fill bg-<?= $pct2 >= 100 ? 'success' : ($pct2 > 0 ? 'warning' : 'danger') ?>" style="width:<?= $pct2 ?>%"></div></div>
                                </td>
                                <td class="text-center">
                                    <small>MKW <?= number_format($r['installment_3_paid']) ?>/<?= number_format($inst_amt) ?></small>
                                    <div class="installment-bar mt-1"><div class="fill bg-<?= $pct3 >= 100 ? 'success' : ($pct3 > 0 ? 'warning' : 'danger') ?>" style="width:<?= $pct3 ?>%"></div></div>
                                </td>
                                <td class="text-center text-success fw-bold">MKW <?= number_format($r['total_paid']) ?></td>
                                <td class="text-center <?= $r['balance'] > 0 ? 'text-danger' : 'text-success' ?> fw-bold">MKW <?= number_format($r['balance']) ?></td>
                                <td class="text-center">
                                    <?php if ($r['lock_after_supervisor']): ?><span class="badge bg-danger lock-badge">1st</span><?php endif; ?>
                                    <?php if ($r['lock_before_ethics']): ?><span class="badge bg-danger lock-badge">2nd</span><?php endif; ?>
                                    <?php if ($r['lock_before_final']): ?><span class="badge bg-danger lock-badge">3rd</span><?php endif; ?>
                                    <?php if (!$r['lock_after_supervisor'] && !$r['lock_before_ethics'] && !$r['lock_before_final']): ?>
                                        <span class="badge bg-success lock-badge">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#payModal<?= $r['id'] ?>"><i class="bi bi-cash"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#lockModal<?= $r['id'] ?>"><i class="bi bi-lock"></i></button>
                                </td>
                            </tr>

                            <!-- Payment Modal -->
                            <div class="modal fade" id="payModal<?= $r['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-success text-white">
                                            <h6 class="modal-title"><i class="bi bi-cash me-1"></i>Record Dissertation Payment - <?= htmlspecialchars($r['full_name']) ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="record_payment">
                                                <input type="hidden" name="fee_id" value="<?= $r['id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Installment</label>
                                                    <select name="installment_num" class="form-select" required>
                                                        <option value="1">1st Installment (After Supervisor) - Paid: MKW <?= number_format($r['installment_1_paid']) ?></option>
                                                        <option value="2">2nd Installment (Before Ethics/Defense) - Paid: MKW <?= number_format($r['installment_2_paid']) ?></option>
                                                        <option value="3">3rd Installment (Before Final) - Paid: MKW <?= number_format($r['installment_3_paid']) ?></option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Amount (MKW)</label>
                                                    <input type="number" name="amount" class="form-control" step="0.01" min="1" value="<?= number_format($inst_amt, 2, '.', '') ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Payment Reference</label>
                                                    <input type="text" name="payment_ref" class="form-control" placeholder="Receipt/reference number">
                                                </div>
                                                <div class="alert alert-info py-2">
                                                    <small><strong>Fee:</strong> MKW <?= number_format($r['fee_amount']) ?> | <strong>Paid:</strong> MKW <?= number_format($r['total_paid']) ?> | <strong>Balance:</strong> MKW <?= number_format($r['balance']) ?></small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Record Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Lock Modal -->
                            <div class="modal fade" id="lockModal<?= $r['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-secondary text-white">
                                            <h6 class="modal-title"><i class="bi bi-lock me-1"></i>Access Lock Settings - <?= htmlspecialchars($r['full_name']) ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="update_locks">
                                                <input type="hidden" name="fee_id" value="<?= $r['id'] ?>">
                                                <p class="text-muted small">Enable locks to restrict dissertation access until the corresponding installment is paid. Student will see a payment notice but cannot proceed with locked phases.</p>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="lock_after_supervisor" id="lock1_<?= $r['id'] ?>" <?= $r['lock_after_supervisor'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="lock1_<?= $r['id'] ?>">
                                                        <strong>Lock after supervisor assigned</strong><br>
                                                        <small class="text-muted">Requires 1st installment (MKW <?= number_format($inst_amt) ?>) to continue after supervisor assignment</small>
                                                    </label>
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="lock_before_ethics" id="lock2_<?= $r['id'] ?>" <?= $r['lock_before_ethics'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="lock2_<?= $r['id'] ?>">
                                                        <strong>Lock before ethics & proposal defense</strong><br>
                                                        <small class="text-muted">Requires 2nd installment (MKW <?= number_format($inst_amt) ?>) to submit ethics or attend defense</small>
                                                    </label>
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="lock_before_final" id="lock3_<?= $r['id'] ?>" <?= $r['lock_before_final'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="lock3_<?= $r['id'] ?>">
                                                        <strong>Lock before final submission</strong><br>
                                                        <small class="text-muted">Requires 3rd installment (MKW <?= number_format($inst_amt) ?>) for final dissertation presentation</small>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Settings</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No dissertation fee records found. Use "Invoice All" to create fee records for dissertation students.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pending Payment Proofs for Approval -->
        <?php if ($pending_proofs && $pending_proofs->num_rows > 0): ?>
        <div class="card mt-4 border-success">
            <div class="card-header bg-success bg-opacity-10 py-2">
                <h6 class="mb-0"><i class="bi bi-cash me-1"></i>Pending Proofs of Payment for Approval</h6>
                <small class="text-muted">Approve or reject submitted proofs for dissertation fee installments.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Installment</th>
                                <th>Amount</th>
                                <th>Proof File</th>
                                <th>Reference</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($pf = $pending_proofs->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pf['full_name']) ?></strong><br><small class="text-muted">ID: <?= htmlspecialchars($pf['student_id']) ?></small></td>
                                <td>
                                    <?php
                                    $installment_no = isset($pf['installment_no']) ? (int)$pf['installment_no'] : 0;
                                    if (!$installment_no && isset($pf['payment_type'])) {
                                        if (preg_match('/dissertation_installment_(\d+)/', $pf['payment_type'], $m)) {
                                            $installment_no = (int)$m[1];
                                        }
                                    }
                                    ?>
                                    <?= $installment_no ? $installment_no : '-' ?> / MKW <?= number_format($pf['installment_amount']) ?>
                                </td>
                                <td>MKW <?= number_format($pf['amount']) ?></td>
                                <td>
                                    <?php if (!empty($pf['proof_file']) && !empty($pf['id'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#proofModal<?= (int)$pf['id'] ?>">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <!-- Modal for proof preview -->
                                        <div class="modal fade" id="proofModal<?= (int)$pf['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Proof of Payment</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body text-center">
                                                        <?php
                                                        $file_ext = strtolower(pathinfo($pf['proof_file'], PATHINFO_EXTENSION));
                                                        $file_path = '../' . $pf['proof_file'];
                                                        if ($file_ext === 'pdf'):
                                                        ?>
                                                            <iframe src="<?= htmlspecialchars($file_path) ?>" width="100%" height="600px"></iframe>
                                                        <?php else: ?>
                                                            <img src="<?= htmlspecialchars($file_path) ?>" class="img-fluid" alt="Proof of Payment">
                                                        <?php endif; ?>
                                                        <div class="mt-3">
                                                            <a href="<?= htmlspecialchars($file_path) ?>" download class="btn btn-primary">
                                                                <i class="bi bi-download"></i> Download
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($pf['reference_number']) ?></td>
                                <td><small><?= $pf['payment_date'] ? date('M j, Y', strtotime($pf['payment_date'])) : '-' ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="proof_id" value="<?= isset($pf['id']) ? (int)$pf['id'] : '' ?>">
                                        <input type="hidden" name="installment_no" value="<?= isset($pf['installment_no']) && $pf['installment_no'] > 0 ? (int)$pf['installment_no'] : '' ?>">
                                        <input type="hidden" name="fee_id" value="<?= isset($pf['fee_id']) && $pf['fee_id'] > 0 ? (int)$pf['fee_id'] : '' ?>">
                                        <button type="submit" name="proof_action" value="approve" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button>
                                        <button type="submit" name="proof_action" value="reject" class="btn btn-sm btn-danger ms-1"><i class="bi bi-x-lg"></i> Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
