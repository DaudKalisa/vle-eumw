<?php
// setup.php - Setup script for VLE System
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h1>VLE System Setup</h1>";

// Base Tables SQL
$baseTablesSql = "
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'lecturer', 'staff', 'hod', 'dean', 'finance', 'examination_manager') NOT NULL,
    related_student_id VARCHAR(20) NULL,
    related_lecturer_id INT NULL,
    related_staff_id INT NULL,
    related_hod_id INT NULL,
    related_dean_id INT NULL,
    related_finance_id INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    student_id VARCHAR(20) PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    department VARCHAR(50),
    year_of_study INT,
    enrollment_date DATE,
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lecturers (
    lecturer_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    department VARCHAR(50),
    position VARCHAR(50),
    hire_date DATE,
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS administrative_staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    department VARCHAR(50),
    position VARCHAR(50),
    hire_date DATE,
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

// VLE Tables SQL (same as create_vle_tables.php)
$vleTablesSql = "
CREATE TABLE IF NOT EXISTS vle_courses (
    course_id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL,
    description TEXT,
    lecturer_id INT,
    total_weeks INT DEFAULT 16,
    is_active BOOLEAN DEFAULT TRUE,
    program_of_study VARCHAR(100),
    year_of_study INT,
    semester ENUM('One', 'Two') DEFAULT 'One',
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_enrollments (
    enrollment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20),
    course_id INT,
    enrollment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    current_week INT DEFAULT 1,
    is_completed BOOLEAN DEFAULT FALSE,
    completion_date DATETIME NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    UNIQUE (student_id, course_id),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_weekly_content (
    content_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    week_number INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    content_type ENUM('presentation', 'video', 'document', 'link', 'text') DEFAULT 'text',
    file_path VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
    is_mandatory BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    week_number INT,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    assignment_type ENUM('formative', 'summative', 'mid_sem', 'final_exam') DEFAULT 'formative',
    max_score INT DEFAULT 100,
    passing_score INT DEFAULT 50,
    due_date DATETIME,
    file_path VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT,
    student_id VARCHAR(20),
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
    text_content TEXT NULL,
    score DECIMAL(5,2) NULL,
    feedback TEXT NULL,
    graded_by INT NULL,
    graded_date DATETIME NULL,
    status ENUM('submitted', 'graded', 'late') DEFAULT 'submitted',
    FOREIGN KEY (assignment_id) REFERENCES vle_assignments(assignment_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE (assignment_id, student_id),
    INDEX idx_assignment (assignment_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_progress (
    progress_id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT,
    week_number INT,
    content_id INT NULL,
    assignment_id INT NULL,
    progress_type ENUM('content_viewed', 'assignment_completed', 'week_completed') NOT NULL,
    completion_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    score DECIMAL(5,2) NULL,
    FOREIGN KEY (enrollment_id) REFERENCES vle_enrollments(enrollment_id) ON DELETE CASCADE,
    FOREIGN KEY (content_id) REFERENCES vle_weekly_content(content_id) ON DELETE SET NULL,
    FOREIGN KEY (assignment_id) REFERENCES vle_assignments(assignment_id) ON DELETE SET NULL,
    INDEX idx_enrollment (enrollment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_forums (
    forum_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    week_number INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_forum_posts (
    post_id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT,
    parent_post_id INT NULL,
    user_id INT,
    title VARCHAR(200) NULL,
    content TEXT NOT NULL,
    post_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_pinned BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (forum_id) REFERENCES vle_forums(forum_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_post_id) REFERENCES vle_forum_posts(post_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_forum (forum_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_grades (
    grade_id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT,
    assignment_id INT NULL,
    grade_type ENUM('formative', 'summative', 'mid_sem', 'final_exam', 'overall') NOT NULL,
    score DECIMAL(5,2),
    max_score DECIMAL(5,2),
    percentage DECIMAL(5,2),
    grade_letter VARCHAR(2),
    graded_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_id) REFERENCES vle_enrollments(enrollment_id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_id) REFERENCES vle_assignments(assignment_id) ON DELETE SET NULL,
    INDEX idx_enrollment (enrollment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance Session Management Tables
CREATE TABLE IF NOT EXISTS attendance_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    lecturer_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    session_code VARCHAR(10) UNIQUE NOT NULL,
    qr_code_data TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    check_in_time DATETIME NOT NULL,
    check_out_time DATETIME NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('present', 'late', 'left_early', 'unresponsive') DEFAULT 'present',
    duration_minutes INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messaging System
CREATE TABLE IF NOT EXISTS vle_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('student', 'lecturer', 'admin', 'finance') NOT NULL,
    sender_id VARCHAR(50) NOT NULL,
    recipient_type ENUM('student', 'lecturer', 'admin', 'finance') NOT NULL,
    recipient_id VARCHAR(50) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    read_date DATETIME NULL,
    INDEX (recipient_type, recipient_id),
    INDEX (sender_type, sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quiz Tables
CREATE TABLE IF NOT EXISTS vle_quizzes (
    quiz_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    week_number INT,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    time_limit INT NULL, -- in minutes
    attempts_allowed INT DEFAULT 1,
    passing_score DECIMAL(5,2) DEFAULT 50.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_quiz_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice', 'true_false', 'short_answer') DEFAULT 'multiple_choice',
    correct_answer TEXT,
    options JSON NULL, -- for multiple choice
    points DECIMAL(5,2) DEFAULT 1.00,
    order_num INT DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES vle_quizzes(quiz_id) ON DELETE CASCADE,
    INDEX idx_quiz (quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_quiz_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT,
    student_id VARCHAR(20),
    attempt_number INT DEFAULT 1,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    score DECIMAL(5,2) NULL,
    max_score DECIMAL(5,2) NULL,
    status ENUM('in_progress', 'completed', 'timed_out') DEFAULT 'in_progress',
    FOREIGN KEY (quiz_id) REFERENCES vle_quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_quiz (quiz_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_quiz_answers (
    answer_id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT,
    question_id INT,
    answer_text TEXT,
    is_correct BOOLEAN,
    points_earned DECIMAL(5,2) DEFAULT 0.00,
    FOREIGN KEY (attempt_id) REFERENCES vle_quiz_attempts(attempt_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES vle_quiz_questions(question_id) ON DELETE CASCADE,
    INDEX idx_attempt (attempt_id),
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Download Requests Table
CREATE TABLE IF NOT EXISTS vle_download_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20),
    content_id INT,
    lecturer_id INT,
    file_path VARCHAR(500),
    file_name VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (content_id) REFERENCES vle_weekly_content(content_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_student (student_id),
    INDEX idx_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_live_sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    lecturer_id VARCHAR(50) NOT NULL,
    session_name VARCHAR(255) NOT NULL,
    session_code VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('pending', 'active', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    max_participants INT DEFAULT 50,
    meeting_url VARCHAR(500),
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_session_participants (
    participant_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    joined_at TIMESTAMP NULL,
    left_at TIMESTAMP NULL,
    status ENUM('invited', 'joined', 'left') DEFAULT 'invited',
    FOREIGN KEY (session_id) REFERENCES vle_live_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_student (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vle_session_invites (
    invite_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    viewed_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    status ENUM('sent', 'viewed', 'accepted', 'declined') DEFAULT 'sent',
    FOREIGN KEY (session_id) REFERENCES vle_live_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_invite (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zoom_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    zoom_account_email VARCHAR(100) NOT NULL UNIQUE,
    zoom_api_key VARCHAR(255) NOT NULL,
    zoom_api_secret VARCHAR(500) NOT NULL,
    zoom_meeting_password VARCHAR(20),
    zoom_enable_recording BOOLEAN DEFAULT TRUE,
    zoom_require_authentication BOOLEAN DEFAULT TRUE,
    zoom_wait_for_host BOOLEAN DEFAULT TRUE,
    zoom_auto_recording ENUM('local', 'cloud', 'none') DEFAULT 'none',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    echo "<div class='container mt-4'>";
    echo "<div class='card'>";
    echo "<div class='card-header'><h5>Creating Base Tables...</h5></div>";
    echo "<div class='card-body'>";

    // Execute base tables
    $baseStatements = array_filter(array_map('trim', explode(';', $baseTablesSql)));
    $baseSuccess = 0;
    $baseTotal = 0;

    foreach ($baseStatements as $sql) {
        if (!empty($sql) && !preg_match('/^--/', $sql)) {
            $baseTotal++;
            if ($conn->query($sql) === TRUE) {
                echo "<div class='text-success'>✓ Base table created successfully</div>";
                $baseSuccess++;
            } else {
                echo "<div class='text-danger'>✗ Error creating base table: " . $conn->error . "</div>";
            }
        }
    }

    echo "</div>";
    echo "<div class='card-footer'><strong>Base Tables: $baseSuccess/$baseTotal created</strong></div>";
    echo "</div>";

    // Now VLE tables
    // Split the SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $vleTablesSql)));

    echo "<div class='card mt-3'>";
    echo "<div class='card-header'><h5>Creating VLE Tables...</h5></div>";
    echo "<div class='card-body'>";

    $successCount = 0;
    $totalCount = 0;

    foreach ($statements as $sql) {
        if (!empty($sql) && !preg_match('/^--/', $sql)) {
            $totalCount++;
            if ($conn->query($sql) === TRUE) {
                echo "<div class='text-success'>✓ Table created successfully</div>";
                $successCount++;
            } else {
                echo "<div class='text-danger'>✗ Error creating table: " . $conn->error . "</div>";
            }
        }
    }

    echo "</div>";
    echo "<div class='card-footer'>";

    $totalTables = $baseTotal + $totalCount;
    $totalSuccess = $baseSuccess + $successCount;

    echo "<strong>Setup Complete: $totalSuccess/$totalTables tables created successfully</strong><br>";
    echo "<a href='index.php' class='btn btn-primary'>Go to VLE System</a>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

    // Insert sample data
    echo "<div class='card mt-3'>";
    echo "<div class='card-header'><h5>Inserting Sample Data...</h5></div>";
    echo "<div class='card-body'>";

    $sampleData = [
        "INSERT INTO lecturers (full_name, email, department, position) VALUES ('Dr. John Smith', 'john.smith@university.edu', 'Computer Science', 'Senior Lecturer')",
        "INSERT INTO students (student_id, full_name, email, department, year_of_study) VALUES ('STU001', 'Alice Johnson', 'alice.johnson@student.university.edu', 'Computer Science', 3)",
        "INSERT INTO users (username, email, password_hash, role, related_lecturer_id, must_change_password) VALUES ('lecturer', 'john.smith@university.edu', '" . password_hash('mvustan', PASSWORD_DEFAULT) . "', 'lecturer', 1, 1)",
        "INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES ('student', 'alice.johnson@student.university.edu', '" . password_hash('password', PASSWORD_DEFAULT) . "', 'student', 'STU001', 1)"
    ];

    $sampleSuccess = 0;
    foreach ($sampleData as $sql) {
        if ($conn->query($sql) === TRUE) {
            echo "<div class='text-success'>✓ Sample data inserted</div>";
            $sampleSuccess++;
        } else {
            echo "<div class='text-warning'>⚠ Error inserting sample data: " . $conn->error . "</div>";
        }
    }

    echo "</div>";
    echo "<div class='card-footer'><strong>Sample Data: $sampleSuccess/" . count($sampleData) . " inserted</strong></div>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
</body>
</html>