<?php
/**
 * Migration: Add new columns to finance_clearance_students
 * Adds fields needed for converting clearance students to full students.
 * Safe to run multiple times - uses IF NOT EXISTS logic.
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$columns = [
    "program_id INT DEFAULT NULL AFTER program",
    "department_id INT DEFAULT NULL AFTER department",
    "gender ENUM('Male','Female','Other') DEFAULT NULL AFTER year_of_study",
    "national_id VARCHAR(50) DEFAULT NULL AFTER gender",
    "address TEXT DEFAULT NULL AFTER national_id",
    "entry_type VARCHAR(10) DEFAULT 'NE' AFTER address",
    "semester ENUM('One','Two') DEFAULT 'One' AFTER entry_type",
    "year_of_registration YEAR DEFAULT NULL AFTER semester",
    "converted_to_student TINYINT(1) DEFAULT 0 AFTER certificate_number",
    "converted_at DATETIME DEFAULT NULL AFTER converted_to_student",
];

echo "<h2>Finance Clearance Students - Column Migration</h2><pre>";

// Update program_type enum to include 'professional'
$conn->query("ALTER TABLE finance_clearance_students MODIFY COLUMN program_type ENUM('degree','professional','masters','doctorate') NOT NULL DEFAULT 'degree'");
echo "Updated program_type enum to include 'professional'\n";

foreach ($columns as $col_def) {
    $col_name = explode(' ', trim($col_def))[0];
    
    $check = $conn->query("SHOW COLUMNS FROM finance_clearance_students LIKE '$col_name'");
    if ($check && $check->num_rows > 0) {
        echo "Column '$col_name' already exists - skipped\n";
    } else {
        $sql = "ALTER TABLE finance_clearance_students ADD COLUMN $col_def";
        if ($conn->query($sql)) {
            echo "Added column '$col_name' ✓\n";
        } else {
            echo "FAILED to add '$col_name': " . $conn->error . "\n";
        }
    }
}

echo "\nDone!</pre>";
echo "<p><a href='finance/Finance_clearence_students.php'>← Back to Finance Clearance Students</a></p>";
$conn->close();
