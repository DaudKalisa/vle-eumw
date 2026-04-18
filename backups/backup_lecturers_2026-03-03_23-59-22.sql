-- VLE Category Backup: Lecturers
-- Generated: 2026-03-03 23:59:22
-- Database: university_portal
-- Category: lecturers

SET FOREIGN_KEY_CHECKS = 0;

-- Table: lecturers
DROP TABLE IF EXISTS `lecturers`;
CREATE TABLE `lecturers` (
  `lecturer_id` int(11) NOT NULL AUTO_INCREMENT,
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
  `finance_role` varchar(20) DEFAULT NULL COMMENT 'Finance sub-roles: finance_entry, finance_approval, finance_manager',
  `nrc` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`lecturer_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('14', 'Goodman Philimon Mwanza', 'pmwanza@exploitsmw.com', NULL, '+265999342411', NULL, NULL, 'ICT Officer', NULL, '1', NULL, NULL, NULL, 'Male', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('15', 'Barnard Yunusu', 'byunusu@exploitsmw.com', NULL, '+265995879992', NULL, NULL, 'Administrator', NULL, '1', 'Head Office', NULL, NULL, 'Male', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('24', 'Kelvin Admin', 'kadmin@exploitsonline.com', NULL, '+265888120022', NULL, NULL, 'Administrator', NULL, '1', NULL, NULL, NULL, 'Female', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('25', 'Admin dmin-kalisa', 'admin-kalisa@exploitsonline.com', NULL, '', NULL, NULL, 'Administrator', NULL, '1', 'Head Office', NULL, NULL, 'Male', 'staff', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('29', 'Dyson Chmbula Phiri', 'daudphiri@live.com', NULL, '+265983177606', 'BCD', 'Community Development', 'Lecturer', NULL, '1', 'Lilongwe Campus', 'Master of Community development', NULL, NULL, 'lecturer', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('32', 'Prof. Mary Phiri', 'mary.phiri@eumw.ac.mw', NULL, '+265 999 111 002', 'Accounting & Finance', NULL, 'Professor', '2026-03-01', '1', NULL, NULL, NULL, NULL, 'lecturer', NULL, NULL);
INSERT INTO `lecturers` (`lecturer_id`, `full_name`, `email`, `password`, `phone`, `department`, `program`, `position`, `hire_date`, `is_active`, `office`, `bio`, `profile_picture`, `gender`, `role`, `finance_role`, `nrc`) VALUES ('33', 'Mr. James Mwale', 'james.mwale@eumw.ac.mw', NULL, '+265 999 111 003', 'Economics', NULL, 'Lecturer', '2026-03-01', '1', NULL, NULL, NULL, NULL, 'lecturer', NULL, NULL);

SET FOREIGN_KEY_CHECKS = 1;
