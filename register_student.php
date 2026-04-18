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

// Auto-add selected_modules column if missing
$col_check = $conn->query("SHOW COLUMNS FROM student_invite_registrations LIKE 'selected_modules'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE student_invite_registrations ADD COLUMN selected_modules TEXT DEFAULT NULL COMMENT 'JSON array of course IDs' AFTER student_type");
}

// Auto-add is_supervisor column if missing
$supervisor_col = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'is_supervisor'");
if ($supervisor_col && $supervisor_col->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER dissertation_only");
}

// ...existing code...
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_courses') {
    header('Content-Type: application/json');
    $year = (int)($_GET['year'] ?? 0);
    $semester = $_GET['semester'] ?? '';
    
    $program_filter = trim($_GET['program'] ?? '');
    
    $where = "c.is_active = 1";
    $params = [];
    $types = "";
    
    // Filter by year
    if ($year > 0) {
        $where .= " AND c.year_of_study = ?";
        $params[] = $year;
        $types .= "i";
    }
    // Filter by semester
    if (in_array($semester, ['One', 'Two'])) {
        $where .= " AND c.semester = ?";
        $params[] = $semester;
        $types .= "s";
    }
    
    // If program is selected, show courses where:
    // 1. program_of_study matches (primary program), OR
    // 2. course is associated with the program via course_programs table
    if (!empty($program_filter)) {
        $where .= " AND (c.program_of_study = ? OR c.course_id IN (
            SELECT cp.course_id FROM course_programs cp 
            INNER JOIN programs p ON cp.program_id = p.program_id 
            WHERE p.program_name = ? AND p.is_active = 1
        ))";
        $params[] = $program_filter;
        $params[] = $program_filter;
        $types .= "ss";
    }
    
    $sql = "SELECT c.course_id, c.course_code, c.course_name, c.year_of_study, c.semester, 
            c.program_of_study,
            (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled_count
            FROM vle_courses c
            WHERE $where
            ORDER BY c.year_of_study, c.semester, c.course_code";
    
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    echo json_encode(['success' => true, 'courses' => $courses]);
    exit;
}

$token = trim($_GET['token'] ?? '');
$success = '';
$error = '';
$invite = [];
$general_mode = empty($token); // General open registration (no invite link)
$is_dissertation_invite = false;

// Validate token if provided
if (!$general_mode) {
    $stmt = $conn->prepare("SELECT * FROM student_registration_invites WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();

    if ($invite) {
        $is_dissertation_invite = !empty($invite['dissertation_only']);
        // Check if this is a supervisor invite - redirect to lecturer registration if so
        if (!empty($invite['is_supervisor'])) {
            header('Location: register_lecturer.php?token=' . urlencode($token));
            exit;
        }
    }

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

// Get programs with their departments for dropdown
$programs = [];
if (!$error) {
    $prog_result = $conn->query("SELECT p.program_id, p.program_code, p.program_name, p.department_id, p.program_type, p.duration_years,
        d.department_name, d.department_code
        FROM programs p
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.is_active = 1
        ORDER BY p.program_name");
    if ($prog_result) {
        while ($prog = $prog_result->fetch_assoc()) {
            $programs[] = $prog;
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
    $student_type = $is_dissertation_invite ? 'dissertation_only' : trim($_POST['student_type'] ?? 'new_student');
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
    } elseif (!$is_dissertation_invite && (empty($_POST['selected_modules']) || !is_array($_POST['selected_modules']) || count($_POST['selected_modules']) === 0)) {
        $error = 'Please select at least one module/course to attend.';
    } elseif (!$is_dissertation_invite && count($_POST['selected_modules']) > 7) {
        $error = 'You can select a maximum of 7 modules/courses.';
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
        // Validate and collect selected modules
        $selected_modules = $_POST['selected_modules'] ?? [];
        $valid_modules = [];
        if (!$is_dissertation_invite) {
            foreach ($selected_modules as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) $valid_modules[] = $mid;
            }
        }
        $modules_json = !empty($valid_modules) ? json_encode($valid_modules) : null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO student_invite_registrations 
            (invite_id, student_id_number, first_name, middle_name, last_name, email, phone, gender, national_id, address, 
             department_id, program, program_type, campus, year_of_study, semester, entry_type, student_type, 
             selected_modules, status, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        $bind_student_id_number = $student_id_number ?: null;
        $stmt->bind_param("isssssssssisssisssss", 
            $invite_id, $bind_student_id_number, $first_name, $middle_name, $last_name, $email, $phone, 
            $gender, $national_id, $address, $department_id, $program, $program_type, 
            $campus, $year_of_study, $semester, $entry_type, $student_type, $modules_json, $ip);
        
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

        /* Module selection styles */
        .module-filter-bar { background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .module-grid { max-height: 400px; overflow-y: auto; border: 1px solid var(--border); border-radius: 12px; padding: 8px; }
        .module-item {
            display: flex; align-items: center; padding: 10px 14px; margin: 4px 0;
            border-radius: 10px; border: 1.5px solid var(--border); cursor: pointer;
            transition: all 0.2s; background: #fff;
        }
        .module-item:hover { border-color: var(--primary-light); background: #f0f4ff; }
        .module-item.selected { border-color: var(--primary); background: #eef2ff; box-shadow: 0 0 0 2px rgba(79,70,229,0.15); }
        .module-item .form-check-input { margin-right: 12px; cursor: pointer; }
        .module-item .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .module-item .module-code { font-weight: 600; font-size: 0.85rem; color: var(--primary); min-width: 100px; }
        .module-item .module-name { font-size: 0.85rem; color: var(--text); flex: 1; }
        .module-item .module-meta { font-size: 0.72rem; color: var(--text-muted); white-space: nowrap; }
        .module-counter { display: inline-flex; align-items: center; gap: 4px; background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 6px 14px; font-size: 0.85rem; font-weight: 600; color: var(--primary); }
        .module-counter.at-max { background: #fef3c7; border-color: #fbbf24; color: #d97706; }
        .module-counter.over-max { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
        .no-modules { text-align: center; padding: 30px; color: var(--text-muted); }
        .loading-modules { text-align: center; padding: 30px; color: var(--primary); }
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
                <?php if ($is_dissertation_invite): ?>
                <div class="mt-2 text-primary"><i class="bi bi-mortarboard me-1"></i><strong>Dissertation-only invite:</strong> your account will have dissertation portal access only.</div>
                <?php endif; ?>
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
                        <label class="form-label">Program <span class="text-danger">*</span></label>
                        <select name="program" id="program" class="form-select" required onchange="onProgramChange()">
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog['program_name']) ?>" 
                                    data-dept-id="<?= (int)$prog['department_id'] ?>"
                                    data-dept-name="<?= htmlspecialchars($prog['department_name'] ?? '') ?>"
                                    data-program-type="<?= htmlspecialchars($prog['program_type'] ?? '') ?>"
                                    data-program-code="<?= htmlspecialchars($prog['program_code']) ?>"
                                    <?= (($_POST['program'] ?? $invite['program'] ?? '') == $prog['program_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prog['program_name']) ?> (<?= htmlspecialchars($prog['program_code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="department" id="department" class="form-select" required>
                            <option value="">Select Program first</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" 
                                    data-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                    <?= (($_POST['department'] ?? $invite['department_id'] ?? '') == $dept['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="deptAutoNote" style="display:none;"><i class="bi bi-info-circle"></i> Auto-filled from selected program</small>
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

                <?php if (!$is_dissertation_invite): ?>
                <!-- Module / Course Selection -->
                <div class="section-title"><i class="bi bi-book"></i> Module / Course Selection</div>
                <p style="font-size:0.85rem;color:var(--text-muted);">
                    Select the modules/courses you will attend. Filter by year and semester, then choose up to <strong>7 modules</strong>.
                </p>

                <div class="module-filter-bar">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold mb-1" style="font-size:0.8rem;">Year of Study</label>
                            <select id="moduleFilterYear" class="form-select form-select-sm">
                                <option value="0">All Years</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold mb-1" style="font-size:0.8rem;">Semester</label>
                            <select id="moduleFilterSemester" class="form-select form-select-sm">
                                <option value="">All Semesters</option>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <div class="module-counter" id="moduleCounter">
                                <i class="bi bi-check2-square"></i>
                                <span id="selectedCount">0</span> / 7 selected
                            </div>
                        </div>
                    </div>
                </div>

                <div class="module-grid" id="moduleGrid">
                    <div class="loading-modules">
                        <div class="spinner-border spinner-border-sm me-2" role="status" style="color:var(--primary);"></div>
                        Select a year and semester to load available modules...
                    </div>
                </div>

                <!-- Hidden inputs for selected modules -->
                <div id="selectedModulesInputs"></div>
                <?php else: ?>
                <div class="section-title"><i class="bi bi-mortarboard"></i> Dissertation Portal Access</div>
                <div class="alert alert-info" style="border-radius:10px;">
                    <i class="bi bi-info-circle me-1"></i>
                    This registration is for dissertation workflow only. You will be routed directly to the dissertation portal after account approval.
                </div>
                <?php endif; ?>

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
function onProgramChange() {
    const progSelect = document.getElementById('program');
    const deptSelect = document.getElementById('department');
    const deptNote = document.getElementById('deptAutoNote');
    const opt = progSelect.options[progSelect.selectedIndex];
    
    if (opt && opt.value) {
        const deptId = opt.getAttribute('data-dept-id');
        const progType = opt.getAttribute('data-program-type');
        
        // Auto-set department
        if (deptId && deptId !== '0') {
            deptSelect.value = deptId;
            deptNote.style.display = 'block';
        } else {
            deptSelect.value = '';
            deptNote.style.display = 'none';
        }
        
        // Auto-set program type if available
        if (progType) {
            const ptMap = {'bachelors': 'degree', 'masters': 'masters', 'doctorate': 'doctorate', 'diploma': 'diploma', 'certificate': 'certificate', 'professional': 'professional', 'degree': 'degree'};
            const ptSelect = document.querySelector('select[name="program_type"]');
            if (ptSelect && ptMap[progType]) {
                ptSelect.value = ptMap[progType];
            }
        }
    } else {
        deptNote.style.display = 'none';
    }
    
    // Reload courses filtered by the selected program
    if (typeof window._loadCourses === 'function') {
        window._loadCourses();
    }
}

// Trigger on page load if program was pre-selected (e.g. form validation error)
document.addEventListener('DOMContentLoaded', function() {
    const prog = document.getElementById('program');
    if (prog && prog.value) {
        onProgramChange();
    }
});

// Module Selection Logic
(function() {
    const MAX_MODULES = 7;
    let selectedModules = new Set();
    let allCourses = [];

    const filterYear = document.getElementById('moduleFilterYear');
    const filterSemester = document.getElementById('moduleFilterSemester');
    const moduleGrid = document.getElementById('moduleGrid');
    const moduleCounter = document.getElementById('moduleCounter');
    const selectedCount = document.getElementById('selectedCount');
    const selectedInputs = document.getElementById('selectedModulesInputs');

    // If these elements don't exist (success/error page), skip
    if (!filterYear || !moduleGrid) return;

    // Restore selections from POST if form had errors
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected_modules'])): ?>
    <?php foreach ($_POST['selected_modules'] as $mid): ?>
    selectedModules.add(<?= (int)$mid ?>);
    <?php endforeach; ?>
    <?php endif; ?>

    // Auto-set filter based on year_of_study and semester form fields
    const yearField = document.querySelector('select[name="year_of_study"]');
    const semesterField = document.querySelector('select[name="semester"]');
    if (yearField && yearField.value) {
        filterYear.value = yearField.value;
    }
    if (semesterField && semesterField.value) {
        filterSemester.value = semesterField.value;
    }

    // Sync module filter when year/semester academic fields change
    if (yearField) yearField.addEventListener('change', function() {
        filterYear.value = this.value;
        loadCourses();
    });
    if (semesterField) semesterField.addEventListener('change', function() {
        filterSemester.value = this.value;
        loadCourses();
    });

    function loadCourses() {
        const year = filterYear.value;
        const semester = filterSemester.value;
        
        // Get selected program name for filtering
        const progSelect = document.getElementById('program');
        const programName = progSelect ? progSelect.value : '';
        
        moduleGrid.innerHTML = '<div class="loading-modules"><div class="spinner-border spinner-border-sm me-2" role="status" style="color:var(--primary);"></div> Loading modules...</div>';

        const params = new URLSearchParams({
            ajax: 'get_courses',
            year: year,
            semester: semester
        });
        if (programName) {
            params.set('program', programName);
        }

        fetch('<?= basename($_SERVER['SCRIPT_NAME']) ?>?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    allCourses = data.courses;
                    renderCourses();
                } else {
                    moduleGrid.innerHTML = '<div class="no-modules"><i class="bi bi-exclamation-circle"></i> Failed to load modules.</div>';
                }
            })
            .catch(err => {
                moduleGrid.innerHTML = '<div class="no-modules"><i class="bi bi-exclamation-circle"></i> Error loading modules.</div>';
            });
    }

    function renderCourses() {
        if (allCourses.length === 0) {
            moduleGrid.innerHTML = '<div class="no-modules"><i class="bi bi-inbox" style="font-size:2rem;"></i><br>No modules found for the selected filter. Try a different year or semester.</div>';
            return;
        }

        let html = '';
        allCourses.forEach(c => {
            const isSelected = selectedModules.has(c.course_id);
            const atMax = selectedModules.size >= MAX_MODULES && !isSelected;

            html += `<label class="module-item ${isSelected ? 'selected' : ''}" data-id="${c.course_id}">
                <input type="checkbox" class="form-check-input module-check" 
                       value="${c.course_id}" 
                       ${isSelected ? 'checked' : ''} 
                       ${atMax ? 'disabled' : ''}
                       onchange="window.toggleModule(${c.course_id}, this)">
                <span class="module-code">${escHtml(c.course_code)}</span>
                <span class="module-name">${escHtml(c.course_name)}</span>
                <span class="module-meta">
                    ${c.program_of_study ? escHtml(c.program_of_study) + ' | ' : ''}
                    Y${(() => { let yrs = [c.year_of_study || '?']; if (c.applicable_years) yrs = yrs.concat(c.applicable_years.split(',').map(y=>y.trim())); return [...new Set(yrs)].sort().join(','); })()} ${c.semester === 'Both' ? 'S1&2' : 'S' + (c.semester === 'Two' ? '2' : '1')}
                    ${c.enrolled_count > 0 ? ' | ' + c.enrolled_count + ' enrolled' : ''}
                </span>
            </label>`;
        });
        moduleGrid.innerHTML = html;
    }

    function escHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    window.toggleModule = function(courseId, checkbox) {
        if (checkbox.checked) {
            if (selectedModules.size >= MAX_MODULES) {
                checkbox.checked = false;
                alert('You can select a maximum of ' + MAX_MODULES + ' modules.');
                return;
            }
            selectedModules.add(courseId);
        } else {
            selectedModules.delete(courseId);
        }
        updateCounter();
        updateHiddenInputs();
        renderCourses();
    };

    function updateCounter() {
        const count = selectedModules.size;
        selectedCount.textContent = count;
        
        moduleCounter.classList.remove('at-max', 'over-max');
        if (count >= MAX_MODULES) moduleCounter.classList.add('at-max');
        if (count > MAX_MODULES) moduleCounter.classList.add('over-max');
    }

    function updateHiddenInputs() {
        selectedInputs.innerHTML = '';
        selectedModules.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_modules[]';
            input.value = id;
            selectedInputs.appendChild(input);
        });
    }

    // Form validation
    document.getElementById('regForm')?.addEventListener('submit', function(e) {
        if (selectedModules.size === 0) {
            e.preventDefault();
            alert('Please select at least one module/course to attend.');
            return;
        }
        if (selectedModules.size > MAX_MODULES) {
            e.preventDefault();
            alert('You can select a maximum of ' + MAX_MODULES + ' modules.');
            return;
        }
    });

    // Event listeners for module filters
    filterYear.addEventListener('change', loadCourses);
    filterSemester.addEventListener('change', loadCourses);

    // Expose loadCourses for program change trigger
    window._loadCourses = loadCourses;

    // Initialize
    updateCounter();
    updateHiddenInputs();
    loadCourses();
})();
</script>
</body>
</html>
