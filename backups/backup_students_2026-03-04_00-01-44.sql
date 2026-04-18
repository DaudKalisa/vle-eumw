-- VLE Category Backup: Students
-- Generated: 2026-03-04 00:01:44
-- Database: university_portal
-- Category: students

SET FOREIGN_KEY_CHECKS = 0;

-- Table: students
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `student_id` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `program_type` enum('degree','professional','masters','doctorate') DEFAULT 'degree',
  `program` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `address` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `campus` varchar(50) DEFAULT 'Mzuzu Campus',
  `year_of_registration` year(4) DEFAULT NULL,
  `semester` enum('One','Two') DEFAULT 'One',
  `academic_level` varchar(10) DEFAULT '1/1',
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `entry_type` varchar(10) DEFAULT 'NE' COMMENT 'ME=Mature Entry, NE=Normal Entry, ODL=Open Distance Learning, PC=Professional Course',
  `student_type` enum('new_student','continuing') DEFAULT 'new_student',
  `student_status` enum('active','graduated','suspended','withdrawn') DEFAULT 'active',
  `graduation_date` date DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BAC/26/BT/NE/0003', 'Samuel Gondwe', 'samuel.gondwe@student.eumw.ac.mw', '+265 888 001 008', 'BAC', 'degree', 'Bachelor of Accounting', '1', '2026-03-01', '1', NULL, NULL, 'Blantyre', '2026', 'One', '1/1', 'Male', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BAC/26/LL/NE/0002', 'Ruth Phiri', 'ruth.phiri@student.eumw.ac.mw', '+265 888 001 007', 'BAC', 'degree', 'Bachelor of Accounting', '1', '2026-03-01', '1', NULL, NULL, 'Lilongwe', '2026', 'One', '1/1', 'Female', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BAC/26/MZ/NE/0001', 'Joseph Kamanga', 'joseph.kamanga@student.eumw.ac.mw', '+265 888 001 006', 'BAC', 'degree', 'Bachelor of Accounting', '1', '2026-03-01', '1', NULL, NULL, 'Mzuzu', '2026', 'One', '1/1', 'Male', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/BT/NE/0002', 'Ettah Phiri', 'kalisadaud@gmail.com', '+265888343511', '3', 'degree', 'Bachelor of Business Administration', '1', NULL, '1', 'Blantyre', NULL, 'Blantyre Campus', '2026', 'One', '1/1', 'Female', 'HR414146', 'NE', 'continuing', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/BT/NE/0004', 'Daniel Mwanza', 'daniel.mwanza@student.eumw.ac.mw', '+265 888 001 004', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'Blantyre', '2026', 'One', '1/1', 'Male', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/BT/ODL/0001', 'Jonathan Phiri', 'daudkphiri@gmail.com', '+265983177606', '3', 'degree', 'Bachelor of Business Administration', '2', NULL, '1', 'Joburge', NULL, 'Blantyre Campus', '2026', 'One', '2/1', 'Male', 'REDSXMY2', 'ODL', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/LL/NE/0003', 'Mercy Banda', 'mercy.banda@student.eumw.ac.mw', '+265 888 001 003', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'Lilongwe', '2026', 'One', '1/1', 'Female', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/MZ/NE/0001', 'Grace Tembo', 'grace.tembo@student.eumw.ac.mw', '+265 888 001 001', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'Mzuzu', '2026', 'One', '1/1', 'Female', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/MZ/NE/0002', 'Peter Chirwa', 'peter.chirwa@student.eumw.ac.mw', '+265 888 001 002', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'Mzuzu', '2026', 'One', '1/1', 'Male', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/MZ/NE/0006', 'Elizabeth Nkhoma', 'elizabeth.nkhoma@student.eumw.ac.mw', '+265 888 001 009', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'Mzuzu', '2026', 'One', '1/1', 'Female', NULL, 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/ODL/NE/0005', 'Faith Nyirenda', 'faith.nyirenda@student.eumw.ac.mw', '+265 888 001 005', '3', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', '', NULL, 'ODel', '2026', 'One', '1/1', 'Female', '', 'NE', 'new_student', 'active', NULL);
INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `academic_level`, `gender`, `national_id`, `entry_type`, `student_type`, `student_status`, `graduation_date`) VALUES ('BBA/26/ODL/NE/0007', 'Michael Mbewe', 'michael.mbewe@student.eumw.ac.mw', '+265 888 001 010', 'BBA', 'degree', 'Bachelor of Business Administration', '1', '2026-03-01', '1', NULL, NULL, 'ODel', '2026', 'One', '1/1', 'Male', NULL, 'NE', 'new_student', 'active', NULL);

-- Table: student_finances
DROP TABLE IF EXISTS `student_finances`;
CREATE TABLE `student_finances` (
  `finance_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `application_fee_paid` decimal(10,2) DEFAULT 0.00,
  `application_fee_date` date DEFAULT NULL,
  `registration_fee` decimal(10,2) DEFAULT 39500.00,
  `registration_paid` decimal(10,2) DEFAULT 0.00,
  `registration_paid_date` date DEFAULT NULL,
  `expected_tuition` decimal(10,2) DEFAULT 500000.00,
  `expected_total` decimal(10,2) DEFAULT 545000.00,
  `tuition_fee` decimal(10,2) DEFAULT 500000.00,
  `tuition_paid` decimal(10,2) DEFAULT 0.00,
  `installment_1` decimal(10,2) DEFAULT 0.00,
  `installment_1_date` date DEFAULT NULL,
  `installment_2` decimal(10,2) DEFAULT 0.00,
  `installment_2_date` date DEFAULT NULL,
  `installment_3` decimal(10,2) DEFAULT 0.00,
  `installment_3_date` date DEFAULT NULL,
  `installment_4` decimal(10,2) DEFAULT 0.00,
  `installment_4_date` date DEFAULT NULL,
  `total_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 539500.00,
  `payment_percentage` int(11) DEFAULT 0,
  `content_access_weeks` int(11) DEFAULT 0 COMMENT 'Weeks of content student can access',
  `last_payment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`finance_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_payment_percentage` (`payment_percentage`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('2', 'STU003', '0.00', NULL, '39500.00', '39500.00', '2026-01-09', '500000.00', '545000.00', '500000.00', '160500.00', '125000.00', '2026-01-09', '35500.00', NULL, '0.00', NULL, '0.00', NULL, '200000.00', '339500.00', '25', '4', NULL, '2026-01-09 05:16:11', '2026-01-09 07:38:06');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('3', 'BIT/26/MZ/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '539500.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '200000.00', '139500.00', '74', '9', NULL, '2026-01-10 06:32:46', '2026-01-10 06:32:46');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('4', 'BHSM/26/MZ/ME/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '1250000.00', '-705000.00', '229', '52', NULL, '2026-01-10 06:33:20', '2026-01-10 08:44:54');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('5', 'BBA/26/LL/NE/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '539500.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '350000.00', '-10500.00', '102', '52', NULL, '2026-01-10 15:57:53', '2026-01-10 22:13:46');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('23', 'BBA/26/BT/ODL/0001', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '545000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', '545000.00', '0', '0', NULL, '2026-03-01 12:01:03', '2026-03-01 12:01:03');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('24', 'BBA/26/ODL/NE/0005', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '539500.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '250000.00', '39500.00', '93', '13', NULL, '2026-03-01 21:50:05', '2026-03-01 21:50:05');
INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES ('25', 'BBA/26/BT/NE/0002', '0.00', NULL, '39500.00', '0.00', NULL, '500000.00', '535000.00', '500000.00', '0.00', '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', NULL, '0.00', '535000.00', '0', '0', NULL, '2026-03-03 22:48:48', '2026-03-03 22:48:48');

-- Table: student_invite_registrations
DROP TABLE IF EXISTS `student_invite_registrations`;
CREATE TABLE `student_invite_registrations` (
  `registration_id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_id` int(11) NOT NULL DEFAULT 0,
  `student_id` varchar(50) DEFAULT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `preferred_username` varchar(100) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `national_id` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program` varchar(200) DEFAULT NULL,
  `program_type` varchar(50) DEFAULT 'degree',
  `campus` varchar(100) DEFAULT 'Mzuzu Campus',
  `year_of_registration` int(11) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT 1,
  `semester` varchar(10) DEFAULT 'One',
  `entry_type` varchar(10) DEFAULT 'NE',
  `student_type` varchar(30) DEFAULT 'new_student',
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`registration_id`),
  KEY `idx_invite` (`invite_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_invite_registrations` (`registration_id`, `invite_id`, `student_id`, `student_id_number`, `user_id`, `first_name`, `middle_name`, `last_name`, `preferred_username`, `email`, `phone`, `gender`, `national_id`, `address`, `department_id`, `program`, `program_type`, `campus`, `year_of_registration`, `year_of_study`, `semester`, `entry_type`, `student_type`, `status`, `reviewed_by`, `reviewed_at`, `admin_notes`, `registered_at`, `ip_address`) VALUES ('1', '1', 'BBA/26/BT/NE/0002', NULL, '63', 'Ettah', '', 'Phiri', NULL, 'kalisadaud@gmail.com', '+265888343511', 'Female', 'HR414146', 'Blantyre', '3', 'Bachelor of Business Administration', 'degree', 'Blantyre Campus', NULL, '1', 'One', 'NE', 'continuing', 'approved', '32', '2026-03-03 22:48:48', '', '2026-03-03 22:47:43', '127.0.0.1');

-- Table: student_registration_invites
DROP TABLE IF EXISTS `student_registration_invites`;
CREATE TABLE `student_registration_invites` (
  `invite_id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program` varchar(200) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `program_type` varchar(50) DEFAULT 'degree',
  `year_of_study` int(11) DEFAULT 1,
  `semester` varchar(10) DEFAULT 'One',
  `entry_type` varchar(10) DEFAULT 'NE',
  `max_uses` int(11) DEFAULT 1,
  `times_used` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`invite_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_registration_invites` (`invite_id`, `token`, `email`, `full_name`, `department_id`, `program`, `campus`, `program_type`, `year_of_study`, `semester`, `entry_type`, `max_uses`, `times_used`, `is_active`, `expires_at`, `created_by`, `created_at`, `updated_at`, `notes`) VALUES ('3', '5009ff5dcebc015b6a2f862aa55980a0744860fdfbeeca09883bd938cfa4986c', NULL, NULL, NULL, NULL, 'Mzuzu Campus', '0', '1', 'One', 'NE', '1', '0', '1', '2026-04-02 21:59:54', '32', '2026-03-03 22:59:54', '2026-03-03 22:59:54', NULL);

-- Table: student_access_logs
DROP TABLE IF EXISTS `student_access_logs`;
CREATE TABLE `student_access_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `content_type` varchar(50) DEFAULT NULL COMMENT 'login, course_view, assignment, content, exam',
  `content_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_type` (`content_type`),
  KEY `idx_date` (`accessed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
