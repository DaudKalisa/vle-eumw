<?php
/**
 * Exam Clearance - External Student Join Page
 * External students use the invite link to register for exam clearance.
 * They fill their details, get invoiced, upload proof of payment,
 * and a user account with role 'exam_clearance_student' is created.
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$token = $_GET['token'] ?? '';
$success = '';
$error = '';
$invite = null;
$student = null;
$step = 'register'; // register, upload_proof, done

// Validate invite token
if (empty($token)) {
    $error = 'Invalid or missing invite link. Please contact the Finance Office for a valid link.';
} else {
    $stmt = $conn->prepare("SELECT * FROM exam_clearance_invites WHERE invite_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();
    
    if (!$invite) {
        $error = 'Invalid invite link. This link does not exist.';
    } elseif (!$invite['is_active']) {
        $error = 'This invite link has been disabled by the Finance Office.';
    } elseif ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
        $error = 'This invite link has expired. Please request a new one from the Finance Office.';
    } elseif ($invite['max_uses'] > 0 && $invite['times_used'] >= $invite['max_uses']) {
        $error = 'This invite link has reached its maximum number of uses.';
    }
}

// Get fee settings for invoicing
$fee_settings = null;
$fee_rs = $conn->query("SELECT * FROM fee_settings WHERE id = 1");
if ($fee_rs) $fee_settings = $fee_rs->fetch_assoc();

// Load programs and departments from database
$programs = [];
$departments = [];
$prog_rs = $conn->query("SELECT program_id, program_name, program_code, program_type, department_id FROM programs WHERE is_active = 1 ORDER BY program_name");
if ($prog_rs) {
    while ($row = $prog_rs->fetch_assoc()) $programs[] = $row;
}
$dept_rs = $conn->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name");
if ($dept_rs) {
    while ($row = $dept_rs->fetch_assoc()) $departments[] = $row;
}

// Check if student already registered with this token or student_id
if ($invite && !$error && isset($_GET['sid'])) {
    $sid = (int)$_GET['sid'];
    $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    if ($student) {
        $step = ($student['status'] === 'proof_submitted' || $student['status'] === 'cleared') ? 'done' : 'upload_proof';
    }
}

// STEP 1: Register student
if ($invite && !$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'register') {
        $student_id = trim($_POST['student_id'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $program_id = (int)($_POST['program_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $campus = trim($_POST['campus'] ?? '');
        $year_of_study = (int)($_POST['year_of_study'] ?? 1);
        $gender = trim($_POST['gender'] ?? '');
        $national_id = trim($_POST['national_id'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $entry_type = trim($_POST['entry_type'] ?? 'NE');
        $semester = trim($_POST['semester'] ?? 'One');
        
        // Resolve program & department names from IDs
        $program = '';
        $department = '';
        $program_type_from_db = null;
        if ($program_id > 0) {
            $p_stmt = $conn->prepare("SELECT program_name, program_type FROM programs WHERE program_id = ?");
            $p_stmt->bind_param("i", $program_id);
            $p_stmt->execute();
            $p_row = $p_stmt->get_result()->fetch_assoc();
            if ($p_row) {
                $program = $p_row['program_name'];
                $program_type_from_db = $p_row['program_type'];
            }
        }
        if ($department_id > 0) {
            $d_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $d_stmt->bind_param("i", $department_id);
            $d_stmt->execute();
            $d_row = $d_stmt->get_result()->fetch_assoc();
            if ($d_row) $department = $d_row['department_name'];
        }
        
        if (empty($student_id) || empty($full_name) || empty($email)) {
            $error = 'Student ID, Full Name, and Email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check if already registered
            $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE student_id = ? AND invite_token = ?");
            $stmt->bind_param("ss", $student_id, $token);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if ($existing) {
                $student = $existing;
                $step = 'upload_proof';
                $error = 'You have already registered. Please upload your proof of payment below.';
            } else {
                // Calculate invoice amount based on program type
                $program_type = $invite['program_type'];
                $invoiced_amount = 0;
                
                if ($fee_settings) {
                    switch ($program_type) {
                        case 'masters':
                            $invoiced_amount = (float)($fee_settings['tuition_masters'] ?? 1100000);
                            break;
                        case 'doctorate':
                            $invoiced_amount = (float)($fee_settings['tuition_doctorate'] ?? 2200000);
                            break;
                        default: // degree
                            $invoiced_amount = (float)($fee_settings['tuition_degree'] ?? 500000);
                            break;
                    }
                } else {
                    $invoiced_amount = $program_type === 'masters' ? 1100000 : ($program_type === 'doctorate' ? 2200000 : 500000);
                }
                
                $stmt = $conn->prepare("INSERT INTO exam_clearance_students (student_id, full_name, email, phone, program, program_id, program_type, department, department_id, campus, year_of_study, gender, national_id, address, entry_type, semester, year_of_registration, invite_token, invoiced_amount, balance, status, is_system_student) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'invoiced', 0)");
                $balance = $invoiced_amount;
                $current_year = date('Y');
                $stmt->bind_param("sssssississsssssisdd", $student_id, $full_name, $email, $phone, $program, $program_id, $program_type, $department, $department_id, $campus, $year_of_study, $gender, $national_id, $address, $entry_type, $semester, $current_year, $token, $invoiced_amount, $balance);
                
                if ($stmt->execute()) {
                    $clearance_id = $conn->insert_id;
                    
                    // Increment invite uses
                    $conn->query("UPDATE exam_clearance_invites SET times_used = times_used + 1 WHERE invite_id = " . (int)$invite['invite_id']);
                    
                    // Create a user account with role 'exam_clearance_student'
                    // Check if user already exists with this email or username
                    $username = strtolower(str_replace(' ', '.', $full_name)) . '.' . substr(md5($email . time()), 0, 4);
                    $check_user = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
                    $check_user->bind_param("ss", $email, $username);
                    $check_user->execute();
                    $existing_user = $check_user->get_result()->fetch_assoc();
                    
                    if (!$existing_user) {
                        // Create user account - default password is the student_id
                        $default_password = password_hash($student_id, PASSWORD_DEFAULT);
                        $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'exam_clearance_student')");
                        $user_stmt->bind_param("sss", $username, $email, $default_password);
                        $user_stmt->execute();
                    }
                    
                    // Load the student record
                    $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
                    $stmt2->bind_param("i", $clearance_id);
                    $stmt2->execute();
                    $student = $stmt2->get_result()->fetch_assoc();
                    
                    $step = 'upload_proof';
                    $success = 'Registration successful! You have been invoiced <strong>MWK ' . number_format($invoiced_amount, 2) . '</strong> for ' . ucfirst($program_type) . ' program. Please upload your proof of payment below.';
                    if (!$existing_user) {
                        $success .= '<br><br><div class="alert alert-info mb-0 mt-2"><strong>Your Login Details:</strong><br>Username: <code>' . htmlspecialchars($username) . '</code><br>Password: <code>' . htmlspecialchars($student_id) . '</code><br>You can log in at the VLE portal to track your clearance status.</div>';
                    }
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
    
    // STEP 2: Upload proof of payment
    if ($_POST['action'] === 'upload_proof') {
        $clearance_id = (int)$_POST['clearance_id'];
        $amount = (float)$_POST['amount'];
        $payment_reference = trim($_POST['payment_reference'] ?? '');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Load student
        $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt->bind_param("i", $clearance_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        
        if (!$student) {
            $error = 'Student record not found.';
        } elseif ($amount <= 0) {
            $error = 'Please enter a valid payment amount.';
            $step = 'upload_proof';
        } else {
            // Handle file upload
            $proof_file = null;
            if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                if (!in_array($_FILES['proof_file']['type'], $allowed_types)) {
                    $error = 'Invalid file type. Only JPG, PNG, and PDF are allowed.';
                    $step = 'upload_proof';
                } elseif ($_FILES['proof_file']['size'] > $max_size) {
                    $error = 'File too large. Maximum size is 5MB.';
                    $step = 'upload_proof';
                } else {
                    $upload_dir = 'uploads/exam_clearance_payments/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $ext = pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION);
                    $proof_file = 'exam_clearance_' . $student['student_id'] . '_' . time() . '.' . $ext;
                    
                    if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_dir . $proof_file)) {
                        $error = 'Failed to upload file. Please try again.';
                        $proof_file = null;
                        $step = 'upload_proof';
                    }
                }
            } else {
                $error = 'Please upload proof of payment.';
                $step = 'upload_proof';
            }
            
            if ($proof_file && !$error) {
                // Save payment record
                $stmt = $conn->prepare("INSERT INTO exam_clearance_payments (clearance_id, amount, payment_reference, payment_date, bank_name, proof_file, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("idsssss", $clearance_id, $amount, $payment_reference, $payment_date, $bank_name, $proof_file, $notes);
                
                if ($stmt->execute()) {
                    // Update student record
                    $conn->query("UPDATE exam_clearance_students SET status = 'proof_submitted', proof_of_payment = '" . $conn->real_escape_string($proof_file) . "', payment_reference = '" . $conn->real_escape_string($payment_reference) . "', amount_claimed = $amount WHERE clearance_id = $clearance_id");
                    
                    $step = 'done';
                    $success = 'Your proof of payment has been submitted successfully! The Finance Office will review it and process your exam clearance.';
                    
                    // Reload student
                    $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
                    $stmt2->bind_param("i", $clearance_id);
                    $stmt2->execute();
                    $student = $stmt2->get_result()->fetch_assoc();
                } else {
                    $error = 'Failed to save payment. Please try again.';
                    $step = 'upload_proof';
                }
            }
        }
    }
}

// Get university settings
$university_name = "Eastern University of Malawi and the World";
$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $settings = $settings_query->fetch_assoc();
    $university_name = $settings['university_name'] ?? $university_name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Clearance Registration - <?= htmlspecialchars($university_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #10b981 0%, #059669 100%); min-height: 100vh; }
        .clearance-card { max-width: 650px; margin: 2rem auto; background: #fff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden; }
        .card-top { background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 1.5rem 2rem; text-align: center; }
        .card-top img { height: 60px; margin-bottom: 0.5rem; }
        .card-body-content { padding: 2rem; }
        .step-badge { display: inline-block; background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; margin-bottom: 0.5rem; }
        .invoice-box { background: #f8f9fa; border: 2px dashed #10b981; border-radius: 12px; padding: 1.25rem; margin: 1rem 0; text-align: center; }
        .invoice-amount { font-size: 2rem; font-weight: 700; color: #059669; }
        .status-done { text-align: center; padding: 2rem; }
        .status-done .check-icon { font-size: 4rem; color: #10b981; }
    </style>
</head>
<body>

<div class="clearance-card">
    <div class="card-top">
        <img src="assets/img/Logo.png" alt="Logo" onerror="this.style.display='none'">
        <h4 class="mb-1"><?= htmlspecialchars($university_name) ?></h4>
        <p class="mb-0 opacity-75">Examination Clearance Portal</p>
        <?php if ($invite): ?>
            <span class="step-badge"><i class="bi bi-shield-check me-1"></i><?= ucfirst($invite['program_type']) ?> Program</span>
        <?php endif; ?>
    </div>
    
    <div class="card-body-content">
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error && !$invite): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-outline-primary">Back to Login</a>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($invite && !$error || ($error && $invite)): ?>
        
            <?php if ($step === 'register'): ?>
                <!-- STEP 1: Registration Form -->
                <h5 class="mb-3"><i class="bi bi-1-circle-fill text-success me-2"></i>Student Registration</h5>
                <p class="text-muted small">Fill in your details to register for examination clearance. You will be invoiced based on the <strong><?= ucfirst($invite['program_type']) ?></strong> fee structure. A login account will be created for you automatically.</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    
                    <h6 class="text-muted mb-2"><i class="bi bi-person me-1"></i>Personal Details</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Student ID / Reg Number <span class="text-danger">*</span></label>
                            <input type="text" name="student_id" class="form-control" required placeholder="e.g. BSC-2023-001">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required placeholder="First Last">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">National ID</label>
                            <input type="text" name="national_id" class="form-control" placeholder="ID number">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+265...">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="student@email.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Physical Address</label>
                            <input type="text" name="address" class="form-control" placeholder="e.g. Area 47, Lilongwe">
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="text-muted mb-2"><i class="bi bi-mortarboard me-1"></i>Academic Details</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                            <select name="program_id" class="form-select" required id="programSelect">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                    <option value="<?= $prog['program_id'] ?>" data-dept="<?= $prog['department_id'] ?>" data-type="<?= htmlspecialchars($prog['program_type']) ?>">
                                        <?= htmlspecialchars($prog['program_name']) ?> (<?= htmlspecialchars($prog['program_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                            <select name="department_id" class="form-select" required id="departmentSelect">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Campus</label>
                            <select name="campus" class="form-select">
                                <option value="">Select Campus</option>
                                <option value="Main Campus">Main Campus</option>
                                <option value="Blantyre Campus">Blantyre Campus</option>
                                <option value="Lilongwe Campus">Lilongwe Campus</option>
                                <option value="Mzuzu Campus">Mzuzu Campus</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Year of Study</label>
                            <select name="year_of_study" class="form-select">
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                                <option value="5">Year 5</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Semester</label>
                            <select name="semester" class="form-select">
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Entry Type</label>
                            <select name="entry_type" class="form-select">
                                <option value="NE">New Entry (NE)</option>
                                <option value="ME">Mature Entry (ME)</option>
                                <option value="ODL">Open Distance Learning (ODL)</option>
                                <option value="PC">Prior Credit (PC)</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-lg w-100 mt-2"><i class="bi bi-person-plus me-2"></i>Register & Get Invoice</button>
                </form>
                
                <script>
                // Auto-select department when program is chosen
                document.getElementById('programSelect').addEventListener('change', function() {
                    const selected = this.options[this.selectedIndex];
                    const deptId = selected.getAttribute('data-dept');
                    if (deptId) {
                        document.getElementById('departmentSelect').value = deptId;
                    }
                });
                </script>
                
            <?php elseif ($step === 'upload_proof' && $student): ?>
                <!-- STEP 2: Invoice & Upload Proof -->
                <h5 class="mb-3"><i class="bi bi-2-circle-fill text-success me-2"></i>Invoice & Payment Proof</h5>
                
                <div class="invoice-box">
                    <p class="mb-1 text-muted">Your Invoice Amount (<?= ucfirst($student['program_type']) ?>)</p>
                    <div class="invoice-amount">MWK <?= number_format($student['invoiced_amount'], 2) ?></div>
                    <p class="mb-0 small text-muted">Student: <?= htmlspecialchars($student['full_name']) ?> (<?= htmlspecialchars($student['student_id']) ?>)</p>
                    <?php if ($student['balance'] > 0): ?>
                        <span class="badge bg-danger mt-2">Balance: MWK <?= number_format($student['balance'], 2) ?></span>
                    <?php else: ?>
                        <span class="badge bg-success mt-2">Fully Paid</span>
                    <?php endif; ?>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_proof">
                    <input type="hidden" name="clearance_id" value="<?= $student['clearance_id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Amount Paid (MWK) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" required min="1" step="0.01" placeholder="e.g. 500000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Payment Reference <span class="text-danger">*</span></label>
                            <input type="text" name="payment_reference" class="form-control" required placeholder="Transaction/Receipt No.">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g. National Bank">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Proof of Payment <span class="text-danger">*</span></label>
                        <input type="file" name="proof_file" class="form-control" required accept=".jpg,.jpeg,.png,.pdf">
                        <small class="text-muted">Upload bank slip, receipt, or screenshot (JPG, PNG, PDF. Max 5MB)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-lg w-100"><i class="bi bi-upload me-2"></i>Submit Proof of Payment</button>
                </form>
                
            <?php elseif ($step === 'done' && $student): ?>
                <!-- STEP 3: Confirmation -->
                <div class="status-done">
                    <?php if ($student['status'] === 'cleared'): ?>
                        <div class="check-icon"><i class="bi bi-patch-check-fill"></i></div>
                        <h4 class="mt-3 text-success">Examination Clearance Approved!</h4>
                        <p class="text-muted">Your exam clearance has been approved. You are cleared to sit for examinations.</p>
                        <?php if ($student['certificate_number']): ?>
                            <div class="alert alert-success">Certificate No: <strong><?= htmlspecialchars($student['certificate_number']) ?></strong></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="check-icon"><i class="bi bi-hourglass-split text-warning"></i></div>
                        <h4 class="mt-3">Proof Submitted - Under Review</h4>
                        <p class="text-muted">Your proof of payment has been submitted. The Finance Office will review and process your clearance. Check back later for updates.</p>
                        <div class="alert alert-info">
                            <strong>Student:</strong> <?= htmlspecialchars($student['full_name']) ?> (<?= htmlspecialchars($student['student_id']) ?>)<br>
                            <strong>Amount Claimed:</strong> MWK <?= number_format($student['amount_claimed'], 2) ?><br>
                            <strong>Status:</strong> <span class="badge bg-warning text-dark">Pending Review</span>
                        </div>
                        <div class="mt-3">
                            <a href="login.php" class="btn btn-outline-primary"><i class="bi bi-box-arrow-in-right me-2"></i>Login to Track Status</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
