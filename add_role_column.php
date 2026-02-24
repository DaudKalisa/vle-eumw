<?php
// add_role_column.php - Add role column to lecturers table
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Adding role column to lecturers table...</h2>";

// Check if role column exists
$check = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'role'");

if ($check->num_rows == 0) {
    // Add role column
    $sql = "ALTER TABLE lecturers ADD COLUMN role VARCHAR(20) DEFAULT 'lecturer' COMMENT 'staff, finance, lecturer'";
    
    if ($conn->query($sql)) {
        echo "✓ Role column added successfully!<br>";
        
        // Update existing finance users
        $conn->query("UPDATE lecturers SET role = 'finance' WHERE department = 'Finance Department' OR email LIKE '%finance%'");
        echo "✓ Updated finance users<br>";
        
        // Update existing admin users
        $conn->query("UPDATE lecturers SET role = 'staff' WHERE department = 'Administration' OR lecturer_id LIKE 'ADMIN%'");
        echo "✓ Updated admin users<br>";
        
        echo "<br><strong>Success! Role column has been added.</strong><br>";
        echo "<a href='admin/dashboard.php'>Go to Admin Dashboard</a>";
    } else {
        echo "Error: " . $conn->error . "<br>";
    }
} else {
    echo "• Role column already exists<br>";
    echo "<a href='admin/dashboard.php'>Go to Admin Dashboard</a>";
}

?>
