<?php
/**
 * Setup Exam Sections & Sub-Questions Support
 * Adds: exam_sections table, parent_question_id + section_id to exam_questions
 */
require_once 'includes/config.php';
$conn = getDbConnection();

echo "<h1>Examination Sections & Sub-Questions Setup</h1>";
$success = 0;
$errors = [];

// 1. Create exam_sections table
$sql = "CREATE TABLE IF NOT EXISTS exam_sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    section_label VARCHAR(20) NOT NULL DEFAULT 'A' COMMENT 'e.g. A, B, C',
    section_title VARCHAR(255) NOT NULL COMMENT 'e.g. Section A: Short Answer Questions',
    description TEXT NULL,
    section_order INT DEFAULT 0,
    total_marks INT DEFAULT 0 COMMENT 'total marks for this section',
    instructions TEXT NULL COMMENT 'section-specific instructions',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam_order (exam_id, section_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "<p style='color:green;'>✓ exam_sections table created/verified</p>";
    $success++;
} else {
    echo "<p style='color:red;'>✗ exam_sections: " . $conn->error . "</p>";
    $errors[] = $conn->error;
}

// 2. Add section_id column to exam_questions
$col_check = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'section_id'");
if ($col_check && $col_check->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_questions ADD COLUMN section_id INT NULL AFTER exam_id")) {
        echo "<p style='color:green;'>✓ Added section_id to exam_questions</p>";
        $success++;
    } else {
        echo "<p style='color:red;'>✗ section_id: " . $conn->error . "</p>";
        $errors[] = $conn->error;
    }
    // Add FK
    $conn->query("ALTER TABLE exam_questions ADD FOREIGN KEY (section_id) REFERENCES exam_sections(section_id) ON DELETE SET NULL");
} else {
    echo "<p style='color:blue;'>ℹ section_id already exists</p>";
}

// 3. Add parent_question_id for sub-questions
$col_check = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'parent_question_id'");
if ($col_check && $col_check->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_questions ADD COLUMN parent_question_id INT NULL AFTER section_id")) {
        echo "<p style='color:green;'>✓ Added parent_question_id to exam_questions</p>";
        $success++;
    } else {
        echo "<p style='color:red;'>✗ parent_question_id: " . $conn->error . "</p>";
        $errors[] = $conn->error;
    }
    $conn->query("ALTER TABLE exam_questions ADD FOREIGN KEY (parent_question_id) REFERENCES exam_questions(question_id) ON DELETE CASCADE");
} else {
    echo "<p style='color:blue;'>ℹ parent_question_id already exists</p>";
}

// 4. Add sub_label column (e.g. "a", "b", "c" for sub-questions, or "i", "ii")
$col_check = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'sub_label'");
if ($col_check && $col_check->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_questions ADD COLUMN sub_label VARCHAR(10) NULL AFTER parent_question_id")) {
        echo "<p style='color:green;'>✓ Added sub_label to exam_questions</p>";
        $success++;
    } else {
        echo "<p style='color:red;'>✗ sub_label: " . $conn->error . "</p>";
        $errors[] = $conn->error;
    }
} else {
    echo "<p style='color:blue;'>ℹ sub_label already exists</p>";
}

// 5. Widen question_text to LONGTEXT for rich HTML content
$conn->query("ALTER TABLE exam_questions MODIFY COLUMN question_text LONGTEXT NOT NULL");
echo "<p style='color:green;'>✓ question_text widened to LONGTEXT</p>";

// 6. Widen answer_text in exam_answers for rich HTML answers
$conn->query("ALTER TABLE exam_answers MODIFY COLUMN answer_text LONGTEXT NULL");
echo "<p style='color:green;'>✓ answer_text widened to LONGTEXT</p>";

// 7. Add explanation column to exam_questions if missing
$conn->query("ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS explanation LONGTEXT NULL");

echo "<hr><h3>Summary</h3>";
echo "<p>$success operations completed successfully.</p>";
if ($errors) {
    echo "<p style='color:red;'>Errors: " . implode('; ', $errors) . "</p>";
}
echo "<p><a href='examination_officer/manage_exams.php'>Go to Exam Management</a></p>";
?>
