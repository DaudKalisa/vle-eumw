<?php
/**
 * Setup Student Type and Semester Shift Feature
 * - Adds student_type column (new_student, continuing)
 * - Adds student_status column for graduation status
 * - Run this once via browser to add the columns
 */

// Use direct connection for CLI compatibility
if (php_sapi_name() === 'cli') {
    $conn = new mysqli('localhost', 'root', '', 'university_portal');
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
} else {
    require_once 'includes/config.php';
    $conn = getDbConnection();
}

$results = [];

// Add student_type column if not exists
$check = $conn->query("SHOW COLUMNS FROM students LIKE 'student_type'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE students ADD COLUMN student_type ENUM('new_student', 'continuing') DEFAULT 'new_student' AFTER entry_type";
    if ($conn->query($sql)) {
        $results[] = "✅ Added student_type column to students table";
    } else {
        $results[] = "❌ Failed to add student_type column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ student_type column already exists";
}

// Add student_status column if not exists (for graduation)
$check = $conn->query("SHOW COLUMNS FROM students LIKE 'student_status'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE students ADD COLUMN student_status ENUM('active', 'graduated', 'suspended', 'withdrawn') DEFAULT 'active' AFTER student_type";
    if ($conn->query($sql)) {
        $results[] = "✅ Added student_status column to students table";
    } else {
        $results[] = "❌ Failed to add student_status column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ student_status column already exists";
}

// Add academic_level column if not exists (to track semester like 1/1, 1/2, etc.)
$check = $conn->query("SHOW COLUMNS FROM students LIKE 'academic_level'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE students ADD COLUMN academic_level VARCHAR(10) DEFAULT '1/1' AFTER semester";
    if ($conn->query($sql)) {
        $results[] = "✅ Added academic_level column to students table";
    } else {
        $results[] = "❌ Failed to add academic_level column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ academic_level column already exists";
}

// Add graduation_date column if not exists
$check = $conn->query("SHOW COLUMNS FROM students LIKE 'graduation_date'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE students ADD COLUMN graduation_date DATE NULL AFTER student_status";
    if ($conn->query($sql)) {
        $results[] = "✅ Added graduation_date column to students table";
    } else {
        $results[] = "❌ Failed to add graduation_date column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ graduation_date column already exists";
}

// Update fee_settings table to include new_student_reg_fee and continuing_reg_fee
$check = $conn->query("SHOW COLUMNS FROM fee_settings LIKE 'new_student_reg_fee'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE fee_settings ADD COLUMN new_student_reg_fee DECIMAL(12,2) DEFAULT 39500.00 AFTER registration_fee";
    if ($conn->query($sql)) {
        $results[] = "✅ Added new_student_reg_fee column to fee_settings table";
    } else {
        $results[] = "❌ Failed to add new_student_reg_fee column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ new_student_reg_fee column already exists";
}

$check = $conn->query("SHOW COLUMNS FROM fee_settings LIKE 'continuing_reg_fee'");
if ($check->num_rows === 0) {
    $sql = "ALTER TABLE fee_settings ADD COLUMN continuing_reg_fee DECIMAL(12,2) DEFAULT 35000.00 AFTER new_student_reg_fee";
    if ($conn->query($sql)) {
        $results[] = "✅ Added continuing_reg_fee column to fee_settings table";
    } else {
        $results[] = "❌ Failed to add continuing_reg_fee column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ continuing_reg_fee column already exists";
}

// Update existing fee_settings with default values
$conn->query("UPDATE fee_settings SET new_student_reg_fee = 39500 WHERE new_student_reg_fee IS NULL OR new_student_reg_fee = 0");
$conn->query("UPDATE fee_settings SET continuing_reg_fee = 35000 WHERE continuing_reg_fee IS NULL OR continuing_reg_fee = 0");
$results[] = "✅ Updated fee_settings with default registration fees";

// Update existing students to set academic_level based on year_of_study and semester
$conn->query("UPDATE students SET academic_level = CONCAT(year_of_study, '/', CASE WHEN semester = 'Two' THEN '2' ELSE '1' END) WHERE academic_level IS NULL OR academic_level = ''");
$results[] = "✅ Updated existing students with academic_level";

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Student Type Feature</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="bi bi-gear"></i> Student Type & Semester Shift Setup</h4>
            </div>
            <div class="card-body">
                <h5>Setup Results:</h5>
                <ul class="list-group mb-4">
                    <?php foreach ($results as $result): ?>
                        <li class="list-group-item"><?php echo $result; ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <div class="alert alert-info">
                    <h6><strong>Features Added:</strong></h6>
                    <ul class="mb-0">
                        <li><strong>Student Type:</strong> New Student (K39,500 registration) or Continuing Student (K35,000 registration)</li>
                        <li><strong>Academic Level:</strong> Track student progress (1/1, 1/2, 2/1, 2/2, etc.)</li>
                        <li><strong>Student Status:</strong> Active, Graduated, Suspended, Withdrawn</li>
                        <li><strong>Semester Shift:</strong> Admin can shift all passing students to next semester</li>
                    </ul>
                </div>
                
                <a href="admin/manage_students.php" class="btn btn-primary">Go to Manage Students</a>
                <a href="admin/semester_shift.php" class="btn btn-success">Go to Semester Shift</a>
            </div>
        </div>
    </div>
</body>
</html>
