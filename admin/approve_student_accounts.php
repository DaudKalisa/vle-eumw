<?php
/**
 * Admin - Approve Student Account Registrations
 * Reviews student registrations submitted via invite links.
 * On approval: creates student record, user account, finance record, sends welcome email.
 * On rejection: sends rejection notification email.
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin', 'odl_coordinator']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Ensure required tables exist
$conn->query("CREATE TABLE IF NOT EXISTS student_registration_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    department_id INT DEFAULT NULL,
    program VARCHAR(200) DEFAULT NULL,
    campus VARCHAR(100) DEFAULT NULL,
    program_type VARCHAR(50) DEFAULT 'degree',
    year_of_study INT DEFAULT 1,
    semester VARCHAR(10) DEFAULT 'One',
    entry_type VARCHAR(10) DEFAULT 'NE',
    max_uses INT DEFAULT 1,
    times_used INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS student_invite_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_id INT NOT NULL DEFAULT 0,
    student_id VARCHAR(50) DEFAULT NULL,
    student_id_number VARCHAR(50) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    preferred_username VARCHAR(100) DEFAULT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    program VARCHAR(200) DEFAULT NULL,
    program_type VARCHAR(50) DEFAULT 'degree',
    campus VARCHAR(100) DEFAULT 'Mzuzu Campus',
    year_of_registration INT DEFAULT NULL,
    year_of_study INT DEFAULT 1,
    semester VARCHAR(10) DEFAULT 'One',
    entry_type VARCHAR(10) DEFAULT 'NE',
    student_type VARCHAR(30) DEFAULT 'new_student',
    selected_modules TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add columns if missing (upgrade path)
try {
    $upgrade_cols = [
        'student_id_number' => "VARCHAR(50) DEFAULT NULL AFTER student_id",
        'preferred_username' => "VARCHAR(100) DEFAULT NULL AFTER last_name",
        'year_of_registration' => "INT DEFAULT NULL AFTER campus",
        'selected_modules' => "TEXT DEFAULT NULL AFTER student_type"
    ];
    foreach ($upgrade_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM student_invite_registrations LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE student_invite_registrations ADD COLUMN $col $def");
        }
    }

    $invite_flag_check = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'dissertation_only'");
    if ($invite_flag_check && $invite_flag_check->num_rows === 0) {
        $conn->query("ALTER TABLE student_registration_invites ADD COLUMN dissertation_only TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
    }
} catch (Throwable $e) {
    error_log("approve_student_accounts.php DDL upgrade error: " . $e->getMessage());
}

// Get departments for reference
$departments = [];
$dept_result = $conn->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[$dept['department_id']] = $dept;
    }
}

// Campus name resolution map (handles numeric IDs or short codes)
$campus_names = [
    '0' => 'Mzuzu Campus',
    '1' => 'Lilongwe Campus',
    '2' => 'Blantyre Campus',
    '3' => 'ODel Campus',
    'MZ' => 'Mzuzu Campus',
    'LL' => 'Lilongwe Campus',
    'BT' => 'Blantyre Campus',
    'ODL' => 'ODel Campus',
];
function resolveCampusName($campus, $map) {
    if ($campus === null || $campus === '') return 'N/A';
    $trimmed = trim((string)$campus);
    if (isset($map[$trimmed])) return $map[$trimmed];
    // Already a full name
    return $trimmed;
}

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_registration'])) {
    $reg_id = (int)$_POST['registration_id'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    
    // Get registration details
    $stmt = $conn->prepare("SELECT r.*, i.program_type as invite_program_type, i.dissertation_only
        FROM student_invite_registrations r
        LEFT JOIN student_registration_invites i ON r.invite_id = i.invite_id
        WHERE r.registration_id = ? AND r.status = 'pending'");
    if (!$stmt) {
        $error = "Database error: " . $conn->error;
    } else {
        $stmt->bind_param("i", $reg_id);
        $stmt->execute();
        $reg = $stmt->get_result()->fetch_assoc();
    }
    
    if (empty($error) && !$reg) {
        $error = "Registration not found or already processed.";
    }
    
    if (empty($error) && $reg) {
        $first_name = $reg['first_name'];
        $middle_name = $reg['middle_name'] ?? '';
        $last_name = $reg['last_name'];
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = $reg['email'];
        $phone = $reg['phone'] ?? '';
        $gender = $reg['gender'] ?? '';
        $national_id = $reg['national_id'] ?? '';
        $address = $reg['address'] ?? '';
        $department_id = (int)$reg['department_id'];
        $program = $reg['program'] ?? '';
        $program_type = $reg['program_type'] ?? 'degree';
        $campus = resolveCampusName($reg['campus'] ?? 'Mzuzu Campus', $campus_names);
        $year_of_study = (int)($reg['year_of_study'] ?? 1);
        $semester = $reg['semester'] ?? 'One';
        $entry_type = $reg['entry_type'] ?? 'NE';
        $student_type = $reg['student_type'] ?? 'new_student';
        $is_dissertation_only = ((int)($reg['dissertation_only'] ?? 0) === 1) || ($student_type === 'dissertation_only');
        // students.student_type is constrained to new_student/continuing in many deployments.
        $student_type_for_students = in_array($student_type, ['new_student', 'continuing'], true) ? $student_type : 'new_student';
        $year_of_registration = date('Y');
        $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');

        // Auto-generate username
        $middle_initial = !empty($middle_name) ? strtolower(substr($middle_name, 0, 1)) : '';
        $base_username = strtolower(substr($first_name, 0, 1) . $middle_initial . preg_replace('/\s+/', '', $last_name));
        $username = $base_username;
        $suffix = 2;
        while (true) {
            $check = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            if ($check->get_result()->fetch_assoc()['cnt'] == 0) break;
            $username = $base_username . $suffix;
            $suffix++;
        }

        // Check email not taken
        $check2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check2->bind_param("s", $email);
        $check2->execute();
        if ($check2->get_result()->num_rows > 0) {
            $error = "Email '{$email}' already exists in the system. Cannot create duplicate account.";
        } else {
            // Use existing student ID if provided, otherwise generate one
            $existing_sid = trim($reg['student_id_number'] ?? '');
            
            if (!empty($existing_sid)) {
                // Validate existing student ID is not already taken
                $sid_check = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                $sid_check->bind_param("s", $existing_sid);
                $sid_check->execute();
                if ($sid_check->get_result()->num_rows > 0) {
                    $error = "Existing Student ID '{$existing_sid}' is already in use. Please review.";
                    $sid_check->close();
                } else {
                    $sid_check->close();
                    $student_id = $existing_sid;
                }
            }
            
            // Generate new student ID if no existing one (or not set yet)
            if (empty($error) && empty($student_id ?? '')) {
                // Get department code for student ID generation
                $dept_data = $departments[$department_id] ?? null;
                if (!$dept_data) {
                    $dept_q = $conn->prepare("SELECT department_id, department_code, department_name FROM departments WHERE department_id = ?");
                    if ($dept_q) {
                        $dept_q->bind_param("i", $department_id);
                        $dept_q->execute();
                        $dept_data = $dept_q->get_result()->fetch_assoc();
                    }
                }
                
                if (!$dept_data) {
                    $error = "Invalid department. Please check the registration details.";
                } else {
                    $dept_code = $dept_data['department_code'];
                    
                    // Generate Student ID
                    $campus_code = 'MZ';
                    if (strpos($campus, 'Lilongwe') !== false) $campus_code = 'LL';
                    elseif (strpos($campus, 'Blantyre') !== false) $campus_code = 'BT';
                    elseif (strpos($campus, 'ODel') !== false) $campus_code = 'ODL';
                    
                    $year_short = substr($year_of_registration, -2);
                    $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
                    $count_query = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
                    $next_num = ($count_query ? ($count_query->fetch_assoc()['count'] ?? 0) : 0) + 1;
                    $sequential = str_pad($next_num, 4, '0', STR_PAD_LEFT);
                    $student_id = $prefix . $sequential;
                }
            }
            
            if (empty($error) && !empty($student_id)) {

                $conn->begin_transaction();
                try {
                    // 1. Create student record
                    $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type, student_type, academic_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssisisssssssssss", $student_id, $full_name, $email, $department_id, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type, $student_type_for_students, $academic_level);
                    $stmt->execute();

                    // 2. Create user account 
                    $temp_password = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$'), 0, 10);
                    $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
                    if ($is_dissertation_only) {
                        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, additional_roles, must_change_password) VALUES (?, ?, ?, 'student', ?, 'dissertation_student', 1)");
                        $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES (?, ?, ?, 'student', ?, 1)");
                        $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                    }
                    $stmt->execute();
                    $new_user_id = $conn->insert_id;

                    // 3. Create student_finances record
                    $expected_total = 0;
                    if (!$is_dissertation_only) {
                        $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                        $fee_settings = $fee_query ? $fee_query->fetch_assoc() : null;
                        
                        $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
                        
                        if ($program_type === 'professional') {
                            $registration_fee = 10000;
                        } else {
                            $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                            $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                            $registration_fee = ($student_type === 'continuing') ? $continuing_reg_fee : $new_student_reg_fee;
                        }
                        
                        $tuition = 500000;
                        switch ($program_type) {
                            case 'professional': $tuition = 200000; break;
                            case 'masters': $tuition = 1100000; break;
                            case 'doctorate': $tuition = 2200000; break;
                        }
                        
                        $expected_total = $application_fee + $registration_fee + $tuition;
                        $expected_tuition = $tuition;
                        
                        $stmt = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, ?, ?, 0, ?, 0, 0)");
                        $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                        $stmt->execute();
                    }

                    // 4. Update registration record
                    $stmt = $conn->prepare("UPDATE student_invite_registrations SET status = 'approved', student_id = ?, user_id = ?, reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE registration_id = ?");
                    $stmt->bind_param("siisi", $student_id, $new_user_id, $user['user_id'], $admin_notes, $reg_id);
                    $stmt->execute();

                    // 5. Enroll student in selected modules/courses
                    $enrolled_modules = 0;
                    if (!$is_dissertation_only && !empty($reg['selected_modules'])) {
                        $module_ids = json_decode($reg['selected_modules'], true);
                        if (is_array($module_ids)) {
                            foreach ($module_ids as $course_id) {
                                $course_id = (int)$course_id;
                                if ($course_id > 0) {
                                    // Check course exists
                                    $ccheck = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_id = ?");
                                    $ccheck->bind_param("i", $course_id);
                                    $ccheck->execute();
                                    if ($ccheck->get_result()->num_rows > 0) {
                                        // Check not already enrolled
                                        $echeck = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                                        $echeck->bind_param("si", $student_id, $course_id);
                                        $echeck->execute();
                                        if ($echeck->get_result()->num_rows === 0) {
                                            $enr = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                                            $enr->bind_param("si", $student_id, $course_id);
                                            if ($enr->execute()) $enrolled_modules++;
                                            $enr->close();
                                        }
                                        $echeck->close();
                                    }
                                    $ccheck->close();
                                }
                            }
                        }
                    }

                    $conn->commit();

                    // 6. Send welcome email
                    $email_status = '';
                    if (isEmailEnabled()) {
                        $email_sent = sendStudentWelcomeEmail($email, $full_name, $student_id, $username, $temp_password, $program, $campus);
                        $email_status = $email_sent ? ' | Welcome email sent.' : ' | Email sending failed.';
                    }

                    $module_info = $enrolled_modules > 0 ? " | Enrolled in {$enrolled_modules} module(s)" : '';
                    if ($is_dissertation_only) {
                        $success = "Student approved! ID: <strong>{$student_id}</strong> | Username: <strong>{$username}</strong> | Access: Dissertation-only" . $email_status;
                    } else {
                        $success = "Student approved! ID: <strong>{$student_id}</strong> | Username: <strong>{$username}</strong> | Invoice: K" . number_format($expected_total) . $module_info . $email_status;
                    }

                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Failed to create student account: " . $e->getMessage();
                    error_log("Approve registration error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                }
            }
        }
    }
}

// Handle rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_registration'])) {
    $reg_id = (int)$_POST['registration_id'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    $reason = trim($_POST['rejection_reason'] ?? 'Your registration has been reviewed and could not be approved at this time.');
    
    try {
        $stmt = $conn->prepare("SELECT * FROM student_invite_registrations WHERE registration_id = ? AND status = 'pending'");
        if (!$stmt) {
            throw new Exception("Database query failed: " . $conn->error);
        }
        $stmt->bind_param("i", $reg_id);
        $stmt->execute();
        $reg = $stmt->get_result()->fetch_assoc();
        
        if (!$reg) {
            $error = "Registration not found or already processed.";
        } else {
            // Try full update first, fallback if columns don't exist
            $update_sql = "UPDATE student_invite_registrations SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE registration_id = ?";
            $stmt2 = $conn->prepare($update_sql);
            
            if (!$stmt2) {
                // Fallback: maybe reviewed_at or admin_notes columns don't exist
                $stmt2 = $conn->prepare("UPDATE student_invite_registrations SET status = 'rejected' WHERE registration_id = ?");
                if (!$stmt2) {
                    throw new Exception("Failed to prepare update: " . $conn->error);
                }
                $stmt2->bind_param("i", $reg_id);
            } else {
                $review_notes = $admin_notes ?: $reason;
                $reviewer_id = $user['user_id'];
                $stmt2->bind_param("isi", $reviewer_id, $review_notes, $reg_id);
            }
            
            if ($stmt2->execute()) {
                // Send rejection email
                try {
                    if (isEmailEnabled() && !empty($reg['email'])) {
                        $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
                        $contact_url = defined('SYSTEM_URL') ? SYSTEM_URL : '/vle-eumw';
                        $body = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                            <div style='background:linear-gradient(135deg,#dc2626,#b91c1c);padding:30px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                <h2 style='margin:0;'>Registration Update</h2>
                            </div>
                            <div style='background:#fff;padding:30px;border:1px solid #e2e8f0;'>
                                <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
                                <p>Thank you for your interest in Exploits University of Malawi.</p>
                                <p>After reviewing your registration, we are unable to approve your application at this time.</p>
                                " . (!empty($reason) ? "<div style='background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin:12px 0;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</div>" : "") . "
                                <p>If you believe this is an error, please contact the admissions office.</p>
                                <div style='text-align:center;margin:20px 0;'>
                                    <a href='" . htmlspecialchars($contact_url) . "' style='display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;'>Visit University Portal</a>
                                </div>
                                <p>Best regards,<br>EUMW Administration</p>
                            </div>
                        </div>";
                        sendEmail($reg['email'], $name, 'Registration Update - EUMW VLE', $body);
                    }
                } catch (Throwable $emailEx) {
                    error_log("Rejection email failed: " . $emailEx->getMessage());
                    // Don't block the rejection if email fails
                }
                $success = "Registration rejected. Student has been notified.";
            } else {
                $error = "Failed to reject registration: " . ($stmt2->error ?? 'Unknown error');
            }
        }
    } catch (Throwable $e) {
        $error = "Failed to reject registration: " . $e->getMessage();
        error_log("Reject registration error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
}

// Handle bulk approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_approve'])) {
    $reg_ids = $_POST['bulk_ids'] ?? [];
    if (!is_array($reg_ids) || empty($reg_ids)) {
        $error = 'No students selected for bulk approval.';
    } else {
        $approved_count = 0;
        $failed_count = 0;
        $bulk_errors = [];
        foreach ($reg_ids as $raw_id) {
            $reg_id = (int)$raw_id;
            if ($reg_id <= 0) continue;

            // Fetch pending registration
            $stmt = $conn->prepare("SELECT r.*, i.program_type as invite_program_type, i.dissertation_only
                FROM student_invite_registrations r
                LEFT JOIN student_registration_invites i ON r.invite_id = i.invite_id
                WHERE r.registration_id = ? AND r.status = 'pending'");
            if (!$stmt) { $failed_count++; continue; }
            $stmt->bind_param("i", $reg_id);
            $stmt->execute();
            $reg = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$reg) { $failed_count++; continue; }

            $first_name = $reg['first_name'];
            $middle_name = $reg['middle_name'] ?? '';
            $last_name = $reg['last_name'];
            $full_name = trim(preg_replace('/\s+/', ' ', $first_name . ' ' . $middle_name . ' ' . $last_name));
            $b_email = $reg['email'];
            $phone = $reg['phone'] ?? '';
            $gender = $reg['gender'] ?? '';
            $national_id = $reg['national_id'] ?? '';
            $address = $reg['address'] ?? '';
            $department_id = (int)$reg['department_id'];
            $program = $reg['program'] ?? '';
            $program_type = $reg['program_type'] ?? 'degree';
            $campus = resolveCampusName($reg['campus'] ?? 'Mzuzu Campus', $campus_names);
            $year_of_study = (int)($reg['year_of_study'] ?? 1);
            $semester = $reg['semester'] ?? 'One';
            $entry_type = $reg['entry_type'] ?? 'NE';
            $student_type = $reg['student_type'] ?? 'new_student';
            $is_dissertation_only = ((int)($reg['dissertation_only'] ?? 0) === 1) || ($student_type === 'dissertation_only');
            $student_type_for_students = in_array($student_type, ['new_student', 'continuing'], true) ? $student_type : 'new_student';
            $year_of_registration = date('Y');
            $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');

            // Auto-generate username
            $middle_initial = !empty($middle_name) ? strtolower(substr($middle_name, 0, 1)) : '';
            $base_username = strtolower(substr($first_name, 0, 1) . $middle_initial . preg_replace('/\s+/', '', $last_name));
            $username = $base_username;
            $suffix = 2;
            while (true) {
                $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = ?");
                $chk->bind_param("s", $username);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()['cnt'] == 0) { $chk->close(); break; }
                $chk->close();
                $username = $base_username . $suffix;
                $suffix++;
            }

            // Check email not taken
            $chk2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $chk2->bind_param("s", $b_email);
            $chk2->execute();
            if ($chk2->get_result()->num_rows > 0) {
                $chk2->close();
                $bulk_errors[] = htmlspecialchars($full_name) . ': email already exists';
                $failed_count++;
                continue;
            }
            $chk2->close();

            // Resolve or generate student ID
            $student_id = '';
            $existing_sid = trim($reg['student_id_number'] ?? '');
            if (!empty($existing_sid)) {
                $sid_chk = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                $sid_chk->bind_param("s", $existing_sid);
                $sid_chk->execute();
                if ($sid_chk->get_result()->num_rows > 0) {
                    $sid_chk->close();
                    $bulk_errors[] = htmlspecialchars($full_name) . ': student ID already in use';
                    $failed_count++;
                    continue;
                }
                $sid_chk->close();
                $student_id = $existing_sid;
            } else {
                $dept_data = $departments[$department_id] ?? null;
                if (!$dept_data) {
                    $dept_q = $conn->prepare("SELECT department_id, department_code, department_name FROM departments WHERE department_id = ?");
                    if ($dept_q) { $dept_q->bind_param("i", $department_id); $dept_q->execute(); $dept_data = $dept_q->get_result()->fetch_assoc(); $dept_q->close(); }
                }
                if (!$dept_data) { $bulk_errors[] = htmlspecialchars($full_name) . ': invalid department'; $failed_count++; continue; }
                $dept_code = $dept_data['department_code'];
                $campus_code = 'MZ';
                if (strpos($campus, 'Lilongwe') !== false) $campus_code = 'LL';
                elseif (strpos($campus, 'Blantyre') !== false) $campus_code = 'BT';
                elseif (strpos($campus, 'ODel') !== false) $campus_code = 'ODL';
                $year_short = substr($year_of_registration, -2);
                $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
                $cnt_q = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
                $next_num = ($cnt_q ? ($cnt_q->fetch_assoc()['count'] ?? 0) : 0) + 1;
                $student_id = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type, student_type, academic_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisisssssssssss", $student_id, $full_name, $b_email, $department_id, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type, $student_type_for_students, $academic_level);
                $stmt->execute(); $stmt->close();

                $temp_password = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$'), 0, 10);
                $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
                if ($is_dissertation_only) {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, additional_roles, must_change_password) VALUES (?, ?, ?, 'student', ?, 'dissertation_student', 1)");
                    $stmt->bind_param("ssss", $username, $b_email, $password_hash, $student_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id, must_change_password) VALUES (?, ?, ?, 'student', ?, 1)");
                    $stmt->bind_param("ssss", $username, $b_email, $password_hash, $student_id);
                }
                $stmt->execute();
                $new_user_id = $conn->insert_id;
                $stmt->close();

                $expected_total = 0;
                if (!$is_dissertation_only) {
                    $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                    $fee_settings = $fee_query ? $fee_query->fetch_assoc() : null;
                    $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
                    if ($program_type === 'professional') { $registration_fee = 10000; }
                    else { $registration_fee = ($student_type === 'continuing') ? ($fee_settings['continuing_reg_fee'] ?? 35000) : ($fee_settings['new_student_reg_fee'] ?? 39500); }
                    $tuition = 500000;
                    switch ($program_type) { case 'professional': $tuition = 200000; break; case 'masters': $tuition = 1100000; break; case 'doctorate': $tuition = 2200000; break; }
                    $expected_total = $application_fee + $registration_fee + $tuition;
                    $expected_tuition = $tuition;
                    $stmt = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, ?, ?, 0, ?, 0, 0)");
                    $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                    $stmt->execute(); $stmt->close();
                }

                $bulk_admin_notes = 'Bulk approved';
                $stmt = $conn->prepare("UPDATE student_invite_registrations SET status = 'approved', student_id = ?, user_id = ?, reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE registration_id = ?");
                $stmt->bind_param("siisi", $student_id, $new_user_id, $user['user_id'], $bulk_admin_notes, $reg_id);
                $stmt->execute(); $stmt->close();

                // Enroll in selected modules
                if (!$is_dissertation_only && !empty($reg['selected_modules'])) {
                    $module_ids = json_decode($reg['selected_modules'], true);
                    if (is_array($module_ids)) {
                        foreach ($module_ids as $cid) {
                            $cid = (int)$cid;
                            if ($cid > 0) {
                                $enr = $conn->prepare("INSERT IGNORE INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                                if ($enr) { $enr->bind_param("si", $student_id, $cid); $enr->execute(); $enr->close(); }
                            }
                        }
                    }
                }

                $conn->commit();
                $approved_count++;

                // Send welcome email (non-blocking)
                try {
                    if (isEmailEnabled()) {
                        sendStudentWelcomeEmail($b_email, $full_name, $student_id, $username, $temp_password, $program, $campus);
                    }
                } catch (Throwable $emailEx) {
                    error_log("Bulk approve email failed for {$b_email}: " . $emailEx->getMessage());
                }

            } catch (Throwable $e) {
                $conn->rollback();
                $bulk_errors[] = htmlspecialchars($full_name) . ': ' . $e->getMessage();
                $failed_count++;
                error_log("Bulk approve error for reg #{$reg_id}: " . $e->getMessage());
            }
        }

        if ($approved_count > 0) {
            $success = "<strong>{$approved_count}</strong> student(s) approved successfully.";
        }
        if ($failed_count > 0) {
            $err_detail = !empty($bulk_errors) ? '<br><small>' . implode('<br>', $bulk_errors) . '</small>' : '';
            $error = "{$failed_count} student(s) failed to approve." . $err_detail;
        }
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'pending';
$valid_campuses_list = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];

// AJAX: auto-save campus for pending registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_reg_campus') {
    header('Content-Type: application/json');
    $reg_id = (int)($_POST['registration_id'] ?? 0);
    $new_campus = trim($_POST['new_campus'] ?? '');
    if ($reg_id <= 0 || !in_array($new_campus, $valid_campuses_list, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        exit;
    }
    $upd = $conn->prepare("UPDATE student_invite_registrations SET campus = ? WHERE registration_id = ? AND status = 'pending'");
    $upd->bind_param('si', $new_campus, $reg_id);
    if ($upd->execute() && $upd->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed or registration already processed.']);
    }
    $upd->close();
    exit;
}

// Handle campus update for pending registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reg_campus'])) {
    $reg_id = (int)($_POST['registration_id'] ?? 0);
    $new_campus = trim($_POST['new_campus'] ?? '');
    if ($reg_id <= 0) {
        $error = 'Invalid registration selected.';
    } elseif (!in_array($new_campus, $valid_campuses_list, true)) {
        $error = 'Invalid campus selected.';
    } else {
        $upd = $conn->prepare("UPDATE student_invite_registrations SET campus = ? WHERE registration_id = ? AND status = 'pending'");
        $upd->bind_param('si', $new_campus, $reg_id);
        if ($upd->execute() && $upd->affected_rows > 0) {
            $success = "Campus updated to <strong>" . htmlspecialchars($new_campus) . "</strong> for registration #" . $reg_id . ".";
        } else {
            $error = 'Failed to update campus. Registration may already be processed.';
        }
        $upd->close();
    }
}

$where = "1=1";
if ($filter === 'pending') $where = "r.status = 'pending'";
elseif ($filter === 'approved') $where = "r.status = 'approved'";
elseif ($filter === 'rejected') $where = "r.status = 'rejected'";

// Count by status
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
$cnt_q = $conn->query("SELECT status, COUNT(*) as cnt FROM student_invite_registrations GROUP BY status");
if ($cnt_q) { while ($c = $cnt_q->fetch_assoc()) { $counts[$c['status']] = (int)$c['cnt']; $counts['all'] += (int)$c['cnt']; } }

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total_q = $conn->query("SELECT COUNT(*) as cnt FROM student_invite_registrations r WHERE $where");
$total_regs = $total_q ? $total_q->fetch_assoc()['cnt'] : 0;
$total_pages = max(1, ceil($total_regs / $per_page));

$registrations = [];
$q = $conn->query("SELECT r.*, d.department_name, d.department_code,
    u.username as reviewer_name
    FROM student_invite_registrations r
    LEFT JOIN departments d ON r.department_id = d.department_id
    LEFT JOIN users u ON r.reviewed_by = u.user_id
    WHERE $where
    ORDER BY r.registered_at DESC
    LIMIT $per_page OFFSET $offset");
if ($q) { while ($row = $q->fetch_assoc()) $registrations[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Student Registrations - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .reg-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s;
        }
        .reg-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .reg-card .card-header {
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .reg-card .card-body { padding: 20px; }
        .stat-pills { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        .stat-pill {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 50px;
            font-size: 0.85rem; font-weight: 600;
            text-decoration: none; color: inherit;
            border: 2px solid #e2e8f0; background: #fff;
            transition: all 0.2s;
        }
        .stat-pill:hover { border-color: #a5b4fc; color: inherit; }
        .stat-pill.active { border-color: #4f46e5; background: #eef2ff; color: #4f46e5; }
        .stat-pill .pill-count {
            background: #e2e8f0; color: #475569; width: 28px; height: 28px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
        }
        .stat-pill.active .pill-count { background: #4f46e5; color: #fff; }
        .stat-pill.pending .pill-count { background: #fbbf24; color: #000; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        .info-item { font-size: 0.85rem; }
        .info-item .label { color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .info-item .value { color: #1e293b; font-weight: 500; }
        .badge-pending { background: #fef3c7; color: #92400e; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-approved { background: #dcfce7; color: #166534; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-rejected { background: #fee2e2; color: #991b1b; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .action-btns { display: flex; gap: 8px; flex-wrap: wrap; }
        .bulk-bar {
            position: sticky; top: 0; z-index: 100;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff; padding: 12px 24px; border-radius: 12px;
            display: none; align-items: center; justify-content: space-between;
            margin-bottom: 16px; box-shadow: 0 4px 16px rgba(79,70,229,0.3);
        }
        .bulk-bar.show { display: flex; }
        .bulk-bar .bulk-count { font-weight: 700; font-size: 1rem; }
        .bulk-bar .btn { border-radius: 8px; font-weight: 600; }
        .bulk-check { width: 20px; height: 20px; cursor: pointer; accent-color: #4f46e5; }
        .select-all-wrap { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .select-all-wrap label { font-size: 0.85rem; font-weight: 600; color: #475569; cursor: pointer; user-select: none; }
        .reg-campus-select { min-width: 150px; transition: background-color 0.4s, border-color 0.4s; }
        .reg-campus-select.saving { opacity: 0.6; pointer-events: none; }
        .reg-campus-select.saved { background-color: #d1fae5 !important; border-color: #10b981 !important; }
        .reg-campus-select.save-error { background-color: #fee2e2 !important; border-color: #ef4444 !important; }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Approve Student Accounts']];
    include 'header_nav.php'; 
    ?>
    <div class="vle-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-clipboard-check me-2 text-primary"></i>Student Registration Approvals</h4>
                <p class="text-muted mb-0" style="font-size:0.85rem;">Review and approve student registrations submitted via invite links</p>
            </div>
            <a href="student_invite_links.php" class="btn btn-outline-primary">
                <i class="bi bi-link-45deg me-1"></i> Manage Invite Links
            </a>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filter pills -->
        <div class="stat-pills">
            <a href="?filter=pending" class="stat-pill <?= $filter === 'pending' ? 'active pending' : '' ?>">
                <i class="bi bi-clock-history"></i> Pending
                <span class="pill-count"><?= $counts['pending'] ?></span>
            </a>
            <a href="?filter=approved" class="stat-pill <?= $filter === 'approved' ? 'active' : '' ?>">
                <i class="bi bi-check-circle"></i> Approved
                <span class="pill-count"><?= $counts['approved'] ?></span>
            </a>
            <a href="?filter=rejected" class="stat-pill <?= $filter === 'rejected' ? 'active' : '' ?>">
                <i class="bi bi-x-circle"></i> Rejected
                <span class="pill-count"><?= $counts['rejected'] ?></span>
            </a>
            <a href="?filter=all" class="stat-pill <?= $filter === 'all' ? 'active' : '' ?>">
                <i class="bi bi-list"></i> All
                <span class="pill-count"><?= $counts['all'] ?></span>
            </a>
        </div>

        <!-- Bulk action bar -->
        <div class="bulk-bar" id="bulkBar">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-check2-square" style="font-size:1.3rem;"></i>
                <span class="bulk-count"><span id="bulkCount">0</span> student(s) selected</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-light btn-sm" onclick="deselectAll()"><i class="bi bi-x-lg me-1"></i>Deselect All</button>
                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#bulkApproveModal"><i class="bi bi-check-all me-1"></i>Bulk Approve</button>
            </div>
        </div>

        <?php if ($filter === 'pending' && !empty($registrations)): ?>
        <div class="select-all-wrap">
            <input type="checkbox" class="bulk-check" id="selectAllCheck" onchange="toggleSelectAll(this)">
            <label for="selectAllCheck">Select all on this page</label>
        </div>
        <?php endif; ?>

        <?php if (empty($registrations)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size:3rem;color:#cbd5e1;"></i>
            <p class="text-muted mt-2">
                <?php if ($filter === 'pending'): ?>
                    No pending registrations to review.
                <?php else: ?>
                    No registrations found for this filter.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php foreach ($registrations as $reg): ?>
        <div class="reg-card">
            <div class="card-header">
                <div class="d-flex align-items-center gap-3">
                    <?php if ($reg['status'] === 'pending'): ?>
                    <input type="checkbox" class="bulk-check bulk-student-check" value="<?= $reg['registration_id'] ?>" data-name="<?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?>" onchange="updateBulkBar()">
                    <?php endif; ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">
                        <?= strtoupper(substr($reg['first_name'],0,1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:1rem;">
                            <?= htmlspecialchars($reg['first_name'] . ' ' . ($reg['middle_name'] ? $reg['middle_name'] . ' ' : '') . $reg['last_name']) ?>
                        </div>
                        <div style="font-size:0.8rem;color:#64748b;">
                            <?= htmlspecialchars($reg['email']) ?>
                            <?php if ($reg['phone']): ?> &bull; <?= htmlspecialchars($reg['phone']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($reg['status'] === 'pending'): ?>
                        <span class="badge-pending"><i class="bi bi-clock me-1"></i>Pending Review</span>
                    <?php elseif ($reg['status'] === 'approved'): ?>
                        <span class="badge-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>
                    <?php else: ?>
                        <span class="badge-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="info-grid mb-3">
                    <?php if (!empty($reg['student_id_number'])): ?>
                    <div class="info-item">
                        <div class="label">Existing Student ID</div>
                        <div class="value" style="color:#7c3aed;font-weight:600;"><?= htmlspecialchars($reg['student_id_number']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['preferred_username'])): ?>
                    <div class="info-item">
                        <div class="label">Preferred Username</div>
                        <div class="value" style="color:#0ea5e9;font-weight:600;"><?= htmlspecialchars($reg['preferred_username']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="label">Department</div>
                        <div class="value"><?= htmlspecialchars($reg['department_name'] ?? 'Not specified') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Program</div>
                        <div class="value"><?= htmlspecialchars($reg['program'] ?? 'Not specified') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Program Type</div>
                        <div class="value"><?= ucfirst(htmlspecialchars($reg['program_type'] ?? 'degree')) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Campus</div>
                        <div class="value">
                            <?php if ($reg['status'] === 'pending'): ?>
                            <select class="form-select form-select-sm reg-campus-select"
                                    data-reg-id="<?= (int)$reg['registration_id'] ?>">
                                <?php foreach ($valid_campuses_list as $vc): ?>
                                <option value="<?= htmlspecialchars($vc) ?>" <?= resolveCampusName($reg['campus'] ?? '', $campus_names) === $vc ? 'selected' : '' ?>><?= htmlspecialchars($vc) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <?= htmlspecialchars(resolveCampusName($reg['campus'] ?? '', $campus_names)) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Gender</div>
                        <div class="value"><?= htmlspecialchars($reg['gender'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">National ID</div>
                        <div class="value"><?= htmlspecialchars($reg['national_id'] ?: 'N/A') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Entry Type</div>
                        <div class="value"><?= htmlspecialchars($reg['entry_type'] ?? 'NE') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Year of Registration</div>
                        <div class="value"><?= htmlspecialchars($reg['year_of_registration'] ?? date('Y')) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Year / Semester</div>
                        <div class="value">Year <?= $reg['year_of_study'] ?? 1 ?> / Sem <?= htmlspecialchars($reg['semester'] ?? 'One') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Student Type</div>
                        <div class="value"><?= ucfirst(str_replace('_', ' ', $reg['student_type'] ?? 'new_student')) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Submitted</div>
                        <div class="value"><?= date('M j, Y g:i A', strtotime($reg['registered_at'])) ?></div>
                    </div>
                    <?php if ($reg['address']): ?>
                    <div class="info-item">
                        <div class="label">Address</div>
                        <div class="value"><?= htmlspecialchars($reg['address']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($reg['selected_modules'])): ?>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="label">Selected Modules</div>
                        <div class="value">
                            <?php 
                            $mod_decoded = json_decode($reg['selected_modules'], true);
                            // Graduation students store JSON object in selected_modules; regular students store int arrays
                            $mod_ids = (is_array($mod_decoded) && array_keys($mod_decoded) === range(0, count($mod_decoded) - 1))
                                ? array_filter($mod_decoded, 'is_int')
                                : [];
                            $mod_ids = array_values($mod_ids);
                            if (count($mod_ids) > 0):
                                $placeholders = implode(',', array_fill(0, count($mod_ids), '?'));
                                $mod_stmt = $conn->prepare("SELECT course_code, course_name FROM vle_courses WHERE course_id IN ($placeholders)");
                                $types_str = str_repeat('i', count($mod_ids));
                                $mod_stmt->bind_param($types_str, ...$mod_ids);
                                $mod_stmt->execute();
                                $mod_result = $mod_stmt->get_result();
                                $mod_list = [];
                                while ($m = $mod_result->fetch_assoc()) {
                                    $mod_list[] = '<span style="display:inline-block;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:2px 8px;margin:2px;font-size:0.78rem;"><strong>' . htmlspecialchars($m['course_code']) . '</strong> ' . htmlspecialchars($m['course_name']) . '</span>';
                                }
                                $mod_stmt->close();
                                echo implode(' ', $mod_list);
                                echo ' <span style="color:#6366f1;font-size:0.75rem;font-weight:600;">(' . count($mod_ids) . ' modules)</span>';
                            elseif (is_array($mod_decoded) && !empty($mod_decoded)):
                                // Graduation student data stored as JSON object – show summary
                                echo '<span class="text-muted fst-italic">Graduation application data stored</span>';
                            else:
                                echo '<span class="text-muted">None selected</span>';
                            endif;
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($reg['student_id']): ?>
                    <div class="info-item">
                        <div class="label">Student ID</div>
                        <div class="value" style="color:#4f46e5;font-weight:600;"><?= htmlspecialchars($reg['student_id']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($reg['status'] !== 'pending'): ?>
                <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;font-size:0.8rem;color:#475569;margin-top:8px;">
                    <strong><?= ucfirst($reg['status']) ?></strong> by <?= htmlspecialchars($reg['reviewer_name'] ?? 'System') ?>
                    on <?= $reg['reviewed_at'] ? date('M j, Y g:i A', strtotime($reg['reviewed_at'])) : 'N/A' ?>
                    <?php if ($reg['admin_notes']): ?>
                    <br><em>Notes: <?= htmlspecialchars($reg['admin_notes']) ?></em>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($reg['status'] === 'pending'): ?>
                <hr style="border-color:#e2e8f0;">
                <div class="action-btns">
                    <!-- Approve Button -->
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= $reg['registration_id'] ?>">
                        <i class="bi bi-check-circle me-1"></i> Approve & Create Account
                    </button>
                    <!-- Reject Button -->
                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $reg['registration_id'] ?>">
                        <i class="bi bi-x-circle me-1"></i> Reject
                    </button>
                </div>

                <!-- Approve Modal -->
                <div class="modal fade" id="approveModal<?= $reg['registration_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content" style="border-radius:16px;border:none;">
                            <div class="modal-header" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:16px 16px 0 0;">
                                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve Registration</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                                    <div class="alert alert-info" style="border-radius:10px;font-size:0.85rem;">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Approving will create:
                                        <ul class="mb-0 mt-1">
                                            <li>Student record with auto-generated Student ID</li>
                                            <li>User account (username + temp password)</li>
                                            <li>Finance invoice</li>
                                            <?php if (!empty($reg['selected_modules'])): ?>
                                            <li>Enroll in <?= count(json_decode($reg['selected_modules'], true) ?: []) ?> selected module(s)</li>
                                            <?php endif; ?>
                                            <li>Welcome email with login credentials</li>
                                        </ul>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Student:</strong> <?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?><br>
                                        <strong>Email:</strong> <?= htmlspecialchars($reg['email']) ?><br>
                                        <strong>Program:</strong> <?= htmlspecialchars($reg['program'] ?? 'N/A') ?><br>
                                        <strong>Campus:</strong> <?= htmlspecialchars(resolveCampusName($reg['campus'] ?? 'N/A', $campus_names)) ?>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Admin Notes <small class="text-muted">(optional)</small></label>
                                        <textarea name="admin_notes" class="form-control" rows="2" placeholder="Internal notes..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="approve_registration" class="btn btn-success">
                                        <i class="bi bi-check-circle me-1"></i> Confirm Approval
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Reject Modal -->
                <div class="modal fade" id="rejectModal<?= $reg['registration_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content" style="border-radius:16px;border:none;">
                            <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:16px 16px 0 0;">
                                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Registration</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                                    <p>
                                        Reject registration for 
                                        <strong><?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?></strong>
                                        (<?= htmlspecialchars($reg['email']) ?>)?
                                    </p>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Rejection Reason <small class="text-muted">(sent to student)</small></label>
                                        <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Please explain why this registration cannot be approved..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Admin Notes <small class="text-muted">(internal only)</small></label>
                                        <textarea name="admin_notes" class="form-control" rows="2" placeholder="Internal notes..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="reject_registration" class="btn btn-danger">
                                        <i class="bi bi-x-circle me-1"></i> Confirm Rejection
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&filter=<?= $filter ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <!-- Bulk Approve Confirmation Modal -->
    <div class="modal fade" id="bulkApproveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-check-all me-2"></i>Bulk Approve Students</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="bulkApproveForm">
                    <div class="modal-body">
                        <div class="alert alert-warning" style="border-radius:10px;font-size:0.85rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            You are about to approve <strong><span id="bulkModalCount">0</span></strong> student(s). This will:
                            <ul class="mb-0 mt-1">
                                <li>Create student records with auto-generated IDs</li>
                                <li>Create user accounts (username + temp password)</li>
                                <li>Generate finance invoices</li>
                                <li>Send welcome emails</li>
                            </ul>
                        </div>
                        <div id="bulkStudentList" style="max-height:200px;overflow-y:auto;font-size:0.85rem;border:1px solid #e2e8f0;border-radius:8px;padding:12px;"></div>
                        <div id="bulkHiddenInputs"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="bulk_approve" class="btn btn-success">
                            <i class="bi bi-check-all me-1"></i> Confirm Bulk Approval
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function getCheckedBoxes() {
        return document.querySelectorAll('.bulk-student-check:checked');
    }
    function updateBulkBar() {
        var checked = getCheckedBoxes();
        var bar = document.getElementById('bulkBar');
        var countEl = document.getElementById('bulkCount');
        countEl.textContent = checked.length;
        if (checked.length > 0) {
            bar.classList.add('show');
        } else {
            bar.classList.remove('show');
        }
        // Sync select-all checkbox
        var allBoxes = document.querySelectorAll('.bulk-student-check');
        var selectAll = document.getElementById('selectAllCheck');
        if (selectAll) {
            selectAll.checked = allBoxes.length > 0 && checked.length === allBoxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < allBoxes.length;
        }
    }
    function toggleSelectAll(el) {
        var boxes = document.querySelectorAll('.bulk-student-check');
        boxes.forEach(function(cb) { cb.checked = el.checked; });
        updateBulkBar();
    }
    function deselectAll() {
        var boxes = document.querySelectorAll('.bulk-student-check');
        boxes.forEach(function(cb) { cb.checked = false; });
        var selectAll = document.getElementById('selectAllCheck');
        if (selectAll) selectAll.checked = false;
        updateBulkBar();
    }
    // Populate bulk modal before showing
    var bulkModal = document.getElementById('bulkApproveModal');
    if (bulkModal) {
        bulkModal.addEventListener('show.bs.modal', function() {
            var checked = getCheckedBoxes();
            document.getElementById('bulkModalCount').textContent = checked.length;
            var listEl = document.getElementById('bulkStudentList');
            var inputsEl = document.getElementById('bulkHiddenInputs');
            var listHtml = '';
            var inputsHtml = '';
            checked.forEach(function(cb, i) {
                listHtml += '<div>' + (i+1) + '. ' + cb.getAttribute('data-name') + '</div>';
                inputsHtml += '<input type="hidden" name="bulk_ids[]" value="' + cb.value + '">';
            });
            listEl.innerHTML = listHtml || '<em class="text-muted">No students selected</em>';
            inputsEl.innerHTML = inputsHtml;
        });
    }
    </script>
    <script>
    document.querySelectorAll('.reg-campus-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var regId = sel.dataset.regId;
            var campus = sel.value;
            sel.classList.add('saving');
            sel.classList.remove('saved', 'save-error');
            var fd = new FormData();
            fd.append('action', 'ajax_update_reg_campus');
            fd.append('registration_id', regId);
            fd.append('new_campus', campus);
            fetch('approve_student_accounts.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    sel.classList.remove('saving');
                    if (data.success) {
                        sel.classList.add('saved');
                        setTimeout(function() { sel.classList.remove('saved'); }, 2000);
                    } else {
                        sel.classList.add('save-error');
                        setTimeout(function() { sel.classList.remove('save-error'); }, 3000);
                        alert('Save failed: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(function() {
                    sel.classList.remove('saving');
                    sel.classList.add('save-error');
                    setTimeout(function() { sel.classList.remove('save-error'); }, 3000);
                    alert('Network error. Please try again.');
                });
        });
    });
    </script>
</body>
</html>
