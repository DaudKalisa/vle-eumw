<?php
// create_faculties_table.php - Create faculties table
require_once 'includes/config.php';

$conn = getDbConnection();

// Create faculties table
$sql = "CREATE TABLE IF NOT EXISTS faculties (
    faculty_id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_code VARCHAR(20) NOT NULL UNIQUE,
    faculty_name VARCHAR(255) NOT NULL,
    head_of_faculty VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faculty_code (faculty_code),
    INDEX idx_faculty_name (faculty_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ Faculties table created successfully!<br>";
    
    // Insert sample faculties
    $sample_faculties = [
        ['FICT', 'Faculty of Information and Communication Technology', 'Dr. John Smith'],
        ['FBM', 'Faculty of Business Management', 'Prof. Sarah Johnson'],
        ['FED', 'Faculty of Education', 'Dr. Michael Brown'],
        ['FNAS', 'Faculty of Natural and Applied Sciences', 'Prof. Emily Davis']
    ];
    
    $stmt = $conn->prepare("INSERT INTO faculties (faculty_code, faculty_name, head_of_faculty) VALUES (?, ?, ?)");
    
    foreach ($sample_faculties as $faculty) {
        $stmt->bind_param("sss", $faculty[0], $faculty[1], $faculty[2]);
        if ($stmt->execute()) {
            echo "✓ Added faculty: {$faculty[1]}<br>";
        }
    }
    
    $stmt->close();
    
} else {
    echo "Error creating faculties table: " . $conn->error . "<br>";
}

// Add faculty_id column to departments table if it doesn't exist
$check_column = $conn->query("SHOW COLUMNS FROM departments LIKE 'faculty_id'");
if ($check_column->num_rows == 0) {
    $alter_sql = "ALTER TABLE departments 
                  ADD COLUMN faculty_id INT NULL AFTER department_name,
                  ADD INDEX idx_faculty_id (faculty_id),
                  ADD FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL";
    
    if ($conn->query($alter_sql)) {
        echo "✓ Added faculty_id column to departments table!<br>";
    } else {
        echo "Error adding faculty_id column: " . $conn->error . "<br>";
    }
} else {
    echo "✓ faculty_id column already exists in departments table.<br>";
}

$conn->close();

echo "<br><strong>Faculties table setup complete!</strong><br>";
echo '<a href="admin/dashboard.php">Go to Admin Dashboard</a>';
?>
