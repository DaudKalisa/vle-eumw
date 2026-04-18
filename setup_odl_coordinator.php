<?php
/**
 * ODL Coordinator Setup Script
 * Creates required tables and role for ODL (Open Distance Learning) Coordinator portal
 * 
 * ODL Coordinator Responsibilities:
 * 1. Approve lecturer claims before finance department processes them
 * 2. Verify student registration and access to course content
 * 3. Manage examinations (like examination officer)
 * 4. Generate reports for ODL program management
 */

require_once 'includes/auth.php';

$conn = getDbConnection();
$messages = [];
$errors = [];

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
<title>ODL Coordinator Setup</title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css'>
</head><body class='bg-light'><div class='container py-5'>";
echo "<h2 class='text-primary mb-4'><i class='bi bi-gear me-2'></i>ODL Coordinator Portal Setup</h2>";

// 1. Create odl_coordinators table
$sql = "CREATE TABLE IF NOT EXISTS odl_coordinators (
    coordinator_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(100),
    position VARCHAR(100) DEFAULT 'ODL Coordinator',
    profile_picture VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $messages[] = "✅ Created/verified <code>odl_coordinators</code> table";
} else {
    $errors[] = "❌ Failed to create odl_coordinators table: " . $conn->error;
}

// 2. Create claims_approval table for ODL approval workflow
$sql = "CREATE TABLE IF NOT EXISTS odl_claims_approval (
    approval_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL COMMENT 'FK to lecturer_finance_requests',
    coordinator_id INT NOT NULL COMMENT 'FK to odl_coordinators',
    status ENUM('pending', 'approved', 'rejected', 'returned') DEFAULT 'pending',
    remarks TEXT,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request (request_id),
    INDEX idx_coordinator (coordinator_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $messages[] = "✅ Created/verified <code>odl_claims_approval</code> table";
} else {
    $errors[] = "❌ Failed to create odl_claims_approval table: " . $conn->error;
}

// 3. Add odl_approval_status column to lecturer_finance_requests if not exists
$check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
if ($check && $check->num_rows == 0) {
    $sql = "ALTER TABLE lecturer_finance_requests 
            ADD COLUMN odl_approval_status ENUM('pending', 'approved', 'rejected', 'returned') DEFAULT 'pending' AFTER status,
            ADD COLUMN odl_approved_by INT NULL AFTER odl_approval_status,
            ADD COLUMN odl_approved_at TIMESTAMP NULL AFTER odl_approved_by,
            ADD COLUMN odl_remarks TEXT AFTER odl_approved_at";
    if ($conn->query($sql)) {
        $messages[] = "✅ Added ODL approval columns to <code>lecturer_finance_requests</code> table";
    } else {
        $errors[] = "❌ Failed to add ODL columns: " . $conn->error;
    }
} else {
    $messages[] = "✅ ODL approval columns already exist in <code>lecturer_finance_requests</code>";
}

// 4. Create student_access_logs table for tracking student access
$sql = "CREATE TABLE IF NOT EXISTS student_access_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    course_id INT,
    content_type VARCHAR(50) COMMENT 'login, course_view, assignment, content, exam',
    content_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_type (content_type),
    INDEX idx_date (accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $messages[] = "✅ Created/verified <code>student_access_logs</code> table";
} else {
    $errors[] = "❌ Failed to create student_access_logs table: " . $conn->error;
}

// 5. Create odl_reports table for scheduled/generated reports
$sql = "CREATE TABLE IF NOT EXISTS odl_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(50) NOT NULL COMMENT 'student_activity, claims_summary, exam_results, enrollment',
    report_name VARCHAR(255) NOT NULL,
    generated_by INT NOT NULL,
    parameters JSON,
    file_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (report_type),
    INDEX idx_generated_by (generated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $messages[] = "✅ Created/verified <code>odl_reports</code> table";
} else {
    $errors[] = "❌ Failed to create odl_reports table: " . $conn->error;
}

// 6. Add 'odl_coordinator' to users role enum if not present
$check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($check && $row = $check->fetch_assoc()) {
    $type = $row['Type'];
    if (strpos($type, 'odl_coordinator') === false) {
        // Need to alter enum to include odl_coordinator
        // Extract existing values
        preg_match("/enum\((.*)\)/", $type, $matches);
        if (!empty($matches[1])) {
            $existing = $matches[1];
            // Add odl_coordinator
            $new_enum = str_replace(")", ",'odl_coordinator')", "enum(" . $existing . ")");
            $sql = "ALTER TABLE users MODIFY COLUMN role " . $new_enum . " DEFAULT 'student'";
            if ($conn->query($sql)) {
                $messages[] = "✅ Added 'odl_coordinator' to users role enum";
            } else {
                $errors[] = "❌ Failed to add odl_coordinator role: " . $conn->error;
            }
        }
    } else {
        $messages[] = "✅ 'odl_coordinator' role already exists in users table";
    }
}

// 7. Create sample ODL Coordinator user if none exists
$check = $conn->query("SELECT * FROM users WHERE role = 'odl_coordinator' LIMIT 1");
if ($check && $check->num_rows == 0) {
    // Create user account
    $username = 'odlcoordinator';
    $password_hash = password_hash('odl2024', PASSWORD_DEFAULT);
    $email = 'odl@university.edu';
    
    $sql = "INSERT INTO users (username, password_hash, email, role, is_active, created_at) 
            VALUES (?, ?, ?, 'odl_coordinator', 1, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $password_hash, $email);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // Create coordinator profile
        $sql = "INSERT INTO odl_coordinators (user_id, full_name, email, position) 
                VALUES (?, 'ODL Coordinator', ?, 'ODL Coordinator')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $email);
        
        if ($stmt->execute()) {
            $coordinator_id = $stmt->insert_id;
            
            // Link user to coordinator
            $conn->query("UPDATE users SET related_staff_id = $coordinator_id WHERE user_id = $user_id");
            
            $messages[] = "✅ Created sample ODL Coordinator account:
                <div class='alert alert-info mt-2'>
                    <strong>Username:</strong> odlcoordinator<br>
                    <strong>Password:</strong> odl2024<br>
                    <strong>Email:</strong> odl@university.edu<br>
                    <small class='text-muted'>Please change the password after first login!</small>
                </div>";
        }
    } else {
        $errors[] = "❌ Failed to create ODL Coordinator user: " . $conn->error;
    }
} else {
    $messages[] = "✅ ODL Coordinator user already exists";
}

// Display results
if (!empty($messages)) {
    echo "<div class='card mb-4'><div class='card-header bg-success text-white'><i class='bi bi-check-circle me-2'></i>Setup Progress</div>";
    echo "<div class='card-body'><ul class='list-unstyled mb-0'>";
    foreach ($messages as $msg) {
        echo "<li class='mb-2'>$msg</li>";
    }
    echo "</ul></div></div>";
}

if (!empty($errors)) {
    echo "<div class='card mb-4'><div class='card-header bg-danger text-white'><i class='bi bi-x-circle me-2'></i>Errors</div>";
    echo "<div class='card-body'><ul class='list-unstyled mb-0 text-danger'>";
    foreach ($errors as $err) {
        echo "<li class='mb-2'>$err</li>";
    }
    echo "</ul></div></div>";
}

// Quick Links
echo "<div class='card'><div class='card-header bg-primary text-white'><i class='bi bi-link-45deg me-2'></i>Quick Links</div>";
echo "<div class='card-body'>
    <div class='row g-3'>
        <div class='col-md-3'>
            <a href='odl_coordinator/dashboard.php' class='btn btn-outline-primary w-100'>
                <i class='bi bi-speedometer2 d-block fs-2 mb-2'></i>ODL Dashboard
            </a>
        </div>
        <div class='col-md-3'>
            <a href='login.php' class='btn btn-outline-secondary w-100'>
                <i class='bi bi-box-arrow-in-right d-block fs-2 mb-2'></i>Login Page
            </a>
        </div>
        <div class='col-md-3'>
            <a href='admin/dashboard.php' class='btn btn-outline-info w-100'>
                <i class='bi bi-gear d-block fs-2 mb-2'></i>Admin Portal
            </a>
        </div>
        <div class='col-md-3'>
            <a href='index.php' class='btn btn-outline-success w-100'>
                <i class='bi bi-house d-block fs-2 mb-2'></i>Home Page
            </a>
        </div>
    </div>
</div></div>";

echo "</div></body></html>";
?>
