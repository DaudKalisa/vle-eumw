<?php
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Adding Gender and National ID Fields...</h2>";

// Add gender and national_id to students table
echo "<h3>Updating students table:</h3>";

$student_columns = [
    "gender ENUM('Male', 'Female', 'Other') DEFAULT NULL",
    "national_id VARCHAR(50)"
];

foreach ($student_columns as $column) {
    $column_name = explode(' ', $column)[0];
    $check = $conn->query("SHOW COLUMNS FROM students LIKE '$column_name'");
    
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE students ADD COLUMN $column";
        if ($conn->query($sql)) {
            echo "✓ Column added successfully: $column_name<br>";
        } else {
            echo "✗ Error adding $column_name: " . $conn->error . "<br>";
        }
    } else {
        echo "⊙ Column already exists: $column_name<br>";
    }
}

// Add gender to lecturers table
echo "<h3>Updating lecturers table:</h3>";

$check = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'gender'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE lecturers ADD COLUMN gender ENUM('Male', 'Female', 'Other') DEFAULT NULL";
    if ($conn->query($sql)) {
        echo "✓ Column 'gender' added to lecturers table<br>";
    } else {
        echo "✗ Error adding gender: " . $conn->error . "<br>";
    }
} else {
    echo "⊙ Column 'gender' already exists in lecturers table<br>";
}

echo "<h3>Database update completed successfully!</h3>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";

$conn->close();
?>
