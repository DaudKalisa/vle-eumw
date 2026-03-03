<?php
/**
 * Public Student Registration Form
 * Two modes:
 *   1. Token-based: register_student.php?token=XXXXX (invite link)
 *   2. General: register_student.php (open registration, no token needed)
 * No login required. After submission, admin must approve before account is created.
 */
require_once 'includes/config.php';

$conn = getDbConnection();
$token = trim($_GET['token'] ?? '');
$success = '';
$error = '';
$invite = null;
$general_mode = empty($token); // General open registration (no invite link)

// Validate token if provided
if (!$general_mode) {
    $stmt = $conn->prepare("SELECT * FROM student_registration_invites WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();

    if (!$invite) {
        $error = 'This registration link is invalid or has been removed.';
    } elseif (!$invite['is_active']) {
        $error = 'This registration link has been deactivated.';
    } elseif ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
        $error = 'This registration link has expired. Please request a new one from your administrator.';
    } elseif ($invite['max_uses'] > 0 && $invite['times_used'] >= $invite['max_uses']) {
        $error = 'This registration link has already been used the maximum number of times.';
    }
}

// Get departments for dropdown
$departments = [];
if (!$error) {
    $dept_result = $conn->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name");
    if ($dept_result) {
        while ($dept = $dept_result->fetch_assoc()) {
            $departments[] = $dept;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && ($invite || $general_mode)) {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $national_id = strtoupper(trim($_POST['national_id'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $department_id = (int)($_POST['department'] ?? $invite['department_id'] ?? 0);
    $program = trim($_POST['program'] ?? $invite['program'] ?? '');
    $program_type = trim($_POST['program_type'] ?? $invite['program_type'] ?? 'degree');
    $campus = trim($_POST['campus'] ?? $invite['campus'] ?? 'Mzuzu Campus');
    $year_of_study = (int)($_POST['year_of_study'] ?? $invite['year_of_study'] ?? 1);
    $semester = trim($_POST['semester'] ?? $invite['semester'] ?? 'One');
    $entry_type = trim($_POST['entry_type'] ?? $invite['entry_type'] ?? 'NE');
    $student_type = trim($_POST['student_type'] ?? 'new_student');
    $student_id_number = trim($_POST['student_id_number'] ?? '');
    $invite_id = $invite ? $invite['invite_id'] : 0;

    // Validation
    if (empty($first_name) || empty($last_name)) {
        $error = 'First name and last name are required.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid email address is required.';
    } elseif ($department_id <= 0) {
        $error = 'Please select a valid department.';
    } elseif (empty($program)) {
        $error = 'Please select a program.';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $error = 'Please select your gender.';
    } elseif (!empty($national_id) && strlen($national_id) > 8) {
        $error = 'National ID must be 8 characters or less.';
    } else {
        // Check if email already used
        if ($invite) {
            $check = $conn->prepare("SELECT registration_id FROM student_invite_registrations WHERE email = ? AND invite_id = ?");
            $check->bind_param("si", $email, $invite_id);
            $check->execute();
        } else {
            $check = $conn->prepare("SELECT registration_id FROM student_invite_registrations WHERE email = ? AND status = 'pending'");
            $check->bind_param("s", $email);
            $check->execute();
        }
        if ($check->get_result()->num_rows > 0) {
            $error = 'This email has already been used to register. Your application is being reviewed.';
        } else {
            // Check if email exists in users table already
            $check2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check2->bind_param("s", $email);
            $check2->execute();
            if ($check2->get_result()->num_rows > 0) {
                $error = 'This email is already registered in the system. Please contact admin or use a different email.';
            } else {
                // Check duplicate national ID
                if (!empty($national_id)) {
                    $nid_check = $conn->prepare("SELECT student_id FROM students WHERE national_id = ?");
                    $nid_check->bind_param("s", $national_id);
                    $nid_check->execute();
                    if ($nid_check->get_result()->num_rows > 0) {
                        $error = 'This National ID is already registered to another student.';
                    }
                }
            }
        }
    }

    if (!$error) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO student_invite_registrations 
            (invite_id, student_id_number, first_name, middle_name, last_name, email, phone, gender, national_id, address, 
             department_id, program, program_type, campus, year_of_study, semester, entry_type, student_type, 
             status, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        $bind_student_id_number = $student_id_number ?: null;
        $stmt->bind_param("isssssssssisssissss", 
            $invite_id, $bind_student_id_number, $first_name, $middle_name, $last_name, $email, $phone, 
            $gender, $national_id, $address, $department_id, $program, $program_type, 
            $campus, $year_of_study, $semester, $entry_type, $student_type, $ip);
        
        if ($stmt->execute()) {
            // Increment usage counter (only for invite-based registrations)
            if ($invite) {
                $upd = $conn->prepare("UPDATE student_registration_invites SET times_used = times_used + 1 WHERE invite_id = ?");
                $upd->bind_param("i", $invite_id);
                $upd->execute();
            }

            $success = 'Your registration has been submitted successfully! An administrator will review your application and you will receive your login credentials via email once approved.';
        } else {
            $error = 'Failed to submit registration. Please try again. Error: ' . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - EUMW VLE</title>
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
    <h1><i class="bi bi-mortarboard me-2"></i>Student Registration</h1>
    <p>Exploits University of Malawi &mdash; Virtual Learning Environment</p>
</div>

<div class="reg-card">
    <?php if ($success): ?>
        <div class="success-box">
            <div class="icon"><i class="bi bi-check-lg"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Registration Submitted!</h3>
            <p style="color:var(--text-muted);max-width:500px;margin:0 auto 20px;">
                <?= htmlspecialchars($success) ?>
            </p>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;max-width:400px;margin:0 auto;">
                <i class="bi bi-clock-history" style="color:#10b981;font-size:20px;"></i>
                <p style="margin:8px 0 0;font-size:0.9rem;color:#166534;">
                    <strong>What happens next?</strong><br>
                    An administrator will review your registration. Once approved, your student ID, username, and temporary password will be sent to your email.
                </p>
            </div>
            <a href="login.php" class="btn btn-outline-primary mt-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login Page
            </a>
        </div>

    <?php elseif ($error && !$invite): ?>
        <div class="error-box">
            <div class="icon"><i class="bi bi-x-lg"></i></div>
            <h3 style="margin-bottom:12px;font-weight:700;">Invalid Link</h3>
            <p style="color:var(--text-muted);max-width:500px;margin:0 auto;">
                <?= htmlspecialchars($error) ?>
            </p>
            <a href="login.php" class="btn btn-outline-primary mt-4">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
            </a>
        </div>

    <?php else: ?>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($invite['email'] || $invite['full_name']): ?>
            <div class="invite-info">
                <i class="bi bi-info-circle me-1"></i>
                This invite was created for
                <?php if ($invite['full_name']): ?><strong><?= htmlspecialchars($invite['full_name']) ?></strong><?php endif; ?>
                <?php if ($invite['email']): ?>(<?= htmlspecialchars($invite['email']) ?>)<?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="regForm">
                <!-- Student Identification -->
                <div class="section-title"><i class="bi bi-card-heading"></i> Student Identification</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="form-label">Student ID Number <small class="text-muted">(if you have one already, e.g. transfer/returning student)</small></label>
                        <input type="text" name="student_id_number" class="form-control"
                               value="<?= htmlspecialchars($_POST['student_id_number'] ?? '') ?>" placeholder="e.g. CS/24/MZ/NE/0001 (leave blank if new student)">
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="section-title"><i class="bi bi-person-circle"></i> Personal Information</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="e.g. John">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" placeholder="e.g. Banda">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? $invite['email'] ?? '') ?>" placeholder="your.email@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+265...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select Gender</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($_POST['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control" maxlength="8"
                               value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>" placeholder="Max 8 characters"
                               style="text-transform:uppercase;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" placeholder="City, District">
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="section-title"><i class="bi bi-mortarboard"></i> Academic Information</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="department" id="department" class="form-select" required onchange="updateProgram()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" 
                                    data-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                    <?= (($_POST['department'] ?? $invite['department_id'] ?? '') == $dept['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Program <span class="text-danger">*</span></label>
                        <input type="text" name="program" id="program" class="form-control" required
                               value="<?= htmlspecialchars($_POST['program'] ?? $invite['program'] ?? '') ?>"
                               placeholder="e.g. Bachelor of Business Administration">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Program Type <span class="text-danger">*</span></label>
                        <select name="program_type" class="form-select" required>
                            <?php 
                            $pt = $_POST['program_type'] ?? $invite['program_type'] ?? 'degree';
                            foreach (['degree' => 'Degree', 'diploma' => 'Diploma', 'certificate' => 'Certificate', 'professional' => 'Professional', 'masters' => 'Masters', 'doctorate' => 'Doctorate'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $pt === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Campus <span class="text-danger">*</span></label>
                        <select name="campus" class="form-select" required>
                            <?php 
                            $cam = $_POST['campus'] ?? $invite['campus'] ?? 'Mzuzu Campus';
                            foreach (['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'] as $c): ?>
                            <option value="<?= $c ?>" <?= $cam === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Entry Type</label>
                        <select name="entry_type" class="form-select">
                            <?php
                            $et = $_POST['entry_type'] ?? ($invite['entry_type'] ?? 'NE');
                            foreach (['NE' => 'Normal Entry (NE)', 'ME' => 'Mature Entry (ME)', 'CE' => 'Continuing Entry (CE)', 'ODL' => 'Open Distance Learning (ODL)', 'PC' => 'Professional Course (PC)'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $et === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Year of Study</label>
                        <select name="year_of_study" class="form-select">
                            <?php $ys = (int)($_POST['year_of_study'] ?? $invite['year_of_study'] ?? 1);
                            for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?= $i ?>" <?= $ys === $i ? 'selected' : '' ?>>Year <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <?php $sem = $_POST['semester'] ?? $invite['semester'] ?? 'One';
                            foreach (['One', 'Two'] as $s): ?>
                            <option value="<?= $s ?>" <?= $sem === $s ? 'selected' : '' ?>>Semester <?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Student Type</label>
                        <select name="student_type" class="form-select">
                            <?php $st = $_POST['student_type'] ?? 'new_student';
                            foreach (['new_student' => 'New Student', 'continuing' => 'Continuing'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $st === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-submit">
                        <i class="bi bi-send me-2"></i> Submit Registration
                    </button>
                </div>
                <p class="text-center mt-3" style="font-size:0.8rem;color:var(--text-muted);">
                    <i class="bi bi-shield-check me-1"></i>
                    Your information will be reviewed by an administrator before your account is created.
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="footer-text">
    &copy; <?= date('Y') ?> Exploits University of Malawi. All rights reserved.<br>
    <a href="login.php" style="color:var(--primary);">Already have an account? Login here</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateProgram() {
    const sel = document.getElementById('department');
    const opt = sel.options[sel.selectedIndex];
    const name = opt.getAttribute('data-name') || '';
    const prog = document.getElementById('program');
    if (name && !prog.value) {
        // Suggest a program based on department name
        prog.value = 'Bachelor of ' + name;
    }
}
</script>
</body>
</html>
