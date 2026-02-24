<?php
// manage_students.php - Admin manage students
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Handle CSV template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'First Name', 'Middle Name', 'Last Name', 'Username', 'Gender', 'National ID', 
        'Phone', 'Address', 'Campus', 'Department Name', 'Program', 
        'Program Type', 'Year of Registration', 'Year of Study', 'Semester', 
        'Entry Type', 'Email'
    ]);
    
    // Sample row
    fputcsv($output, [
        'John', 'Paul', 'Banda', 'jbanda', 'Male', 'MZ12345678', 
        '+265991234567', '123 Main St, Mzuzu', 'Mzuzu Campus', 'Business Administration', 'Bachelor of Business Administration', 
        'degree', '2026', '1', 'One', 'NE', 'jbanda@example.com'
    ]);
    
    fclose($output);
    exit();
}

// Handle CSV template download for existing student IDs
if (isset($_GET['download_existing_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="existing_students_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers - includes Student ID
    fputcsv($output, [
        'Student ID', 'First Name', 'Middle Name', 'Last Name', 'Username', 'Email',
        'Gender', 'National ID', 'Phone', 'Address', 'Campus', 
        'Department Name', 'Program', 'Program Type', 
        'Year of Study', 'Semester'
    ]);
    
    // Sample rows
    fputcsv($output, [
        'BBA/24/MZ/NE/0001', 'John', 'Paul', 'Banda', 'jbanda', 'jbanda@exploitsonline.com',
        'Male', 'MZ12345678', '+265991234567', '123 Main St, Mzuzu', 'Mzuzu Campus', 
        'Business Administration', 'Bachelor of Business Administration', 'degree', '1', 'One'
    ]);
    fputcsv($output, [
        'BBA/24/LL/NE/0002', 'Jane', '', 'Phiri', 'jphiri', 'jphiri@exploitsonline.com',
        'Female', 'MZ87654321', '+265881234567', '456 Center Ave, Lilongwe', 'Lilongwe Campus', 
        'Business Administration', 'Bachelor of Business Administration', 'degree', '2', 'One'
    ]);
    
    fclose($output);
    exit();
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces

        // Auto-generate username: first initial + middle initial (if exists) + surname (e.g., daud kalisa phiri = dkphiri)
        $middle_initial = !empty($middle_name) ? strtolower(substr($middle_name, 0, 1)) : '';
        $base_username = strtolower(substr($first_name, 0, 1) . $middle_initial . preg_replace('/\s+/', '', $last_name));
        $username = $base_username;
        // Check for username collision
        $user_check = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $user_check->bind_param("s", $username);
        $user_check->execute();
        $user_count = $user_check->get_result()->fetch_assoc()['count'] ?? 0;
        if ($user_count > 0) {
            // Add number suffix if collision
            $user_check2 = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
            $user_check2->bind_param("s", $username);
            $user_check2->execute();
            $user_count2 = $user_check2->get_result()->fetch_assoc()['count'] ?? 0;
            if ($user_count2 > 0) {
                // If still not unique, append a number
                $suffix = 2;
                $try_username = $username;
                while (true) {
                    $try_username = $username . $suffix;
                    $user_check3 = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
                    $user_check3->bind_param("s", $try_username);
                    $user_check3->execute();
                    $user_count3 = $user_check3->get_result()->fetch_assoc()['count'] ?? 0;
                    if ($user_count3 == 0) {
                        $username = $try_username;
                        break;
                    }
                    $suffix++;
                }
            }
        } else if ($user_count > 0) {
            // If no middle name, append a number
            $suffix = 2;
            $try_username = $username;
            while (true) {
                $try_username = $username . $suffix;
                $user_check3 = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
                $user_check3->bind_param("s", $try_username);
                $user_check3->execute();
                $user_count3 = $user_check3->get_result()->fetch_assoc()['count'] ?? 0;
                if ($user_count3 == 0) {
                    $username = $try_username;
                    break;
                }
                $suffix++;
            }
        }

        // Default email: username@exploitsonline.com
        $default_email = $username . '@exploitsonline.com';
        $email = trim($_POST['email']);
        if (empty($email)) {
            $email = $default_email;
        }
        $department = trim($_POST['department']);
        $program = trim($_POST['program'] ?? '');
        $year_of_study = (int)$_POST['year_of_study'];
        $campus = trim($_POST['campus'] ?? 'Mzuzu Campus');
        $year_of_registration = trim($_POST['year_of_registration'] ?? '');
        $semester = trim($_POST['semester'] ?? 'One');
        $gender = trim($_POST['gender'] ?? '');
        $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
        $national_id = strtoupper(trim($_POST['national_id'] ?? '')); // Auto-capitalize
        
        // Validate National ID - max 8 characters
        if (!empty($national_id) && strlen($national_id) > 8) {
            $error = "National ID must be 8 characters or less.";
        }
        
        // Check for duplicate National ID (if provided)
        if (!isset($error) && !empty($national_id)) {
            $nid_check = $conn->prepare("SELECT student_id FROM students WHERE national_id = ?");
            $nid_check->bind_param("s", $national_id);
            $nid_check->execute();
            if ($nid_check->get_result()->num_rows > 0) {
                $error = "This National ID '" . htmlspecialchars($national_id) . "' is already registered to another student.";
            }
            $nid_check->close();
        }
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $program_type = trim($_POST['program_type'] ?? 'degree');
        $entry_type = trim($_POST['entry_type'] ?? 'NE');
        $student_type = trim($_POST['student_type'] ?? 'new_student');
        
        // Calculate academic_level from year_of_study and semester
        $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');
        
        // Validate department exists
        $dept_query = $conn->prepare("SELECT department_id, department_code, department_name FROM departments WHERE department_id = ?");
        $dept_query->bind_param("i", $department);
        $dept_query->execute();
        $dept_result = $dept_query->get_result();
        
        if ($dept_result->num_rows === 0) {
            $error = "Invalid department selected. Please select a valid department.";
        } else {
            // Check for duplicate username
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Username '$username' already exists. Please choose a different username.";
            } else {
                // Check for duplicate email
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = "Email '$email' is already registered. Please use a different email.";
                } else {
            $dept_data = $dept_result->fetch_assoc();
            $dept_code = $dept_data['department_code'];
            
            // Auto-generate Student ID
            // Extract campus code
            $campus_code = 'MZ';
            if (strpos($campus, 'Lilongwe') !== false) {
                $campus_code = 'LL';
            } elseif (strpos($campus, 'Blantyre') !== false) {
                $campus_code = 'BT';
            }
            
            // Get last 2 digits of year
            $year_short = substr($year_of_registration, -2);
            
            // Get next sequential number for this combination
            $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
            $count_query = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
            $next_num = ($count_query->fetch_assoc()['count'] ?? 0) + 1;
            $sequential = str_pad($next_num, 4, '0', STR_PAD_LEFT);
            
            $student_id = $prefix . $sequential;

            try {
                // Add to students table
                $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type, student_type, academic_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssissssssssssss", $student_id, $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type, $student_type, $academic_level);
                $stmt->execute();

                // Create user account
                $password_hash = password_hash('password123', PASSWORD_DEFAULT); // Default password
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES (?, ?, ?, 'student', ?, 1)");
                $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                $stmt->execute();
                
                // Create student_finances record with invoice
                // Get fee settings
                $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                $fee_settings = $fee_query->fetch_assoc();
                
                // Continuing students exempt from application fee
                $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
                
                // Use student_type and program_type based registration fee
                // Professional courses: K10,000 flat rate
                // Other programs: K39,500 for new, K35,000 for continuing
                if ($program_type === 'professional') {
                    $registration_fee = 10000; // Professional course flat rate
                } else {
                    $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                    $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                    $registration_fee = ($student_type === 'continuing') ? $continuing_reg_fee : $new_student_reg_fee;
                }
                
                // Determine tuition based on program type
                $tuition = 500000; // Default degree
                switch ($program_type) {
                    case 'professional':
                        $tuition = 200000;
                        break;
                    case 'masters':
                        $tuition = 1100000;
                        break;
                    case 'doctorate':
                        $tuition = 2200000;
                        break;
                }
                
                $expected_total = $application_fee + $registration_fee + $tuition;
                $expected_tuition = $tuition;
                
                // Insert student_finances record
                $stmt = $conn->prepare("INSERT INTO student_finances 
                    (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) 
                    VALUES (?, ?, ?, 0, ?, 0, 0)");
                $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                $stmt->execute();

                // Send welcome email with credentials
                $temp_password = 'password123';
                if (isEmailEnabled()) {
                    $email_sent = sendStudentWelcomeEmail($email, $full_name, $student_id, $username, $temp_password, $program, $campus);
                    $email_status = $email_sent ? ' | Welcome email sent' : ' | Email notification failed';
                } else {
                    $email_status = '';
                }

                $success = "Student added successfully! Student ID: $student_id | Invoice Created: K" . number_format($expected_total) . $email_status;
            } catch (mysqli_sql_exception $e) {
                // Rollback: delete student if user creation failed
                $conn->query("DELETE FROM students WHERE student_id = '$student_id'");
                $error = "Failed to create student: " . $e->getMessage();
            }
                } // end email check
            } // end username check
        }
    } elseif (isset($_POST['delete_student'])) {
        $student_id = $_POST['student_id'];

        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Delete related records in order (child tables first)
            // 1. Delete VLE submissions
            $stmt = $conn->prepare("DELETE FROM vle_submissions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 2. Delete VLE enrollments
            $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 3. Delete payment transactions
            $stmt = $conn->prepare("DELETE FROM payment_transactions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 4. Delete payment submissions
            $stmt = $conn->prepare("DELETE FROM payment_submissions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 5. Delete course registration requests
            $stmt = $conn->prepare("DELETE FROM course_registration_requests WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 6. Delete student finances
            $stmt = $conn->prepare("DELETE FROM student_finances WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 7. Delete user account
            $stmt = $conn->prepare("DELETE FROM users WHERE related_student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();

            // 8. Finally delete student record
            $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $success = "Student and all related records deleted successfully!";
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = "Failed to delete student: " . $e->getMessage();
        }
    } elseif (isset($_POST['reset_password'])) {
        $student_id = trim($_POST['student_id']);
        if (empty($student_id)) {
            $error = "Student ID is required!";
        } else {
            // Check if student exists
            $check_stmt = $conn->prepare("SELECT u.user_id, u.email, s.full_name FROM users u JOIN students s ON u.related_student_id = s.student_id WHERE u.related_student_id = ? AND u.role = 'student'");
            $check_stmt->bind_param("s", $student_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows === 0) {
                $error = "No user account found for student ID: " . htmlspecialchars($student_id);
            } else {
                $student_info = $check_result->fetch_assoc();
                $new_password = 'password123';
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_student_id = ? AND role = 'student'");
                $stmt->bind_param("ss", $password_hash, $student_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Send password reset notification email
                    if (isEmailEnabled()) {
                        sendPasswordResetEmail($student_info['email'], $student_info['full_name'], $new_password, true);
                    }
                    $success = "Password reset to default for " . htmlspecialchars($student_info['full_name']) . " (ID: " . htmlspecialchars($student_id) . ")";
                } else {
                    $error = "Failed to reset password. No changes were made.";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    } elseif (isset($_POST['bulk_upload'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            
            $upload_success = 0;
            $upload_errors = [];
            $row_num = 0;
            
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                $row_num++;
                
                try {
                    // Extract data from CSV
                    $first_name = trim($data[0] ?? '');
                    $middle_name = trim($data[1] ?? '');
                    $last_name = trim($data[2] ?? '');
                    $username = trim($data[3] ?? '');
                    $gender = trim($data[4] ?? '');
                    $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
                    $national_id = trim($data[5] ?? '');
                    $phone = trim($data[6] ?? '');
                    $address = trim($data[7] ?? '');
                    $campus = trim($data[8] ?? 'Mzuzu Campus');
                    $department_name = trim($data[9] ?? '');
                    $program = trim($data[10] ?? '');
                    $program_type = trim($data[11] ?? 'degree');
                    $year_of_registration = trim($data[12] ?? date('Y'));
                    $year_of_study = (int)($data[13] ?? 1);
                    $semester = trim($data[14] ?? 'One');
                    $entry_type = trim($data[15] ?? 'NE');
                    $email = trim($data[16] ?? '');
                    
                    // Validate required fields
                    if (empty($first_name) || empty($last_name) || empty($username) || empty($department_name)) {
                        $upload_errors[] = "Row $row_num: Missing required fields (First Name, Last Name, Username, or Department Name)";
                        continue;
                    }
                    
                    // Look up department ID by name
                    $dept_lookup = $conn->prepare("SELECT department_id, department_code FROM departments WHERE department_name LIKE ?");
                    $dept_search = '%' . $department_name . '%';
                    $dept_lookup->bind_param("s", $dept_search);
                    $dept_lookup->execute();
                    $dept_lookup_result = $dept_lookup->get_result();
                    
                    if ($dept_lookup_result->num_rows === 0) {
                        $upload_errors[] = "Row $row_num: Department '$department_name' not found";
                        continue;
                    }
                    
                    $dept_data = $dept_lookup_result->fetch_assoc();
                    $department = $dept_data['department_id'];
                    $dept_code = $dept_data['department_code'];
                    
                    // Create full name
                    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                    
                    // Extract campus code
                    $campus_code = 'MZ';
                    if (strpos($campus, 'Lilongwe') !== false) {
                        $campus_code = 'LL';
                    } elseif (strpos($campus, 'Blantyre') !== false) {
                        $campus_code = 'BT';
                    }
                    
                    // Get last 2 digits of year
                    $year_short = substr($year_of_registration, -2);
                    
                    // Get next sequential number
                    $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
                    $count_query = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
                    $next_num = ($count_query->fetch_assoc()['count'] ?? 0) + 1;
                    $sequential = str_pad($next_num, 4, '0', STR_PAD_LEFT);
                    $student_id = $prefix . $sequential;
                    
                    // Calculate academic_level
                    $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');
                    $student_type = 'new_student'; // Bulk upload defaults to new students
                    
                    // Insert student
                    $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type, student_type, academic_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssissssssssssss", $student_id, $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type, $student_type, $academic_level);
                    $stmt->execute();
                    
                    // Create user account
                    $password_hash = password_hash('password123', PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES (?, ?, ?, 'student', ?, 1)");
                    $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                    $stmt->execute();
                    
                    // Create student_finances record with invoice
                    $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                    $fee_settings = $fee_query->fetch_assoc();
                    
                    // Continuing students exempt from application fee
                    $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
                    
                    // Use student_type and program_type based registration fee
                    // Professional courses: K10,000 flat rate
                    // Other programs: K39,500 for new, K35,000 for continuing
                    if ($program_type === 'professional') {
                        $registration_fee = 10000; // Professional course flat rate
                    } else {
                        $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                        $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                        $registration_fee = ($student_type === 'continuing') ? $continuing_reg_fee : $new_student_reg_fee;
                    }
                    
                    // Determine tuition based on program type
                    $tuition = 500000; // Default degree
                    switch ($program_type) {
                        case 'professional':
                            $tuition = 200000;
                            break;
                        case 'masters':
                            $tuition = 1100000;
                            break;
                        case 'doctorate':
                            $tuition = 2200000;
                            break;
                    }
                    
                    $expected_total = $application_fee + $registration_fee + $tuition;
                    $expected_tuition = $tuition;
                    
                    // Insert student_finances record
                    $stmt = $conn->prepare("INSERT INTO student_finances 
                        (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) 
                        VALUES (?, ?, ?, 0, ?, 0, 0)");
                    $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                    $stmt->execute();
                    
                    $upload_success++;
                } catch (Exception $e) {
                    $upload_errors[] = "Row $row_num: " . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            // Set success/error messages
            if ($upload_success > 0) {
                $success = "Successfully uploaded $upload_success student(s).";
            }
            if (!empty($upload_errors)) {
                $error = "Upload completed with errors: <br>" . implode('<br>', array_slice($upload_errors, 0, 10));
                if (count($upload_errors) > 10) {
                    $error .= "<br>... and " . (count($upload_errors) - 10) . " more errors.";
                }
            }
        } else {
            $error = "Please select a valid CSV file to upload.";
        }
    } elseif (isset($_POST['bulk_upload_existing'])) {
        // Handle bulk upload for students with existing IDs
        if (isset($_FILES['csv_existing_file']) && $_FILES['csv_existing_file']['error'] == 0) {
            $file = $_FILES['csv_existing_file']['tmp_name'];
            $handle = fopen($file, 'r');
            
            $upload_success = 0;
            $upload_errors = [];
            $row_num = 0;
            
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                $row_num++;
                
                try {
                    // Extract data from CSV - Student ID is first column
                    $student_id = trim($data[0] ?? '');
                    $first_name = trim($data[1] ?? '');
                    $middle_name = trim($data[2] ?? '');
                    $last_name = trim($data[3] ?? '');
                    $username = trim($data[4] ?? '');
                    $email = trim($data[5] ?? '');
                    $gender = trim($data[6] ?? '');
                    $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
                    $national_id = trim($data[7] ?? '');
                    $phone = trim($data[8] ?? '');
                    $address = trim($data[9] ?? '');
                    $campus = trim($data[10] ?? 'Mzuzu Campus');
                    $department_name = trim($data[11] ?? '');
                    $program = trim($data[12] ?? '');
                    $program_type = trim($data[13] ?? 'degree');
                    $year_of_study = (int)($data[14] ?? 1);
                    $semester = trim($data[15] ?? 'One');
                    
                    // Validate required fields
                    if (empty($student_id) || empty($first_name) || empty($last_name)) {
                        $upload_errors[] = "Row $row_num: Missing required fields (Student ID, First Name, or Last Name)";
                        continue;
                    }
                    
                    // Look up department ID by name
                    $department = '';
                    if (!empty($department_name)) {
                        $dept_lookup = $conn->prepare("SELECT department_id FROM departments WHERE department_name LIKE ?");
                        $dept_search = '%' . $department_name . '%';
                        $dept_lookup->bind_param("s", $dept_search);
                        $dept_lookup->execute();
                        $dept_lookup_result = $dept_lookup->get_result();
                        
                        if ($dept_lookup_result->num_rows > 0) {
                            $department = $dept_lookup_result->fetch_assoc()['department_id'];
                        }
                        $dept_lookup->close();
                    }
                    
                    // Check if student ID already exists
                    $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                    $check_stmt->bind_param("s", $student_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        $upload_errors[] = "Row $row_num: Student ID '$student_id' already exists";
                        $check_stmt->close();
                        continue;
                    }
                    $check_stmt->close();
                    
                    // Auto-generate username if empty: first initial + middle initial + surname
                    if (empty($username)) {
                        $middle_initial = !empty($middle_name) ? strtolower(substr($middle_name, 0, 1)) : '';
                        $username = strtolower(substr($first_name, 0, 1) . $middle_initial . preg_replace('/\s+/', '', $last_name));
                    }
                    
                    // Auto-generate email if empty
                    if (empty($email)) {
                        $email = $username . '@exploitsonline.com';
                    }
                    
                    // Check if username already exists
                    $user_check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                    $user_check->bind_param("s", $username);
                    $user_check->execute();
                    if ($user_check->get_result()->num_rows > 0) {
                        // Append number to username
                        $base_username = $username;
                        $counter = 1;
                        do {
                            $username = $base_username . $counter;
                            $user_check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                            $user_check->bind_param("s", $username);
                            $user_check->execute();
                            $counter++;
                        } while ($user_check->get_result()->num_rows > 0);
                        $email = $username . '@exploitsonline.com';
                    }
                    $user_check->close();
                    
                    // Create full name
                    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                    
                    // Extract year of registration from student ID (format: XXX/YY/XX/XX/XXXX)
                    $id_parts = explode('/', $student_id);
                    $year_of_registration = isset($id_parts[1]) ? '20' . $id_parts[1] : date('Y');
                    $entry_type = isset($id_parts[3]) ? $id_parts[3] : 'NE';
                    
                    // Calculate academic_level and set student_type
                    $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');
                    $student_type_bulk = 'new_student'; // Default for bulk upload
                    
                    // Insert student
                    $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type, student_type, academic_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssissssssssssss", $student_id, $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type, $student_type_bulk, $academic_level);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Create user account
                    $password_hash = password_hash('password123', PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES (?, ?, ?, 'student', ?, 1)");
                    $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Create student_finances record with invoice
                    $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                    $fee_settings = $fee_query->fetch_assoc();
                    
                    // For existing ID uploads, default to new_student type
                    $student_type = 'new_student';
                    
                    // Continuing students exempt from application fee (but bulk upload defaults to new_student)
                    $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
                    
                    // Use student_type and program_type based registration fee
                    // Professional courses: K10,000 flat rate
                    // Other programs: K39,500 for new, K35,000 for continuing
                    if ($program_type === 'professional') {
                        $registration_fee = 10000; // Professional course flat rate
                    } else {
                        $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                        $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                        $registration_fee = ($student_type === 'continuing') ? $continuing_reg_fee : $new_student_reg_fee;
                    }
                    
                    // Determine tuition based on program type
                    $tuition = 500000; // Default degree
                    switch ($program_type) {
                        case 'professional':
                            $tuition = 200000;
                            break;
                        case 'masters':
                            $tuition = 1100000;
                            break;
                        case 'doctorate':
                            $tuition = 2200000;
                            break;
                    }
                    
                    $expected_total = $application_fee + $registration_fee + $tuition;
                    $expected_tuition = $tuition;
                    
                    // Insert student_finances record
                    $stmt = $conn->prepare("INSERT INTO student_finances 
                        (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) 
                        VALUES (?, ?, ?, 0, ?, 0, 0)");
                    $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                    $stmt->execute();
                    $stmt->close();
                    
                    $upload_success++;
                } catch (Exception $e) {
                    $upload_errors[] = "Row $row_num: " . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            // Set success/error messages
            if ($upload_success > 0) {
                $success = "Successfully uploaded $upload_success student(s) with existing IDs.";
            }
            if (!empty($upload_errors)) {
                $error = "Upload completed with errors: <br>" . implode('<br>', array_slice($upload_errors, 0, 10));
                if (count($upload_errors) > 10) {
                    $error .= "<br>... and " . (count($upload_errors) - 10) . " more errors.";
                }
            }
        } else {
            $error = "Please select a valid CSV file to upload.";
        }
    }
}

// Get all students with usernames
$students = [];
$result = $conn->query("SELECT s.*, u.username, d.department_name, d.department_code 
                        FROM students s 
                        LEFT JOIN users u ON s.student_id = u.related_student_id 
                        LEFT JOIN departments d ON s.department = d.department_id 
                        ORDER BY s.full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get all departments/programs for dropdown
$departments = [];
$dept_query = "SELECT department_id, department_code, department_name FROM departments ORDER BY department_name";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .card-header-upload {
            background: var(--vle-gradient-success) !important;
            border: none;
            color: white;
        }
        .card-header-students {
            background: var(--vle-gradient-primary) !important;
            border: none;
            color: white;
        }
    </style>
</head>

<body>
        <?php 
        $breadcrumbs = [['title' => 'Manage Students']];
        include 'header_nav.php'; 
        ?>
        <div class="vle-content">

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert vle-alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-people me-2"></i>Manage Students</h2>
            <button class="btn btn-vle-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                <i class="bi bi-plus-circle me-1"></i> Add New Student
            </button>
        </div>

        <!-- Bulk Upload Section -->
        <div class="card vle-card mb-4">
            <div class="card-header card-header-upload">
                <h5 class="mb-0"><i class="bi bi-upload me-2"></i>Bulk Upload Students</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Auto-generate Student IDs -->
                    <div class="col-md-6 border-end">
                        <h6 class="text-success"><i class="bi bi-plus-circle me-1"></i> New Students (Auto-Generate IDs)</h6>
                        <p class="mb-3 text-muted small">For new students - system will auto-generate Student IDs</p>
                        <a href="?download_template" class="btn btn-outline-success mb-3">
                            <i class="bi bi-download me-1"></i> Download Template
                        </a>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_file" class="form-label vle-form-label">Select CSV File *</label>
                                <input type="file" class="form-control vle-form-control" id="csv_file" name="csv_file" accept=".csv" required>
                            </div>
                            <button type="submit" name="bulk_upload" class="btn btn-success">
                                <i class="bi bi-upload me-1"></i> Upload New Students
                            </button>
                        </form>
                    </div>
                    
                    <!-- Upload with Existing Student IDs -->
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="bi bi-card-list me-1"></i> Existing Students (With Student IDs)</h6>
                        <p class="mb-3 text-muted small">For students who already have Student IDs assigned</p>
                        <a href="?download_existing_template" class="btn btn-outline-primary mb-3">
                            <i class="bi bi-download me-1"></i> Download Template
                        </a>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_existing_file" class="form-label vle-form-label">Select CSV File *</label>
                                <input type="file" class="form-control vle-form-control" id="csv_existing_file" name="csv_existing_file" accept=".csv" required>
                            </div>
                            <button type="submit" name="bulk_upload_existing" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i> Upload Existing Students
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students List -->
        <div class="card vle-card">
            <div class="card-header card-header-students">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Students (<?php echo count($students); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <p class="text-muted">No students found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <div class="mb-3">
                            <input type="text" id="studentSearch" class="form-control vle-form-control" placeholder="Search by Student ID or Name...">
                        </div>
                        <table class="table vle-table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Department Code</th>
                                    <th>Year/Semester</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($student['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['department_code'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($student['year_of_study']) . ' / ' . htmlspecialchars($student['semester']); ?></td>
                                        <td>
                                            <a href="edit_student.php?id=<?php echo urlencode($student['student_id']); ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                <button type="submit" name="delete_student" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <script>
                        document.getElementById('studentSearch').addEventListener('input', function() {
                            var search = this.value.toLowerCase();
                            var rows = document.querySelectorAll('#studentsTable tbody tr');
                            rows.forEach(function(row) {
                                var id = row.cells[0].textContent.toLowerCase();
                                var name = row.cells[1].textContent.toLowerCase();
                                if (id.includes(search) || name.includes(search)) {
                                    row.style.display = '';
                                } else {
                                    row.style.display = 'none';
                                }
                            });
                        });
                        </script>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>

        <!-- Settings Modal -->
        <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="settingsModalLabel"><i class="bi bi-gear"></i> System Appearance Settings</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="themeSettingsForm">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="themeSelect" class="form-label">Visual Theme</label>
                                <select class="form-select" id="themeSelect" name="theme">
                                    <option value="default">Default (Light)</option>
                                    <option value="dark">Dark</option>
                                    <option value="sky">Sky Blue</option>
                                    <option value="system">System</option>
                                </select>
                                <div class="form-text">Choose the color theme for the admin system interface.</div>
                            </div>
                            <div class="alert alert-info small">
                                <i class="bi bi-info-circle"></i> Theme changes are applied instantly and saved for your next login.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        // Theme switching logic
        document.addEventListener('DOMContentLoaded', function() {
            const themeForm = document.getElementById('themeSettingsForm');
            const themeSelect = document.getElementById('themeSelect');
            // Load saved theme
            const savedTheme = localStorage.getItem('vle_theme');
            if (savedTheme) {
                themeSelect.value = savedTheme;
                applyTheme(savedTheme);
            }
            themeForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const theme = themeSelect.value;
                localStorage.setItem('vle_theme', theme);
                applyTheme(theme);
                // Optionally show a toast or feedback
                var modal = bootstrap.Modal.getInstance(document.getElementById('settingsModal'));
                if (modal) modal.hide();
            });
            function applyTheme(theme) {
                document.body.classList.remove('theme-dark', 'theme-sky', 'theme-system');
                switch (theme) {
                    case 'dark':
                        document.body.classList.add('theme-dark');
                        break;
                    case 'sky':
                        document.body.classList.add('theme-sky');
                        break;
                    case 'system':
                        document.body.classList.add('theme-system');
                        break;
                    default:
                        // Default (light)
                        break;
                }
            }
        });
        </script>
        <style>
        /* Theme Styles */
        body.theme-dark {
            background-color: #181a1b !important;
            color: #f1f1f1 !important;
        }
        body.theme-dark .card, body.theme-dark .modal-content {
            background-color: #23272b !important;
            color: #f1f1f1 !important;
        }
        body.theme-dark .navbar, body.theme-dark .card-header, body.theme-dark .modal-header {
            background-color: #111827 !important;
            color: #fff !important;
        }
        body.theme-dark .table {
            color: #f1f1f1 !important;
        }
        body.theme-sky {
            background-color: #e6f4fb !important;
            color: #222 !important;
        }
        body.theme-sky .navbar, body.theme-sky .card-header, body.theme-sky .modal-header {
            background-color: #87ceeb !important;
            color: #222 !important;
        }
        body.theme-sky .modal-content, body.theme-sky .card {
            background-color: #fafdff !important;
            color: #222 !important;
        }
        body.theme-system {
            /* Use prefers-color-scheme */
        }
        body.theme-system {
            background-color: #fff;
            color: #222;
        }
        @media (prefers-color-scheme: dark) {
            body.theme-system {
                background-color: #181a1b !important;
                color: #f1f1f1 !important;
            }
            body.theme-system .card, body.theme-system .modal-content {
                background-color: #23272b !important;
                color: #f1f1f1 !important;
            }
            body.theme-system .navbar, body.theme-system .card-header, body.theme-system .modal-header {
                background-color: #111827 !important;
                color: #fff !important;
            }
            body.theme-system .table {
                color: #f1f1f1 !important;
            }
        }
        </style>
    <script>
    // Password visibility toggle
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.querySelector('i').classList.remove('bi-eye');
            btn.querySelector('i').classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            btn.querySelector('i').classList.remove('bi-eye-slash');
            btn.querySelector('i').classList.add('bi-eye');
        }
    }
    
    // Update registration fee display based on student type and program type
    function updateRegistrationFeeDisplay() {
        const studentType = document.getElementById('student_type');
        const programType = document.getElementById('program_type');
        const regFeeInfo = document.getElementById('reg_fee_info');
        
        if (studentType && regFeeInfo) {
            let regFee = '39,500';
            let appFee = '5,500';
            let tuition = '500,000';
            let exemptApp = false;
            
            // Check if professional course
            if (programType && programType.value === 'professional') {
                regFee = '10,000';
                tuition = '200,000';
            } else if (studentType.value === 'continuing') {
                regFee = '35,000';
                exemptApp = true;
            }
            
            // Also exempt application fee for continuing students
            if (studentType.value === 'continuing') {
                exemptApp = true;
            }
            
            // Update tuition based on program type
            if (programType) {
                switch(programType.value) {
                    case 'professional': tuition = '200,000'; break;
                    case 'masters': tuition = '1,100,000'; break;
                    case 'doctorate': tuition = '2,200,000'; break;
                    default: tuition = '500,000';
                }
            }
            
            let feeText = 'Registration: K' + regFee;
            if (exemptApp) {
                feeText += ' | App Fee: Exempt';
            } else {
                feeText += ' | App Fee: K' + appFee;
            }
            feeText += ' | Tuition: K' + tuition;
            
            regFeeInfo.textContent = feeText;
        }
    }
    
        function updateDepartmentField() {
            const programSelect = document.getElementById('department');
            const departmentSelect = document.getElementById('program');
            
            if (!programSelect.value) {
                departmentSelect.innerHTML = '<option value="">Select Program First</option>';
                return;
            }
            
            // Get the full program name
            const selectedOption = programSelect.options[programSelect.selectedIndex];
            const fullProgramName = selectedOption.getAttribute('data-name');
            
            // Remove common prefixes
            let departmentName = fullProgramName;
            const prefixes = ['Bachelor of ', 'Bachelors of ', 'Masters of ', 'Master of ', 'Doctorate in ', 'PhD in ', 'Certificate in ', 'Diploma in '];
            
            for (let prefix of prefixes) {
                if (departmentName.startsWith(prefix)) {
                    departmentName = departmentName.substring(prefix.length);
                    break;
                }
            }
            
            // Update department dropdown
            departmentSelect.innerHTML = '<option value="' + departmentName + '" selected>' + departmentName + '</option>';
            
            // Trigger student ID generation
            generateStudentID();
        }
        
        function updateEntryCode() {
            const entryType = document.getElementById('entry_type').value;
            const entryCodeField = document.getElementById('entry_code');
            entryCodeField.value = entryType;
            generateStudentID();
        }
        
        // Auto-generate username and email from first, middle, and last name
        function generateStudentCredentials() {
            const firstName = document.getElementById('first_name').value.trim().toLowerCase();
            const middleName = document.getElementById('middle_name')?.value.trim().toLowerCase() || '';
            const lastName = document.getElementById('last_name').value.trim().toLowerCase();
            
            if (firstName && lastName) {
                // Username: first initial + middle initial + surname (e.g., daud kalisa phiri = dkphiri)
                const middleInitial = middleName ? middleName.charAt(0) : '';
                const username = firstName.charAt(0) + middleInitial + lastName.replace(/\s+/g, '');
                document.getElementById('username').value = username;
                
                // Email: username@exploitsonline.com
                document.getElementById('email').value = username + '@exploitsonline.com';
            }
        }
        
        function generateStudentID() {
            const department = document.getElementById('department');
            const yearOfReg = document.getElementById('year_of_registration').value;
            const campus = document.getElementById('campus').value;
            const entryType = document.getElementById('entry_type').value;
            
            if (!department.value || !yearOfReg || !campus || !entryType) {
                return; // Don't generate if required fields are missing
            }
            
            // Get department code from selected option
            const deptCode = department.options[department.selectedIndex].getAttribute('data-code') || 'UNK';
            
            // Get last 2 digits of year
            const yearShort = yearOfReg.substring(2);
            
            // Extract campus code
            let campusCode = 'MZ';
            if (campus.includes('Lilongwe')) {
                campusCode = 'LL';
            } else if (campus.includes('Blantyre')) {
                campusCode = 'BT';
            }
            
            // Generate preview ID (backend will add sequential number)
            const studentIDField = document.getElementById('student_id');
            studentIDField.value = deptCode + '/' + yearShort + '/' + campusCode + '/' + entryType + '/####';
        }
        
        // Attach listeners to relevant fields
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('department').addEventListener('change', generateStudentID);
            document.getElementById('year_of_registration').addEventListener('change', generateStudentID);
            document.getElementById('campus').addEventListener('change', generateStudentID);
            document.getElementById('entry_type').addEventListener('change', generateStudentID);
            
            // Modal form listeners
            document.getElementById('modal_department').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_year_of_registration').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_campus').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_entry_type').addEventListener('change', generateModalStudentID);
        });
        
        // Functions for modal form
        function updateModalDepartmentField() {
            const department = document.getElementById('modal_department');
            const program = document.getElementById('modal_program');
            const programType = document.getElementById('program_type');
            
            if (department.value) {
                const selectedOption = department.options[department.selectedIndex];
                const deptName = selectedOption.getAttribute('data-name');
                const type = programType.value;
                
                // Generate proper program name based on program type
                let programPrefix = 'Bachelor of';
                switch(type) {
                    case 'professional':
                        programPrefix = 'Professional Certificate in';
                        break;
                    case 'masters':
                        programPrefix = 'Master of';
                        break;
                    case 'doctorate':
                        programPrefix = 'Doctor of Philosophy in';
                        break;
                    default:
                        programPrefix = 'Bachelor of';
                }
                
                const fullProgramName = programPrefix + ' ' + deptName;
                
                // Clear and populate program select
                program.innerHTML = '<option value="' + fullProgramName + '">' + fullProgramName + '</option>';
                program.value = fullProgramName;
            } else {
                program.innerHTML = '<option value="">Select Department First</option>';
            }
            
            generateModalStudentID();
        }
        
        function updateModalEntryCode() {
            const entryType = document.getElementById('modal_entry_type');
            const entryCode = document.getElementById('modal_entry_code');
            entryCode.value = entryType.value;
            generateModalStudentID();
        }
        
        function generateModalStudentID() {
            const department = document.getElementById('modal_department').value;
            const yearOfReg = document.getElementById('modal_year_of_registration').value;
            const campus = document.getElementById('modal_campus').value;
            const entryType = document.getElementById('modal_entry_type').value;
            
            if (!department || !yearOfReg || !campus || !entryType) {
                return;
            }
            
            const deptElement = document.getElementById('modal_department');
            const deptCode = deptElement.options[deptElement.selectedIndex].getAttribute('data-code') || 'UNK';
            const yearShort = yearOfReg.substring(2);
            
            let campusCode = 'MZ';
            if (campus.includes('Lilongwe')) {
                campusCode = 'LL';
            } else if (campus.includes('Blantyre')) {
                campusCode = 'BT';
            }
            
            const studentIDField = document.getElementById('modal_student_id');
            studentIDField.value = deptCode + '/' + yearShort + '/' + campusCode + '/' + entryType + '/####';
        }
        
        // Password validation for reset password forms
        document.addEventListener('DOMContentLoaded', function() {
            // Get all reset password forms
            const forms = document.querySelectorAll('[id^="resetPasswordForm"]');
            forms.forEach(function(form) {
                const studentId = form.querySelector('input[name="student_id"]').value;
                const newPasswordInput = document.getElementById('newPassword' + studentId);
                const confirmPasswordInput = document.getElementById('confirmPassword' + studentId);
                const mismatchDiv = document.getElementById('passwordMismatch' + studentId);
                const strengthBar = document.getElementById('passwordStrength' + studentId);
                if (!newPasswordInput || !confirmPasswordInput) return;

                // Password strength meter
                newPasswordInput.addEventListener('input', function() {
                    const val = newPasswordInput.value;
                    let score = 0;
                    if (val.length >= 6) score++;
                    if (/[A-Z]/.test(val)) score++;
                    if (/[0-9]/.test(val)) score++;
                    if (/[^A-Za-z0-9]/.test(val)) score++;
                    let percent = (score / 4) * 100;
                    if (strengthBar) {
                        strengthBar.style.width = percent + '%';
                        strengthBar.className = 'progress-bar';
                        if (score <= 1) strengthBar.classList.add('bg-danger');
                        else if (score === 2) strengthBar.classList.add('bg-warning');
                        else if (score >= 3) strengthBar.classList.add('bg-success');
                    }
                });

                // Validate on form submit
                form.addEventListener('submit', function(e) {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        confirmPasswordInput.classList.add('is-invalid');
                        if (mismatchDiv) mismatchDiv.style.display = 'block';
                        return false;
                    }
                    if (newPassword.length < 6) {
                        e.preventDefault();
                        newPasswordInput.classList.add('is-invalid');
                        alert('Password must be at least 6 characters long!');
                        return false;
                    }
                    return true;
                });

                // Real-time validation
                confirmPasswordInput.addEventListener('input', function() {
                    if (this.value && this.value !== newPasswordInput.value) {
                        this.classList.add('is-invalid');
                        if (mismatchDiv) mismatchDiv.style.display = 'block';
                    } else {
                        this.classList.remove('is-invalid');
                        if (mismatchDiv) mismatchDiv.style.display = 'none';
                    }
                });
            });

            // Auto-close modals on success and scroll to message
            <?php if (isset($success) && strpos($success, 'Password reset successfully') !== false): ?>
                // Close all reset password modals
                const modals = document.querySelectorAll('[id^="resetPasswordModal"]');
                modals.forEach(function(modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                });
                // Scroll to success message
                window.scrollTo({ top: 0, behavior: 'smooth' });
            <?php endif; ?>
        });
    </script>
    
    <!-- Add Student Modal -->
    <script>
    // Auto-fill username field based on name inputs
    document.addEventListener('DOMContentLoaded', function() {
        function generateUsername() {
            const first = document.getElementById('first_name').value.trim().toLowerCase();
            const middle = document.getElementById('middle_name').value.trim().toLowerCase();
            const last = document.getElementById('last_name').value.trim().toLowerCase();
            let username = '';
            if (first && last) {
                // Username: first initial + middle initial + surname (e.g., daud kalisa phiri = dkphiri)
                const middleInitial = middle ? middle.charAt(0) : '';
                username = first.charAt(0) + middleInitial + last.replace(/\s+/g, '');
            }
            document.getElementById('username').value = username;
        }
        const first = document.getElementById('first_name');
        const middle = document.getElementById('middle_name');
        const last = document.getElementById('last_name');
        if (first && last && middle) {
            first.addEventListener('input', generateUsername);
            middle.addEventListener('input', generateUsername);
            last.addEventListener('input', generateUsername);
        }
    });
    </script>
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Add New Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Line 1: First Name, Middle Name, Last Name, Username -->
                            <div class="col-12"><h6 class="text-primary">Personal Information</h6></div>
                            <div class="col-md-3">
                                <label for="first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First name" required oninput="generateStudentCredentials()">
                            </div>
                            <div class="col-md-3">
                                <label for="middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Middle name" oninput="generateStudentCredentials()">
                            </div>
                            <div class="col-md-3">
                                <label for="last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last name" required oninput="generateStudentCredentials()">
                            </div>
                            <div class="col-md-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="Auto-generated" required>
                                <small class="text-muted">Auto-generated from name</small>
                            </div>
                            
                            <!-- Line 2: Gender, National ID, Phone Number, Home Address -->
                            <div class="col-md-3">
                                <label for="gender" class="form-label">Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="national_id" class="form-label">National ID <small class="text-muted">(Max 8 chars)</small></label>
                                <input type="text" class="form-control" id="national_id" name="national_id" placeholder="National ID" maxlength="8" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                                <div class="form-text">Must be unique. No duplicates allowed.</div>
                            </div>
                            <div class="col-md-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" placeholder="+265...">
                            </div>
                            <div class="col-md-3">
                                <label for="address" class="form-label">Home Address</label>
                                <input type="text" class="form-control" id="address" name="address" placeholder="Address">
                            </div>
                            
                            <!-- Line 3: Campus, Program of Study, Department, Program Type -->
                            <div class="col-12"><h6 class="text-primary mt-2">Academic Information</h6></div>
                            <div class="col-md-3">
                                <label for="modal_campus" class="form-label">Campus *</label>
                                <select class="form-select" id="modal_campus" name="campus" required>
                                    <option value="Mzuzu Campus" selected>Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_department" class="form-label">Department *</label>
                                <select class="form-select" id="modal_department" name="department" required onchange="updateModalDepartmentField()">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept['department_id']); ?>" 
                                                data-code="<?php echo htmlspecialchars($dept['department_code']); ?>"
                                                data-name="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_program" class="form-label">Program *</label>
                                <select class="form-select bg-light" id="modal_program" name="program" required>
                                    <option value="">Select Department First</option>
                                </select>
                                <small class="text-muted">e.g. Bachelor of Business Administration</small>
                            </div>
                            <div class="col-md-3">
                                <label for="program_type" class="form-label">Program Type *</label>
                                <select class="form-select" id="program_type" name="program_type" required onchange="updateModalDepartmentField(); updateRegistrationFeeDisplay();">
                                    <option value="degree" selected>Degree (K500,000)</option>
                                    <option value="professional">Professional (K200,000)</option>
                                    <option value="masters">Masters (K1,100,000)</option>
                                    <option value="doctorate">Doctorate (K2,200,000)</option>
                                </select>
                                <small class="text-muted">Determines tuition fees</small>
                            </div>
                            
                            <!-- Line 4: Year of Registration, Year of Study, Semester, Student ID -->
                            <div class="col-md-3">
                                <label for="modal_year_of_registration" class="form-label">Year of Registration *</label>
                                <input type="number" class="form-control" id="modal_year_of_registration" name="year_of_registration" 
                                       min="2000" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="year_of_study" class="form-label">Year of Study *</label>
                                <select class="form-select" id="year_of_study" name="year_of_study" required>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="semester" class="form-label">Semester *</label>
                                <select class="form-select" id="semester" name="semester" required>
                                    <option value="One" selected>Semester One</option>
                                    <option value="Two">Semester Two</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_student_id" class="form-label">Student ID *</label>
                                <input type="text" class="form-control bg-light" id="modal_student_id" name="student_id" placeholder="Auto-generated" readonly required>
                                <small class="text-muted">Auto-generated based on selections</small>
                            </div>
                            
                            <!-- Line 5: Email, Entry Level, Entry Code, Student Type -->
                            <div class="col-12"><h6 class="text-primary mt-2">Additional Information</h6></div>
                            <div class="col-md-3">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="Auto-generated" required>
                                <small class="text-muted">Auto-generated from username</small>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_entry_type" class="form-label">Entry Level *</label>
                                <select class="form-select" id="modal_entry_type" name="entry_type" required onchange="updateModalEntryCode()">
                                    <option value="NE" selected>Normal Entry (NE)</option>
                                    <option value="ME">Mature Entry (ME)</option>
                                    <option value="CE">Continuing Entry (CE)</option>
                                    <option value="ODL">Open Distance Learning (ODL)</option>
                                    <option value="PC">Professional Course (PC)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_entry_code" class="form-label">Entry Code *</label>
                                <input type="text" class="form-control" id="modal_entry_code" name="entry_code" value="NE" maxlength="10" required>
                                <small class="text-muted">Editable entry code</small>
                            </div>
                            <div class="col-md-3">
                                <label for="student_type" class="form-label">Student Type *</label>
                                <select class="form-select" id="student_type" name="student_type" required onchange="updateRegistrationFeeDisplay()">
                                    <option value="new_student" selected>New Student</option>
                                    <option value="continuing">Continuing Student</option>
                                </select>
                                <small class="text-muted" id="reg_fee_info">Registration: K39,500 | App Fee: K5,500 | Tuition: K500,000</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <small class="text-muted me-auto">Default password will be: password123</small>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_student" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Add Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>