<?php
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Creating Modules Table...</h2>";

// Create modules table
$sql = "CREATE TABLE IF NOT EXISTS modules (
    module_id INT PRIMARY KEY AUTO_INCREMENT,
    module_code VARCHAR(20) UNIQUE NOT NULL,
    module_name VARCHAR(200) NOT NULL,
    program_of_study VARCHAR(100),
    year_of_study INT,
    semester ENUM('One', 'Two'),
    credits INT DEFAULT 3,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_program (program_of_study),
    INDEX idx_year (year_of_study),
    INDEX idx_semester (semester)
)";

if ($conn->query($sql)) {
    echo "✓ Modules table created successfully<br>";
} else {
    echo "✗ Error creating modules table: " . $conn->error . "<br>";
}

// Insert sample modules
echo "<h3>Adding sample modules:</h3>";
$sample_modules = [
    ['CS101', 'Introduction to Computer Science', 'Bachelors of Computer Science', 1, 'One', 3],
    ['CS102', 'Programming Fundamentals', 'Bachelors of Computer Science', 1, 'One', 4],
    ['CS201', 'Data Structures and Algorithms', 'Bachelors of Computer Science', 2, 'One', 4],
    ['IT101', 'Information Technology Basics', 'Bachelors of Information Technology', 1, 'One', 3],
    ['IT102', 'Database Systems', 'Bachelors of Information Technology', 1, 'Two', 4],
];

foreach ($sample_modules as $module) {
    $stmt = $conn->prepare("INSERT IGNORE INTO modules (module_code, module_name, program_of_study, year_of_study, semester, credits) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssisi", $module[0], $module[1], $module[2], $module[3], $module[4], $module[5]);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "✓ Added module: {$module[1]} ({$module[0]})<br>";
        } else {
            echo "⊙ Module already exists: {$module[1]}<br>";
        }
    }
}

echo "<h3>Database update completed successfully!</h3>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";

?>
