<?php
// setup_registration_requests.php - Create course registration requests table
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting up Course Registration Requests System...</h2>";

// Check if table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'course_registration_requests'");

if ($tableCheck->num_rows > 0) {
    echo "<p style='color: orange;'>⚠️ Table 'course_registration_requests' already exists. Skipping creation.</p>";
} else {
    // Create the table
    $sql = "CREATE TABLE course_registration_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(20) NOT NULL,
        course_id INT NOT NULL,
        semester VARCHAR(50) NOT NULL,
        academic_year VARCHAR(50) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_date TIMESTAMP NULL,
        admin_notes TEXT NULL,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
        INDEX idx_status (status),
        INDEX idx_student (student_id),
        INDEX idx_course (course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>✓ Table 'course_registration_requests' created successfully!</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating table: " . $conn->error . "</p>";
    }
}

// Display table structure
echo "<h3>Table Structure:</h3>";
$structure = $conn->query("DESCRIBE course_registration_requests");
if ($structure) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<h3>Setup Complete!</h3>";
echo "<p>The course registration requests system is ready.</p>";
echo "<p><a href='index.php'>← Back to Home</a></p>";

?>
