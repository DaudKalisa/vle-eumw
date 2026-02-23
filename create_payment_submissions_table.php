<?php
// create_payment_submissions_table.php - Create payment_submissions table
require_once 'includes/config.php';

$conn = getDbConnection();

$sql = "CREATE TABLE IF NOT EXISTS payment_submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(100),
    transaction_date DATE,
    proof_of_payment VARCHAR(255),
    submission_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by VARCHAR(50),
    reviewed_date TIMESTAMP NULL,
    finance_id INT,
    notes TEXT,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_student_status (student_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($conn->query($sql) === TRUE) {
    echo "Table 'payment_submissions' created successfully or already exists.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

$conn->close();
echo "Setup complete!";
?>
