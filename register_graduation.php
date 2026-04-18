<?php
/**
 * Public Registration Form – Graduation Clearance
 * Students join via invite link, fill application details, and submit for clearance.
 * register_graduation.php?token=XXXXX
 */
require_once 'includes/config.php';
if (file_exists('includes/email.php')) require_once 'includes/email.php';
$conn = getDbConnection();

// ── Ensure tables ──────────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS graduation_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, student_id_number VARCHAR(50) DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL, middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL, gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(30) DEFAULT NULL, address TEXT DEFAULT NULL,
    campus VARCHAR(100) DEFAULT 'Blantyre Campus', program VARCHAR(200) DEFAULT NULL,
    department_id INT DEFAULT NULL, year_of_entry YEAR DEFAULT NULL,
    year_of_completion YEAR DEFAULT NULL,
    transcript_processed_before TINYINT(1) DEFAULT 0,
    transcript_processed_date DATE DEFAULT NULL,
    application_type ENUM('clearance','transcript') DEFAULT 'clearance',
    status ENUM('pending','finance_approved','finance_referred','ict_approved',
                'dean_approved','rc_approved','librarian_approved','admin_generated',
                'registrar_approved','admissions_filed','completed','rejected') DEFAULT 'pending',
    current_step VARCHAR(50) DEFAULT 'finance',
    rejection_reason TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$token = trim($_GET['token'] ?? '');
$success = '';
$error = '';
$invite = [];

if (empty($token)) {
    $error = 'No registration token provided. Please use the link sent by the university.';
} else {
    $stmt = $conn->prepare("SELECT * FROM student_registration_invites WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();

    if (!$invite)                               $error = 'This link is invalid.';
    elseif (empty($invite['is_graduation_student'])) $error = 'This is not a graduation clearance link.';
    elseif (!$invite['is_active'])              $error = 'This link has been deactivated.';
    elseif ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) $error = 'This link has expired.';
    elseif ($invite['max_uses'] > 0 && $invite['times_used'] >= $invite['max_uses']) $error = 'This link has reached its maximum uses.';
}

// Departments & Programs
$departments = [];
$dr = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
if ($dr) while ($d = $dr->fetch_assoc()) $departments[] = $d;

$programs = [];
// Also fetch department_id if available (for auto-select when program is chosen)
$has_prog_dept = $conn->query("SHOW COLUMNS FROM programs LIKE 'department_id'")->num_rows > 0;
if (!$has_prog_dept) {
    $conn->query("ALTER TABLE programs ADD COLUMN department_id INT DEFAULT NULL");
    $has_prog_dept = true;
}
$pr = $conn->query("SELECT program_id, program_name, department_id FROM programs WHERE is_active=1 ORDER BY program_name");
if ($pr) while ($p = $pr->fetch_assoc()) $programs[] = $p;

// ── Handle submission ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($invite) && empty($error)) {
    $first_name   = trim($_POST['first_name'] ?? '');
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $gender       = $_POST['gender'] ?? '';
    $national_id  = trim($_POST['national_id'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $student_id_number = trim($_POST['student_id_number'] ?? '');
    $campus       = $_POST['campus'] ?? ($invite['campus'] ?? 'Blantyre Campus');
    $program      = $_POST['program'] ?? ($invite['program'] ?? '');
    $department_id= !empty($_POST['department_id']) ? (int)$_POST['department_id'] : ($invite['department_id'] ?? null);
    $year_of_entry      = (int)($_POST['year_of_entry'] ?? date('Y') - 4);
    $year_of_completion = (int)($_POST['year_of_completion'] ?? date('Y'));
    $transcript_before  = isset($_POST['transcript_processed_before']) ? 1 : 0;
    $transcript_date    = $transcript_before && !empty($_POST['transcript_processed_date']) ? $_POST['transcript_processed_date'] : null;
    $app_type     = $_POST['application_type'] ?? 'clearance';
    $preferred_username = trim($_POST['preferred_username'] ?? '');
    $password     = $_POST['password'] ?? '';

    // Validate
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = 'First name, last name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        // Check duplicate email
        $ck = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
        $ck->bind_param("ss", $email, $email);
        $ck->execute();
        if ($ck->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists. Contact admin.';
        }
    }

    if (empty($error)) {
        $conn->begin_transaction();
        try {
            // 1. Create user account (pending, needs admin approval)
            // Username is the student ID (or fallback to preferred/first.last)
            $username = !empty($student_id_number)
                ? $student_id_number
                : ($preferred_username ?: strtolower($first_name . '.' . $last_name));
            // Ensure unique username
            $uCheck = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $uCheck->bind_param("s", $username);
            $uCheck->execute();
            if ($uCheck->get_result()->num_rows > 0) {
                if (empty($student_id_number)) {
                    $username .= rand(100, 999);
                } else {
                    throw new \Exception('A registration with this Student ID already exists. Please contact the admin.');
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $full_name = trim("$first_name $middle_name $last_name");
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            // Insert into student_invite_registrations for admin approval
            $stmt = $conn->prepare("INSERT INTO student_invite_registrations 
                (invite_id, student_id_number, first_name, middle_name, last_name, preferred_username, 
                 email, phone, gender, national_id, address, department_id, program, campus,
                 year_of_registration, status, ip_address, student_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'graduation_student')");
            $invite_id = $invite['invite_id'];
            $yr = date('Y');
            $stmt->bind_param("issssssssssissss",
                $invite_id, $student_id_number, $first_name, $middle_name, $last_name,
                $preferred_username, $email, $phone, $gender, $national_id, $address,
                $department_id, $program, $campus, $yr, $ip);
            $stmt->execute();
            $reg_id = $conn->insert_id;

            // Store graduation-specific data in a temp table or in notes
            // We'll store the extra graduation fields in the notes/selected_modules column as JSON
            $grad_data = json_encode([
                'year_of_entry'      => $year_of_entry,
                'year_of_completion' => $year_of_completion,
                'transcript_processed_before' => $transcript_before,
                'transcript_processed_date'   => $transcript_date,
                'application_type'   => $app_type,
                'password_hash'      => $hash,
                'username'           => $username,
            ]);
            $conn->query("UPDATE student_invite_registrations SET selected_modules = '" . $conn->real_escape_string($grad_data) . "' WHERE registration_id = $reg_id");

            // Increment invite usage
            $conn->query("UPDATE student_registration_invites SET times_used = times_used + 1 WHERE invite_id = " . $invite['invite_id']);

            $conn->commit();
            $success = 'Your graduation clearance application has been submitted! You will receive an email once your account is approved by the admin.';

            // Send confirmation email to student with login credentials
            if (function_exists('isEmailEnabled') && isEmailEnabled()) {
                $student_email_body = "
<h2 style='color:#059669;'>Application Received</h2>
<p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
<p>Your graduation clearance application has been received and is awaiting admin review.</p>
<p>Your login credentials (will be active once your account is approved):</p>
<ul>
  <li><strong>Username:</strong> " . htmlspecialchars($username) . "</li>
  <li><strong>Password:</strong> " . htmlspecialchars($password) . "</li>
</ul>
<p>You will receive another email when your account is approved.</p>
<p>Thank you,<br>Exploits University Malawi</p>
";
                sendEmail($email, $full_name, 'Graduation Application Received – EUMW VLE', $student_email_body);
            }

            // Notify admin users via email
            if (function_exists('isEmailEnabled') && isEmailEnabled() && function_exists('sendEmail')) {
                $admin_notify = $conn->query("SELECT email, COALESCE(full_name, username) AS name FROM users WHERE role IN ('admin','staff') AND is_active=1 AND email IS NOT NULL AND email != ''");
                if ($admin_notify) {
                    $admin_body = "
<h2 style='color:#1e40af;'>New Graduation Application</h2>
<p>A new graduation clearance application has been submitted and requires your review.</p>
<table style='border-collapse:collapse;width:100%;font-size:14px;'>
  <tr style='background:#f1f5f9;'><td style='padding:8px;font-weight:bold;'>Name:</td><td style='padding:8px;'>" . htmlspecialchars($full_name) . "</td></tr>
  <tr><td style='padding:8px;font-weight:bold;'>Student ID:</td><td style='padding:8px;'>" . htmlspecialchars($student_id_number) . "</td></tr>
  <tr style='background:#f1f5f9;'><td style='padding:8px;font-weight:bold;'>Email:</td><td style='padding:8px;'>" . htmlspecialchars($email) . "</td></tr>
  <tr><td style='padding:8px;font-weight:bold;'>Campus:</td><td style='padding:8px;'>" . htmlspecialchars($campus) . "</td></tr>
  <tr style='background:#f1f5f9;'><td style='padding:8px;font-weight:bold;'>Program:</td><td style='padding:8px;'>" . htmlspecialchars($program) . "</td></tr>
  <tr><td style='padding:8px;font-weight:bold;'>Application Type:</td><td style='padding:8px;'>" . htmlspecialchars(ucfirst($app_type)) . "</td></tr>
</table>
<br>
<a href='admin/graduation_students.php' style='background:#1e40af;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block;'>Review Application</a>
";
                    while ($admin_row = $admin_notify->fetch_assoc()) {
                        sendEmail($admin_row['email'], $admin_row['name'], 'New Graduation Application – Action Required', $admin_body);
                    }
                }
            }
        } catch (\Throwable $e) {
            $conn->rollback();
            $error = 'Submission failed: ' . $e->getMessage();
        }
    }
}

$page_title = 'Graduation Clearance Registration';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> – EUMW VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f0f4f8 0%,#e2e8f0 100%);min-height:100vh;}
        .reg-header{background:linear-gradient(135deg,#059669,#047857);color:#fff;text-align:center;padding:2rem 1rem;border-radius:0 0 24px 24px;}
        .reg-header h2{font-weight:700;}
        .form-card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:800px;margin:-2rem auto 2rem;padding:2rem;}
        .section-title{font-weight:600;color:#059669;border-bottom:2px solid #d1fae5;padding-bottom:.5rem;margin:1.5rem 0 1rem;}
        .section-title i{margin-right:.5rem;}
        .form-label{font-weight:500;font-size:.9rem;}
    </style>
</head>
<body>
<div class="reg-header">
    <h2><i class="bi bi-mortarboard-fill me-2"></i>Graduation Clearance Application</h2>
    <p class="mb-0 opacity-75">Exploits University of Malawi</p>
</div>

<div class="container">
<?php if ($success): ?>
    <div class="form-card text-center">
        <i class="bi bi-check-circle-fill text-success" style="font-size:4rem;"></i>
        <h4 class="mt-3 text-success">Application Submitted</h4>
        <p class="text-muted"><?= $success ?></p>
        <a href="login.php" class="btn btn-success mt-3"><i class="bi bi-box-arrow-in-right me-1"></i>Go to Login</a>
    </div>
<?php elseif ($error && empty($invite)): ?>
    <div class="form-card text-center">
        <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:4rem;"></i>
        <h4 class="mt-3 text-danger">Registration Unavailable</h4>
        <p class="text-muted"><?= htmlspecialchars($error) ?></p>
    </div>
<?php else: ?>
    <div class="form-card">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post" id="gradForm">
            <!-- Personal Details -->
            <h5 class="section-title"><i class="bi bi-person-fill"></i>Personal Details</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($invite['full_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($invite['email'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">-- Select --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">National ID</label>
                    <input type="text" name="national_id" class="form-control">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control">
                </div>
            </div>

            <!-- Academic Details -->
            <h5 class="section-title"><i class="bi bi-journal-bookmark-fill"></i>Academic Details</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Student ID Number <span class="text-danger">*</span></label>
                    <input type="text" name="student_id_number" id="studentIdInput" class="form-control" placeholder="e.g. BSC-CS-2020-001" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Campus <span class="text-danger">*</span></label>
                    <select name="campus" class="form-select" required>
                        <option value="Blantyre Campus" <?= ($invite['campus'] ?? '') === 'Blantyre Campus' ? 'selected' : '' ?>>Blantyre Campus</option>
                        <option value="Lilongwe Campus" <?= ($invite['campus'] ?? '') === 'Lilongwe Campus' ? 'selected' : '' ?>>Lilongwe Campus</option>
                        <option value="Mzuzu Campus" <?= ($invite['campus'] ?? '') === 'Mzuzu Campus' ? 'selected' : '' ?>>Mzuzu Campus</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Program</label>
                    <select name="program" id="programSelect" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= htmlspecialchars($p['program_name']) ?>"
                            data-dept="<?= (int)($p['department_id'] ?? 0) ?>"
                            <?= ($invite['program'] ?? '') === $p['program_name'] ? 'selected' : '' ?>><?= htmlspecialchars($p['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" id="departmentSelect" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= ($invite['department_id'] ?? '') == $d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Year of Entry <span class="text-danger">*</span></label>
                    <select name="year_of_entry" class="form-select" required>
                        <?php for ($y = date('Y'); $y >= date('Y') - 10; $y--): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Year of Completion <span class="text-danger">*</span></label>
                    <select name="year_of_completion" class="form-select" required>
                        <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Transcript History -->
            <h5 class="section-title"><i class="bi bi-file-earmark-text-fill"></i>Transcript History</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Was your transcript processed before?</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="transcript_processed_before" value="1" id="tranYes" onchange="document.getElementById('tranDate').style.display='block'">
                        <label class="form-check-label" for="tranYes">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="transcript_processed_before" value="0" id="tranNo" checked onchange="document.getElementById('tranDate').style.display='none'">
                        <label class="form-check-label" for="tranNo">No</label>
                    </div>
                </div>
                <div class="col-md-6" id="tranDate" style="display:none">
                    <label class="form-label">Date it was processed</label>
                    <input type="date" name="transcript_processed_date" class="form-control">
                </div>
            </div>

            <!-- Application Type -->
            <h5 class="section-title"><i class="bi bi-clipboard-check-fill"></i>Application Type</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="application_type" value="clearance" id="typeClear" checked>
                        <label class="form-check-label" for="typeClear"><strong>Graduation Clearance</strong> – Full clearance for graduation</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="application_type" value="transcript" id="typeTran">
                        <label class="form-check-label" for="typeTran"><strong>Transcript Only</strong> – Request academic transcript</label>
                    </div>
                </div>
            </div>

            <!-- Account Credentials -->
            <h5 class="section-title"><i class="bi bi-shield-lock-fill"></i>Account Credentials</h5>
            <p class="text-muted small mb-3">Your username will be set to your <strong>Student ID Number</strong>. Choose a secure password.</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Username</label>
                    <input type="text" name="preferred_username" id="usernameDisplay" class="form-control bg-light" readonly placeholder="Auto-filled from Student ID">
                    <small class="text-muted">Set automatically to your Student ID</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="6" id="regPwd">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" required id="regPwdC">
                </div>
            </div>

            <hr class="my-4">
            <div class="d-grid">
                <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-send-fill me-2"></i>Submit Application</button>
            </div>
        </form>
    </div>
<?php endif; ?>
</div>

<script>
// Auto-fill username from student ID
var studentIdInput = document.getElementById('studentIdInput');
var usernameDisplay = document.getElementById('usernameDisplay');
if (studentIdInput && usernameDisplay) {
    studentIdInput.addEventListener('input', function() {
        usernameDisplay.value = this.value.trim();
    });
}

// Auto-select department when program is chosen
var programSelect = document.getElementById('programSelect');
var departmentSelect = document.getElementById('departmentSelect');
if (programSelect && departmentSelect) {
    programSelect.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        var deptId = opt ? opt.getAttribute('data-dept') : '0';
        if (deptId && deptId !== '0') {
            departmentSelect.value = deptId;
        }
    });
}

document.getElementById('gradForm')?.addEventListener('submit', function(e) {
    var p = document.getElementById('regPwd').value;
    var c = document.getElementById('regPwdC').value;
    if (p !== c) { e.preventDefault(); alert('Passwords do not match!'); return; }
    var sid = document.getElementById('studentIdInput')?.value.trim();
    if (!sid) { e.preventDefault(); alert('Student ID Number is required.'); return; }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
