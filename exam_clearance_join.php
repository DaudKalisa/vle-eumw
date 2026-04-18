<?php
/**
 * Exam Clearance - External Student Join Page
 * External students use the invite link to register for exam clearance.
 * They fill their details, get invoiced, upload proof of payment,
 * and a user account with role 'exam_clearance_student' is created.
 * Works for all program types: degree, masters, doctorate, diploma, etc.
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
if (!$error) {
    $prog_rs = $conn->query("SELECT p.program_id, p.program_name, p.program_code, p.program_type, p.department_id,
        d.department_name, d.department_code
        FROM programs p
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.is_active = 1
        ORDER BY p.program_name");
    if ($prog_rs) {
        while ($row = $prog_rs->fetch_assoc()) $programs[] = $row;
    }
    $dept_rs = $conn->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name");
    if ($dept_rs) {
        while ($row = $dept_rs->fetch_assoc()) $departments[] = $row;
    }
}

// Check if student already registered with this token
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
        $firstname = trim($_POST['firstname'] ?? '');
        $other_names = trim($_POST['other_names'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $full_name = trim($firstname . ' ' . $other_names . ' ' . $surname);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $program_id = (int)($_POST['program_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $campus = trim($_POST['campus'] ?? 'Mzuzu Campus');
        $year_of_study = (int)($_POST['year_of_study'] ?? 1);
        $gender = trim($_POST['gender'] ?? '');
        $national_id = strtoupper(trim($_POST['national_id'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $entry_type = trim($_POST['entry_type'] ?? 'NE');
        $semester = trim($_POST['semester'] ?? 'One');
        $program_type = trim($_POST['program_type'] ?? 'degree');
        
        // Resolve program & department names from IDs
        $program = '';
        $department = '';
        if ($program_id > 0) {
            $p_stmt = $conn->prepare("SELECT program_name, program_type FROM programs WHERE program_id = ?");
            $p_stmt->bind_param("i", $program_id);
            $p_stmt->execute();
            $p_row = $p_stmt->get_result()->fetch_assoc();
            if ($p_row) {
                $program = $p_row['program_name'];
                $program_type = $p_row['program_type'] ?: $program_type;
            }
        }
        if ($department_id > 0) {
            $d_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $d_stmt->bind_param("i", $department_id);
            $d_stmt->execute();
            $d_row = $d_stmt->get_result()->fetch_assoc();
            if ($d_row) $department = $d_row['department_name'];
        }
        
        if (empty($student_id) || empty($firstname) || empty($surname) || empty($email)) {
            $error = 'Student ID, Firstname, Surname, and Email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
            $error = 'Please select your gender.';
        } elseif ($department_id <= 0) {
            $error = 'Please select a valid department.';
        } elseif (empty($program)) {
            $error = 'Please select a program.';
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
                // Check if email already used for this invite
                $email_check = $conn->prepare("SELECT clearance_id FROM exam_clearance_students WHERE email = ? AND invite_token = ?");
                $email_check->bind_param("ss", $email, $token);
                $email_check->execute();
                if ($email_check->get_result()->num_rows > 0) {
                    $error = 'This email has already been used to register with this invite link.';
                } else {
                    // Calculate registration fee based on entry type (NE/ME = new, CE = continuing)
                    $registration_fee = 0;
                    if ($fee_settings) {
                        if ($entry_type === 'CE') {
                            $registration_fee = (float)($fee_settings['continuing_reg_fee'] ?? 35000);
                        } else {
                            $registration_fee = (float)($fee_settings['new_student_reg_fee'] ?? 39500);
                        }
                    } else {
                        $registration_fee = ($entry_type === 'CE') ? 35000 : 39500;
                    }
                    
                    // Calculate tuition based on program type
                    $tuition_amount = 0;
                    if ($fee_settings) {
                        switch ($program_type) {
                            case 'masters':
                                $tuition_amount = (float)($fee_settings['tuition_masters'] ?? 1100000);
                                break;
                            case 'doctorate':
                                $tuition_amount = (float)($fee_settings['tuition_doctorate'] ?? 2200000);
                                break;
                            default:
                                $tuition_amount = (float)($fee_settings['tuition_degree'] ?? 500000);
                                break;
                        }
                    } else {
                        $tuition_amount = $program_type === 'masters' ? 1100000 : ($program_type === 'doctorate' ? 2200000 : 500000);
                    }
                    
                    // Total invoice = tuition + registration fee
                    $invoiced_amount = $tuition_amount + $registration_fee;
                    
                    $stmt = $conn->prepare("INSERT INTO exam_clearance_students (student_id, full_name, email, phone, program, program_id, program_type, department, department_id, campus, year_of_study, gender, national_id, address, entry_type, semester, year_of_registration, invite_token, invoiced_amount, registration_fee, balance, status, is_system_student) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'registered', 0)");
                    $balance = $invoiced_amount;
                    $current_year = date('Y');
                    $stmt->bind_param("sssssississsssssssddd", $student_id, $full_name, $email, $phone, $program, $program_id, $program_type, $department, $department_id, $campus, $year_of_study, $gender, $national_id, $address, $entry_type, $semester, $current_year, $token, $invoiced_amount, $registration_fee, $balance);
                    
                    if ($stmt->execute()) {
                        $clearance_id = $conn->insert_id;
                        
                        // Increment invite uses
                        $conn->query("UPDATE exam_clearance_invites SET times_used = times_used + 1 WHERE invite_id = " . (int)$invite['invite_id']);
                        
                        // Create a user account with role 'exam_clearance_student'
                        $username = strtolower(str_replace(' ', '.', $full_name)) . '.' . substr(md5($email . time()), 0, 4);
                        $check_user = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
                        $check_user->bind_param("ss", $email, $username);
                        $check_user->execute();
                        $existing_user = $check_user->get_result()->fetch_assoc();
                        
                        if (!$existing_user) {
                            $default_password = password_hash('password123', PASSWORD_DEFAULT);
                            $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, 'exam_clearance_student', 0)");
                            $user_stmt->bind_param("sss", $username, $email, $default_password);
                            $user_stmt->execute();
                        }
                        
                        // Load the student record
                        $stmt2 = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
                        $stmt2->bind_param("i", $clearance_id);
                        $stmt2->execute();
                        $student = $stmt2->get_result()->fetch_assoc();
                        
                        $step = 'registration_success';
                        
                        // Send email with login details
                        if (!$existing_user) {
                            require_once 'includes/exam_clearance_helpers.php';
                            $login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/login.php';
                            $reg_fee_label = ($entry_type === 'CE') ? 'Continuing Student' : (($entry_type === 'ME') ? 'Mature Entry' : 'Normal Entry');
                            $email_content = "
                                <p class='greeting'>Dear {$full_name},</p>
                                <p class='content-text'>Your registration for examination clearance has been <strong>submitted successfully</strong> and is pending approval by the administrator.</p>
                                <div class='info-box'>
                                    <h3>Registration Details</h3>
                                    <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>{$student_id}</span></div>
                                    <div class='info-row'><span class='info-label'>Program</span><span class='info-value'>{$program}</span></div>
                                    <div class='info-row'><span class='info-label'>Entry Type</span><span class='info-value'>{$reg_fee_label}</span></div>
                                    <div class='info-row'><span class='info-label'>Registration Fee</span><span class='info-value'>MWK " . number_format($registration_fee, 2) . "</span></div>
                                    <div class='info-row'><span class='info-label'>Tuition Fee</span><span class='info-value'>MWK " . number_format($tuition_amount, 2) . "</span></div>
                                    <div class='info-row'><span class='info-label'>Total Invoiced</span><span class='info-value'>MWK " . number_format($invoiced_amount, 2) . "</span></div>
                                </div>
                                <div class='info-box' style='background:#e8f5e9;border-color:#4caf50;'>
                                    <h3>Your Login Credentials</h3>
                                    <div class='info-row'><span class='info-label'>Username</span><span class='info-value'>{$username}</span></div>
                                    <div class='info-row'><span class='info-label'>Email</span><span class='info-value'>{$email}</span></div>
                                    <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>{$student_id}</span></div>
                                    <div class='info-row'><span class='info-label'>Password</span><span class='info-value'>password123</span></div>
                                </div>
                                <p class='content-text'><strong>You can log in using your Student ID, Email, or Username.</strong></p>
                                <p class='content-text'>Your account will be activated once the administrator approves your registration. You will receive another email when your account is approved.</p>
                                <p class='content-text'>Please change your password after your first login for security.</p>
                            ";
                            sendExamClearanceEmail($email, $full_name, 'Registration Submitted - Exam Clearance', $email_content);
                            
                            // Notify admin/finance of new registration
                            notifyFinanceNewApplication($conn, $full_name, $student_id, $program_type);
                        }
                        
                        $login_details = !$existing_user ? [
                            'username' => $username,
                            'email' => $email,
                            'student_id' => $student_id,
                            'password' => 'password123'
                        ] : null;
                    } else {
                        $error = 'Registration failed. Please try again.';
                    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Clearance Registration - EUMW VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        .reg-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #2563eb 100%);
            color: #fff;
            padding: 40px 0 60px;
            text-align: center;
            position: relative;
        }
        .reg-header::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0; right: 0;
            height: 30px;
            background: var(--bg);
            border-radius: 30px 30px 0 0;
        }
        .reg-header h1 { font-weight: 700; font-size: 1.8rem; margin-bottom: 6px; }
        .reg-header p { opacity: 0.85; font-size: 0.95rem; }
        .reg-card {
            max-width: 820px;
            margin: -20px auto 40px;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 0;
            position: relative;
            z-index: 2;
            overflow: hidden;
        }
        .reg-card .card-body { padding: 36px 40px; }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-label { font-weight: 500; font-size: 0.875rem; color: var(--text); }
        .form-label .text-danger { font-size: 0.85rem; }
        .form-control, .form-select {
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .btn-submit {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 14px 40px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: all 0.3s;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79,70,229,0.4); color: #fff; }
        .success-box {
            text-align: center;
            padding: 60px 40px;
        }
        .success-box .icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #fff;
        }
        .error-box {
            text-align: center;
            padding: 60px 40px;
        }
        .error-box .icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #fff;
        }
        .invite-info {
            background: #f0f4ff;
            border: 1px solid #c7d2fe;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
            font-size: 0.875rem;
        }
        .invite-info strong { color: var(--primary); }
        .footer-text {
            text-align: center;
            padding: 20px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .invoice-box {
            background: #f0fdf4;
            border: 2px dashed var(--success);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .invoice-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #059669;
        }
        @media (max-width: 600px) {
            .reg-card .card-body { padding: 20px; }
            .reg-header { padding: 30px 15px 50px; }
            .reg-header h1 { font-size: 1.4rem; }
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="reg-header">
    <img src="assets/img/Logo.png" alt="EUMW Logo" style="height:50px;margin-bottom:12px;" onerror="this.style.display='none'">
    <h1><i class="bi bi-shield-check me-2"></i>Examination Clearance Registration</h1>
    <p>Exploits University of Malawi &mdash; Virtual Learning Environment</p>
</div>

<div class="reg-card">

    <?php if ($step === 'registration_success'): ?>
        <!-- REGISTRATION SUCCESS PAGE -->
        <div class="success-box">
            <div class="icon"><i class="bi bi-check-circle-fill"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Registered Successfully!</h3>
            <p style="color:var(--text-muted);max-width:560px;margin:0 auto 20px;">
                Your examination clearance registration has been submitted. An administrator will review and approve your registration.
                You will receive an email notification once approved.
            </p>
            
            <!-- Invoice Breakdown -->
            <div class="invoice-box" style="max-width:500px;margin:0 auto 20px;">
                <p class="mb-1" style="color:var(--text-muted);">Invoice Breakdown (<?php echo ucfirst($student['program_type']); ?> &mdash; <?php echo ($student['entry_type'] === 'CE') ? 'Continuing' : (($student['entry_type'] === 'ME') ? 'Mature Entry' : 'Normal Entry'); ?>)</p>
                <table style="width:100%;font-size:0.95rem;margin:10px 0;">
                    <tr><td style="text-align:left;padding:4px 0;">Registration Fee</td><td style="text-align:right;font-weight:600;">MWK <?php echo number_format($student['registration_fee'], 2); ?></td></tr>
                    <tr><td style="text-align:left;padding:4px 0;">Tuition Fee</td><td style="text-align:right;font-weight:600;">MWK <?php echo number_format($student['invoiced_amount'] - $student['registration_fee'], 2); ?></td></tr>
                    <tr style="border-top:2px solid #059669;"><td style="text-align:left;padding:8px 0;font-weight:700;">Total Invoiced</td><td style="text-align:right;"><div class="invoice-amount" style="font-size:1.5rem;">MWK <?php echo number_format($student['invoiced_amount'], 2); ?></div></td></tr>
                </table>
            </div>
            
            <?php if (isset($login_details) && $login_details): ?>
            <!-- Login Credentials -->
            <div style="background:#e8f5e9;border:2px solid #4caf50;border-radius:12px;padding:20px;max-width:500px;margin:0 auto 20px;text-align:left;">
                <h5 style="color:#2e7d32;margin-bottom:12px;text-align:center;"><i class="bi bi-key-fill me-1"></i> Your Login Credentials</h5>
                <table style="width:100%;font-size:0.9rem;">
                    <tr><td style="padding:6px 0;font-weight:600;width:40%;">Username:</td><td><code style="background:#c8e6c9;padding:3px 8px;border-radius:4px;"><?php echo htmlspecialchars($login_details['username']); ?></code></td></tr>
                    <tr><td style="padding:6px 0;font-weight:600;">Email:</td><td><code style="background:#c8e6c9;padding:3px 8px;border-radius:4px;"><?php echo htmlspecialchars($login_details['email']); ?></code></td></tr>
                    <tr><td style="padding:6px 0;font-weight:600;">Student ID:</td><td><code style="background:#c8e6c9;padding:3px 8px;border-radius:4px;"><?php echo htmlspecialchars($login_details['student_id']); ?></code></td></tr>
                    <tr><td style="padding:6px 0;font-weight:600;">Password:</td><td><code style="background:#c8e6c9;padding:3px 8px;border-radius:4px;"><?php echo htmlspecialchars($login_details['password']); ?></code></td></tr>
                </table>
                <p style="margin:10px 0 0;font-size:0.82rem;color:#555;text-align:center;">
                    <i class="bi bi-info-circle me-1"></i>You can log in using your <strong>Student ID</strong>, <strong>Email</strong>, or <strong>Username</strong>.<br>
                    These credentials have also been sent to your email.
                </p>
            </div>
            <?php endif; ?>
            
            <div class="alert alert-warning" style="border-radius:10px;max-width:500px;margin:0 auto 16px;">
                <i class="bi bi-hourglass-split me-1"></i>
                <strong>Pending Approval:</strong> Your account is currently inactive. Once approved by the administrator, you will be able to log in and upload your proof of payment.
            </div>
            
            <a href="login.php" class="btn btn-outline-primary mt-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login Page
            </a>
        </div>

    <?php elseif ($step === 'done' && $student): ?>
        <!-- STEP 3: Final Confirmation -->
        <?php if ($student['status'] === 'cleared'): ?>
        <div class="success-box">
            <div class="icon"><i class="bi bi-patch-check-fill"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Examination Clearance Approved!</h3>
            <p style="color:var(--text-muted);max-width:500px;margin:0 auto 20px;">
                Your exam clearance has been approved. You are cleared to sit for examinations.
            </p>
            <?php if (!empty($student['certificate_number'])): ?>
                <div class="alert alert-success" style="border-radius:10px;max-width:400px;margin:0 auto;">
                    Certificate No: <strong><?php echo htmlspecialchars($student['certificate_number']); ?></strong>
                </div>
            <?php endif; ?>
            <a href="login.php" class="btn btn-outline-primary mt-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login Page
            </a>
        </div>
        <?php else: ?>
        <div class="success-box">
            <div class="icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><i class="bi bi-hourglass-split"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Proof Submitted &mdash; Under Review</h3>
            <p style="color:var(--text-muted);max-width:500px;margin:0 auto 20px;">
                Your proof of payment has been submitted. The Finance Office will review and process your clearance.
            </p>
            <div style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:10px;padding:16px;max-width:440px;margin:0 auto;">
                <p style="margin:0;font-size:0.9rem;color:var(--text);">
                    <strong>Student:</strong> <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_id']); ?>)<br>
                    <strong>Amount Claimed:</strong> MWK <?php echo number_format($student['amount_claimed'] ?? 0, 2); ?><br>
                    <strong>Status:</strong> <span class="badge bg-warning text-dark">Pending Review</span>
                </p>
            </div>
            <a href="login.php" class="btn btn-outline-primary mt-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login to Track Status
            </a>
        </div>
        <?php endif; ?>

    <?php elseif ($step === 'upload_proof' && $student): ?>
        <!-- STEP 2: Invoice & Upload Proof -->
        <div class="card-body">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="section-title"><i class="bi bi-receipt"></i> Your Invoice</div>
            <div class="invoice-box">
                <p class="mb-1" style="color:var(--text-muted);">Invoice Amount (<?php echo ucfirst($student['program_type']); ?> Program)</p>
                <div class="invoice-amount">MWK <?php echo number_format($student['invoiced_amount'], 2); ?></div>
                <p class="mb-0 mt-1" style="font-size:0.85rem;color:var(--text-muted);">
                    Student: <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_id']); ?>)
                </p>
                <?php if ($student['balance'] > 0): ?>
                    <span class="badge bg-danger mt-2">Balance: MWK <?php echo number_format($student['balance'], 2); ?></span>
                <?php else: ?>
                    <span class="badge bg-success mt-2">Fully Paid</span>
                <?php endif; ?>
            </div>

            <div class="section-title"><i class="bi bi-upload"></i> Upload Proof of Payment</div>
            <form method="POST" enctype="multipart/form-data" id="proofForm">
                <input type="hidden" name="action" value="upload_proof">
                <input type="hidden" name="clearance_id" value="<?php echo $student['clearance_id']; ?>">
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Amount Paid (MWK) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" required min="1" step="0.01"
                               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" placeholder="e.g. 500000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Reference <span class="text-danger">*</span></label>
                        <input type="text" name="payment_reference" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['payment_reference'] ?? ''); ?>" placeholder="Transaction/Receipt No.">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['bank_name'] ?? ''); ?>" placeholder="e.g. National Bank">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Proof of Payment <span class="text-danger">*</span></label>
                        <input type="file" name="proof_file" class="form-control" required accept=".jpg,.jpeg,.png,.pdf">
                        <small style="color:var(--text-muted);">Upload bank slip, receipt, or screenshot (JPG, PNG, PDF. Max 5MB)</small>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-submit">
                        <i class="bi bi-upload me-2"></i> Submit Proof of Payment
                    </button>
                </div>
            </form>
        </div>

    <?php elseif ($error && !$invite): ?>
        <div class="error-box">
            <div class="icon"><i class="bi bi-x-lg"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Invalid Link</h3>
            <p style="color:var(--text-muted);max-width:500px;margin:0 auto;">
                <?php echo htmlspecialchars($error); ?>
            </p>
            <a href="login.php" class="btn btn-outline-primary mt-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
            </a>
        </div>

    <?php else: ?>
        <!-- STEP 1: Registration Form -->
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($invite): ?>
            <div class="invite-info">
                <i class="bi bi-info-circle me-1"></i>
                You are registering for <strong>Examination Clearance</strong>.
                Your account will be created automatically and you will be invoiced based on your program type.
            </div>
            <?php endif; ?>

            <form method="POST" id="regForm">
                <input type="hidden" name="action" value="register">

                <!-- Student Identification -->
                <div class="section-title"><i class="bi bi-card-heading"></i> Student Identification</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="form-label">Student ID / Registration Number <span class="text-danger">*</span></label>
                        <input type="text" name="student_id" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>" placeholder="e.g. CS/24/MZ/NE/0001 or BSC-2023-001">
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="section-title"><i class="bi bi-person-circle"></i> Personal Information</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Firstname <span class="text-danger">*</span></label>
                        <input type="text" name="firstname" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>" placeholder="e.g. John">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Other Name(s)</label>
                        <input type="text" name="other_names" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['other_names'] ?? ''); ?>" placeholder="e.g. James">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Surname <span class="text-danger">*</span></label>
                        <input type="text" name="surname" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['surname'] ?? ''); ?>" placeholder="e.g. Banda">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="your.email@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+265...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select Gender</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                            <option value="<?php echo $g; ?>" <?php echo ($_POST['gender'] ?? '') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control" maxlength="8"
                               value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>" placeholder="Max 8 characters"
                               style="text-transform:uppercase;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" placeholder="City, District">
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="section-title"><i class="bi bi-mortarboard"></i> Academic Information</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Program <span class="text-danger">*</span></label>
                        <select name="program_id" id="program" class="form-select" required onchange="onProgramChange()">
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo (int)$prog['program_id']; ?>"
                                    data-dept-id="<?php echo (int)$prog['department_id']; ?>"
                                    data-dept-name="<?php echo htmlspecialchars($prog['department_name'] ?? ''); ?>"
                                    data-program-type="<?php echo htmlspecialchars($prog['program_type'] ?? ''); ?>"
                                    data-program-code="<?php echo htmlspecialchars($prog['program_code']); ?>"
                                    <?php echo (($_POST['program_id'] ?? '') == $prog['program_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['program_name']); ?> (<?php echo htmlspecialchars($prog['program_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="department_id" id="department" class="form-select" required>
                            <option value="">Select Program first</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($dept['department_name']); ?>"
                                    <?php echo (($_POST['department_id'] ?? '') == $dept['department_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="deptAutoNote" style="display:none;"><i class="bi bi-info-circle"></i> Auto-filled from selected program</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Program Type <span class="text-danger">*</span></label>
                        <select name="program_type" id="programType" class="form-select" required>
                            <?php
                            $pt = $_POST['program_type'] ?? 'degree';
                            foreach (['degree' => 'Degree', 'diploma' => 'Diploma', 'certificate' => 'Certificate', 'professional' => 'Professional', 'masters' => 'Masters', 'doctorate' => 'Doctorate'] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $pt === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Campus <span class="text-danger">*</span></label>
                        <select name="campus" class="form-select" required>
                            <?php
                            $cam = $_POST['campus'] ?? 'Mzuzu Campus';
                            foreach (['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'] as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $cam === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Entry Type</label>
                        <select name="entry_type" class="form-select">
                            <?php
                            $et = $_POST['entry_type'] ?? 'NE';
                            foreach (['NE' => 'Normal Entry (NE)', 'ME' => 'Mature Entry (ME)', 'CE' => 'Continuing Entry (CE)', 'ODL' => 'Open Distance Learning (ODL)', 'PC' => 'Professional Course (PC)'] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $et === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Year of Study</label>
                        <select name="year_of_study" class="form-select">
                            <?php $ys = (int)($_POST['year_of_study'] ?? 1);
                            for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ys === $i ? 'selected' : ''; ?>>Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <?php $sem = $_POST['semester'] ?? 'One';
                            foreach (['One', 'Two'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $sem === $s ? 'selected' : ''; ?>>Semester <?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Exam Clearance Info -->
                <div class="section-title"><i class="bi bi-shield-check"></i> Examination Clearance</div>
                <div class="alert alert-info" style="border-radius:10px;">
                    <i class="bi bi-info-circle me-1"></i>
                    After registration, you will be <strong>invoiced automatically</strong> based on your program type.
                    You will then upload your proof of payment for the Finance Office to review and approve your clearance.
                    <strong>A login account will be created for you automatically.</strong>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-submit">
                        <i class="bi bi-send me-2"></i> Register & Get Invoice
                    </button>
                </div>
                <p class="text-center mt-3" style="font-size:0.8rem;color:var(--text-muted);">
                    <i class="bi bi-shield-check me-1"></i>
                    Your information will be processed by the Finance Office for examination clearance.
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="footer-text">
    &copy; <?php echo date('Y'); ?> Exploits University of Malawi. All rights reserved.<br>
    <a href="login.php" style="color:var(--primary);">Already have an account? Login here</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function onProgramChange() {
    var progSelect = document.getElementById('program');
    var deptSelect = document.getElementById('department');
    var deptNote = document.getElementById('deptAutoNote');
    var ptSelect = document.getElementById('programType');
    var opt = progSelect.options[progSelect.selectedIndex];
    
    if (opt && opt.value) {
        var deptId = opt.getAttribute('data-dept-id');
        var progType = opt.getAttribute('data-program-type');
        
        // Auto-set department
        if (deptId && deptId !== '0') {
            deptSelect.value = deptId;
            deptNote.style.display = 'block';
        } else {
            deptSelect.value = '';
            deptNote.style.display = 'none';
        }
        
        // Auto-set program type if available
        if (progType && ptSelect) {
            var ptMap = {'bachelors': 'degree', 'masters': 'masters', 'doctorate': 'doctorate', 'diploma': 'diploma', 'certificate': 'certificate', 'professional': 'professional', 'degree': 'degree'};
            if (ptMap[progType]) {
                ptSelect.value = ptMap[progType];
            }
        }
    } else {
        deptNote.style.display = 'none';
    }
}

// Trigger on page load if program was pre-selected
document.addEventListener('DOMContentLoaded', function() {
    var prog = document.getElementById('program');
    if (prog && prog.value) {
        onProgramChange();
    }
});
</script>
</body>
</html>