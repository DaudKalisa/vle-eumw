<?php
require_once 'includes/config.php';
$conn = getDbConnection();

// Check if examination manager exists
$result = $conn->query("SELECT manager_id FROM examination_managers WHERE email = 'exam.manager@university.edu'");
if ($result->num_rows > 0) {
    $manager = $result->fetch_assoc();
    $managerId = $manager['manager_id'];

    // Check if user account exists
    $result = $conn->query("SELECT user_id FROM users WHERE username = 'exam_manager'");
    if ($result->num_rows == 0) {
        // Create user account
        $password = password_hash("ExamManager2024!", PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'staff', ?)");
        $stmt->bind_param("sssi", $username, $email, $password, $managerId);

        $username = "exam_manager";
        $email = "exam.manager@university.edu";

        if ($stmt->execute()) {
            echo "✓ User account created successfully for examination manager\n";
            echo "Username: exam_manager\n";
            echo "Email: exam.manager@university.edu\n";
            echo "Password: ExamManager2024!\n";
        } else {
            echo "✗ Failed to create user account: " . $conn->error . "\n";
        }
    } else {
        echo "ℹ User account already exists\n";
    }
} else {
    echo "✗ Examination manager not found\n";
}
?>