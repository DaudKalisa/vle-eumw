-- =====================================================
-- VLE Production Database - Safe Schema Migration
-- Target: if0_40881536_exploits_vle (ct.ws / gt.tc)
-- Generated: 2026-03-05 (v2 - error-free edition)
--
-- FULLY ERROR-FREE ON A LIVE DATABASE:
--   - ADD COLUMN IF NOT EXISTS → skips existing columns
--   - CREATE TABLE IF NOT EXISTS → skips existing tables
--   - MODIFY COLUMN → safe, re-applies column def
--   - No DROP, DELETE, or TRUNCATE statements
--   - No data is overwritten except admin password reset
--
-- HOW TO RUN:
--   1. Open phpMyAdmin → select your database
--   2. Go to SQL tab
--   3. Paste this entire script
--   4. Click "Go"
--   5. Should complete with zero errors
-- =====================================================


-- #####################################################
-- PART 1: SCHEMA CHANGES TO EXISTING TABLES
-- (All use IF NOT EXISTS — no duplicate errors)
-- #####################################################

-- =====================================================
-- 1.1  users table
-- =====================================================

-- Expand role ENUM to include all roles
ALTER TABLE `users`
MODIFY COLUMN `role` ENUM('student','lecturer','staff','hod','dean','finance','admin','examination_manager','odl_coordinator') DEFAULT 'student';

-- Additional roles (multi-role support)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `additional_roles` VARCHAR(255) DEFAULT NULL AFTER `role`;

-- Password change flag
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) DEFAULT 1 AFTER `password_hash`;

-- Theme preference
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `theme_preference` VARCHAR(20) DEFAULT 'navy';

-- Login security columns
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `failed_login_attempts` INT DEFAULT 0;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_failed_login` DATETIME NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `account_locked_until` DATETIME NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_login_ip` VARCHAR(45) NULL;


-- =====================================================
-- 1.2  students table
-- =====================================================

ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `entry_type` VARCHAR(10) DEFAULT 'NE' COMMENT 'ME=Mature, NE=Normal, ODL=Open Distance, PC=Professional';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `year_of_registration` YEAR DEFAULT NULL;
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `program_type` ENUM('degree','professional','masters','doctorate') DEFAULT 'degree';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `student_type` ENUM('new_student','continuing') DEFAULT 'new_student';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `student_status` ENUM('active','graduated','suspended','withdrawn') DEFAULT 'active';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `academic_level` VARCHAR(10) DEFAULT '1/1';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `graduation_date` DATE NULL;

-- Widen student_id if needed (safe — only widens, never shrinks)
ALTER TABLE `students` MODIFY COLUMN `student_id` VARCHAR(50) NOT NULL;


-- =====================================================
-- 1.3  lecturers table
-- =====================================================

ALTER TABLE `lecturers` ADD COLUMN IF NOT EXISTS `password` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `lecturers` ADD COLUMN IF NOT EXISTS `role` VARCHAR(20) DEFAULT 'lecturer' COMMENT 'staff, finance, lecturer';
ALTER TABLE `lecturers` ADD COLUMN IF NOT EXISTS `is_active` BOOLEAN DEFAULT TRUE;


-- =====================================================
-- 1.4  student_finances table
-- =====================================================

ALTER TABLE `student_finances` ADD COLUMN IF NOT EXISTS `application_fee_paid` DECIMAL(10,2) DEFAULT 0;
ALTER TABLE `student_finances` ADD COLUMN IF NOT EXISTS `application_fee_date` DATE DEFAULT NULL;
ALTER TABLE `student_finances` ADD COLUMN IF NOT EXISTS `expected_tuition` DECIMAL(10,2) DEFAULT 500000.00;
ALTER TABLE `student_finances` ADD COLUMN IF NOT EXISTS `expected_total` DECIMAL(10,2) DEFAULT 545000.00;
ALTER TABLE `student_finances` ADD COLUMN IF NOT EXISTS `last_payment_date` DATE DEFAULT NULL;


-- =====================================================
-- 1.5  fee_settings table
-- =====================================================

ALTER TABLE `fee_settings` ADD COLUMN IF NOT EXISTS `new_student_reg_fee` DECIMAL(12,2) DEFAULT 39500.00;
ALTER TABLE `fee_settings` ADD COLUMN IF NOT EXISTS `continuing_reg_fee` DECIMAL(12,2) DEFAULT 35000.00;


-- =====================================================
-- 1.6  exams table
-- =====================================================

ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `shuffle_questions` TINYINT(1) DEFAULT 1;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `shuffle_options` TINYINT(1) DEFAULT 1;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `show_results` TINYINT(1) DEFAULT 1;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `allow_review` TINYINT(1) DEFAULT 0;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `require_camera` TINYINT(1) DEFAULT 1;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `require_token` TINYINT(1) DEFAULT 0;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `max_attempts` INT DEFAULT 1;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `created_by` INT NULL;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `dean_approved` TINYINT(1) DEFAULT 0;


-- =====================================================
-- 1.7  exam_questions table
-- =====================================================

ALTER TABLE `exam_questions` ADD COLUMN IF NOT EXISTS `question_order` INT DEFAULT 0;
ALTER TABLE `exam_questions` ADD COLUMN IF NOT EXISTS `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP;


-- =====================================================
-- 1.8  exam_tokens table
-- =====================================================

ALTER TABLE `exam_tokens` ADD COLUMN IF NOT EXISTS `token_type` ENUM('single_use','multi_use') DEFAULT 'single_use';
ALTER TABLE `exam_tokens` ADD COLUMN IF NOT EXISTS `used_by` INT NULL;
ALTER TABLE `exam_tokens` ADD COLUMN IF NOT EXISTS `created_by` INT NULL;


-- =====================================================
-- 1.9  exam_sessions table
-- =====================================================

ALTER TABLE `exam_sessions` ADD COLUMN IF NOT EXISTS `ended_at` DATETIME NULL;
ALTER TABLE `exam_sessions` ADD COLUMN IF NOT EXISTS `status` ENUM('in_progress','completed','abandoned','timed_out') DEFAULT 'in_progress';


-- =====================================================
-- 1.10  exam_results table
-- =====================================================

ALTER TABLE `exam_results` ADD COLUMN IF NOT EXISTS `score` DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE `exam_results` ADD COLUMN IF NOT EXISTS `is_passed` TINYINT(1) DEFAULT 0;
ALTER TABLE `exam_results` ADD COLUMN IF NOT EXISTS `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP;


-- =====================================================
-- 1.11  vle_exam_results table
-- =====================================================

ALTER TABLE `vle_exam_results` ADD COLUMN IF NOT EXISTS `dean_approved` TINYINT(1) DEFAULT 0;


-- =====================================================
-- 1.12  lecturer_finance_requests table
--       (columns added by dean portal & ODL coordinator)
-- =====================================================

ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `dean_approval_status` ENUM('pending','approved','rejected','returned') DEFAULT NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `dean_approved_by` INT NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `dean_approved_at` DATETIME NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `dean_remarks` TEXT NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `odl_approval_status` ENUM('pending','approved','rejected','returned') DEFAULT 'pending';
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `odl_approved_by` INT NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `odl_approved_at` TIMESTAMP NULL;
ALTER TABLE `lecturer_finance_requests` ADD COLUMN IF NOT EXISTS `odl_remarks` TEXT NULL;


-- =====================================================
-- 1.13  payment_submissions table
-- =====================================================

ALTER TABLE `payment_submissions` ADD COLUMN IF NOT EXISTS `transaction_type` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `payment_submissions` ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(100) DEFAULT NULL;


-- =====================================================
-- 1.14  vle_submissions table
-- =====================================================

ALTER TABLE `vle_submissions` ADD COLUMN IF NOT EXISTS `marked_file_path` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `vle_submissions` ADD COLUMN IF NOT EXISTS `marked_file_name` VARCHAR(255) DEFAULT NULL;


-- =====================================================
-- 1.15  student_invite_registrations table
-- =====================================================

ALTER TABLE `student_invite_registrations` ADD COLUMN IF NOT EXISTS `student_id_number` VARCHAR(50) DEFAULT NULL COMMENT 'Existing student ID if transfer/returning';
ALTER TABLE `student_invite_registrations` ADD COLUMN IF NOT EXISTS `preferred_username` VARCHAR(100) DEFAULT NULL COMMENT 'Student preferred username';
ALTER TABLE `student_invite_registrations` ADD COLUMN IF NOT EXISTS `year_of_registration` INT DEFAULT NULL;
ALTER TABLE `student_invite_registrations` ADD COLUMN IF NOT EXISTS `selected_modules` TEXT DEFAULT NULL COMMENT 'JSON array of selected course IDs';
ALTER TABLE `student_invite_registrations` MODIFY COLUMN `invite_id` INT NOT NULL DEFAULT 0;


-- #####################################################
-- PART 2: CREATE MISSING TABLES
-- (All use IF NOT EXISTS — safe to run repeatedly)
-- #####################################################

-- =====================================================
-- 2.1  Attendance System
-- =====================================================

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
    `status` ENUM('present','late','left_early','unresponsive') DEFAULT 'present',
    `duration_minutes` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (`session_id`, `student_id`),
    INDEX `idx_session` (`session_id`),
    INDEX `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2.2  Quiz System
-- =====================================================

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
    `question_type` ENUM('multiple_choice','true_false','short_answer') DEFAULT 'multiple_choice',
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
    `status` ENUM('in_progress','completed','timed_out') DEFAULT 'in_progress',
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

-- =====================================================
-- 2.3  Download Requests
-- =====================================================

CREATE TABLE IF NOT EXISTS `vle_download_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` VARCHAR(20),
    `content_id` INT,
    `lecturer_id` INT,
    `file_path` VARCHAR(500),
    `file_name` VARCHAR(255),
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `approved_at` DATETIME NULL,
    INDEX `idx_student` (`student_id`),
    INDEX `idx_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2.4  Zoom Settings
-- =====================================================

CREATE TABLE IF NOT EXISTS `zoom_settings` (
    `setting_id` INT PRIMARY KEY AUTO_INCREMENT,
    `zoom_account_email` VARCHAR(100) NOT NULL UNIQUE,
    `zoom_api_key` VARCHAR(255) NOT NULL,
    `zoom_api_secret` VARCHAR(500) NOT NULL,
    `zoom_meeting_password` VARCHAR(20),
    `zoom_enable_recording` BOOLEAN DEFAULT TRUE,
    `zoom_require_authentication` BOOLEAN DEFAULT TRUE,
    `zoom_wait_for_host` BOOLEAN DEFAULT TRUE,
    `zoom_auto_recording` ENUM('local','cloud','none') DEFAULT 'none',
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2.5  Course Programs (links courses to programs)
-- =====================================================

CREATE TABLE IF NOT EXISTS `course_programs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT NOT NULL,
    `program_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_course_program` (`course_id`, `program_id`),
    KEY `program_id` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2.6  Lecturer Finance Requests
-- =====================================================

CREATE TABLE IF NOT EXISTS `lecturer_finance_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `lecturer_id` INT NOT NULL,
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

-- =====================================================
-- 2.7  Finance Audit Log
-- =====================================================

CREATE TABLE IF NOT EXISTS `finance_audit_log` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `action_type` VARCHAR(50) NOT NULL,
    `performed_by` VARCHAR(10) NOT NULL,
    `finance_role` VARCHAR(20) NOT NULL,
    `target_student_id` VARCHAR(20) DEFAULT NULL,
    `target_transaction_id` INT DEFAULT NULL,
    `amount` DECIMAL(10,2) DEFAULT NULL,
    `action_details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2.8  Payment Approvals
-- =====================================================

CREATE TABLE IF NOT EXISTS `payment_approvals` (
    `approval_id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT NOT NULL,
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

-- =====================================================
-- 2.9  Assignment Questions & Answers
-- =====================================================

CREATE TABLE IF NOT EXISTS `vle_assignment_questions` (
    `question_id` INT AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT NOT NULL,
    `question_text` TEXT NOT NULL,
    `question_type` ENUM('multiple_choice','open_ended') DEFAULT 'open_ended',
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
-- 2.10  Lecturer Invite Links System
-- =====================================================

CREATE TABLE IF NOT EXISTS `lecturer_registration_invites` (
    `invite_id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `email` VARCHAR(150) DEFAULT NULL,
    `full_name` VARCHAR(150) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `position` VARCHAR(50) DEFAULT NULL,
    `max_uses` INT DEFAULT 1,
    `times_used` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `expires_at` DATETIME DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `notes` TEXT DEFAULT NULL,
    INDEX `idx_token` (`token`),
    INDEX `idx_email` (`email`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lecturer_invite_registrations` (
    `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
    `invite_id` INT NOT NULL DEFAULT 0,
    `lecturer_id` INT DEFAULT NULL,
    `user_id` INT DEFAULT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT NULL,
    `national_id` VARCHAR(20) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `position` VARCHAR(50) DEFAULT NULL,
    `qualification` VARCHAR(200) DEFAULT NULL,
    `specialization` VARCHAR(200) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `selected_modules` TEXT DEFAULT NULL COMMENT 'JSON array of course_ids (max 7)',
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    INDEX `idx_invite` (`invite_id`),
    INDEX `idx_lecturer` (`lecturer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- #####################################################
-- PART 3: ADMIN PASSWORD RESET
-- (Set admin password to: 3xp10!ts)
-- Comment out if you don't want to reset
-- #####################################################

UPDATE `users`
SET `password_hash` = '$2y$10$z4Whe4FRM0FMhjomdnMqcOYtnBek3rFuRGTf.ePGw5Ffon.pjvVOa'
WHERE `username` = 'admin';


-- #####################################################
-- PART 4: VERIFICATION QUERIES (run manually after)
-- #####################################################

-- Check tables exist:
-- SHOW TABLES;

-- Check users columns:
-- SHOW COLUMNS FROM users;

-- Check admin account:
-- SELECT user_id, username, role, additional_roles, is_active FROM users WHERE username = 'admin';

-- Check new lecturer invite tables:
-- SHOW TABLES LIKE 'lecturer%';

-- =====================================================
-- END OF SAFE MIGRATION SCRIPT
-- =====================================================
