<?php
/**
 * Manage Examination Managers - Admin Portal
 * Separate management for Examination Managers (senior role)
 * Managers oversee the entire examination system, security, and reporting
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Ensure examination_manager role exists in users ENUM
try {
    $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col_check && $row = $col_check->fetch_assoc()) {
        if (strpos($row['Type'], 'examination_manager') === false) {
            preg_match("/enum\((.*)\)/", $row['Type'], $matches);
            if (!empty($matches[1])) {
                $new_enum = str_replace(")", ",'examination_manager')", "enum(" . $matches[1] . ")");
                $conn->query("ALTER TABLE users MODIFY COLUMN role " . $new_enum . " DEFAULT 'student'");
            }
        }
    }
} catch (Exception $e) { /* silently continue */ }

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_manager'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? 'Academic Affairs');
        $position = 'Examination Manager';
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } elseif (empty($username)) {
            $error = "Username is required.";
        } elseif (empty($password)) {
            $error = "Password is required.";
        } else {
            // Check duplicates
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Username '$username' already exists.";
            } else {
                $check2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check2->bind_param("s", $email);
                $check2->execute();
                if ($check2->get_result()->num_rows > 0) {
                    $error = "Email '$email' already exists in user accounts.";
                } else {
                    $check3 = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ?");
                    $check3->bind_param("s", $email);
                    $check3->execute();
                    if ($check3->get_result()->num_rows > 0) {
                        $error = "Email '$email' already exists in examination managers.";
                    } else {
                        $conn->begin_transaction();
                        try {
                            $insert = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position) VALUES (?, ?, ?, ?, ?)");
                            $insert->bind_param("sssss", $full_name, $email, $phone, $department, $position);
                            $insert->execute();
                            $manager_id = $conn->insert_id;

                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $user_insert = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'examination_manager', ?)");
                            $user_insert->bind_param("sssi", $username, $email, $hashed, $manager_id);
                            $user_insert->execute();

                            $conn->commit();
                            $success = "Examination Manager '$full_name' added successfully.";

                            // Send welcome email
                            if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled()) {
                                $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php' : '/vle-eumw/login.php';
                                $subject = "Welcome to VLE - Examination Manager Account";
                                $message = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                                    <div style='background:linear-gradient(135deg,#7c3aed,#6d28d9);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                        <h2 style='margin:0;'>\u2705 Welcome to VLE</h2>
                                        <p style='margin:8px 0 0;opacity:0.9;'>Examination Manager Account</p>
                                    </div>
                                    <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                                        <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                        <p>Your <strong>Examination Manager</strong> account has been created successfully.</p>
                                        <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;margin:16px 0;'>
                                            <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                            <p style='margin:4px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                            <p style='margin:4px 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                                        </div>
                                        <p style='color:#dc2626;font-size:0.9em;'>\u26a0\ufe0f Please change your password after first login for security purposes.</p>
                                        <div style='text-align:center;margin:20px 0;'>
                                            <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;'>\ud83d\udd11 Login to Your Portal</a>
                                        </div>
                                        <p style='font-size:0.85em;color:#64748b;text-align:center;'>Or copy this link: <a href='" . htmlspecialchars($login_url) . "' style='color:#7c3aed;'>" . htmlspecialchars($login_url) . "</a></p>
                                    </div>
                                </body></html>";
                                sendEmail($email, $full_name, $subject, $message);
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Failed to add manager: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $manager_id = (int)$_POST['manager_id'];
        $current_status = (int)$_POST['current_status'];
        $new_status = $current_status ? 0 : 1;
        $update = $conn->prepare("UPDATE examination_managers SET is_active = ? WHERE manager_id = ? AND position = 'Examination Manager'");
        $update->bind_param("ii", $new_status, $manager_id);
        if ($update->execute() && $update->affected_rows > 0) {
            $success = "Manager has been " . ($new_status ? 'activated' : 'deactivated') . ".";
        } else {
            $error = "Failed to update status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $manager_id = (int)$_POST['manager_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $info_stmt = $conn->prepare("SELECT full_name, email FROM examination_managers WHERE manager_id = ?");
            $info_stmt->bind_param("i", $manager_id);
            $info_stmt->execute();
            $info = $info_stmt->get_result()->fetch_assoc();
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_staff_id = ? AND role = 'examination_manager'");
            $upd->bind_param("si", $hashed, $manager_id);
            if ($upd->execute() && $upd->affected_rows > 0) {
                if ($info && function_exists('isEmailEnabled') && isEmailEnabled() && function_exists('sendPasswordResetEmail')) {
                    sendPasswordResetEmail($info['email'], $info['full_name'], $new_password, true);
                }
                $success = "Password reset for '" . htmlspecialchars($info['full_name'] ?? 'Manager') . "'.";
            } else {
                $error = "Failed to reset password. No matching user account found.";
            }
        }
    } elseif (isset($_POST['update_manager'])) {
        $manager_id = (int)$_POST['manager_id'];
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } else {
            $check = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ? AND manager_id != ?");
            $check->bind_param("si", $email, $manager_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Email already used by another record.";
            } else {
                $upd = $conn->prepare("UPDATE examination_managers SET full_name = ?, email = ?, phone = ?, department = ? WHERE manager_id = ? AND position = 'Examination Manager'");
                $upd->bind_param("ssssi", $full_name, $email, $phone, $department, $manager_id);
                if ($upd->execute()) {
                    // Also update email in users table
                    $conn->prepare("UPDATE users SET email = ? WHERE related_staff_id = ? AND role = 'examination_manager'")->execute([$email, $manager_id]) ?? null;
                    $success = "Manager details updated.";
                } else {
                    $error = "Failed to update manager.";
                }
            }
        }
    } elseif (isset($_POST['delete_manager'])) {
        $manager_id = (int)$_POST['manager_id'];
        $conn->begin_transaction();
        try {
            $info = $conn->prepare("SELECT full_name FROM examination_managers WHERE manager_id = ?");
            $info->bind_param("i", $manager_id);
            $info->execute();
            $name = $info->get_result()->fetch_assoc()['full_name'] ?? 'Unknown';

            $conn->prepare("DELETE FROM users WHERE related_staff_id = ? AND role = 'examination_manager'")->execute([$manager_id]) ?? null;
            $del = $conn->prepare("DELETE FROM examination_managers WHERE manager_id = ? AND position = 'Examination Manager'");
            $del->bind_param("i", $manager_id);
            $del->execute();

            $conn->commit();
            $success = "Manager '$name' permanently deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to delete: " . $e->getMessage();
        }
    }
}

// Get managers only (position = 'Examination Manager')
$query = "
    SELECT em.*, u.username, u.user_id,
           (SELECT COUNT(*) FROM exams WHERE created_by = u.user_id) as exams_created,
           (SELECT COUNT(*) FROM exam_sessions WHERE exam_id IN (SELECT exam_id FROM exams WHERE created_by = u.user_id)) as sessions_count
    FROM examination_managers em
    LEFT JOIN users u ON em.manager_id = u.related_staff_id AND u.role = 'examination_manager'
    WHERE em.position = 'Examination Manager'
    ORDER BY em.full_name
";
$result = $conn->query($query);
$managers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Stats
$total = count($managers);
$active = count(array_filter($managers, fn($m) => ($m['is_active'] ?? 0) == 1));
$inactive = $total - $active;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Examination Managers - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $page_title = "Manage Examination Managers";
    $breadcrumbs = [['title' => 'Examination Managers']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-shield-lock me-2" style="color:#7c3aed;"></i>Examination Managers</h2>
                <p class="text-muted mb-0">Senior staff who oversee the examination system, security, and reporting</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addManagerModal">
                <i class="bi bi-person-plus me-1"></i>Add Manager
            </button>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold" style="color:#7c3aed;"><?= $total ?></div>
                        <small class="text-muted">Total Managers</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-success"><?= $active ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-warning"><?= $inactive ?></div>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manager Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Manager Directory</h5>
                <span class="badge bg-light text-dark"><?= $total ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if ($total > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Department</th>
                                <th>Username</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($managers as $m): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($m['full_name']) ?></strong>
                                    <br><small class="text-muted"><i class="bi bi-shield-lock me-1"></i>Examination Manager</small>
                                </td>
                                <td>
                                    <small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($m['email']) ?></small>
                                    <?php if (!empty($m['phone'])): ?>
                                    <br><small><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($m['phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= htmlspecialchars($m['department'] ?? 'N/A') ?></small></td>
                                <td><code><?= htmlspecialchars($m['username'] ?? 'N/A') ?></code></td>
                                <td>
                                    <span class="badge bg-primary me-1"><?= $m['exams_created'] ?? 0 ?> exams</span>
                                    <span class="badge bg-info"><?= $m['sessions_count'] ?? 0 ?> sessions</span>
                                </td>
                                <td>
                                    <?php if ($m['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= date('M d, Y', strtotime($m['created_at'])) ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editManager(<?= $m['manager_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-outline-warning" onclick="openResetPassword(<?= $m['manager_id'] ?>, '<?= htmlspecialchars(addslashes($m['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($m['username'] ?? ''), ENT_QUOTES) ?>')" title="Reset Password"><i class="bi bi-key"></i></button>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="manager_id" value="<?= $m['manager_id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $m['is_active'] ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-outline-<?= $m['is_active'] ? 'warning' : 'success' ?>" onclick="return confirm('<?= $m['is_active'] ? 'Deactivate' : 'Activate' ?> this manager?')" title="<?= $m['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $m['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="manager_id" value="<?= $m['manager_id'] ?>">
                                            <button type="submit" name="delete_manager" class="btn btn-outline-danger" onclick="return confirm('PERMANENTLY DELETE this manager? This cannot be undone.')" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                    <p>No examination managers yet.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addManagerModal"><i class="bi bi-person-plus me-1"></i>Add First Manager</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Role Responsibilities Info -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Examination Manager Responsibilities</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6 class="text-primary"><i class="bi bi-shield-check me-1"></i>Oversight</h6>
                        <ul class="small text-muted">
                            <li>Oversee entire examination system</li>
                            <li>Approve exam schedules</li>
                            <li>Manage examination policies</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-success"><i class="bi bi-lock me-1"></i>Security</h6>
                        <ul class="small text-muted">
                            <li>Security monitoring</li>
                            <li>Access token management</li>
                            <li>Integrity enforcement</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-info"><i class="bi bi-bar-chart me-1"></i>Reporting</h6>
                        <ul class="small text-muted">
                            <li>Semester result reports</li>
                            <li>Performance analytics</li>
                            <li>Publish official results</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Manager Modal -->
    <div class="modal fade" id="addManagerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Examination Manager</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" name="department" value="Academic Affairs">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required minlength="8">
                                <small class="form-text text-muted">Minimum 8 characters</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_manager" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Add Manager</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Manager Modal -->
    <div class="modal fade" id="editManagerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Examination Manager</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="manager_id" id="edit_manager_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="edit_middle_name" name="middle_name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="edit_email" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="edit_phone" name="phone">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" id="edit_department" name="department">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_manager" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="resetPasswordForm">
                    <input type="hidden" name="manager_id" id="reset_manager_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>Resetting password for: <strong id="reset_name"></strong>
                            <br><small>Username: <code id="reset_username"></code></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="reset_new_pw" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePw('reset_new_pw',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="reset_confirm_pw" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePw('reset_confirm_pw',this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="genPw()"><i class="bi bi-shuffle me-1"></i>Generate Random</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function editManager(id) {
        fetch('get_examination_officer.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const o = data.officer;
                    document.getElementById('edit_manager_id').value = o.manager_id;
                    const parts = (o.full_name || '').trim().split(/\s+/);
                    document.getElementById('edit_first_name').value = parts[0] || '';
                    document.getElementById('edit_last_name').value = parts.length > 1 ? parts[parts.length - 1] : '';
                    document.getElementById('edit_middle_name').value = parts.length > 2 ? parts.slice(1, -1).join(' ') : '';
                    document.getElementById('edit_email').value = o.email;
                    document.getElementById('edit_phone').value = o.phone || '';
                    document.getElementById('edit_department').value = o.department || '';
                    new bootstrap.Modal(document.getElementById('editManagerModal')).show();
                } else { alert('Failed to load manager details'); }
            }).catch(() => alert('Failed to load manager details'));
    }
    function openResetPassword(id, name, username) {
        document.getElementById('reset_manager_id').value = id;
        document.getElementById('reset_name').textContent = name;
        document.getElementById('reset_username').textContent = username || 'N/A';
        document.getElementById('reset_new_pw').value = '';
        document.getElementById('reset_confirm_pw').value = '';
        new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
    }
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.querySelector('i').className = 'bi bi-eye' + (inp.type === 'text' ? '-slash' : '');
    }
    function genPw() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
        let pw = '';
        for (let i = 0; i < 12; i++) pw += chars[Math.floor(Math.random() * chars.length)];
        document.getElementById('reset_new_pw').value = pw;
        document.getElementById('reset_confirm_pw').value = pw;
        document.getElementById('reset_new_pw').type = 'text';
        document.getElementById('reset_confirm_pw').type = 'text';
    }
    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        if (document.getElementById('reset_new_pw').value !== document.getElementById('reset_confirm_pw').value) {
            e.preventDefault();
            alert('Passwords do not match!');
        }
    });
    </script>
</body>
</html>
