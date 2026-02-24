-- Database Backup for VLE System
-- Database: university_portal
-- Generated: 2026-01-29 02:31:31
-- Version: 5.0
-- ==========================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ==========================================
-- Table structure for `administrative_staff`
-- ==========================================

DROP TABLE IF EXISTS `administrative_staff`;
CREATE TABLE `administrative_staff` (
  `staff_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `administrative_staff` (1 rows)
-- ==========================================

INSERT INTO `administrative_staff` (`staff_id`, `full_name`, `email`, `phone`, `department`, `position`, `hire_date`, `is_active`) VALUES ('1', 'Admin User', 'admin@university.edu', NULL, 'Administration', 'System Administrator', NULL, '1');

-- ==========================================
-- Table structure for `course_registration_requests`
-- ==========================================

DROP TABLE IF EXISTS `course_registration_requests`;
CREATE TABLE `course_registration_requests` (
  `request_id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `course_id` int NOT NULL,
  `semester` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `academic_year` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `request_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_date` timestamp NULL DEFAULT NULL,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`request_id`),
  KEY `idx_status` (`status`),
  KEY `idx_student` (`student_id`),
  KEY `idx_course` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `course_registration_requests` (15 rows)
-- ==========================================

INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('6', 'BHSM/26/LL/NE/0001', '6', 'Semester 1', '2026/2027', 'approved', '2026-01-10 22:47:26', '4', '2026-01-11 06:54:24', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('7', 'BHSM/26/LL/NE/0001', '9', 'Semester 1', '2026/2027', 'approved', '2026-01-10 22:47:26', '4', '2026-01-11 06:54:32', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('8', 'BHSM/26/LL/NE/0001', '4', 'Semester 1', '2026/2027', 'rejected', '2026-01-10 22:47:26', '4', '2026-01-11 06:55:13', 'Student already enrolled in this course');
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('9', 'BHSM/26/LL/NE/0001', '5', 'Semester 1', '2026/2027', 'rejected', '2026-01-10 22:47:26', '4', '2026-01-11 06:55:01', 'Student already enrolled in this course');
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('10', 'BHSM/26/LL/NE/0001', '7', 'Semester 1', '2026/2027', 'approved', '2026-01-10 22:47:43', '4', '2026-01-11 06:54:16', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('16', 'BCD/26/LL/ME/0001', '6', 'Semester 1', '2026/2027', 'approved', '2026-01-19 12:33:46', '4', '2026-01-19 12:34:26', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('17', 'BCD/26/LL/ME/0001', '9', 'Semester 1', '2026/2027', 'approved', '2026-01-19 12:33:46', '4', '2026-01-19 12:34:35', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('18', 'BCD/26/LL/ME/0001', '4', 'Semester 1', '2026/2027', 'approved', '2026-01-19 12:33:46', '4', '2026-01-19 12:34:44', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('19', 'BBA/26/LL/NE/0001', '6', 'Semester 1', '2026/2027', 'approved', '2026-01-22 14:56:14', '4', '2026-01-22 15:10:23', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('20', 'BBA/26/LL/NE/0001', '9', 'Semester 1', '2026/2027', 'approved', '2026-01-22 14:56:14', '4', '2026-01-22 15:10:23', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('21', 'BBA/26/LL/NE/0001', '4', 'Semester 1', '2026/2027', 'approved', '2026-01-22 14:56:14', '4', '2026-01-22 15:10:23', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('25', 'BBA/26/BT/NE/0002', '6', 'Semester 1', '2026/2027', 'approved', '2026-01-26 23:38:14', '4', '2026-01-26 23:38:50', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('26', 'BBA/26/BT/NE/0002', '4', 'Semester 1', '2026/2027', 'approved', '2026-01-26 23:38:14', '4', '2026-01-26 23:38:50', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('27', 'BBA/26/BT/NE/0002', '5', 'Semester 1', '2026/2027', 'approved', '2026-01-26 23:38:14', '4', '2026-01-26 23:38:50', NULL);
INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES ('28', 'BBA/26/BT/NE/0002', '7', 'Semester 1', '2026/2027', 'approved', '2026-01-26 23:38:14', '4', '2026-01-26 23:38:50', NULL);

-- ==========================================
-- Table structure for `departments`
-- ==========================================

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `department_id` int NOT NULL AUTO_INCREMENT,
  `department_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `department_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `faculty_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `department_code` (`department_code`),
  KEY `idx_faculty_id` (`faculty_id`),
  CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `departments` (15 rows)
-- ==========================================

INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('1', 'BIT', 'Information Technology', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('2', 'BLSCM', 'Logistics and Supply Chain Management', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('3', 'BBA', 'Business Administration', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('4', 'BHRM', 'Human Resource Management (Business Studies)', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('5', 'BHSM', 'Heathy Systems Management', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('6', 'BCD', 'Community Development', '5', '2026-01-08 22:32:02');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('7', 'MBA', 'Postgraduate Business Administration', '5', '2026-01-08 23:07:50');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('8', 'MHRM', 'Postgraduate Human Resource Management', '5', '2026-01-08 23:08:17');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('9', 'DBA', 'Doctorate Studies', '5', '2026-01-08 23:08:38');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('10', 'PHD', 'Doctor of Philosophy in Developmental Studies', '5', '2026-01-08 23:09:29');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('11', 'MDS', 'Postgraduate Developmental Studies', '5', '2026-01-08 23:09:51');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('12', 'DIP', 'Business Management', '5', '2026-01-08 23:10:17');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('13', 'PC', 'Professional Courses (ABMA, BEMERC, ABA, ICAM)', '5', '2026-01-08 23:11:58');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('14', 'PGS', 'Postgraduate Studies', '5', '2026-01-10 23:59:45');
INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES ('15', 'BAC', 'Accounting Department', '5', '2026-01-11 00:02:34');

-- ==========================================
-- Table structure for `faculties`
-- ==========================================

DROP TABLE IF EXISTS `faculties`;
CREATE TABLE `faculties` (
  `faculty_id` int NOT NULL AUTO_INCREMENT,
  `faculty_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `faculty_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `head_of_faculty` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`faculty_id`),
  UNIQUE KEY `faculty_code` (`faculty_code`),
  KEY `idx_faculty_code` (`faculty_code`),
  KEY `idx_faculty_name` (`faculty_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Data for table `faculties` (1 rows)
-- ==========================================

INSERT INTO `faculties` (`faculty_id`, `faculty_code`, `faculty_name`, `head_of_faculty`, `created_at`, `updated_at`) VALUES ('5', 'FCOM', 'Faculty of Commerce', 'Mr. Paul Chipeta', '2026-01-09 00:43:24', '2026-01-09 00:43:24');

-- ==========================================
-- Table structure for `fee_settings`
-- ==========================================

DROP TABLE IF EXISTS `fee_settings`;
CREATE TABLE `fee_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_fee` decimal(10,2) DEFAULT '5500.00',
  `registration_fee` decimal(10,2) DEFAULT '39500.00',
  `tuition_degree` decimal(10,2) DEFAULT '500000.00',
  `tuition_professional` decimal(10,2) DEFAULT '200000.00',
  `tuition_masters` decimal(10,2) DEFAULT '1100000.00',
  `tuition_doctorate` decimal(10,2) DEFAULT '2200000.00',
  `supplementary_exam_fee` decimal(10,2) DEFAULT '50000.00',
  `deferred_exam_fee` decimal(10,2) DEFAULT '50000.00',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `fee_settings` (1 rows)
-- ==========================================

INSERT INTO `fee_settings` (`id`, `application_fee`, `registration_fee`, `tuition_degree`, `tuition_professional`, `tuition_masters`, `tuition_doctorate`, `supplementary_exam_fee`, `deferred_exam_fee`, `updated_at`) VALUES ('1', '5500.00', '35000.00', '500000.00', '200000.00', '1100000.00', '2200000.00', '50000.00', '50000.00', '2026-01-26 20:37:53');

-- ==========================================
-- Table structure for `finance_audit_log`
-- ==========================================

DROP TABLE IF EXISTS `finance_audit_log`;
CREATE TABLE `finance_audit_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'payment_entry, payment_approval, payment_rejection, report_access, etc',
  `performed_by` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Finance user ID',
  `finance_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Role at time of action',
  `target_student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_transaction_id` int DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `action_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_performed_by` (`performed_by`),
  KEY `idx_student` (`target_student_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Table structure for `finance_users`
-- ==========================================

DROP TABLE IF EXISTS `finance_users`;
CREATE TABLE `finance_users` (
  `finance_id` int NOT NULL AUTO_INCREMENT,
  `finance_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g., FIN2024001',
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Finance Department',
  `position` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Finance Officer',
  `gender` enum('Male','Female','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `profile_picture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`finance_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `finance_code` (`finance_code`),
  KEY `idx_email` (`email`),
  KEY `idx_finance_code` (`finance_code`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Data for table `finance_users` (4 rows)
-- ==========================================

INSERT INTO `finance_users` (`finance_id`, `finance_code`, `full_name`, `email`, `phone`, `password`, `department`, `position`, `gender`, `national_id`, `address`, `profile_picture`, `hire_date`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'FIN2026002', 'Maria Phiri', 'mariaphiri@gmail.com', '+265999659452', '$2y$10$B9GycB7iXG6l5owfswmoquKCQRNdDxHeDvDA4vDMcFVcKy5VweDfy', 'Finance Department', 'Assistant Accountant', 'Female', NULL, NULL, NULL, NULL, '1', '2026-01-29 02:11:17', '2026-01-29 02:11:17');
INSERT INTO `finance_users` (`finance_id`, `finance_code`, `full_name`, `email`, `phone`, `password`, `department`, `position`, `gender`, `national_id`, `address`, `profile_picture`, `hire_date`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'FIN2026003', 'Dyson Phiri', 'dyphiri@exploitsmw.com', '+265999659452', '$2y$10$ODRe8RTbG1OvoKqUX3DvsOrTJrSLla4s7UfCFSzn7Wg3qn.eaw6e2', 'Finance Department', 'Accountant', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-29 02:13:17', '2026-01-29 02:13:17');
INSERT INTO `finance_users` (`finance_id`, `finance_code`, `full_name`, `email`, `phone`, `password`, `department`, `position`, `gender`, `national_id`, `address`, `profile_picture`, `hire_date`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'FIN2026004', 'Finance Exploits', 'finance@exploitsmw.com', '', '$2y$10$gzVFA7QCszOXrGAkgKLfsu8LoHgrDATYk2j6Q9VAwGjowKHFbTBAO', 'Finance Department', 'Finance Officer', 'Male', NULL, NULL, NULL, NULL, '1', '2026-01-29 02:13:17', '2026-01-29 02:29:49');
INSERT INTO `finance_users` (`finance_id`, `finance_code`, `full_name`, `email`, `phone`, `password`, `department`, `position`, `gender`, `national_id`, `address`, `profile_picture`, `hire_date`, `is_active`, `created_at`, `updated_at`) VALUES ('6', 'FIN2026218', 'Wellington Phiri', 'wphiri@exploits.com', '', '$2y$10$cQQ3FzuxQmN9jIeKGbsXQe0llnojJ4YFa.xowD2msc6t26/evRDRK', 'Finance Department', 'Finance Officer', 'Male', NULL, NULL, NULL, NULL, '1', '2026-01-29 02:31:37', '2026-01-29 02:31:37');

-- ==========================================
-- Table structure for `lecturer_finance_requests`
-- ==========================================

DROP TABLE IF EXISTS `lecturer_finance_requests`;
CREATE TABLE `lecturer_finance_requests` (
  `request_id` int NOT NULL AUTO_INCREMENT,
  `lecturer_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `month` int NOT NULL,
  `year` int NOT NULL,
  `courses_data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `total_students` int DEFAULT '0',
  `total_modules` int DEFAULT '0',
  `total_assignments_marked` int DEFAULT '0',
  `total_content_uploaded` int DEFAULT '0',
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `hourly_rate` decimal(10,2) DEFAULT '0.00',
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `additional_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('pending','approved','rejected','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `submission_date` datetime DEFAULT NULL,
  `reviewed_date` datetime DEFAULT NULL,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `request_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `response_date` datetime DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_lecturer` (`lecturer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_submission` (`submission_date`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `lecturer_finance_requests` (5 rows)
-- ==========================================

INSERT INTO `lecturer_finance_requests` (`request_id`, `lecturer_id`, `month`, `year`, `courses_data`, `total_students`, `total_modules`, `total_assignments_marked`, `total_content_uploaded`, `total_hours`, `hourly_rate`, `total_amount`, `signature_path`, `additional_notes`, `status`, `submission_date`, `reviewed_date`, `admin_notes`, `request_date`, `response_date`) VALUES ('1', '1', '2', '2025', '[{\"course_id\":5,\"course_name\":\"Business Mathematics I\",\"students\":3,\"assignments\":\"6\",\"content\":2},{\"course_id\":7,\"course_name\":\"Introduction to Business Management\",\"students\":2,\"assignments\":\"0\",\"content\":0},{\"course_id\":8,\"course_name\":\"Micro and Macroeconomics\",\"students\":0,\"assignments\":\"0\",\"content\":0}]', '5', '3', '6', '2', '10.00', '8500.00', '85000.00', 'signature_1_1769025579.png', '', 'paid', '2026-01-21 21:59:39', NULL, NULL, '2026-01-21 21:59:39', '2026-01-25 13:05:14');
INSERT INTO `lecturer_finance_requests` (`request_id`, `lecturer_id`, `month`, `year`, `courses_data`, `total_students`, `total_modules`, `total_assignments_marked`, `total_content_uploaded`, `total_hours`, `hourly_rate`, `total_amount`, `signature_path`, `additional_notes`, `status`, `submission_date`, `reviewed_date`, `admin_notes`, `request_date`, `response_date`) VALUES ('2', '1', '2', '2024', '[{\"course_id\":5,\"course_name\":\"Business Mathematics I\",\"students\":3,\"assignments\":\"6\",\"content\":2},{\"course_id\":7,\"course_name\":\"Introduction to Business Management\",\"students\":2,\"assignments\":\"0\",\"content\":0},{\"course_id\":8,\"course_name\":\"Micro and Macroeconomics\",\"students\":0,\"assignments\":\"0\",\"content\":0}]', '5', '3', '6', '2', '23.00', '8500.00', '195500.00', 'signature_1_1769026359.png', '', 'paid', '2026-01-21 22:12:39', NULL, NULL, '2026-01-21 22:12:39', '2026-01-21 22:43:11');
INSERT INTO `lecturer_finance_requests` (`request_id`, `lecturer_id`, `month`, `year`, `courses_data`, `total_students`, `total_modules`, `total_assignments_marked`, `total_content_uploaded`, `total_hours`, `hourly_rate`, `total_amount`, `signature_path`, `additional_notes`, `status`, `submission_date`, `reviewed_date`, `admin_notes`, `request_date`, `response_date`) VALUES ('3', '1', '2', '2024', '[{\"course_id\":5,\"course_name\":\"Business Mathematics I\",\"students\":3,\"assignments\":\"6\",\"content\":2},{\"course_id\":7,\"course_name\":\"Introduction to Business Management\",\"students\":2,\"assignments\":\"0\",\"content\":0},{\"course_id\":8,\"course_name\":\"Micro and Macroeconomics\",\"students\":0,\"assignments\":\"0\",\"content\":0}]', '5', '3', '6', '2', '63.00', '8500.00', '535500.00', 'signature_1_1769026616.png', '', 'paid', '2026-01-21 22:16:56', NULL, NULL, '2026-01-21 22:16:56', '2026-01-21 22:41:25');
INSERT INTO `lecturer_finance_requests` (`request_id`, `lecturer_id`, `month`, `year`, `courses_data`, `total_students`, `total_modules`, `total_assignments_marked`, `total_content_uploaded`, `total_hours`, `hourly_rate`, `total_amount`, `signature_path`, `additional_notes`, `status`, `submission_date`, `reviewed_date`, `admin_notes`, `request_date`, `response_date`) VALUES ('4', '1', '2', '2025', '[{\"course_id\":5,\"course_name\":\"Business Mathematics I\",\"students\":3,\"assignments\":\"6\",\"content\":2},{\"course_id\":8,\"course_name\":\"Micro and Macroeconomics\",\"students\":0,\"assignments\":\"0\",\"content\":0}]', '3', '2', '6', '2', '22.00', '8500.00', '187000.00', 'signature_1_1769033415.png', '', 'approved', '2026-01-21 14:10:15', NULL, NULL, '2026-01-22 00:10:15', '2026-01-25 13:04:24');
INSERT INTO `lecturer_finance_requests` (`request_id`, `lecturer_id`, `month`, `year`, `courses_data`, `total_students`, `total_modules`, `total_assignments_marked`, `total_content_uploaded`, `total_hours`, `hourly_rate`, `total_amount`, `signature_path`, `additional_notes`, `status`, `submission_date`, `reviewed_date`, `admin_notes`, `request_date`, `response_date`) VALUES ('5', '1', '1', '2026', '[{\"course_id\":5,\"course_name\":\"Business Mathematics I\",\"students\":3,\"assignments\":\"12\",\"content\":4},{\"course_id\":7,\"course_name\":\"Introduction to Business Management\",\"students\":2,\"assignments\":\"0\",\"content\":3},{\"course_id\":8,\"course_name\":\"Micro and Macroeconomics\",\"students\":0,\"assignments\":\"0\",\"content\":0}]', '5', '3', '12', '7', '100.00', '8500.00', '850000.00', 'signature_1_1769072356.png', '', 'rejected', '2026-01-22 00:59:16', NULL, NULL, '2026-01-22 10:59:16', '2026-01-25 13:04:18');

-- ==========================================
-- Table structure for `lecturers`
-- ==========================================

DROP TABLE IF EXISTS `lecturers`;
CREATE TABLE `lecturers` (
  `lecturer_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `program` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `office` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `profile_picture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gender` enum('Male','Female','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'lecturer' COMMENT 'staff, finance, lecturer',
  `finance_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Finance sub-roles: finance_entry, finance_approval, finance_manager',
  `nrc` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`lecturer_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `lecturers` (6 rows)
-- ==========================================

INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('1', 'Dr. Andrew Chilenga', 'daudkphiri@gmail.com', NULL, '+265999243411', 'Computer Science', NULL, 'Senior Lecturer', NULL, '1', 'Lilongwe Campus', '', 'lecturer_1_1767903615.jpg', 'Male', 'lecturer', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('2', 'Dr. Daud Kalisa', 'daudphiri@live.com', NULL, '0983177606', 'Computer Science', NULL, 'Lecturer', NULL, '1', 'Blantyre', '', 'lecturer_2_1767902784.png', NULL, 'lecturer', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('14', 'Goodman Philimon Mwanza', 'pmwanza@exploitsmw.com', NULL, '+265999342411', NULL, NULL, 'ICT Officer', NULL, '1', NULL, NULL, NULL, 'Male', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('15', 'Barnard Yunusu', 'byunusu@exploitsmw.com', NULL, '+265995879992', NULL, NULL, 'Administrator', NULL, '1', 'Head Office', NULL, NULL, 'Male', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('17', 'Dyson Windo Palira', 'dyson@exploitsmw.com', NULL, '+26599993533', 'BCD', 'Bachelors of Arts in Community Development', 'Senior Lecturer', NULL, '1', 'Lilongwe Campus', 'PhD holder', NULL, 'Female', 'lecturer', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('21', 'jay thawi', 'jaythawi@gmail.com', NULL, '0997592969', 'BIT', 'Information Technology', 'lecturer', NULL, '1', 'Lilongwe Campus', '', 'lecturer_21_1769234815.jpg', 'Male', 'lecturer', NULL, NULL);

-- ==========================================
-- Table structure for `modules`
-- ==========================================

DROP TABLE IF EXISTS `modules`;
CREATE TABLE `modules` (
  `module_id` int NOT NULL AUTO_INCREMENT,
  `module_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `module_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `program_of_study` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `year_of_study` int DEFAULT NULL,
  `semester` enum('One','Two') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `credits` int DEFAULT '3',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`module_id`),
  UNIQUE KEY `module_code` (`module_code`),
  KEY `idx_program` (`program_of_study`),
  KEY `idx_year` (`year_of_study`),
  KEY `idx_semester` (`semester`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `modules` (18 rows)
-- ==========================================

INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('7', 'BBA 1101', 'English for Academic Purposes I', 'Bachelors of Business Administration', '1', 'One', '3', 'English for Academic Purposes I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('8', 'BBA 1103', 'Business Mathematics I', 'Bachelors of Business Administration', '1', 'One', '4', 'Business Mathematics I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('9', 'BAC 1101', 'Financial Accounting I', 'Bachelors of Business Administration', '1', 'One', '3', 'Financial Accounting I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('10', 'BBA 1104', 'Introduction to Business Management', 'Bachelors of Business Administration', '1', 'One', '4', 'Introduction to Business Management', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('11', 'BBA 1105', 'Micro and Macroeconomics', 'Bachelors of Business Administration', '1', 'One', '4', 'Micro and Macroeconomics', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('12', 'BAC 2204', 'Computer Applications', 'Bachelors of Business Administration', '1', 'One', '4', 'Computer Applications', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('13', 'BBA 1201', 'English for Academic Purposes  II', 'Bachelors of Business Administration', '1', 'Two', '3', 'English for Academic Purposes  II', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('14', 'BBA 1202', 'Business Mathematics II', 'Bachelors of Business Administration', '1', 'Two', '4', 'Business Mathematics II', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('15', 'BBA 1203', 'Business Statistics', 'Bachelors of Business Administration', '1', 'Two', '3', 'Business Statistics', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('16', 'BAC 1201', 'Financial Accounting II', 'Bachelors of Business Administration', '1', 'Two', '4', 'Financial Accounting II', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('17', 'BBA 1204', 'Principles of Management', 'Bachelors of Business Administration', '1', 'Two', '4', 'Principles of Management', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('18', 'BBA 2101', 'Marketing Fundamentals I', 'Bachelors of Business Administration', '2', 'One', '4', 'Marketing Fundamentals I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('19', 'BBA 2102', 'Business Values and Ethics', 'Bachelors of Business Administration', '2', 'One', '3', 'Business Values and Ethics', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('20', 'BBA 2103', 'Organisational Behaviour', 'Bachelors of Business Administration', '2', 'One', '4', 'Organisational Behaviour', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('21', 'BBA 2104', 'Business Environment I', 'Bachelors of Business Administration', '2', 'One', '3', 'Business Environment I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('22', 'BBA 2106', 'Communicating in Organization I', 'Bachelors of Business Administration', '2', 'One', '4', 'Communicating in Organization I', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('23', 'BAC 2202', 'Costing and Budgetary Control', 'Bachelors of Business Administration', '2', 'Two', '4', 'Costing and Budgetary Control', '2026-01-09 19:28:01');
INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES ('24', 'BBA 2201', 'Marketing Fundamentals II', 'Bachelors of Business Administration', '2', 'Two', '4', 'Marketing Fundamentals II', '2026-01-09 19:28:01');

-- ==========================================
-- Table structure for `payment_approvals`
-- ==========================================

DROP TABLE IF EXISTS `payment_approvals`;
CREATE TABLE `payment_approvals` (
  `approval_id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entered_by` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Finance clerk who entered the payment',
  `entered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Finance approver who approved',
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`approval_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_entered_by` (`entered_by`),
  KEY `transaction_id` (`transaction_id`),
  CONSTRAINT `payment_approvals_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Table structure for `payment_submissions`
-- ==========================================

DROP TABLE IF EXISTS `payment_submissions`;
CREATE TABLE `payment_submissions` (
  `submission_id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `proof_of_payment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `submission_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `reviewed_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reviewed_date` timestamp NULL DEFAULT NULL,
  `finance_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`submission_id`),
  KEY `idx_student_status` (`student_id`,`status`),
  KEY `idx_status` (`status`),
  CONSTRAINT `payment_submissions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `payment_submissions` (4 rows)
-- ==========================================

INSERT INTO `payment_submissions` (`submission_id`, `student_id`, `amount`, `payment_reference`, `transaction_date`, `proof_of_payment`, `transaction_type`, `bank_name`, `submission_date`, `status`, `reviewed_by`, `reviewed_date`, `finance_id`, `notes`) VALUES ('3', 'BHSM/26/LL/NE/0001', '60000.00', 'FTRN20013MKBT/BNK', '2026-01-09', 'payment_BHSM_26_LL_NE_0001_1768077935.png', 'Bank Deposit', 'National Bank of Malawi', '2026-01-10 22:45:35', 'approved', '7', '2026-01-10 23:25:10', '16', 'This has been verified Thanks');
INSERT INTO `payment_submissions` (`submission_id`, `student_id`, `amount`, `payment_reference`, `transaction_date`, `proof_of_payment`, `transaction_type`, `bank_name`, `submission_date`, `status`, `reviewed_by`, `reviewed_date`, `finance_id`, `notes`) VALUES ('4', 'BHSM/26/LL/NE/0001', '125000.00', 'FTRN200138KBT/BNK', '2026-01-09', 'payment_BHSM_26_LL_NE_0001_1768080256.png', 'Electronic Transfer', 'National Bank of Malawi', '2026-01-10 23:24:16', 'approved', '7', '2026-01-10 23:25:27', '17', 'verified');
INSERT INTO `payment_submissions` (`submission_id`, `student_id`, `amount`, `payment_reference`, `transaction_date`, `proof_of_payment`, `transaction_type`, `bank_name`, `submission_date`, `status`, `reviewed_by`, `reviewed_date`, `finance_id`, `notes`) VALUES ('7', 'BCD/26/LL/ME/0001', '150000.00', 'gggggghhhhhj', '2026-01-15', 'payment_BCD_26_LL_ME_0001_1768818975.pdf', 'Bank Deposit', 'National Bank of Malawi', '2026-01-19 12:36:15', 'approved', '23', '2026-01-19 12:37:30', '26', '');
INSERT INTO `payment_submissions` (`submission_id`, `student_id`, `amount`, `payment_reference`, `transaction_date`, `proof_of_payment`, `transaction_type`, `bank_name`, `submission_date`, `status`, `reviewed_by`, `reviewed_date`, `finance_id`, `notes`) VALUES ('11', 'BBA/26/BT/NE/0002', '200000.00', 'TRFNME00000', '2026-01-21', 'payment_BBA_26_BT_NE_0002_1769598643.pdf', 'Bank Deposit', 'National Bank of Malawi', '2026-01-28 13:10:43', 'approved', '23', '2026-01-28 20:27:35', '31', '');

-- ==========================================
-- Table structure for `payment_transactions`
-- ==========================================

DROP TABLE IF EXISTS `payment_transactions`;
CREATE TABLE `payment_transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Registration, Installment 1-4',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Cash',
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approval_status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'approved' COMMENT 'Payment approval status',
  `approved_by` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Finance user who approved',
  `approved_at` datetime DEFAULT NULL COMMENT 'When payment was approved',
  PRIMARY KEY (`transaction_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_payment_date` (`payment_date`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Data for table `payment_transactions` (21 rows)
-- ==========================================

INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('4', 'STU003', 'auto_distributed', '200000.00', 'cash', 'TFP10020214', '0000-00-00', '7', 'Auto-distributed: Registration: K39,500, Installment 1: K125,000, Installment 2: K35,500', '2026-01-09 07:38:06', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('6', 'BHSM/26/MZ/ME/0001', 'payment', '250000.00', 'Cash', NULL, '2026-01-02', '', 'Electronic Transfer via National Bank of Malawi - Ref: FTRN20013MKC9/BNK', '2026-01-10 05:49:03', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('7', 'BHSM/26/MZ/ME/0001', 'payment', '539500.00', 'cheque', 'TFP100202156', '2026-01-10', 'bmambo', '', '2026-01-10 06:17:51', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('8', 'BHSM/26/MZ/ME/0001', 'registration_fee', '39500.00', 'bank_transfer', 'TFP10020215jk', '2026-01-10', 'bmambo', '', '2026-01-10 06:19:11', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('9', 'BHSM/26/MZ/ME/0001', 'payment', '250000.00', 'bank_transfer', 'TFP100202124', '2026-01-10', 'bmambo', '', '2026-01-10 06:27:19', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('11', 'BIT/26/MZ/NE/0001', 'installment_1', '500000.00', 'bank_transfer', 'TFP100202166', '2026-01-08', 'bmambo', '', '2026-01-10 06:29:01', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('12', 'BIT/26/MZ/NE/0001', 'payment', '200000.00', 'bank_transfer', 'TFP10020216', '2026-01-06', 'bmambo', '', '2026-01-10 06:32:46', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('13', 'BHSM/26/MZ/ME/0001', 'payment', '1250000.00', 'bank_transfer', 'TFP10020214hy', '2026-01-08', 'bmambo', '', '2026-01-10 06:33:20', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('14', 'BBA/26/LL/NE/0001', 'payment', '150000.00', 'bank_transfer', 'TFP100202162', '2026-01-02', 'bmambo', '', '2026-01-10 15:57:53', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('15', 'BBA/26/LL/NE/0001', 'payment', '200000.00', 'Cash', NULL, '2026-01-10', '', 'Bank Deposit via National Bank of Malawi - Ref: FTRN20013MKOT/BNK', '2026-01-10 22:13:46', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('16', 'BHSM/26/LL/NE/0001', 'payment', '60000.00', 'Cash', NULL, '2026-01-09', '', 'Bank Deposit via National Bank of Malawi - Ref: FTRN20013MKBT/BNK', '2026-01-10 23:25:10', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('17', 'BHSM/26/LL/NE/0001', 'payment', '125000.00', 'Cash', NULL, '2026-01-09', '', 'Electronic Transfer via National Bank of Malawi - Ref: FTRN200138KBT/BNK', '2026-01-10 23:25:27', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('19', 'BCD/26/MZ/NE/0001', 'application', '5500.00', 'bank_deposit', 'TFP10020217', '2026-01-10', 'bmambo', 'Bank: National Bank of Malawi', '2026-01-11 00:52:18', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('20', 'BCD/26/MZ/NE/0001', 'registration_fee', '39500.00', 'bank_transfer', 'TFP10020213', '2026-01-11', 'bmambo', 'Bank: National Bank of Malawi', '2026-01-11 06:32:58', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('21', 'BCD/26/MZ/NE/0001', 'application', '5500.00', 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 06:50:15', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('22', 'BHSM/26/LL/NE/0001', 'application', '5500.00', 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 06:51:18', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('23', 'BHSM/26/LL/NE/0001', 'registration_fee', '39500.00', 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 06:52:30', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('26', 'BCD/26/LL/ME/0001', 'payment', '150000.00', 'Cash', NULL, '2026-01-15', '', 'Bank Deposit via National Bank of Malawi - Ref: gggggghhhhhj', '2026-01-19 12:37:30', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('27', 'BBA/26/LL/ODL/0001', 'registration_fee', '39500.00', 'bank_transfer', 'nyuuuuuuuuuuu', '2026-01-18', 'finance', '', '2026-01-20 21:54:28', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('28', 'BBA/26/LL/ODL/0001', 'payment', '505500.00', 'bank_transfer', 'REWSFDRDTTII', '2026-01-20', 'finance', '', '2026-01-20 21:56:08', 'approved', NULL, NULL);
INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES ('31', 'BBA/26/BT/NE/0002', 'payment', '200000.00', 'Cash', NULL, '2026-01-21', 'Finance Officer', 'Bank Deposit via National Bank of Malawi - Ref: TRFNME00000', '2026-01-28 20:27:35', 'approved', NULL, NULL);

-- ==========================================
-- Table structure for `programs`
-- ==========================================

DROP TABLE IF EXISTS `programs`;
CREATE TABLE `programs` (
  `program_id` int NOT NULL AUTO_INCREMENT,
  `program_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_id` int DEFAULT NULL,
  `program_type` enum('degree','professional','masters','doctorate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'degree',
  `duration_years` int DEFAULT '4',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`program_id`),
  UNIQUE KEY `program_code` (`program_code`),
  KEY `idx_program_code` (`program_code`),
  KEY `idx_department` (`department_id`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Data for table `programs` (9 rows)
-- ==========================================

INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('2', 'BSC-IT', 'Bachelor of Information Technology', '1', 'degree', '4', 'IT program focusing on systems administration and network management', '1', '2026-01-10 23:52:11', '2026-01-11 00:06:41');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('3', 'BBA', 'Bachelor of Business Administration', '3', 'degree', '4', 'Business administration program covering management, accounting, and entrepreneurship', '0', '2026-01-10 23:52:11', '2026-01-23 09:17:39');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('4', 'BAC', 'Bachelor of Accounting', '15', 'degree', '4', 'Accounting program preparing students for professional accounting careers', '1', '2026-01-10 23:52:11', '2026-01-11 00:12:28');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('5', 'BA-EDU', 'Bachelor of Arts Community Development', '6', 'degree', '4', 'Teacher education program for primary and secondary education', '1', '2026-01-10 23:52:11', '2026-01-11 00:00:48');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('7', 'MBA', 'Master of Business Administration', '14', 'masters', '2', 'Postgraduate business program for experienced professionals', '1', '2026-01-10 23:52:11', '2026-01-11 00:04:59');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('8', 'MSC-CS', 'Master of Human Resources Management', '14', 'masters', '2', 'Advanced computer science program with research focus', '1', '2026-01-10 23:52:11', '2026-01-11 00:05:31');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('9', 'PHD-BUS', 'Doctor of Philosophy in Business', '9', 'doctorate', '4', 'Research doctorate in business administration and management', '1', '2026-01-10 23:52:11', '2026-01-11 00:06:06');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('10', 'LSCM', 'Bachelor of Logistics and Supply Chain Management', '12', 'degree', '4', '', '1', '2026-01-11 00:13:52', '2026-01-11 00:13:52');
INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('11', 'HSM', 'Bachelor of Healthy System Management', '5', 'degree', '4', '', '0', '2026-01-11 00:15:02', '2026-01-23 09:17:44');

-- ==========================================
-- Table structure for `semester_courses`
-- ==========================================

DROP TABLE IF EXISTS `semester_courses`;
CREATE TABLE `semester_courses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `semester` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `academic_year` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_course_semester` (`course_id`,`semester`,`academic_year`),
  CONSTRAINT `semester_courses_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `semester_courses` (26 rows)
-- ==========================================

INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('1', '6', 'Semester 1', '2026/2027', '1', '2026-01-10 12:19:56');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('2', '9', 'Semester 1', '2026/2027', '1', '2026-01-10 12:19:56');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('3', '4', 'Semester 1', '2026/2027', '1', '2026-01-10 12:19:56');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('4', '5', 'Semester 1', '2026/2027', '1', '2026-01-10 12:19:56');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('5', '7', 'Semester 1', '2026/2027', '1', '2026-01-10 12:19:56');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('6', '13', 'Semester 2', '2026/2027', '1', '2026-01-10 12:20:44');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('7', '10', 'Semester 2', '2026/2027', '1', '2026-01-10 12:20:44');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('8', '11', 'Semester 2', '2026/2027', '1', '2026-01-10 12:20:44');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('9', '12', 'Semester 2', '2026/2027', '1', '2026-01-10 12:20:44');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('10', '14', 'Semester 2', '2026/2027', '1', '2026-01-10 12:20:44');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('11', '24', 'Semester 1', '2026/2027', '1', '2026-01-10 12:48:48');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('12', '26', 'Semester 1', '2026/2027', '1', '2026-01-10 12:48:48');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('13', '28', 'Semester 1', '2026/2027', '1', '2026-01-10 12:48:48');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('14', '29', 'Semester 1', '2026/2027', '1', '2026-01-10 12:48:48');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('15', '25', 'Semester 1', '2026/2027', '1', '2026-01-10 12:48:48');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('16', '33', 'Semester 2', '2026/2027', '1', '2026-01-10 12:49:22');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('17', '32', 'Semester 2', '2026/2027', '1', '2026-01-10 12:49:22');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('18', '30', 'Semester 2', '2026/2027', '1', '2026-01-10 12:49:22');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('19', '31', 'Semester 2', '2026/2027', '1', '2026-01-10 12:49:22');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('20', '34', 'Semester 2', '2026/2027', '1', '2026-01-10 12:49:22');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('21', '15', 'Semester 1', '2026/2027', '1', '2026-01-10 15:32:19');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('22', '16', 'Semester 1', '2026/2027', '1', '2026-01-10 15:32:19');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('23', '17', 'Semester 1', '2026/2027', '1', '2026-01-10 15:32:19');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('24', '18', 'Semester 1', '2026/2027', '1', '2026-01-10 15:32:19');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('25', '19', 'Semester 1', '2026/2027', '1', '2026-01-10 15:32:19');
INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES ('30', '8', 'Semester 1', '2026/2027', '1', '2026-01-22 10:01:27');

-- ==========================================
-- Table structure for `student_finances`
-- ==========================================

DROP TABLE IF EXISTS `student_finances`;
CREATE TABLE `student_finances` (
  `finance_id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `application_fee_paid` decimal(10,2) DEFAULT '0.00',
  `application_fee_date` date DEFAULT NULL,
  `registration_fee` decimal(10,2) DEFAULT '39500.00',
  `registration_paid` decimal(10,2) DEFAULT '0.00',
  `registration_paid_date` date DEFAULT NULL,
  `expected_tuition` decimal(10,2) DEFAULT '500000.00',
  `expected_total` decimal(10,2) DEFAULT '545000.00',
  `tuition_fee` decimal(10,2) DEFAULT '500000.00',
  `tuition_paid` decimal(10,2) DEFAULT '0.00',
  `installment_1` decimal(10,2) DEFAULT '0.00',
  `installment_1_date` date DEFAULT NULL,
  `installment_2` decimal(10,2) DEFAULT '0.00',
  `installment_2_date` date DEFAULT NULL,
  `installment_3` decimal(10,2) DEFAULT '0.00',
  `installment_3_date` date DEFAULT NULL,
  `installment_4` decimal(10,2) DEFAULT '0.00',
  `installment_4_date` date DEFAULT NULL,
  `total_paid` decimal(10,2) DEFAULT '0.00',
  `balance` decimal(10,2) DEFAULT '539500.00',
  `payment_percentage` int DEFAULT '0',
  `content_access_weeks` int DEFAULT '0' COMMENT 'Weeks of content student can access',
  `last_payment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`finance_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_payment_percentage` (`payment_percentage`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- Data for table `student_finances` (12 rows)
-- ==========================================

INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('2', 'STU003', '0.00', NULL, '39500.00', '39500.00', '2026-01-09', '500000.00', '545000.00', '500000.00', '160500.00', '125000.00', '2026-01-09', '35500.00', NULL, '0.00', NULL, '0.00', NULL, '200000.00', '339500.00', '25', '4', NULL, '2026-01-09 05:16:11', '2026-01-09 07:38:06');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('3', 'BIT/26/MZ/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '539500.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '200000.00', '139500.00', '74', '9', NULL, '2026-01-10 06:32:46', '2026-01-10 06:32:46');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('4', 'BHSM/26/MZ/ME/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '1250000.00', '-705000.00', '229', '52', NULL, '2026-01-10 06:33:20', '2026-01-10 08:44:54');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('5', 'BBA/26/LL/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '539500.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '350000.00', '-10500.00', '102', '52', NULL, '2026-01-10 15:57:53', '2026-01-10 22:13:46');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('6', 'BHSM/26/LL/NE/0001', '5500.00', '2026-01-11', '39500.00', '39500.00', '2026-01-11', '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '230000.00', '315000.00', '42', '4', '2026-01-11', '2026-01-10 23:25:10', '2026-01-11 06:52:30');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('8', 'BCD/26/MZ/NE/0001', '5500.00', '2026-01-10', '39500.00', '39500.00', '2026-01-11', '500000.00', '545000.00', '500000.00', '0.00', '5500.00', '2026-01-11', '0.00', NULL, '0.00', NULL, '0.00', NULL, '50500.00', '494500.00', '9', '0', '2026-01-11', '2026-01-11 00:52:18', '2026-01-11 06:50:15');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('9', 'BBA/26/LL/ODL/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '545000.00', '-505500.00', '193', '52', NULL, '2026-01-12 15:56:44', '2026-01-20 21:56:08');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('11', 'BCD/26/LL/ME/0001', '0.00', NULL, '39500.00', '0.00', NULL, '1100000.00', '1145000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '150000.00', '845000.00', '26', '4', NULL, '2026-01-19 12:31:10', '2026-01-19 12:37:30');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('12', 'BIT/26/LL/ME/0001', '0.00', NULL, '39500.00', '0.00', NULL, '2200000.00', '2245000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', '2245000.00', '0', '0', NULL, '2026-01-22 10:49:13', '2026-01-22 10:49:13');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('13', 'BBA/26/LL/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', '545000.00', '0', '0', NULL, '2026-01-22 14:53:27', '2026-01-22 14:53:27');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('14', 'BBA/26/BT/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', '545000.00', '0', '0', NULL, '2026-01-22 15:09:50', '2026-01-22 15:09:50');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('16', 'BBA/26/BT/NE/0002', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '200000.00', '145000.00', '73', '9', NULL, '2026-01-24 19:11:37', '2026-01-28 20:27:35');

-- ==========================================
-- Table structure for `students`
-- ==========================================

DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `program_type` enum('degree','professional','masters','doctorate') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'degree',
  `program` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `year_of_study` int DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `profile_picture` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `campus` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Mzuzu Campus',
  `year_of_registration` year DEFAULT NULL,
  `semester` enum('One','Two') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'One',
  `gender` enum('Male','Female','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `national_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entry_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'NE' COMMENT 'ME=Mature Entry, NE=Normal Entry, ODL=Open Distance Learning, PC=Professional Course',
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `students` (8 rows)
-- ==========================================

INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BBA/26/BT/NE/0001', 'Desimo Milda Manda', 'dmmand@exploitsmw.com', '+26589855444', '3', 'degree', '0', '1', NULL, '1', 'Blantyre', NULL, 'Blantyre Campus', '2026', 'One', 'Female', 'REFFDFDFF', 'NE');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BBA/26/BT/NE/0002', 'Student Student', 'daudpphiri@yahoo.com', '+27845084661', '', 'degree', '', '2', NULL, '1', 'Chemusa, Near SUMLA butchery, M1 Zalewa Road, Blantyre', 'student_BBA_26_BT_NE_0002_1769647742.png', 'Blantyre Campus', '2026', 'Two', 'Female', 'ADSDFDFRR', 'NE');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BBA/26/LL/NE/0001', 'Malia Daud', 'mdaud@exploitsmw.com', '+265999240411', '3', 'degree', '0', '1', NULL, '1', 'MBAYAYI', NULL, 'Lilongwe Campus', '2026', 'One', 'Male', 'DADRVDCV', 'NE');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BBA/26/LL/ODL/0001', 'Daud Kalisa Phiri', 'daudphiri@yahoo.com', '+26599958656', '4', 'degree', 'Bachelor\'s of Human Resource Management (Business Studies)', '1', NULL, '1', 'Mbayani', NULL, 'Lilongwe Campus', '2026', 'One', 'Male', 'MES123DK', 'ODL');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BCD/26/LL/ME/0001', 'Maria Mwaka Matewere', 'mmat23@gmail.com', '+27845084661', '6', 'masters', '0', '1', NULL, '1', 'Chemusa, Near SUMLA butchery, M1 Zalewa Road, Blantyre', NULL, 'Lilongwe Campus', '2026', 'One', 'Female', 'ADSDFDFRR', 'ME');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BCD/26/MZ/NE/0001', 'Charles Musta Chamama', 'cchamama@exploitsmw.com', '+265999243411', '15', 'degree', 'Bachelor\'s of Accounting', '1', NULL, '1', 'Mbayani', NULL, 'Mzuzu Campus', '2026', 'One', 'Male', 'WE3456789', 'NE');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BHSM/26/LL/NE/0001', 'Linda Chirwa', 'lchirwa@exploitsmw.com', '+26588835662', '5', 'degree', '0', '1', NULL, '1', 'Ndirande', NULL, 'Lilongwe Campus', '2026', 'One', 'Female', 'TEDSMYT', 'NE');
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES ('BIT/26/LL/ME/0001', 'Watipaso Andrew Chiumia', 'watipasolchiumia@gmail.com', '+265994592752', '1', 'doctorate', '0', '3', NULL, '1', 'Lilongwe City', NULL, 'Lilongwe Campus', '2026', 'Two', 'Male', 'PQ2GT7M9G', 'ME');

-- ==========================================
-- Table structure for `university_settings`
-- ==========================================

DROP TABLE IF EXISTS `university_settings`;
CREATE TABLE `university_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `university_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `address_po_box` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_area` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_street` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address_country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `website` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `receipt_footer_text` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `university_settings` (1 rows)
-- ==========================================

INSERT INTO `university_settings` (`id`, `university_name`, `address_po_box`, `address_area`, `address_street`, `address_city`, `address_country`, `phone`, `email`, `website`, `logo_path`, `receipt_footer_text`, `created_at`, `updated_at`) VALUES ('1', 'Exploits University', 'P.O.Box 301752', 'Area 4', 'Maula Streats', 'Lilongwe', 'Malawi', '+265999568251', 'info@exploitsmw.com', 'www.exploitsmw.com', '../uploads/university/logo_1767937933.png', 'Thank you for your payment', '2026-01-09 07:22:56', '2026-01-09 07:52:13');

-- ==========================================
-- Table structure for `users`
-- ==========================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('student','lecturer','staff','hod','dean','finance') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `related_student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `related_lecturer_id` int DEFAULT NULL,
  `related_staff_id` int DEFAULT NULL,
  `related_hod_id` int DEFAULT NULL,
  `related_dean_id` int DEFAULT NULL,
  `related_finance_id` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `theme_preference` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'navy',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `users` (22 rows)
-- ==========================================

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('1', 'lecturer', 'daudkphiri@gmail.com', '$2y$10$4BytfSolFJ0/VuhJkXCESeQLUu0Yhzlt2kmSCtHnMwurXYVeYI42e', 'lecturer', NULL, '1', NULL, NULL, NULL, NULL, '1', '2026-01-06 21:53:51', '2026-01-24 02:19:33', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('3', 'lecturer2', 'daudphiri@live.com', '$2y$10$45uXd0XRyUCkrrBhAgR4cORUkWBydc4XSU3JYKZsHQW43Shj1cwWK', 'lecturer', NULL, '2', NULL, NULL, NULL, NULL, '1', '2026-01-06 22:05:40', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('4', 'admin', 'admin@university.edu', '$2y$10$45uXd0XRyUCkrrBhAgR4cORUkWBydc4XSU3JYKZsHQW43Shj1cwWK', 'staff', NULL, NULL, '1', NULL, NULL, NULL, '1', '2026-01-06 22:05:58', '2026-01-25 11:48:29', 'emerald');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('6', 'dphiri', 'daudphiri@exploitsmw.com', '$2y$10$whlM/69jx2PcAZR3TpfTNefSBICk8CZ1cEymXgBpc3WY4vrI7JfDu', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-09 07:01:45', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('7', 'bmambo', 'bmambo@exploitsmw.com', '$2y$10$mBDO9v1IxC5kIg1EGelGsujJn4kACHARoKZMDIF77ssRDV62HGTO.', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-09 07:03:39', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('9', 'pmwanza', 'pmwanza@exploitsmw.com', '$2y$10$UAirhfBY0K7dh/3/9fdpceabvrVwmsjKMAmffoQqum5Z.sRnM90F6', 'staff', NULL, '14', NULL, NULL, NULL, NULL, '1', '2026-01-09 16:53:00', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('10', 'byunusu', 'byunusu@exploitsmw.com', '$2y$10$hqebecgQVSK13GYkz5k80.HwY83ffptqs9d3sq1TWQbzi4EOZjEFG', 'staff', NULL, '15', NULL, NULL, NULL, NULL, '1', '2026-01-09 16:55:00', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('14', 'mphiri', 'mariaphiri@gmail.com', '$2y$10$SQpDKhR5.1kScMAHK.B/Muu57uQnsQSR11gTKEKkdbBZe31woadzC', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-10 07:51:11', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('16', 'chamama', 'cchamama@exploitsmw.com', '$2y$10$AG9gVRpagwX6G3XhS0jmNOQRve1Uha3ChS0e.3ShvUrk7qUCLAr4.', 'student', 'BCD/26/MZ/NE/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-10 22:39:36', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('17', 'lchirwa', 'lchirwa@exploitsmw.com', '$2y$10$jKL2B4OOD5Naeh.Wo/1k8uiO/5aIhUGMLMzkalWIq8Z4raKEGFdnK', 'student', 'BHSM/26/LL/NE/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-10 22:43:12', '2026-01-24 08:27:47', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('18', 'dpalira', 'dyson@exploitsmw.com', '$2y$10$DOIj7LETsrHVadPu6wqys./iNqdalDZpffRI5Q.nVU.IPUtwJch0G', 'lecturer', NULL, '17', NULL, NULL, NULL, NULL, '1', '2026-01-10 22:55:49', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('19', 'aphiri', 'aaron@exploitsmw.com', '$2y$10$yv5p682NpQ6RB2DCnut5PO4r51Hd.x9maFcEECSGQBFS/K3xlLCI.', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-11 08:21:15', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('20', 'dyphiri', 'dyphiri@exploitsmw.com', '$2y$10$ODRe8RTbG1OvoKqUX3DvsOrTJrSLla4s7UfCFSzn7Wg3qn.eaw6e2', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-11 08:25:52', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('21', 'daud', 'daudphiri@yahoo.com', '$2y$10$2XeF0LPg5i9U0OuOk4kb8.6.mh.GXm9uZsc1HOkbhTsqfE4dqPx1e', 'student', 'BBA/26/LL/ODL/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-12 15:56:44', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('23', 'finance', 'finance@exploitsmw.com', '$2y$10$gzVFA7QCszOXrGAkgKLfsu8LoHgrDATYk2j6Q9VAwGjowKHFbTBAO', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, '1', '2026-01-12 16:09:54', '2026-01-25 13:02:01', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('24', 'mmaterere', 'mmat23@gmail.com', '$2y$10$onYQ9debjlw9ha/guBDZyOo/r1gc3vYqEpPmiiB0fmvfKhlmc2SEq', 'student', 'BCD/26/LL/ME/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-19 12:31:10', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('25', 'watichi', 'watipasolchiumia@gmail.com', '$2y$10$83.kcKu5MxteuGscLNtWROZojV02Xg7YocEpkkC7Rq.fH1jHXkamy', 'student', 'BIT/26/LL/ME/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-22 00:49:13', NULL, 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('26', 'mdaud', 'mdaud@exploitsmw.com', '$2y$10$SyTdCgqWODeyxgh/7Rd0lOlfTypaRoaWfM8qYssW2d/mdsOGfs28W', 'student', 'BBA/26/LL/NE/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-22 04:53:27', '2026-01-22 04:53:48', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('27', 'dmmanda', 'dmmand@exploitsmw.com', '$2y$10$mPtnPMxraqONZto6DsUIpeAWW5tU8my6h9Xys4WjpdrGtpf.aw8CK', 'student', 'BBA/26/BT/NE/0001', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-22 05:09:50', '2026-01-22 05:11:03', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('28', 'lecturer1', 'jaythawi@gmail.com', '$2y$10$UC2vpilM6lGbo24Jmprivu8bZHie3PQkxmry7.SxfgqQXdsA7t0DC', 'lecturer', NULL, '21', NULL, NULL, NULL, NULL, '1', '2026-01-23 21:53:08', '2026-01-23 22:17:00', 'navy');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('30', 'student', 'daudpphiri@yahoo.com', '$2y$10$JQJ4IZalIO82faPAlbbVuuAJMZGAlfoE6mxIa97i5BVsz075fa20e', 'student', 'BBA/26/BT/NE/0002', NULL, NULL, NULL, NULL, NULL, '1', '2026-01-24 09:11:37', '2026-01-25 12:32:40', 'orange');
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`, `theme_preference`) VALUES ('31', 'wphiri', 'wphiri@exploits.com', '$2y$10$cQQ3FzuxQmN9jIeKGbsXQe0llnojJ4YFa.xowD2msc6t26/evRDRK', 'finance', NULL, NULL, NULL, NULL, NULL, '6', '1', '2026-01-29 02:31:37', NULL, 'navy');

-- ==========================================
-- Table structure for `vle_announcements`
-- ==========================================

DROP TABLE IF EXISTS `vle_announcements`;
CREATE TABLE `vle_announcements` (
  `announcement_id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `lecturer_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  KEY `course_id` (`course_id`),
  KEY `lecturer_id` (`lecturer_id`),
  CONSTRAINT `vle_announcements_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`) ON DELETE CASCADE,
  CONSTRAINT `vle_announcements_ibfk_2` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`lecturer_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_announcements` (1 rows)
-- ==========================================

INSERT INTO `vle_announcements` (`announcement_id`, `course_id`, `lecturer_id`, `title`, `content`, `created_date`) VALUES ('2', '5', '1', 'test', 'test', '2026-01-22 10:56:48');

-- ==========================================
-- Table structure for `vle_assignment_answers`
-- ==========================================

DROP TABLE IF EXISTS `vle_assignment_answers`;
CREATE TABLE `vle_assignment_answers` (
  `answer_id` int NOT NULL AUTO_INCREMENT,
  `question_id` int NOT NULL,
  `assignment_id` int NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `answer_text` text,
  `is_correct` tinyint(1) DEFAULT NULL,
  `submitted_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`answer_id`),
  KEY `question_id` (`question_id`),
  KEY `assignment_id` (`assignment_id`),
  KEY `student_id` (`student_id`)
) ENGINE=MyISAM AUTO_INCREMENT=19 DEFAULT CHARSET=latin1;

-- ==========================================
-- Data for table `vle_assignment_answers` (6 rows)
-- ==========================================

INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('13', '1', '11', 'MBA/26/LL/NE/0001', 'This the correct at of writing assignments', NULL, '2026-01-21 08:48:35');
INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('14', '2', '11', 'MBA/26/LL/NE/0001', 'This is the other way of writing an assignment', NULL, '2026-01-21 08:48:35');
INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('15', '3', '11', 'MBA/26/LL/NE/0001', 'this the very jdjdol', NULL, '2026-01-21 08:48:35');
INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('16', '4', '11', 'MBA/26/LL/NE/0001', 'hdhhhbjjjjjjj', NULL, '2026-01-21 08:48:35');
INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('17', '5', '11', 'MBA/26/LL/NE/0001', 'hdhhhhhhhhdjjjd', NULL, '2026-01-21 08:48:35');
INSERT INTO `vle_assignment_answers` (`answer_id`, `question_id`, `assignment_id`, `student_id`, `answer_text`, `is_correct`, `submitted_date`) VALUES ('18', '6', '11', 'MBA/26/LL/NE/0001', 'conducting research', '1', '2026-01-21 08:48:35');

-- ==========================================
-- Table structure for `vle_assignment_questions`
-- ==========================================

DROP TABLE IF EXISTS `vle_assignment_questions`;
CREATE TABLE `vle_assignment_questions` (
  `question_id` int NOT NULL AUTO_INCREMENT,
  `assignment_id` int NOT NULL,
  `question_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `question_type` enum('multiple_choice','open_ended') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'open_ended',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_required` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`question_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_assignment_questions` (6 rows)
-- ==========================================

INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('1', '11', ' Define research and explain its main purpose in academic and professional contexts. (5 marks)', 'open_ended', '[]', '0');
INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('2', '11', 'What are the key differences between quantitative and qualitative research? Provide at least three distinguishing characteristics for each. (6 marks)', 'open_ended', '[]', '0');
INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('3', '11', 'Describe the eight main steps in the research process in sequential order. (8 marks)', 'open_ended', '[]', '0');
INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('4', '11', 'Explain the difference between basic research and applied research. Give one original example of each from your field of study. (6 marks)', 'open_ended', '[]', '0');
INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('5', '11', 'What is the difference between validity and reliability in research? Why are both important for quality research? (6 marks)', 'open_ended', '[]', '0');
INSERT INTO `vle_assignment_questions` (`question_id`, `assignment_id`, `question_text`, `question_type`, `options`, `is_required`) VALUES ('6', '8', 'Assignment 2', '', '[]', '1');

-- ==========================================
-- Table structure for `vle_assignments`
-- ==========================================

DROP TABLE IF EXISTS `vle_assignments`;
CREATE TABLE `vle_assignments` (
  `assignment_id` int NOT NULL AUTO_INCREMENT,
  `course_id` int DEFAULT NULL,
  `week_number` int DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `assignment_type` enum('formative','summative','mid_sem','final_exam') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'formative',
  `max_score` int DEFAULT '100',
  `passing_score` int DEFAULT '50',
  `due_date` datetime DEFAULT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  KEY `course_id` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_assignments` (4 rows)
-- ==========================================

INSERT INTO `vle_assignments` (`assignment_id`, `course_id`, `week_number`, `title`, `description`, `assignment_type`, `max_score`, `passing_score`, `due_date`, `file_path`, `file_name`, `is_active`, `created_date`) VALUES ('6', '10', '1', 'Knowledge understanding', 'what is understanding', 'formative', '100', '50', '0000-00-00 00:00:00', '1768079753_courses_template.csv', 'courses_template.csv', '1', '2026-01-10 23:15:53');
INSERT INTO `vle_assignments` (`assignment_id`, `course_id`, `week_number`, `title`, `description`, `assignment_type`, `max_score`, `passing_score`, `due_date`, `file_path`, `file_name`, `is_active`, `created_date`) VALUES ('7', '5', '1', 'ASSIGNMENT 1- WEEK 1', 'A concise, accessible introduction to quantitative methods\r\nfor economics and finance students, this textbook con-\r\ntains lots of practical applications to show why maths is\r\nnecessary and relevant to economics, as well as worked\r\nexamples and exercises to help students learn and prepare\r\nfor exams.\r\n Introduces mathematical techniques in the context of\r\nintroductory economics, bridging the gap between the\r\ntwo subjects\r\n Written in a friendly conversational style, but with\r\nprecise presentation of mathematics\r\n Explains applications in detail, enabling students to\r\nlearn how to put mathematics into practice\r\n Encourages students to develop confidence in their\r\nnumeracy skills by solving arithmetical problems\r\nwithout a calculator\r\n Extensive provision of worked examples and exercises\r\nto underpin the reader’s knowledge and learning\r\n‘This outstanding textbook is the by-product of lecture notes writ-\r\nten by a dedicated teacher. Mathematics is carefully exposited for\r\nfirst-year students, using familiar applications drawn from economics\r\nand finance. By working through the problems provided, students\r\ncan overcome any fear they might have of mathematics to make it an\r\nenjoyable companion.’\r\nChris Jones is Associate Professor of Economics at Australian\r\nNational University\r\n‘In this well-written text, mathematical techniques are introduced\r\ntogether with basic economic ideas, underlining the fact that\r\nmathematics should not be treated separately, but is an integral and\r\nessential part of economics. The style is friendly and conversational,\r\nand the mathematical techniques are treated rigorously, with many\r\nclearly presented examples. Dr Asano is adept in pinpointing those\r\nareas that students find difficult, making this a very useful and\r\ncomprehensive text for anyone undertaking the study of economics.’\r\nValerie Haggan-Ozaki, Faculty of Liberal Arts, Sophia University\r\n‘Dr Asano is a renowned teacher, who transformed the course he has\r\ntaught on this subject into a popular, albeit challenging, course that\r\nlaid an excellent foundation for Economics majors. He has brought\r\nthis style to this textbook and students will find it very thorough in its\r\ntreatment of each topic, and find that they will learn by doing as much\r\nas by reading. Students new to economics will find the style easy to\r\nfollow and this textbook will facilitate the teaching of this material for\r\nlecturers too.’\r\nMartin Richardson, Professor of Economics, Australian Nationa', 'formative', '100', '50', '0000-00-00 00:00:00', '1768228099_Grounds for Applying Probability and Non-Probability Sampling using Schreuder, Gregoire, and Weyer (2001)(1).docx', 'Grounds for Applying Probability and Non-Probability Sampling using Schreuder, Gregoire, and Weyer (2001)(1).docx', '1', '2026-01-12 16:28:19');
INSERT INTO `vle_assignments` (`assignment_id`, `course_id`, `week_number`, `title`, `description`, `assignment_type`, `max_score`, `passing_score`, `due_date`, `file_path`, `file_name`, `is_active`, `created_date`) VALUES ('8', '5', '2', 'ASSIGNMENT 2- WEEK 2', '', 'formative', '100', '50', '2026-02-20 12:00:00', NULL, NULL, '1', '2026-01-23 00:03:43');
INSERT INTO `vle_assignments` (`assignment_id`, `course_id`, `week_number`, `title`, `description`, `assignment_type`, `max_score`, `passing_score`, `due_date`, `file_path`, `file_name`, `is_active`, `created_date`) VALUES ('9', '5', '1', 'Discrete Math', 'Maths', 'formative', '100', '50', '2026-01-23 11:07:00', '1769159308_istockphoto-1351371432-612x612.jpg', 'istockphoto-1351371432-612x612.jpg', '1', '2026-01-23 01:08:29');

-- ==========================================
-- Table structure for `vle_attendance`
-- ==========================================

DROP TABLE IF EXISTS `vle_attendance`;
CREATE TABLE `vle_attendance` (
  `attendance_id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `course_id` int NOT NULL,
  `student_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attended` tinyint(1) DEFAULT '1',
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attendance_id`),
  KEY `session_id` (`session_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `vle_attendance_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `vle_class_sessions` (`session_id`),
  CONSTRAINT `vle_attendance_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- Data for table `vle_attendance` (3 rows)
-- ==========================================

INSERT INTO `vle_attendance` (`attendance_id`, `session_id`, `course_id`, `student_id`, `attended`, `timestamp`) VALUES ('1', '4', '7', 'BBA/26/BT/NE/0002', '1', '2026-01-27 02:01:21');
INSERT INTO `vle_attendance` (`attendance_id`, `session_id`, `course_id`, `student_id`, `attended`, `timestamp`) VALUES ('2', '11', '7', 'BBA/26/BT/NE/0002', '1', '2026-01-27 02:34:31');
INSERT INTO `vle_attendance` (`attendance_id`, `session_id`, `course_id`, `student_id`, `attended`, `timestamp`) VALUES ('3', '15', '7', 'BBA/26/BT/NE/0002', '1', '2026-01-27 02:42:35');

-- ==========================================
-- Table structure for `vle_class_sessions`
-- ==========================================

DROP TABLE IF EXISTS `vle_class_sessions`;
CREATE TABLE `vle_class_sessions` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `lecturer_id` int NOT NULL,
  `session_date` date NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `is_completed` tinyint(1) DEFAULT '1',
  `session_code` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `session_code` (`session_code`),
  KEY `course_id` (`course_id`),
  KEY `lecturer_id` (`lecturer_id`),
  CONSTRAINT `vle_class_sessions_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`),
  CONSTRAINT `vle_class_sessions_ibfk_2` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`lecturer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- Data for table `vle_class_sessions` (15 rows)
-- ==========================================

INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('1', '5', '1', '2026-01-27', 'TThe introduction', '0', '9546b18d973627f2', '2026-01-27 01:50:15');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('2', '5', '1', '2026-01-27', 'TThe introduction', '0', '10826190e9cab3aa', '2026-01-27 01:52:26');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('3', '5', '1', '2026-01-27', 'TThe introduction', '0', 'aa1b6037bdbb8734', '2026-01-27 01:53:43');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('4', '7', '1', '2026-01-27', 'hh', '0', '4896abe949b13eda', '2026-01-27 01:54:20');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('5', '7', '1', '2026-01-27', 'The Wind', '0', '97334c4528be4557', '2026-01-27 02:17:34');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('6', '7', '1', '2026-01-27', 'The Wind', '0', '20015a34394a1366', '2026-01-27 02:18:01');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('7', '7', '1', '2026-01-27', 'hhkk', '0', 'a102287513597b27', '2026-01-27 02:27:47');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('8', '5', '1', '2026-01-27', 'second', '0', 'b3aa553284c56ca5', '2026-01-27 02:29:17');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('9', '5', '1', '2026-01-27', 'jjh', '0', '6ec1f672e0f5e1af', '2026-01-27 02:31:15');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('10', '5', '1', '2026-01-27', '', '0', 'd0b4623e953d9ad6', '2026-01-27 02:31:34');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('11', '7', '1', '2026-01-27', 'Busines Manageme 1', '0', 'ad3740e3192987f7', '2026-01-27 02:34:08');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('12', '7', '1', '2026-01-27', '', '0', 'b675fd03504e4147', '2026-01-27 02:34:57');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('13', '7', '1', '2026-01-27', '', '0', '074160b53f629b29', '2026-01-27 02:35:42');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('14', '5', '1', '2026-01-27', '', '0', '57642f46cd1bf360', '2026-01-27 02:39:14');
INSERT INTO `vle_class_sessions` (`session_id`, `course_id`, `lecturer_id`, `session_date`, `topic`, `is_completed`, `session_code`, `created_at`) VALUES ('15', '7', '1', '2026-01-27', '', '0', '7c1b1048b57b5140', '2026-01-27 02:42:00');

-- ==========================================
-- Table structure for `vle_courses`
-- ==========================================

DROP TABLE IF EXISTS `vle_courses`;
CREATE TABLE `vle_courses` (
  `course_id` int NOT NULL AUTO_INCREMENT,
  `course_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `course_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `lecturer_id` int DEFAULT NULL,
  `program_of_study` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `year_of_study` int DEFAULT NULL,
  `total_weeks` int DEFAULT '16',
  `is_active` tinyint(1) DEFAULT '1',
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `course_code` (`course_code`),
  KEY `lecturer_id` (`lecturer_id`),
  CONSTRAINT `vle_courses_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`lecturer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_courses` (44 rows)
-- ==========================================

INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('4', 'BBA 1101', 'English for Academic Purposes I', 'English for Academic Purposes I', '2', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('5', 'BBA 1103', 'Business Mathematics I', 'Business Mathematics I', '1', 'Bachelors of Business Administration', '1', '18', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('6', 'BAC 1101', 'Financial Accounting I', 'Financial Accounting I', '2', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('7', 'BBA 1104', 'Introduction to Business Management', 'Introduction to Business Management', '1', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('8', 'BBA 1105', 'Micro and Macroeconomics', 'Micro and Macroeconomics', '1', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('9', 'BAC 2204', 'Computer Applications', 'Computer Applications', NULL, 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('10', 'BBA 1201', 'English for Academic Purposes  II', 'English for Academic Purposes  II', '17', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('11', 'BBA 1202', 'Business Mathematics II', 'Business Mathematics II', '21', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('12', 'BBA 1203', 'Business Statistics', 'Business Statistics', NULL, 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('13', 'BAC 1201', 'Financial Accounting II', 'Financial Accounting II', '17', 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('14', 'BBA 1204', 'Principles of Management', 'Principles of Management', NULL, 'Bachelors of Business Administration', '1', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('15', 'BBA 2101', 'Marketing Fundamentals I', 'Marketing Fundamentals I', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('16', 'BBA 2102', 'Business Values and Ethics', 'Business Values and Ethics', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('17', 'BBA 2103', 'Organisational Behaviour', 'Organisational Behaviour', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('18', 'BBA 2104', 'Business Environment I', 'Business Environment I', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('19', 'BBA 2106', 'Communicating in Organization I', 'Communicating in Organization I', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('20', 'BAC 2202', 'Costing and Budgetary Control', 'Costing and Budgetary Control', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('21', 'BBA 2201', 'Marketing Fundamentals II', 'Marketing Fundamentals II', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('22', 'BBA 2203', 'Business Environment II', 'Business Environment II', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('23', 'BBA 2204', 'Communicating in Organizaton II', 'Communicating in Organizaton II', NULL, 'Bachelors of Business Administration', '2', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('24', 'BBA 3101', 'Managing Organizations I', 'Managing Organizations I', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('25', 'BBA 3106', 'Business to Business Marketing', 'Business to Business Marketing', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('26', 'BBA 3102', 'Management Information Systems', 'Management Information Systems', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('27', 'BBA3101', 'Company Law 1', 'Company Law 1', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('28', 'BBA 3103', 'Brands Management', 'Brands Management', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('29', 'BBA 3105', 'Entrepreneurship and Small Business Management', 'Entrepreneurship and Small Business Management', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('30', 'BBA 3202', 'Research Methods', 'Research Methods', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('31', 'BBA 3203', 'E-Business Strategy', 'E-Business Strategy', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('32', 'BBA 3201', 'Managing in Organisation II', 'Managing in Organisation II', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('33', 'BAC 3203', 'Financial Management', 'Financial Management', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('34', 'BBA 3205', 'Company Law II', 'Company Law II', NULL, 'Bachelors of Business Administration', '3', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('35', 'BAC 4103', 'Risk Management', 'Risk Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('36', 'BAC 3101', 'Corporate Governance and Ethics', 'Corporate Governance and Ethics', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('37', 'BBA 4104', 'Organisational Leadership', 'Organisational Leadership', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('38', 'BBA 4105', 'Managerial Economics', 'Managerial Economics', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('39', 'BBA  4106', 'Operations and Production Management', 'Operations and Production Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('40', 'BBA 4102', 'Corporate Strategy and Planning', 'Corporate Strategy and Planning', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('41', 'BBA 4103', 'Service Management', 'Service Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('42', 'BBA 4201', 'production and Total Quality Management', 'production and Total Quality Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('43', 'BBA 4202', 'Project Management', 'Project Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('44', 'BBA 4203', 'International Business', 'International Business', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('45', 'BBA 4101', 'Contemporary Issues in Marketing', 'Contemporary Issues in Marketing', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('46', 'BBA 4205', 'Change Management', 'Change Management', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');
INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES ('47', 'BBA 4206', 'Dissertation', 'Dissertation', NULL, 'Bachelors of Business Administration', '4', '16', '1', '2026-01-10 09:13:05');

-- ==========================================
-- Table structure for `vle_enrollments`
-- ==========================================

DROP TABLE IF EXISTS `vle_enrollments`;
CREATE TABLE `vle_enrollments` (
  `enrollment_id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `course_id` int DEFAULT NULL,
  `enrollment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `current_week` int DEFAULT '1',
  `is_completed` tinyint(1) DEFAULT '0',
  `completion_date` datetime DEFAULT NULL,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `student_id` (`student_id`,`course_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `vle_enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `vle_enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_enrollments` (24 rows)
-- ==========================================

INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('12', 'BCD/26/MZ/NE/0001', '4', '2026-01-10 22:48:58', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('13', 'BHSM/26/LL/NE/0001', '4', '2026-01-10 22:48:59', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('14', 'BCD/26/MZ/NE/0001', '5', '2026-01-10 22:49:16', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('15', 'BHSM/26/LL/NE/0001', '5', '2026-01-10 22:49:16', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('16', 'BCD/26/MZ/NE/0001', '10', '2026-01-10 23:16:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('17', 'BHSM/26/LL/NE/0001', '10', '2026-01-10 23:16:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('18', 'BHSM/26/LL/NE/0001', '7', '2026-01-11 06:54:16', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('19', 'BHSM/26/LL/NE/0001', '6', '2026-01-11 06:54:24', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('20', 'BHSM/26/LL/NE/0001', '9', '2026-01-11 06:54:32', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('26', 'BCD/26/LL/ME/0001', '6', '2026-01-19 12:34:26', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('27', 'BCD/26/LL/ME/0001', '9', '2026-01-19 12:34:35', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('28', 'BCD/26/LL/ME/0001', '4', '2026-01-19 12:34:44', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('29', 'BBA/26/LL/ODL/0001', '4', '2026-01-22 02:23:18', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('30', 'BBA/26/LL/ODL/0001', '5', '2026-01-22 02:26:02', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('31', 'BCD/26/LL/ME/0001', '5', '2026-01-22 02:26:02', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('32', 'BBA/26/LL/NE/0001', '6', '2026-01-22 05:10:23', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('33', 'BBA/26/LL/NE/0001', '9', '2026-01-22 05:10:23', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('34', 'BBA/26/LL/NE/0001', '4', '2026-01-22 05:10:23', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('37', 'BBA/26/BT/NE/0002', '6', '2026-01-26 23:38:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('38', 'BBA/26/BT/NE/0002', '4', '2026-01-26 23:38:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('39', 'BBA/26/BT/NE/0002', '5', '2026-01-26 23:38:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('40', 'BBA/26/BT/NE/0002', '7', '2026-01-26 23:38:50', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('41', 'BBA/26/BT/NE/0002', '18', '2026-01-28 22:33:15', '1', '0', NULL);
INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES ('42', 'BBA/26/BT/NE/0002', '9', '2026-01-28 22:33:38', '1', '0', NULL);

-- ==========================================
-- Table structure for `vle_forum_posts`
-- ==========================================

DROP TABLE IF EXISTS `vle_forum_posts`;
CREATE TABLE `vle_forum_posts` (
  `post_id` int NOT NULL AUTO_INCREMENT,
  `forum_id` int DEFAULT NULL,
  `parent_post_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `post_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_pinned` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`post_id`),
  KEY `forum_id` (`forum_id`),
  KEY `parent_post_id` (`parent_post_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `vle_forum_posts_ibfk_1` FOREIGN KEY (`forum_id`) REFERENCES `vle_forums` (`forum_id`),
  CONSTRAINT `vle_forum_posts_ibfk_2` FOREIGN KEY (`parent_post_id`) REFERENCES `vle_forum_posts` (`post_id`),
  CONSTRAINT `vle_forum_posts_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_forum_posts` (2 rows)
-- ==========================================

INSERT INTO `vle_forum_posts` (`post_id`, `forum_id`, `parent_post_id`, `user_id`, `title`, `content`, `post_date`, `is_pinned`) VALUES ('1', '3', NULL, '1', 'maths are hard', 'test', '2026-01-22 00:52:40', '0');
INSERT INTO `vle_forum_posts` (`post_id`, `forum_id`, `parent_post_id`, `user_id`, `title`, `content`, `post_date`, `is_pinned`) VALUES ('2', '3', '1', '1', '', 'what?', '2026-01-22 00:52:53', '0');

-- ==========================================
-- Table structure for `vle_forums`
-- ==========================================

DROP TABLE IF EXISTS `vle_forums`;
CREATE TABLE `vle_forums` (
  `forum_id` int NOT NULL AUTO_INCREMENT,
  `course_id` int DEFAULT NULL,
  `week_number` int DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`forum_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `vle_forums_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_forums` (1 rows)
-- ==========================================

INSERT INTO `vle_forums` (`forum_id`, `course_id`, `week_number`, `title`, `description`, `is_active`, `created_date`) VALUES ('3', '5', '7', 'Forum test', '', '1', '2026-01-22 00:51:26');

-- ==========================================
-- Table structure for `vle_grades`
-- ==========================================

DROP TABLE IF EXISTS `vle_grades`;
CREATE TABLE `vle_grades` (
  `grade_id` int NOT NULL AUTO_INCREMENT,
  `enrollment_id` int DEFAULT NULL,
  `assignment_id` int DEFAULT NULL,
  `grade_type` enum('formative','summative','mid_sem','final_exam','overall') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `max_score` decimal(5,2) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `grade_letter` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `graded_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`grade_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `assignment_id` (`assignment_id`),
  CONSTRAINT `vle_grades_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `vle_enrollments` (`enrollment_id`),
  CONSTRAINT `vle_grades_ibfk_2` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Table structure for `vle_messages`
-- ==========================================

DROP TABLE IF EXISTS `vle_messages`;
CREATE TABLE `vle_messages` (
  `message_id` int NOT NULL AUTO_INCREMENT,
  `sender_type` enum('student','lecturer') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sender_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `recipient_type` enum('student','lecturer') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `recipient_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `sent_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `read_date` datetime DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `recipient_type` (`recipient_type`,`recipient_id`),
  KEY `sender_type` (`sender_type`,`sender_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_messages` (8 rows)
-- ==========================================

INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('1', 'student', 'STU002', 'lecturer', '1', 'Application to open week 2 assignment', 'please open week 2 assignment', '0', '2026-01-08 20:54:06', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('2', 'lecturer', '1', 'student', 'STU002', 'Re: Application to open week 2 assignment', 'please write it is open', '0', '2026-01-08 20:57:50', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('3', 'student', 'STU002', 'lecturer', '1', 'Re: Re: Application to open week 2 assignment', 'Thanks so much', '0', '2026-01-08 20:58:22', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('4', 'student', 'BBA/26/BT/ODL/0001', 'lecturer', '1', 'test', 'test', '0', '2026-01-22 01:22:32', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('5', 'student', 'BBA/26/BT/ODL/0001', 'lecturer', '1', 'remark', 'check my grades', '0', '2026-01-23 09:06:22', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('6', 'lecturer', '1', 'student', 'BBA/26/BT/ODL/0001', 'remark', 'noted', '0', '2026-01-23 09:08:43', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('7', 'student', 'BBA/26/BT/ODL/0001', 'lecturer', '1', 'remark', 'thanks', '0', '2026-01-23 21:39:56', NULL);
INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES ('8', 'student', 'BBA/26/BT/NE/0002', 'student', 'BBA/26/LL/NE/0001', 'THE PEOPLE OF', 'The people', '0', '2026-01-28 13:43:50', NULL);

-- ==========================================
-- Table structure for `vle_progress`
-- ==========================================

DROP TABLE IF EXISTS `vle_progress`;
CREATE TABLE `vle_progress` (
  `progress_id` int NOT NULL AUTO_INCREMENT,
  `enrollment_id` int DEFAULT NULL,
  `week_number` int DEFAULT NULL,
  `content_id` int DEFAULT NULL,
  `assignment_id` int DEFAULT NULL,
  `progress_type` enum('content_viewed','assignment_completed','week_completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `completion_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`progress_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `content_id` (`content_id`),
  KEY `assignment_id` (`assignment_id`),
  CONSTRAINT `vle_progress_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `vle_enrollments` (`enrollment_id`),
  CONSTRAINT `vle_progress_ibfk_2` FOREIGN KEY (`content_id`) REFERENCES `vle_weekly_content` (`content_id`),
  CONSTRAINT `vle_progress_ibfk_3` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Table structure for `vle_submissions`
-- ==========================================

DROP TABLE IF EXISTS `vle_submissions`;
CREATE TABLE `vle_submissions` (
  `submission_id` int NOT NULL AUTO_INCREMENT,
  `assignment_id` int DEFAULT NULL,
  `student_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `submission_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `text_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `score` decimal(5,2) DEFAULT NULL,
  `feedback` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `marked_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `marked_file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `marked_file_notified` tinyint(1) NOT NULL DEFAULT '0',
  `graded_by` int DEFAULT NULL,
  `graded_date` datetime DEFAULT NULL,
  `status` enum('submitted','graded','late') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'submitted',
  PRIMARY KEY (`submission_id`),
  UNIQUE KEY `assignment_id` (`assignment_id`,`student_id`),
  KEY `student_id` (`student_id`),
  KEY `graded_by` (`graded_by`),
  CONSTRAINT `vle_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`),
  CONSTRAINT `vle_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `vle_submissions_ibfk_3` FOREIGN KEY (`graded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_submissions` (1 rows)
-- ==========================================

INSERT INTO `vle_submissions` (`submission_id`, `assignment_id`, `student_id`, `submission_date`, `file_path`, `file_name`, `text_content`, `score`, `feedback`, `marked_file_path`, `marked_file_name`, `marked_file_notified`, `graded_by`, `graded_date`, `status`) VALUES ('6', '7', 'BBA/26/BT/NE/0002', '2026-01-28 21:50:08', '1769629808_BBA/26/BT/NE/0002/Brief and Guidelines for Assessment 3-2.pdf', 'Brief and Guidelines for Assessment 3-2.pdf', '<p>THe asss</p>', NULL, NULL, NULL, NULL, '0', NULL, NULL, 'submitted');

-- ==========================================
-- Table structure for `vle_weekly_content`
-- ==========================================

DROP TABLE IF EXISTS `vle_weekly_content`;
CREATE TABLE `vle_weekly_content` (
  `content_id` int NOT NULL AUTO_INCREMENT,
  `course_id` int DEFAULT NULL,
  `week_number` int NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `content_type` enum('presentation','video','document','link','text') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'text',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_mandatory` tinyint(1) DEFAULT '1',
  `sort_order` int DEFAULT '0',
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`content_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `vle_weekly_content_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ==========================================
-- Data for table `vle_weekly_content` (11 rows)
-- ==========================================

INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('8', '10', '16', 'English for Academic Purpose', 'http://localhost/vle_system/lecturer/dashboard.php', 'text', NULL, NULL, '1', '0', '2026-01-10 23:11:41');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('9', '10', '16', 'English for Academic Purpose', 'http://localhost/vle_system/lecturer/dashboard.php', 'text', NULL, NULL, '1', '0', '2026-01-10 23:12:05');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('10', '10', '1', 'TOPIC OVERVIEW WEEK 1: INtroduction to EAP', '<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Contradictions Regarding Interview Advantages</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The claim about flexibility being universally advantageous is contested.</strong> Research by Brinkmann (2014) argues that excessive flexibility in interviews can actually undermine data quality by introducing inconsistency across participants, making systematic comparison difficult. The very flexibility praised as a strength can become a methodological weakness when researchers need standardized data.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The assertion that private settings encourage openness is oversimplified.</strong> Karnieli-Miller et al. (2009) found that one-on-one interview settings can actually <em>inhibit</em> disclosure, particularly when discussing sensitive topics, due to the intensity of the researcher\'s gaze and the lack of peer normalization. Focus groups sometimes elicit more honest responses about sensitive topics because participants hear others share similar experiences first.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The depth advantage is not always realized.</strong> Hollway and Jefferson (2000) demonstrated that interviews often produce socially desirable narratives rather than \"deep\" insights, as participants perform idealized versions of themselves. The assumption that interviews naturally access deeper meaning has been challenged by discourse analysts who show that interview talk is performative rather than revelatory.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Contradictions Regarding Limitations</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The resource-intensive claim ignores comparative context.</strong> While interviews do require time, large-scale survey research with comparable sample sizes often requires <em>more</em> resources for development, pilot testing, distribution, and analysis (Fowler, 2013). The characterization of interviews as uniquely resource-intensive is misleading.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Interviewer bias may be less problematic than survey bias.</strong> Schaeffer and Dykema (2011) found that survey question wording and ordering effects can introduce more systematic bias than interviewer effects, which tend to be idiosyncratic and therefore potentially identifiable. The passage presents interviewer bias as a unique limitation when all methods involve researcher influence.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">The passage presents a somewhat simplified view that doesn\'t fully engage with the methodological complexity and ongoing debates about interview methods in qualitative research.</p><p><br></p>', 'text', NULL, NULL, '1', '0', '2026-01-10 23:14:27');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('13', '7', '8', 'IT', '', 'text', NULL, NULL, '0', '0', '2026-01-21 23:50:06');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('14', '7', '8', 'IT', '', 'text', NULL, NULL, '0', '0', '2026-01-21 23:50:07');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('15', '7', '8', 'IT', '', 'text', NULL, NULL, '0', '0', '2026-01-21 23:50:07');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('16', '5', '13', 'IT', '', 'text', NULL, NULL, '0', '0', '2026-01-22 00:39:47');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('17', '5', '2', 'Into to business', 'DOC', 'document', NULL, NULL, '1', '0', '2026-01-22 00:49:15');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('19', '5', '5', 'Intro to micro economics', '', 'video', NULL, NULL, '0', '0', '2026-01-23 01:15:34');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('20', '5', '1', 'How to make Aouto Reoport', 'https://youtu.be/m9c_kcqasbY', 'video', NULL, NULL, '1', '0', '2026-01-23 15:07:26');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES ('21', '5', '1', 'Critical Analysis of Five Core Research Ethics Principles: A Contemporary Perspective', '<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\" align=\"justify\">1.1 Introduction</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">Research ethics constitutes an essential pillar of academic investigation, guaranteeing that knowledge advancement does not jeopardize the respect, entitlements, and safety of study participants. The development of ethical research frameworks, especially following the revelations from the Nuremberg War Crimes Trial and the Tuskegee Syphilis Study, has resulted in establishing foundational ethical tenets that inform current research conduct (Sleeboom-Faulkner, Simpson, Burgos-Martinez, &amp; McMurray, 2017). These landmark occurrences emphasize the vital necessity of safeguarding human subjects and upholding research integrity. This analysis critically examines five principal ethical guidelines—informed consent, debriefing, participant protection, deception, and confidentiality—investigating their conceptual underpinnings, real-world implementation, obstacles, and ramifications across diverse research settings.</p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\"><br></p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\" align=\"justify\">1.2 Informed Consent as the Cornerstone of Research Ethics</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">Informed consent represents the foundational ethical tenet in research, reflecting commitment to participant self-governance and autonomous choice. This standard mandates that investigators furnish prospective participants with thorough information regarding the investigation and secure their voluntary participation agreement (Ghooi, 2014). Nevertheless, informed consent transcends simply acquiring a signature on a form; it constitutes a continuous dialogue and mutual comprehension between investigator and participant.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">Burr and Gibson (2018) offer an advanced examination of informed consent utilizing Luhmann\'s social systems theory, contending that informed consent ought to be conceptualized as a fluid communication exchange instead of a rigid procedural obligation. Their work illuminates how conventional informed consent methods frequently neglect the intricacy of decision-making processes and the social environments where consent is determined (Bettez, 2015). This viewpoint encourages investigators to progress beyond formulaic methods and participate more authentically with participants.</p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\"><iframe frameborder=\"0\" src=\"//www.youtube.com/embed/gYfRzSMcuig\" width=\"640\" height=\"360\" class=\"note-video-clip\"></iframe><br></p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">The real-world execution of informed consent encounters multiple obstacles. Brehaut et al. (2015) performed a comprehensive assessment of consent documentation and discovered weak associations between consent form components and decision-making quality. Their investigation reveals that numerous consent documents, although technically thorough, do not enable authentic comprehension or high-quality participant decision-making. This discovery prompts crucial inquiries regarding current informed consent effectiveness and indicates necessity for more creative methods to guarantee consent is genuinely \"informed.\"</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">The notion of presumptive consent, although practical under certain circumstances, introduces ethical complications (Farhud et al., 2014). When direct participant consent proves impossible, investigators might consult comparable populations to evaluate potential acceptability. However, this methodology presumes uniformity across populations and might inadequately protect participant self-determination. The friction between methodological requirements and ethical stringency persists as an enduring challenge in research ethics.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\" align=\"justify\">1.3 Debriefing: Completing the Research Engagement</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">Debriefing functions as the ethical complement to informed consent, offering participants information regarding the investigation\'s actual purpose and their contribution following data gathering completion. This standard acknowledges that participants merit understanding their contribution and having concerns or inquiries addressed (Pollock, 2012). Competent debriefing surpasses merely dispensing information; it strives to guarantee participants conclude the research involvement without psychological, mental, or physical damage.</p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\"><iframe frameborder=\"0\" src=\"//www.youtube.com/embed/B1QWOvJEzQw\" width=\"640\" height=\"360\" class=\"note-video-clip\"></iframe><br></p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">The scheduling and caliber of debriefing constitute critical factors. Immediate debriefing enables investigators to address distress or bewilderment while the encounter remains fresh, yet in certain longitudinal investigations, immediate complete revelation might jeopardize subsequent data gathering. This generates an ethical strain between openness and methodological precision. Investigators must thoughtfully reconcile these contradictory requirements, consistently prioritizing participant welfare (Kanyangale, 2019).</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">Wolff-Michael and Hella (2018) address contemporary viewpoints on research ethics in qualitative investigation, highlighting that debriefing must be culturally responsive and contextually suitable. In cross-cultural research, debriefing methods that are customary in Western settings might be unsuitable or potentially damaging in alternative cultural contexts. Investigators must modify their debriefing protocols to recognize cultural standards, authority relationships, and communication patterns (Kanyangale, 2019).</p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\"><iframe frameborder=\"0\" src=\"//www.youtube.com/embed/04twHU39hfo\" width=\"640\" height=\"360\" class=\"note-video-clip\"></iframe><br></p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\" align=\"justify\">The effectiveness of debriefing as a harm-mitigation strategy has been questioned particularly in cases involving deception or distressing stimuli exposure (Eungoo &amp; Hwang, 2023). Although debriefing can offer clarification and support, it might not completely reverse negative effects experienced during investigation. This restriction highlights the importance of comprehensive risk assessment and minimization during research planning rather than relying solely on post-study interventions.</p><p align=\"justify\"><br></p>', 'text', NULL, NULL, '1', '0', '2026-01-23 21:40:36');

SET FOREIGN_KEY_CHECKS=1;

-- End of backup
