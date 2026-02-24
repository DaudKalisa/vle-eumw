<?php
// setup_fee_system.php - Create fee management tables and update student_finances
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h3>Setting up Fee Management System...</h3>";

// 1. Create fee_settings table
$sql_fees = "CREATE TABLE IF NOT EXISTS fee_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    application_fee DECIMAL(10,2) DEFAULT 5500.00,
    registration_fee DECIMAL(10,2) DEFAULT 39500.00,
    tuition_degree DECIMAL(10,2) DEFAULT 500000.00,
    tuition_professional DECIMAL(10,2) DEFAULT 200000.00,
    tuition_masters DECIMAL(10,2) DEFAULT 1100000.00,
    tuition_doctorate DECIMAL(10,2) DEFAULT 2200000.00,
    supplementary_exam_fee DECIMAL(10,2) DEFAULT 50000.00,
    deferred_exam_fee DECIMAL(10,2) DEFAULT 50000.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql_fees) === TRUE) {
    echo "✅ Fee settings table created successfully!<br>";
    
    // Insert default fees
    $check = $conn->query("SELECT COUNT(*) as count FROM fee_settings");
    $row = $check->fetch_assoc();
    
    if ($row['count'] == 0) {
        $insert = "INSERT INTO fee_settings (application_fee, registration_fee, tuition_degree, tuition_professional, tuition_masters, tuition_doctorate, supplementary_exam_fee, deferred_exam_fee) 
                   VALUES (5500, 39500, 500000, 200000, 1100000, 2200000, 50000, 50000)";
        
        if ($conn->query($insert) === TRUE) {
            echo "✅ Default fee settings inserted!<br>";
        }
    }
} else {
    echo "❌ Error creating fee_settings table: " . $conn->error . "<br>";
}

// 2. Add program_type to students table if not exists
$check_column = $conn->query("SHOW COLUMNS FROM students LIKE 'program_type'");
if ($check_column->num_rows == 0) {
    $sql = "ALTER TABLE students ADD COLUMN program_type ENUM('degree', 'professional', 'masters', 'doctorate') DEFAULT 'degree' AFTER department";
    if ($conn->query($sql) === TRUE) {
        echo "✅ Added program_type column to students table!<br>";
    } else {
        echo "❌ Error adding program_type: " . $conn->error . "<br>";
    }
}

// 3. Add application_fee column to student_finances if not exists
$check_app_fee = $conn->query("SHOW COLUMNS FROM student_finances LIKE 'application_fee_paid'");
if ($check_app_fee->num_rows == 0) {
    $sql = "ALTER TABLE student_finances 
            ADD COLUMN application_fee_paid DECIMAL(10,2) DEFAULT 0 AFTER student_id,
            ADD COLUMN application_fee_date DATE DEFAULT NULL AFTER application_fee_paid,
            ADD COLUMN expected_tuition DECIMAL(10,2) DEFAULT 500000.00 AFTER registration_paid_date,
            ADD COLUMN expected_total DECIMAL(10,2) DEFAULT 545000.00 AFTER expected_tuition";
    
    if ($conn->query($sql) === TRUE) {
        echo "✅ Added application fee and expected amounts to student_finances!<br>";
    } else {
        echo "❌ Error: " . $conn->error . "<br>";
    }
}

// 4. Update existing student_finances records
$update = "UPDATE student_finances SET 
           application_fee_paid = 0,
           expected_tuition = 500000,
           expected_total = 545000
           WHERE application_fee_paid IS NULL OR application_fee_paid = 0";

if ($conn->query($update) === TRUE) {
    echo "✅ Updated existing student finance records!<br>";
}

echo "<br><a href='admin/fee_settings.php' class='btn btn-primary'>Manage Fee Settings</a> | ";
echo "<a href='admin/dashboard.php'>Go to Dashboard</a>";
?>
