<?php
/**
 * Setup Examination Clearance System Tables
 * Run this once via browser to create the required tables
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$tables = [];

// 1. Exam clearance invites - generated links (like finance clearance invites)
$tables[] = "CREATE TABLE IF NOT EXISTS exam_clearance_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_token VARCHAR(64) NOT NULL UNIQUE,
    program_type ENUM('all','degree','professional','masters','doctorate') NOT NULL DEFAULT 'degree',
    clearance_type ENUM('midsemester','endsemester') NOT NULL DEFAULT 'endsemester',
    minimum_payment_percent INT DEFAULT 100 COMMENT '50 for mid-semester, 100 for end-semester',
    description VARCHAR(255) DEFAULT NULL,
    max_uses INT DEFAULT 0 COMMENT '0 = unlimited',
    times_used INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_token (invite_token),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 2. Exam clearance students - tracks students applying for exam clearance
$tables[] = "CREATE TABLE IF NOT EXISTS exam_clearance_students (
    clearance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL COMMENT 'Student registration number',
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    program VARCHAR(200) DEFAULT NULL,
    program_id INT DEFAULT NULL,
    program_type ENUM('degree','professional','masters','doctorate') NOT NULL DEFAULT 'degree',
    department VARCHAR(200) DEFAULT NULL,
    department_id INT DEFAULT NULL,
    campus VARCHAR(100) DEFAULT NULL,
    year_of_study INT DEFAULT NULL,
    gender ENUM('Male','Female','Other') DEFAULT NULL,
    national_id VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    entry_type VARCHAR(10) DEFAULT 'NE',
    semester ENUM('One','Two') DEFAULT 'One',
    year_of_registration YEAR DEFAULT NULL,
    invite_token VARCHAR(64) DEFAULT NULL,
    proof_of_payment VARCHAR(255) DEFAULT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    amount_claimed DECIMAL(12,2) DEFAULT 0.00,
    invoiced_amount DECIMAL(12,2) DEFAULT 0.00,
    registration_fee DECIMAL(12,2) DEFAULT 0.00,
    balance DECIMAL(12,2) DEFAULT 0.00,
    amount_paid DECIMAL(12,2) DEFAULT 0.00,
    proof_request_type ENUM('tuition','registration','both') DEFAULT NULL,
    required_amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Exact amount finance requires for clearance',
    revenue_recorded TINYINT(1) DEFAULT 0,
    status ENUM('registered','invoiced','proof_submitted','proof_requested','cleared','rejected') DEFAULT 'registered',
    clearance_type ENUM('midsemester','endsemester') NOT NULL DEFAULT 'endsemester',
    finance_notes TEXT DEFAULT NULL,
    cleared_by INT DEFAULT NULL,
    cleared_at DATETIME DEFAULT NULL,
    certificate_number VARCHAR(50) DEFAULT NULL,
    is_system_student TINYINT(1) DEFAULT 0 COMMENT '1 if student is from the main students table',
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_status (status),
    INDEX idx_invite (invite_token),
    INDEX idx_cert (certificate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 3. Exam clearance payments - tracks proof uploads & reviews
$tables[] = "CREATE TABLE IF NOT EXISTS exam_clearance_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    clearance_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    payment_date DATE DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    proof_file VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_notes TEXT DEFAULT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clearance_id) REFERENCES exam_clearance_students(clearance_id) ON DELETE CASCADE,
    INDEX idx_clearance (clearance_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

echo "<h2>Examination Clearance System - Database Setup</h2>";
echo "<pre>";

$success = 0;
$errors = 0;

foreach ($tables as $sql) {
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
    $tbl = $m[1] ?? 'unknown';
    
    if ($conn->query($sql)) {
        echo "&#10004; Table '$tbl' created/verified successfully.\n";
        $success++;
    } else {
        echo "&#10008; Error creating '$tbl': " . $conn->error . "\n";
        $errors++;
    }
}

echo "\n--- Done: $success succeeded, $errors failed ---\n";
echo "</pre>";
echo "<p><a href='finance/exam_clearance_invites.php'>Go to Exam Clearance Invites &rarr;</a></p>";
echo "<p><a href='finance/exam_clearance_students.php'>Go to Exam Clearance Students &rarr;</a></p>";
echo "<p><a href='student/exam_clearance.php'>Go to Student Exam Clearance &rarr;</a></p>";
$conn->close();
?>
