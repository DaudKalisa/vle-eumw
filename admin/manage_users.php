<?php
// manage_users.php - Admin manage all system users
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete user
    if (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        $current_user_id = $_SESSION['vle_user_id'];
        
        // Prevent self-deletion
        if ($user_id == $current_user_id) {
            $error = "You cannot delete your own account.";
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $success = "User deleted successfully!";
            } else {
                $error = "Failed to delete user: " . $conn->error;
            }
        }
    }
    
    // Toggle user status
    if (isset($_POST['toggle_status'])) {
        $user_id = (int)$_POST['user_id'];
        $new_status = (int)$_POST['new_status'];
        
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $new_status, $user_id);
        
        if ($stmt->execute()) {
            $success = "User status updated successfully!";
        } else {
            $error = "Failed to update status.";
        }
    }
    
    // Unlock account (clear failed login attempts and account lock)
    if (isset($_POST['unlock_account'])) {
        $user_id = (int)$_POST['user_id'];
        
        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, account_locked_until = NULL, last_failed_login = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Also update account_locks table if it exists
            $al_exists = $conn->query("SHOW TABLES LIKE 'account_locks'")->num_rows > 0;
            if ($al_exists) {
                $unlock_stmt = $conn->prepare("UPDATE account_locks SET unlocked_at = NOW() WHERE user_id = ? AND unlocked_at IS NULL");
                $unlock_stmt->bind_param("i", $user_id);
                $unlock_stmt->execute();
            }
            $success = "Account unlocked successfully! Failed login attempts have been reset.";
        } else {
            $error = "No locked account found for this user, or account was already unlocked.";
        }
    }
    
    // Reset password
    if (isset($_POST['reset_password'])) {
        $user_id = (int)$_POST['user_id'];
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
            $stmt->bind_param("si", $password_hash, $user_id);
            
            if ($stmt->execute()) {
                // Send password reset email notification
                $user_stmt = $conn->prepare("SELECT u.email, u.username, COALESCE(s.full_name, l.full_name, a.full_name, f.full_name, u.username) as full_name FROM users u LEFT JOIN students s ON u.related_student_id = s.student_id LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id LEFT JOIN administrative_staff a ON u.related_staff_id = a.staff_id LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id WHERE u.user_id = ?");
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $reset_user = $user_stmt->get_result()->fetch_assoc();
                
                $email_status = '';
                if ($reset_user && isEmailEnabled()) {
                    $email_sent = sendPasswordResetEmail($reset_user['email'], $reset_user['full_name'], $new_password, true);
                    $email_status = $email_sent ? ' Email notification sent.' : ' Email notification failed.';
                }
                
                $success = "Password reset successfully! User will be required to change password on next login." . $email_status;
            } else {
                $error = "Failed to reset password.";
            }
        } else {
            $error = "Passwords do not match or password is too short (minimum 6 characters).";
        }
    }
    
    // Update user details
    if (isset($_POST['update_user'])) {
        $user_id = (int)$_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = trim($_POST['role']);
        
        // Collect additional roles from checkboxes
        $all_roles = ['student', 'lecturer', 'staff', 'finance', 'hod', 'dean', 'odl_coordinator', 'examination_manager', 'examination_officer', 'research_coordinator'];
        $additional = [];
        if (isset($_POST['additional_roles']) && is_array($_POST['additional_roles'])) {
            foreach ($_POST['additional_roles'] as $r) {
                $r = trim($r);
                if (in_array($r, $all_roles) && $r !== $role) {
                    $additional[] = $r;
                }
            }
        }
        $additional_roles = !empty($additional) ? implode(',', $additional) : null;
        
        // Check if username already exists for different user
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $check_stmt->bind_param("si", $username, $user_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $error = "Username '$username' already exists.";
        } else {
            // Check if email already exists for different user
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check_stmt->bind_param("si", $email, $user_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Email '$email' already exists.";
            } else {
                // First get the current user data to check if role changed
                $cur_stmt = $conn->prepare("SELECT u.*, 
                    COALESCE(s.full_name, l.full_name, a.full_name, f.full_name, em.full_name, oc.full_name, u.username) as resolved_name,
                    COALESCE(s.phone, l.phone, a.phone, f.phone, em.phone, oc.phone, '') as resolved_phone,
                    COALESCE(l.department, a.department, f.department, em.department, oc.department, '') as resolved_department
                    FROM users u
                    LEFT JOIN students s ON u.related_student_id = s.student_id
                    LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
                    LEFT JOIN administrative_staff a ON u.related_staff_id = a.staff_id
                    LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id
                    LEFT JOIN examination_managers em ON u.related_staff_id = em.manager_id AND u.role IN ('examination_manager','examination_officer')
                    LEFT JOIN odl_coordinators oc ON u.related_lecturer_id = oc.coordinator_id
                    WHERE u.user_id = ?");
                $cur_stmt->bind_param("i", $user_id);
                $cur_stmt->execute();
                $current_user = $cur_stmt->get_result()->fetch_assoc();
                $old_role = $current_user['role'] ?? '';
                $resolved_name = $current_user['resolved_name'] ?? $username;
                $resolved_phone = $current_user['resolved_phone'] ?? '';
                $resolved_department = $current_user['resolved_department'] ?? '';
                
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ?, additional_roles = ? WHERE user_id = ?");
                $stmt->bind_param("ssssi", $username, $email, $role, $additional_roles, $user_id);
                
                if ($stmt->execute()) {
                    // If role changed, look up and update the related_id for the new role
                    if ($role !== $old_role) {
                        $related_id_updated = false;
                        $related_col = '';
                        $related_val = null;
                        
                        switch ($role) {
                            case 'student':
                                // Look up student by email
                                $lookup = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['student_id'];
                                    $upd = $conn->prepare("UPDATE users SET related_student_id = ? WHERE user_id = ?");
                                    $upd->bind_param("si", $related_val, $user_id);
                                    $upd->execute();
                                    $related_id_updated = true;
                                }
                                break;
                                
                            case 'lecturer':
                                // Look up lecturer by email, create if not exists
                                $lookup = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['lecturer_id'];
                                } else {
                                    // Auto-create lecturer record
                                    $ins = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, department, position, is_active) VALUES (?, ?, ?, ?, 'Lecturer', 1)");
                                    $ins->bind_param("ssss", $resolved_name, $email, $resolved_phone, $resolved_department);
                                    $ins->execute();
                                    $related_val = $conn->insert_id;
                                }
                                $upd = $conn->prepare("UPDATE users SET related_lecturer_id = ? WHERE user_id = ?");
                                $upd->bind_param("ii", $related_val, $user_id);
                                $upd->execute();
                                $related_id_updated = true;
                                break;
                                
                            case 'staff':
                            case 'admin':
                                // Look up administrative staff by email, create if not exists
                                $lookup = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['staff_id'];
                                } else {
                                    $pos = ($role === 'admin') ? 'Administrator' : 'Staff';
                                    $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                    $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $pos);
                                    $ins->execute();
                                    $related_val = $conn->insert_id;
                                }
                                $upd = $conn->prepare("UPDATE users SET related_staff_id = ? WHERE user_id = ?");
                                $upd->bind_param("ii", $related_val, $user_id);
                                $upd->execute();
                                $related_id_updated = true;
                                break;
                                
                            case 'hod':
                                // Look up administrative staff by email, create if not exists with HOD position
                                $lookup = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['staff_id'];
                                    // Ensure position is set to Head of Department
                                    $upd_pos = $conn->prepare("UPDATE administrative_staff SET position = 'Head of Department' WHERE staff_id = ? AND (position IS NULL OR position = '' OR position = 'Staff')");
                                    $upd_pos->bind_param("i", $related_val);
                                    $upd_pos->execute();
                                } else {
                                    $hod_pos = 'Head of Department';
                                    $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                    $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $hod_pos);
                                    $ins->execute();
                                    $related_val = $conn->insert_id;
                                }
                                $upd = $conn->prepare("UPDATE users SET related_hod_id = ?, related_staff_id = ? WHERE user_id = ?");
                                $upd->bind_param("iii", $related_val, $related_val, $user_id);
                                $upd->execute();
                                $related_id_updated = true;
                                break;
                                
                            case 'dean':
                                // Look up administrative staff by email, create if not exists with Dean position
                                $lookup = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['staff_id'];
                                    // Ensure position is set to Dean
                                    $upd_pos = $conn->prepare("UPDATE administrative_staff SET position = 'Dean' WHERE staff_id = ? AND (position IS NULL OR position = '' OR position = 'Staff')");
                                    $upd_pos->bind_param("i", $related_val);
                                    $upd_pos->execute();
                                } else {
                                    $dean_pos = 'Dean';
                                    $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                    $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $dean_pos);
                                    $ins->execute();
                                    $related_val = $conn->insert_id;
                                }
                                $upd = $conn->prepare("UPDATE users SET related_dean_id = ?, related_staff_id = ? WHERE user_id = ?");
                                $upd->bind_param("iii", $related_val, $related_val, $user_id);
                                $upd->execute();
                                $related_id_updated = true;
                                break;
                                
                            case 'finance':
                                // Look up finance user by email, create if not exists
                                $lookup = $conn->prepare("SELECT finance_id FROM finance_users WHERE email = ?");
                                $lookup->bind_param("s", $email);
                                $lookup->execute();
                                $lr = $lookup->get_result();
                                if ($lr->num_rows > 0) {
                                    $related_val = $lr->fetch_assoc()['finance_id'];
                                } else {
                                    $fin_code = 'FIN-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                                    $fin_pos = 'Finance Officer';
                                    $ins = $conn->prepare("INSERT INTO finance_users (finance_code, full_name, email, phone, department, position, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
                                    $ins->bind_param("ssssss", $fin_code, $resolved_name, $email, $resolved_phone, $resolved_department, $fin_pos);
                                    $ins->execute();
                                    $related_val = $conn->insert_id;
                                }
                                $upd = $conn->prepare("UPDATE users SET related_finance_id = ? WHERE user_id = ?");
                                $upd->bind_param("ii", $related_val, $user_id);
                                $upd->execute();
                                $related_id_updated = true;
                                break;
                                
                            case 'examination_manager':
                            case 'examination_officer':
                                // Look up examination manager by email, create if not exists
                                $em_exists = $conn->query("SHOW TABLES LIKE 'examination_managers'")->num_rows > 0;
                                if ($em_exists) {
                                    $lookup = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ?");
                                    $lookup->bind_param("s", $email);
                                    $lookup->execute();
                                    $lr = $lookup->get_result();
                                    if ($lr->num_rows > 0) {
                                        $related_val = $lr->fetch_assoc()['manager_id'];
                                    } else {
                                        $em_pos = ($role === 'examination_manager') ? 'Examination Manager' : 'Examination Officer';
                                        $ins = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position, hire_date, is_active, created_at) VALUES (?, ?, ?, ?, ?, CURDATE(), 1, NOW())");
                                        $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $em_pos);
                                        $ins->execute();
                                        $related_val = $conn->insert_id;
                                    }
                                    $upd = $conn->prepare("UPDATE users SET related_staff_id = ? WHERE user_id = ?");
                                    $upd->bind_param("ii", $related_val, $user_id);
                                    $upd->execute();
                                    $related_id_updated = true;
                                }
                                break;
                                
                            case 'odl_coordinator':
                                // Look up ODL coordinator or lecturer by email, create if not exists
                                $oc_exists = $conn->query("SHOW TABLES LIKE 'odl_coordinators'")->num_rows > 0;
                                if ($oc_exists) {
                                    $lookup = $conn->prepare("SELECT coordinator_id FROM odl_coordinators WHERE email = ?");
                                    $lookup->bind_param("s", $email);
                                    $lookup->execute();
                                    $lr = $lookup->get_result();
                                    if ($lr->num_rows > 0) {
                                        $related_val = $lr->fetch_assoc()['coordinator_id'];
                                    } else {
                                        $coord_pos = 'ODL Coordinator';
                                        $ins = $conn->prepare("INSERT INTO odl_coordinators (user_id, full_name, email, phone, department, position, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                                        $ins->bind_param("isssss", $user_id, $resolved_name, $email, $resolved_phone, $resolved_department, $coord_pos);
                                        $ins->execute();
                                        $related_val = $conn->insert_id;
                                    }
                                }
                                // Also link lecturer record if exists
                                $lk = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE email = ?");
                                $lk->bind_param("s", $email);
                                $lk->execute();
                                $lk_r = $lk->get_result();
                                if ($lk_r->num_rows > 0) {
                                    $lec_id = $lk_r->fetch_assoc()['lecturer_id'];
                                    $upd = $conn->prepare("UPDATE users SET related_lecturer_id = ? WHERE user_id = ?");
                                    $upd->bind_param("ii", $lec_id, $user_id);
                                    $upd->execute();
                                }
                                $related_id_updated = true;
                                break;
                                
                            case 'research_coordinator':
                                // Look up research coordinator by email, create if not exists
                                $rc_exists = $conn->query("SHOW TABLES LIKE 'research_coordinators'")->num_rows > 0;
                                if ($rc_exists) {
                                    $lookup = $conn->prepare("SELECT coordinator_id FROM research_coordinators WHERE email = ?");
                                    $lookup->bind_param("s", $email);
                                    $lookup->execute();
                                    $lr = $lookup->get_result();
                                    if ($lr->num_rows > 0) {
                                        $related_val = $lr->fetch_assoc()['coordinator_id'];
                                    } else {
                                        $ins = $conn->prepare("INSERT INTO research_coordinators (user_id, full_name, email, phone, department, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                                        $ins->bind_param("issss", $user_id, $resolved_name, $email, $resolved_phone, $resolved_department);
                                        $ins->execute();
                                        $related_val = $conn->insert_id;
                                    }
                                    $upd = $conn->prepare("UPDATE users SET related_staff_id = ? WHERE user_id = ?");
                                    $upd->bind_param("ii", $related_val, $user_id);
                                    $upd->execute();
                                    $related_id_updated = true;
                                }
                                break;
                        }
                        
                        if ($related_id_updated) {
                            $success = "User updated successfully! Role changed to " . ucfirst(str_replace('_', ' ', $role)) . " and related ID linked.";
                        } else {
                            $success = "User updated successfully! Role changed to " . ucfirst(str_replace('_', ' ', $role)) . ". Note: No matching record found in the " . str_replace('_', ' ', $role) . " table for this email - related ID was not updated.";
                        }
                        
                        // Also ensure records exist for additional roles
                        if (!empty($additional)) {
                            foreach ($additional as $add_role) {
                                switch ($add_role) {
                                    case 'lecturer':
                                        $chk = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE email = ?");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            $ins = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, department, position, is_active) VALUES (?, ?, ?, ?, 'Lecturer', 1)");
                                            $ins->bind_param("ssss", $resolved_name, $email, $resolved_phone, $resolved_department);
                                            $ins->execute();
                                        }
                                        break;
                                    case 'hod':
                                        $chk = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ? AND position = 'Head of Department'");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            // Check if any admin_staff record exists
                                            $chk2 = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                            $chk2->bind_param("s", $email); $chk2->execute();
                                            if ($chk2->get_result()->num_rows === 0) {
                                                $hod_p = 'Head of Department';
                                                $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                                $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $hod_p);
                                                $ins->execute();
                                            }
                                        }
                                        break;
                                    case 'dean':
                                        $chk = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ? AND position = 'Dean'");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            $chk2 = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                            $chk2->bind_param("s", $email); $chk2->execute();
                                            if ($chk2->get_result()->num_rows === 0) {
                                                $dean_p = 'Dean';
                                                $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                                $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $dean_p);
                                                $ins->execute();
                                            }
                                        }
                                        break;
                                    case 'finance':
                                        $chk = $conn->prepare("SELECT finance_id FROM finance_users WHERE email = ?");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            $fc = 'FIN-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                                            $fp = 'Finance Officer';
                                            $ins = $conn->prepare("INSERT INTO finance_users (finance_code, full_name, email, phone, department, position, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
                                            $ins->bind_param("ssssss", $fc, $resolved_name, $email, $resolved_phone, $resolved_department, $fp);
                                            $ins->execute();
                                        }
                                        break;
                                    case 'examination_manager':
                                    case 'examination_officer':
                                        $chk = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ?");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            $emp = ($add_role === 'examination_manager') ? 'Examination Manager' : 'Examination Officer';
                                            $ins = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position, hire_date, is_active, created_at) VALUES (?, ?, ?, ?, ?, CURDATE(), 1, NOW())");
                                            $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $emp);
                                            $ins->execute();
                                        }
                                        break;
                                    case 'odl_coordinator':
                                        $oc_t = $conn->query("SHOW TABLES LIKE 'odl_coordinators'")->num_rows > 0;
                                        if ($oc_t) {
                                            $chk = $conn->prepare("SELECT coordinator_id FROM odl_coordinators WHERE email = ?");
                                            $chk->bind_param("s", $email); $chk->execute();
                                            if ($chk->get_result()->num_rows === 0) {
                                                $cp = 'ODL Coordinator';
                                                $ins = $conn->prepare("INSERT INTO odl_coordinators (user_id, full_name, email, phone, department, position, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                                                $ins->bind_param("isssss", $user_id, $resolved_name, $email, $resolved_phone, $resolved_department, $cp);
                                                $ins->execute();
                                            }
                                        }
                                        break;
                                    case 'research_coordinator':
                                        $rc_t = $conn->query("SHOW TABLES LIKE 'research_coordinators'")->num_rows > 0;
                                        if ($rc_t) {
                                            $chk = $conn->prepare("SELECT coordinator_id FROM research_coordinators WHERE email = ?");
                                            $chk->bind_param("s", $email); $chk->execute();
                                            if ($chk->get_result()->num_rows === 0) {
                                                $ins = $conn->prepare("INSERT INTO research_coordinators (user_id, full_name, email, phone, department, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                                                $ins->bind_param("issss", $user_id, $resolved_name, $email, $resolved_phone, $resolved_department);
                                                $ins->execute();
                                            }
                                        }
                                        break;
                                    case 'staff':
                                    case 'admin':
                                        $chk = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                                        $chk->bind_param("s", $email); $chk->execute();
                                        if ($chk->get_result()->num_rows === 0) {
                                            $sp = ($add_role === 'admin') ? 'Administrator' : 'Staff';
                                            $ins = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                                            $ins->bind_param("sssss", $resolved_name, $email, $resolved_phone, $resolved_department, $sp);
                                            $ins->execute();
                                        }
                                        break;
                                }
                            }
                        }
                        
                        // Send role change notification email
                        if (isEmailEnabled()) {
                            try {
                                $role_display = ucfirst(str_replace('_', ' ', $role));
                                $old_role_display = ucfirst(str_replace('_', ' ', $old_role));
                                
                                $role_descriptions = [
                                    'student' => 'Access your courses, assignments, and learning materials through the Student Portal.',
                                    'lecturer' => 'Manage your courses, upload content, create assignments, and grade student submissions.',
                                    'staff' => 'Access administrative functions and staff management tools.',
                                    'admin' => 'Full system administration including user management, settings, and system configuration.',
                                    'hod' => 'Oversee your department\'s courses, lecturers, students, and academic allocations.',
                                    'dean' => 'Manage faculty-level academic operations, programs, and departmental oversight.',
                                    'finance' => 'Manage student fees, payments, financial reports, and billing operations.',
                                    'odl_coordinator' => 'Coordinate Open and Distance Learning programs and student support.',
                                    'examination_manager' => 'Oversee examination processes, security, scheduling, and result management.',
                                    'examination_officer' => 'Handle day-to-day exam operations, invigilation, and exam logistics.',
                                    'research_coordinator' => 'Oversee dissertation management, supervisor assignments, and research quality assurance.'
                                ];
                                
                                $role_dashboards = [
                                    'student' => 'student/dashboard.php',
                                    'lecturer' => 'lecturer/dashboard.php',
                                    'staff' => 'admin/dashboard.php',
                                    'admin' => 'admin/dashboard.php',
                                    'hod' => 'hod/dashboard.php',
                                    'dean' => 'dean/dashboard.php',
                                    'finance' => 'finance/dashboard.php',
                                    'odl_coordinator' => 'odl_coordinator/dashboard.php',
                                    'examination_manager' => 'examination_manager/dashboard.php',
                                    'examination_officer' => 'examination_officer/dashboard.php',
                                    'research_coordinator' => 'research_coordinator/dashboard.php'
                                ];
                                
                                $description = $role_descriptions[$role] ?? 'Access your designated portal and features.';
                                $dashboard_url = $role_dashboards[$role] ?? 'dashboard.php';
                                $uni = getUniversitySettings();
                                $uni_name = $uni['university_name'] ?? 'VLE System';
                                $login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI'])) . '/login.php';
                                
                                $subject = "Role Assignment: You have been assigned as " . $role_display . " - " . $uni_name;
                                
                                $content = "
                                    <p class='greeting'>Dear <strong>" . htmlspecialchars($username) . "</strong>,</p>
                                    <p class='content-text'>We are writing to inform you that your account role has been updated in the <strong>" . htmlspecialchars($uni_name) . "</strong> Virtual Learning Environment.</p>
                                    
                                    <div class='info-box'>
                                        <h3>Role Assignment Details</h3>
                                        <table width='100%' cellpadding='0' cellspacing='0'>
                                            <tr><td style='padding:8px 0;font-weight:600;color:#64748b;width:140px;font-size:14px;border-bottom:1px solid #e2e8f0;'>Previous Role:</td><td style='padding:8px 0;color:#1e293b;font-weight:500;font-size:14px;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($old_role_display) . "</td></tr>
                                            <tr><td style='padding:8px 0;font-weight:600;color:#64748b;width:140px;font-size:14px;border-bottom:1px solid #e2e8f0;'>New Role:</td><td style='padding:8px 0;color:#1e293b;font-weight:700;font-size:14px;border-bottom:1px solid #e2e8f0;'><span style='color:#16a34a;'>" . htmlspecialchars($role_display) . "</span></td></tr>
                                            <tr><td style='padding:8px 0;font-weight:600;color:#64748b;width:140px;font-size:14px;border-bottom:1px solid #e2e8f0;'>Username:</td><td style='padding:8px 0;color:#1e293b;font-weight:500;font-size:14px;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($username) . "</td></tr>
                                            <tr><td style='padding:8px 0;font-weight:600;color:#64748b;width:140px;font-size:14px;'>Email:</td><td style='padding:8px 0;color:#1e293b;font-weight:500;font-size:14px;'>" . htmlspecialchars($email) . "</td></tr>
                                        </table>
                                    </div>
                                    
                                    <div class='alert-box info'>
                                        <strong>What does this mean?</strong><br>
                                        " . htmlspecialchars($description) . "
                                    </div>
                                    
                                    <div class='btn-container'>
                                        <a href='" . htmlspecialchars($login_url) . "' class='btn-primary'>Login to Your Portal</a>
                                    </div>
                                    
                                    <p class='content-text'>If you have any questions about your new role or need assistance, please contact the system administrator.</p>
                                    
                                    <div class='divider'></div>
                                    <p style='font-size:13px;color:#6b7280;'>This change was made by an administrator on " . date('F j, Y \a\t g:i A') . ".</p>
                                ";
                                
                                $body = getEmailTemplate('Role Assignment Notification', $content, '', '#7c3aed');
                                $email_sent = sendEmail($email, $username, $subject, $body);
                                
                                if ($email_sent) {
                                    $success .= " A notification email has been sent to " . htmlspecialchars($email) . ".";
                                }
                            } catch (Throwable $e) {
                                error_log("Role change email failed: " . $e->getMessage());
                                // Don't block the success message if email fails
                            }
                        }
                    } else {
                        $success = "User updated successfully!";
                    }
                } else {
                    $error = "Failed to update user: " . $conn->error;
                }
            }
        }
    }
}

// Get filter parameters
$filter_role = $_GET['role'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];
$types = '';

// Always exclude students from admin user list (students managed separately)
$where_conditions[] = "u.role != 'student'";

if ($filter_role !== 'all') {
    $where_conditions[] = "(u.role = ? OR FIND_IN_SET(?, COALESCE(u.additional_roles, '')))";
    $params[] = $filter_role;
    $params[] = $filter_role;
    $types .= 'ss';
}

if ($filter_status !== 'all') {
    $where_conditions[] = "u.is_active = ?";
    $params[] = ($filter_status === 'active') ? 1 : 0;
    $types .= 'i';
}

if (!empty($search)) {
    $where_conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "SELECT u.*, 
          COALESCE(s.full_name, l.full_name, f.full_name, u.username) as display_name,
          s.student_id, l.lecturer_id, f.finance_id,
          u.additional_roles
          FROM users u
          LEFT JOIN students s ON u.related_student_id = s.student_id
          LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
          LEFT JOIN finance_users f ON u.related_finance_id = f.finance_id
          $where_clause
          ORDER BY u.created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Get counts by role (excluding students)
$role_counts = [];
$count_result = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE role != 'student' GROUP BY role");
while ($row = $count_result->fetch_assoc()) {
    $role_counts[$row['role']] = $row['count'];
}
$total_users = array_sum($role_counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .user-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 12px;
        }
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .role-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
        }
        .filter-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .action-btn {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        .table th {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            font-weight: 600;
            border: none;
        }
        .status-active { color: #10b981; }
        .status-inactive { color: #ef4444; }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'manage_users';
    $pageTitle = 'Manage Users';
    $breadcrumbs = [['title' => 'Manage Users']];
    include 'header_nav.php'; 
    ?>
    
    <div class="vle-content">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1"><i class="bi bi-people-fill me-2"></i>Manage All Users</h2>
                    <p class="text-muted mb-0">View, edit, and manage all system users</p>
                </div>
                <div>
                    <span class="badge bg-primary fs-6"><?php echo $total_users; ?> Total Users</span>
                </div>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #3b82f6;">
                        <div class="card-body py-3">
                            <h4 class="mb-1 text-primary"><?php echo $role_counts['student'] ?? 0; ?></h4>
                            <small class="text-muted">Students</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #10b981;">
                        <div class="card-body py-3">
                            <h4 class="mb-1 text-success"><?php echo $role_counts['lecturer'] ?? 0; ?></h4>
                            <small class="text-muted">Lecturers</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #f59e0b;">
                        <div class="card-body py-3">
                            <h4 class="mb-1 text-warning"><?php echo $role_counts['finance'] ?? 0; ?></h4>
                            <small class="text-muted">Finance</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #ef4444;">
                        <div class="card-body py-3">
                            <h4 class="mb-1 text-danger"><?php echo $role_counts['staff'] ?? 0; ?></h4>
                            <small class="text-muted">Admins</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #8b5cf6;">
                        <div class="card-body py-3">
                            <h4 class="mb-1" style="color: #8b5cf6;"><?php echo ($role_counts['hod'] ?? 0) + ($role_counts['dean'] ?? 0); ?></h4>
                            <small class="text-muted">HOD/Dean</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <div class="card text-center h-100" style="border-left: 4px solid #6366f1;">
                        <div class="card-body py-3">
                            <h4 class="mb-1" style="color: #6366f1;"><?php echo $total_users; ?></h4>
                            <small class="text-muted">Total</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" placeholder="Username or email..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role">
                            <option value="all" <?php echo $filter_role === 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <option value="lecturer" <?php echo $filter_role === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                            <option value="staff" <?php echo $filter_role === 'staff' ? 'selected' : ''; ?>>Admin/Staff</option>
                            <option value="finance" <?php echo $filter_role === 'finance' ? 'selected' : ''; ?>>Finance</option>
                            <option value="hod" <?php echo $filter_role === 'hod' ? 'selected' : ''; ?>>HOD</option>
                            <option value="dean" <?php echo $filter_role === 'dean' ? 'selected' : ''; ?>>Dean</option>
                            <option value="odl_coordinator" <?php echo $filter_role === 'odl_coordinator' ? 'selected' : ''; ?>>ODL Coordinator</option>
                            <option value="examination_manager" <?php echo $filter_role === 'examination_manager' ? 'selected' : ''; ?>>Examination Manager</option>
                            <option value="examination_officer" <?php echo $filter_role === 'examination_officer' ? 'selected' : ''; ?>>Examination Officer</option>
                            <option value="research_coordinator" <?php echo $filter_role === 'research_coordinator' ? 'selected' : ''; ?>>Research Coordinator</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="manage_users.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Clear</a>
                    </div>
                </form>
            </div>
            
            <!-- Users Table -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Display Name</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Last Login</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                            <p class="mb-0 mt-2">No users found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><strong>#<?php echo $u['user_id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><?php echo htmlspecialchars($u['display_name']); ?></td>
                                            <td>
                                                <?php
                                                $role_colors = [
                                                    'student' => 'bg-info',
                                                    'lecturer' => 'bg-success',
                                                    'staff' => 'bg-danger',
                                                    'finance' => 'bg-warning text-dark',
                                                    'hod' => 'bg-purple',
                                                    'dean' => 'bg-dark',
                                                    'odl_coordinator' => 'bg-primary',
                                                    'examination_manager' => 'bg-secondary',
                                                    'examination_officer' => 'bg-secondary',
                                                    'research_coordinator' => 'bg-info'
                                                ];
                                                $role_labels = [
                                                    'student' => 'Student',
                                                    'lecturer' => 'Lecturer',
                                                    'staff' => 'Admin/Staff',
                                                    'finance' => 'Finance',
                                                    'hod' => 'HOD',
                                                    'dean' => 'Dean',
                                                    'odl_coordinator' => 'Coordinator',
                                                    'examination_manager' => 'Examination Manager',
                                                    'examination_officer' => 'Examination Officer',
                                                    'research_coordinator' => 'Research Coordinator'
                                                ];
                                                $role_color = $role_colors[$u['role']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $role_color; ?> role-badge">
                                                    <?php echo $role_labels[$u['role']] ?? ucfirst($u['role']); ?>
                                                </span>
                                                <?php if (!empty($u['additional_roles'])): ?>
                                                    <?php foreach (explode(',', $u['additional_roles']) as $extra_role): ?>
                                                        <?php $extra_role = trim($extra_role); if (empty($extra_role)) continue; ?>
                                                        <span class="badge <?php echo $role_colors[$extra_role] ?? 'bg-secondary'; ?> role-badge" style="opacity:0.8; font-size:0.65rem;">
                                                            +<?php echo $role_labels[$extra_role] ?? ucfirst($extra_role); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($u['is_active']): ?>
                                                    <span class="status-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="status-inactive"><i class="bi bi-x-circle-fill me-1"></i>Inactive</span>
                                                <?php endif; ?>
                                                <?php 
                                                $is_locked = !empty($u['account_locked_until']) && strtotime($u['account_locked_until']) > time();
                                                $has_failed_attempts = !empty($u['failed_login_attempts']) && $u['failed_login_attempts'] >= 5;
                                                if ($is_locked): ?>
                                                    <br><span class="badge bg-danger"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                                                <?php elseif ($has_failed_attempts): ?>
                                                    <br><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i><?php echo (int)$u['failed_login_attempts']; ?> Failed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $u['created_at'] ? date('M j, Y', strtotime($u['created_at'])) : 'N/A'; ?></td>
                                            <td><?php echo $u['last_login'] ? date('M j, Y H:i', strtotime($u['last_login'])) : 'Never'; ?></td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <!-- Edit Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-primary action-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#editModal<?php echo $u['user_id']; ?>"
                                                            title="Edit User">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    
                                                    <!-- Reset Password Button -->
                                                    <button type="button" class="btn btn-sm btn-outline-warning action-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#resetModal<?php echo $u['user_id']; ?>"
                                                            title="Reset Password">
                                                        <i class="bi bi-key"></i>
                                                    </button>
                                                    
                                                    <!-- Toggle Status Button -->
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" name="toggle_status" 
                                                                class="btn btn-sm btn-outline-<?php echo $u['is_active'] ? 'secondary' : 'success'; ?> action-btn"
                                                                title="<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="bi bi-<?php echo $u['is_active'] ? 'pause' : 'play'; ?>-fill"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Unlock Account Button (visible when locked or has 5+ failed attempts) -->
                                                    <?php if ($is_locked || $has_failed_attempts): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                        <button type="submit" name="unlock_account" 
                                                                class="btn btn-sm btn-outline-warning action-btn"
                                                                onclick="return confirm('Unlock this account and reset failed login attempts?')"
                                                                title="<?php echo $is_locked ? 'Unlock Account' : 'Reset Failed Attempts'; ?>">
                                                            <i class="bi bi-unlock-fill"></i>
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Delete Button -->
                                                    <?php if ($u['user_id'] != $_SESSION['vle_user_id']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger action-btn" 
                                                            data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $u['user_id']; ?>"
                                                            title="Delete User">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Edit Modal -->
                                        <div class="modal fade" id="editModal<?php echo $u['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header" style="background: var(--vle-gradient-primary); color: white;">
                                                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit User #<?php echo $u['user_id']; ?></h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Username</label>
                                                                    <input type="text" class="form-control" name="username" 
                                                                           value="<?php echo htmlspecialchars($u['username']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Email</label>
                                                                    <input type="email" class="form-control" name="email" 
                                                                           value="<?php echo htmlspecialchars($u['email']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Primary Role</label>
                                                                    <select class="form-select" name="role" required onchange="updateAdditionalRoles(this, <?php echo $u['user_id']; ?>)">
                                                                        <option value="student" <?php echo $u['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                                                        <option value="lecturer" <?php echo $u['role'] === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                                                        <option value="staff" <?php echo $u['role'] === 'staff' ? 'selected' : ''; ?>>Admin/Staff</option>
                                                                        <option value="finance" <?php echo $u['role'] === 'finance' ? 'selected' : ''; ?>>Finance</option>
                                                                        <option value="hod" <?php echo $u['role'] === 'hod' ? 'selected' : ''; ?>>HOD</option>
                                                                        <option value="dean" <?php echo $u['role'] === 'dean' ? 'selected' : ''; ?>>Dean</option>
                                                                        <option value="odl_coordinator" <?php echo $u['role'] === 'odl_coordinator' ? 'selected' : ''; ?>>ODL Coordinator</option>
                                                                        <option value="examination_manager" <?php echo $u['role'] === 'examination_manager' ? 'selected' : ''; ?>>Examination Manager</option>
                                                                        <option value="examination_officer" <?php echo $u['role'] === 'examination_officer' ? 'selected' : ''; ?>>Examination Officer</option>
                                                                        <option value="research_coordinator" <?php echo $u['role'] === 'research_coordinator' ? 'selected' : ''; ?>>Research Coordinator</option>
                                                                    </select>
                                                                    <small class="text-muted">Determines default dashboard on login</small>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Additional Roles</label>
                                                                    <div class="border rounded p-2" id="additionalRoles<?php echo $u['user_id']; ?>" style="max-height: 160px; overflow-y: auto;">
                                                                        <?php
                                                                        $available_roles = [
                                                                            'lecturer' => 'Lecturer',
                                                                            'staff' => 'Admin/Staff',
                                                                            'hod' => 'HOD',
                                                                            'dean' => 'Dean',
                                                                            'odl_coordinator' => 'ODL Coordinator',
                                                                            'examination_manager' => 'Examination Manager',
                                                                            'examination_officer' => 'Examination Officer',
                                                                            'research_coordinator' => 'Research Coordinator',
                                                                            'finance' => 'Finance',
                                                                            'student' => 'Student'
                                                                        ];
                                                                        $user_additional = !empty($u['additional_roles']) ? array_map('trim', explode(',', $u['additional_roles'])) : [];
                                                                        foreach ($available_roles as $rval => $rlabel):
                                                                            $is_primary = ($u['role'] === $rval);
                                                                            $is_checked = in_array($rval, $user_additional);
                                                                        ?>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input additional-role-<?php echo $u['user_id']; ?>" 
                                                                                   type="checkbox" name="additional_roles[]" 
                                                                                   value="<?php echo $rval; ?>" 
                                                                                   id="role_<?php echo $rval; ?>_<?php echo $u['user_id']; ?>"
                                                                                   <?php echo $is_checked ? 'checked' : ''; ?>
                                                                                   <?php echo $is_primary ? 'disabled' : ''; ?>>
                                                                            <label class="form-check-label <?php echo $is_primary ? 'text-muted' : ''; ?>" 
                                                                                   for="role_<?php echo $rval; ?>_<?php echo $u['user_id']; ?>">
                                                                                <?php echo $rlabel; ?>
                                                                                <?php if ($is_primary): ?>
                                                                                    <small class="text-muted">(primary)</small>
                                                                                <?php endif; ?>
                                                                            </label>
                                                                        </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                    <small class="text-muted">Grant access to multiple portals</small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="update_user" class="btn btn-primary">
                                                                <i class="bi bi-save me-1"></i>Save Changes
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Reset Password Modal -->
                                        <div class="modal fade" id="resetModal<?php echo $u['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header bg-warning">
                                                            <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                            <p>Reset password for <strong><?php echo htmlspecialchars($u['username']); ?></strong></p>
                                                            <div class="mb-3">
                                                                <label class="form-label">New Password</label>
                                                                <input type="password" class="form-control" name="new_password" 
                                                                       minlength="6" required placeholder="Minimum 6 characters">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Confirm Password</label>
                                                                <input type="password" class="form-control" name="confirm_password" 
                                                                       minlength="6" required placeholder="Confirm password">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="reset_password" class="btn btn-warning">
                                                                <i class="bi bi-key me-1"></i>Reset Password
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Delete Modal -->
                                        <div class="modal fade" id="deleteModal<?php echo $u['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete User</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                            <div class="text-center mb-3">
                                                                <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                                                            </div>
                                                            <p class="text-center">Are you sure you want to delete this user?</p>
                                                            <div class="alert alert-light">
                                                                <strong>Username:</strong> <?php echo htmlspecialchars($u['username']); ?><br>
                                                                <strong>Email:</strong> <?php echo htmlspecialchars($u['email']); ?><br>
                                                                <strong>Role:</strong> <?php echo ucfirst($u['role']); ?>
                                                            </div>
                                                            <p class="text-danger text-center"><small>This action cannot be undone!</small></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="delete_user" class="btn btn-danger">
                                                                <i class="bi bi-trash me-1"></i>Delete User
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Summary -->
            <div class="mt-3 text-muted">
                <small>Showing <?php echo count($users); ?> of <?php echo $total_users; ?> users</small>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // When primary role changes, disable that checkbox in additional roles
    function updateAdditionalRoles(selectEl, userId) {
        const primaryRole = selectEl.value;
        const checkboxes = document.querySelectorAll('.additional-role-' + userId);
        checkboxes.forEach(cb => {
            if (cb.value === primaryRole) {
                cb.checked = false;
                cb.disabled = true;
                cb.closest('.form-check').querySelector('label').classList.add('text-muted');
                // Update label to show (primary)
                let label = cb.closest('.form-check').querySelector('label');
                if (!label.querySelector('small')) {
                    label.insertAdjacentHTML('beforeend', ' <small class="text-muted">(primary)</small>');
                }
            } else {
                cb.disabled = false;
                cb.closest('.form-check').querySelector('label').classList.remove('text-muted');
                let small = cb.closest('.form-check').querySelector('label small');
                if (small) small.remove();
            }
        });
    }
    </script>
</body>
</html>
