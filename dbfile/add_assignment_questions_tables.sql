-- Add table for assignment questions
CREATE TABLE IF NOT EXISTS vle_assignment_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice', 'open_ended') DEFAULT 'open_ended',
    options JSON NULL,
    correct_answer TEXT NULL,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES vle_assignments(assignment_id)
);

-- Add table for student answers to assignment questions
CREATE TABLE IF NOT EXISTS vle_assignment_answers (
    answer_id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    assignment_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    answer_text TEXT,
    is_correct BOOLEAN NULL,
    submitted_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES vle_assignment_questions(question_id),
    FOREIGN KEY (assignment_id) REFERENCES vle_assignments(assignment_id),
    FOREIGN KEY (student_id) REFERENCES students(student_id)
);