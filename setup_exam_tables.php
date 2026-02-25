<?php
// setup_exam_tables.php - Setup/Update Examination Tables for Admin Integration
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h1>Examination System Tables Setup</h1>";
echo "<p>Setting up examination tables for admin integration...</p>";

$success = 0;
$errors = [];

// Drop and recreate tables to ensure correct structure
$tables_to_drop = ['exam_monitoring', 'exam_results', 'exam_answers', 'exam_sessions', 'exam_tokens', 'exam_questions', 'exams'];

echo "<h3>Preparing Tables...</h3>";

// Note: Only drop if we want a fresh start - commenting out for safety
// foreach ($tables_to_drop as $table) {
//     $conn->query("DROP TABLE IF EXISTS $table");
//     echo "<p>Dropped table (if existed): $table</p>";
// }

// Create/Update Exams Table
$sql_exams = "CREATE TABLE IF NOT EXISTS exams (
    exam_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_code VARCHAR(50) NOT NULL UNIQUE,
    exam_name VARCHAR(255) NOT NULL,
    exam_type ENUM('quiz', 'mid_term', 'final', 'assignment') DEFAULT 'final',
    course_id INT NULL,
    lecturer_id INT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 60,
    total_marks INT NOT NULL DEFAULT 100,
    passing_marks INT NOT NULL DEFAULT 40,
    max_attempts INT DEFAULT 1,
    shuffle_questions TINYINT(1) DEFAULT 1,
    shuffle_options TINYINT(1) DEFAULT 1,
    show_results TINYINT(1) DEFAULT 1,
    allow_review TINYINT(1) DEFAULT 0,
    require_camera TINYINT(1) DEFAULT 1,
    require_token TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_course (course_id),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_active (is_active),
    INDEX idx_start_time (start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_exams)) {
    echo "<p style='color:green;'>✓ exams table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exams table: " . $conn->error . "</p>";
    $errors[] = "exams: " . $conn->error;
}

// Add missing columns to exams table if it exists
$alter_statements = [
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS shuffle_questions TINYINT(1) DEFAULT 1",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS shuffle_options TINYINT(1) DEFAULT 1",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS show_results TINYINT(1) DEFAULT 1",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS allow_review TINYINT(1) DEFAULT 0",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS require_camera TINYINT(1) DEFAULT 1",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS require_token TINYINT(1) DEFAULT 0",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS max_attempts INT DEFAULT 1",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS created_by INT NULL",
    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL"
];

foreach ($alter_statements as $sql) {
    $conn->query($sql); // Ignore errors as columns may already exist
}

// Enable camera invigilation for ALL existing exams
$conn->query("UPDATE exams SET require_camera = 1 WHERE require_camera = 0");
echo "<p style='color:green;'>✓ Camera invigilation enabled for all exams</p>";

// Create Exam Questions Table
$sql_questions = "CREATE TABLE IF NOT EXISTS exam_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice', 'multiple_answer', 'true_false', 'short_answer', 'essay') DEFAULT 'multiple_choice',
    options JSON NULL,
    correct_answer TEXT NULL,
    marks INT NOT NULL DEFAULT 1,
    question_order INT DEFAULT 0,
    explanation TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_order (question_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_questions)) {
    echo "<p style='color:green;'>✓ exam_questions table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_questions table: " . $conn->error . "</p>";
    $errors[] = "exam_questions: " . $conn->error;
}

// Add missing columns
$conn->query("ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS question_order INT DEFAULT 0");
$conn->query("ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP");

// Create Exam Tokens Table
$sql_tokens = "CREATE TABLE IF NOT EXISTS exam_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    token VARCHAR(50) NOT NULL UNIQUE,
    token_type ENUM('single_use', 'multi_use') DEFAULT 'single_use',
    is_used TINYINT(1) DEFAULT 0,
    used_by INT NULL,
    used_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_token (token),
    INDEX idx_used (is_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_tokens)) {
    echo "<p style='color:green;'>✓ exam_tokens table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_tokens table: " . $conn->error . "</p>";
    $errors[] = "exam_tokens: " . $conn->error;
}

// Add missing columns
$conn->query("ALTER TABLE exam_tokens ADD COLUMN IF NOT EXISTS token_type ENUM('single_use', 'multi_use') DEFAULT 'single_use'");
$conn->query("ALTER TABLE exam_tokens ADD COLUMN IF NOT EXISTS used_by INT NULL");
$conn->query("ALTER TABLE exam_tokens ADD COLUMN IF NOT EXISTS created_by INT NULL");

// Create Exam Sessions Table
$sql_sessions = "CREATE TABLE IF NOT EXISTS exam_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    token_id INT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    status ENUM('in_progress', 'completed', 'abandoned', 'timed_out') DEFAULT 'in_progress',
    time_remaining INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    browser_info JSON NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_sessions)) {
    echo "<p style='color:green;'>✓ exam_sessions table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_sessions table: " . $conn->error . "</p>";
    $errors[] = "exam_sessions: " . $conn->error;
}

// Add missing columns
$conn->query("ALTER TABLE exam_sessions ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL");
$conn->query("ALTER TABLE exam_sessions ADD COLUMN IF NOT EXISTS status ENUM('in_progress', 'completed', 'abandoned', 'timed_out') DEFAULT 'in_progress'");

// Create Exam Answers Table
$sql_answers = "CREATE TABLE IF NOT EXISTS exam_answers (
    answer_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_text TEXT NULL,
    is_correct TINYINT(1) NULL,
    marks_obtained DECIMAL(10,2) DEFAULT 0,
    answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES exam_questions(question_id) ON DELETE CASCADE,
    INDEX idx_session (session_id),
    INDEX idx_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_answers)) {
    echo "<p style='color:green;'>✓ exam_answers table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_answers table: " . $conn->error . "</p>";
    $errors[] = "exam_answers: " . $conn->error;
}

// Create Exam Results Table
$sql_results = "CREATE TABLE IF NOT EXISTS exam_results (
    result_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    session_id INT NULL,
    score DECIMAL(10,2) NOT NULL DEFAULT 0,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_passed TINYINT(1) DEFAULT 0,
    grade VARCHAR(5) NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    remarks TEXT NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_passed (is_passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_results)) {
    echo "<p style='color:green;'>✓ exam_results table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_results table: " . $conn->error . "</p>";
    $errors[] = "exam_results: " . $conn->error;
}

// Add missing columns
$conn->query("ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS score DECIMAL(10,2) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS is_passed TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP");

// Create Exam Monitoring Table
$sql_monitoring = "CREATE TABLE IF NOT EXISTS exam_monitoring (
    monitoring_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    event_type ENUM('camera_snapshot', 'tab_change', 'window_blur', 'window_focus', 'fullscreen_exit', 'copy_attempt', 'right_click', 'violation') NOT NULL,
    event_data JSON NULL,
    snapshot_path VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_session (session_id),
    INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql_monitoring)) {
    echo "<p style='color:green;'>✓ exam_monitoring table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ Error with exam_monitoring table: " . $conn->error . "</p>";
    $errors[] = "exam_monitoring: " . $conn->error;
}

echo "<hr>";
echo "<h3>Summary</h3>";
echo "<p><strong>Tables processed:</strong> 7</p>";
echo "<p><strong>Successful:</strong> $success</p>";

if (count($errors) > 0) {
    echo "<p><strong>Errors:</strong></p>";
    echo "<ul>";
    foreach ($errors as $err) {
        echo "<li style='color:red;'>$err</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:green;'><strong>✓ All examination tables are ready!</strong></p>";
}

echo "<hr>";
echo "<p><a href='admin/manage_exams.php' class='btn btn-primary' style='background:#1e3c72;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>Go to Examination Management</a></p>";
?>
