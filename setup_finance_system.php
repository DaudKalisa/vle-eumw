<?php
// setup_finance_system.php - Setup finance system tables and sample data
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting up Finance System...</h2>";

// Step 1: Update students table to add missing fields
echo "<h3>Step 1: Updating students table...</h3>";

$alterations = [
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS entry_type VARCHAR(10) DEFAULT 'NE' COMMENT 'ME=Mature Entry, NE=Normal Entry, ODL=Open Distance Learning, PC=Professional Course'",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS year_of_registration YEAR DEFAULT YEAR(CURRENT_DATE)",
    "ALTER TABLE students MODIFY COLUMN student_id VARCHAR(50) NOT NULL"
];

foreach ($alterations as $sql) {
    try {
        if ($conn->query($sql)) {
            echo "✓ Column updated successfully<br>";
        }
    } catch (Exception $e) {
        echo "• Column may already exist or: " . $e->getMessage() . "<br>";
    }
}

// Step 2: Create student_finances table
echo "<h3>Step 2: Creating student_finances table...</h3>";

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
    echo "Error: " . $conn->error . "<br>";
}

// Step 3: Create payment_transactions table
echo "<h3>Step 3: Creating payment_transactions table...</h3>";

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
    echo "Error: " . $conn->error . "<br>";
}

// Step 4: Add finance role to lecturers table
echo "<h3>Step 4: Setting up finance user role...</h3>";

// Create a sample finance user
$finance_id = 'FIN' . date('Y') . '001';
$full_name = 'Finance Officer';
$email = 'finance@university.edu';
$password = password_hash('finance123', PASSWORD_DEFAULT);

$check = $conn->query("SELECT * FROM lecturers WHERE email = 'finance@university.edu'");
if ($check->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO lecturers (lecturer_id, full_name, email, password, department, role, is_active) VALUES (?, ?, ?, ?, 'Finance Department', 'finance', TRUE)");
    $stmt->bind_param("ssss", $finance_id, $full_name, $email, $password);
    if ($stmt->execute()) {
        echo "✓ Finance user created (Email: finance@university.edu, Password: finance123)<br>";
    } else {
        echo "Error creating finance user: " . $stmt->error . "<br>";
    }
    $stmt->close();
} else {
    echo "• Finance user already exists<br>";
}

// Step 5: Generate auto Student IDs and create 20 sample students
echo "<h3>Step 5: Creating 20 sample students with finance records...</h3>";

$programs = ['CS', 'IT', 'BBA', 'EDU'];
$campuses = ['MZ', 'LL', 'BT'];
$entry_types = ['NE', 'ME', 'ODL', 'PC'];
$genders = ['Male', 'Female'];
$years = [1, 2, 3, 4];

// Function to generate student ID
function generateStudentID($program, $campus, $year_of_reg, $entry_type, $conn) {
    // Get the last sequence number for this combination
    $check_sql = "SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1";
    $pattern = "$program/$campus/$year_of_reg/$entry_type/%";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $last_id = $result->fetch_assoc()['student_id'];
        $parts = explode('/', $last_id);
        $sequence = intval($parts[4]) + 1;
    } else {
        $sequence = 1;
    }
    
    return "$program/$campus/$year_of_reg/$entry_type/" . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

$sample_first_names = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'James', 'Emma', 'Robert', 'Olivia', 'William', 'Sophia', 'Daniel', 'Isabella', 'Matthew', 'Mia', 'Joseph', 'Charlotte', 'Andrew', 'Amelia'];
$sample_last_names = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin'];

for ($i = 0; $i < 20; $i++) {
    $first_name = $sample_first_names[$i];
    $last_name = $sample_last_names[$i];
    $program = $programs[array_rand($programs)];
    $campus = $campuses[array_rand($campuses)];
    $entry_type = $entry_types[array_rand($entry_types)];
    $year_of_reg = rand(2023, 2026);
    $year_of_study = $years[array_rand($years)];
    $gender = $genders[array_rand($genders)];
    
    $student_id = generateStudentID($program, $campus, $year_of_reg, $entry_type, $conn);
    $email = strtolower($first_name . '.' . $last_name) . '@student.edu';
    $password = password_hash('student123', PASSWORD_DEFAULT);
    
    // Check if student already exists
    $check = $conn->query("SELECT student_id FROM students WHERE student_id = '$student_id'");
    if ($check->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO students (student_id, first_name, last_name, email, password_hash, program, year_of_study, semester, campus, gender, entry_type, year_of_registration, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 'One', ?, ?, ?, ?, TRUE)");
        $stmt->bind_param("ssssssisss", $student_id, $first_name, $last_name, $email, $password, $program, $year_of_study, $campus, $gender, $entry_type, $year_of_reg);
        
        if ($stmt->execute()) {
            echo "✓ Created student: $student_id - $first_name $last_name<br>";
            
            // Create finance record with random payment status
            $payment_scenarios = [
                ['reg' => 39500, 'inst1' => 0, 'inst2' => 0, 'inst3' => 0, 'inst4' => 0],           // Only registration
                ['reg' => 39500, 'inst1' => 125000, 'inst2' => 0, 'inst3' => 0, 'inst4' => 0],      // 25%
                ['reg' => 39500, 'inst1' => 125000, 'inst2' => 125000, 'inst3' => 0, 'inst4' => 0], // 50%
                ['reg' => 39500, 'inst1' => 125000, 'inst2' => 125000, 'inst3' => 125000, 'inst4' => 0], // 75%
                ['reg' => 39500, 'inst1' => 125000, 'inst2' => 125000, 'inst3' => 125000, 'inst4' => 125000], // 100%
            ];
            
            $scenario = $payment_scenarios[array_rand($payment_scenarios)];
            
            $reg_paid = $scenario['reg'];
            $inst1 = $scenario['inst1'];
            $inst2 = $scenario['inst2'];
            $inst3 = $scenario['inst3'];
            $inst4 = $scenario['inst4'];
            
            $tuition_paid = $inst1 + $inst2 + $inst3 + $inst4;
            $total_paid = $reg_paid + $tuition_paid;
            $balance = 539500 - $total_paid;
            $payment_percentage = round(($tuition_paid / 500000) * 100);
            
            // Calculate content access weeks
            $access_weeks = 0;
            if ($payment_percentage >= 100) $access_weeks = 16;
            elseif ($payment_percentage >= 75) $access_weeks = 12;
            elseif ($payment_percentage >= 50) $access_weeks = 8;
            elseif ($payment_percentage >= 25) $access_weeks = 4;
            
            $reg_date = $reg_paid > 0 ? date('Y-m-d', strtotime("-" . rand(1, 90) . " days")) : null;
            $inst1_date = $inst1 > 0 ? date('Y-m-d', strtotime("-" . rand(1, 80) . " days")) : null;
            $inst2_date = $inst2 > 0 ? date('Y-m-d', strtotime("-" . rand(1, 60) . " days")) : null;
            $inst3_date = $inst3 > 0 ? date('Y-m-d', strtotime("-" . rand(1, 40) . " days")) : null;
            $inst4_date = $inst4 > 0 ? date('Y-m-d', strtotime("-" . rand(1, 20) . " days")) : null;
            
            $stmt = $conn->prepare("INSERT INTO student_finances (student_id, registration_paid, registration_paid_date, installment_1, installment_1_date, installment_2, installment_2_date, installment_3, installment_3_date, installment_4, installment_4_date, tuition_paid, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sdsdsdsdsdddddii", $student_id, $reg_paid, $reg_date, $inst1, $inst1_date, $inst2, $inst2_date, $inst3, $inst3_date, $inst4, $inst4_date, $tuition_paid, $total_paid, $balance, $payment_percentage, $access_weeks);
            $stmt->execute();
            
            echo "&nbsp;&nbsp;→ Finance record: {$payment_percentage}% paid, {$access_weeks} weeks access<br>";
        }
    }
}

echo "<br><h3>✓ Finance System Setup Complete!</h3>";
echo "<p><strong>Finance User Login:</strong></p>";
echo "<ul>";
echo "<li>Email: finance@university.edu</li>";
echo "<li>Password: finance123</li>";
echo "</ul>";

echo "<p><strong>Sample Students Created: 20</strong></p>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Login as finance user to access finance dashboard</li>";
echo "<li>View student finance records and process payments</li>";
echo "<li>Students can only access content based on payment percentage</li>";
echo "</ol>";

$conn->close();

echo '<br><a href="admin/dashboard.php" class="btn btn-primary">Go to Admin Dashboard</a>';
echo ' <a href="login.php" class="btn btn-success">Login as Finance User</a>';
?>
