<?php
/**
 * Setup Finance Users Table
 * Creates a dedicated finance_users table and migrates finance users from lecturers table
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Setup Finance Users Table</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='container py-4'>
<h1>Setup Finance Users Table</h1>
<hr>";

// Step 1: Create finance_users table
echo "<h3>Step 1: Creating finance_users table...</h3>";

$sql = "
CREATE TABLE IF NOT EXISTS finance_users (
    finance_id INT AUTO_INCREMENT PRIMARY KEY,
    finance_code VARCHAR(20) UNIQUE COMMENT 'e.g., FIN2024001',
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    password VARCHAR(255),
    department VARCHAR(100) DEFAULT 'Finance Department',
    position VARCHAR(100) DEFAULT 'Finance Officer',
    gender ENUM('Male', 'Female', 'Other'),
    national_id VARCHAR(50),
    address TEXT,
    profile_picture VARCHAR(255),
    hire_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_finance_code (finance_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

if ($conn->query($sql)) {
    echo "<div class='alert alert-success'>✓ finance_users table created successfully!</div>";
    
    // Also try to convert existing table if it has wrong collation
    @$conn->query("ALTER TABLE finance_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
} else {
    echo "<div class='alert alert-danger'>✗ Error creating table: " . $conn->error . "</div>";
}

// Step 2: Check if lecturers table has role column
echo "<h3>Step 2: Checking for existing finance users in lecturers table...</h3>";

$has_role_column = false;
$result = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'role'");
if ($result && $result->num_rows > 0) {
    $has_role_column = true;
    echo "<div class='alert alert-info'>• Lecturers table has 'role' column</div>";
} else {
    echo "<div class='alert alert-warning'>• Lecturers table does not have 'role' column - no finance users to migrate</div>";
}

// Step 3: Migrate finance users from lecturers table
echo "<h3>Step 3: Migrating finance users from lecturers table...</h3>";

$migrated = 0;
$skipped = 0;
$errors = [];

if ($has_role_column) {
    // Get finance users from lecturers table
    $result = $conn->query("SELECT * FROM lecturers WHERE role = 'finance'");
    
    if ($result && $result->num_rows > 0) {
        echo "<div class='alert alert-info'>Found " . $result->num_rows . " finance user(s) to migrate</div>";
        
        // Get the highest existing finance code number to continue from
        $max_code_result = $conn->query("SELECT MAX(CAST(SUBSTRING(finance_code, 8) AS UNSIGNED)) as max_num FROM finance_users WHERE finance_code LIKE 'FIN" . date('Y') . "%'");
        $max_code_row = $max_code_result->fetch_assoc();
        $next_code_num = ($max_code_row['max_num'] ?? 0) + 1;
        
        while ($row = $result->fetch_assoc()) {
            // Check if already exists in finance_users
            $check = $conn->prepare("SELECT finance_id FROM finance_users WHERE email = ?");
            $check->bind_param("s", $row['email']);
            $check->execute();
            
            if ($check->get_result()->num_rows > 0) {
                $skipped++;
                echo "<div class='text-muted'>• Skipped: " . htmlspecialchars($row['full_name']) . " (already exists)</div>";
                continue;
            }
            
            // Generate unique finance code
            $finance_code = isset($row['lecturer_id']) && strpos($row['lecturer_id'], 'FIN') === 0 
                ? $row['lecturer_id'] 
                : 'FIN' . date('Y') . str_pad($next_code_num++, 3, '0', STR_PAD_LEFT);
            
            // Insert into finance_users
            $stmt = $conn->prepare("
                INSERT INTO finance_users (finance_code, full_name, email, phone, password, department, position, gender, national_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Validate gender - must match ENUM values or be NULL
            $raw_gender = $row['gender'] ?? null;
            $valid_genders = ['Male', 'Female', 'Other'];
            $gender = in_array($raw_gender, $valid_genders) ? $raw_gender : null;
            
            $national_id = $row['national_id'] ?? null;
            $position = $row['position'] ?? 'Finance Officer';
            $department = $row['department'] ?? 'Finance Department';
            $phone = $row['phone'] ?? null;
            $password = $row['password'] ?? null;
            $is_active = $row['is_active'] ?? 1;
            
            $stmt->bind_param("sssssssssi", 
                $finance_code,
                $row['full_name'],
                $row['email'],
                $phone,
                $password,
                $department,
                $position,
                $gender,
                $national_id,
                $is_active
            );
            
            if ($stmt->execute()) {
                $new_finance_id = $conn->insert_id;
                $migrated++;
                echo "<div class='text-success'>✓ Migrated: " . htmlspecialchars($row['full_name']) . " (ID: $new_finance_id, Code: $finance_code)</div>";
                
                // Update users table to reference new finance_users table
                $old_lecturer_id = $row['lecturer_id'];
                $update = $conn->prepare("UPDATE users SET related_finance_id = ?, related_lecturer_id = NULL WHERE related_lecturer_id = ?");
                $update->bind_param("ii", $new_finance_id, $old_lecturer_id);
                if ($update->execute() && $update->affected_rows > 0) {
                    echo "<div class='text-info ms-3'>  → Updated user reference to finance_users table</div>";
                }
            } else {
                $errors[] = "Failed to migrate " . $row['full_name'] . ": " . $stmt->error;
            }
        }
    } else {
        echo "<div class='alert alert-warning'>No finance users found in lecturers table</div>";
    }
}

echo "<div class='alert alert-primary mt-3'>Migration complete: $migrated migrated, $skipped skipped</div>";

if (!empty($errors)) {
    echo "<div class='alert alert-danger'><strong>Errors:</strong><br>" . implode("<br>", $errors) . "</div>";
}

// Step 4: Create a sample finance user if none exist
echo "<h3>Step 4: Ensuring at least one finance user exists...</h3>";

$check = $conn->query("SELECT COUNT(*) as count FROM finance_users");
$count = $check->fetch_assoc()['count'];

if ($count == 0) {
    $finance_code = 'FIN' . date('Y') . '001';
    $full_name = 'Finance Officer';
    $email = 'finance@university.edu';
    $password = password_hash('finance123', PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO finance_users (finance_code, full_name, email, password, department, position, is_active) VALUES (?, ?, ?, ?, 'Finance Department', 'Finance Officer', TRUE)");
    $stmt->bind_param("ssss", $finance_code, $full_name, $email, $password);
    
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        echo "<div class='alert alert-success'>✓ Created sample finance user:<br>
            <strong>Email:</strong> finance@university.edu<br>
            <strong>Password:</strong> finance123<br>
            <strong>ID:</strong> $new_id</div>";
        
        // Create corresponding user account
        $check_user = $conn->query("SELECT user_id FROM users WHERE email = 'finance@university.edu'");
        if ($check_user->num_rows == 0) {
            $stmt2 = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_finance_id, is_active, must_change_password) VALUES (?, ?, ?, 'finance', ?, TRUE, 1)");
            $username = 'finance';
            $stmt2->bind_param("sssi", $username, $email, $password, $new_id);
            if ($stmt2->execute()) {
                echo "<div class='text-success'>✓ Created user account for finance user</div>";
            }
        }
    } else {
        echo "<div class='alert alert-danger'>✗ Failed to create sample finance user: " . $stmt->error . "</div>";
    }
} else {
    echo "<div class='alert alert-info'>• $count finance user(s) already exist in finance_users table</div>";
}

// Step 5: Optionally clean up lecturers table (commented out for safety)
echo "<h3>Step 5: Cleanup (Optional)</h3>";
echo "<div class='alert alert-warning'>
    <strong>Note:</strong> Finance users have been copied to the new table. 
    To remove them from the lecturers table, you can run the following SQL manually after verifying everything works:
    <pre class='mt-2 bg-light p-2'>DELETE FROM lecturers WHERE role = 'finance';</pre>
</div>";

// Step 6: Show current finance users
echo "<h3>Step 6: Current Finance Users</h3>";

$result = $conn->query("SELECT * FROM finance_users ORDER BY full_name");
if ($result && $result->num_rows > 0) {
    echo "<table class='table table-striped'>
        <thead class='table-dark'>
            <tr>
                <th>ID</th>
                <th>Code</th>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Position</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>";
    while ($row = $result->fetch_assoc()) {
        $status = $row['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
        echo "<tr>
            <td>{$row['finance_id']}</td>
            <td>{$row['finance_code']}</td>
            <td>" . htmlspecialchars($row['full_name']) . "</td>
            <td>{$row['email']}</td>
            <td>{$row['department']}</td>
            <td>{$row['position']}</td>
            <td>$status</td>
        </tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<div class='alert alert-info'>No finance users found</div>";
}

echo "<hr>
<h3>Next Steps</h3>
<ol>
    <li>Update any code that references finance users from the lecturers table</li>
    <li>Test the finance login functionality</li>
    <li>Remove finance users from lecturers table once verified</li>
</ol>
<p><a href='admin/messages.php' class='btn btn-primary'>Go to Admin Messages</a> 
   <a href='admin/manage_finance.php' class='btn btn-secondary'>Manage Finance Users</a></p>
</body>
</html>";

?>
