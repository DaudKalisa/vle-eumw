<?php
// Test database connection
$conn = new mysqli('localhost', 'root', '');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "✅ Database connection: OK\n\n";

// Check if university_portal database exists
$result = $conn->query("SHOW DATABASES LIKE 'university_portal'");
if ($result->num_rows > 0) {
    echo "✅ Database 'university_portal' exists\n";
    
    // Try to use the database
    $conn->select_db('university_portal');
    
    // Check for users table
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result->num_rows > 0) {
        echo "✅ Table 'users' exists\n";
    } else {
        echo "⚠️  Table 'users' not found\n";
    }
} else {
    echo "⚠️  Database 'university_portal' not found - will be created on first access\n";
}

$conn->close();
echo "\n✅ System ready!\n";
?>
