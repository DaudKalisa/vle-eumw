<?php
// create_announcements_table.php - Create announcements table
require_once 'includes/config.php';

$conn = getDbConnection();

$sql = "CREATE TABLE IF NOT EXISTS vle_announcements (
    announcement_id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    lecturer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE
)";

if ($conn->query($sql)) {
    echo "vle_announcements table created successfully!\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

?>
