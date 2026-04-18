<?php
/**
 * Setup Dean Portal Tables
 * Run this script once to create all necessary tables for the Dean Portal
 */

require_once 'includes/config.php';
$conn = getDbConnection();

echo "<h1>Setting up Dean Portal Tables</h1>";
echo "<pre>";

$tables_created = 0;
$columns_added = 0;
$errors = [];

// 1. Create dean_announcements table
echo "\n[1] Creating dean_announcements table...\n";
$sql = "CREATE TABLE IF NOT EXISTS dean_announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    target_audience ENUM('all', 'lecturers', 'students') DEFAULT 'all',
    faculty_id INT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_faculty (faculty_id),
    INDEX idx_created_by (created_by),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ dean_announcements table ready\n";
    $tables_created++;
} else {
    echo "✗ Error: " . $conn->error . "\n";
    $errors[] = "dean_announcements: " . $conn->error;
}

// 2. Create dean_claims_approval table
echo "\n[2] Creating dean_claims_approval table...\n";
$sql = "CREATE TABLE IF NOT EXISTS dean_claims_approval (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    dean_id INT NOT NULL,
    action ENUM('approved', 'rejected', 'returned', 'forwarded_to_finance') NOT NULL,
    remarks TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request (request_id),
    INDEX idx_dean (dean_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ dean_claims_approval table ready\n";
    $tables_created++;
} else {
    echo "✗ Error: " . $conn->error . "\n";
    $errors[] = "dean_claims_approval: " . $conn->error;
}

// 3. Add dean columns to lecturer_finance_requests
echo "\n[3] Adding dean columns to lecturer_finance_requests...\n";

$columns = [
    ['dean_approval_status', "ALTER TABLE lecturer_finance_requests ADD COLUMN dean_approval_status ENUM('pending','approved','rejected','returned') DEFAULT NULL"],
    ['dean_approved_by', "ALTER TABLE lecturer_finance_requests ADD COLUMN dean_approved_by INT NULL"],
    ['dean_approved_at', "ALTER TABLE lecturer_finance_requests ADD COLUMN dean_approved_at DATETIME NULL"],
    ['dean_remarks', "ALTER TABLE lecturer_finance_requests ADD COLUMN dean_remarks TEXT NULL"]
];

foreach ($columns as $col) {
    $check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE '{$col[0]}'");
    if ($check && $check->num_rows == 0) {
        if ($conn->query($col[1])) {
            echo "✓ Added column: {$col[0]}\n";
            $columns_added++;
        } else {
            echo "✗ Error adding {$col[0]}: " . $conn->error . "\n";
            $errors[] = "Column {$col[0]}: " . $conn->error;
        }
    } else {
        echo "— Column {$col[0]} already exists\n";
    }
}

// 4. Add dean_approved column to vle_exam_results
echo "\n[4] Adding dean_approved column to vle_exam_results...\n";
$check = $conn->query("SHOW TABLES LIKE 'vle_exam_results'");
if ($check && $check->num_rows > 0) {
    $col_check = $conn->query("SHOW COLUMNS FROM vle_exam_results LIKE 'dean_approved'");
    if ($col_check && $col_check->num_rows == 0) {
        if ($conn->query("ALTER TABLE vle_exam_results ADD COLUMN dean_approved TINYINT(1) DEFAULT 0")) {
            echo "✓ Added column: dean_approved\n";
            $columns_added++;
        } else {
            echo "✗ Error: " . $conn->error . "\n";
            $errors[] = "vle_exam_results dean_approved: " . $conn->error;
        }
    } else {
        echo "— Column dean_approved already exists\n";
    }
} else {
    echo "— vle_exam_results table does not exist (will be created when exams feature is used)\n";
}

// 5. Add dean_approved column to exams table (if exists)
echo "\n[5] Adding dean_approved column to exams table...\n";
$check = $conn->query("SHOW TABLES LIKE 'exams'");
if ($check && $check->num_rows > 0) {
    $col_check = $conn->query("SHOW COLUMNS FROM exams LIKE 'dean_approved'");
    if ($col_check && $col_check->num_rows == 0) {
        if ($conn->query("ALTER TABLE exams ADD COLUMN dean_approved TINYINT(1) DEFAULT 0")) {
            echo "✓ Added column: dean_approved to exams\n";
            $columns_added++;
        } else {
            echo "✗ Error: " . $conn->error . "\n";
            $errors[] = "exams dean_approved: " . $conn->error;
        }
    } else {
        echo "— Column dean_approved already exists\n";
    }
} else {
    echo "— exams table does not exist\n";
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "SETUP COMPLETE\n";
echo str_repeat("=", 50) . "\n";
echo "Tables created/verified: $tables_created\n";
echo "Columns added: $columns_added\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

echo "\n</pre>";

echo "<h2>Next Steps</h2>";
echo "<ul>";
echo "<li>Ensure a user with role 'dean' exists in the users table</li>";
echo "<li>Assign the dean to a faculty in the faculties table (head_of_faculty column)</li>";
echo "<li>Dean can now login at <a href='login.php'>login.php</a> and will be redirected to the Dean Portal</li>";
echo "</ul>";

echo "<p><a href='login.php' class='btn btn-primary' style='padding: 10px 20px; background: #1a472a; border: none; color: white; text-decoration: none; border-radius: 5px;'>Go to Login</a></p>";
?>
