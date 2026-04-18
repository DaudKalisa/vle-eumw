<?php
/**
 * Setup Finance Clearance System Tables
 * Run this once via browser to create the required tables
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$tables = [];

// 1. Finance clearance invites - generated links
$tables[] = "CREATE TABLE IF NOT EXISTS finance_clearance_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_token VARCHAR(64) NOT NULL UNIQUE,
    program_type ENUM('degree','masters','doctorate') NOT NULL DEFAULT 'degree',
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

// 2. Finance clearance students - separate from main students (convertible to full students)
$tables[] = "CREATE TABLE IF NOT EXISTS finance_clearance_students (
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
    entry_type VARCHAR(10) DEFAULT 'NE' COMMENT 'NE=New Entry, ME=Mature Entry, ODL=Open Distance, PC=Prior Credit',
    semester ENUM('One','Two') DEFAULT 'One',
    year_of_registration YEAR DEFAULT NULL,
    invite_token VARCHAR(64) DEFAULT NULL,
    proof_of_payment VARCHAR(255) DEFAULT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    amount_claimed DECIMAL(12,2) DEFAULT 0.00,
    invoiced_amount DECIMAL(12,2) DEFAULT 0.00,
    balance DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('registered','invoiced','proof_submitted','cleared','rejected') DEFAULT 'registered',
    finance_notes TEXT DEFAULT NULL,
    cleared_by INT DEFAULT NULL,
    cleared_at DATETIME DEFAULT NULL,
    certificate_number VARCHAR(50) DEFAULT NULL,
    converted_to_student TINYINT(1) DEFAULT 0 COMMENT '1 if converted to full student',
    converted_at DATETIME DEFAULT NULL,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_status (status),
    INDEX idx_invite (invite_token),
    INDEX idx_cert (certificate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 3. Finance clearance payments - tracks proof uploads & reviews
$tables[] = "CREATE TABLE IF NOT EXISTS finance_clearance_payments (
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
    FOREIGN KEY (clearance_id) REFERENCES finance_clearance_students(clearance_id) ON DELETE CASCADE,
    INDEX idx_clearance (clearance_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

echo "<h2>Finance Clearance System - Database Setup</h2>";
echo "<pre>";

$success = 0;
$errors = 0;

foreach ($tables as $sql) {
    // Extract table name
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
    $tbl = $m[1] ?? 'unknown';
    
    if ($conn->query($sql)) {
        echo "✅ Table '$tbl' created/verified successfully.\n";
        $success++;
    } else {
        echo "❌ Error creating '$tbl': " . $conn->error . "\n";
        $errors++;
    }
}

echo "\n--- Done: $success succeeded, $errors failed ---\n";
echo "</pre>";
echo "<p><a href='finance/finance_clearance_invites.php'>Go to Finance Clearance Invites →</a></p>";
echo "<p><a href='finance/Finance_clearence_students.php'>Go to Finance Clearance Students →</a></p>";
?>
