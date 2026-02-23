<?php
// setup_all_finance_tables.php - Create all missing finance system tables
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting up ALL Finance System Tables...</h2>";
echo "<hr>";

// Step 1: Add password and role columns to lecturers table if missing
echo "<h3>Step 1: Checking lecturers table for password and role columns...</h3>";

// Check and add password column
$check_pwd = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'password'");
if ($check_pwd->num_rows == 0) {
    $sql = "ALTER TABLE lecturers ADD COLUMN password VARCHAR(255) AFTER email";
    if ($conn->query($sql)) {
        echo "✓ Password column added to lecturers table<br>";
    }
} else {
    echo "• Password column already exists<br>";
}

// Check and add role column
$check = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'role'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE lecturers ADD COLUMN role VARCHAR(20) DEFAULT 'lecturer' COMMENT 'staff, finance, lecturer'";
    if ($conn->query($sql)) {
        echo "✓ Role column added to lecturers table<br>";
        // Update existing users
        $conn->query("UPDATE lecturers SET role = 'finance' WHERE department = 'Finance Department' OR email LIKE '%finance%'");
        $conn->query("UPDATE lecturers SET role = 'staff' WHERE department = 'Administration' OR lecturer_id LIKE 'ADMIN%'");
        echo "✓ Updated existing user roles<br>";
    }
} else {
    echo "• Role column already exists<br>";
}

// Check and add is_active column
$check_active = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'is_active'");
if ($check_active->num_rows == 0) {
    $sql = "ALTER TABLE lecturers ADD COLUMN is_active BOOLEAN DEFAULT TRUE";
    if ($conn->query($sql)) {
        echo "✓ is_active column added to lecturers table<br>";
    }
} else {
    echo "• is_active column already exists<br>";
}

// Step 2: Update students table
echo "<h3>Step 2: Updating students table...</h3>";
$alterations = [
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS entry_type VARCHAR(10) DEFAULT 'NE' COMMENT 'ME=Mature Entry, NE=Normal Entry, ODL=Open Distance Learning, PC=Professional Course'",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS year_of_registration YEAR DEFAULT YEAR(CURRENT_DATE)",
    "ALTER TABLE students MODIFY COLUMN student_id VARCHAR(50) NOT NULL"
];

foreach ($alterations as $sql) {
    try {
        if ($conn->query($sql)) {
            echo "✓ Students table updated<br>";
        }
    } catch (Exception $e) {
        echo "• Column may already exist<br>";
    }
}

// Step 3: Create student_finances table
echo "<h3>Step 3: Creating student_finances table...</h3>";
$sql = "CREATE TABLE IF NOT EXISTS student_finances (
    finance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    registration_fee DECIMAL(10, 2) DEFAULT 39500.00,
    registration_paid DECIMAL(10, 2) DEFAULT 0.00,
    registration_paid_date DATE NULL,
    tuition_fee DECIMAL(10, 2) DEFAULT 500000.00,
    tuition_paid DECIMAL(10, 2) DEFAULT 0.00,
    installment_1 DECIMAL(10, 2) DEFAULT 0.00,
    installment_1_date DATE NULL,
    installment_2 DECIMAL(10, 2) DEFAULT 0.00,
    installment_2_date DATE NULL,
    installment_3 DECIMAL(10, 2) DEFAULT 0.00,
    installment_3_date DATE NULL,
    installment_4 DECIMAL(10, 2) DEFAULT 0.00,
    installment_4_date DATE NULL,
    total_paid DECIMAL(10, 2) DEFAULT 0.00,
    balance DECIMAL(10, 2) DEFAULT 539500.00,
    payment_percentage INT DEFAULT 0,
    content_access_weeks INT DEFAULT 0 COMMENT 'Weeks of content student can access',
    last_payment_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_payment_percentage (payment_percentage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ student_finances table created successfully!<br>";
} else {
    echo "• student_finances table already exists or error: " . $conn->error . "<br>";
}

// Step 4: Create payment_transactions table
echo "<h3>Step 4: Creating payment_transactions table...</h3>";
$sql = "CREATE TABLE IF NOT EXISTS payment_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    payment_type VARCHAR(50) NOT NULL COMMENT 'Registration, Installment 1-4',
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'Cash',
    reference_number VARCHAR(100),
    payment_date DATE NOT NULL,
    recorded_by VARCHAR(100) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ payment_transactions table created successfully!<br>";
} else {
    echo "• payment_transactions table already exists or error: " . $conn->error . "<br>";
}

// Step 5: Create finance records for existing students
echo "<h3>Step 5: Creating finance records for existing students...</h3>";
$result = $conn->query("SELECT student_id FROM students");
$count = 0;
while ($student = $result->fetch_assoc()) {
    $student_id = $student['student_id'];
    $check = $conn->query("SELECT * FROM student_finances WHERE student_id = '$student_id'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO student_finances (student_id, registration_fee, tuition_fee, total_paid, balance, payment_percentage, content_access_weeks) VALUES ('$student_id', 39500.00, 500000.00, 0.00, 539500.00, 0, 0)");
        $count++;
    }
}
echo "✓ Created finance records for $count students<br>";

// Step 6: Ensure finance user exists
echo "<h3>Step 6: Checking finance user...</h3>";
$check = $conn->query("SELECT * FROM lecturers WHERE email = 'finance@university.edu'");
if ($check->num_rows == 0) {
    $finance_id = 'FIN' . date('Y') . '001';
    $full_name = 'Finance Officer';
    $email = 'finance@university.edu';
    $password = password_hash('finance123', PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO lecturers (lecturer_id, full_name, email, password, department, role, is_active) VALUES (?, ?, ?, ?, 'Finance Department', 'finance', TRUE)");
    $stmt->bind_param("ssss", $finance_id, $full_name, $email, $password);
    if ($stmt->execute()) {
        echo "✓ Finance user created<br>";
        echo "  <strong>Email:</strong> finance@university.edu<br>";
        echo "  <strong>Password:</strong> finance123<br>";
    }
} else {
    echo "• Finance user already exists (finance@university.edu)<br>";
    // Update role if needed
    $conn->query("UPDATE lecturers SET role = 'finance' WHERE email = 'finance@university.edu'");
}

$conn->close();

echo "<hr>";
echo "<h3 style='color: green;'>✓ All Finance System Tables Created Successfully!</h3>";
echo "<br>";
echo "<div style='background: #f0f0f0; padding: 20px; border-left: 4px solid #28a745;'>";
echo "<h4>Next Steps:</h4>";
echo "<ol>";
echo "<li><a href='login.php'><strong>Login as Finance Officer</strong></a>";
echo "    <ul>";
echo "        <li>Email: finance@university.edu</li>";
echo "        <li>Password: finance123</li>";
echo "    </ul>";
echo "</li>";
echo "<li><a href='finance/dashboard.php'><strong>Go to Finance Dashboard</strong></a></li>";
echo "<li><a href='admin/dashboard.php'><strong>Go to Admin Dashboard</strong></a></li>";
echo "</ol>";
echo "</div>";
?>
