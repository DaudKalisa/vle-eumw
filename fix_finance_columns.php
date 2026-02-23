<?php
// fix_finance_columns.php - Ensure all necessary columns exist in student_finances table
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Fixing Finance Columns...</h2>";

// Check and add missing columns one by one
$columns_to_check = [
    'application_fee_paid' => "ALTER TABLE student_finances ADD COLUMN application_fee_paid DECIMAL(10,2) DEFAULT 0 AFTER student_id",
    'application_fee_date' => "ALTER TABLE student_finances ADD COLUMN application_fee_date DATE DEFAULT NULL AFTER application_fee_paid",
    'expected_total' => "ALTER TABLE student_finances ADD COLUMN expected_total DECIMAL(10,2) DEFAULT 545000.00 AFTER tuition_paid",
    'last_payment_date' => "ALTER TABLE student_finances ADD COLUMN last_payment_date DATE DEFAULT NULL AFTER payment_percentage"
];

foreach ($columns_to_check as $column_name => $alter_sql) {
    $check = $conn->query("SHOW COLUMNS FROM student_finances LIKE '$column_name'");
    
    if ($check->num_rows == 0) {
        echo "<p>Adding column: <strong>$column_name</strong>... ";
        if ($conn->query($alter_sql) === TRUE) {
            echo "<span style='color: green;'>✓ Success</span></p>";
        } else {
            echo "<span style='color: red;'>✗ Error: " . $conn->error . "</span></p>";
        }
    } else {
        echo "<p>Column <strong>$column_name</strong> already exists. <span style='color: blue;'>✓ OK</span></p>";
    }
}

// Update existing records to have proper expected_total based on program_type
echo "<h3>Updating expected_total for all students...</h3>";

$update_query = "
UPDATE student_finances sf
JOIN students s ON sf.student_id = s.student_id
SET sf.expected_total = CASE 
    WHEN s.program_type = 'professional' THEN 245000
    WHEN s.program_type = 'masters' THEN 1145000
    WHEN s.program_type = 'doctorate' THEN 2245000
    ELSE 545000
END,
sf.balance = (CASE 
    WHEN s.program_type = 'professional' THEN 245000
    WHEN s.program_type = 'masters' THEN 1145000
    WHEN s.program_type = 'doctorate' THEN 2245000
    ELSE 545000
END) - COALESCE(sf.total_paid, 0),
sf.payment_percentage = ROUND((COALESCE(sf.total_paid, 0) / (CASE 
    WHEN s.program_type = 'professional' THEN 245000
    WHEN s.program_type = 'masters' THEN 1145000
    WHEN s.program_type = 'doctorate' THEN 2245000
    ELSE 545000
END)) * 100)
";

if ($conn->query($update_query) === TRUE) {
    echo "<p style='color: green;'>✓ Successfully updated expected_total for all students based on their program type!</p>";
} else {
    echo "<p style='color: red;'>✗ Error updating: " . $conn->error . "</p>";
}

// Set default values for NULL fields
echo "<h3>Setting default values...</h3>";

$default_updates = [
    "UPDATE student_finances SET application_fee_paid = 0 WHERE application_fee_paid IS NULL",
    "UPDATE student_finances SET registration_paid = 0 WHERE registration_paid IS NULL",
    "UPDATE student_finances SET installment_1 = 0 WHERE installment_1 IS NULL",
    "UPDATE student_finances SET installment_2 = 0 WHERE installment_2 IS NULL",
    "UPDATE student_finances SET installment_3 = 0 WHERE installment_3 IS NULL",
    "UPDATE student_finances SET installment_4 = 0 WHERE installment_4 IS NULL",
    "UPDATE student_finances SET total_paid = 0 WHERE total_paid IS NULL"
];

foreach ($default_updates as $update_sql) {
    if ($conn->query($update_sql) === TRUE) {
        echo "<p style='color: green;'>✓ Default values set</p>";
    } else {
        echo "<p style='color: red;'>✗ Error: " . $conn->error . "</p>";
    }
}

echo "<h3>All Done!</h3>";
echo "<p><a href='finance/student_finances.php'>Go to Student Finances</a> | <a href='finance/dashboard.php'>Finance Dashboard</a></p>";

$conn->close();
?>
