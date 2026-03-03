<?php
// admin/database_manager.php - Database Backup & Import Tool
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Create backups directory if not exists
$backup_dir = '../backups/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$message = '';
$error = '';

// Handle backup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Create Backup
    if ($_POST['action'] === 'backup') {
        try {
            $tables = [];
            $result = $conn->query("SHOW TABLES");
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backup_dir . $filename;
            
            $sql_content = "-- VLE Database Backup\n";
            $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql_content .= "-- Database: " . DB_NAME . "\n\n";
            $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n";
            $sql_content .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
            
            foreach ($tables as $table) {
                // Get table structure
                $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                if ($row = $create_result->fetch_assoc()) {
                    $sql_content .= "-- Table: $table\n";
                    $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                    $sql_content .= $row['Create Table'] . ";\n\n";
                }
                
                // Get table data
                $data_result = $conn->query("SELECT * FROM `$table`");
                if ($data_result && $data_result->num_rows > 0) {
                    $columns = [];
                    $fields = $data_result->fetch_fields();
                    foreach ($fields as $field) {
                        $columns[] = "`{$field->name}`";
                    }
                    
                    while ($row = $data_result->fetch_assoc()) {
                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = "'" . $conn->real_escape_string($value) . "'";
                            }
                        }
                        $sql_content .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
                    }
                    $sql_content .= "\n";
                }
            }
            
            $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            
            if (file_put_contents($filepath, $sql_content)) {
                $message = "Backup created successfully: <strong>$filename</strong> (" . number_format(strlen($sql_content)) . " bytes)";
            } else {
                $error = "Failed to write backup file.";
            }
            
        } catch (Exception $e) {
            $error = "Backup failed: " . $e->getMessage();
        }
    }
    
    // Import/Restore Database
    if ($_POST['action'] === 'import' && isset($_FILES['sql_file'])) {
        if ($_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
            $uploaded_file = $_FILES['sql_file']['tmp_name'];
            $original_name = $_FILES['sql_file']['name'];
            
            // Validate file extension
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $error = "Invalid file type. Only .sql files are allowed.";
            } else {
                $sql_content = file_get_contents($uploaded_file);
                
                if (empty($sql_content)) {
                    $error = "SQL file is empty.";
                } else {
                    // Disable foreign key checks and exception mode
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                    mysqli_report(MYSQLI_REPORT_OFF); // Suppress exceptions for import
                    
                    // Better SQL splitting - handle statements properly
                    $sql_content = preg_replace('/^--.*$/m', '', $sql_content); // Remove single-line comments
                    $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content); // Remove multi-line comments
                    
                    // Split on semicolons but be careful with CREATE TABLE statements
                    $statements = [];
                    $current = '';
                    $lines = explode("\n", $sql_content);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $current .= ' ' . $line;
                        if (substr($line, -1) === ';') {
                            $statements[] = trim($current);
                            $current = '';
                        }
                    }
                    if (!empty(trim($current))) {
                        $statements[] = trim($current);
                    }
                    
                    $success_count = 0;
                    $error_count = 0;
                    $skipped_count = 0;
                    $errors = [];
                    
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        if (empty($statement)) continue;
                        
                        // Handle CREATE TABLE - drop table first if exists
                        if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                            $table_name = $matches[1];
                            @$conn->query("DROP TABLE IF EXISTS `$table_name`");
                        }
                        
                        // Convert INSERT INTO to INSERT IGNORE INTO
                        $statement = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement);
                        
                        try {
                            if (@$conn->query($statement)) {
                                $success_count++;
                            } else {
                                // Check if it's a "table already exists" or "duplicate" error - skip these
                                $err = $conn->error;
                                if (strpos($err, 'already exists') !== false || strpos($err, 'Duplicate') !== false) {
                                    $skipped_count++;
                                } else {
                                    $error_count++;
                                    if (count($errors) < 5 && $err) {
                                        $errors[] = substr($err, 0, 100);
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            $msg = $e->getMessage();
                            if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {
                                $skipped_count++;
                            } else {
                                $error_count++;
                                if (count($errors) < 5) {
                                    $errors[] = substr($msg, 0, 100);
                                }
                            }
                        }
                    }
                    
                    // Re-enable foreign key checks and exception mode
                    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    
                    if ($error_count === 0) {
                        $message = "Import successful! Executed: $success_count" . ($skipped_count > 0 ? ", Skipped duplicates: $skipped_count" : "");
                    } else {
                        $message = "Import completed. Success: $success_count, Errors: $error_count" . ($skipped_count > 0 ? ", Skipped: $skipped_count" : "");
                        if (!empty($errors)) {
                            $error = "Sample errors: " . implode("; ", $errors);
                        }
                    }
                }
            }
        } else {
            $error = "File upload failed. Error code: " . $_FILES['sql_file']['error'];
        }
    }
    
    // Delete backup
    if ($_POST['action'] === 'delete' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']); // Prevent directory traversal
        $filepath = $backup_dir . $filename;
        
        if (file_exists($filepath) && is_file($filepath)) {
            if (unlink($filepath)) {
                $message = "Backup deleted: $filename";
            } else {
                $error = "Failed to delete backup.";
            }
        } else {
            $error = "Backup file not found.";
        }
    }
    
    // Truncate tables by category
    if ($_POST['action'] === 'truncate_category') {
        $category = $_POST['category'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';
        
        // Define table categories
        $categories = [
            'students' => [
                'label' => 'Students',
                'tables' => ['students', 'student_finances', 'student_invite_registrations', 'student_registration_invites', 'student_access_logs'],
                'icon' => 'bi-people',
                'color' => 'primary',
                'description' => 'All student records, finances, invites and registrations'
            ],
            'lecturers' => [
                'label' => 'Lecturers',
                'tables' => ['lecturers'],
                'icon' => 'bi-person-workspace',
                'color' => 'info',
                'description' => 'All lecturer records'
            ],
            'courses_content' => [
                'label' => 'Courses & Content',
                'tables' => ['vle_courses', 'vle_weekly_content', 'vle_enrollments', 'vle_progress', 'vle_download_requests'],
                'icon' => 'bi-journal-text',
                'color' => 'success',
                'description' => 'Courses, weekly content, enrollments and progress'
            ],
            'assignments' => [
                'label' => 'Assignments & Submissions',
                'tables' => ['vle_assignments', 'vle_submissions', 'vle_grades'],
                'icon' => 'bi-file-earmark-check',
                'color' => 'warning',
                'description' => 'Assignments, student submissions and grades'
            ],
            'exams' => [
                'label' => 'Examinations',
                'tables' => ['exams', 'exam_questions', 'exam_tokens', 'exam_sessions', 'exam_answers', 'exam_results', 'exam_monitoring', 'examination_managers'],
                'icon' => 'bi-clipboard2-check',
                'color' => 'danger',
                'description' => 'Exams, questions, tokens, sessions, answers, results and monitoring'
            ],
            'quizzes' => [
                'label' => 'Quizzes',
                'tables' => ['vle_quizzes', 'vle_quiz_questions', 'vle_quiz_attempts', 'vle_quiz_answers'],
                'icon' => 'bi-question-circle',
                'color' => 'purple',
                'description' => 'Quizzes, questions, attempts and answers'
            ],
            'forums' => [
                'label' => 'Forums & Discussions',
                'tables' => ['vle_forums', 'vle_forum_posts'],
                'icon' => 'bi-chat-dots',
                'color' => 'secondary',
                'description' => 'Discussion forums and posts'
            ],
            'messages' => [
                'label' => 'Messages & Notifications',
                'tables' => ['vle_messages', 'vle_notifications'],
                'icon' => 'bi-envelope',
                'color' => 'dark',
                'description' => 'Internal messages and notifications'
            ],
            'live_sessions' => [
                'label' => 'Live Sessions',
                'tables' => ['vle_live_sessions', 'vle_session_participants', 'vle_session_invites', 'vle_webrtc_signals', 'vle_session_chat', 'vle_session_peers'],
                'icon' => 'bi-camera-video',
                'color' => 'danger',
                'description' => 'Live classes, participants, WebRTC signals and chat'
            ],
            'attendance' => [
                'label' => 'Attendance',
                'tables' => ['attendance_sessions', 'attendance_records'],
                'icon' => 'bi-calendar-check',
                'color' => 'info',
                'description' => 'Attendance sessions and records'
            ],
            'finance' => [
                'label' => 'Finance & Payments',
                'tables' => ['student_finances', 'payment_transactions', 'fee_settings', 'finance_users'],
                'icon' => 'bi-cash-coin',
                'color' => 'success',
                'description' => 'Student finances, payment transactions and fee settings'
            ],
            'login_security' => [
                'label' => 'Login & Security Logs',
                'tables' => ['login_attempts', 'login_history', 'account_locks'],
                'icon' => 'bi-shield-lock',
                'color' => 'secondary',
                'description' => 'Login attempts, history and account locks'
            ],
            'users' => [
                'label' => 'User Accounts',
                'tables' => ['users'],
                'icon' => 'bi-person-gear',
                'color' => 'danger',
                'description' => 'All user login accounts (WARNING: will lock everyone out!)'
            ],
        ];
        
        if (!isset($categories[$category])) {
            $error = 'Invalid category selected.';
        } elseif (empty($admin_password)) {
            $error = 'You must enter your admin password to confirm truncation.';
        } else {
            // Verify admin password
            $uid = $_SESSION['user_id'] ?? 0;
            $pw_stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $pw_stmt->bind_param('i', $uid);
            $pw_stmt->execute();
            $pw_result = $pw_stmt->get_result();
            $pw_user = $pw_result->fetch_assoc();
            $pw_stmt->close();
            
            if (!$pw_user || !password_verify($admin_password, $pw_user['password_hash'])) {
                $error = 'Incorrect password. Truncation cancelled.';
            } else {
            $cat = $categories[$category];
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $truncated = 0;
            $skipped = 0;
            foreach ($cat['tables'] as $table) {
                $check = $conn->query("SHOW TABLES LIKE '$table'");
                if ($check && $check->num_rows > 0) {
                    if ($conn->query("TRUNCATE TABLE `$table`")) {
                        $truncated++;
                    }
                } else {
                    $skipped++;
                }
            }
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $message = "<strong>{$cat['label']}</strong> truncated! $truncated table(s) cleared" . ($skipped > 0 ? ", $skipped table(s) not found (skipped)" : '') . '.';
            }
        }
    }

    // Backup category tables
    if ($_POST['action'] === 'backup_category') {
        $category = $_POST['category'] ?? '';
        
        // Re-use the truncate_categories definition
        $categories = [
            'students' => ['label' => 'Students', 'tables' => ['students', 'student_finances', 'student_invite_registrations', 'student_registration_invites', 'student_access_logs']],
            'lecturers' => ['label' => 'Lecturers', 'tables' => ['lecturers']],
            'courses_content' => ['label' => 'Courses & Content', 'tables' => ['vle_courses', 'vle_weekly_content', 'vle_enrollments', 'vle_progress', 'vle_download_requests']],
            'assignments' => ['label' => 'Assignments & Submissions', 'tables' => ['vle_assignments', 'vle_submissions', 'vle_grades']],
            'exams' => ['label' => 'Examinations', 'tables' => ['exams', 'exam_questions', 'exam_tokens', 'exam_sessions', 'exam_answers', 'exam_results', 'exam_monitoring', 'examination_managers']],
            'quizzes' => ['label' => 'Quizzes', 'tables' => ['vle_quizzes', 'vle_quiz_questions', 'vle_quiz_attempts', 'vle_quiz_answers']],
            'forums' => ['label' => 'Forums & Discussions', 'tables' => ['vle_forums', 'vle_forum_posts']],
            'messages' => ['label' => 'Messages & Notifications', 'tables' => ['vle_messages', 'vle_notifications']],
            'live_sessions' => ['label' => 'Live Sessions', 'tables' => ['vle_live_sessions', 'vle_session_participants', 'vle_session_invites', 'vle_webrtc_signals', 'vle_session_chat', 'vle_session_peers']],
            'attendance' => ['label' => 'Attendance', 'tables' => ['attendance_sessions', 'attendance_records']],
            'finance' => ['label' => 'Finance & Payments', 'tables' => ['student_finances', 'payment_transactions', 'fee_settings', 'finance_users']],
            'login_security' => ['label' => 'Login & Security Logs', 'tables' => ['login_attempts', 'login_history', 'account_locks']],
            'users' => ['label' => 'User Accounts', 'tables' => ['users']],
        ];
        
        if (isset($categories[$category])) {
            $cat = $categories[$category];
            $filename = 'backup_' . $category . '_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backup_dir . $filename;
            
            $sql_content = "-- VLE Category Backup: {$cat['label']}\n";
            $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql_content .= "-- Database: " . DB_NAME . "\n";
            $sql_content .= "-- Category: $category\n\n";
            $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            
            $backed_up = 0;
            foreach ($cat['tables'] as $table) {
                $check = $conn->query("SHOW TABLES LIKE '$table'");
                if ($check && $check->num_rows > 0) {
                    $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                    if ($row = $create_result->fetch_assoc()) {
                        $sql_content .= "-- Table: $table\n";
                        $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                        $sql_content .= $row['Create Table'] . ";\n\n";
                    }
                    $data_result = $conn->query("SELECT * FROM `$table`");
                    if ($data_result && $data_result->num_rows > 0) {
                        $columns = [];
                        $fields = $data_result->fetch_fields();
                        foreach ($fields as $field) {
                            $columns[] = "`{$field->name}`";
                        }
                        while ($row = $data_result->fetch_assoc()) {
                            $values = [];
                            foreach ($row as $value) {
                                if ($value === null) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                                }
                            }
                            $sql_content .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
                        }
                        $sql_content .= "\n";
                    }
                    $backed_up++;
                }
            }
            
            $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            
            if (file_put_contents($filepath, $sql_content)) {
                $message = "<strong>{$cat['label']}</strong> backup created: <strong>$filename</strong> ($backed_up table(s), " . number_format(strlen($sql_content)) . " bytes)";
            } else {
                $error = "Failed to write backup file.";
            }
        } else {
            $error = 'Invalid category for backup.';
        }
    }
    
    // Restore category from uploaded SQL file
    if ($_POST['action'] === 'restore_category') {
        $category = $_POST['category'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';
        
        $categories = [
            'students' => ['label' => 'Students', 'tables' => ['students', 'student_finances', 'student_invite_registrations', 'student_registration_invites', 'student_access_logs']],
            'lecturers' => ['label' => 'Lecturers', 'tables' => ['lecturers']],
            'courses_content' => ['label' => 'Courses & Content', 'tables' => ['vle_courses', 'vle_weekly_content', 'vle_enrollments', 'vle_progress', 'vle_download_requests']],
            'assignments' => ['label' => 'Assignments & Submissions', 'tables' => ['vle_assignments', 'vle_submissions', 'vle_grades']],
            'exams' => ['label' => 'Examinations', 'tables' => ['exams', 'exam_questions', 'exam_tokens', 'exam_sessions', 'exam_answers', 'exam_results', 'exam_monitoring', 'examination_managers']],
            'quizzes' => ['label' => 'Quizzes', 'tables' => ['vle_quizzes', 'vle_quiz_questions', 'vle_quiz_attempts', 'vle_quiz_answers']],
            'forums' => ['label' => 'Forums & Discussions', 'tables' => ['vle_forums', 'vle_forum_posts']],
            'messages' => ['label' => 'Messages & Notifications', 'tables' => ['vle_messages', 'vle_notifications']],
            'live_sessions' => ['label' => 'Live Sessions', 'tables' => ['vle_live_sessions', 'vle_session_participants', 'vle_session_invites', 'vle_webrtc_signals', 'vle_session_chat', 'vle_session_peers']],
            'attendance' => ['label' => 'Attendance', 'tables' => ['attendance_sessions', 'attendance_records']],
            'finance' => ['label' => 'Finance & Payments', 'tables' => ['student_finances', 'payment_transactions', 'fee_settings', 'finance_users']],
            'login_security' => ['label' => 'Login & Security Logs', 'tables' => ['login_attempts', 'login_history', 'account_locks']],
            'users' => ['label' => 'User Accounts', 'tables' => ['users']],
        ];
        
        if (!isset($categories[$category])) {
            $error = 'Invalid category for restore.';
        } elseif (empty($admin_password)) {
            $error = 'You must enter your admin password to confirm restore.';
        } elseif (!isset($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a .sql backup file to restore.';
        } else {
            // Verify admin password
            $uid = $_SESSION['user_id'] ?? 0;
            $pw_stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $pw_stmt->bind_param('i', $uid);
            $pw_stmt->execute();
            $pw_result = $pw_stmt->get_result();
            $pw_user = $pw_result->fetch_assoc();
            $pw_stmt->close();
            
            if (!$pw_user || !password_verify($admin_password, $pw_user['password_hash'])) {
                $error = 'Incorrect password. Restore cancelled.';
            } else {
                $ext = strtolower(pathinfo($_FILES['restore_file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'sql') {
                    $error = 'Invalid file type. Only .sql files are allowed.';
                } else {
                    $sql_content = file_get_contents($_FILES['restore_file']['tmp_name']);
                    if (empty($sql_content)) {
                        $error = 'SQL file is empty.';
                    } else {
                        $cat = $categories[$category];
                        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                        mysqli_report(MYSQLI_REPORT_OFF);
                        
                        $sql_content = preg_replace('/^--.*$/m', '', $sql_content);
                        $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);
                        
                        $statements = [];
                        $current = '';
                        $lines = explode("\n", $sql_content);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (empty($line)) continue;
                            $current .= ' ' . $line;
                            if (substr($line, -1) === ';') {
                                $statements[] = trim($current);
                                $current = '';
                            }
                        }
                        if (!empty(trim($current))) {
                            $statements[] = trim($current);
                        }
                        
                        $success_count = 0;
                        $error_count = 0;
                        $skipped_count = 0;
                        
                        foreach ($statements as $statement) {
                            $statement = trim($statement);
                            if (empty($statement)) continue;
                            
                            // Only process statements for tables in this category
                            $is_relevant = false;
                            foreach ($cat['tables'] as $t) {
                                if (stripos($statement, "`$t`") !== false || stripos($statement, " $t ") !== false || stripos($statement, " $t;") !== false) {
                                    $is_relevant = true;
                                    break;
                                }
                            }
                            // Also allow SET statements
                            if (preg_match('/^SET\s+/i', $statement)) $is_relevant = true;
                            
                            if (!$is_relevant) { $skipped_count++; continue; }
                            
                            if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                                @$conn->query("DROP TABLE IF EXISTS `{$matches[1]}`");
                            }
                            $statement = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement);
                            
                            try {
                                if (@$conn->query($statement)) {
                                    $success_count++;
                                } else {
                                    $err = $conn->error;
                                    if (strpos($err, 'already exists') !== false || strpos($err, 'Duplicate') !== false) {
                                        $skipped_count++;
                                    } else {
                                        $error_count++;
                                    }
                                }
                            } catch (Exception $e) {
                                $msg = $e->getMessage();
                                if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {
                                    $skipped_count++;
                                } else {
                                    $error_count++;
                                }
                            }
                        }
                        
                        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                        
                        if ($error_count === 0) {
                            $message = "<strong>{$cat['label']}</strong> restored successfully! Executed: $success_count" . ($skipped_count > 0 ? ", Skipped: $skipped_count" : '');
                        } else {
                            $message = "<strong>{$cat['label']}</strong> restore completed. Success: $success_count, Errors: $error_count" . ($skipped_count > 0 ? ", Skipped: $skipped_count" : '');
                        }
                    }
                }
            }
        }
    }

    // Download backup
    if ($_POST['action'] === 'download' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $filepath = $backup_dir . $filename;
        
        if (file_exists($filepath) && is_file($filepath)) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        }
    }
    
    // Restore from existing backup
    if ($_POST['action'] === 'restore' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $filepath = $backup_dir . $filename;
        
        if (file_exists($filepath) && is_file($filepath)) {
            $sql_content = file_get_contents($filepath);
            
            if (!empty($sql_content)) {
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                mysqli_report(MYSQLI_REPORT_OFF); // Suppress exceptions
                
                // Clean and parse SQL
                $sql_content = preg_replace('/^--.*$/m', '', $sql_content);
                $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);
                
                $statements = [];
                $current = '';
                $lines = explode("\n", $sql_content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $current .= ' ' . $line;
                    if (substr($line, -1) === ';') {
                        $statements[] = trim($current);
                        $current = '';
                    }
                }
                if (!empty(trim($current))) {
                    $statements[] = trim($current);
                }
                
                $success_count = 0;
                $error_count = 0;
                $skipped_count = 0;
                
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (empty($statement)) continue;
                    
                    // Handle CREATE TABLE - drop table first
                    if (preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                        $table_name = $matches[1];
                        @$conn->query("DROP TABLE IF EXISTS `$table_name`");
                    }
                    
                    // Convert INSERT INTO to INSERT IGNORE INTO
                    $statement = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement);
                    
                    try {
                        if (@$conn->query($statement)) {
                            $success_count++;
                        } else {
                            $err = $conn->error;
                            if (strpos($err, 'already exists') !== false || strpos($err, 'Duplicate') !== false) {
                                $skipped_count++;
                            } else {
                                $error_count++;
                            }
                        }
                    } catch (Exception $e) {
                        $msg = $e->getMessage();
                        if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {
                            $skipped_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
                
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                
                if ($error_count === 0) {
                    $message = "Database restored from '$filename'! Executed: $success_count" . ($skipped_count > 0 ? ", Skipped duplicates: $skipped_count" : "");
                } else {
                    $message = "Restore completed. Success: $success_count, Errors: $error_count" . ($skipped_count > 0 ? ", Skipped: $skipped_count" : "");
                }
            }
        } else {
            $error = "Backup file not found: $filename";
        }
    }
}

// Get existing backups
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $filepath = $backup_dir . $file;
            $backups[] = [
                'filename' => $file,
                'size' => filesize($filepath),
                'date' => filemtime($filepath),
            ];
        }
    }
}

// Get database stats
$db_stats = [];
$table_details = [];
$result = $conn->query("SHOW TABLE STATUS");
$total_size = 0;
$total_rows = 0;
$table_count = 0;
while ($row = $result->fetch_assoc()) {
    $total_size += $row['Data_length'] + $row['Index_length'];
    $total_rows += $row['Rows'];
    $table_count++;
    $table_details[$row['Name']] = ['rows' => $row['Rows'], 'size' => $row['Data_length'] + $row['Index_length']];
}
$db_stats['tables'] = $table_count;
$db_stats['rows'] = $total_rows;
$db_stats['size'] = $total_size;

// Truncate categories definition (for the UI)
$truncate_categories = [
    'students' => ['label' => 'Students', 'tables' => ['students', 'student_finances', 'student_invite_registrations', 'student_registration_invites', 'student_access_logs'], 'icon' => 'bi-people', 'color' => '#4f46e5', 'description' => 'All student records, finances, invites and registrations'],
    'lecturers' => ['label' => 'Lecturers', 'tables' => ['lecturers'], 'icon' => 'bi-person-workspace', 'color' => '#0891b2', 'description' => 'All lecturer records'],
    'courses_content' => ['label' => 'Courses & Content', 'tables' => ['vle_courses', 'vle_weekly_content', 'vle_enrollments', 'vle_progress', 'vle_download_requests'], 'icon' => 'bi-journal-text', 'color' => '#059669', 'description' => 'Courses, weekly content, enrollments and progress tracking'],
    'assignments' => ['label' => 'Assignments & Submissions', 'tables' => ['vle_assignments', 'vle_submissions', 'vle_grades'], 'icon' => 'bi-file-earmark-check', 'color' => '#d97706', 'description' => 'Assignments, student submissions and grades'],
    'exams' => ['label' => 'Examinations', 'tables' => ['exams', 'exam_questions', 'exam_tokens', 'exam_sessions', 'exam_answers', 'exam_results', 'exam_monitoring', 'examination_managers'], 'icon' => 'bi-clipboard2-check', 'color' => '#dc2626', 'description' => 'Exams, questions, tokens, sessions, answers, results and monitoring'],
    'quizzes' => ['label' => 'Quizzes', 'tables' => ['vle_quizzes', 'vle_quiz_questions', 'vle_quiz_attempts', 'vle_quiz_answers'], 'icon' => 'bi-question-circle', 'color' => '#7c3aed', 'description' => 'Quizzes, questions, attempts and answers'],
    'forums' => ['label' => 'Forums & Discussions', 'tables' => ['vle_forums', 'vle_forum_posts'], 'icon' => 'bi-chat-dots', 'color' => '#6b7280', 'description' => 'Discussion forums and posts'],
    'messages' => ['label' => 'Messages & Notifications', 'tables' => ['vle_messages', 'vle_notifications'], 'icon' => 'bi-envelope', 'color' => '#374151', 'description' => 'Internal messages and notifications'],
    'live_sessions' => ['label' => 'Live Sessions', 'tables' => ['vle_live_sessions', 'vle_session_participants', 'vle_session_invites', 'vle_webrtc_signals', 'vle_session_chat', 'vle_session_peers'], 'icon' => 'bi-camera-video', 'color' => '#e11d48', 'description' => 'Live classes, participants, WebRTC signals and chat'],
    'attendance' => ['label' => 'Attendance', 'tables' => ['attendance_sessions', 'attendance_records'], 'icon' => 'bi-calendar-check', 'color' => '#0ea5e9', 'description' => 'Attendance sessions and records'],
    'finance' => ['label' => 'Finance & Payments', 'tables' => ['student_finances', 'payment_transactions', 'fee_settings', 'finance_users'], 'icon' => 'bi-cash-coin', 'color' => '#16a34a', 'description' => 'Student finances, payment transactions and fee settings'],
    'login_security' => ['label' => 'Login & Security Logs', 'tables' => ['login_attempts', 'login_history', 'account_locks'], 'icon' => 'bi-shield-lock', 'color' => '#475569', 'description' => 'Login attempts, history and account locks'],
    'users' => ['label' => 'User Accounts', 'tables' => ['users'], 'icon' => 'bi-person-gear', 'color' => '#b91c1c', 'description' => 'All user login accounts (will lock everyone out!)'],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Manager - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .backup-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .backup-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .upload-zone {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #0d6efd;
            background: #f0f7ff;
        }
        .upload-zone input[type="file"] {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2><i class="bi bi-database-gear me-2"></i>Database Manager</h2>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Database Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="bi bi-table fs-1"></i>
                    <h3 class="mt-2"><?= number_format($db_stats['tables']) ?></h3>
                    <p class="mb-0">Tables</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card green">
                    <i class="bi bi-list-ol fs-1"></i>
                    <h3 class="mt-2"><?= number_format($db_stats['rows']) ?></h3>
                    <p class="mb-0">Total Rows</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card orange">
                    <i class="bi bi-hdd fs-1"></i>
                    <h3 class="mt-2"><?= number_format($db_stats['size'] / 1024 / 1024, 2) ?> MB</h3>
                    <p class="mb-0">Database Size</p>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Backup Section -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-download me-2"></i>Create Backup</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Create a complete backup of your database including all tables and data.</p>
                        <form method="POST" id="backupForm">
                            <input type="hidden" name="action" value="backup">
                            <button type="submit" class="btn btn-primary btn-lg w-100" id="backupBtn">
                                <i class="bi bi-cloud-download me-2"></i>Create Backup Now
                            </button>
                        </form>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Backups are stored in the <code>/backups/</code> folder
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Import Section -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-upload me-2"></i>Import Database</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <input type="hidden" name="action" value="import">
                            <div class="upload-zone" id="uploadZone" onclick="document.getElementById('sql_file').click()">
                                <i class="bi bi-cloud-upload fs-1 text-muted"></i>
                                <p class="mb-1 mt-2">Click to browse or drag & drop</p>
                                <small class="text-muted">Only .sql files allowed</small>
                                <input type="file" name="sql_file" id="sql_file" accept=".sql" required>
                            </div>
                            <div id="selectedFile" class="mt-2 d-none">
                                <span class="badge bg-info" id="fileName"></span>
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100 mt-3" id="importBtn" disabled>
                                <i class="bi bi-cloud-upload me-2"></i>Import Database
                            </button>
                        </form>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> Importing will overwrite existing data!
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Existing Backups -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-archive me-2"></i>Existing Backups (<?= count($backups) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-muted"></i>
                        <p class="text-muted mt-2">No backups found. Create your first backup above.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Filename</th>
                                    <th>Size</th>
                                    <th>Date Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td>
                                            <i class="bi bi-file-earmark-code text-primary me-2"></i>
                                            <?= htmlspecialchars($backup['filename']) ?>
                                        </td>
                                        <td><?= number_format($backup['size'] / 1024, 2) ?> KB</td>
                                        <td><?= date('M d, Y H:i:s', $backup['date']) ?></td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="download">
                                                <input type="hidden" name="filename" value="<?= htmlspecialchars($backup['filename']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this backup?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="filename" value="<?= htmlspecialchars($backup['filename']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('This will OVERWRITE your current database. Are you absolutely sure?')">
                                                <input type="hidden" name="action" value="import">
                                                <input type="hidden" name="restore_file" value="<?= htmlspecialchars($backup['filename']) ?>">
                                                <button type="button" class="btn btn-sm btn-outline-warning" title="Restore" 
                                                        onclick="restoreBackup('<?= htmlspecialchars($backup['filename']) ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Management by Category -->
        <div class="card mb-4" id="truncateSection">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Data Management by Category</h5>
                <span class="badge bg-light text-dark"><?= count($truncate_categories) ?> Categories</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4" style="border-radius:10px;">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Use the action buttons on each card to <strong>Backup</strong>, <strong>Restore</strong>, or <strong>Delete</strong> data for specific categories. Password is required for destructive actions.
                </div>
                <div class="row g-3">
                    <?php foreach ($truncate_categories as $cat_key => $cat): 
                        $cat_rows = 0;
                        $cat_tables_found = 0;
                        foreach ($cat['tables'] as $t) {
                            if (isset($table_details[$t])) {
                                $cat_rows += $table_details[$t]['rows'];
                                $cat_tables_found++;
                            }
                        }
                    ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="card h-100 truncate-card" style="border-left: 4px solid <?= $cat['color'] ?>;transition:all 0.2s;"
                             onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 4px 15px rgba(0,0,0,0.1)'"
                             onmouseout="this.style.transform='';this.style.boxShadow=''">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi <?= $cat['icon'] ?>" style="font-size:1.5rem;color:<?= $cat['color'] ?>"></i>
                                    <h6 class="mb-0 fw-bold"><?= $cat['label'] ?></h6>
                                </div>
                                <p class="text-muted mb-2" style="font-size:0.8rem;"><?= $cat['description'] ?></p>
                                <div class="d-flex justify-content-between mb-3" style="font-size:0.75rem;">
                                    <span class="text-muted"><i class="bi bi-table me-1"></i><?= $cat_tables_found ?>/<?= count($cat['tables']) ?> tables</span>
                                    <span class="fw-semibold" style="color:<?= $cat['color'] ?>"><?= number_format($cat_rows) ?> rows</span>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-primary flex-fill" title="Backup this category"
                                            onclick="backupCategory('<?= $cat_key ?>')">
                                        <i class="bi bi-download me-1"></i>Backup
                                    </button>
                                    <button class="btn btn-sm btn-outline-success flex-fill" title="Restore this category"
                                            onclick="openRestoreModal('<?= $cat_key ?>', '<?= htmlspecialchars($cat['label']) ?>', '<?= $cat['icon'] ?>', '<?= $cat['color'] ?>')">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger flex-fill" title="Delete all data in this category"
                                            onclick="openTruncateModal('<?= $cat_key ?>', '<?= htmlspecialchars($cat['label']) ?>', '<?= htmlspecialchars($cat['description']) ?>', '<?= $cat['icon'] ?>', '<?= $cat['color'] ?>', <?= count($cat['tables']) ?>, <?= $cat_rows ?>, '<?= htmlspecialchars(implode(', ', $cat['tables'])) ?>')">
                                        <i class="bi bi-trash3 me-1"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Category Modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden;">
                <div class="modal-header" id="restoreModalHeader" style="background:#059669;color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore Category Data</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i id="restoreModalIcon" class="bi bi-people" style="font-size:3rem;color:#059669;"></i>
                        <h5 class="mt-2 fw-bold" id="restoreModalLabel">Category Name</h5>
                        <p class="text-muted" style="font-size:0.9rem;">Upload a .sql backup file to restore this category's data</p>
                    </div>
                    <div class="alert alert-warning" style="border-radius:10px;font-size:0.85rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        This will <strong>overwrite</strong> existing data in the selected category tables.
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="restoreForm">
                        <input type="hidden" name="action" value="restore_category">
                        <input type="hidden" name="category" id="restoreCategory" value="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Select backup file (.sql):</label>
                            <input type="file" name="restore_file" class="form-control" id="restoreFileInput" accept=".sql" required style="border-radius:10px;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="bi bi-shield-lock me-1"></i>Enter your admin password to confirm:</label>
                            <input type="password" name="admin_password" class="form-control text-center" id="restorePasswordInput"
                                   placeholder="Enter your password" autocomplete="off" style="font-size:1rem;border-radius:10px;">
                        </div>
                        <button type="submit" class="btn btn-success w-100" id="restoreSubmitBtn" disabled style="border-radius:10px;padding:12px;">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Restore Category Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Truncate Confirmation Modal -->
    <div class="modal fade" id="truncateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden;">
                <div class="modal-header" id="truncateModalHeader" style="background:#dc2626;color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Truncation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i id="truncateModalIcon" class="bi bi-people" style="font-size:3rem;color:#dc2626;"></i>
                        <h5 class="mt-2 fw-bold" id="truncateModalLabel">Category Name</h5>
                        <p class="text-muted" id="truncateModalDesc" style="font-size:0.9rem;">Description</p>
                    </div>
                    <div class="alert alert-light" style="border-radius:10px;border:1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                            <span><i class="bi bi-table me-1"></i>Tables affected:</span>
                            <strong id="truncateTableCount">0</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2" style="font-size:0.85rem;">
                            <span><i class="bi bi-list-ol me-1"></i>Rows to be deleted:</span>
                            <strong class="text-danger" id="truncateRowCount">0</strong>
                        </div>
                        <div style="font-size:0.75rem;color:#64748b;" id="truncateTableList">Tables: ...</div>
                    </div>
                    <form method="POST" id="truncateForm">
                        <input type="hidden" name="action" value="truncate_category">
                        <input type="hidden" name="category" id="truncateCategory" value="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="bi bi-shield-lock me-1"></i>Enter your admin password to confirm:</label>
                            <input type="password" name="admin_password" class="form-control text-center" id="truncateConfirmInput"
                                   placeholder="Enter your password" autocomplete="off" style="font-size:1rem;border-radius:10px;">
                        </div>
                        <button type="submit" class="btn btn-danger w-100" id="truncateSubmitBtn" disabled style="border-radius:10px;padding:12px;">
                            <i class="bi bi-trash3 me-2"></i>Permanently Delete All Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Backup category - simple form submit
        function backupCategory(key) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="backup_category"><input type="hidden" name="category" value="' + key + '">';
            document.body.appendChild(form);
            form.submit();
        }

        // Restore modal functions
        function openRestoreModal(key, label, icon, color) {
            document.getElementById('restoreCategory').value = key;
            document.getElementById('restoreModalIcon').className = 'bi ' + icon;
            document.getElementById('restoreModalIcon').style.color = color;
            document.getElementById('restoreModalLabel').textContent = label;
            document.getElementById('restoreModalHeader').style.background = color;
            document.getElementById('restoreFileInput').value = '';
            document.getElementById('restorePasswordInput').value = '';
            document.getElementById('restoreSubmitBtn').disabled = true;
            new bootstrap.Modal(document.getElementById('restoreModal')).show();
        }

        function checkRestoreReady() {
            const hasFile = document.getElementById('restoreFileInput').files.length > 0;
            const hasPass = document.getElementById('restorePasswordInput').value.length > 0;
            document.getElementById('restoreSubmitBtn').disabled = !(hasFile && hasPass);
        }
        document.getElementById('restoreFileInput').addEventListener('change', checkRestoreReady);
        document.getElementById('restorePasswordInput').addEventListener('input', checkRestoreReady);

        document.getElementById('restoreForm').addEventListener('submit', function() {
            const btn = document.getElementById('restoreSubmitBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Restoring...';
            btn.disabled = true;
        });

        // Truncate modal functions
        function openTruncateModal(key, label, desc, icon, color, tableCount, rowCount, tableList) {
            document.getElementById('truncateCategory').value = key;
            document.getElementById('truncateModalIcon').className = 'bi ' + icon;
            document.getElementById('truncateModalIcon').style.color = color;
            document.getElementById('truncateModalLabel').textContent = label;
            document.getElementById('truncateModalDesc').textContent = desc;
            document.getElementById('truncateTableCount').textContent = tableCount;
            document.getElementById('truncateRowCount').textContent = rowCount.toLocaleString();
            document.getElementById('truncateTableList').textContent = 'Tables: ' + tableList;
            document.getElementById('truncateConfirmInput').value = '';
            document.getElementById('truncateSubmitBtn').disabled = true;
            document.getElementById('truncateModalHeader').style.background = color;
            new bootstrap.Modal(document.getElementById('truncateModal')).show();
        }

        document.getElementById('truncateConfirmInput').addEventListener('input', function() {
            document.getElementById('truncateSubmitBtn').disabled = this.value.length === 0;
        });

        document.getElementById('truncateForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('truncateSubmitBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';
            btn.disabled = true;
        });

        // File upload handling
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('sql_file');
        const importBtn = document.getElementById('importBtn');
        const selectedFile = document.getElementById('selectedFile');
        const fileName = document.getElementById('fileName');
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name + ' (' + (this.files[0].size / 1024).toFixed(2) + ' KB)';
                selectedFile.classList.remove('d-none');
                importBtn.disabled = false;
            }
        });
        
        // Drag and drop
        uploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        uploadZone.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
        });
        
        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
        
        // Backup button loading state
        document.getElementById('backupForm').addEventListener('submit', function() {
            const btn = document.getElementById('backupBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating backup...';
            btn.disabled = true;
        });
        
        // Import button loading state
        document.getElementById('importForm').addEventListener('submit', function() {
            const btn = document.getElementById('importBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing...';
            btn.disabled = true;
        });
        
        // Restore from existing backup
        function restoreBackup(filename) {
            if (confirm('This will OVERWRITE your current database with the backup: ' + filename + '\n\nAre you absolutely sure?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="filename" value="${filename}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
