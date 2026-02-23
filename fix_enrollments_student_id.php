<?php
// fix_enrollments_student_id.php - Fix student_id type in vle_enrollments table
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Fixing vle_enrollments table structure...</h2>";

// Check current structure
$result = $conn->query("DESCRIBE vle_enrollments");
echo "<h3>Current vle_enrollments structure:</h3>";
echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
}
echo "</table>";

// Check students table student_id type
$result = $conn->query("DESCRIBE students");
echo "<h3>Current students table structure:</h3>";
echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    if ($row['Field'] === 'student_id') {
        echo "<tr style='background-color: yellow;'><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
    }
}
echo "</table>";

echo "<h3>Applying fixes...</h3>";

// Drop foreign key constraint first
$conn->query("ALTER TABLE vle_enrollments DROP FOREIGN KEY vle_enrollments_ibfk_1");
echo "✓ Dropped foreign key constraint vle_enrollments_ibfk_1<br>";

// Modify student_id column to VARCHAR(20)
$conn->query("ALTER TABLE vle_enrollments MODIFY COLUMN student_id VARCHAR(20) NOT NULL");
echo "✓ Modified student_id to VARCHAR(20)<br>";

// Re-add foreign key constraint
$conn->query("ALTER TABLE vle_enrollments ADD CONSTRAINT vle_enrollments_ibfk_1 
              FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE");
echo "✓ Re-added foreign key constraint<br>";

echo "<h3>Verification - Updated structure:</h3>";
$result = $conn->query("DESCRIBE vle_enrollments");
echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    $highlight = ($row['Field'] === 'student_id') ? 'background-color: lightgreen;' : '';
    echo "<tr style='$highlight'><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
}
echo "</table>";

echo "<h3 style='color: green;'>✓ Fix completed successfully!</h3>";
echo "<p><a href='admin/manage_courses.php'>Go to Manage Courses</a> | <a href='admin/dashboard.php'>Go to Dashboard</a></p>";

$conn->close();
?>
