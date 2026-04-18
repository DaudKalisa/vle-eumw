<?php
/**
 * Setup script – Graduation Clearance System
 * Run once via browser: http://localhost/vle-eumw/setup_graduation_clearance.php
 */
require_once 'includes/config.php';
$conn = getDbConnection();
$msgs = [];

function run(mysqli $conn, string $sql, string $label, array &$msgs): void {
    if ($conn->query($sql)) {
        $msgs[] = ['ok', $label];
    } else {
        $msgs[] = ['err', "$label: " . $conn->error];
    }
}

// ── 1. Graduation invite flags on existing table ──────────────────────────────
foreach (['is_graduation_student' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER notes"] as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        run($conn, "ALTER TABLE student_registration_invites ADD COLUMN $col $def", "Add $col to student_registration_invites", $msgs);
    } else {
        $msgs[] = ['ok', "Column $col already exists"];
    }
}

// ── 2. Graduation applications ─────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_applications (
    application_id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT NOT NULL,
    student_id_number       VARCHAR(50) DEFAULT NULL,
    first_name              VARCHAR(100) NOT NULL,
    middle_name             VARCHAR(100) DEFAULT NULL,
    last_name               VARCHAR(100) NOT NULL,
    email                   VARCHAR(150) NOT NULL,
    phone                   VARCHAR(30)  DEFAULT NULL,
    gender                  VARCHAR(10)  DEFAULT NULL,
    national_id             VARCHAR(30)  DEFAULT NULL,
    address                 TEXT         DEFAULT NULL,
    campus                  VARCHAR(100) DEFAULT 'Mzuzu Campus',
    program                 VARCHAR(200) DEFAULT NULL,
    department_id           INT          DEFAULT NULL,
    year_of_entry           YEAR         DEFAULT NULL,
    year_of_completion      YEAR         DEFAULT NULL,
    transcript_processed_before  TINYINT(1) DEFAULT 0,
    transcript_processed_date    DATE       DEFAULT NULL,
    application_type        ENUM('clearance','transcript') DEFAULT 'clearance',
    status                  ENUM('pending','finance_approved','finance_referred',
                                 'ict_approved','dean_approved','rc_approved',
                                 'librarian_approved','admin_generated',
                                 'registrar_approved','admissions_filed',
                                 'completed','rejected') DEFAULT 'pending',
    current_step            VARCHAR(50) DEFAULT 'finance',
    rejection_reason        TEXT        DEFAULT NULL,
    submitted_at            TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_campus (campus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_applications", $msgs);

// ── 3. Clearance workflow steps ────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_clearance_steps (
    step_id         INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    step_name       ENUM('finance','ict','dean','rc','librarian','admin','registrar','admissions') NOT NULL,
    officer_user_id INT          DEFAULT NULL,
    officer_name    VARCHAR(200) DEFAULT NULL,
    officer_role    VARCHAR(100) DEFAULT NULL,
    officer_title   VARCHAR(100) DEFAULT NULL,
    status          ENUM('pending','approved','rejected','referred') DEFAULT 'pending',
    notes           TEXT         DEFAULT NULL,
    step_data       TEXT         DEFAULT NULL,
    signature_text  VARCHAR(255) DEFAULT NULL,
    actioned_at     DATETIME     DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_app_step (application_id, step_name),
    INDEX idx_application (application_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_clearance_steps", $msgs);

// ── 4. ICT module selections ───────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_ict_modules (
    module_id       INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    year_of_study   INT          DEFAULT NULL,
    module_code     VARCHAR(30)  DEFAULT NULL,
    module_name     VARCHAR(200) NOT NULL,
    marks_obtained  DECIMAL(5,2) DEFAULT NULL,
    grade           VARCHAR(5)   DEFAULT NULL,
    grade_point     DECIMAL(3,2) DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_ict_modules", $msgs);

// ── 5. Finance clearance details ───────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_finance_details (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    application_id          INT NOT NULL UNIQUE,
    tuition_fee_balance     DECIMAL(12,2) DEFAULT 0.00,
    dissertation_fee_paid   TINYINT(1)    DEFAULT 0,
    graduation_fee_paid     TINYINT(1)    DEFAULT 0,
    graduation_fee_amount   DECIMAL(12,2) DEFAULT 0.00,
    other_charges           DECIMAL(12,2) DEFAULT 0.00,
    decision               ENUM('approved','referred') DEFAULT 'approved',
    referral_campus        VARCHAR(100)  DEFAULT NULL,
    notes                  TEXT          DEFAULT NULL,
    created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_finance_details", $msgs);

// ── 6. Overall grade summary (set by ICT officer) ─────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_grade_summary (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    application_id      INT NOT NULL UNIQUE,
    gpa                 DECIMAL(4,2) DEFAULT NULL,
    overall_grade       VARCHAR(100) DEFAULT NULL,
    classification      ENUM('Distinction','Merit','Credit','Pass','Fail') DEFAULT NULL,
    total_credits       INT          DEFAULT NULL,
    remarks             TEXT         DEFAULT NULL,
    set_by              INT          DEFAULT NULL,
    set_at              DATETIME     DEFAULT NULL,
    INDEX idx_application (application_id),
    INDEX idx_classification (classification)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_grade_summary", $msgs);

// ── 7. Librarian clearance ─────────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS graduation_library_details (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    application_id          INT NOT NULL UNIQUE,
    has_outstanding_books   TINYINT(1)   DEFAULT 0,
    books_list              TEXT         DEFAULT NULL,
    cleared                 TINYINT(1)   DEFAULT 0,
    notes                   TEXT         DEFAULT NULL,
    created_at              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create graduation_library_details", $msgs);

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Graduation Clearance Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light p-4">
<div class="container" style="max-width:700px">
<h4 class="mb-3"><i class="bi bi-mortarboard-fill"></i> Graduation Clearance Setup</h4>
<ul class="list-group">';
foreach ($msgs as [$type, $msg]) {
    $cls = $type === 'ok' ? 'list-group-item-success' : 'list-group-item-danger';
    $icon = $type === 'ok' ? '✔' : '✘';
    echo "<li class=\"list-group-item $cls\">$icon $msg</li>";
}
echo '</ul>
<div class="mt-3"><a href="admin/graduation_students.php" class="btn btn-primary">Go to Graduation Students</a>
<a href="admin/graduation_invite_links.php" class="btn btn-outline-secondary ms-2">Manage Invite Links</a></div>
</div></body></html>';
