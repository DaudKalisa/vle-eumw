<?php
/**
 * Public Lecturer Registration Form
 * Accessed via invite link: register_lecturer.php?token=XXXXX
 * Lecturer fills personal info, selects modules (filtered by year & semester, max 7),
 * and submits for admin approval.
 */
require_once 'includes/config.php';

$conn = getDbConnection();
$token = trim($_GET['token'] ?? '');
$success = '';
$error = '';
$invite = null;

// Auto-create tables if they don't exist
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_registration_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    max_uses INT DEFAULT 1,
    times_used INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    INDEX idx_token (token),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS lecturer_invite_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_id INT NOT NULL DEFAULT 0,
    lecturer_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(20) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    qualification VARCHAR(200) DEFAULT NULL,
    specialization VARCHAR(200) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    selected_modules TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    INDEX idx_invite (invite_id),
    INDEX idx_status (status),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Validate token
if (empty($token)) {
    $error = 'No registration token provided. Please use the link sent to you by the administrator.';
} else {
    // First, try to find in lecturer_registration_invites
    $stmt = $conn->prepare("SELECT * FROM lecturer_registration_invites WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();

    // If not found, check if it's a supervisor bulk invite from student_registration_invites
    if (!$invite) {
        $stmt2 = $conn->prepare("SELECT * FROM student_registration_invites WHERE token = ? AND is_supervisor = 1 AND dissertation_only = 1");
        $stmt2->bind_param("s", $token);
        $stmt2->execute();
        $bulk_invite = $stmt2->get_result()->fetch_assoc();
        if ($bulk_invite) {
            // Mark this as a bulk supervisor invite by prefixing the invite_id with 'bulk_'
            $invite = $bulk_invite;
            $invite['is_bulk'] = 1;
        }
    }

    if (!$invite) {
        $error = 'This registration link is invalid or has been removed.';
    } elseif (!$invite['is_active']) {
        $error = 'This registration link has been deactivated.';
    } elseif ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
        $error = 'This registration link has expired. Please request a new one from the administrator.';
    } elseif ($invite['max_uses'] > 0 && $invite['times_used'] >= $invite['max_uses']) {
        $error = 'This registration link has already been used the maximum number of times.';
    }
}

// Get departments
$departments = [];
if (!$error) {
    $dept_result = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
    if ($dept_result) {
        while ($d = $dept_result->fetch_assoc()) $departments[] = $d['department'];
    }
    // Also try departments table
    $dept2 = $conn->query("SELECT department_name FROM departments ORDER BY department_name");
    if ($dept2) {
        while ($d = $dept2->fetch_assoc()) {
            if (!in_array($d['department_name'], $departments)) $departments[] = $d['department_name'];
        }
    }
    sort($departments);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $invite) {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $national_id = strtoupper(trim($_POST['national_id'] ?? ''));
    $department = trim($_POST['department'] ?? $invite['department'] ?? '');
    $position = trim($_POST['position'] ?? $invite['position'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $selected_modules = $_POST['selected_modules'] ?? [];
    $invite_id = $invite['invite_id'];

    // Validation
    if (empty($first_name) || empty($last_name)) {
        $error = 'First name and last name are required.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid email address is required.';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $error = 'Please select your gender.';
    } elseif (empty($department)) {
        $error = 'Please select or enter a department.';
    } elseif (empty($position)) {
        $error = 'Please select a position.';
    } elseif (empty($qualification)) {
        $error = 'Please enter your highest qualification.';
    } elseif (count($selected_modules) === 0) {
        $error = 'Please select at least one module to teach.';
    } elseif (count($selected_modules) > 7) {
        $error = 'You can select a maximum of 7 modules.';
    } elseif (!empty($national_id) && strlen($national_id) > 20) {
        $error = 'National ID is too long.';
    } else {
        // Validate module IDs
        $valid_modules = [];
        foreach ($selected_modules as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) $valid_modules[] = $mid;
        }
        if (count($valid_modules) === 0) {
            $error = 'Please select valid modules.';
        }

        if (!$error) {
            // Check duplicate email in registrations
            $check = $conn->prepare("SELECT registration_id FROM lecturer_invite_registrations WHERE email = ? AND status = 'pending'");
            $check->bind_param("s", $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'A registration with this email is already pending review.';
            }
        }

        if (!$error) {
            // Check if email exists in users table
            $check2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check2->bind_param("s", $email);
            $check2->execute();
            if ($check2->get_result()->num_rows > 0) {
                $error = 'This email is already registered in the system. Please contact the administrator.';
            }
        }

        if (!$error) {
            $modules_json = json_encode($valid_modules);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            $stmt = $conn->prepare("INSERT INTO lecturer_invite_registrations 
                (invite_id, first_name, middle_name, last_name, email, phone, gender, national_id, 
                 department, position, qualification, specialization, bio, selected_modules, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $bind_middle = $middle_name ?: null;
            $bind_phone = $phone ?: null;
            $bind_nid = $national_id ?: null;
            $bind_spec = $specialization ?: null;
            $bind_bio = $bio ?: null;
            $stmt->bind_param("issssssssssssss",
                $invite_id, $first_name, $bind_middle, $last_name, $email, $bind_phone,
                $gender, $bind_nid, $department, $position, $qualification,
                $bind_spec, $bind_bio, $modules_json, $ip);

            if ($stmt->execute()) {
                // Update invite usage counter
                if (!empty($invite['is_bulk'])) {
                    // This is a bulk supervisor invite from student_registration_invites
                    $upd = $conn->prepare("UPDATE student_registration_invites SET times_used = times_used + 1 WHERE invite_id = ?");
                    $upd->bind_param("i", $invite['invite_id']);
                    $upd->execute();
                } else {
                    // Regular lecturer invite
                    $upd = $conn->prepare("UPDATE lecturer_registration_invites SET times_used = times_used + 1 WHERE invite_id = ?");
                    $upd->bind_param("i", $invite_id);
                    $upd->execute();
                }

                $success = 'Registration submitted successfully! An administrator will review your application and you will receive an email with your login credentials once approved.';
            } else {
                $error = 'Failed to submit registration. Please try again. Error: ' . $conn->error;
            }
        }
    }
}

// Get available courses for AJAX endpoint
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_courses') {
    header('Content-Type: application/json');
    $year = (int)($_GET['year'] ?? 0);
    $semester = $_GET['semester'] ?? '';
    
    $where = "c.is_active = 1";
    $params = [];
    $types = "";
    
    if ($year > 0) {
        $where .= " AND c.year_of_study = ?";
        $params[] = $year;
        $types .= "i";
    }
    if (in_array($semester, ['One', 'Two'])) {
        $where .= " AND c.semester = ?";
        $params[] = $semester;
        $types .= "s";
    }
    
    $sql = "SELECT c.course_id, c.course_code, c.course_name, c.year_of_study, c.semester, 
            c.program_of_study, c.lecturer_id,
            CASE WHEN c.lecturer_id IS NOT NULL AND c.lecturer_id > 0 THEN l.full_name ELSE NULL END as current_lecturer
            FROM vle_courses c
            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Registration - EUMW VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 50%, #d1fae5 100%); min-height: 100vh; }
        .reg-container { max-width: 860px; margin: 40px auto; padding: 0 16px; }
        .reg-header {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff; text-align: center; padding: 40px 30px;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 4px 20px rgba(5, 150, 105, 0.3);
        }
        .reg-header h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; }
        .reg-header p { opacity: 0.9; margin-bottom: 0; }
        .reg-body {
            background: #fff; padding: 36px; border-radius: 0 0 20px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0; border-top: none;
        }
        .section-title {
            font-weight: 700; font-size: 1.1rem; color: #059669;
            border-bottom: 2px solid #d1fae5; padding-bottom: 8px;
            margin-top: 28px; margin-bottom: 16px;
        }
        .section-title:first-child { margin-top: 0; }
        .form-label { font-weight: 500; font-size: 0.88rem; color: #334155; }
        .form-control, .form-select { border-radius: 10px; border: 1.5px solid #e2e8f0; padding: 10px 14px; font-size: 0.9rem; }
        .form-control:focus, .form-select:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
        .btn-submit {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff; border: none; padding: 14px 40px; font-size: 1rem;
            font-weight: 600; border-radius: 12px; width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(5,150,105,0.3); color: #fff; }
        .error-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 16px; padding: 40px; text-align: center; }
        .success-banner { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 16px; padding: 40px; text-align: center; }

        /* Module selection styles */
        .module-filter-bar { background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .module-grid { max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px; }
        .module-item {
            display: flex; align-items: center; padding: 10px 14px; margin: 4px 0;
            border-radius: 10px; border: 1.5px solid #e2e8f0; cursor: pointer;
            transition: all 0.2s; background: #fff;
        }
        .module-item:hover { border-color: #a7f3d0; background: #f0fdf4; }
        .module-item.selected { border-color: #10b981; background: #ecfdf5; box-shadow: 0 0 0 2px rgba(16,185,129,0.15); }
        .module-item.assigned { opacity: 0.6; }
        .module-item .form-check-input { margin-right: 12px; cursor: pointer; }
        .module-item .module-code { font-weight: 600; font-size: 0.85rem; color: #059669; min-width: 100px; }
        .module-item .module-name { font-size: 0.85rem; color: #334155; flex: 1; }
        .module-item .module-meta { font-size: 0.72rem; color: #94a3b8; white-space: nowrap; }
        .module-counter { display: inline-flex; align-items: center; gap: 4px; background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 8px; padding: 6px 14px; font-size: 0.85rem; font-weight: 600; }
        .module-counter.at-max { background: #fef3c7; border-color: #fbbf24; color: #d97706; }
        .module-counter.over-max { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
        .no-modules { text-align: center; padding: 30px; color: #94a3b8; }
        .loading-modules { text-align: center; padding: 30px; color: #10b981; }
    </style>
</head>
<body>
    <div class="reg-container">
        <div class="reg-header">
            <h1><i class="bi bi-person-badge me-2"></i>Lecturer Registration</h1>
            <p>Exploits University of Malawi - Virtual Learning Environment</p>
        </div>

        <?php if ($success): ?>
        <div class="reg-body">
            <div class="success-banner">
                <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                <h4 class="text-success mt-3">Registration Submitted!</h4>
                <p class="text-muted"><?= htmlspecialchars($success) ?></p>
                <div class="mt-3" style="background:#ecfdf5;border-radius:10px;padding:16px;display:inline-block;">
                    <p style="font-size:0.85rem;margin:0;"><i class="bi bi-info-circle me-1"></i>What happens next:</p>
                    <ol style="font-size:0.85rem;text-align:left;margin:8px 0 0;padding-left:20px;">
                        <li>An administrator will review your information</li>
                        <li>Your module/course selections will be reviewed</li>
                        <li>Once approved, you'll receive your login credentials via email</li>
                    </ol>
                </div>
            </div>
        </div>

        <?php elseif ($error && !$invite): ?>
        <div class="reg-body">
            <div class="error-banner">
                <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:3rem;"></i>
                <h4 class="text-danger mt-3">Registration Unavailable</h4>
                <p class="text-muted"><?= htmlspecialchars($error) ?></p>
                <a href="login.php" class="btn btn-outline-success mt-3">Go to Login</a>
            </div>
        </div>

        <?php else: ?>
        <div class="reg-body">
            <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius:10px;">
                <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($invite['full_name'] || (isset($invite['department']) && $invite['department'])): ?>
            <div class="alert alert-info" style="border-radius:10px;background:#ecfdf5;border-color:#a7f3d0;color:#047857;">
                <i class="bi bi-person-badge me-2"></i>
                You've been invited to register as a lecturer
                <?php if (isset($invite['department']) && $invite['department']): ?> in the <strong><?= htmlspecialchars($invite['department']) ?></strong> department<?php endif; ?>
                <?php if (isset($invite['position']) && $invite['position']): ?> as a <strong><?= htmlspecialchars($invite['position']) ?></strong><?php endif; ?>.
            </div>
            <?php endif; ?>

            <form method="POST" id="lecturerRegForm">
                <!-- Personal Information -->
                <div class="section-title"><i class="bi bi-person me-2"></i>Personal Information</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" required
                            value="<?= htmlspecialchars($_POST['first_name'] ?? explode(' ', $invite['full_name'] ?? '')[0] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                            value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                            value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required
                            value="<?= htmlspecialchars($_POST['email'] ?? $invite['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+265..."
                            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select gender</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($_POST['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control" placeholder="e.g. AB123456" maxlength="20"
                            value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>">
                    </div>
                </div>

                <!-- Professional Information -->
                <div class="section-title"><i class="bi bi-briefcase me-2"></i>Professional Information</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="department" class="form-select" required>
                            <option value="">Select department</option>
                            <?php 
                            $sel_dept = $_POST['department'] ?? (isset($invite['department']) ? $invite['department'] : '');
                            foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $sel_dept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Position <span class="text-danger">*</span></label>
                        <select name="position" class="form-select" required>
                            <option value="">Select position</option>
                            <?php 
                            $positions = ['Lecturer', 'Senior Lecturer', 'Associate Professor', 'Professor', 'Part-time Lecturer', 'Teaching Assistant'];
                            $sel_pos = $_POST['position'] ?? $invite['position'] ?? '';
                            foreach ($positions as $p): ?>
                            <option value="<?= $p ?>" <?= $sel_pos === $p ? 'selected' : '' ?>><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Highest Qualification <span class="text-danger">*</span></label>
                        <select name="qualification" class="form-select" required>
                            <option value="">Select qualification</option>
                            <?php 
                            $quals = ['Certificate', 'Diploma', 'Bachelor\'s Degree', 'Postgraduate Diploma', 'Master\'s Degree', 'PhD / Doctorate', 'Post-Doctoral'];
                            $sel_qual = $_POST['qualification'] ?? '';
                            foreach ($quals as $q): ?>
                            <option value="<?= $q ?>" <?= $sel_qual === $q ? 'selected' : '' ?>><?= $q ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Specialization</label>
                        <input type="text" name="specialization" class="form-control" placeholder="e.g. Machine Learning, Organic Chemistry"
                            value="<?= htmlspecialchars($_POST['specialization'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Brief Bio</label>
                        <textarea name="bio" class="form-control" rows="3" placeholder="A brief professional bio (optional)"><?= htmlspecialchars($_POST['bio'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Module Selection -->
                <div class="section-title"><i class="bi bi-book me-2"></i>Module / Course Selection</div>
                <p style="font-size:0.85rem;color:#64748b;">
                    Select the modules you would like to teach. Filter by year and semester, then choose up to <strong>7 modules</strong>.
                    Modules already assigned to another lecturer are shown but marked.
                </p>

                <div class="module-filter-bar">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold mb-1" style="font-size:0.8rem;">Year of Study</label>
                            <select id="filterYear" class="form-select form-select-sm">
                                <option value="0">All Years</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold mb-1" style="font-size:0.8rem;">Semester</label>
                            <select id="filterSemester" class="form-select form-select-sm">
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
                        <div class="spinner-border spinner-border-sm text-success me-2" role="status"></div>
                        Select a year and semester to load available modules...
                    </div>
                </div>

                <!-- Hidden inputs for selected modules will be dynamically added -->
                <div id="selectedModulesInputs"></div>

                <hr class="my-4">
                <button type="submit" class="btn btn-submit" id="submitBtn">
                    <i class="bi bi-send me-2"></i>Submit Registration
                </button>
                <p class="text-center text-muted mt-3" style="font-size:0.8rem;">
                    <i class="bi bi-shield-check me-1"></i>
                    Your information is securely submitted. An administrator will review your application.
                </p>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        const MAX_MODULES = 7;
        let selectedModules = new Set();
        let allCourses = [];

        const filterYear = document.getElementById('filterYear');
        const filterSemester = document.getElementById('filterSemester');
        const moduleGrid = document.getElementById('moduleGrid');
        const moduleCounter = document.getElementById('moduleCounter');
        const selectedCount = document.getElementById('selectedCount');
        const selectedInputs = document.getElementById('selectedModulesInputs');

        // Restore selections from POST if form had errors
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected_modules'])): ?>
        <?php foreach ($_POST['selected_modules'] as $mid): ?>
        selectedModules.add(<?= (int)$mid ?>);
        <?php endforeach; ?>
        <?php endif; ?>

        function loadCourses() {
            const year = filterYear.value;
            const semester = filterSemester.value;
            
            moduleGrid.innerHTML = '<div class="loading-modules"><div class="spinner-border spinner-border-sm text-success me-2" role="status"></div> Loading modules...</div>';

            const params = new URLSearchParams({
                ajax: 'get_courses',
                token: '<?= htmlspecialchars($token) ?>',
                year: year,
                semester: semester
            });

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
                const isAssigned = c.lecturer_id && c.lecturer_id > 0 && c.current_lecturer;
                const atMax = selectedModules.size >= MAX_MODULES && !isSelected;

                html += `<label class="module-item ${isSelected ? 'selected' : ''} ${isAssigned ? 'assigned' : ''}" data-id="${c.course_id}">
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
                        ${isAssigned ? ' | <span style="color:#d97706;">Assigned: ' + escHtml(c.current_lecturer) + '</span>' : ''}
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
            renderCourses(); // Re-render to update disabled state
        };

        function updateCounter() {
            const count = selectedModules.size;
            selectedCount.textContent = count;
            
            moduleCounter.classList.remove('at-max', 'over-max');
            if (count >= MAX_MODULES) {
                moduleCounter.classList.add('at-max');
            }
            if (count > MAX_MODULES) {
                moduleCounter.classList.add('over-max');
            }
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
        document.getElementById('lecturerRegForm')?.addEventListener('submit', function(e) {
            if (selectedModules.size === 0) {
                e.preventDefault();
                alert('Please select at least one module to teach.');
                return;
            }
            if (selectedModules.size > MAX_MODULES) {
                e.preventDefault();
                alert('You can select a maximum of ' + MAX_MODULES + ' modules.');
                return;
            }
        });

        // Event listeners
        filterYear.addEventListener('change', loadCourses);
        filterSemester.addEventListener('change', loadCourses);

        // Initialize
        updateCounter();
        updateHiddenInputs();
        loadCourses(); // Load all courses initially
    })();
    </script>
</body>
</html>
