<?php
/**
 * Exam Clearance Review - Review individual student exam clearance
 * Finance officer reviews payment proof, checks balance, clears or rejects
 */
require_once '../includes/auth.php';
require_once '../includes/exam_clearance_helpers.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

$clearance_id = (int)($_GET['id'] ?? 0);
if (!$clearance_id) {
    header('Location: exam_clearance_students.php');
    exit;
}

// Load student
$stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
$stmt->bind_param("i", $clearance_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header('Location: exam_clearance_students.php?error=notfound');
    exit;
}

// Load payments
$payments = [];
$pstmt = $conn->prepare("SELECT ecp.*, u.username as reviewer_name FROM exam_clearance_payments ecp LEFT JOIN users u ON ecp.reviewed_by = u.user_id WHERE ecp.clearance_id = ? ORDER BY ecp.submitted_at DESC");
$pstmt->bind_param("i", $clearance_id);
$pstmt->execute();
$payments = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Approve/reject a payment
    if ($_POST['action'] === 'review_payment') {
        $payment_id = (int)$_POST['payment_id'];
        $decision = $_POST['decision'];
        $review_notes = trim($_POST['review_notes'] ?? '');
        
        if (!in_array($decision, ['approved', 'rejected'])) {
            $error = 'Invalid decision.';
        } else {
            $uid = (int)$user['user_id'];
            $stmt = $conn->prepare("UPDATE exam_clearance_payments SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE payment_id = ? AND clearance_id = ?");
            $stmt->bind_param("sisii", $decision, $uid, $review_notes, $payment_id, $clearance_id);
            $stmt->execute();
            
            // Recalculate balance
            $sum_rs = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM exam_clearance_payments WHERE clearance_id = $clearance_id AND status = 'approved'");
            $total_approved = $sum_rs->fetch_assoc()['total'];
            $new_balance = $student['invoiced_amount'] - $total_approved;
            $conn->query("UPDATE exam_clearance_students SET balance = $new_balance, amount_claimed = $total_approved WHERE clearance_id = $clearance_id");
            
            // Log activity
            $pay_amount = 0;
            foreach ($payments as $p) { if ((int)$p['payment_id'] === $payment_id) { $pay_amount = $p['amount']; break; } }
            logExamClearanceActivity($conn, $clearance_id, 'payment_' . $decision, 'finance', $uid, $user['full_name'] ?? $user['username'], "Payment #{$payment_id} {$decision}. Amount: MWK " . number_format($pay_amount, 2) . ($review_notes ? ". Notes: {$review_notes}" : ''));
            
            // Email student
            notifyStudentPaymentReviewed($conn, $student['email'], $student['full_name'], $pay_amount, $decision, $review_notes);
            
            $success = "Payment $decision successfully.";
            
            // AUTO-CLEAR: If balance is zero or less after approval, automatically clear the student
            if ($decision === 'approved' && $new_balance <= 0 && $student['status'] !== 'cleared') {
                $clearance_type = $student['clearance_type'] ?? 'endsemester';
                $year = date('Y');
                $prefix = ($clearance_type === 'midsemester') ? 'ECM' : 'EC';
                $cnt_rs = $conn->query("SELECT COUNT(*) as cnt FROM exam_clearance_students WHERE certificate_number IS NOT NULL AND certificate_number LIKE '{$prefix}-$year%'");
                $cnt = ($cnt_rs->fetch_assoc()['cnt'] ?? 0) + 1;
                $cert_number = $prefix . '-' . $year . '-' . str_pad($cnt, 5, '0', STR_PAD_LEFT);
                
                $auto_stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'cleared', cleared_by = ?, cleared_at = NOW(), certificate_number = ?, finance_notes = 'Auto-cleared: balance fully paid', amount_paid = ? WHERE clearance_id = ?");
                $auto_stmt->bind_param("isdi", $uid, $cert_number, $total_approved, $clearance_id);
                $auto_stmt->execute();
                
                // Record revenue
                $rev_check = $conn->query("SELECT revenue_recorded FROM exam_clearance_students WHERE clearance_id = $clearance_id");
                $already_recorded = (int)($rev_check->fetch_assoc()['revenue_recorded'] ?? 0);
                
                if (!$already_recorded && $total_approved > 0) {
                    $reg_fee = (float)($student['registration_fee'] ?? 0);
                    $tuition_paid = $total_approved - $reg_fee;
                    if ($tuition_paid < 0) { $tuition_paid = 0; $reg_fee = $total_approved; }
                    $today = date('Y-m-d');
                    $sid = $student['student_id'];
                    $ctype_label = ($clearance_type === 'midsemester') ? 'Mid-Semester' : 'End-Semester';
                    
                    if ($tuition_paid > 0) {
                        $rev_stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_tuition', ?, ?, ?, ?, ?)");
                        $desc = "Exam Clearance Tuition ({$ctype_label}) - {$student['full_name']}";
                        $recorded_by = $user['username'] ?? 'system';
                        $rev_stmt->bind_param("sdssss", $sid, $tuition_paid, $today, $desc, $cert_number, $recorded_by);
                        $rev_stmt->execute();
                    }
                    if ($reg_fee > 0) {
                        $rev_stmt2 = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_registration', ?, ?, ?, ?, ?)");
                        $desc2 = "Exam Clearance Registration Fee ({$ctype_label}) - {$student['full_name']}";
                        $recorded_by2 = $user['username'] ?? 'system';
                        $rev_stmt2->bind_param("sdssss", $sid, $reg_fee, $today, $desc2, $cert_number, $recorded_by2);
                        $rev_stmt2->execute();
                    }
                    $conn->query("UPDATE exam_clearance_students SET revenue_recorded = 1 WHERE clearance_id = $clearance_id");
                }
                
                // Log auto-clearance
                logExamClearanceActivity($conn, $clearance_id, 'cleared', 'system', $uid, 'System (Auto-Clear)', "Auto-cleared: balance fully paid. Certificate: {$cert_number}. Amount: MWK " . number_format($total_approved, 2));
                
                // Email student
                notifyStudentCleared($conn, $student['email'], $student['full_name'], $cert_number, 'System (Auto-Clearance)');
                
                $success = "Payment approved. Student AUTO-CLEARED — balance is fully paid! Certificate: $cert_number";
            }
            
            // AUTO-CLEAR: If balance is zero or less after approval, automatically clear the student
            if ($decision === 'approved' && $new_balance <= 0 && $student['status'] !== 'cleared') {
                $clearance_type = $student['clearance_type'] ?? 'endsemester';
                $year = date('Y');
                $prefix = ($clearance_type === 'midsemester') ? 'ECM' : 'EC';
                $cnt_rs = $conn->query("SELECT COUNT(*) as cnt FROM exam_clearance_students WHERE certificate_number IS NOT NULL AND certificate_number LIKE '{$prefix}-$year%'");
                $cnt = ($cnt_rs->fetch_assoc()['cnt'] ?? 0) + 1;
                $cert_number = $prefix . '-' . $year . '-' . str_pad($cnt, 5, '0', STR_PAD_LEFT);
                
                $auto_stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'cleared', cleared_by = ?, cleared_at = NOW(), certificate_number = ?, finance_notes = 'Auto-cleared: balance fully paid', amount_paid = ? WHERE clearance_id = ?");
                $auto_stmt->bind_param("isdi", $uid, $cert_number, $total_approved, $clearance_id);
                $auto_stmt->execute();
                
                // Record revenue
                $rev_check = $conn->query("SELECT revenue_recorded FROM exam_clearance_students WHERE clearance_id = $clearance_id");
                $already_recorded = (int)($rev_check->fetch_assoc()['revenue_recorded'] ?? 0);
                
                if (!$already_recorded && $total_approved > 0) {
                    $reg_fee = (float)($student['registration_fee'] ?? 0);
                    $tuition_paid = $total_approved - $reg_fee;
                    if ($tuition_paid < 0) { $tuition_paid = 0; $reg_fee = $total_approved; }
                    $today = date('Y-m-d');
                    $sid = $student['student_id'];
                    $ctype_label = ($clearance_type === 'midsemester') ? 'Mid-Semester' : 'End-Semester';
                    
                    if ($tuition_paid > 0) {
                        $rev_stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_tuition', ?, ?, ?, ?, ?)");
                        $desc = "Exam Clearance Tuition ({$ctype_label}) - {$student['full_name']}";
                        $recorded_by = $user['username'] ?? 'system';
                        $rev_stmt->bind_param("sdssss", $sid, $tuition_paid, $today, $desc, $cert_number, $recorded_by);
                        $rev_stmt->execute();
                    }
                    if ($reg_fee > 0) {
                        $rev_stmt2 = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_registration', ?, ?, ?, ?, ?)");
                        $desc2 = "Exam Clearance Registration Fee ({$ctype_label}) - {$student['full_name']}";
                        $recorded_by2 = $user['username'] ?? 'system';
                        $rev_stmt2->bind_param("sdssss", $sid, $reg_fee, $today, $desc2, $cert_number, $recorded_by2);
                        $rev_stmt2->execute();
                    }
                    $conn->query("UPDATE exam_clearance_students SET revenue_recorded = 1 WHERE clearance_id = $clearance_id");
                }
                
                // Log auto-clearance
                logExamClearanceActivity($conn, $clearance_id, 'cleared', 'system', $uid, 'System (Auto-Clear)', "Auto-cleared: balance fully paid. Certificate: {$cert_number}. Amount: MWK " . number_format($total_approved, 2));
                
                // Email student
                notifyStudentCleared($conn, $student['email'], $student['full_name'], $cert_number, 'System (Auto-Clearance)');
                
                $success = "Payment approved. Student AUTO-CLEARED — balance is fully paid! Certificate: $cert_number";
            }
            
            // Reload
            $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
            $stmt2->bind_param("i", $clearance_id);
            $stmt2->execute();
            $student = $stmt2->get_result()->fetch_assoc();
            
            $pstmt2 = $conn->prepare("SELECT ecp.*, u.username as reviewer_name FROM exam_clearance_payments ecp LEFT JOIN users u ON ecp.reviewed_by = u.user_id WHERE ecp.clearance_id = ? ORDER BY ecp.submitted_at DESC");
            $pstmt2->bind_param("i", $clearance_id);
            $pstmt2->execute();
            $payments = $pstmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
    
    // Clear student
    if ($_POST['action'] === 'clear_student') {
        $finance_notes = trim($_POST['finance_notes'] ?? '');
        $uid = (int)$user['user_id'];
        
        // Calculate total approved payments
        $sum_rs = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM exam_clearance_payments WHERE clearance_id = $clearance_id AND status = 'approved'");
        $clear_amount_paid = (float)($sum_rs->fetch_assoc()['total'] ?? 0);
        
        // Generate certificate number: EC-YEAR-XXXXX
        $year = date('Y');
        $clearance_type = $student['clearance_type'] ?? 'endsemester';
        $prefix = ($clearance_type === 'midsemester') ? 'ECM' : 'EC';
        $cnt_rs = $conn->query("SELECT COUNT(*) as cnt FROM exam_clearance_students WHERE certificate_number IS NOT NULL AND certificate_number LIKE '{$prefix}-$year%'");
        $cnt = ($cnt_rs->fetch_assoc()['cnt'] ?? 0) + 1;
        $cert_number = $prefix . '-' . $year . '-' . str_pad($cnt, 5, '0', STR_PAD_LEFT);
        
        $stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'cleared', cleared_by = ?, cleared_at = NOW(), certificate_number = ?, finance_notes = ?, amount_paid = ? WHERE clearance_id = ?");
        $stmt->bind_param("issdi", $uid, $cert_number, $finance_notes, $clear_amount_paid, $clearance_id);
        
        if ($stmt->execute()) {
            // Record revenue in payment_transactions (for finance reports)
            $rev_check = $conn->query("SELECT revenue_recorded FROM exam_clearance_students WHERE clearance_id = $clearance_id");
            $already_recorded = (int)($rev_check->fetch_assoc()['revenue_recorded'] ?? 0);
            
            if (!$already_recorded && $clear_amount_paid > 0) {
                // Split revenue: registration fee + tuition
                $reg_fee = (float)($student['registration_fee'] ?? 0);
                $tuition_paid = $clear_amount_paid - $reg_fee;
                if ($tuition_paid < 0) { $tuition_paid = 0; $reg_fee = $clear_amount_paid; }
                
                $today = date('Y-m-d');
                $sid = $student['student_id'];
                $ctype_label = ($clearance_type === 'midsemester') ? 'Mid-Semester' : 'End-Semester';
                
                if ($tuition_paid > 0) {
                    $rev_stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_tuition', ?, ?, ?, ?, ?)");
                    $desc = "Exam Clearance Tuition ({$ctype_label}) - {$student['full_name']}";
                    $recorded_by = $user['username'] ?? 'system';
                    $rev_stmt->bind_param("sdssss", $sid, $tuition_paid, $today, $desc, $cert_number, $recorded_by);
                    $rev_stmt->execute();
                }
                if ($reg_fee > 0) {
                    $rev_stmt2 = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_date, notes, reference_number, recorded_by) VALUES (?, 'exam_clearance_registration', ?, ?, ?, ?, ?)");
                    $desc2 = "Exam Clearance Registration Fee ({$ctype_label}) - {$student['full_name']}";
                    $recorded_by2 = $user['username'] ?? 'system';
                    $rev_stmt2->bind_param("sdssss", $sid, $reg_fee, $today, $desc2, $cert_number, $recorded_by2);
                    $rev_stmt2->execute();
                }
                
                $conn->query("UPDATE exam_clearance_students SET revenue_recorded = 1 WHERE clearance_id = $clearance_id");
            }
            
            $success = "Student cleared for examinations! Certificate Number: $cert_number. Amount recorded: MWK " . number_format($clear_amount_paid, 2);
            $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
            $stmt2->bind_param("i", $clearance_id);
            $stmt2->execute();
            $student = $stmt2->get_result()->fetch_assoc();
            
            // Log activity
            logExamClearanceActivity($conn, $clearance_id, 'cleared', 'finance', $uid, $user['full_name'] ?? $user['username'], "Student cleared. Certificate: {$cert_number}. Amount Paid: MWK " . number_format($clear_amount_paid, 2) . ($finance_notes ? ". Notes: {$finance_notes}" : ''));
            
            // Email student
            notifyStudentCleared($conn, $student['email'], $student['full_name'], $cert_number, $user['full_name'] ?? $user['username']);
        } else {
            $error = 'Failed to clear student.';
        }
    }
    
    // Reject student
    if ($_POST['action'] === 'reject_student') {
        $finance_notes = trim($_POST['finance_notes'] ?? '');
        $uid = (int)$user['user_id'];
        
        $stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'rejected', cleared_by = ?, finance_notes = ? WHERE clearance_id = ?");
        $stmt->bind_param("isi", $uid, $finance_notes, $clearance_id);
        $stmt->execute();
        $success = 'Student exam clearance rejected.';
        
        // Log activity
        logExamClearanceActivity($conn, $clearance_id, 'rejected', 'finance', $uid, $user['full_name'] ?? $user['username'], "Clearance rejected." . ($finance_notes ? " Reason: {$finance_notes}" : ''));
        
        // Email student
        notifyStudentRejected($conn, $student['email'], $student['full_name'], $finance_notes);
        
        $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt2->bind_param("i", $clearance_id);
        $stmt2->execute();
        $student = $stmt2->get_result()->fetch_assoc();
    }
    
    // Request proof of payment from student
    if ($_POST['action'] === 'request_proof') {
        $finance_notes = trim($_POST['finance_notes'] ?? '');
        $proof_request_type = $_POST['proof_request_type'] ?? 'both';
        $required_amount = !empty($_POST['required_amount']) ? (float)$_POST['required_amount'] : null;
        $uid = (int)$user['user_id'];
        
        if (!in_array($proof_request_type, ['tuition', 'registration', 'both'])) {
            $proof_request_type = 'both';
        }
        
        $stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'proof_requested', finance_notes = ?, proof_request_type = ?, required_amount = ? WHERE clearance_id = ?");
        $stmt->bind_param("ssdi", $finance_notes, $proof_request_type, $required_amount, $clearance_id);
        $stmt->execute();
        
        $type_labels = ['tuition' => 'Tuition Fee', 'registration' => 'Registration Fee', 'both' => 'Tuition & Registration Fees'];
        $type_label = $type_labels[$proof_request_type] ?? 'Payment';
        $success = "Proof of payment requested from student for: {$type_label}" . ($required_amount ? " (MWK " . number_format($required_amount, 2) . ")" : "") . ".";
        
        // Log activity
        logExamClearanceActivity($conn, $clearance_id, 'proof_requested', 'finance', $uid, $user['full_name'] ?? $user['username'], "Requested {$type_label} proof." . ($required_amount ? " Required: MWK " . number_format($required_amount, 2) : '') . ($finance_notes ? " Notes: {$finance_notes}" : ''));
        
        // Email student
        notifyStudentProofRequested($conn, $student['email'], $student['full_name'], $finance_notes);
        
        $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt2->bind_param("i", $clearance_id);
        $stmt2->execute();
        $student = $stmt2->get_result()->fetch_assoc();
    }
    
    // Convert external student to full system student
    if ($_POST['action'] === 'convert_to_student') {
        // Ensure converted_to_student column exists
        $col_check = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_student'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_student TINYINT(1) DEFAULT 0 AFTER is_system_student");
            $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_at DATETIME DEFAULT NULL AFTER converted_to_student");
        }
        
        if (!empty($student['converted_to_student'])) {
            $error = 'This student has already been converted to a full system student.';
        } else {
            // Check if student_id already exists in students table
            $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $check_stmt->bind_param("s", $student['student_id']);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = 'A student with ID ' . htmlspecialchars($student['student_id']) . ' already exists in the system.';
            } else {
                // Insert into students table
                $ins = $conn->prepare("INSERT INTO students (student_id, full_name, email, phone, program, department, campus, year_of_study, gender, national_id, address, entry_type, semester, year_of_registration, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $ins->bind_param("sssssssisssssi",
                    $student['student_id'],
                    $student['full_name'],
                    $student['email'],
                    $student['phone'],
                    $student['program'],
                    $student['department'],
                    $student['campus'],
                    $student['year_of_study'],
                    $student['gender'],
                    $student['national_id'],
                    $student['address'],
                    $student['entry_type'],
                    $student['semester'],
                    $student['year_of_registration']
                );
                
                if ($ins->execute()) {
                    $new_student_id = $student['student_id'];
                    
                    // Update the user account role from exam_clearance_student to student
                    $conn->query("UPDATE users SET role = 'student', related_student_id = '" . $conn->real_escape_string($new_student_id) . "' WHERE email = '" . $conn->real_escape_string($student['email']) . "' AND role = 'exam_clearance_student'");
                    
                    // Mark as converted
                    $conn->query("UPDATE exam_clearance_students SET converted_to_student = 1, converted_at = NOW(), is_system_student = 1 WHERE clearance_id = $clearance_id");
                    
                    $success = 'Student successfully converted to full system student! They can now access all student features.';
                    
                    // Reload
                    $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
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

$page_title = 'Review Exam Clearance: ' . $student['full_name'];
$breadcrumbs = [
    ['title' => 'Exam Clearance Students', 'url' => 'exam_clearance_students.php'],
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
                        <tr><td class="text-muted">Clearance Type</td><td><span class="badge bg-<?= ($student['clearance_type'] ?? 'endsemester') === 'midsemester' ? 'warning text-dark' : 'success' ?>"><?= ($student['clearance_type'] ?? 'endsemester') === 'midsemester' ? 'Mid-Semester (50%)' : 'End-of-Semester (100%)' ?></span></td></tr>
                        <tr><td class="text-muted">Department</td><td><?= htmlspecialchars($student['department'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Campus</td><td><?= htmlspecialchars($student['campus'] ?: '—') ?></td></tr>
                        <tr><td class="text-muted">Year</td><td>Year <?= $student['year_of_study'] ?></td></tr>
                        <tr><td class="text-muted">Source</td><td>
                            <?php if ($student['is_system_student']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>System Student</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">External</span>
                            <?php endif; ?>
                        </td></tr>
                        <tr><td class="text-muted">Applied</td><td><?= date('M j, Y H:i', strtotime($student['registered_at'])) ?></td></tr>
                        <tr><td class="text-muted">Status</td><td>
                            <?php $sc = ['registered'=>'secondary','invoiced'=>'info','proof_submitted'=>'warning','proof_requested'=>'info','cleared'=>'success','rejected'=>'danger']; ?>
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
                    <?php 
                    $ctype = $student['clearance_type'] ?? 'endsemester';
                    $min_pct = ($ctype === 'midsemester') ? 50 : 100;
                    $min_required = $student['invoiced_amount'] * ($min_pct / 100);
                    ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Clearance Type:</span>
                        <span class="badge bg-<?= $ctype === 'midsemester' ? 'warning text-dark' : 'success' ?>"><?= $ctype === 'midsemester' ? 'Mid-Semester (50%)' : 'End-of-Semester (100%)' ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Invoiced Amount:</span>
                        <strong>MWK <?= number_format($student['invoiced_amount'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Registration Fee:</span>
                        <strong>MWK <?= number_format($student['registration_fee'] ?? 0, 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Minimum Required (<?= $min_pct ?>%):</span>
                        <strong class="text-primary">MWK <?= number_format($min_required, 2) ?></strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Approved Payments:</span>
                        <strong class="text-success">MWK <?= number_format($total_approved, 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Pending Payments:</span>
                        <strong class="text-warning">MWK <?= number_format($total_pending, 2) ?></strong>
                    </div>
                    <?php if (!empty($student['amount_paid']) && $student['amount_paid'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Recorded Payment:</span>
                        <strong class="text-info">MWK <?= number_format($student['amount_paid'], 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold">Outstanding Balance:</span>
                        <strong class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?> fs-5">MWK <?= number_format($balance, 2) ?></strong>
                    </div>
                    <?php 
                    $shortfall = $min_required - $total_approved;
                    if ($shortfall > 0): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-bold">Still Needed for Clearance:</span>
                            <strong class="text-danger">MWK <?= number_format($shortfall, 2) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student['required_amount'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Finance Required Amount:</span>
                        <strong class="text-danger">MWK <?= number_format($student['required_amount'], 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($total_approved >= $min_required): ?>
                        <div class="alert alert-success mt-3 mb-0 py-2"><i class="bi bi-check-circle me-2"></i>Meets <?= $min_pct ?>% payment requirement - eligible for <?= $ctype === 'midsemester' ? 'mid-semester' : 'end-of-semester' ?> exam clearance.</div>
                    <?php elseif ($balance <= 0): ?>
                        <div class="alert alert-success mt-3 mb-0 py-2"><i class="bi bi-check-circle me-2"></i>No outstanding balance - eligible for exam clearance.</div>
                    <?php else: ?>
                        <div class="alert alert-warning mt-3 mb-0 py-2"><i class="bi bi-exclamation-triangle me-2"></i>Student has not met the <?= $min_pct ?>% payment requirement yet (MWK <?= number_format($shortfall, 2) ?> short).</div>
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
                    <!-- Clear / Reject Form -->
                    <form method="POST" id="clearanceForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Finance Notes</label>
                            <textarea name="finance_notes" class="form-control" rows="3" placeholder="Notes about the clearance decision..."><?= htmlspecialchars($student['finance_notes'] ?? '') ?></textarea>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="clear_student" class="btn btn-success" onclick="return confirm('Clear this student for examinations? Amount paid (MWK <?= number_format($total_approved, 2) ?>) will be recorded and added to revenue.')"><i class="bi bi-check-circle me-2"></i>Clear for Examinations (Record MWK <?= number_format($total_approved, 2) ?>)</button>
                            <button type="submit" name="action" value="reject_student" class="btn btn-outline-danger" onclick="return confirm('Reject this student\'s exam clearance?')"><i class="bi bi-x-circle me-2"></i>Reject Clearance</button>
                        </div>
                    </form>
                    
                    <hr>
                    
                    <!-- Request Proof Form (separate) -->
                    <h6 class="fw-bold text-warning"><i class="bi bi-receipt me-2"></i>Request Proof of Payment</h6>
                    <form method="POST" id="proofRequestForm">
                        <input type="hidden" name="action" value="request_proof">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Proof Type Required</label>
                            <select name="proof_request_type" class="form-select" id="proofType">
                                <option value="both" <?= ($student['proof_request_type'] ?? '') === 'both' ? 'selected' : '' ?>>Both Tuition & Registration Fee</option>
                                <option value="tuition" <?= ($student['proof_request_type'] ?? '') === 'tuition' ? 'selected' : '' ?>>Tuition Fee Only</option>
                                <option value="registration" <?= ($student['proof_request_type'] ?? '') === 'registration' ? 'selected' : '' ?>>Registration Fee Only</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Required Amount (MWK)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="required_amount" id="requiredAmount" class="form-control" value="<?= htmlspecialchars($student['required_amount'] ?? '') ?>" placeholder="Enter exact amount required">
                                <button type="button" class="btn btn-outline-secondary" id="calcBalance" title="Auto-calculate balance"><i class="bi bi-calculator"></i> Calculate</button>
                            </div>
                            <small class="text-muted">Leave blank for no specific amount, or click Calculate to auto-fill the outstanding balance.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notes to Student</label>
                            <textarea name="finance_notes" class="form-control" rows="2" placeholder="Explain what payment proof is needed..."><?= htmlspecialchars($student['finance_notes'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-warning w-100" onclick="return confirm('Request proof of payment from this student?')"><i class="bi bi-receipt me-2"></i>Send Proof Request</button>
                    </form>
                </div>
            </div>
            
            <script>
            document.getElementById('calcBalance')?.addEventListener('click', function() {
                var invoiced = <?= (float)$student['invoiced_amount'] ?>;
                var regFee = <?= (float)($student['registration_fee'] ?? 0) ?>;
                var approved = <?= (float)$total_approved ?>;
                var minPct = <?= $min_pct ?>;
                var proofType = document.getElementById('proofType').value;
                var required = 0;
                
                if (proofType === 'tuition') {
                    var tuitionTotal = invoiced - regFee;
                    required = Math.max(0, (tuitionTotal * minPct / 100) - Math.max(0, approved - regFee));
                } else if (proofType === 'registration') {
                    required = Math.max(0, regFee - Math.min(approved, regFee));
                } else {
                    required = Math.max(0, (invoiced * minPct / 100) - approved);
                }
                
                document.getElementById('requiredAmount').value = required.toFixed(2);
            });
            </script>
            <?php else: ?>
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-body text-center">
                    <div class="text-success mb-2"><i class="bi bi-patch-check-fill" style="font-size:3rem"></i></div>
                    <h5 class="text-success">Cleared for Examinations</h5>
                    <p class="text-muted">Certificate: <strong><?= htmlspecialchars($student['certificate_number']) ?></strong></p>
                    <p class="text-muted small">Cleared on <?= date('M j, Y H:i', strtotime($student['cleared_at'])) ?></p>
                    <a href="exam_clearance_certificate.php?id=<?= $student['clearance_id'] ?>" class="btn btn-success"><i class="bi bi-printer me-2"></i>Print Certificate</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Convert to Full Student (external students only) -->
            <?php if (!$student['is_system_student'] || (isset($student['converted_to_student']) && !$student['converted_to_student'])): ?>
            <?php if (empty($student['converted_to_student'])): ?>
            <div class="card shadow-sm border-0 mt-3 border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Convert to System Student</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Convert this external exam clearance student to a full system student. This will create a record in the students table and update their user role to <code>student</code>.</p>
                    <form method="POST" onsubmit="return confirm('Convert this student to a full system student? This action cannot be undone.')">
                        <input type="hidden" name="action" value="convert_to_student">
                        <button type="submit" class="btn btn-info w-100"><i class="bi bi-arrow-repeat me-2"></i>Convert to Full Student</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-body text-center py-3">
                    <span class="badge bg-info"><i class="bi bi-check-circle me-1"></i>Converted to System Student</span>
                    <?php if (!empty($student['converted_at'])): ?>
                    <p class="small text-muted mt-1 mb-0">on <?= date('M j, Y H:i', strtotime($student['converted_at'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
                                    $proof_path = '../uploads/exam_clearance_payments/' . $pay['proof_file'];
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
