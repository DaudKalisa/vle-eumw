<?php
/**
 * Setup Script - Student Registration Invite Links
 * Creates the table for managing invite tokens that allow
 * admins/ODL coordinators/super_admins to send registration links to students.
 */
require_once 'includes/config.php';

$conn = getDbConnection();
$messages = [];
$errors = [];

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
<title>Student Invite Links Setup</title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css'>
</head><body class='bg-light'><div class='container py-5'>";
echo "<h2 class='text-primary mb-4'><i class='bi bi-link-45deg me-2'></i>Student Registration Invite Links Setup</h2>";

// 1. Create student_registration_invites table
$sql = "CREATE TABLE IF NOT EXISTS student_registration_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL COMMENT 'Optional: pre-fill email if sent to specific student',
    full_name VARCHAR(150) DEFAULT NULL COMMENT 'Optional: pre-fill name',
    department_id INT DEFAULT NULL COMMENT 'Optional: pre-assign department',
    program VARCHAR(200) DEFAULT NULL COMMENT 'Optional: pre-assign program',
    campus VARCHAR(100) DEFAULT NULL COMMENT 'Optional: pre-assign campus',
    program_type VARCHAR(50) DEFAULT 'degree',
    year_of_study INT DEFAULT 1,
    semester VARCHAR(10) DEFAULT 'One',
    entry_type VARCHAR(10) DEFAULT 'NE',
    max_uses INT DEFAULT 1 COMMENT '1 = single use, >1 = multi-use link',
    times_used INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL COMMENT 'NULL = never expires',
    created_by INT NOT NULL COMMENT 'FK to users.user_id (admin/coordinator who created)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL COMMENT 'Admin notes about this invite',
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $messages[] = "&#10004; Created/verified <code>student_registration_invites</code> table";
} else {
    $errors[] = "&#10008; Failed to create table: " . $conn->error;
}

// 2. Create student_invite_registrations table to track who registered via invites (with approval workflow)
$sql2 = "CREATE TABLE IF NOT EXISTS student_invite_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_id INT NOT NULL DEFAULT 0 COMMENT '0 = general registration (no invite)',
    student_id VARCHAR(50) DEFAULT NULL COMMENT 'Generated student_id (set on approval)',
    student_id_number VARCHAR(50) DEFAULT NULL COMMENT 'Existing student ID if transfer/returning',
    user_id INT DEFAULT NULL COMMENT 'The user_id created (set on approval)',
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    preferred_username VARCHAR(100) DEFAULT NULL COMMENT 'Student preferred username',
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    program VARCHAR(200) DEFAULT NULL,
    program_type VARCHAR(50) DEFAULT 'degree',
    campus VARCHAR(100) DEFAULT 'Mzuzu Campus',
    year_of_registration INT DEFAULT NULL,
    year_of_study INT DEFAULT 1,
    semester VARCHAR(10) DEFAULT 'One',
    entry_type VARCHAR(10) DEFAULT 'NE',
    student_type VARCHAR(30) DEFAULT 'new_student',
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL COMMENT 'Admin who approved/rejected',
    reviewed_at DATETIME DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    INDEX idx_invite (invite_id),
    INDEX idx_student (student_id),
    INDEX idx_status (status),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql2)) {
    $messages[] = "&#10004; Created/verified <code>student_invite_registrations</code> table";
} else {
    $errors[] = "&#10008; Failed to create registrations tracking table: " . $conn->error;
}

// Add new columns if table already exists (upgrade path)
$alter_cols = [
    "student_id_number VARCHAR(50) DEFAULT NULL COMMENT 'Existing student ID if transfer/returning' AFTER student_id",
    "preferred_username VARCHAR(100) DEFAULT NULL COMMENT 'Student preferred username' AFTER last_name",
    "year_of_registration INT DEFAULT NULL AFTER campus"
];
foreach ($alter_cols as $col_def) {
    $col_name = explode(' ', trim($col_def))[0];
    $check = $conn->query("SHOW COLUMNS FROM student_invite_registrations LIKE '$col_name'");
    if ($check && $check->num_rows === 0) {
        if ($conn->query("ALTER TABLE student_invite_registrations ADD COLUMN $col_def")) {
            $messages[] = "&#10004; Added column <code>$col_name</code> to student_invite_registrations";
        } else {
            $errors[] = "&#10008; Failed to add column $col_name: " . $conn->error;
        }
    }
}

// Update invite_id default to allow 0 for general registrations
$conn->query("ALTER TABLE student_invite_registrations MODIFY COLUMN invite_id INT NOT NULL DEFAULT 0");

// Display results
foreach ($messages as $msg) {
    echo "<div class='alert alert-success'>$msg</div>";
}
foreach ($errors as $err) {
    echo "<div class='alert alert-danger'>$err</div>";
}

if (empty($errors)) {
    echo "<div class='alert alert-info mt-3'>
        <i class='bi bi-info-circle me-2'></i>
        <strong>Setup complete!</strong> You can now use the <strong>Student Invite Links</strong> feature from the Admin portal.
        <br><a href='admin/student_invite_links.php' class='btn btn-primary btn-sm mt-2'><i class='bi bi-link-45deg me-1'></i> Go to Invite Links</a>
    </div>";
}

echo "</div></body></html>";
?>
