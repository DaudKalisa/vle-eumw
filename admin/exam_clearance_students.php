<?php
/**
 * Admin - Manage Exam Clearance Students
 * Admin can view all exam clearance students and convert external students to system students.
 */
require_once '../includes/auth.php';
require_once '../includes/exam_clearance_helpers.php';
requireLogin();
requireRole(['admin', 'super_admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Handle photo upload (multipart form, separate from action-based handlers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo' && isset($_POST['clearance_id'])) {
    $cid = (int)$_POST['clearance_id'];
    if (empty($_FILES['profile_photo']['name'])) {
        $error = 'Please select an image file to upload.';
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ftype = mime_content_type($_FILES['profile_photo']['tmp_name']);
        if (!in_array($ftype, $allowed_types, true)) {
            $error = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
        } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
            $error = 'Image must be under 2 MB.';
        } else {
            $ext  = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $safe_ext = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'jpg';
            $fname = 'ec_' . $cid . '_' . time() . '.' . $safe_ext;
            $dest  = __DIR__ . '/../uploads/exam_clearance_profiles/' . $fname;
            if (!is_dir(__DIR__ . '/../uploads/exam_clearance_profiles/')) {
                mkdir(__DIR__ . '/../uploads/exam_clearance_profiles/', 0755, true);
            }
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest)) {
                // Remove old photo file if present
                $old_stmt = $conn->prepare("SELECT profile_photo FROM exam_clearance_students WHERE clearance_id=?");
                $old_stmt->bind_param('i', $cid);
                $old_stmt->execute();
                $old_row = $old_stmt->get_result()->fetch_assoc();
                $old_stmt->close();
                if (!empty($old_row['profile_photo'])) {
                    $old_path = __DIR__ . '/../uploads/exam_clearance_profiles/' . basename($old_row['profile_photo']);
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $upd_photo = $conn->prepare("UPDATE exam_clearance_students SET profile_photo=? WHERE clearance_id=?");
                $upd_photo->bind_param('si', $fname, $cid);
                $upd_photo->execute();
                $upd_photo->close();
                $success = 'Profile photo uploaded successfully.';
            } else {
                $error = 'Failed to save the photo. Check folder permissions.';
            }
        }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Convert external student to full system student
    if ($_POST['action'] === 'convert_to_student') {
        $clearance_id = (int)($_POST['clearance_id'] ?? 0);
        
        // Load student
        $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt->bind_param("i", $clearance_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        
        if (!$student) {
            $error = 'Student not found.';
        } else {
            // Ensure converted_to_student column exists
            $col_check = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_student'");
            if ($col_check && $col_check->num_rows === 0) {
                $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_student TINYINT(1) DEFAULT 0 AFTER is_system_student");
                $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_at DATETIME DEFAULT NULL AFTER converted_to_student");
            }
            
            if (!empty($student['converted_to_student'])) {
                $error = 'This student has already been converted to a full system student.';
            } else {
                // Check if student_id already exists in students table
                $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                $check_stmt->bind_param("s", $student['student_id']);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'A student with ID ' . htmlspecialchars($student['student_id']) . ' already exists in the system.';
                } else {
                    // Insert into students table
                    $ins = $conn->prepare("INSERT INTO students (student_id, full_name, email, phone, program, department, campus, year_of_study, gender, national_id, address, entry_type, semester, year_of_registration) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->bind_param("sssssssisssssi",
                        $student['student_id'],
                        $student['full_name'],
                        $student['email'],
                        $student['phone'],
                        $student['program'],
                        $student['department'],
                        $student['campus'],
                        $student['year_of_study'],
                        $student['gender'],
                        $student['national_id'],
                        $student['address'],
                        $student['entry_type'],
                        $student['semester'],
                        $student['year_of_registration']
                    );
                    
                    if ($ins->execute()) {
                        // Update the user account role from exam_clearance_student to student
                        $conn->query("UPDATE users SET role = 'student', related_student_id = '" . $conn->real_escape_string($student['student_id']) . "' WHERE email = '" . $conn->real_escape_string($student['email']) . "' AND role = 'exam_clearance_student'");
                        
                        // Mark as converted
                        $conn->query("UPDATE exam_clearance_students SET converted_to_student = 1, converted_at = NOW(), is_system_student = 1 WHERE clearance_id = $clearance_id");
                        
                        $success = 'Student "' . htmlspecialchars($student['full_name']) . '" successfully converted to full system student! They can now access all student features.';
                    } else {
                        $error = 'Failed to convert student: ' . $conn->error;
                    }
                }
            }
        }
    }
    
    // Convert exam clearance student to dissertation-only student
    if ($_POST['action'] === 'convert_to_dissertation_student' && isset($_POST['clearance_id'])) {
        $clearance_id = (int)$_POST['clearance_id'];

        // Ensure tracking column exists
        $col_check = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_dissertation'");
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_dissertation TINYINT(1) DEFAULT 0 AFTER converted_at");
            $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_dissertation_at DATETIME DEFAULT NULL AFTER converted_to_dissertation");
        }

        $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt->bind_param("i", $clearance_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $error = 'Student not found.';
        } elseif (!empty($student['converted_to_dissertation'])) {
            $error = 'This student has already been converted to a dissertation-only student.';
        } else {
            // Update the user account role to dissertation_student and set related_student_id
            // so the session's vle_related_id is populated correctly on next login
            $upd = $conn->prepare("UPDATE users SET role = 'dissertation_student', is_active = 1, related_student_id = ? WHERE email = ? AND role = 'exam_clearance_student'");
            $upd->bind_param("ss", $student['student_id'], $student['email']);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();

            if ($affected > 0) {
                // Insert / refresh a students table row so the portal can find the student by student_id.
                // This lets dissertation.php, finance pages, and all other portal pages work without
                // relying on the exam_clearance_students fallback.
                $dept_val   = substr($student['department'] ?? '', 0, 50);
                $prog_val   = substr($student['program']    ?? '', 0, 100);
                $name_val   = substr($student['full_name']  ?? '', 0, 100);
                $pt_val     = in_array($student['program_type'] ?? '', ['degree','diploma','professional','masters','doctorate'])
                                ? $student['program_type'] : 'degree';
                $sem_val    = in_array($student['semester'] ?? '', ['One','Two']) ? $student['semester'] : 'One';
                $gen_val    = in_array($student['gender'] ?? '', ['Male','Female','Other']) ? $student['gender'] : null;
                $yr_reg     = !empty($student['year_of_registration']) ? (int)$student['year_of_registration'] : (int)date('Y');
                $yos_val    = max(1, (int)($student['year_of_study'] ?? 4));
                $sid_val    = $student['student_id'];
                $email_val  = substr($student['email'] ?? '', 0, 100);
                $phone_val  = substr($student['phone'] ?? '', 0, 20);
                $nid_val    = substr($student['national_id'] ?? '', 0, 50);
                $addr_val   = $student['address'] ?? null;
                $campus_val = substr($student['campus'] ?? '', 0, 50);
                $et_val     = substr($student['entry_type'] ?? 'NE', 0, 10);
                $pic_val    = $student['profile_picture'] ?? null;

                $ins = $conn->prepare(
                    "INSERT INTO students (student_id, full_name, email, phone, department, program_type, program, year_of_study, campus, semester, gender, national_id, address, entry_type, year_of_registration, profile_picture, is_active, student_status, student_type)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'active', 'continuing')
                     ON DUPLICATE KEY UPDATE
                         full_name            = VALUES(full_name),
                         email                = VALUES(email),
                         phone                = VALUES(phone),
                         department           = VALUES(department),
                         program_type         = VALUES(program_type),
                         program              = VALUES(program),
                         year_of_study        = VALUES(year_of_study),
                         campus               = VALUES(campus),
                         semester             = VALUES(semester),
                         gender               = VALUES(gender),
                         national_id          = VALUES(national_id),
                         address              = VALUES(address),
                         entry_type           = VALUES(entry_type),
                         year_of_registration = VALUES(year_of_registration),
                         is_active            = 1,
                         student_status       = 'active'"
                );
                if ($ins) {
                    $ins->bind_param("sssssssissssssis",
                        $sid_val, $name_val, $email_val, $phone_val, $dept_val,
                        $pt_val, $prog_val, $yos_val, $campus_val, $sem_val,
                        $gen_val, $nid_val, $addr_val, $et_val, $yr_reg, $pic_val
                    );
                    $ins->execute();
                    $ins->close();
                }

                // Mark as converted in exam_clearance_students
                $conn->query("UPDATE exam_clearance_students SET converted_to_dissertation = 1, converted_to_dissertation_at = NOW() WHERE clearance_id = $clearance_id");
                $success = 'Student <strong>' . htmlspecialchars($student['full_name']) . '</strong> (' . htmlspecialchars($student['student_id']) . ') has been converted to a dissertation-only student. They can now access the dissertation portal.';
            } else {
                $error = 'No matching exam clearance user account found for this student. The user may not exist or already has a different role.';
            }
        }
    }

    // Unlock account — matches manage_students.php pattern (name="unlock_account")
    if (isset($_POST['unlock_account']) && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $es = $conn->prepare("SELECT email FROM exam_clearance_students WHERE clearance_id=?");
        $es->bind_param("i", $cid);
        $es->execute();
        $es_row = $es->get_result()->fetch_assoc();
        $es->close();
        if ($es_row) {
            $upd = $conn->prepare("UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL, last_failed_login = NULL WHERE email = ? AND role IN ('exam_clearance_student','dissertation_student')");
            $upd->bind_param("s", $es_row['email']);
            $upd->execute();
            if ($upd->affected_rows > 0) {
                $success = 'Account unlocked successfully. Failed login attempts have been reset.';
            } else {
                // User record may not exist yet — clear by email anyway
                $error = 'No matching user account found for this student email.';
            }
            $upd->close();
        } else {
            $error = 'Student not found.';
        }
    }

    // Delete student record
    if ($_POST['action'] === 'delete' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $conn->query("DELETE FROM exam_clearance_payments WHERE clearance_id = $cid");
        $conn->query("DELETE FROM exam_clearance_students WHERE clearance_id = $cid");
        $success = 'Student record deleted.';
    }

    // Edit student details
    if ($_POST['action'] === 'edit_student' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $upd_full_name    = trim($_POST['full_name'] ?? '');
        $upd_email        = trim($_POST['email'] ?? '');
        $upd_phone        = trim($_POST['phone'] ?? '');
        $upd_program_id   = (int)($_POST['program_id'] ?? 0);
        $upd_department_id = (int)($_POST['department_id'] ?? 0);
        $upd_campus       = trim($_POST['campus'] ?? '');
        $upd_year         = (int)($_POST['year_of_study'] ?? 1);
        $upd_semester     = trim($_POST['semester'] ?? 'One');
        $upd_gender       = trim($_POST['gender'] ?? '');
        $upd_national_id  = trim($_POST['national_id'] ?? '');
        $upd_address      = trim($_POST['address'] ?? '');
        $upd_clearance_type = trim($_POST['clearance_type'] ?? '');
        if (!in_array($upd_clearance_type, ['midsemester', 'endsemester'], true)) {
            $upd_clearance_type = 'endsemester';
        }
        $allowed_campuses = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];
        if (!in_array($upd_campus, $allowed_campuses, true)) {
            $upd_campus = 'Mzuzu Campus';
        }
        // Resolve names from IDs
        $upd_program = ''; $upd_program_type = 'degree'; $upd_department = '';
        if ($upd_program_id > 0) {
            $p_stmt = $conn->prepare("SELECT program_name, program_type FROM programs WHERE program_id = ?");
            $p_stmt->bind_param("i", $upd_program_id);
            $p_stmt->execute();
            $p_row = $p_stmt->get_result()->fetch_assoc();
            $p_stmt->close();
            if ($p_row) { $upd_program = $p_row['program_name']; $upd_program_type = $p_row['program_type'] ?? 'degree'; }
        }
        if ($upd_department_id > 0) {
            $d_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $d_stmt->bind_param("i", $upd_department_id);
            $d_stmt->execute();
            $d_row = $d_stmt->get_result()->fetch_assoc();
            $d_stmt->close();
            if ($d_row) { $upd_department = $d_row['department_name']; }
        }
        if (empty($upd_full_name) || empty($upd_email)) {
            $error = 'Full name and email are required.';
        } else {
            // Fetch old email before update for syncing user account
            $old_email_stmt = $conn->prepare("SELECT email FROM exam_clearance_students WHERE clearance_id=?");
            if (!$old_email_stmt) {
                $error = 'Failed to load existing student details. Please try again.';
                error_log('EC Edit: old email prepare failed: ' . $conn->error);
            } else {
                $old_email_stmt->bind_param("i", $cid);
                $old_email_stmt->execute();
                $old_email_row = $old_email_stmt->get_result()->fetch_assoc();
                $old_email_stmt->close();
                $old_email = $old_email_row['email'] ?? '';

                $upd = $conn->prepare("UPDATE exam_clearance_students SET full_name=?, email=?, phone=?, program=?, program_id=?, program_type=?, department=?, department_id=?, campus=?, year_of_study=?, semester=?, gender=?, national_id=?, address=?, clearance_type=?, updated_at=NOW() WHERE clearance_id=?");
                if (!$upd) {
                    $error = 'Failed to prepare update. Please contact administrator.';
                    error_log('EC Edit: update prepare failed: ' . $conn->error);
                } else {
                    // Types: full_name(s) email(s) phone(s) program(s) program_id(i) program_type(s) department(s) department_id(i) campus(s) year_of_study(i) semester(s) gender(s) national_id(s) address(s) clearance_type(s) clearance_id(i) = 16 params
                    $upd->bind_param("ssssissisisssssi",
                        $upd_full_name, $upd_email, $upd_phone,
                        $upd_program, $upd_program_id, $upd_program_type,
                        $upd_department, $upd_department_id,
                        $upd_campus, $upd_year, $upd_semester,
                        $upd_gender, $upd_national_id, $upd_address, $upd_clearance_type, $cid);
                    if ($upd->execute()) {
                        // Sync user account email if it changed
                        // Covers both exam_clearance_student and dissertation_student roles
                        if ($old_email && $old_email !== $upd_email) {
                            $sync = $conn->prepare("UPDATE users SET email=? WHERE email=? AND role IN ('exam_clearance_student','dissertation_student')");
                            if ($sync) {
                                $sync->bind_param("ss", $upd_email, $old_email);
                                $sync->execute();
                                $sync->close();
                            }
                        }
                        $success = 'Student details updated successfully.';
                    } else {
                        $error = 'Failed to update details: ' . $conn->error;
                    }
                    $upd->close();
                }
            }
        }
    }

    // Reset student password
    if ($_POST['action'] === 'reset_password' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        if (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            // Get student details to find user record and send notification
            $es = $conn->prepare("SELECT email, full_name, student_id FROM exam_clearance_students WHERE clearance_id=?");
            $es->bind_param("i", $cid);
            $es->execute();
            $es_row = $es->get_result()->fetch_assoc();
            $es->close();
            if ($es_row) {
                // ── De-duplicate: if multiple user rows share this email, keep the
                //    most relevant one and delete the rest before touching passwords. ──
                $dup_q = $conn->prepare("SELECT user_id, role FROM users WHERE email = ? ORDER BY FIELD(role,'student','exam_clearance_student','dissertation_student') DESC, user_id ASC");
                $dup_q->bind_param("s", $es_row['email']);
                $dup_q->execute();
                $dup_rows = $dup_q->get_result()->fetch_all(MYSQLI_ASSOC);
                $dup_q->close();
                if (count($dup_rows) > 1) {
                    // Keep the first (highest-priority) row; delete all others
                    $keep_id = (int)$dup_rows[0]['user_id'];
                    foreach (array_slice($dup_rows, 1) as $dup) {
                        $del_dup = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                        $del_dup->bind_param("i", $dup['user_id']);
                        $del_dup->execute();
                        $del_dup->close();
                    }
                }
                // Reset password AND clear any account lock simultaneously
                $upd_pw = $conn->prepare("UPDATE users SET password_hash=?, must_change_password=1, failed_login_attempts=0, account_locked_until=NULL, last_failed_login=NULL WHERE email=?");
                $upd_pw->bind_param("ss", $hash, $es_row['email']);
                $upd_pw->execute();
                $affected = $upd_pw->affected_rows;
                $upd_pw->close();
                // If no user account exists yet, auto-create one
                if ($affected === 0) {
                    // Check whether a user with this email already exists (affected_rows=0
                    // can mean "row exists but value unchanged" OR "no row at all")
                    $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                    $chk->bind_param("s", $es_row['email']);
                    $chk->execute();
                    $user_exists = (bool)$chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($user_exists) {
                        // User exists — the UPDATE matched but changed nothing (same hash).
                        // Treat as success.
                        $affected = 1;
                    } else {
                        $parts = explode(' ', trim($es_row['full_name'] ?? ''));
                        $fname_initial = strtolower(substr($parts[0] ?? 'u', 0, 1));
                        $surname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', end($parts) ?: 'student'));
                        $base_username = $fname_initial . $surname;
                        $username_candidate = $base_username;
                        $un_suffix = 1;
                        do {
                            $un_chk = $conn->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
                            $un_chk->bind_param("s", $username_candidate);
                            $un_chk->execute();
                            $exists = (bool)$un_chk->get_result()->fetch_assoc();
                            $un_chk->close();
                            if ($exists) {
                                $username_candidate = $base_username . $un_suffix++;
                            }
                        } while ($exists);
                        $ins = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active, must_change_password) VALUES (?, ?, ?, 'exam_clearance_student', 1, 1)");
                        $ins->bind_param("sss", $username_candidate, $es_row['email'], $hash);
                        $ins->execute();
                        $affected = $ins->affected_rows;
                        $ins->close();
                    } // end else (user does not exist)
                }
                if ($affected > 0) {
                    $login_url = (defined('SITE_URL') ? SITE_URL : 'https://vle.exploitsonline.com') . '/login.php';
                    $content = "
                        <p class='greeting'>Dear " . htmlspecialchars($es_row['full_name'] ?: 'Student') . ",</p>
                        <p class='content-text'>Your password has been reset by the administrator for your Exam Clearance account.</p>
                        <div class='info-box'>
                            <h3>Updated Login Details</h3>
                            <div class='info-row'><span class='info-label'>Student ID</span><span class='info-value'>" . htmlspecialchars($es_row['student_id']) . "</span></div>
                            <div class='info-row'><span class='info-label'>Email</span><span class='info-value'>" . htmlspecialchars($es_row['email']) . "</span></div>
                            <div class='info-row'><span class='info-label'>New Password</span><span class='info-value'><strong>" . htmlspecialchars($new_password) . "</strong></span></div>
                        </div>
                        <p class='content-text'><strong>Important:</strong> You will be asked to change this password after logging in.</p>
                        <p style='text-align:center;margin-top:20px;'><a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:#0d1b4a;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;'>Login to VLE</a></p>
                    ";
                    // Always mark as success once DB is updated — email is best-effort
                    $success = 'Password reset successfully.';
                    // Try to email in the background (non-blocking failure)
                    try {
                        $mail_ok = sendExamClearanceEmail($es_row['email'], $es_row['full_name'] ?: 'Student', 'Password Reset - Exam Clearance Account', $content);
                        if ($mail_ok) {
                            $success = 'Password reset successfully and login details emailed to student.';
                        } else {
                            error_log('EC Admin Reset: Failed to send password reset email to ' . $es_row['email']);
                        }
                    } catch (Exception $mail_ex) {
                        error_log('EC Admin Reset: Email exception for ' . $es_row['email'] . ': ' . $mail_ex->getMessage());
                    }
                } else {
                    $error = 'User account not found for this student.';
                }
            } else {
                $error = 'Student not found.';
            }
        }
    }

    // Approve student — approve for exam clearance only (activate account, keep exam_clearance_student role)
    if ($_POST['action'] === 'approve_student' && isset($_POST['clearance_id'])) {
        $clearance_id = (int)$_POST['clearance_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        $stmt = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
        $stmt->bind_param("i", $clearance_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        
        if (!$student) {
            $error = 'Student not found.';
        } elseif ($student['status'] === 'invoiced' || $student['status'] === 'proof_submitted' || $student['status'] === 'cleared') {
            $error = 'This student has already been approved for exam clearance.';
        } else {
            $conn->begin_transaction();
            try {
                // Activate the user account so they can log in (keep role as exam_clearance_student)
                $activate_stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE email = ? AND role = 'exam_clearance_student'");
                $activate_stmt->bind_param("s", $student['email']);
                $activate_stmt->execute();
                $activate_stmt->close();
                
                // Set status to invoiced (student can now log in and upload proof of payment)
                $upd_stmt = $conn->prepare("UPDATE exam_clearance_students SET status = 'invoiced', updated_at = NOW() WHERE clearance_id = ?");
                $upd_stmt->bind_param("i", $clearance_id);
                $upd_stmt->execute();
                $upd_stmt->close();
                
                // Save admin notes if provided
                if (!empty($admin_notes)) {
                    $notes_stmt = $conn->prepare("UPDATE exam_clearance_students SET admin_notes = ? WHERE clearance_id = ?");
                    $notes_stmt->bind_param("si", $admin_notes, $clearance_id);
                    $notes_stmt->execute();
                    $notes_stmt->close();
                }
                
                $conn->commit();
                $success = '<i class="bi bi-check-circle"></i> Student <strong>' . htmlspecialchars($student['full_name']) . '</strong> (' . htmlspecialchars($student['student_id']) . ') has been approved for exam clearance. Their account is now active and they can log in to upload proof of payment.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to approve student: ' . $e->getMessage();
            }
        }
    }
    
    // Bulk approve — approve for exam clearance only (activate accounts, keep exam_clearance_student role)
    if ($_POST['action'] === 'bulk_approve' && isset($_POST['bulk_ids'])) {
        $bulk_ids = $_POST['bulk_ids'];
        if (!is_array($bulk_ids) || empty($bulk_ids)) {
            $error = 'No students selected for bulk approval.';
        } else {
            $approved = 0;
            $skipped = 0;
            foreach ($bulk_ids as $raw_id) {
                $cid = (int)$raw_id;
                if ($cid <= 0) continue;
                
                $st = $conn->prepare("SELECT * FROM exam_clearance_students WHERE clearance_id = ?");
                $st->bind_param("i", $cid);
                $st->execute();
                $stu = $st->get_result()->fetch_assoc();
                $st->close();
                
                if (!$stu || in_array($stu['status'], ['invoiced', 'proof_submitted', 'cleared'])) { $skipped++; continue; }
                
                $conn->begin_transaction();
                try {
                    // Activate user account (keep role as exam_clearance_student)
                    $act = $conn->prepare("UPDATE users SET is_active = 1 WHERE email = ? AND role = 'exam_clearance_student'");
                    $act->bind_param("s", $stu['email']);
                    $act->execute();
                    $act->close();
                    
                    // Set status to invoiced
                    $upd = $conn->prepare("UPDATE exam_clearance_students SET status = 'invoiced', updated_at = NOW() WHERE clearance_id = ?");
                    $upd->bind_param("i", $cid);
                    $upd->execute();
                    $upd->close();
                    
                    $conn->commit();
                    $approved++;
                } catch (Exception $e) {
                    $conn->rollback();
                    $skipped++;
                }
            }
            $success = "<strong>{$approved}</strong> student(s) approved for exam clearance successfully.";
            if ($skipped > 0) $success .= " ({$skipped} skipped — already approved or failed.)";
        }
    }
    
    // Bulk delete
    if ($_POST['action'] === 'bulk_delete' && isset($_POST['bulk_ids'])) {
        $bulk_ids = $_POST['bulk_ids'];
        if (!is_array($bulk_ids) || empty($bulk_ids)) {
            $error = 'No students selected for bulk delete.';
        } else {
            $deleted = 0;
            foreach ($bulk_ids as $raw_id) {
                $cid = (int)$raw_id;
                if ($cid <= 0) continue;
                $conn->query("DELETE FROM exam_clearance_payments WHERE clearance_id = $cid");
                $conn->query("DELETE FROM exam_clearance_students WHERE clearance_id = $cid");
                $deleted++;
            }
            $success = "<strong>{$deleted}</strong> student record(s) deleted successfully.";
        }
    }

    // Bulk enroll selected EC students by their program/year/semester
    if ($_POST['action'] === 'bulk_enroll_by_program' && isset($_POST['bulk_ids'])) {
        $bulk_ids = $_POST['bulk_ids'];
        if (!is_array($bulk_ids) || empty($bulk_ids)) {
            $error = 'No students selected.';
        } else {
            $total_enrolled  = 0;
            $students_enrolled = 0;
            foreach ($bulk_ids as $raw_id) {
                $cid = (int)$raw_id;
                if ($cid <= 0) continue;
                $st = $conn->prepare("SELECT student_id, program, year_of_study, semester FROM exam_clearance_students WHERE clearance_id = ?");
                $st->bind_param("i", $cid);
                $st->execute();
                $st_row = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$st_row) continue;
                $ec_prog = $st_row['program'] ?? '';
                $ec_year = (int)($st_row['year_of_study'] ?? 0);
                $ec_sem  = $st_row['semester'] ?? '';
                $sid_val = $st_row['student_id'];
                $c_stmt = $conn->prepare(
                    "SELECT course_id FROM vle_courses
                     WHERE (program_of_study = ? OR program_of_study IS NULL OR program_of_study = '')
                       AND (year_of_study IS NULL OR year_of_study = 0 OR year_of_study = ?)
                       AND (semester IS NULL OR semester = '' OR semester = 'Both' OR semester = ?)"
                );
                $c_stmt->bind_param("sis", $ec_prog, $ec_year, $ec_sem);
                $c_stmt->execute();
                $c_result = $c_stmt->get_result();
                $per_student = 0;
                while ($c_row = $c_result->fetch_assoc()) {
                    $ins = $conn->prepare("INSERT IGNORE INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                    $ins->bind_param("si", $sid_val, $c_row['course_id']);
                    if ($ins->execute() && $ins->affected_rows > 0) { $per_student++; $total_enrolled++; }
                    $ins->close();
                }
                $c_stmt->close();
                if ($per_student > 0) $students_enrolled++;
            }
            if ($total_enrolled > 0) {
                $success = "<strong>{$total_enrolled}</strong> course enrollment(s) added across <strong>{$students_enrolled}</strong> student(s).";
            } else {
                $error = 'No new enrollments added. Students may already be enrolled in all matching courses, or no matching courses were found.';
            }
        }
    }

    // Assign individual courses to an EC/dissertation student
    if ($_POST['action'] === 'assign_courses' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $course_ids = isset($_POST['course_ids']) && is_array($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : [];
        $st = $conn->prepare("SELECT student_id FROM exam_clearance_students WHERE clearance_id = ?");
        $st->bind_param("i", $cid);
        $st->execute();
        $st_row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$st_row) {
            $error = 'Student not found.';
        } elseif (empty($course_ids)) {
            $error = 'Please select at least one course.';
        } else {
            $enrolled_count = 0;
            foreach ($course_ids as $course_id) {
                if ($course_id <= 0) continue;
                $ins = $conn->prepare("INSERT IGNORE INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                $ins->bind_param("si", $st_row['student_id'], $course_id);
                if ($ins->execute() && $ins->affected_rows > 0) $enrolled_count++;
                $ins->close();
            }
            $success = "Student enrolled in <strong>{$enrolled_count}</strong> course(s) successfully.";
        }
    }

    // Deregister (unenroll) selected courses from an EC/dissertation student
    if ($_POST['action'] === 'deregister_courses_student' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $remove_ids = isset($_POST['remove_course_ids']) && is_array($_POST['remove_course_ids']) ? array_map('intval', $_POST['remove_course_ids']) : [];
        $st = $conn->prepare("SELECT student_id FROM exam_clearance_students WHERE clearance_id = ?");
        $st->bind_param("i", $cid);
        $st->execute();
        $st_row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$st_row) {
            $error = 'Student not found.';
        } elseif (empty($remove_ids)) {
            $error = 'Please select at least one course to remove.';
        } else {
            $removed_count = 0;
            foreach ($remove_ids as $course_id) {
                if ($course_id <= 0) continue;
                $del = $conn->prepare("DELETE FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                $del->bind_param("si", $st_row['student_id'], $course_id);
                if ($del->execute() && $del->affected_rows > 0) $removed_count++;
                $del->close();
            }
            if ($removed_count > 0) {
                $success = "Removed <strong>{$removed_count}</strong> course enrollment(s) from student.";
            } else {
                $error = 'No enrollments were removed. The selected courses may have already been unenrolled.';
            }
        }
    }

    // Enroll EC/dissertation student in all courses matching their program/year/semester
    if ($_POST['action'] === 'enroll_by_program_student' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $st = $conn->prepare("SELECT student_id, program, year_of_study, semester FROM exam_clearance_students WHERE clearance_id = ?");
        $st->bind_param("i", $cid);
        $st->execute();
        $st_row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$st_row) {
            $error = 'Student not found.';
        } else {
            $ec_prog  = $st_row['program'] ?? '';
            $ec_year  = (int)($st_row['year_of_study'] ?? 0);
            $ec_sem   = $st_row['semester'] ?? '';
            $sid_val  = $st_row['student_id'];
            // Find matching courses via program_of_study field OR course_programs join table
            $c_stmt = $conn->prepare(
                "SELECT DISTINCT c.course_id FROM vle_courses c
                 LEFT JOIN course_programs cp ON cp.course_id = c.course_id
                 LEFT JOIN programs p ON p.program_id = cp.program_id
                 WHERE (c.program_of_study = ? OR c.program_of_study IS NULL OR c.program_of_study = ''
                        OR p.program_name = ?)
                   AND (c.year_of_study IS NULL OR c.year_of_study = 0 OR c.year_of_study = ?)
                   AND (c.semester IS NULL OR c.semester = '' OR c.semester = 'Both' OR c.semester = ?)
                   AND c.is_active = 1"
            );
            $c_stmt->bind_param("ssis", $ec_prog, $ec_prog, $ec_year, $ec_sem);
            $c_stmt->execute();
            $c_result = $c_stmt->get_result();
            $matching_courses = $c_result->fetch_all(MYSQLI_ASSOC);
            $c_stmt->close();
            $found_count    = count($matching_courses);
            $enrolled_count = 0;
            foreach ($matching_courses as $c_row) {
                $ins = $conn->prepare("INSERT IGNORE INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                $ins->bind_param("si", $sid_val, $c_row['course_id']);
                if ($ins->execute() && $ins->affected_rows > 0) $enrolled_count++;
                $ins->close();
            }
            if ($enrolled_count > 0) {
                $success = "Student enrolled in <strong>{$enrolled_count}</strong> course(s) matching their program.";
            } elseif ($found_count === 0) {
                $error = 'No courses found for Program: <strong>' . htmlspecialchars($ec_prog) . '</strong>, Year ' . $ec_year . ', Semester ' . htmlspecialchars($ec_sem) . '. Please configure courses in <a href="manage_courses.php">Manage Courses</a>.';
            } else {
                $error = 'Student is already enrolled in all <strong>' . $found_count . '</strong> matching course(s). No new enrollments needed.';
            }
        }
    }

    // Bulk assign courses to ALL students matching Year + Semester + Program criteria
    // Covers both exam_clearance_students and dissertation/regular students tables
    if ($_POST['action'] === 'bulk_assign_by_criteria') {
        $crit_year    = (int)($_POST['criteria_year'] ?? 0);
        $crit_sem     = trim($_POST['criteria_semester'] ?? '');
        $crit_program = trim($_POST['criteria_program'] ?? '');
        $allowed_sems = ['One', 'Two'];
        if ($crit_year < 1 || $crit_year > 6 || !in_array($crit_sem, $allowed_sems, true)) {
            $error = 'Please select a valid Year and Semester.';
        } else {
            // Build WHERE clauses
            $ec_where  = 'year_of_study = ? AND semester = ?';
            $ec_types  = 'is';
            $ec_params = [$crit_year, $crit_sem];
            $st_where  = 'year_of_study = ? AND semester = ?';
            $st_types  = 'is';
            $st_params = [$crit_year, $crit_sem];
            if ($crit_program !== '') {
                $ec_where   .= ' AND program = ?';
                $ec_types   .= 's';
                $ec_params[] = $crit_program;
                $st_where   .= ' AND program = ?';
                $st_types   .= 's';
                $st_params[] = $crit_program;
            }

            // Collect student_ids from exam_clearance_students
            $student_ids = [];
            $stmt_ec = $conn->prepare("SELECT DISTINCT student_id FROM exam_clearance_students WHERE $ec_where");
            $stmt_ec->bind_param($ec_types, ...$ec_params);
            $stmt_ec->execute();
            $ec_res = $stmt_ec->get_result();
            while ($r = $ec_res->fetch_assoc()) { if ($r['student_id']) $student_ids[$r['student_id']] = true; }
            $stmt_ec->close();

            // Also collect student_ids from students table (regular + dissertation)
            $stmt_st = $conn->prepare("SELECT DISTINCT student_id FROM students WHERE $st_where");
            $stmt_st->bind_param($st_types, ...$st_params);
            $stmt_st->execute();
            $st_res = $stmt_st->get_result();
            while ($r = $st_res->fetch_assoc()) { if ($r['student_id']) $student_ids[$r['student_id']] = true; }
            $stmt_st->close();

            if (empty($student_ids)) {
                $error = 'No students found matching the selected criteria.';
            } else {
                // Find matching courses
                $course_ids = [];
                if ($crit_program !== '') {
                    // Program specified: check program_of_study field AND course_programs join table
                    $c_stmt = $conn->prepare(
                        "SELECT DISTINCT c.course_id FROM vle_courses c
                         LEFT JOIN course_programs cp ON cp.course_id = c.course_id
                         LEFT JOIN programs p ON p.program_id = cp.program_id
                         WHERE (c.program_of_study = ? OR c.program_of_study IS NULL OR c.program_of_study = ''
                                OR p.program_name = ?)
                           AND (c.year_of_study IS NULL OR c.year_of_study = 0 OR c.year_of_study = ?)
                           AND (c.semester IS NULL OR c.semester = '' OR c.semester = 'Both' OR c.semester = ?)
                           AND c.is_active = 1"
                    );
                    $c_stmt->bind_param("ssis", $crit_program, $crit_program, $crit_year, $crit_sem);
                } else {
                    // No program filter: match ALL active courses for this year/semester
                    $c_stmt = $conn->prepare(
                        "SELECT DISTINCT c.course_id FROM vle_courses c
                         WHERE (c.year_of_study IS NULL OR c.year_of_study = 0 OR c.year_of_study = ?)
                           AND (c.semester IS NULL OR c.semester = '' OR c.semester = 'Both' OR c.semester = ?)
                           AND c.is_active = 1"
                    );
                    $c_stmt->bind_param("is", $crit_year, $crit_sem);
                }
                $c_stmt->execute();
                $c_res = $c_stmt->get_result();
                while ($r = $c_res->fetch_assoc()) $course_ids[] = (int)$r['course_id'];
                $c_stmt->close();

                // If admin hand-picked specific courses in the modal, restrict to those (validated against DB results)
                if (!empty($_POST['selected_course_ids']) && is_array($_POST['selected_course_ids'])) {
                    $picked = array_map('intval', $_POST['selected_course_ids']);
                    $course_ids = array_values(array_intersect($course_ids, $picked));
                }

                if (empty($course_ids)) {
                    $error = 'No matching courses found for Year ' . $crit_year . ', Semester ' . htmlspecialchars($crit_sem) . ($crit_program ? ', Program: ' . htmlspecialchars($crit_program) : '') . '.';
                } else {
                    $total_enrolled   = 0;
                    $students_enrolled = 0;
                    foreach (array_keys($student_ids) as $sid) {
                        $per_student = 0;
                        foreach ($course_ids as $cid_val) {
                            $ins = $conn->prepare("INSERT IGNORE INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                            $ins->bind_param("si", $sid, $cid_val);
                            if ($ins->execute() && $ins->affected_rows > 0) { $per_student++; $total_enrolled++; }
                            $ins->close();
                        }
                        if ($per_student > 0) $students_enrolled++;
                    }
                    if ($total_enrolled > 0) {
                        $student_count = count($student_ids);
                        $success = "<strong>{$total_enrolled}</strong> course enrollment(s) added across <strong>{$students_enrolled}</strong> of <strong>{$student_count}</strong> student(s) (Year {$crit_year}, Sem {$crit_sem}" . ($crit_program ? ", " . htmlspecialchars($crit_program) : '') . ").";
                    } else {
                        $error = 'No new enrollments added. All matching students may already be enrolled in all matching courses.';
                    }
                }
            }
        }
    }
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? ''; // system or external
$search = trim($_GET['search'] ?? '');

$where = "1=1";
$params = [];
$types = '';

if ($filter_status && $filter_status !== 'all' && in_array($filter_status, ['registered', 'approved', 'invoiced', 'proof_submitted', 'proof_requested', 'cleared', 'rejected'])) {
    $where .= " AND ecs.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_type === 'system') {
    $where .= " AND ecs.is_system_student = 1";
} elseif ($filter_type === 'external') {
    $where .= " AND ecs.is_system_student = 0";
}
if ($search) {
    $where .= " AND (ecs.student_id LIKE ? OR ecs.full_name LIKE ? OR ecs.email LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'sss';
}

// Ensure exam_clearance_payments table exists
$conn->query("CREATE TABLE IF NOT EXISTS exam_clearance_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    clearance_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    proof_file VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    reviewed_by INT DEFAULT NULL
)");

// Ensure profile_photo column exists
$conn->query("ALTER TABLE exam_clearance_students ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL");

// Ensure converted_to_student column exists
$col_check = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_student'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_student TINYINT(1) DEFAULT 0");
    $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_at DATETIME DEFAULT NULL");
}

// Ensure converted_to_dissertation column exists
$col_check2 = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'converted_to_dissertation'");
if ($col_check2 && $col_check2->num_rows === 0) {
    $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_dissertation TINYINT(1) DEFAULT 0 AFTER converted_at");
    $conn->query("ALTER TABLE exam_clearance_students ADD COLUMN converted_to_dissertation_at DATETIME DEFAULT NULL AFTER converted_to_dissertation");
}

// Status counts for pills
$counts = ['registered' => 0, 'invoiced' => 0, 'proof_submitted' => 0, 'cleared' => 0, 'rejected' => 0, 'all' => 0];
$cnt_q = $conn->query("SELECT status, COUNT(*) as cnt FROM exam_clearance_students GROUP BY status");
if ($cnt_q) { while ($c = $cnt_q->fetch_assoc()) { $counts[$c['status']] = (int)$c['cnt']; $counts['all'] += (int)$c['cnt']; } }

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total_q_sql = "SELECT COUNT(*) as cnt FROM exam_clearance_students ecs WHERE $where";
$total_stmt = $conn->prepare($total_q_sql);
if ($total_stmt) {
    if (!empty($params)) $total_stmt->bind_param($types, ...$params);
    $total_stmt->execute();
    $total_regs = $total_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
} else {
    $total_regs = 0;
}
$total_pages = max(1, ceil($total_regs / $per_page));

$query = "SELECT ecs.*, 
          (SELECT COALESCE(SUM(ecp.amount), 0) FROM exam_clearance_payments ecp WHERE ecp.clearance_id = ecs.clearance_id AND ecp.status = 'approved') as total_approved,
          (SELECT COUNT(*) FROM exam_clearance_payments ecp WHERE ecp.clearance_id = ecs.clearance_id) as payment_count
          FROM exam_clearance_students ecs 
          WHERE $where 
          ORDER BY ecs.registered_at DESC
          LIMIT $per_page OFFSET $offset";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $students = [];
}

// Fetch account lock info separately for this page of students (avoids any JOIN/subquery column conflicts)
$ec_lock_info = [];
if (!empty($students)) {
    $ec_emails = array_values(array_unique(array_column($students, 'email')));
    $ec_placeholders = implode(',', array_fill(0, count($ec_emails), '?'));
    $ec_types = str_repeat('s', count($ec_emails));
    $lock_stmt = $conn->prepare("SELECT email, failed_login_attempts, account_locked_until FROM users WHERE email IN ($ec_placeholders) AND role IN ('exam_clearance_student','dissertation_student')");
    if ($lock_stmt) {
        $lock_stmt->bind_param($ec_types, ...$ec_emails);
        $lock_stmt->execute();
        $lock_rows = $lock_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lock_stmt->close();
        foreach ($lock_rows as $lr) {
            $ec_lock_info[$lr['email']] = [
                'failed_login_attempts' => (int)$lr['failed_login_attempts'],
                'account_locked_until'  => $lr['account_locked_until'],
            ];
        }
    }
}

// Load programs and departments for Edit modal dropdowns
$edit_programs = [];
$edit_departments = [];
$prog_rs = $conn->query("SELECT p.program_id, p.program_name, p.program_type, p.department_id, d.department_name
    FROM programs p LEFT JOIN departments d ON p.department_id = d.department_id
    WHERE p.is_active = 1 ORDER BY p.program_name");
if ($prog_rs) { while ($row = $prog_rs->fetch_assoc()) $edit_programs[] = $row; }
$dept_rs = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
if ($dept_rs) { while ($row = $dept_rs->fetch_assoc()) $edit_departments[] = $row; }

// Load all available courses for Assign Courses modals
$all_courses_list = [];
$ac_rs = $conn->query("SELECT course_id, course_code, course_name, program_of_study, year_of_study, semester FROM vle_courses ORDER BY course_name");
if ($ac_rs) { while ($row = $ac_rs->fetch_assoc()) $all_courses_list[] = $row; }

// Pre-load vle_enrollments for students on this page (keyed by student_id)
$page_enrollments = []; // [student_id => [course_id, ...]]
if (!empty($students)) {
    $page_sids = array_values(array_unique(array_column($students, 'student_id')));
    if (!empty($page_sids)) {
        $pe_ph = implode(',', array_fill(0, count($page_sids), '?'));
        $pe_types = str_repeat('s', count($page_sids));
        $pe_stmt = $conn->prepare("SELECT student_id, course_id FROM vle_enrollments WHERE student_id IN ($pe_ph)");
        if ($pe_stmt) {
            $pe_stmt->bind_param($pe_types, ...$page_sids);
            $pe_stmt->execute();
            $pe_rows = $pe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $pe_stmt->close();
            foreach ($pe_rows as $pe) {
                $page_enrollments[$pe['student_id']][] = (int)$pe['course_id'];
            }
        }
    }
}

$page_title = 'Manage Exam Clearance Students';
$breadcrumbs = [['title' => 'Dashboard', 'url' => 'dashboard.php'], ['title' => 'Exam Clearance Students']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
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
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        .info-item { font-size: 0.85rem; }
        .info-item .label { color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .info-item .value { color: #1e293b; font-weight: 500; }
        .badge-registered { background: #e0e7ff; color: #3730a3; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-approved { background: #d1fae5; color: #065f46; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-invoiced { background: #dbeafe; color: #1e40af; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-proof_submitted { background: #fef3c7; color: #92400e; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-cleared { background: #dcfce7; color: #166534; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-rejected { background: #fee2e2; color: #991b1b; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-proof_requested { background: #fce7f3; color: #9d174d; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
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
        .search-box { margin-bottom: 16px; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="vle-content">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;"><i class="bi bi-check-circle me-2"></i><?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2 text-primary"></i>Exam Clearance Students</h4>
            <p class="text-muted mb-0" style="font-size:0.85rem;">Review and manage exam clearance applications from system and external students</p>
        </div>
        <div class="d-flex gap-2">
            <a href="../finance/exam_clearance_reports.php" class="btn btn-outline-primary"><i class="bi bi-clipboard-data me-1"></i>Reports</a>
            <a href="exam_clearance_invite_links.php" class="btn btn-outline-success"><i class="bi bi-link-45deg me-1"></i>Invite Links</a>
            <a href="../finance/exam_clearance_students.php" class="btn btn-outline-primary"><i class="bi bi-cash-stack me-1"></i>Finance View</a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkAssignCriteriaModal"><i class="bi bi-people me-1"></i>Assign Courses by Criteria</button>
        </div>
    </div>

    <!-- Search -->
    <div class="search-box">
        <form method="GET" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <div style="flex:1;max-width:400px;">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, student ID, or email...">
                </div>
            </div>
            <select name="type" class="form-select" style="width:auto;">
                <option value="">All Types</option>
                <option value="system" <?= $filter_type === 'system' ? 'selected' : '' ?>>System</option>
                <option value="external" <?= $filter_type === 'external' ? 'selected' : '' ?>>External</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
            <?php if ($search || $filter_type): ?>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Filter pills -->
    <div class="stat-pills">
        <a href="?filter=all&type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=all" class="stat-pill <?= $filter_status === 'all' ? 'active' : '' ?>">
            <i class="bi bi-list"></i> All
            <span class="pill-count"><?= $counts['all'] ?></span>
        </a>
        <a href="?type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=registered" class="stat-pill <?= $filter_status === 'registered' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i> Registered
            <span class="pill-count"><?= $counts['registered'] ?? 0 ?></span>
        </a>
        <a href="?type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=invoiced" class="stat-pill <?= $filter_status === 'invoiced' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Invoiced
            <span class="pill-count"><?= $counts['invoiced'] ?? 0 ?></span>
        </a>
        <a href="?type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=proof_submitted" class="stat-pill <?= $filter_status === 'proof_submitted' ? 'active' : '' ?>">
            <i class="bi bi-upload"></i> Proof Submitted
            <span class="pill-count"><?= $counts['proof_submitted'] ?? 0 ?></span>
        </a>
        <a href="?type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=cleared" class="stat-pill <?= $filter_status === 'cleared' ? 'active' : '' ?>">
            <i class="bi bi-check-circle"></i> Cleared
            <span class="pill-count"><?= $counts['cleared'] ?? 0 ?></span>
        </a>
        <a href="?type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>&status=rejected" class="stat-pill <?= $filter_status === 'rejected' ? 'active' : '' ?>">
            <i class="bi bi-x-circle"></i> Rejected
            <span class="pill-count"><?= $counts['rejected'] ?? 0 ?></span>
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
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#bulkApproveModal"><i class="bi bi-check-circle me-1"></i>Bulk Approve</button>
            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal"><i class="bi bi-trash me-1"></i>Bulk Delete</button>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkEnrollModal"><i class="bi bi-book-half me-1"></i>Bulk Enroll Courses</button>
        </div>
    </div>

    <?php if (!empty($students)): ?>
    <div class="select-all-wrap">
        <input type="checkbox" class="bulk-check" id="selectAllCheck" onchange="toggleSelectAll(this)">
        <label for="selectAllCheck">Select all on this page</label>
        <span class="text-muted small ms-2">(<?= $total_regs ?> total)</span>
    </div>
    <?php endif; ?>

    <?php if (empty($students)): ?>
    <div class="text-center py-5">
        <i class="bi bi-inbox" style="font-size:3rem;color:#cbd5e1;"></i>
        <p class="text-muted mt-2">No exam clearance students found for this filter.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($students as $s): 
        $status_icons = ['registered'=>'clock','approved'=>'check2','invoiced'=>'receipt','proof_submitted'=>'upload','proof_requested'=>'arrow-repeat','cleared'=>'check-circle','rejected'=>'x-circle'];
        $status_icon = $status_icons[$s['status']] ?? 'circle';
        $ec_lock_row   = $ec_lock_info[$s['email']] ?? [];
        $ec_locked     = !empty($ec_lock_row['account_locked_until']) && strtotime($ec_lock_row['account_locked_until']) > time();
        $ec_failed     = !empty($ec_lock_row['failed_login_attempts']) && (int)$ec_lock_row['failed_login_attempts'] >= 5;
        $ec_failed_cnt = (int)($ec_lock_row['failed_login_attempts'] ?? 0);
    ?>
    <div class="reg-card">
        <div class="card-header">
            <div class="d-flex align-items-center gap-3">
                <input type="checkbox" class="bulk-check bulk-student-check" value="<?= $s['clearance_id'] ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" onchange="updateBulkBar()">
                <?php if (!empty($s['profile_photo']) && file_exists(__DIR__ . '/../uploads/exam_clearance_profiles/' . basename($s['profile_photo']))): ?>
                <img src="../uploads/exam_clearance_profiles/<?= htmlspecialchars(basename($s['profile_photo'])) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;cursor:pointer;" data-bs-toggle="modal" data-bs-target="#photoModal<?= $s['clearance_id'] ?>" title="Change photo">
                <?php else: ?>
                <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,<?= $s['is_system_student'] ? '#10b981,#059669' : '#667eea,#764ba2' ?>);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;cursor:pointer;" data-bs-toggle="modal" data-bs-target="#photoModal<?= $s['clearance_id'] ?>" title="Upload photo">
                    <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div>
                    <div style="font-weight:600;font-size:1rem;">
                        <?= htmlspecialchars($s['full_name']) ?>
                    </div>
                    <div style="font-size:0.8rem;color:#64748b;">
                        <?= htmlspecialchars($s['email']) ?>
                        <?php if (!empty($s['phone'])): ?> &bull; <?= htmlspecialchars($s['phone']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($s['is_system_student']): ?>
                    <span style="background:#dcfce7;color:#166534;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;"><i class="bi bi-check-circle me-1"></i>System</span>
                <?php else: ?>
                    <span style="background:#f1f5f9;color:#475569;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;">External</span>
                <?php endif; ?>
                <span class="badge-<?= $s['status'] ?>"><i class="bi bi-<?= $status_icon ?> me-1"></i><?= ucfirst(str_replace('_', ' ', $s['status'])) ?></span>
                <?php if ($ec_locked): 
                    $lock_secs = strtotime($ec_lock_row['account_locked_until']) - time();
                    $lock_mins = (int)ceil($lock_secs / 60);
                ?>
                    <span class="ec-lock-badge" data-unlock-ts="<?= strtotime($ec_lock_row['account_locked_until']) ?>" style="background:#dc2626;color:#fff;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;"><i class="bi bi-lock-fill"></i><span class="lock-timer-label"><?= $lock_mins ?>m left</span></span>
                <?php elseif ($ec_failed): ?>
                    <span style="background:#f59e0b;color:#fff;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;"><i class="bi bi-exclamation-triangle me-1"></i><?= $ec_failed_cnt ?> Failed</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="info-grid mb-3">
                <div class="info-item">
                    <div class="label">Student ID</div>
                    <div class="value" style="color:#4f46e5;font-weight:600;"><?= htmlspecialchars($s['student_id']) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Program</div>
                    <div class="value"><?= htmlspecialchars($s['program'] ?: '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Program Type</div>
                    <div class="value">
                        <?php 
                        $pt = $s['program_type'] ?? 'degree';
                        $pt_colors = ['degree'=>'#4f46e5','diploma'=>'#059669','masters'=>'#0891b2','doctorate'=>'#dc2626','professional'=>'#7c3aed'];
                        ?>
                        <span style="background:<?= $pt_colors[$pt] ?? '#4f46e5' ?>;color:#fff;padding:2px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;"><?= ucfirst($pt) ?></span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Clearance Type</div>
                    <div class="value"><?= ucfirst($s['clearance_type'] ?? 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Campus</div>
                    <div class="value"><?= htmlspecialchars($s['campus'] ?: 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Department</div>
                    <div class="value"><?= htmlspecialchars($s['department'] ?: 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Year / Semester</div>
                    <div class="value">Year <?= $s['year_of_study'] ?? '—' ?> / Sem <?= htmlspecialchars($s['semester'] ?? '—') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Gender</div>
                    <div class="value"><?= htmlspecialchars($s['gender'] ?: 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Entry Type</div>
                    <div class="value"><?= htmlspecialchars($s['entry_type'] ?: 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Registered</div>
                    <div class="value"><?= date('M j, Y g:i A', strtotime($s['registered_at'])) ?></div>
                </div>
                <?php if (!empty($s['national_id'])): ?>
                <div class="info-item">
                    <div class="label">National ID</div>
                    <div class="value"><?= htmlspecialchars($s['national_id']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($s['address'])): ?>
                <div class="info-item">
                    <div class="label">Address</div>
                    <div class="value"><?= htmlspecialchars($s['address']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php
                $s_enrolled_ids   = array_map('intval', $page_enrollments[$s['student_id']] ?? []);
                $s_enrolled_count = count($s_enrolled_ids);
                // Build full enrolled course records for this student
                $s_enrolled_courses = [];
                foreach ($all_courses_list as $_ec) {
                    if (in_array((int)$_ec['course_id'], $s_enrolled_ids, true)) {
                        $s_enrolled_courses[] = $_ec;
                    }
                }
            ?>
            <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;margin-bottom:12px;">
                <!-- Top row: Payment Proofs + Courses Assigned count -->
                <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:center;margin-bottom:<?= $s_enrolled_count > 0 ? '10px' : '0' ?>;">
                    <div>
                        <span style="color:#94a3b8;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Payment Proofs Submitted</span>
                        <div style="font-weight:700;color:#1e293b;font-size:1.1rem;"><?= (int)($s['payment_count'] ?? 0) ?></div>
                    </div>
                    <div>
                        <span style="color:#94a3b8;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">Courses Assigned</span>
                        <div style="font-weight:700;font-size:1.1rem;<?= $s_enrolled_count > 0 ? 'color:#059669;' : 'color:#94a3b8;' ?>"><?= $s_enrolled_count ?></div>
                    </div>
                </div>
                <?php if ($s_enrolled_count > 0): ?>
                <!-- Course name grid: 2 columns, up to 4 per row (2 per column per visual row) -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 12px;">
                    <?php foreach ($s_enrolled_courses as $_c): ?>
                    <div style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #d1fae5;border-radius:6px;padding:4px 8px;min-width:0;">
                        <i class="bi bi-book" style="color:#059669;flex-shrink:0;font-size:0.75rem;"></i>
                        <span style="font-size:0.78rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($_c['course_name']) ?>">
                            <strong><?= htmlspecialchars($_c['course_code'] ?: 'N/A') ?></strong> — <?= htmlspecialchars($_c['course_name']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($s['converted_to_student'])): ?>
            <div style="background:#dbeafe;border-radius:8px;padding:10px 16px;font-size:0.8rem;color:#1e40af;margin-bottom:8px;">
                <i class="bi bi-arrow-repeat me-1"></i>
                <strong>Converted to system student</strong>
                <?php if (!empty($s['converted_at'])): ?> on <?= date('M j, Y g:i A', strtotime($s['converted_at'])) ?><?php endif; ?>
            </div>
            <?php endif; ?>

            <hr style="border-color:#e2e8f0;">
            <div class="action-btns">
                <?php if ($s['status'] === 'registered'): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-shield-check me-1"></i> Approve for Exam Clearance
                </button>
                <?php elseif (in_array($s['status'], ['invoiced', 'proof_submitted', 'cleared'])): ?>
                <span class="btn btn-outline-success disabled"><i class="bi bi-check-circle me-1"></i> Approved</span>
                <?php endif; ?>
                
                <a href="../finance/exam_clearance_review.php?id=<?= $s['clearance_id'] ?>" class="btn btn-primary">
                    <i class="bi bi-eye me-1"></i> Review
                </a>

                <?php if ($s['status'] === 'cleared'): ?>
                <a href="../finance/exam_clearance_certificate.php?id=<?= $s['clearance_id'] ?>" class="btn btn-outline-success" target="_blank">
                    <i class="bi bi-award me-1"></i> View Certificate
                </a>
                <?php endif; ?>
                
                <?php if (!$s['is_system_student'] && empty($s['converted_to_student'])): ?>
                <button class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#convertModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-person-plus me-1"></i> Convert to Student
                </button>
                <?php endif; ?>

                <?php if (empty($s['converted_to_dissertation']) && empty($s['converted_to_student'])): ?>
                <button class="btn btn-purple" style="background:#7c3aed;color:#fff;border:none;" data-bs-toggle="modal" data-bs-target="#dissConvertModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-journal-bookmark-fill me-1"></i> Add to Dissertation
                </button>
                <?php elseif (!empty($s['converted_to_dissertation'])): ?>
                <span class="btn btn-outline-secondary disabled" style="cursor:default;">
                    <i class="bi bi-journal-check me-1"></i> Dissertation Student
                </span>
                <?php endif; ?>
                
                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-trash me-1"></i> Delete
                </button>
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-pencil me-1"></i> Edit Details
                </button>
                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#resetPwModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-key me-1"></i> Reset Password
                </button>
                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#photoModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-camera me-1"></i> <?= empty($s['profile_photo']) ? 'Upload Photo' : 'Change Photo' ?>
                </button>
                <?php if ($ec_locked || $ec_failed): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="unlock_account">
                    <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                    <button type="submit" name="unlock_account" class="btn btn-warning"
                            onclick="return confirm('Unlock this account and reset failed login attempts?')"
                            title="<?= $ec_locked ? 'Unlock account (locked for ' . (int)ceil((strtotime($ec_lock_row['account_locked_until']) - time()) / 60) . ' more min)' : 'Reset ' . $ec_failed_cnt . ' failed login attempts' ?>">
                        <i class="bi bi-unlock-fill me-1"></i><?= $ec_locked ? 'Unlock Account' : 'Reset ' . $ec_failed_cnt . ' Failed Attempts' ?>
                    </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assignCoursesModal<?= $s['clearance_id'] ?>">
                    <i class="bi bi-book-half me-1"></i> Assign Courses
                </button>
            </div>
        </div>
    </div>

    <?php if ($s['status'] === 'registered'): ?>
    <!-- Approve Modal -->
    <div class="modal fade" id="approveModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Approve for Exam Clearance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="approve_student">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <div class="alert alert-success" style="border-radius:10px;font-size:0.85rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            This will activate the student's account for exam clearance only. They will be able to log in and upload proof of payment.
                        </div>
                        <div style="background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:12px;">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div style="width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#15803d);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:20px;">
                                    <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:1.05rem;"><?= htmlspecialchars($s['full_name']) ?></div>
                                    <div style="font-size:0.8rem;color:#64748b;"><?= htmlspecialchars($s['email']) ?></div>
                                </div>
                            </div>
                            <div class="row g-2" style="font-size:0.85rem;">
                                <div class="col-6"><span style="color:#94a3b8;">Student ID:</span><br><strong><?= htmlspecialchars($s['student_id']) ?></strong></div>
                                <div class="col-6"><span style="color:#94a3b8;">Program:</span><br><strong><?= htmlspecialchars($s['program'] ?: 'N/A') ?></strong></div>
                                <div class="col-6"><span style="color:#94a3b8;">Campus:</span><br><strong><?= htmlspecialchars($s['campus'] ?: 'N/A') ?></strong></div>
                                <div class="col-6"><span style="color:#94a3b8;">Program Type:</span><br><strong><?= ucfirst($s['program_type'] ?? 'degree') ?></strong></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Admin Notes (optional)</label>
                            <textarea name="admin_notes" class="form-control" rows="2" placeholder="Add any notes about this approval..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-shield-check me-1"></i> Approve for Exam Clearance
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$s['is_system_student'] && empty($s['converted_to_student'])): ?>
    <!-- Convert Modal -->
    <div class="modal fade" id="convertModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Convert to System Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="convert_to_student">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <div class="alert alert-info" style="border-radius:10px;font-size:0.85rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            This will create a full student record and grant access to all student features.
                        </div>
                        <p>
                            Convert <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                            (<?= htmlspecialchars($s['email']) ?>) to a full system student?
                        </p>
                        <div class="mb-2">
                            <strong>Student ID:</strong> <?= htmlspecialchars($s['student_id']) ?><br>
                            <strong>Program:</strong> <?= htmlspecialchars($s['program'] ?: 'N/A') ?><br>
                            <strong>Campus:</strong> <?= htmlspecialchars($s['campus'] ?: 'N/A') ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info text-white">
                            <i class="bi bi-person-plus me-1"></i> Confirm Conversion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Convert to Dissertation Student Modal -->
    <?php if (empty($s['converted_to_dissertation']) && empty($s['converted_to_student'])): ?>
    <div class="modal fade" id="dissConvertModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-journal-bookmark-fill me-2"></i>Add to Dissertation Portal</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="convert_to_dissertation_student">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <div class="alert" style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:10px;font-size:0.85rem;color:#4c1d95;">
                            <i class="bi bi-info-circle me-1"></i>
                            This will change the student's account role to <strong>Dissertation Student</strong>, giving them access to the dissertation portal while keeping their exam clearance record.
                        </div>
                        <p>
                            Convert <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                            (<?= htmlspecialchars($s['email']) ?>) to a dissertation-only student?
                        </p>
                        <div class="mb-2" style="font-size:0.9rem;">
                            <strong>Student ID:</strong> <?= htmlspecialchars($s['student_id']) ?><br>
                            <strong>Program:</strong> <?= htmlspecialchars($s['program'] ?: 'N/A') ?><br>
                            <strong>Campus:</strong> <?= htmlspecialchars($s['campus'] ?: 'N/A') ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn text-white" style="background:#7c3aed;border-color:#7c3aed;">
                            <i class="bi bi-journal-bookmark-fill me-1"></i> Confirm &amp; Add to Dissertation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Student Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <div class="alert alert-danger" style="border-radius:10px;font-size:0.85rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This will permanently delete the student's exam clearance record and all associated payments. This cannot be undone.
                        </div>
                        <p>
                            Delete record for <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                            (<?= htmlspecialchars($s['student_id']) ?>)?
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i> Confirm Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal fade" id="photoModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-camera me-2"></i>Profile Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body text-center">
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <?php if (!empty($s['profile_photo']) && file_exists(__DIR__ . '/../uploads/exam_clearance_profiles/' . basename($s['profile_photo']))): ?>
                        <img src="../uploads/exam_clearance_profiles/<?= htmlspecialchars(basename($s['profile_photo'])) ?>" id="photoPreview<?= $s['clearance_id'] ?>" alt="" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #e0f2fe;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
                        <?php else: ?>
                        <div id="photoPreview<?= $s['clearance_id'] ?>" style="width:100px;height:100px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:36px;margin:0 auto 12px;"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <p class="text-muted small mb-3"><?= htmlspecialchars($s['full_name']) ?></p>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Choose image <span class="text-muted">(JPG/PNG/WEBP, max 2 MB)</span></label>
                            <input type="file" name="profile_photo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp" required
                                onchange="previewPhoto(this,'photoPreview<?= $s['clearance_id'] ?>')">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Details Modal -->
    <div class="modal fade" id="editModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#475569,#334155);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Student Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_student">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($s['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($s['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($s['phone'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">— Select —</option>
                                    <option value="Male" <?= ($s['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($s['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-semibold">Program <span class="text-danger">*</span></label>
                                <select name="program_id" id="editProgram_<?= $s['clearance_id'] ?>" class="form-select edit-program-select" data-modal-id="<?= $s['clearance_id'] ?>" onchange="onEditProgramChange(this)">
                                    <option value="">— Select Program —</option>
                                    <?php foreach ($edit_programs as $ep): ?>
                                    <option value="<?= $ep['program_id'] ?>"
                                        data-dept-id="<?= $ep['department_id'] ?>"
                                        data-dept-name="<?= htmlspecialchars($ep['department_name'] ?? '') ?>"
                                        data-prog-type="<?= htmlspecialchars($ep['program_type'] ?? 'degree') ?>"
                                        <?= (int)($s['program_id'] ?? 0) === (int)$ep['program_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ep['program_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Current: <em><?= htmlspecialchars($s['program'] ?? '—') ?></em></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Department</label>
                                <select name="department_id" id="editDept_<?= $s['clearance_id'] ?>" class="form-select">
                                    <option value="">— Auto-filled from program —</option>
                                    <?php foreach ($edit_departments as $ed): ?>
                                    <option value="<?= $ed['department_id'] ?>"
                                        <?= (int)($s['department_id'] ?? 0) === (int)$ed['department_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ed['department_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="editDeptNote_<?= $s['clearance_id'] ?>" style="display:none;color:#0d9488;"><i class="bi bi-check-circle"></i> Auto-filled from program</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Program Type</label>
                                <select name="program_type" id="editProgType_<?= $s['clearance_id'] ?>" class="form-select">
                                    <option value="degree" <?= ($s['program_type'] ?? '') === 'degree' ? 'selected' : '' ?>>Degree</option>
                                    <option value="diploma" <?= ($s['program_type'] ?? '') === 'diploma' ? 'selected' : '' ?>>Diploma</option>
                                    <option value="professional" <?= ($s['program_type'] ?? '') === 'professional' ? 'selected' : '' ?>>Professional</option>
                                    <option value="masters" <?= ($s['program_type'] ?? '') === 'masters' ? 'selected' : '' ?>>Masters</option>
                                    <option value="doctorate" <?= ($s['program_type'] ?? '') === 'doctorate' ? 'selected' : '' ?>>Doctorate</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Campus</label>
                                <select name="campus" class="form-select">
                                    <option value="Mzuzu Campus" <?= ($s['campus'] ?? '') === 'Mzuzu Campus' ? 'selected' : '' ?>>Mzuzu Campus</option>
                                    <option value="Lilongwe Campus" <?= ($s['campus'] ?? '') === 'Lilongwe Campus' ? 'selected' : '' ?>>Lilongwe Campus</option>
                                    <option value="Blantyre Campus" <?= ($s['campus'] ?? '') === 'Blantyre Campus' ? 'selected' : '' ?>>Blantyre Campus</option>
                                    <option value="ODel Campus" <?= ($s['campus'] ?? '') === 'ODel Campus' ? 'selected' : '' ?>>ODel Campus</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Year of Study</label>
                                <select name="year_of_study" class="form-select">
                                    <?php for ($y = 1; $y <= 5; $y++): ?>
                                    <option value="<?= $y ?>" <?= (int)($s['year_of_study'] ?? 1) === $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Semester</label>
                                <select name="semester" class="form-select">
                                    <option value="One" <?= ($s['semester'] ?? '') === 'One' ? 'selected' : '' ?>>One</option>
                                    <option value="Two" <?= ($s['semester'] ?? '') === 'Two' ? 'selected' : '' ?>>Two</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Clearance Type</label>
                                <select name="clearance_type" class="form-select">
                                    <option value="endsemester" <?= ($s['clearance_type'] ?? '') === 'endsemester' ? 'selected' : '' ?>>End-Semester</option>
                                    <option value="midsemester" <?= ($s['clearance_type'] ?? '') === 'midsemester' ? 'selected' : '' ?>>Mid-Semester</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">National ID</label>
                                <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($s['national_id'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Address</label>
                                <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($s['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPwModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                        <p class="mb-3" style="font-size:0.9rem;">Reset password for <strong><?= htmlspecialchars($s['full_name']) ?></strong> (<?= htmlspecialchars($s['email']) ?>).</p>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required placeholder="Min 6 characters">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required placeholder="Repeat password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-white">
                            <i class="bi bi-key me-1"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Courses Modal -->
    <?php
    $ec_enrolled_ids = array_map('intval', $page_enrollments[$s['student_id']] ?? []);
    $ec_prog_filter = $s['program'] ?? '';
    $ec_year_filter = (int)($s['year_of_study'] ?? 0);
    $ec_sem_filter  = $s['semester'] ?? '';
    ?>
    <div class="modal fade" id="assignCoursesModal<?= $s['clearance_id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-book-half me-2"></i>Assign Courses — <?= htmlspecialchars($s['full_name']) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs px-3 pt-3" id="acTabs<?= $s['clearance_id'] ?>" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#acSelect<?= $s['clearance_id'] ?>" type="button">
                                <i class="bi bi-list-check me-1"></i> Select Courses
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#acProgram<?= $s['clearance_id'] ?>" type="button">
                                <i class="bi bi-diagram-3 me-1"></i> Enroll by Program
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-danger" data-bs-toggle="tab" data-bs-target="#acDeregister<?= $s['clearance_id'] ?>" type="button">
                                <i class="bi bi-x-circle me-1"></i> Enrolled Courses
                                <?php if (!empty($ec_enrolled_ids)): ?>
                                <span class="badge bg-danger ms-1"><?= count($ec_enrolled_ids) ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <!-- Tab 1: Select individual courses -->
                        <div class="tab-pane fade show active p-3" id="acSelect<?= $s['clearance_id'] ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="assign_courses">
                                <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                <?php if (empty($all_courses_list)): ?>
                                <p class="text-muted">No courses available. Please create courses in Manage Courses first.</p>
                                <?php else: ?>
                                <p class="text-muted small mb-2">Select courses to enroll <strong><?= htmlspecialchars($s['full_name']) ?></strong> in. Already-enrolled courses are pre-checked.</p>
                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search courses..." oninput="filterCourseList(this, 'acCourseList<?= $s['clearance_id'] ?>')">
                                <div id="acCourseList<?= $s['clearance_id'] ?>" style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px;">
                                    <?php foreach ($all_courses_list as $acCourse): ?>
                                    <?php $alreadyEnrolled = in_array((int)$acCourse['course_id'], $ec_enrolled_ids, true); ?>
                                    <div class="form-check py-1 px-3 ac-course-item" data-name="<?= strtolower(htmlspecialchars($acCourse['course_name'] . ' ' . $acCourse['course_code'])) ?>">
                                        <input class="form-check-input" type="checkbox" name="course_ids[]" value="<?= $acCourse['course_id'] ?>"
                                            id="ac<?= $s['clearance_id'] ?>_<?= $acCourse['course_id'] ?>"
                                            <?= $alreadyEnrolled ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="ac<?= $s['clearance_id'] ?>_<?= $acCourse['course_id'] ?>">
                                            <strong><?= htmlspecialchars($acCourse['course_code'] ?: $acCourse['course_name']) ?></strong>
                                            — <?= htmlspecialchars($acCourse['course_name']) ?>
                                            <?php if ($acCourse['program_of_study']): ?>
                                            <span class="badge bg-light text-dark ms-1" style="font-size:0.7rem;"><?= htmlspecialchars($acCourse['program_of_study']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($alreadyEnrolled): ?>
                                            <span class="badge bg-success ms-1" style="font-size:0.7rem;"><i class="bi bi-check-circle"></i> Enrolled</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex justify-content-end mt-3">
                                    <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-1"></i> Save Enrollments
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>

                        <!-- Tab 3: Deregister enrolled courses -->
                        <div class="tab-pane fade p-3" id="acDeregister<?= $s['clearance_id'] ?>">
                            <?php
                            // Build enrolled courses list for this student
                            $ec_enrolled_courses = [];
                            foreach ($all_courses_list as $acC) {
                                if (in_array((int)$acC['course_id'], $ec_enrolled_ids, true)) {
                                    $ec_enrolled_courses[] = $acC;
                                }
                            }
                            ?>
                            <?php if (empty($ec_enrolled_courses)): ?>
                            <div class="alert alert-info" style="border-radius:8px;font-size:0.85rem;">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong><?= htmlspecialchars($s['full_name']) ?></strong> is not enrolled in any courses yet.
                            </div>
                            <div class="text-end"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
                            <?php else: ?>
                            <p class="text-muted small mb-2">Check the courses you want to <strong class="text-danger">remove</strong> from <strong><?= htmlspecialchars($s['full_name']) ?></strong>, then click Remove.</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="deregister_courses_student">
                                <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deregSelectAll('deregList<?= $s['clearance_id'] ?>')"><i class="bi bi-check-all"></i> All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="deregDeselectAll('deregList<?= $s['clearance_id'] ?>')"><i class="bi bi-x-square"></i> None</button>
                                </div>
                                <div id="deregList<?= $s['clearance_id'] ?>" style="max-height:300px;overflow-y:auto;border:1px solid #fee2e2;border-radius:8px;padding:8px;">
                                    <?php foreach ($ec_enrolled_courses as $ecC):
                                        $semLbl = ($ecC['semester'] === 'Both') ? 'Sem 1&2' : htmlspecialchars($ecC['semester'] ?? '');
                                    ?>
                                    <div class="form-check py-1 px-3 d-flex align-items-center gap-2">
                                        <input class="form-check-input dereg-cb-<?= $s['clearance_id'] ?>" type="checkbox" name="remove_course_ids[]" value="<?= $ecC['course_id'] ?>" id="drg<?= $s['clearance_id'] ?>_<?= $ecC['course_id'] ?>">
                                        <label class="form-check-label small flex-grow-1" for="drg<?= $s['clearance_id'] ?>_<?= $ecC['course_id'] ?>">
                                            <strong><?= htmlspecialchars($ecC['course_code'] ?: $ecC['course_name']) ?></strong>
                                            — <?= htmlspecialchars($ecC['course_name']) ?>
                                            <?php if ($ecC['program_of_study']): ?>
                                            <span class="badge bg-light text-dark ms-1" style="font-size:0.7rem;"><?= htmlspecialchars($ecC['program_of_study']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($ecC['year_of_study']): ?>
                                            <span class="badge bg-secondary ms-1" style="font-size:0.7rem;">Yr<?= $ecC['year_of_study'] ?></span>
                                            <?php endif; ?>
                                            <?php if ($semLbl): ?>
                                            <span class="badge bg-info ms-1" style="font-size:0.7rem;"><?= $semLbl ?></span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <small class="text-muted"><?= count($ec_enrolled_courses) ?> course(s) currently enrolled</small>
                                    <div>
                                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger" onclick="return confirmDeregister(this, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>', 'deregList<?= $s['clearance_id'] ?>')">
                                            <i class="bi bi-x-circle me-1"></i> Remove Selected
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>

                        <!-- Tab 2: Enroll by Program -->
                        <div class="tab-pane fade p-3" id="acProgram<?= $s['clearance_id'] ?>">
                            <?php
                            // Count how many courses would match
                            $match_count = 0;
                            foreach ($all_courses_list as $acC) {
                                $prog_match = (empty($acC['program_of_study']) || $acC['program_of_study'] === $ec_prog_filter);
                                $year_match = (empty($acC['year_of_study']) || (int)$acC['year_of_study'] === 0 || (int)$acC['year_of_study'] === $ec_year_filter);
                                $sem_match  = (empty($acC['semester']) || $acC['semester'] === 'Both' || $acC['semester'] === $ec_sem_filter);
                                if ($prog_match && $year_match && $sem_match) $match_count++;
                            }
                            ?>
                            <p class="text-muted small mb-3">This will auto-enroll <strong><?= htmlspecialchars($s['full_name']) ?></strong> in all courses that match their program details.</p>
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;margin-bottom:16px;">
                                <div class="row g-2" style="font-size:0.88rem;">
                                    <div class="col-sm-4"><span class="text-muted">Program:</span><br><strong><?= htmlspecialchars($ec_prog_filter ?: '—') ?></strong></div>
                                    <div class="col-sm-4"><span class="text-muted">Year of Study:</span><br><strong><?= $ec_year_filter ?: '—' ?></strong></div>
                                    <div class="col-sm-4"><span class="text-muted">Semester:</span><br><strong><?= htmlspecialchars($ec_sem_filter ?: '—') ?></strong></div>
                                </div>
                                <div class="mt-2" style="font-size:0.85rem;">
                                    <i class="bi bi-info-circle me-1 text-success"></i>
                                    <strong><?= $match_count ?></strong> course(s) match this student's program, year, and semester.
                                </div>
                            </div>
                            <?php if ($match_count > 0): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="enroll_by_program_student">
                                <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                <div class="d-flex justify-content-end">
                                    <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Enroll <?= htmlspecialchars(addslashes($s['full_name'])) ?> in all <?= $match_count ?> matching course(s)?')">
                                        <i class="bi bi-diagram-3 me-1"></i> Enroll in <?= $match_count ?> Matching Course(s)
                                    </button>
                                </div>
                            </form>
                            <?php else: ?>
                            <div class="alert alert-warning" style="border-radius:8px;font-size:0.85rem;">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No courses match this student's program. You can still manually assign courses using the <strong>Select Courses</strong> tab, or update the student's program details first.
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&status=<?= htmlspecialchars($filter_status) ?>&type=<?= htmlspecialchars($filter_type) ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Bulk Approve Modal -->
<div class="modal fade" id="bulkApproveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:16px 16px 0 0;">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Bulk Approve for Exam Clearance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="bulkApproveForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_approve">
                    <div class="alert alert-success" style="border-radius:10px;font-size:0.85rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        You are about to approve <strong><span id="bulkApproveCount">0</span></strong> student(s) for exam clearance. Their accounts will be activated so they can log in and upload proof of payment. Already approved students will be skipped.
                    </div>
                    <div id="bulkApproveList" style="max-height:200px;overflow-y:auto;font-size:0.85rem;border:1px solid #e2e8f0;border-radius:8px;padding:12px;"></div>
                    <div id="bulkApproveInputs"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i> Confirm Bulk Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:16px 16px 0 0;">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Bulk Delete Students</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="bulkDeleteForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_delete">
                    <div class="alert alert-danger" style="border-radius:10px;font-size:0.85rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        You are about to delete <strong><span id="bulkModalCount">0</span></strong> student record(s). This cannot be undone.
                    </div>
                    <div id="bulkStudentList" style="max-height:200px;overflow-y:auto;font-size:0.85rem;border:1px solid #e2e8f0;border-radius:8px;padding:12px;"></div>
                    <div id="bulkHiddenInputs"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i> Confirm Bulk Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Enroll by Program Modal -->
<div class="modal fade" id="bulkEnrollModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:16px 16px 0 0;">
                <h5 class="modal-title"><i class="bi bi-book-half me-2"></i>Bulk Enroll by Program</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="bulkEnrollForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_enroll_by_program">
                    <div class="alert alert-primary" style="border-radius:10px;font-size:0.85rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Enroll <strong><span id="bulkEnrollCount">0</span></strong> selected student(s) in all courses matching their program, year, and semester. Already-enrolled courses are skipped.
                    </div>
                    <div id="bulkEnrollList" style="max-height:200px;overflow-y:auto;font-size:0.85rem;border:1px solid #e2e8f0;border-radius:8px;padding:12px;"></div>
                    <div id="bulkEnrollInputs"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-book-half me-1"></i> Confirm Bulk Enroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Courses by Criteria Modal -->
<div class="modal fade" id="bulkAssignCriteriaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border-radius:16px 16px 0 0;">
                <h5 class="modal-title"><i class="bi bi-people me-2"></i>Assign Courses by Criteria</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_assign_by_criteria">
                    <div class="alert" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;font-size:0.85rem;color:#3730a3;">
                        <i class="bi bi-info-circle me-1"></i>
                        Automatically enroll <strong>all matching students</strong> (Exam Clearance &amp; Dissertation) in courses that match the selected Year and Semester criteria. Already-enrolled courses are skipped.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Year of Study <span class="text-danger">*</span></label>
                            <select name="criteria_year" id="criteriaYear" class="form-select" required onchange="updateCriteriaPreview()">
                                <option value="">— Select Year —</option>
                                <?php for ($y = 1; $y <= 6; $y++): ?>
                                <option value="<?= $y ?>">Year <?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Semester <span class="text-danger">*</span></label>
                            <select name="criteria_semester" id="criteriaSemester" class="form-select" required onchange="updateCriteriaPreview()">
                                <option value="">— Select Semester —</option>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Program of Study <span class="text-muted">(optional)</span></label>
                            <select name="criteria_program" id="criteriaProgram" class="form-select" onchange="updateCriteriaPreview()">
                                <option value="">All Programs</option>
                                <?php foreach ($edit_programs as $ep): ?>
                                <option value="<?= htmlspecialchars($ep['program_name']) ?>"><?= htmlspecialchars($ep['program_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Leave blank to assign to students in all programs.</small>
                        </div>
                    </div>
                    <div class="mt-3 p-3" style="background:#f8fafc;border-radius:10px;font-size:0.85rem;">
                        <strong><i class="bi bi-lightning-charge me-1 text-primary"></i>How it works:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            <li>Select Year &amp; Semester to preview matching courses below — uncheck any you want to skip</li>
                            <li>Finds all students in <strong>Exam Clearance</strong> and <strong>Students</strong> tables matching the criteria</li>
                            <li>Enrolls them in only the <strong>checked courses</strong> below</li>
                            <li>Existing enrollments are preserved (no duplicates)</li>
                        </ul>
                    </div>

                    <!-- Live course preview -->
                    <div id="criteriaCoursePreview" class="mt-3" style="display:none;">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <strong style="color:#4f46e5;"><i class="bi bi-journal-bookmark me-1"></i>Matching Courses &nbsp;<span class="badge ms-1" id="criteriaCourseCount" style="background:#4f46e5;">0</span></strong>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="criteriaSelectAll()"><i class="bi bi-check-all"></i> All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="criteriaDeselectAll()"><i class="bi bi-x-square"></i> None</button>
                            </div>
                        </div>
                        <div style="max-height:260px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                    <tr>
                                        <th width="5%"><input type="checkbox" class="form-check-input" id="criteriaSelectAllCb" onchange="criteriaToggleAll(this)"></th>
                                        <th width="12%">Code</th>
                                        <th>Course Name</th>
                                        <th width="22%">Program</th>
                                        <th width="8%">Year</th>
                                        <th width="10%">Semester</th>
                                    </tr>
                                </thead>
                                <tbody id="criteriaCourseTbody"></tbody>
                            </table>
                        </div>
                        <div class="text-muted small mt-1" id="criteriaCourseNote"></div>
                    </div>
                    <div id="criteriaCourseEmpty" class="alert alert-warning mt-3 py-2" style="display:none;font-size:0.85rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>No courses found matching this selection in <code>vle_courses</code>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" onclick="return confirmCriteriaBulkAssign()">
                        <i class="bi bi-people me-1"></i> Assign Courses to Matching Students
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
// Populate bulk approve modal
var bulkApproveModal = document.getElementById('bulkApproveModal');
if (bulkApproveModal) {
    bulkApproveModal.addEventListener('show.bs.modal', function() {
        var checked = getCheckedBoxes();
        document.getElementById('bulkApproveCount').textContent = checked.length;
        var listEl = document.getElementById('bulkApproveList');
        var inputsEl = document.getElementById('bulkApproveInputs');
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
// Populate bulk delete modal
var bulkModal = document.getElementById('bulkDeleteModal');
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

// Populate bulk enroll modal
var bulkEnrollModal = document.getElementById('bulkEnrollModal');
if (bulkEnrollModal) {
    bulkEnrollModal.addEventListener('show.bs.modal', function() {
        var checked = getCheckedBoxes();
        document.getElementById('bulkEnrollCount').textContent = checked.length;
        var listEl = document.getElementById('bulkEnrollList');
        var inputsEl = document.getElementById('bulkEnrollInputs');
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

// ── Edit modal: auto-fill Department & Program Type when Program is selected ─────
function onEditProgramChange(selectEl) {
    var modalId   = selectEl.getAttribute('data-modal-id');
    var selected  = selectEl.options[selectEl.selectedIndex];
    var deptId    = selected ? selected.getAttribute('data-dept-id')   : '';
    var deptName  = selected ? selected.getAttribute('data-dept-name') : '';
    var progType  = selected ? selected.getAttribute('data-prog-type') : '';

    // Set department select
    var deptSel  = document.getElementById('editDept_'     + modalId);
    var deptNote = document.getElementById('editDeptNote_' + modalId);
    if (deptSel && deptId) {
        deptSel.value = deptId;
        if (deptNote) deptNote.style.display = '';
    } else if (deptSel) {
        if (deptNote) deptNote.style.display = 'none';
    }

    // Set program type select
    var typeSel = document.getElementById('editProgType_' + modalId);
    if (typeSel && progType) {
        typeSel.value = progType;
    }
}

// ── Live search: auto-submit form as admin types (400ms debounce) ───────────
(function() {
    var searchInput = document.querySelector('input[name="search"]');
    if (!searchInput) return;
    var form = searchInput.closest('form');
    if (!form) return;
    var timer = null;
    // Add a subtle spinner indicator
    var spinner = document.createElement('span');
    spinner.innerHTML = '<span class="spinner-border spinner-border-sm text-primary ms-2" style="display:none;" id="searchSpinner"></span>';
    searchInput.parentNode.appendChild(spinner);
    var spinEl = document.getElementById('searchSpinner');
    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        if (spinEl) spinEl.style.display = '';
        timer = setTimeout(function() {
            form.submit();
        }, 400);
    });
    // Focus search box on page load if there's a value
    if (searchInput.value) {
        searchInput.focus();
        var len = searchInput.value.length;
        searchInput.setSelectionRange(len, len);
    }
})();

// ── Photo preview before upload ────────────────────────────────────────────────
function previewPhoto(input, previewId) {
    var preview = document.getElementById(previewId);
    if (!preview || !input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        if (preview.tagName === 'IMG') {
            preview.src = e.target.result;
        } else {
            // Replace div with img
            var img = document.createElement('img');
            img.id = previewId;
            img.src = e.target.result;
            img.alt = '';
            img.style.cssText = 'width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #e0f2fe;display:block;margin:0 auto 12px;';
            preview.parentNode.replaceChild(img, preview);
        }
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Filter course checkboxes in Assign Courses modal ─────────────────────────
function filterCourseList(input, listId) {
    var q = input.value.toLowerCase();
    var items = document.querySelectorAll('#' + listId + ' .ac-course-item');
    items.forEach(function(item) {
        var name = item.getAttribute('data-name') || '';
        item.style.display = name.indexOf(q) !== -1 ? '' : 'none';
    });
}

// ── Live countdown timer for locked accounts ──────────────────────────────────
(function() {
    function updateLockTimers() {
        var badges = document.querySelectorAll('.ec-lock-badge[data-unlock-ts]');
        var now = Math.floor(Date.now() / 1000);
        badges.forEach(function(badge) {
            var unlockTs = parseInt(badge.getAttribute('data-unlock-ts'), 10);
            var secsLeft = unlockTs - now;
            var label = badge.querySelector('.lock-timer-label');
            if (!label) return;
            if (secsLeft <= 0) {
                badge.style.background = '#6b7280';
                label.textContent = 'Unlocked — refresh';
            } else {
                var mins = Math.floor(secsLeft / 60);
                var secs = secsLeft % 60;
                if (mins > 0) {
                    label.textContent = mins + 'm ' + (secs > 0 ? secs + 's' : '') + ' left';
                } else {
                    label.textContent = secs + 's left';
                }
            }
        });
    }
    updateLockTimers();
    setInterval(updateLockTimers, 1000);
})();

// ── Bulk Assign by Criteria: live course preview ──────────────────────────────
var allCoursesList = <?= json_encode($all_courses_list) ?>;

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function updateCriteriaPreview() {
    var yearEl  = document.getElementById('criteriaYear');
    var semEl   = document.getElementById('criteriaSemester');
    var progEl  = document.getElementById('criteriaProgram');
    var preview = document.getElementById('criteriaCoursePreview');
    var empty   = document.getElementById('criteriaCourseEmpty');
    var tbody   = document.getElementById('criteriaCourseTbody');
    var countEl = document.getElementById('criteriaCourseCount');
    var noteEl  = document.getElementById('criteriaCourseNote');
    if (!yearEl || !semEl) return;
    var year = yearEl.value;
    var sem  = semEl.value;
    var prog = progEl ? progEl.value : '';

    if (!year || !sem) {
        if (preview) preview.style.display = 'none';
        if (empty)   empty.style.display   = 'none';
        return;
    }

    var matched = allCoursesList.filter(function(c) {
        var yr = parseInt(c.year_of_study);
        var yearMatch = (!yr || yr === parseInt(year));
        var semMatch  = (!c.semester || c.semester === '' || c.semester === 'Both' || c.semester === sem);
        var progMatch = !prog || (!c.program_of_study || c.program_of_study === '' || c.program_of_study === prog);
        return yearMatch && semMatch && progMatch;
    });

    if (matched.length === 0) {
        if (preview) preview.style.display = 'none';
        if (empty)   empty.style.display   = '';
        return;
    }
    if (empty) empty.style.display = 'none';

    var html = '';
    matched.forEach(function(c) {
        var semBadge = c.semester === 'Both' ? 'Sem 1 &amp; 2' : escHtml(c.semester || 'Any');
        html += '<tr>';
        html += '<td class="text-center"><input type="checkbox" class="form-check-input criteria-course-cb" name="selected_course_ids[]" value="' + c.course_id + '" checked onchange="updateCriteriaSelectAll()"></td>';
        html += '<td><strong>' + escHtml(c.course_code) + '</strong></td>';
        html += '<td>' + escHtml(c.course_name) + '</td>';
        html += '<td><small class="text-muted">' + escHtml(c.program_of_study || 'General') + '</small></td>';
        html += '<td>' + (c.year_of_study ? 'Year ' + c.year_of_study : 'Any') + '</td>';
        html += '<td><span class="badge bg-info">' + semBadge + '</span></td>';
        html += '</tr>';
    });
    if (tbody)   tbody.innerHTML = html;
    if (countEl) countEl.textContent = matched.length;
    if (noteEl)  noteEl.textContent  = matched.length + ' course(s) found — uncheck any you want to exclude.';
    var masterCb = document.getElementById('criteriaSelectAllCb');
    if (masterCb) { masterCb.checked = true; masterCb.indeterminate = false; }
    if (preview) preview.style.display = '';
}

function criteriaSelectAll() {
    document.querySelectorAll('.criteria-course-cb').forEach(function(cb){ cb.checked = true; });
    updateCriteriaSelectAll();
}
function criteriaDeselectAll() {
    document.querySelectorAll('.criteria-course-cb').forEach(function(cb){ cb.checked = false; });
    updateCriteriaSelectAll();
}
function criteriaToggleAll(masterCb) {
    document.querySelectorAll('.criteria-course-cb').forEach(function(cb){ cb.checked = masterCb.checked; });
    updateCriteriaSelectAll();
}
function updateCriteriaSelectAll() {
    var cbs     = document.querySelectorAll('.criteria-course-cb');
    var checked = document.querySelectorAll('.criteria-course-cb:checked');
    var master  = document.getElementById('criteriaSelectAllCb');
    if (master) {
        master.checked = cbs.length > 0 && checked.length === cbs.length;
        master.indeterminate = checked.length > 0 && checked.length < cbs.length;
    }
    var noteEl = document.getElementById('criteriaCourseNote');
    if (noteEl) noteEl.textContent = checked.length + ' of ' + cbs.length + ' course(s) selected for assignment.';
}
function confirmCriteriaBulkAssign() {
    var cbs = document.querySelectorAll('.criteria-course-cb');
    if (cbs.length > 0) {
        var checked = document.querySelectorAll('.criteria-course-cb:checked');
        if (checked.length === 0) {
            alert('Please select at least one course to assign.');
            return false;
        }
        return confirm('Assign ' + checked.length + ' course(s) to all matching students?');
    }
    return confirm('Assign courses to all matching students based on the selected criteria?');
}
// Reset preview when modal closes
var _bacModal = document.getElementById('bulkAssignCriteriaModal');
if (_bacModal) {
    _bacModal.addEventListener('hidden.bs.modal', function() {
        var preview = document.getElementById('criteriaCoursePreview');
        var empty   = document.getElementById('criteriaCourseEmpty');
        if (preview) preview.style.display = 'none';
        if (empty)   empty.style.display   = 'none';
        var yr = document.getElementById('criteriaYear');
        var sm = document.getElementById('criteriaSemester');
        var pr = document.getElementById('criteriaProgram');
        if (yr) yr.value = ''; if (sm) sm.value = ''; if (pr) pr.value = '';
    });
}
// ── Deregister tab helpers ────────────────────────────────────────────────────
function deregSelectAll(listId) {
    document.querySelectorAll('#' + listId + ' input[type="checkbox"]').forEach(function(cb){ cb.checked = true; });
}
function deregDeselectAll(listId) {
    document.querySelectorAll('#' + listId + ' input[type="checkbox"]').forEach(function(cb){ cb.checked = false; });
}
function confirmDeregister(btn, name, listId) {
    var checked = document.querySelectorAll('#' + listId + ' input[type="checkbox"]:checked');
    if (checked.length === 0) { alert('Please select at least one course to remove.'); return false; }
    return confirm('Remove ' + checked.length + ' course(s) from ' + name + '? This cannot be undone.');
}

// Initialise Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
    new bootstrap.Tooltip(el);
});
</script>
