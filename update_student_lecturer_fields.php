<?php
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Updating Database Schema...</h2>";

// Add new columns to students table
$student_columns = [
    "campus VARCHAR(50) DEFAULT 'Mzuzu Campus'",
    "year_of_registration YEAR",
    "semester ENUM('One', 'Two') DEFAULT 'One'"
];

echo "<h3>Updating students table:</h3>";
foreach ($student_columns as $column) {
    $column_name = explode(' ', $column)[0];
    
    // Check if column exists
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

echo "<h3>Database update completed successfully!</h3>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";

$conn->close();
?>
