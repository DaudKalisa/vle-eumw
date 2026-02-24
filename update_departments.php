<?php
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Creating Departments Table and Adding Program Field...</h2>";

// Create departments table
echo "<h3>Creating departments table:</h3>";
$sql = "CREATE TABLE IF NOT EXISTS departments (
    department_id INT PRIMARY KEY AUTO_INCREMENT,
    department_code VARCHAR(10) UNIQUE NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql)) {
    echo "✓ Departments table created successfully<br>";
} else {
    echo "✗ Error creating departments table: " . $conn->error . "<br>";
}

// Add program field to students table
echo "<h3>Updating students table:</h3>";
$check = $conn->query("SHOW COLUMNS FROM students LIKE 'program'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE students ADD COLUMN program VARCHAR(100) AFTER department";
    if ($conn->query($sql)) {
        echo "✓ Column 'program' added to students table<br>";
    } else {
        echo "✗ Error adding program column: " . $conn->error . "<br>";
    }
} else {
    echo "⊙ Column 'program' already exists in students table<br>";
}

// Add program field to lecturers table
echo "<h3>Updating lecturers table:</h3>";
$check = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'program'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE lecturers ADD COLUMN program VARCHAR(100) AFTER department";
    if ($conn->query($sql)) {
        echo "✓ Column 'program' added to lecturers table<br>";
    } else {
        echo "✗ Error adding program column: " . $conn->error . "<br>";
    }
} else {
    echo "⊙ Column 'program' already exists in lecturers table<br>";
}

// Insert some sample departments
echo "<h3>Adding sample departments:</h3>";
$departments = [
    ['CS', 'Computer Science'],
    ['ENG', 'Engineering'],
    ['BUS', 'Business Administration'],
    ['MED', 'Medicine'],
    ['LAW', 'Law'],
    ['EDU', 'Education']
];

foreach ($departments as $dept) {
    $stmt = $conn->prepare("INSERT IGNORE INTO departments (department_code, department_name) VALUES (?, ?)");
    $stmt->bind_param("ss", $dept[0], $dept[1]);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "✓ Added department: {$dept[1]} ({$dept[0]})<br>";
        } else {
            echo "⊙ Department already exists: {$dept[1]}<br>";
        }
    }
}

echo "<h3>Database update completed successfully!</h3>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";

?>
