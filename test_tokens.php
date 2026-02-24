<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$sql = "CREATE TABLE IF NOT EXISTS exam_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    is_used BOOLEAN DEFAULT FALSE,
    used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "Table created successfully\n";
} else {
    echo "Error: " . $conn->error . "\n";
}
?>