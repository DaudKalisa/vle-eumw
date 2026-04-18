-- =====================================================
-- VLE Database Sync Script for Production
-- Target: if0_40881536_exploits_vle (ct.ws / gt.tc)
-- Generated: 2026-03-05
-- Run in phpMyAdmin SQL tab
-- =====================================================

-- =====================================================
-- 1. FIX USERS ROLE ENUM (add examination_manager)
-- =====================================================
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('student','lecturer','staff','hod','dean','finance','admin','examination_manager','odl_coordinator') DEFAULT 'student';

-- =====================================================
-- 2. ADD additional_roles COLUMN if missing
-- =====================================================
-- Check manually: SHOW COLUMNS FROM users LIKE 'additional_roles';
-- If missing, run:
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `additional_roles` VARCHAR(255) DEFAULT NULL AFTER `role`;

-- =====================================================
-- 3. CREATE MISSING TABLES
-- =====================================================

-- 3a. Attendance System Tables
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
    `session_id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT NOT NULL,
    `lecturer_id` INT NOT NULL,
    `session_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NULL,
    `session_code` VARCHAR(10) UNIQUE NOT NULL,
    `qr_code_data` TEXT NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_records` (
    `record_id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `student_id` VARCHAR(20) NOT NULL,
    `check_in_time` DATETIME NOT NULL,
    `check_out_time` DATETIME NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `status` ENUM('present', 'late', 'left_early', 'unresponsive') DEFAULT 'present',
    `duration_minutes` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (`session_id`, `student_id`),
    INDEX `idx_session` (`session_id`),
    INDEX `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3b. Quiz System Tables
CREATE TABLE IF NOT EXISTS `vle_quizzes` (
    `quiz_id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT,
    `week_number` INT,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `time_limit` INT NULL,
    `attempts_allowed` INT DEFAULT 1,
    `passing_score` DECIMAL(5,2) DEFAULT 50.00,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vle_quiz_questions` (
    `question_id` INT AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT,
    `question_text` TEXT NOT NULL,
    `question_type` ENUM('multiple_choice', 'true_false', 'short_answer') DEFAULT 'multiple_choice',
    `correct_answer` TEXT,
    `options` JSON NULL,
    `points` DECIMAL(5,2) DEFAULT 1.00,
    `order_num` INT DEFAULT 0,
    INDEX `idx_quiz` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vle_quiz_attempts` (
    `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT,
    `student_id` VARCHAR(20),
    `attempt_number` INT DEFAULT 1,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    `score` DECIMAL(5,2) NULL,
    `max_score` DECIMAL(5,2) NULL,
    `status` ENUM('in_progress', 'completed', 'timed_out') DEFAULT 'in_progress',
    INDEX `idx_quiz` (`quiz_id`),
    INDEX `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vle_quiz_answers` (
    `answer_id` INT AUTO_INCREMENT PRIMARY KEY,
    `attempt_id` INT,
    `question_id` INT,
    `answer_text` TEXT,
    `is_correct` BOOLEAN,
    `points_earned` DECIMAL(5,2) DEFAULT 0.00,
    INDEX `idx_attempt` (`attempt_id`),
    INDEX `idx_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3c. Download Requests Table
CREATE TABLE IF NOT EXISTS `vle_download_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` VARCHAR(20),
    `content_id` INT,
    `lecturer_id` INT,
    `file_path` VARCHAR(500),
    `file_name` VARCHAR(255),
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `approved_at` DATETIME NULL,
    INDEX `idx_student` (`student_id`),
    INDEX `idx_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3d. Zoom Settings Table
CREATE TABLE IF NOT EXISTS `zoom_settings` (
    `setting_id` INT PRIMARY KEY AUTO_INCREMENT,
    `zoom_account_email` VARCHAR(100) NOT NULL UNIQUE,
    `zoom_api_key` VARCHAR(255) NOT NULL,
    `zoom_api_secret` VARCHAR(500) NOT NULL,
    `zoom_meeting_password` VARCHAR(20),
    `zoom_enable_recording` BOOLEAN DEFAULT TRUE,
    `zoom_require_authentication` BOOLEAN DEFAULT TRUE,
    `zoom_wait_for_host` BOOLEAN DEFAULT TRUE,
    `zoom_auto_recording` ENUM('local', 'cloud', 'none') DEFAULT 'none',
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3e. Course Programs Table (links courses to programs)
CREATE TABLE IF NOT EXISTS `course_programs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT NOT NULL,
    `program_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_course_program` (`course_id`, `program_id`),
    KEY `program_id` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3f. Lecturer Finance Requests Table
CREATE TABLE IF NOT EXISTS `lecturer_finance_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `lecturer_id` INT(11) NOT NULL,
    `request_type` ENUM('monthly_payment','airtime') NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `total_amount` DECIMAL(10,2) DEFAULT NULL,
    `details` TEXT,
    `request_date` DATETIME NOT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `response` TEXT,
    `response_date` DATETIME,
    `odl_approval_status` ENUM('pending','approved','rejected','forwarded_to_dean') DEFAULT NULL,
    `odl_approved_by` INT DEFAULT NULL,
    `odl_approval_date` DATETIME DEFAULT NULL,
    `odl_comments` TEXT DEFAULT NULL,
    `dean_approval_status` ENUM('pending','approved','rejected','returned') DEFAULT NULL,
    `dean_approved_by` INT DEFAULT NULL,
    `dean_approval_date` DATETIME DEFAULT NULL,
    `dean_comments` TEXT DEFAULT NULL,
    INDEX (`lecturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3g. Finance Audit Log Table
CREATE TABLE IF NOT EXISTS `finance_audit_log` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `action_type` VARCHAR(50) NOT NULL,
    `performed_by` VARCHAR(10) NOT NULL,
    `finance_role` VARCHAR(20) NOT NULL,
    `target_student_id` VARCHAR(20) DEFAULT NULL,
    `target_transaction_id` INT(11) DEFAULT NULL,
    `amount` DECIMAL(10,2) DEFAULT NULL,
    `action_details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3h. Payment Approvals Table
CREATE TABLE IF NOT EXISTS `payment_approvals` (
    `approval_id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT(11) NOT NULL,
    `student_id` VARCHAR(20) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_type` VARCHAR(50) NOT NULL,
    `entered_by` VARCHAR(10) NOT NULL,
    `entered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_by` VARCHAR(10) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejected_by` VARCHAR(10) DEFAULT NULL,
    `rejected_at` DATETIME DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `notes` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3i. Assignment Questions & Answers Tables
CREATE TABLE IF NOT EXISTS `vle_assignment_questions` (
    `question_id` INT AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT NOT NULL,
    `question_text` TEXT NOT NULL,
    `question_type` ENUM('multiple_choice', 'open_ended') DEFAULT 'open_ended',
    `options` JSON NULL,
    `correct_answer` TEXT NULL,
    `created_date` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vle_assignment_answers` (
    `answer_id` INT AUTO_INCREMENT PRIMARY KEY,
    `question_id` INT NOT NULL,
    `assignment_id` INT NOT NULL,
    `student_id` VARCHAR(20) NOT NULL,
    `answer_text` TEXT,
    `is_correct` BOOLEAN NULL,
    `submitted_date` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. RESET ADMIN PASSWORD (to: 3xp10!ts)
-- =====================================================
UPDATE `users` 
SET `password_hash` = '$2y$10$z4Whe4FRM0FMhjomdnMqcOYtnBek3rFuRGTf.ePGw5Ffon.pjvVOa' 
WHERE `username` = 'admin';

-- =====================================================
-- 5. VERIFY: Run these after to confirm
-- =====================================================
-- SHOW TABLES;
-- SHOW COLUMNS FROM users LIKE 'role';
-- SELECT user_id, username, role, additional_roles, is_active FROM users WHERE username = 'admin';

-- =====================================================
-- END OF SYNC SCRIPT
-- =====================================================
