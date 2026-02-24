<?php
// create_programs_table.php - Create programs table
require_once 'includes/config.php';

$conn = getDbConnection();

// Create programs table
$sql = "CREATE TABLE IF NOT EXISTS programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(10) UNIQUE NOT NULL,
    program_name VARCHAR(255) NOT NULL,
    department_id INT NULL,
    program_type ENUM('degree', 'professional', 'masters', 'doctorate') DEFAULT 'degree',
    duration_years INT DEFAULT 4,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    INDEX idx_program_code (program_code),
    INDEX idx_department (department_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "✓ Programs table created successfully!<br>";
    
    // Insert sample programs
    $samples = [
        ['BSC-CS', 'Bachelor of Science in Computer Science', 'degree', 4, 'Comprehensive computer science program covering programming, algorithms, and software development'],
        ['BSC-IT', 'Bachelor of Science in Information Technology', 'degree', 4, 'IT program focusing on systems administration and network management'],
        ['BBA', 'Bachelor of Business Administration', 'degree', 4, 'Business administration program covering management, accounting, and entrepreneurship'],
        ['BSC-ACC', 'Bachelor of Science in Accounting', 'degree', 4, 'Accounting program preparing students for professional accounting careers'],
        ['BA-EDU', 'Bachelor of Arts in Education', 'degree', 4, 'Teacher education program for primary and secondary education'],
        ['BSC-ENG', 'Bachelor of Science in Engineering', 'professional', 5, 'Engineering program with specializations in civil, electrical, and mechanical'],
        ['MBA', 'Master of Business Administration', 'masters', 2, 'Postgraduate business program for experienced professionals'],
        ['MSC-CS', 'Master of Science in Computer Science', 'masters', 2, 'Advanced computer science program with research focus'],
        ['PHD-BUS', 'Doctor of Philosophy in Business', 'doctorate', 4, 'Research doctorate in business administration and management']
    ];
    
    $stmt = $conn->prepare("INSERT INTO programs (program_code, program_name, program_type, duration_years, description) VALUES (?, ?, ?, ?, ?)");
    
    $inserted = 0;
    foreach ($samples as $sample) {
        $stmt->bind_param("sssss", $sample[0], $sample[1], $sample[2], $sample[3], $sample[4]);
        if ($stmt->execute()) {
            $inserted++;
        }
    }
    
    echo "✓ Inserted $inserted sample programs<br>";
} else {
    echo "✗ Error creating programs table: " . $conn->error . "<br>";
}

echo "<br><a href='admin/manage_programs.php'>Go to Manage Programs</a> | <a href='admin/dashboard.php'>Admin Dashboard</a>";
?>
