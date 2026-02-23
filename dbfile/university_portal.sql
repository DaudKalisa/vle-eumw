-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 11, 2026 at 07:55 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `university_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `administrative_staff`
--

CREATE TABLE `administrative_staff` (
  `staff_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `administrative_staff`
--

INSERT INTO `administrative_staff` (`staff_id`, `full_name`, `email`, `phone`, `department`, `position`, `hire_date`, `is_active`) VALUES
(1, 'Admin User', 'admin@university.edu', NULL, 'Administration', 'System Administrator', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `course_registration_requests`
--

CREATE TABLE `course_registration_requests` (
  `request_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `academic_year` varchar(50) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `request_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_date` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_registration_requests`
--

INSERT INTO `course_registration_requests` (`request_id`, `student_id`, `course_id`, `semester`, `academic_year`, `status`, `request_date`, `reviewed_by`, `reviewed_date`, `admin_notes`) VALUES
(6, 'BHSM/26/LL/NE/0001', 6, 'Semester 1', '2026/2027', 'approved', '2026-01-10 20:47:26', 4, '2026-01-11 04:54:24', NULL),
(7, 'BHSM/26/LL/NE/0001', 9, 'Semester 1', '2026/2027', 'approved', '2026-01-10 20:47:26', 4, '2026-01-11 04:54:32', NULL),
(8, 'BHSM/26/LL/NE/0001', 4, 'Semester 1', '2026/2027', 'rejected', '2026-01-10 20:47:26', 4, '2026-01-11 04:55:13', 'Student already enrolled in this course'),
(9, 'BHSM/26/LL/NE/0001', 5, 'Semester 1', '2026/2027', 'rejected', '2026-01-10 20:47:26', 4, '2026-01-11 04:55:01', 'Student already enrolled in this course'),
(10, 'BHSM/26/LL/NE/0001', 7, 'Semester 1', '2026/2027', 'approved', '2026-01-10 20:47:43', 4, '2026-01-11 04:54:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_code` varchar(10) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty_id`, `created_at`) VALUES
(1, 'BIT', 'Information Technology', 5, '2026-01-08 20:32:02'),
(2, 'BLSCM', 'Logistics and Supply Chain Management', 5, '2026-01-08 20:32:02'),
(3, 'BBA', 'Business Administration', 5, '2026-01-08 20:32:02'),
(4, 'BHRM', 'Human Resource Management (Business Studies)', 5, '2026-01-08 20:32:02'),
(5, 'BHSM', 'Heathy Systems Management', 5, '2026-01-08 20:32:02'),
(6, 'BCD', 'Community Development', 5, '2026-01-08 20:32:02'),
(7, 'MBA', 'Master of Business Administration', 5, '2026-01-08 21:07:50'),
(8, 'MHRM', 'Master Of Human Resource Management', 5, '2026-01-08 21:08:17'),
(9, 'DBA', 'Doctorate Studies', 5, '2026-01-08 21:08:38'),
(10, 'PHD', 'Doctor of Philosophy in Developmental Studies', 5, '2026-01-08 21:09:29'),
(11, 'MDS', 'Master of Developmental Studies', 5, '2026-01-08 21:09:51'),
(12, 'DIP', 'Business Studies', 5, '2026-01-08 21:10:17'),
(13, 'PC', 'Professional Courses (ABMA, BEMERC, ABA, ICAM)', 5, '2026-01-08 21:11:58'),
(14, 'PGS', 'Postgraduate Studies', 5, '2026-01-10 21:59:45'),
(15, 'BAC', 'Department of Accounting', 5, '2026-01-10 22:02:34');

-- --------------------------------------------------------

--
-- Table structure for table `faculties`
--

CREATE TABLE `faculties` (
  `faculty_id` int(11) NOT NULL,
  `faculty_code` varchar(20) NOT NULL,
  `faculty_name` varchar(255) NOT NULL,
  `head_of_faculty` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `faculties`
--

INSERT INTO `faculties` (`faculty_id`, `faculty_code`, `faculty_name`, `head_of_faculty`, `created_at`, `updated_at`) VALUES
(5, 'FCOM', 'Faculty of Commerce', 'Mr. Paul Chipeta', '2026-01-08 22:43:24', '2026-01-08 22:43:24');

-- --------------------------------------------------------

--
-- Table structure for table `fee_settings`
--

CREATE TABLE `fee_settings` (
  `id` int(11) NOT NULL,
  `application_fee` decimal(10,2) DEFAULT 5500.00,
  `registration_fee` decimal(10,2) DEFAULT 39500.00,
  `tuition_degree` decimal(10,2) DEFAULT 500000.00,
  `tuition_professional` decimal(10,2) DEFAULT 200000.00,
  `tuition_masters` decimal(10,2) DEFAULT 1100000.00,
  `tuition_doctorate` decimal(10,2) DEFAULT 2200000.00,
  `supplementary_exam_fee` decimal(10,2) DEFAULT 50000.00,
  `deferred_exam_fee` decimal(10,2) DEFAULT 50000.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_settings`
--

INSERT INTO `fee_settings` (`id`, `application_fee`, `registration_fee`, `tuition_degree`, `tuition_professional`, `tuition_masters`, `tuition_doctorate`, `supplementary_exam_fee`, `deferred_exam_fee`, `updated_at`) VALUES
(1, 5500.00, 39500.00, 500000.00, 200000.00, 1100000.00, 2200000.00, 50000.00, 50000.00, '2026-01-09 06:02:14');

-- --------------------------------------------------------

--
-- Table structure for table `finance_audit_log`
--

CREATE TABLE `finance_audit_log` (
  `log_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL COMMENT 'payment_entry, payment_approval, payment_rejection, report_access, etc',
  `performed_by` varchar(10) NOT NULL COMMENT 'Finance user ID',
  `finance_role` varchar(20) NOT NULL COMMENT 'Role at time of action',
  `target_student_id` varchar(20) DEFAULT NULL,
  `target_transaction_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `action_details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lecturers`
--

CREATE TABLE `lecturers` (
  `lecturer_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `office` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `role` varchar(20) DEFAULT 'lecturer' COMMENT 'staff, finance, lecturer',
  `finance_role` varchar(20) DEFAULT NULL COMMENT 'Finance sub-roles: finance_entry, finance_approval, finance_manager'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lecturers`
--

INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`) VALUES
(1, 'Dr. Andrew Chilenga', 'daudkphiri@gmail.com', NULL, '+265999243411', 'Computer Science', NULL, 'Senior Lecturer', NULL, 1, 'Lilongwe Campus', '', 'lecturer_1_1767903615.jpg', NULL, 'lecturer', NULL),
(2, 'Dr. Daud Kalisa', 'daudphiri@live.com', NULL, '0983177606', 'Computer Science', NULL, 'Lecturer', NULL, 1, 'Blantyre', '', 'lecturer_2_1767902784.png', NULL, 'lecturer', NULL),
(13, 'Finance Officer', 'finance@university.edu', '$2y$10$SNUACzJ/MPOXSRXbYFDYruqa2TRMcKRZhg94PTdnnQ3jWDq.NqxTC', '', 'Finance Department', NULL, 'Finance Officer', NULL, 1, 'Head Office', NULL, NULL, 'Male', 'finance', 'finance_manager'),
(14, 'Goodman Philimon Mwanza', 'pmwanza@exploitsmw.com', NULL, '+265999342411', NULL, NULL, 'ICT Officer', NULL, 1, NULL, NULL, NULL, 'Male', 'staff', NULL),
(15, 'Barnard Yunusu', 'byunusu@exploitsmw.com', NULL, '+265995879992', NULL, NULL, 'Administrator', NULL, 1, 'Head Office', NULL, NULL, 'Male', 'staff', NULL),
(16, 'Maria Phiri', 'mariaphiri@gmail.com', '$2y$10$B9GycB7iXG6l5owfswmoquKCQRNdDxHeDvDA4vDMcFVcKy5VweDfy', '+265999659452', 'Finance Department', NULL, 'Assistant Accountant', NULL, 1, 'Head Office', NULL, NULL, 'Female', 'finance', 'finance_manager'),
(17, 'Dyson Windo Palira', 'dyson@exploitsmw.com', NULL, '+26599993533', 'BCD', 'Bachelors of Arts in Community Development', 'Senior Lecturer', NULL, 1, 'Lilongwe Campus', 'PhD holder', NULL, 'Female', 'lecturer', NULL),
(18, 'Aaron Phiri', 'aaron@exploitsmw.com', '$2y$10$yv5p682NpQ6RB2DCnut5PO4r51Hd.x9maFcEECSGQBFS/K3xlLCI.', '', 'Finance Department', NULL, 'Accountant', NULL, 1, 'Head Office', NULL, NULL, 'Male', 'finance', 'finance_entry'),
(19, 'Dyson Phiri', 'dyphiri@exploitsmw.com', '$2y$10$ODRe8RTbG1OvoKqUX3DvsOrTJrSLla4s7UfCFSzn7Wg3qn.eaw6e2', '+265999659452', 'Finance Department', NULL, 'Accountant', NULL, 1, 'Head Office', NULL, NULL, '', 'finance', 'finance_entry');

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `module_id` int(11) NOT NULL,
  `module_code` varchar(20) NOT NULL,
  `module_name` varchar(200) NOT NULL,
  `program_of_study` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `semester` enum('One','Two') DEFAULT NULL,
  `credits` int(11) DEFAULT 3,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`module_id`, `module_code`, `module_name`, `program_of_study`, `year_of_study`, `semester`, `credits`, `description`, `created_at`) VALUES
(1, 'CS101', 'Introduction to Computer Science', 'Bachelors Of Information Technology', 1, 'One', 3, '', '2026-01-08 21:15:25'),
(2, 'CS102', 'Programming Fundamentals', 'Bachelors Of Information Technology', 1, 'One', 4, '', '2026-01-08 21:15:25'),
(3, 'CS201', 'Data Structures and Algorithms', 'Bachelors of Computer Science', 2, 'One', 4, NULL, '2026-01-08 21:15:25'),
(4, 'IT101', 'Information Technology Basics', 'Bachelors of Information Technology', 1, 'One', 3, NULL, '2026-01-08 21:15:25'),
(5, 'IT102', 'Database Systems', 'Bachelors Of Information Technology', 1, 'Two', 4, '', '2026-01-08 21:15:25'),
(6, 'BBA3202', 'Research Methods', 'Bachelors of Business Administration', 3, 'Two', 3, 'All students to Take', '2026-01-08 22:55:25'),
(7, 'BBA 1101', 'English for Academic Purposes I', 'Bachelors of Business Administration', 1, 'One', 3, 'English for Academic Purposes I', '2026-01-09 17:28:01'),
(8, 'BBA 1103', 'Business Mathematics I', 'Bachelors of Business Administration', 1, 'One', 4, 'Business Mathematics I', '2026-01-09 17:28:01'),
(9, 'BAC 1101', 'Financial Accounting I', 'Bachelors of Business Administration', 1, 'One', 3, 'Financial Accounting I', '2026-01-09 17:28:01'),
(10, 'BBA 1104', 'Introduction to Business Management', 'Bachelors of Business Administration', 1, 'One', 4, 'Introduction to Business Management', '2026-01-09 17:28:01'),
(11, 'BBA 1105', 'Micro and Macroeconomics', 'Bachelors of Business Administration', 1, 'One', 4, 'Micro and Macroeconomics', '2026-01-09 17:28:01'),
(12, 'BAC 2204', 'Computer Applications', 'Bachelors of Business Administration', 1, 'One', 4, 'Computer Applications', '2026-01-09 17:28:01'),
(13, 'BBA 1201', 'English for Academic Purposes  II', 'Bachelors of Business Administration', 1, 'Two', 3, 'English for Academic Purposes  II', '2026-01-09 17:28:01'),
(14, 'BBA 1202', 'Business Mathematics II', 'Bachelors of Business Administration', 1, 'Two', 4, 'Business Mathematics II', '2026-01-09 17:28:01'),
(15, 'BBA 1203', 'Business Statistics', 'Bachelors of Business Administration', 1, 'Two', 3, 'Business Statistics', '2026-01-09 17:28:01'),
(16, 'BAC 1201', 'Financial Accounting II', 'Bachelors of Business Administration', 1, 'Two', 4, 'Financial Accounting II', '2026-01-09 17:28:01'),
(17, 'BBA 1204', 'Principles of Management', 'Bachelors of Business Administration', 1, 'Two', 4, 'Principles of Management', '2026-01-09 17:28:01'),
(18, 'BBA 2101', 'Marketing Fundamentals I', 'Bachelors of Business Administration', 2, 'One', 4, 'Marketing Fundamentals I', '2026-01-09 17:28:01'),
(19, 'BBA 2102', 'Business Values and Ethics', 'Bachelors of Business Administration', 2, 'One', 3, 'Business Values and Ethics', '2026-01-09 17:28:01'),
(20, 'BBA 2103', 'Organisational Behaviour', 'Bachelors of Business Administration', 2, 'One', 4, 'Organisational Behaviour', '2026-01-09 17:28:01'),
(21, 'BBA 2104', 'Business Environment I', 'Bachelors of Business Administration', 2, 'One', 3, 'Business Environment I', '2026-01-09 17:28:01'),
(22, 'BBA 2106', 'Communicating in Organization I', 'Bachelors of Business Administration', 2, 'One', 4, 'Communicating in Organization I', '2026-01-09 17:28:01'),
(23, 'BAC 2202', 'Costing and Budgetary Control', 'Bachelors of Business Administration', 2, 'Two', 4, 'Costing and Budgetary Control', '2026-01-09 17:28:01'),
(24, 'BBA 2201', 'Marketing Fundamentals II', 'Bachelors of Business Administration', 2, 'Two', 4, 'Marketing Fundamentals II', '2026-01-09 17:28:01');

-- --------------------------------------------------------

--
-- Table structure for table `payment_approvals`
--

CREATE TABLE `payment_approvals` (
  `approval_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_type` varchar(50) NOT NULL,
  `entered_by` varchar(10) NOT NULL COMMENT 'Finance clerk who entered the payment',
  `entered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(10) DEFAULT NULL COMMENT 'Finance approver who approved',
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` varchar(10) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_submissions`
--

CREATE TABLE `payment_submissions` (
  `submission_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `proof_of_payment` varchar(255) DEFAULT NULL,
  `transaction_type` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `submission_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` varchar(50) DEFAULT NULL,
  `reviewed_date` timestamp NULL DEFAULT NULL,
  `finance_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_submissions`
--

INSERT INTO `payment_submissions` (`submission_id`, `student_id`, `amount`, `payment_reference`, `transaction_date`, `proof_of_payment`, `transaction_type`, `bank_name`, `submission_date`, `status`, `reviewed_by`, `reviewed_date`, `finance_id`, `notes`) VALUES
(3, 'BHSM/26/LL/NE/0001', 60000.00, 'FTRN20013MKBT/BNK', '2026-01-09', 'payment_BHSM_26_LL_NE_0001_1768077935.png', 'Bank Deposit', 'National Bank of Malawi', '2026-01-10 20:45:35', 'approved', '7', '2026-01-10 21:25:10', 16, 'This has been verified Thanks'),
(4, 'BHSM/26/LL/NE/0001', 125000.00, 'FTRN200138KBT/BNK', '2026-01-09', 'payment_BHSM_26_LL_NE_0001_1768080256.png', 'Electronic Transfer', 'National Bank of Malawi', '2026-01-10 21:24:16', 'approved', '7', '2026-01-10 21:25:27', 17, 'verified');

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `transaction_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `payment_type` varchar(50) NOT NULL COMMENT 'Registration, Installment 1-4',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approval_status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'Payment approval status',
  `approved_by` varchar(10) DEFAULT NULL COMMENT 'Finance user who approved',
  `approved_at` datetime DEFAULT NULL COMMENT 'When payment was approved'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_transactions`
--

INSERT INTO `payment_transactions` (`transaction_id`, `student_id`, `payment_type`, `amount`, `payment_method`, `reference_number`, `payment_date`, `recorded_by`, `notes`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES
(4, 'STU003', 'auto_distributed', 200000.00, 'cash', 'TFP10020214', '0000-00-00', '7', 'Auto-distributed: Registration: K39,500, Installment 1: K125,000, Installment 2: K35,500', '2026-01-09 05:38:06', 'approved', NULL, NULL),
(6, 'BHSM/26/MZ/ME/0001', 'payment', 250000.00, 'Cash', NULL, '2026-01-02', '', 'Electronic Transfer via National Bank of Malawi - Ref: FTRN20013MKC9/BNK', '2026-01-10 03:49:03', 'approved', NULL, NULL),
(7, 'BHSM/26/MZ/ME/0001', 'payment', 539500.00, 'cheque', 'TFP100202156', '2026-01-10', 'bmambo', '', '2026-01-10 04:17:51', 'approved', NULL, NULL),
(8, 'BHSM/26/MZ/ME/0001', 'registration_fee', 39500.00, 'bank_transfer', 'TFP10020215jk', '2026-01-10', 'bmambo', '', '2026-01-10 04:19:11', 'approved', NULL, NULL),
(9, 'BHSM/26/MZ/ME/0001', 'payment', 250000.00, 'bank_transfer', 'TFP100202124', '2026-01-10', 'bmambo', '', '2026-01-10 04:27:19', 'approved', NULL, NULL),
(11, 'BIT/26/MZ/NE/0001', 'installment_1', 500000.00, 'bank_transfer', 'TFP100202166', '2026-01-08', 'bmambo', '', '2026-01-10 04:29:01', 'approved', NULL, NULL),
(12, 'BIT/26/MZ/NE/0001', 'payment', 200000.00, 'bank_transfer', 'TFP10020216', '2026-01-06', 'bmambo', '', '2026-01-10 04:32:46', 'approved', NULL, NULL),
(13, 'BHSM/26/MZ/ME/0001', 'payment', 1250000.00, 'bank_transfer', 'TFP10020214hy', '2026-01-08', 'bmambo', '', '2026-01-10 04:33:20', 'approved', NULL, NULL),
(14, 'BBA/26/LL/NE/0001', 'payment', 150000.00, 'bank_transfer', 'TFP100202162', '2026-01-02', 'bmambo', '', '2026-01-10 13:57:53', 'approved', NULL, NULL),
(15, 'BBA/26/LL/NE/0001', 'payment', 200000.00, 'Cash', NULL, '2026-01-10', '', 'Bank Deposit via National Bank of Malawi - Ref: FTRN20013MKOT/BNK', '2026-01-10 20:13:46', 'approved', NULL, NULL),
(16, 'BHSM/26/LL/NE/0001', 'payment', 60000.00, 'Cash', NULL, '2026-01-09', '', 'Bank Deposit via National Bank of Malawi - Ref: FTRN20013MKBT/BNK', '2026-01-10 21:25:10', 'approved', NULL, NULL),
(17, 'BHSM/26/LL/NE/0001', 'payment', 125000.00, 'Cash', NULL, '2026-01-09', '', 'Electronic Transfer via National Bank of Malawi - Ref: FTRN200138KBT/BNK', '2026-01-10 21:25:27', 'approved', NULL, NULL),
(19, 'BCD/26/MZ/NE/0001', 'application', 5500.00, 'bank_deposit', 'TFP10020217', '2026-01-10', 'bmambo', 'Bank: National Bank of Malawi', '2026-01-10 22:52:18', 'approved', NULL, NULL),
(20, 'BCD/26/MZ/NE/0001', 'registration_fee', 39500.00, 'bank_transfer', 'TFP10020213', '2026-01-11', 'bmambo', 'Bank: National Bank of Malawi', '2026-01-11 04:32:58', 'approved', NULL, NULL),
(21, 'BCD/26/MZ/NE/0001', 'application', 5500.00, 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 04:50:15', 'approved', NULL, NULL),
(22, 'BHSM/26/LL/NE/0001', 'application', 5500.00, 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 04:51:18', 'approved', NULL, NULL),
(23, 'BHSM/26/LL/NE/0001', 'registration_fee', 39500.00, 'cash', '', '2026-01-11', 'bmambo', '', '2026-01-11 04:52:30', 'approved', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `program_code` varchar(10) NOT NULL,
  `program_name` varchar(255) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program_type` enum('degree','professional','masters','doctorate') DEFAULT 'degree',
  `duration_years` int(11) DEFAULT 4,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program_id`, `program_code`, `program_name`, `department_id`, `program_type`, `duration_years`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(2, 'BSC-IT', 'Bachelor of Information Technology', 1, 'degree', 4, 'IT program focusing on systems administration and network management', 1, '2026-01-10 21:52:11', '2026-01-10 22:06:41'),
(3, 'BBA', 'Bachelor of Business Administration', 3, 'degree', 4, 'Business administration program covering management, accounting, and entrepreneurship', 1, '2026-01-10 21:52:11', '2026-01-10 22:01:11'),
(4, 'BAC', 'Bachelor of Accounting', 15, 'degree', 4, 'Accounting program preparing students for professional accounting careers', 1, '2026-01-10 21:52:11', '2026-01-10 22:12:28'),
(5, 'BA-EDU', 'Bachelor of Arts Community Development', 6, 'degree', 4, 'Teacher education program for primary and secondary education', 1, '2026-01-10 21:52:11', '2026-01-10 22:00:48'),
(7, 'MBA', 'Master of Business Administration', 14, 'masters', 2, 'Postgraduate business program for experienced professionals', 1, '2026-01-10 21:52:11', '2026-01-10 22:04:59'),
(8, 'MSC-CS', 'Master of Human Resources Management', 14, 'masters', 2, 'Advanced computer science program with research focus', 1, '2026-01-10 21:52:11', '2026-01-10 22:05:31'),
(9, 'PHD-BUS', 'Doctor of Philosophy in Business', 9, 'doctorate', 4, 'Research doctorate in business administration and management', 1, '2026-01-10 21:52:11', '2026-01-10 22:06:06'),
(10, 'LSCM', 'Bachelor of Logistics and Supply Chain Management', 12, 'degree', 4, '', 1, '2026-01-10 22:13:52', '2026-01-10 22:13:52'),
(11, 'HSM', 'Bachelor of Healthy System Management', 5, 'degree', 4, '', 1, '2026-01-10 22:15:02', '2026-01-10 22:15:02');

-- --------------------------------------------------------

--
-- Table structure for table `semester_courses`
--

CREATE TABLE `semester_courses` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `academic_year` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `semester_courses`
--

INSERT INTO `semester_courses` (`id`, `course_id`, `semester`, `academic_year`, `is_active`, `created_at`) VALUES
(1, 6, 'Semester 1', '2026/2027', 1, '2026-01-10 10:19:56'),
(2, 9, 'Semester 1', '2026/2027', 1, '2026-01-10 10:19:56'),
(3, 4, 'Semester 1', '2026/2027', 1, '2026-01-10 10:19:56'),
(4, 5, 'Semester 1', '2026/2027', 1, '2026-01-10 10:19:56'),
(5, 7, 'Semester 1', '2026/2027', 1, '2026-01-10 10:19:56'),
(6, 13, 'Semester 2', '2026/2027', 1, '2026-01-10 10:20:44'),
(7, 10, 'Semester 2', '2026/2027', 1, '2026-01-10 10:20:44'),
(8, 11, 'Semester 2', '2026/2027', 1, '2026-01-10 10:20:44'),
(9, 12, 'Semester 2', '2026/2027', 1, '2026-01-10 10:20:44'),
(10, 14, 'Semester 2', '2026/2027', 1, '2026-01-10 10:20:44'),
(11, 24, 'Semester 1', '2026/2027', 1, '2026-01-10 10:48:48'),
(12, 26, 'Semester 1', '2026/2027', 1, '2026-01-10 10:48:48'),
(13, 28, 'Semester 1', '2026/2027', 1, '2026-01-10 10:48:48'),
(14, 29, 'Semester 1', '2026/2027', 1, '2026-01-10 10:48:48'),
(15, 25, 'Semester 1', '2026/2027', 1, '2026-01-10 10:48:48'),
(16, 33, 'Semester 2', '2026/2027', 1, '2026-01-10 10:49:22'),
(17, 32, 'Semester 2', '2026/2027', 1, '2026-01-10 10:49:22'),
(18, 30, 'Semester 2', '2026/2027', 1, '2026-01-10 10:49:22'),
(19, 31, 'Semester 2', '2026/2027', 1, '2026-01-10 10:49:22'),
(20, 34, 'Semester 2', '2026/2027', 1, '2026-01-10 10:49:22'),
(21, 15, 'Semester 1', '2026/2027', 1, '2026-01-10 13:32:19'),
(22, 16, 'Semester 1', '2026/2027', 1, '2026-01-10 13:32:19'),
(23, 17, 'Semester 1', '2026/2027', 1, '2026-01-10 13:32:19'),
(24, 18, 'Semester 1', '2026/2027', 1, '2026-01-10 13:32:19'),
(25, 19, 'Semester 1', '2026/2027', 1, '2026-01-10 13:32:19');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

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
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `entry_type` varchar(10) DEFAULT 'NE' COMMENT 'ME=Mature Entry, NE=Normal Entry, ODL=Open Distance Learning, PC=Professional Course'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `full_name`, `email`, `phone`, `department`, `program_type`, `program`, `year_of_study`, `enrollment_date`, `is_active`, `address`, `profile_picture`, `campus`, `year_of_registration`, `semester`, `gender`, `national_id`, `entry_type`) VALUES
('BCD/26/MZ/NE/0001', 'Charles Musta Chamama', 'cchamama@exploitsmw.com', '+265999243411', '6', 'degree', '0', 1, NULL, 1, 'Mbayani', NULL, 'Mzuzu Campus', '2026', 'One', 'Male', 'WE3456789', 'NE'),
('BHSM/26/LL/NE/0001', 'Linda Chirwa', 'lchirwa@exploitsmw.com', '+26588835662', '5', 'degree', '0', 1, NULL, 1, 'Ndirande', NULL, 'Lilongwe Campus', '2026', 'One', 'Female', 'TEDSMYT', 'NE');

-- --------------------------------------------------------

--
-- Table structure for table `student_finances`
--

CREATE TABLE `student_finances` (
  `finance_id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_finances`
--

INSERT INTO `student_finances` (`finance_id`, `student_id`, `application_fee_paid`, `application_fee_date`, `registration_fee`, `registration_paid`, `registration_paid_date`, `expected_tuition`, `expected_total`, `tuition_fee`, `tuition_paid`, `installment_1`, `installment_1_date`, `installment_2`, `installment_2_date`, `installment_3`, `installment_3_date`, `installment_4`, `installment_4_date`, `total_paid`, `balance`, `payment_percentage`, `content_access_weeks`, `last_payment_date`, `created_at`, `updated_at`) VALUES
(2, 'STU003', 0.00, NULL, 39500.00, 39500.00, '2026-01-09', 500000.00, 545000.00, 500000.00, 160500.00, 125000.00, '2026-01-09', 35500.00, NULL, 0.00, NULL, 0.00, NULL, 200000.00, 339500.00, 25, 4, NULL, '2026-01-09 03:16:11', '2026-01-09 05:38:06'),
(3, 'BIT/26/MZ/NE/0001', 0.00, NULL, 39500.00, 0.00, NULL, 500000.00, 539500.00, 500000.00, 0.00, 0.00, NULL, 0.00, NULL, 0.00, NULL, 0.00, NULL, 200000.00, 139500.00, 74, 9, NULL, '2026-01-10 04:32:46', '2026-01-10 04:32:46'),
(4, 'BHSM/26/MZ/ME/0001', 0.00, NULL, 39500.00, 0.00, NULL, 500000.00, 545000.00, 500000.00, 0.00, 0.00, NULL, 0.00, NULL, 0.00, NULL, 0.00, NULL, 1250000.00, -705000.00, 229, 52, NULL, '2026-01-10 04:33:20', '2026-01-10 06:44:54'),
(5, 'BBA/26/LL/NE/0001', 0.00, NULL, 39500.00, 0.00, NULL, 500000.00, 539500.00, 500000.00, 0.00, 0.00, NULL, 0.00, NULL, 0.00, NULL, 0.00, NULL, 350000.00, -10500.00, 102, 52, NULL, '2026-01-10 13:57:53', '2026-01-10 20:13:46'),
(6, 'BHSM/26/LL/NE/0001', 5500.00, '2026-01-11', 39500.00, 39500.00, '2026-01-11', 500000.00, 545000.00, 500000.00, 0.00, 0.00, NULL, 0.00, NULL, 0.00, NULL, 0.00, NULL, 230000.00, 315000.00, 42, 4, '2026-01-11', '2026-01-10 21:25:10', '2026-01-11 04:52:30'),
(8, 'BCD/26/MZ/NE/0001', 5500.00, '2026-01-10', 39500.00, 39500.00, '2026-01-11', 500000.00, 545000.00, 500000.00, 0.00, 5500.00, '2026-01-11', 0.00, NULL, 0.00, NULL, 0.00, NULL, 50500.00, 494500.00, 9, 0, '2026-01-11', '2026-01-10 22:52:18', '2026-01-11 04:50:15');

-- --------------------------------------------------------

--
-- Table structure for table `university_settings`
--

CREATE TABLE `university_settings` (
  `id` int(11) NOT NULL,
  `university_name` varchar(255) NOT NULL DEFAULT 'Exploits University',
  `address_po_box` varchar(100) DEFAULT 'P.O.Box 301752',
  `address_area` varchar(100) DEFAULT 'Area 4',
  `address_street` varchar(100) DEFAULT '',
  `address_city` varchar(100) DEFAULT 'Lilongwe',
  `address_country` varchar(100) DEFAULT 'Malawi',
  `phone` varchar(50) DEFAULT '',
  `email` varchar(100) DEFAULT '',
  `website` varchar(100) DEFAULT '',
  `logo_path` varchar(255) DEFAULT '',
  `receipt_footer_text` text DEFAULT 'Thank you for your payment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `university_settings`
--

INSERT INTO `university_settings` (`id`, `university_name`, `address_po_box`, `address_area`, `address_street`, `address_city`, `address_country`, `phone`, `email`, `website`, `logo_path`, `receipt_footer_text`, `created_at`, `updated_at`) VALUES
(1, 'Exploits University', 'P.O.Box 301752', 'Area 4', 'Maula Streats', 'Lilongwe', 'Malawi', '+265999568251', 'info@exploitsmw.com', 'www.exploitsmw.com', '../uploads/university/logo_1767937933.png', 'Thank you for your payment', '2026-01-09 05:22:56', '2026-01-09 05:52:13');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','lecturer','staff','hod','dean','finance') NOT NULL,
  `related_student_id` varchar(20) DEFAULT NULL,
  `related_lecturer_id` int(11) DEFAULT NULL,
  `related_staff_id` int(11) DEFAULT NULL,
  `related_hod_id` int(11) DEFAULT NULL,
  `related_dean_id` int(11) DEFAULT NULL,
  `related_finance_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `related_student_id`, `related_lecturer_id`, `related_staff_id`, `related_hod_id`, `related_dean_id`, `related_finance_id`, `is_active`, `created_at`, `last_login`) VALUES
(1, 'lecturer', 'daudkphiri@gmail.com', '$2y$10$45uXd0XRyUCkrrBhAgR4cORUkWBydc4XSU3JYKZsHQW43Shj1cwWK', 'lecturer', NULL, 1, NULL, NULL, NULL, NULL, 1, '2026-01-06 21:53:51', NULL),
(3, 'lecturer2', 'daudphiri@live.com', '$2y$10$45uXd0XRyUCkrrBhAgR4cORUkWBydc4XSU3JYKZsHQW43Shj1cwWK', 'lecturer', NULL, 2, NULL, NULL, NULL, NULL, 1, '2026-01-06 22:05:40', NULL),
(4, 'admin', 'admin@university.edu', '$2y$10$45uXd0XRyUCkrrBhAgR4cORUkWBydc4XSU3JYKZsHQW43Shj1cwWK', 'staff', NULL, NULL, 1, NULL, NULL, NULL, 1, '2026-01-06 22:05:58', NULL),
(6, 'dphiri', 'daudphiri@exploitsmw.com', '$2y$10$whlM/69jx2PcAZR3TpfTNefSBICk8CZ1cEymXgBpc3WY4vrI7JfDu', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-09 07:01:45', NULL),
(7, 'bmambo', 'bmambo@exploitsmw.com', '$2y$10$mBDO9v1IxC5kIg1EGelGsujJn4kACHARoKZMDIF77ssRDV62HGTO.', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-09 07:03:39', NULL),
(9, 'pmwanza', 'pmwanza@exploitsmw.com', '$2y$10$UAirhfBY0K7dh/3/9fdpceabvrVwmsjKMAmffoQqum5Z.sRnM90F6', 'staff', NULL, 14, NULL, NULL, NULL, NULL, 1, '2026-01-09 16:53:00', NULL),
(10, 'byunusu', 'byunusu@exploitsmw.com', '$2y$10$hqebecgQVSK13GYkz5k80.HwY83ffptqs9d3sq1TWQbzi4EOZjEFG', 'staff', NULL, 15, NULL, NULL, NULL, NULL, 1, '2026-01-09 16:55:00', NULL),
(14, 'mphiri', 'mariaphiri@gmail.com', '$2y$10$SQpDKhR5.1kScMAHK.B/Muu57uQnsQSR11gTKEKkdbBZe31woadzC', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-10 07:51:11', NULL),
(16, 'chamama', 'cchamama@exploitsmw.com', '$2y$10$AG9gVRpagwX6G3XhS0jmNOQRve1Uha3ChS0e.3ShvUrk7qUCLAr4.', 'student', 'BCD/26/MZ/NE/0001', NULL, NULL, NULL, NULL, NULL, 1, '2026-01-10 22:39:36', NULL),
(17, 'lchirwa', 'lchirwa@exploitsmw.com', '$2y$10$jKL2B4OOD5Naeh.Wo/1k8uiO/5aIhUGMLMzkalWIq8Z4raKEGFdnK', 'student', 'BHSM/26/LL/NE/0001', NULL, NULL, NULL, NULL, NULL, 1, '2026-01-10 22:43:12', NULL),
(18, 'dpalira', 'dyson@exploitsmw.com', '$2y$10$DOIj7LETsrHVadPu6wqys./iNqdalDZpffRI5Q.nVU.IPUtwJch0G', 'lecturer', NULL, 17, NULL, NULL, NULL, NULL, 1, '2026-01-10 22:55:49', NULL),
(19, 'aphiri', 'aaron@exploitsmw.com', '$2y$10$yv5p682NpQ6RB2DCnut5PO4r51Hd.x9maFcEECSGQBFS/K3xlLCI.', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-11 08:21:15', NULL),
(20, 'dyphiri', 'dyphiri@exploitsmw.com', '$2y$10$ODRe8RTbG1OvoKqUX3DvsOrTJrSLla4s7UfCFSzn7Wg3qn.eaw6e2', 'finance', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-11 08:25:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vle_announcements`
--

CREATE TABLE `vle_announcements` (
  `announcement_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `created_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_announcements`
--

INSERT INTO `vle_announcements` (`announcement_id`, `course_id`, `lecturer_id`, `title`, `content`, `created_date`) VALUES
(1, 2, 1, 'Exams are here', 'Write Exams', '2026-01-08 22:01:32');

-- --------------------------------------------------------

--
-- Table structure for table `vle_assignments`
--

CREATE TABLE `vle_assignments` (
  `assignment_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `week_number` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `assignment_type` enum('formative','summative','mid_sem','final_exam') DEFAULT 'formative',
  `max_score` int(11) DEFAULT 100,
  `passing_score` int(11) DEFAULT 50,
  `due_date` datetime DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_assignments`
--

INSERT INTO `vle_assignments` (`assignment_id`, `course_id`, `week_number`, `title`, `description`, `assignment_type`, `max_score`, `passing_score`, `due_date`, `file_path`, `file_name`, `is_active`, `created_date`) VALUES
(1, 1, 1, 'Weekly assignment', 'Assignment', 'formative', 100, 50, '0000-00-00 00:00:00', NULL, NULL, 1, '2026-01-06 22:23:20'),
(2, 1, 2, 'Week 2 Assignment', 'hdhdhdhdhdhd', 'formative', 100, 50, '0000-00-00 00:00:00', '1767899662_blessed couples.jpg', 'blessed couples.jpg', 1, '2026-01-08 21:14:22'),
(3, 1, 3, 'ASSIGNMENT 3 - WEEK 3', 'contadict with evidence \r\n\r\nInterviews, particularly semi-structured or in-depth interviews, allow researchers to explore participants’ perspectives in detail. One major advantage of interviews is the opportunity for depth and flexibility, as researchers can probe responses and clarify meanings as they emerge (Gill et al., 2008; Jain, 2021). Interviews are especially useful when discussing sensitive topics, as they provide a private setting that may encourage openness. However, interviews can be time-consuming and resource-intensive, and the quality of data may be influenced by interviewer bias or participants’ willingness to share honestly (Chenail, 2012).', 'formative', 100, 50, '0000-00-00 00:00:00', NULL, NULL, 1, '2026-01-08 21:29:18'),
(4, 1, 4, 'ASSIGNMENT 4 - WEEK 4', 'contadict with evidence \r\n\r\nInterviews, particularly semi-structured or in-depth interviews, allow researchers to explore participants’ perspectives in detail. One major advantage of interviews is the opportunity for depth and flexibility, as researchers can probe responses and clarify meanings as they emerge (Gill et al., 2008; Jain, 2021). Interviews are especially useful when discussing sensitive topics, as they provide a private setting that may encourage openness. However, interviews can be time-consuming and resource-intensive, and the quality of data may be influenced by interviewer bias or participants’ willingness to share honestly (Chenail, 2012).', 'formative', 100, 50, '0000-00-00 00:00:00', NULL, NULL, 1, '2026-01-08 21:30:12'),
(5, 2, 1, 'ASSIGNMENT 1- WEEK 1', 'Individual assignment for week 1', 'formative', 100, 50, '0000-00-00 00:00:00', NULL, NULL, 1, '2026-01-08 23:32:04'),
(6, 10, 1, 'Knowledge understanding', 'what is understanding', 'formative', 100, 50, '0000-00-00 00:00:00', '1768079753_courses_template.csv', 'courses_template.csv', 1, '2026-01-10 23:15:53');

-- --------------------------------------------------------

--
-- Table structure for table `vle_courses`
--

CREATE TABLE `vle_courses` (
  `course_id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `program_of_study` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `total_weeks` int(11) DEFAULT 16,
  `is_active` tinyint(1) DEFAULT 1,
  `created_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_courses`
--

INSERT INTO `vle_courses` (`course_id`, `course_code`, `course_name`, `description`, `lecturer_id`, `program_of_study`, `year_of_study`, `total_weeks`, `is_active`, `created_date`) VALUES
(1, 'BBA1102', 'COMPUTER APPLICATIONS', 'contadict with evidence \r\n\r\nInterviews, particularly semi-structured or in-depth interviews, allow researchers to explore participants’ perspectives in detail. One major advantage of interviews is the opportunity for depth and flexibility, as researchers can probe responses and clarify meanings as they emerge (Gill et al., 2008; Jain, 2021). Interviews are especially useful when discussing sensitive topics, as they provide a private setting that may encourage openness. However, interviews can be time-consuming and resource-intensive, and the quality of data may be influenced by interviewer bias or participants’ willingness to share honestly (Chenail, 2012).', NULL, NULL, NULL, 16, 1, '2026-01-06 22:13:42'),
(2, 'BBA2101', 'Introduction Computer programing', 'Systems Security. This course builds core systems security skills by covering authentication, authorization, vulnerability scanning, and breach detection, helping you monitor, protect, and harden systems against cyber threats.', NULL, NULL, NULL, 16, 1, '2026-01-07 08:25:54'),
(3, 'BBA3202', 'Research Methods', 'This subject focuses on designing, conducting, and reporting findings from a research project. The objective \r\nof this subject is to undertake a project related to the chosen specialisation. Students will identify an \r\nappropriate research problem, conduct a literature review, engage in data collection, analyse the data and \r\npresent findings in a formal research report.', NULL, NULL, NULL, 16, 1, '2026-01-07 08:28:45'),
(4, 'BBA 1101', 'English for Academic Purposes I', 'English for Academic Purposes I', 2, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(5, 'BBA 1103', 'Business Mathematics I', 'Business Mathematics I', 1, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(6, 'BAC 1101', 'Financial Accounting I', 'Financial Accounting I', 2, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(7, 'BBA 1104', 'Introduction to Business Management', 'Introduction to Business Management', 1, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(8, 'BBA 1105', 'Micro and Macroeconomics', 'Micro and Macroeconomics', 1, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(9, 'BAC 2204', 'Computer Applications', 'Computer Applications', NULL, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(10, 'BBA 1201', 'English for Academic Purposes  II', 'English for Academic Purposes  II', 17, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(11, 'BBA 1202', 'Business Mathematics II', 'Business Mathematics II', NULL, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(12, 'BBA 1203', 'Business Statistics', 'Business Statistics', NULL, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(13, 'BAC 1201', 'Financial Accounting II', 'Financial Accounting II', 17, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(14, 'BBA 1204', 'Principles of Management', 'Principles of Management', NULL, 'Bachelors of Business Administration', 1, 16, 1, '2026-01-10 09:13:05'),
(15, 'BBA 2101', 'Marketing Fundamentals I', 'Marketing Fundamentals I', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(16, 'BBA 2102', 'Business Values and Ethics', 'Business Values and Ethics', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(17, 'BBA 2103', 'Organisational Behaviour', 'Organisational Behaviour', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(18, 'BBA 2104', 'Business Environment I', 'Business Environment I', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(19, 'BBA 2106', 'Communicating in Organization I', 'Communicating in Organization I', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(20, 'BAC 2202', 'Costing and Budgetary Control', 'Costing and Budgetary Control', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(21, 'BBA 2201', 'Marketing Fundamentals II', 'Marketing Fundamentals II', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(22, 'BBA 2203', 'Business Environment II', 'Business Environment II', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(23, 'BBA 2204', 'Communicating in Organizaton II', 'Communicating in Organizaton II', NULL, 'Bachelors of Business Administration', 2, 16, 1, '2026-01-10 09:13:05'),
(24, 'BBA 3101', 'Managing Organizations I', 'Managing Organizations I', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(25, 'BBA 3106', 'Business to Business Marketing', 'Business to Business Marketing', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(26, 'BBA 3102', 'Management Information Systems', 'Management Information Systems', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(27, 'BBA3101', 'Company Law 1', 'Company Law 1', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(28, 'BBA 3103', 'Brands Management', 'Brands Management', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(29, 'BBA 3105', 'Entrepreneurship and Small Business Management', 'Entrepreneurship and Small Business Management', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(30, 'BBA 3202', 'Research Methods', 'Research Methods', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(31, 'BBA 3203', 'E-Business Strategy', 'E-Business Strategy', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(32, 'BBA 3201', 'Managing in Organisation II', 'Managing in Organisation II', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(33, 'BAC 3203', 'Financial Management', 'Financial Management', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(34, 'BBA 3205', 'Company Law II', 'Company Law II', NULL, 'Bachelors of Business Administration', 3, 16, 1, '2026-01-10 09:13:05'),
(35, 'BAC 4103', 'Risk Management', 'Risk Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(36, 'BAC 3101', 'Corporate Governance and Ethics', 'Corporate Governance and Ethics', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(37, 'BBA 4104', 'Organisational Leadership', 'Organisational Leadership', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(38, 'BBA 4105', 'Managerial Economics', 'Managerial Economics', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(39, 'BBA  4106', 'Operations and Production Management', 'Operations and Production Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(40, 'BBA 4102', 'Corporate Strategy and Planning', 'Corporate Strategy and Planning', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(41, 'BBA 4103', 'Service Management', 'Service Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(42, 'BBA 4201', 'production and Total Quality Management', 'production and Total Quality Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(43, 'BBA 4202', 'Project Management', 'Project Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(44, 'BBA 4203', 'International Business', 'International Business', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(45, 'BBA 4101', 'Contemporary Issues in Marketing', 'Contemporary Issues in Marketing', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(46, 'BBA 4205', 'Change Management', 'Change Management', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05'),
(47, 'BBA 4206', 'Dissertation', 'Dissertation', NULL, 'Bachelors of Business Administration', 4, 16, 1, '2026-01-10 09:13:05');

-- --------------------------------------------------------

--
-- Table structure for table `vle_enrollments`
--

CREATE TABLE `vle_enrollments` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `enrollment_date` datetime DEFAULT current_timestamp(),
  `current_week` int(11) DEFAULT 1,
  `is_completed` tinyint(1) DEFAULT 0,
  `completion_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_enrollments`
--

INSERT INTO `vle_enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `current_week`, `is_completed`, `completion_date`) VALUES
(12, 'BCD/26/MZ/NE/0001', 4, '2026-01-10 22:48:58', 1, 0, NULL),
(13, 'BHSM/26/LL/NE/0001', 4, '2026-01-10 22:48:59', 1, 0, NULL),
(14, 'BCD/26/MZ/NE/0001', 5, '2026-01-10 22:49:16', 1, 0, NULL),
(15, 'BHSM/26/LL/NE/0001', 5, '2026-01-10 22:49:16', 1, 0, NULL),
(16, 'BCD/26/MZ/NE/0001', 10, '2026-01-10 23:16:50', 1, 0, NULL),
(17, 'BHSM/26/LL/NE/0001', 10, '2026-01-10 23:16:50', 1, 0, NULL),
(18, 'BHSM/26/LL/NE/0001', 7, '2026-01-11 06:54:16', 1, 0, NULL),
(19, 'BHSM/26/LL/NE/0001', 6, '2026-01-11 06:54:24', 1, 0, NULL),
(20, 'BHSM/26/LL/NE/0001', 9, '2026-01-11 06:54:32', 1, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vle_forums`
--

CREATE TABLE `vle_forums` (
  `forum_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `week_number` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_forums`
--

INSERT INTO `vle_forums` (`forum_id`, `course_id`, `week_number`, `title`, `description`, `is_active`, `created_date`) VALUES
(1, 1, 1, 'Introduction', 'Please  introduce yourself', 1, '2026-01-07 08:26:52'),
(2, 1, 0, 'Stuents comments', 'hdhdhhdhddhhdhdhhdhdhhdhdhdh', 1, '2026-01-08 21:31:50');

-- --------------------------------------------------------

--
-- Table structure for table `vle_forum_posts`
--

CREATE TABLE `vle_forum_posts` (
  `post_id` int(11) NOT NULL,
  `forum_id` int(11) DEFAULT NULL,
  `parent_post_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `content` text NOT NULL,
  `post_date` datetime DEFAULT current_timestamp(),
  `is_pinned` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vle_grades`
--

CREATE TABLE `vle_grades` (
  `grade_id` int(11) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `grade_type` enum('formative','summative','mid_sem','final_exam','overall') NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `max_score` decimal(5,2) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `grade_letter` varchar(2) DEFAULT NULL,
  `graded_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vle_messages`
--

CREATE TABLE `vle_messages` (
  `message_id` int(11) NOT NULL,
  `sender_type` enum('student','lecturer') NOT NULL,
  `sender_id` varchar(50) NOT NULL,
  `recipient_type` enum('student','lecturer') NOT NULL,
  `recipient_id` varchar(50) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_date` datetime DEFAULT current_timestamp(),
  `read_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_messages`
--

INSERT INTO `vle_messages` (`message_id`, `sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `subject`, `message`, `is_read`, `sent_date`, `read_date`) VALUES
(1, 'student', 'STU002', 'lecturer', '1', 'Application to open week 2 assignment', 'please open week 2 assignment', 0, '2026-01-08 20:54:06', NULL),
(2, 'lecturer', '1', 'student', 'STU002', 'Re: Application to open week 2 assignment', 'please write it is open', 0, '2026-01-08 20:57:50', NULL),
(3, 'student', 'STU002', 'lecturer', '1', 'Re: Re: Application to open week 2 assignment', 'Thanks so much', 0, '2026-01-08 20:58:22', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vle_progress`
--

CREATE TABLE `vle_progress` (
  `progress_id` int(11) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `week_number` int(11) DEFAULT NULL,
  `content_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `progress_type` enum('content_viewed','assignment_completed','week_completed') NOT NULL,
  `completion_date` datetime DEFAULT current_timestamp(),
  `score` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vle_submissions`
--

CREATE TABLE `vle_submissions` (
  `submission_id` int(11) NOT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `submission_date` datetime DEFAULT current_timestamp(),
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `text_content` text DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_date` datetime DEFAULT NULL,
  `status` enum('submitted','graded','late') DEFAULT 'submitted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vle_weekly_content`
--

CREATE TABLE `vle_weekly_content` (
  `content_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `week_number` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `content_type` enum('presentation','video','document','link','text') DEFAULT 'text',
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vle_weekly_content`
--

INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES
(1, 1, 1, 'Defence for research', 'reade akkkdk', 'presentation', '1767730854_Mvula G- Thesis Defence presentation.pptx', '0', 1, 0, '2026-01-06 22:20:54'),
(2, 1, 1, 'Party', 'hdhdhhdh', 'video', '1767730917_DSC_3260.jpg', '0', 1, 0, '2026-01-06 22:21:57'),
(3, 1, 1, 'Introduction to computer Systems', 'Introduction to Computer Systems\r\n1. Overview of Computer Systems\r\n\r\nA computer system is an integrated set of hardware and software components designed to process data into meaningful information. Computer systems are used in education, business, healthcare, government, research, and daily life. Understanding how computer systems work is fundamental for students in information technology, computer science, and business-related fields.\r\n\r\nA computer system performs four basic functions:\r\n\r\nInput – accepting data and instructions\r\n\r\nProcessing – transforming data into information\r\n\r\nOutput – presenting processed information\r\n\r\nStorage – saving data and information for future use\r\n\r\n2. Components of a Computer System\r\n\r\nA computer system consists of both hardware and software, working together to perform tasks.\r\n\r\n2.1 Hardware Components\r\n\r\nHardware refers to the physical parts of a computer system that can be seen and touched.\r\n\r\na) Input Devices\r\n\r\nThese devices are used to enter data and instructions into the computer. Examples include:\r\n\r\nKeyboard\r\n\r\nMouse\r\n\r\nScanner\r\n\r\nMicrophone\r\n\r\nWebcam\r\n\r\nTouchscreen\r\n\r\nb) Central Processing Unit (CPU)\r\n\r\nThe CPU is the brain of the computer. It performs all processing and controls other components.\r\n\r\nThe CPU consists of:\r\n\r\nArithmetic Logic Unit (ALU) – performs calculations and logical operations\r\n\r\nControl Unit (CU) – directs and coordinates activities of the computer\r\n\r\nRegisters – small, fast memory locations inside the CPU\r\n\r\nc) Memory and Storage Devices\r\n\r\nPrimary Memory (Main Memory)\r\n\r\nRandom Access Memory (RAM)\r\n\r\nRead Only Memory (ROM)\r\n\r\nSecondary Storage Devices\r\n\r\nHard Disk Drives (HDD)\r\n\r\nSolid State Drives (SSD)\r\n\r\nUSB Flash Drives\r\n\r\nOptical Discs (CD/DVD)\r\n\r\nd) Output Devices\r\n\r\nThese devices present information to the user. Examples include:\r\n\r\nMonitor\r\n\r\nPrinter\r\n\r\nSpeakers\r\n\r\nProjector\r\n\r\n3. Software Components\r\n\r\nSoftware refers to the programs and instructions that tell the computer what to do.\r\n\r\n3.1 System Software\r\n\r\nSystem software manages and controls computer hardware and provides a platform for application software.\r\n\r\nExamples include:\r\n\r\nOperating Systems (Windows, Linux, macOS)\r\n\r\nDevice Drivers\r\n\r\nUtility Programs (antivirus, disk cleanup)\r\n\r\n3.2 Application Software\r\n\r\nApplication software is designed to perform specific user tasks.\r\n\r\nExamples include:\r\n\r\nWord processors (Microsoft Word)\r\n\r\nSpreadsheets (Excel)\r\n\r\nPresentation software (PowerPoint)\r\n\r\nBrowsers (Chrome, Firefox)\r\n\r\nAccounting and database systems\r\n\r\n4. Types of Computer Systems\r\n4.1 Personal Computers (PCs)\r\n\r\nUsed by individuals for general-purpose tasks such as word processing, browsing, and learning.\r\n\r\n4.2 Servers\r\n\r\nPowerful computers that provide services and resources to other computers on a network.\r\n\r\n4.3 Mobile Devices\r\n\r\nPortable computing devices such as smartphones and tablets.\r\n\r\n4.4 Embedded Systems\r\n\r\nSpecial-purpose computers embedded in devices like ATMs, cars, washing machines, and medical equipment.\r\n\r\n5. Data and Information\r\n\r\nData refers to raw facts and figures (numbers, text, symbols).\r\n\r\nInformation is processed data that is meaningful and useful for decision-making.\r\n\r\nExample:\r\n\r\nData: 45, 60, 75\r\n\r\nInformation: Average score = 60\r\n\r\n6. Computer System Operation Cycle\r\n\r\nThe operation of a computer system follows the IPO Cycle:\r\n\r\nInput\r\n\r\nProcessing\r\n\r\nOutput\r\n\r\nStorage\r\n\r\nThis cycle repeats continuously while the computer is in operation.\r\n\r\n7. Importance of Computer Systems\r\n\r\nComputer systems:\r\n\r\nImprove efficiency and productivity\r\n\r\nEnhance communication\r\n\r\nSupport data storage and analysis\r\n\r\nEnable automation of tasks\r\n\r\nSupport decision-making\r\n\r\n8. Ethical and Security Issues in Computer Systems\r\n8.1 Computer Ethics\r\n\r\nEthical use of computer systems involves:\r\n\r\nRespecting privacy\r\n\r\nAvoiding plagiarism\r\n\r\nUsing software legally\r\n\r\nResponsible use of information\r\n\r\n8.2 Computer Security\r\n\r\nSecurity measures protect computer systems and data from threats such as viruses, hacking, and data theft.\r\n\r\nExamples of security measures:\r\n\r\nPasswords and authentication\r\n\r\nAntivirus software\r\n\r\nFirewalls\r\n\r\nData backups\r\n\r\n9. Summary\r\n\r\nAn understanding of computer systems involves knowledge of hardware, software, system operations, and ethical issues. Computer systems play a critical role in modern society, making computer literacy an essential skill for students and professionals.\r\n\r\n10. Review Questions\r\n\r\nDefine a computer system.\r\n\r\nIdentify and explain the main components of a computer system.\r\n\r\nDifferentiate between system software and application software.\r\n\r\nExplain the IPO cycle.\r\n\r\nList three importance of computer systems.\r\n\r\nEnd of Lecture', 'text', NULL, NULL, 1, 0, '2026-01-06 23:19:14'),
(4, 1, 1, 'SYETEM SECURITY', 'Systems Security. This course builds core systems security skills by covering authentication, authorization, vulnerability scanning, and breach detection, helping you monitor, protect, and harden systems against cyber threats.', 'video', NULL, NULL, 1, 0, '2026-01-07 08:22:44');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES
(5, 1, 2, 'The Introduction to computer System', '<h1 class=\"text-text-100 mt-3 -mb-1 text-[1.375rem] font-bold\">The Introduction to Computer Systems</h1>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">What is a Computer System?</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">A computer system is an integrated set of hardware and software components that work together to process data and produce meaningful information. It accepts input, processes it according to instructions, stores data, and produces output. Computer systems range from simple embedded devices to complex supercomputers.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Core Components of a Computer System</h2>\r\n<h3 class=\"text-text-100 mt-2 -mb-1 text-base font-bold\">1. Hardware</h3>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Hardware refers to the physical components you can touch:</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Central Processing Unit (CPU)</strong>: Often called the \"brain\" of the computer, the CPU executes instructions and performs calculations. It contains the Arithmetic Logic Unit (ALU) for mathematical operations and the Control Unit (CU) for coordinating activities.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Memory</strong>: Computer memory is hierarchical. Primary memory includes RAM (Random Access Memory) for temporary storage during operations and ROM (Read-Only Memory) for permanent instructions. Secondary storage devices like hard drives and SSDs provide long-term data retention.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Input Devices</strong>: These allow users to enter data into the system, including keyboards, mice, scanners, microphones, and touchscreens.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Output Devices</strong>: These present processed information to users through monitors, printers, speakers, and projectors.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Motherboard</strong>: The main circuit board that connects all components and allows them to communicate.</p>\r\n<h3 class=\"text-text-100 mt-2 -mb-1 text-base font-bold\">2. Software</h3>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Software provides the instructions that tell hardware what to do:</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>System Software</strong>: This includes the operating system (Windows, macOS, Linux) that manages hardware resources and provides a platform for applications. It also includes utility programs for system maintenance.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Application Software</strong>: Programs designed for end-users to accomplish specific tasks, such as word processors, web browsers, games, and specialized professional tools.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Firmware</strong>: Software permanently stored in hardware devices, providing low-level control.</p>\r\n<h3 class=\"text-text-100 mt-2 -mb-1 text-base font-bold\">3. Data</h3>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Data is raw facts and figures that become meaningful information after processing. Data flows through the system in binary form (0s and 1s).</p>\r\n<h3 class=\"text-text-100 mt-2 -mb-1 text-base font-bold\">4. Users (Peopleware)</h3>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">People who interact with computer systems, including end-users, programmers, system administrators, and analysts.</p>\r\n<h3 class=\"text-text-100 mt-2 -mb-1 text-base font-bold\">5. Procedures</h3>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Documentation and instructions that guide how the system should be used and maintained.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">How Computer Systems Work: The Information Processing Cycle</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Computer systems follow a basic cycle:</p>\r\n<ol class=\"[li_&amp;]:mb-0 [li_&amp;]:mt-1.5 [li_&amp;]:gap-1.5 [&amp;:not(:last-child)_ul]:pb-1 [&amp;:not(:last-child)_ol]:pb-1 list-decimal flex flex-col gap-2 pl-8 mb-3\"><li class=\"whitespace-normal break-words pl-2\"><strong>Input</strong>: Data enters the system through input devices</li><li class=\"whitespace-normal break-words pl-2\"><strong>Processing</strong>: The CPU manipulates data according to program instructions</li><li class=\"whitespace-normal break-words pl-2\"><strong>Storage</strong>: Data is saved in memory or storage devices</li><li class=\"whitespace-normal break-words pl-2\"><strong>Output</strong>: Results are presented through output devices</li></ol>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Types of Computer Systems</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Personal Computers (PCs)</strong>: Desktop and laptop computers for individual use</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Servers</strong>: Powerful computers that provide services to other computers on a network</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Mainframes</strong>: Large systems used by organizations for critical applications and bulk data processing</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Supercomputers</strong>: Extremely powerful systems for complex scientific calculations</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Embedded Systems</strong>: Specialized computers built into other devices (cars, appliances, medical equipment)</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Mobile Devices</strong>: Smartphones and tablets with computing capabilities</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">The Binary System</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Computers operate using binary code, representing all data as combinations of two digits: 0 and 1. Each binary digit is called a bit, and eight bits make a byte. This simple system allows computers to reliably store and process all types of information through electrical signals (on/off states).</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Computer Architecture</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Modern computers generally follow the Von Neumann architecture, which features a single storage structure for both instructions and data, and a sequential instruction execution model. This architecture includes the CPU, memory unit, input/output devices, and a bus system for communication.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Importance of Computer Systems</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">Computer systems have transformed modern society by enabling automation, enhancing communication, supporting scientific research, facilitating education, driving economic growth, and solving complex problems across all domains of human activity.</p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><br></p><p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><img style=\"width: 25%;\" src=\"data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAA4QAAAK4CAYAAAAsgTNZAAAABGdBTUEAALGPC/xhBQAAAAlwSFlzAAAV1QAAFdUBe9J4kAAA/7JJREFUeF7svQmj20iOpavru3hL557VM/92/uu83qqrcvF6fRf7ne8cgKTka2e6etppl3AkKILBCACBCJIAg5JO3gq7wWAwGAwGg8FgMBgcHe5VOhgMBoPBYDAYDAaDI8MEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeKCQgHg8FgMBgMBoPB4EgxAeFgMBgMBoPBYDAYHCkmIBwMBoPBYDAYDAaDI8UEhIPBYDAYDAaDwWBwpJiAcDAYDAaDwWAwGAyOFBMQDgaDwWAwGAwGg8GRYgLCwWAwGAwGg8FgMDhSTEA4GAwGg8FgMBgMBkeK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', 'text', NULL, NULL, 1, 0, '2026-01-08 20:27:55');
INSERT INTO `vle_weekly_content` (`content_id`, `course_id`, `week_number`, `title`, `description`, `content_type`, `file_path`, `file_name`, `is_mandatory`, `sort_order`, `created_date`) VALUES
(6, 1, 2, 'Week 2', '', 'presentation', '1767897489_EXM_7589.jpg', '0', 1, 0, '2026-01-08 20:38:09'),
(7, 2, 1, 'WEET ONE TOPIC OVERVIEW', '<h2 style=\"box-sizing: border-box; margin-top: 0px; margin-bottom: 0.5rem; font-weight: 500; line-height: 1.2; font-size: 2rem; color: rgb(33, 37, 41); font-family: system-ui, -apple-system, &quot;Segoe UI&quot;, Roboto, &quot;Helvetica Neue&quot;, Arial, &quot;Noto Sans&quot;, &quot;Liberation Sans&quot;, sans-serif, &quot;Apple Color Emoji&quot;, &quot;Segoe UI Emoji&quot;, &quot;Segoe UI Symbol&quot;, &quot;Noto Color Emoji&quot;; font-style: normal; font-variant-ligatures: normal; font-variant-caps: normal; letter-spacing: normal; orphans: 2; text-align: start; text-indent: 0px; text-transform: none; widows: 2; word-spacing: 0px; -webkit-text-stroke-width: 0px; white-space: normal; background-color: rgb(248, 249, 250); text-decoration-thickness: initial; text-decoration-style: initial; text-decoration-color: initial;\">Manage Program of Study</h2><p><br></p>', 'text', NULL, NULL, 1, 0, '2026-01-08 23:30:37'),
(8, 10, 16, 'English for Academic Purpose', 'http://localhost/vle_system/lecturer/dashboard.php', 'text', NULL, NULL, 1, 0, '2026-01-10 23:11:41'),
(9, 10, 16, 'English for Academic Purpose', 'http://localhost/vle_system/lecturer/dashboard.php', 'text', NULL, NULL, 1, 0, '2026-01-10 23:12:05'),
(10, 10, 1, 'TOPIC OVERVIEW WEEK 1: INtroduction to EAP', '<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Contradictions Regarding Interview Advantages</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The claim about flexibility being universally advantageous is contested.</strong> Research by Brinkmann (2014) argues that excessive flexibility in interviews can actually undermine data quality by introducing inconsistency across participants, making systematic comparison difficult. The very flexibility praised as a strength can become a methodological weakness when researchers need standardized data.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The assertion that private settings encourage openness is oversimplified.</strong> Karnieli-Miller et al. (2009) found that one-on-one interview settings can actually <em>inhibit</em> disclosure, particularly when discussing sensitive topics, due to the intensity of the researcher\'s gaze and the lack of peer normalization. Focus groups sometimes elicit more honest responses about sensitive topics because participants hear others share similar experiences first.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The depth advantage is not always realized.</strong> Hollway and Jefferson (2000) demonstrated that interviews often produce socially desirable narratives rather than \"deep\" insights, as participants perform idealized versions of themselves. The assumption that interviews naturally access deeper meaning has been challenged by discourse analysts who show that interview talk is performative rather than revelatory.</p>\r\n<h2 class=\"text-text-100 mt-3 -mb-1 text-[1.125rem] font-bold\">Contradictions Regarding Limitations</h2>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>The resource-intensive claim ignores comparative context.</strong> While interviews do require time, large-scale survey research with comparable sample sizes often requires <em>more</em> resources for development, pilot testing, distribution, and analysis (Fowler, 2013). The characterization of interviews as uniquely resource-intensive is misleading.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\"><strong>Interviewer bias may be less problematic than survey bias.</strong> Schaeffer and Dykema (2011) found that survey question wording and ordering effects can introduce more systematic bias than interviewer effects, which tend to be idiosyncratic and therefore potentially identifiable. The passage presents interviewer bias as a unique limitation when all methods involve researcher influence.</p>\r\n<p class=\"font-claude-response-body break-words whitespace-normal leading-[1.7]\">The passage presents a somewhat simplified view that doesn\'t fully engage with the methodological complexity and ongoing debates about interview methods in qualitative research.</p><p><br></p>', 'text', NULL, NULL, 1, 0, '2026-01-10 23:14:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `administrative_staff`
--
ALTER TABLE `administrative_staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `course_registration_requests`
--
ALTER TABLE `course_registration_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_course` (`course_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD KEY `idx_faculty_id` (`faculty_id`);

--
-- Indexes for table `faculties`
--
ALTER TABLE `faculties`
  ADD PRIMARY KEY (`faculty_id`),
  ADD UNIQUE KEY `faculty_code` (`faculty_code`),
  ADD KEY `idx_faculty_code` (`faculty_code`),
  ADD KEY `idx_faculty_name` (`faculty_name`);

--
-- Indexes for table `fee_settings`
--
ALTER TABLE `fee_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `finance_audit_log`
--
ALTER TABLE `finance_audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_action_type` (`action_type`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_student` (`target_student_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `lecturers`
--
ALTER TABLE `lecturers`
  ADD PRIMARY KEY (`lecturer_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`module_id`),
  ADD UNIQUE KEY `module_code` (`module_code`),
  ADD KEY `idx_program` (`program_of_study`),
  ADD KEY `idx_year` (`year_of_study`),
  ADD KEY `idx_semester` (`semester`);

--
-- Indexes for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_entered_by` (`entered_by`),
  ADD KEY `transaction_id` (`transaction_id`);

--
-- Indexes for table `payment_submissions`
--
ALTER TABLE `payment_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD KEY `idx_student_status` (`student_id`,`status`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_payment_date` (`payment_date`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD UNIQUE KEY `program_code` (`program_code`),
  ADD KEY `idx_program_code` (`program_code`),
  ADD KEY `idx_department` (`department_id`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `semester_courses`
--
ALTER TABLE `semester_courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_course_semester` (`course_id`,`semester`,`academic_year`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `student_finances`
--
ALTER TABLE `student_finances`
  ADD PRIMARY KEY (`finance_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_payment_percentage` (`payment_percentage`);

--
-- Indexes for table `university_settings`
--
ALTER TABLE `university_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vle_announcements`
--
ALTER TABLE `vle_announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `vle_assignments`
--
ALTER TABLE `vle_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `vle_courses`
--
ALTER TABLE `vle_courses`
  ADD PRIMARY KEY (`course_id`),
  ADD UNIQUE KEY `course_code` (`course_code`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `vle_enrollments`
--
ALTER TABLE `vle_enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `student_id` (`student_id`,`course_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `vle_forums`
--
ALTER TABLE `vle_forums`
  ADD PRIMARY KEY (`forum_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `vle_forum_posts`
--
ALTER TABLE `vle_forum_posts`
  ADD PRIMARY KEY (`post_id`),
  ADD KEY `forum_id` (`forum_id`),
  ADD KEY `parent_post_id` (`parent_post_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `vle_grades`
--
ALTER TABLE `vle_grades`
  ADD PRIMARY KEY (`grade_id`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `vle_messages`
--
ALTER TABLE `vle_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `recipient_type` (`recipient_type`,`recipient_id`),
  ADD KEY `sender_type` (`sender_type`,`sender_id`);

--
-- Indexes for table `vle_progress`
--
ALTER TABLE `vle_progress`
  ADD PRIMARY KEY (`progress_id`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `content_id` (`content_id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `vle_submissions`
--
ALTER TABLE `vle_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD UNIQUE KEY `assignment_id` (`assignment_id`,`student_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `graded_by` (`graded_by`);

--
-- Indexes for table `vle_weekly_content`
--
ALTER TABLE `vle_weekly_content`
  ADD PRIMARY KEY (`content_id`),
  ADD KEY `course_id` (`course_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `administrative_staff`
--
ALTER TABLE `administrative_staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `course_registration_requests`
--
ALTER TABLE `course_registration_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `faculties`
--
ALTER TABLE `faculties`
  MODIFY `faculty_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `fee_settings`
--
ALTER TABLE `fee_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `finance_audit_log`
--
ALTER TABLE `finance_audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lecturers`
--
ALTER TABLE `lecturers`
  MODIFY `lecturer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `module_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_submissions`
--
ALTER TABLE `payment_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `semester_courses`
--
ALTER TABLE `semester_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `student_finances`
--
ALTER TABLE `student_finances`
  MODIFY `finance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `university_settings`
--
ALTER TABLE `university_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `vle_announcements`
--
ALTER TABLE `vle_announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vle_assignments`
--
ALTER TABLE `vle_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vle_courses`
--
ALTER TABLE `vle_courses`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `vle_enrollments`
--
ALTER TABLE `vle_enrollments`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `vle_forums`
--
ALTER TABLE `vle_forums`
  MODIFY `forum_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vle_forum_posts`
--
ALTER TABLE `vle_forum_posts`
  MODIFY `post_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vle_grades`
--
ALTER TABLE `vle_grades`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vle_messages`
--
ALTER TABLE `vle_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vle_progress`
--
ALTER TABLE `vle_progress`
  MODIFY `progress_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vle_submissions`
--
ALTER TABLE `vle_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vle_weekly_content`
--
ALTER TABLE `vle_weekly_content`
  MODIFY `content_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `course_registration_requests`
--
ALTER TABLE `course_registration_requests`
  ADD CONSTRAINT `course_registration_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_registration_requests_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`) ON DELETE SET NULL;

--
-- Constraints for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  ADD CONSTRAINT `payment_approvals_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_submissions`
--
ALTER TABLE `payment_submissions`
  ADD CONSTRAINT `payment_submissions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `semester_courses`
--
ALTER TABLE `semester_courses`
  ADD CONSTRAINT `semester_courses_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `vle_announcements`
--
ALTER TABLE `vle_announcements`
  ADD CONSTRAINT `vle_announcements_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vle_announcements_ibfk_2` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`lecturer_id`) ON DELETE CASCADE;

--
-- Constraints for table `vle_assignments`
--
ALTER TABLE `vle_assignments`
  ADD CONSTRAINT `vle_assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`);

--
-- Constraints for table `vle_courses`
--
ALTER TABLE `vle_courses`
  ADD CONSTRAINT `vle_courses_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`lecturer_id`);

--
-- Constraints for table `vle_enrollments`
--
ALTER TABLE `vle_enrollments`
  ADD CONSTRAINT `vle_enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vle_enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`);

--
-- Constraints for table `vle_forums`
--
ALTER TABLE `vle_forums`
  ADD CONSTRAINT `vle_forums_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`);

--
-- Constraints for table `vle_forum_posts`
--
ALTER TABLE `vle_forum_posts`
  ADD CONSTRAINT `vle_forum_posts_ibfk_1` FOREIGN KEY (`forum_id`) REFERENCES `vle_forums` (`forum_id`),
  ADD CONSTRAINT `vle_forum_posts_ibfk_2` FOREIGN KEY (`parent_post_id`) REFERENCES `vle_forum_posts` (`post_id`),
  ADD CONSTRAINT `vle_forum_posts_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `vle_grades`
--
ALTER TABLE `vle_grades`
  ADD CONSTRAINT `vle_grades_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `vle_enrollments` (`enrollment_id`),
  ADD CONSTRAINT `vle_grades_ibfk_2` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`);

--
-- Constraints for table `vle_progress`
--
ALTER TABLE `vle_progress`
  ADD CONSTRAINT `vle_progress_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `vle_enrollments` (`enrollment_id`),
  ADD CONSTRAINT `vle_progress_ibfk_2` FOREIGN KEY (`content_id`) REFERENCES `vle_weekly_content` (`content_id`),
  ADD CONSTRAINT `vle_progress_ibfk_3` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`);

--
-- Constraints for table `vle_submissions`
--
ALTER TABLE `vle_submissions`
  ADD CONSTRAINT `vle_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `vle_assignments` (`assignment_id`),
  ADD CONSTRAINT `vle_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `vle_submissions_ibfk_3` FOREIGN KEY (`graded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `vle_weekly_content`
--
ALTER TABLE `vle_weekly_content`
  ADD CONSTRAINT `vle_weekly_content_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `vle_courses` (`course_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
