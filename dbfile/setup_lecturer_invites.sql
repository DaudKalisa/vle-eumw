-- =====================================================
-- Lecturer Registration Invite Links - Manual SQL Setup
-- Run in phpMyAdmin SQL tab
-- Generated: 2026-03-05
-- =====================================================

-- 1. Invite tokens table (admin creates these)
CREATE TABLE IF NOT EXISTS `lecturer_registration_invites` (
    `invite_id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `email` VARCHAR(150) DEFAULT NULL COMMENT 'Optional: pre-fill email if sent to specific lecturer',
    `full_name` VARCHAR(150) DEFAULT NULL COMMENT 'Optional: pre-fill name',
    `department` VARCHAR(100) DEFAULT NULL COMMENT 'Optional: pre-assign department',
    `position` VARCHAR(50) DEFAULT NULL COMMENT 'e.g. Lecturer, Senior Lecturer',
    `max_uses` INT DEFAULT 1 COMMENT '1 = single use, >1 = multi-use link',
    `times_used` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `expires_at` DATETIME DEFAULT NULL COMMENT 'NULL = never expires',
    `created_by` INT NOT NULL COMMENT 'FK to users.user_id (admin who created)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `notes` TEXT DEFAULT NULL COMMENT 'Admin notes about this invite',
    INDEX `idx_token` (`token`),
    INDEX `idx_email` (`email`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Registration records (lecturer fills form, waits for approval)
CREATE TABLE IF NOT EXISTS `lecturer_invite_registrations` (
    `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
    `invite_id` INT NOT NULL DEFAULT 0 COMMENT '0 = general registration (no invite)',
    `lecturer_id` INT DEFAULT NULL COMMENT 'Set on approval - FK to lecturers',
    `user_id` INT DEFAULT NULL COMMENT 'The user_id created (set on approval)',
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(30) DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT NULL,
    `national_id` VARCHAR(20) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `position` VARCHAR(50) DEFAULT NULL,
    `qualification` VARCHAR(200) DEFAULT NULL COMMENT 'e.g. PhD, Masters, etc.',
    `specialization` VARCHAR(200) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `selected_modules` TEXT DEFAULT NULL COMMENT 'JSON array of selected course_ids (max 7)',
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `reviewed_by` INT DEFAULT NULL COMMENT 'Admin who approved/rejected',
    `reviewed_at` DATETIME DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    INDEX `idx_invite` (`invite_id`),
    INDEX `idx_lecturer` (`lecturer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- VERIFY: Run after to confirm
-- =====================================================
-- SHOW TABLES LIKE 'lecturer%';
-- DESCRIBE lecturer_registration_invites;
-- DESCRIBE lecturer_invite_registrations;

-- =====================================================
-- END
-- =====================================================
