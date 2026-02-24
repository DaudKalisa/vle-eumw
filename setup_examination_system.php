<?php
// setup_examination_system.php - Setup script for Examination Management System
require_once 'includes/config.php';

$conn = getDbConnection();

// Check if running from command line
$is_cli = php_sapi_name() === 'cli';

if ($is_cli) {
    echo "Examination Management System Setup\n";
    echo "===================================\n\n";
} else {
    echo "<h1>Examination Management System Setup</h1>";
}

// Examination System Tables SQL
$examinationTablesSql = [
    // Examination Managers Table
    "CREATE TABLE IF NOT EXISTS examination_managers (
        manager_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        phone VARCHAR(20),
        department VARCHAR(50),
        position VARCHAR(50) DEFAULT 'Examination Officer',
        hire_date DATE,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exams Table
    "CREATE TABLE IF NOT EXISTS exams (
        exam_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_code VARCHAR(20) NOT NULL UNIQUE,
        exam_name VARCHAR(200) NOT NULL,
        course_id INT,
        lecturer_id INT,
        exam_manager_id INT,
        exam_type ENUM('mid_term', 'final', 'quiz', 'assignment') DEFAULT 'mid_term',
        description TEXT,
        total_questions INT DEFAULT 0,
        total_marks INT DEFAULT 100,
        passing_marks INT DEFAULT 50,
        duration_minutes INT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        instructions TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE SET NULL,
        FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE SET NULL,
        FOREIGN KEY (exam_manager_id) REFERENCES examination_managers(manager_id) ON DELETE SET NULL,
        INDEX idx_course (course_id),
        INDEX idx_lecturer (lecturer_id),
        INDEX idx_manager (exam_manager_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Questions Table
    "CREATE TABLE IF NOT EXISTS exam_questions (
        question_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        question_number INT NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('multiple_choice', 'true_false', 'short_answer', 'essay') DEFAULT 'multiple_choice',
        options JSON NULL,
        correct_answer TEXT,
        marks INT DEFAULT 1,
        explanation TEXT NULL,
        FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
        INDEX idx_exam (exam_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Tokens Table
    "CREATE TABLE IF NOT EXISTS exam_tokens (
        token_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        student_id VARCHAR(20) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        is_used BOOLEAN DEFAULT FALSE,
        used_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_exam (exam_id),
        INDEX idx_student (student_id),
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Sessions Table
    "CREATE TABLE IF NOT EXISTS exam_sessions (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        student_id VARCHAR(20) NOT NULL,
        token_id INT NOT NULL,
        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        submitted_at DATETIME NULL,
        time_remaining INT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        ip_address VARCHAR(45),
        user_agent TEXT,
        browser_info JSON,
        INDEX idx_exam (exam_id),
        INDEX idx_student (student_id),
        INDEX idx_token (token_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Answers Table
    "CREATE TABLE IF NOT EXISTS exam_answers (
        answer_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        question_id INT NOT NULL,
        answer_text TEXT,
        is_correct BOOLEAN NULL,
        marks_obtained DECIMAL(5,2) DEFAULT 0,
        answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session (session_id),
        INDEX idx_question (question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Monitoring Table
    "CREATE TABLE IF NOT EXISTS exam_monitoring (
        monitoring_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        event_type ENUM('camera_snapshot', 'tab_visibility_change', 'fullscreen_exited', 'session_started', 'window_blur', 'window_focus') NOT NULL,
        event_data JSON,
        snapshot_path VARCHAR(500) NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45),
        INDEX idx_session (session_id),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Exam Results Table
    "CREATE TABLE IF NOT EXISTS exam_results (
        result_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        student_id VARCHAR(20) NOT NULL,
        session_id INT NOT NULL,
        total_marks DECIMAL(5,2),
        marks_obtained DECIMAL(5,2),
        percentage DECIMAL(5,2),
        grade VARCHAR(2),
        status ENUM('passed', 'failed', 'incomplete', 'flagged') DEFAULT 'incomplete',
        flagged_reason TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_exam (exam_id),
        INDEX idx_student (student_id),
        INDEX idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

try {
    if (!$is_cli) {
        echo "<div class='container mt-4'>";
        echo "<div class='card'>";
        echo "<div class='card-header'><h5>Creating Examination System Tables...</h5></div>";
        echo "<div class='card-body'>";
    } else {
        echo "\nCreating Examination System Tables...\n";
        echo "-------------------------------------\n";
    }

    // Execute examination tables
    $success = 0;
    $total = count($examinationTablesSql);

    foreach ($examinationTablesSql as $sql) {
        if ($conn->query($sql) === TRUE) {
            if ($is_cli) {
                echo "✓ Examination table created successfully\n";
            } else {
                echo "<div class='text-success'>✓ Examination table created successfully</div>";
            }
            $success++;
        } else {
            if ($is_cli) {
                echo "✗ Error creating examination table: " . $conn->error . "\n";
            } else {
                echo "<div class='text-danger'>✗ Error creating examination table: " . $conn->error . "</div>";
            }
        }
    }

    if ($is_cli) {
        echo "\nTables Created: $success / $total\n";
    } else {
        echo "<div class='mt-3'>";
        echo "<strong>Tables Created: $success / $total</strong><br>";
    }

    if ($success == $total) {
        if ($is_cli) {
            echo "\n✓ All examination system tables created successfully!\n\n";
            echo "Creating Default Examination Manager Account...\n";
            echo "-----------------------------------------------\n";
        } else {
            echo "<div class='alert alert-success mt-3'>✓ All examination system tables created successfully!</div>";
            echo "<h5 class='mt-4'>Creating Default Examination Manager Account...</h5>";
        }

        // Create default examination manager account
        // Check if examination manager already exists
        $checkManager = $conn->query("SELECT manager_id FROM examination_managers WHERE email = 'exam.manager@university.edu'");
        if ($checkManager->num_rows == 0) {
            $insertManager = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position) VALUES (?, ?, ?, ?, ?)");
            $insertManager->bind_param("sssss", $name, $email, $phone, $dept, $pos);

            $name = "Examination Officer";
            $email = "exam.manager@university.edu";
            $phone = "+260-211-123456";
            $dept = "Academic Affairs";
            $pos = "Examination Officer";

            if ($insertManager->execute()) {
                $managerId = $conn->insert_id;
                if ($is_cli) {
                    echo "✓ Default examination manager created (ID: $managerId)\n";
                } else {
                    echo "<div class='text-success'>✓ Default examination manager created (ID: $managerId)</div>";
                }

                // Create user account for examination manager
                $checkUser = $conn->query("SELECT user_id FROM users WHERE email = 'exam.manager@university.edu'");
                if ($checkUser->num_rows == 0) {
                    $password = password_hash("ExamManager2024!", PASSWORD_DEFAULT);
                    $insertUser = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'staff', ?)");
                    $insertUser->bind_param("sssi", $username, $userEmail, $password, $managerId);

                    $username = "exam_manager";
                    $userEmail = "exam.manager@university.edu";

                    if ($insertUser->execute()) {
                        if ($is_cli) {
                            echo "✓ User account created for examination manager\n\n";
                            echo "Login Credentials:\n";
                            echo "Username: exam_manager\n";
                            echo "Email: exam.manager@university.edu\n";
                            echo "Password: ExamManager2024!\n";
                            echo "Please change the password after first login.\n\n";
                        } else {
                            echo "<div class='text-success'>✓ User account created for examination manager</div>";
                            echo "<div class='alert alert-info mt-3'>";
                            echo "<strong>Login Credentials:</strong><br>";
                            echo "Username: exam_manager<br>";
                            echo "Email: exam.manager@university.edu<br>";
                            echo "Password: ExamManager2024!<br>";
                            echo "<em>Please change the password after first login.</em>";
                            echo "</div>";
                        }
                    } else {
                        if ($is_cli) {
                            echo "⚠ Could not create user account: " . $conn->error . "\n";
                        } else {
                            echo "<div class='text-warning'>⚠ Could not create user account: " . $conn->error . "</div>";
                        }
                    }
                } else {
                    if ($is_cli) {
                        echo "ℹ User account already exists for examination manager\n";
                    } else {
                        echo "<div class='text-info'>ℹ User account already exists for examination manager</div>";
                    }
                }
            } else {
                if ($is_cli) {
                    echo "✗ Error creating examination manager: " . $conn->error . "\n";
                } else {
                    echo "<div class='text-danger'>✗ Error creating examination manager: " . $conn->error . "</div>";
                }
            }
        } else {
            if ($is_cli) {
                echo "ℹ Default examination manager already exists\n";
            } else {
                echo "<div class='text-info'>ℹ Default examination manager already exists</div>";
            }
        }

        if ($is_cli) {
            echo "\n✓ Examination Management System Setup Complete!\n";
            echo "You can now access the examination manager dashboard at: /examination_manager/dashboard.php\n";
        } else {
            echo "<div class='alert alert-success mt-3'>";
            echo "<strong>✓ Examination Management System Setup Complete!</strong><br>";
            echo "You can now access the examination manager dashboard at: <code>/examination_manager/dashboard.php</code>";
            echo "</div>";
        }

    } else {
        if ($is_cli) {
            echo "\n✗ Some tables failed to create. Please check the errors above.\n";
        } else {
            echo "<div class='alert alert-danger mt-3'>✗ Some tables failed to create. Please check the errors above.</div>";
        }
    }

    if (!$is_cli) {
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }

} catch (Exception $e) {
    if ($is_cli) {
        echo "\nSetup Error: " . $e->getMessage() . "\n";
    } else {
        echo "<div class='alert alert-danger mt-4'>";
        echo "<strong>Setup Error:</strong> " . $e->getMessage();
        echo "</div>";
    }
}
?></content>
<parameter name="filePath">c:\xampp\htdocs\vle-eumw\setup_examination_system.php