<?php
/**
 * Manage Heads of Department (HOD) - Admin Panel
 * CRUD operations for HOD users linked to administrative_staff table
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$success = '';
$error = '';

// Get departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_hod'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } elseif (empty($username)) {
            $error = "Username is required.";
        } elseif (empty($password)) {
            $error = "Password is required.";
        } elseif (empty($department)) {
            $error = "Department is required for HOD.";
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
                    $check3 = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ?");
                    $check3->bind_param("s", $email);
                    $check3->execute();
                    if ($check3->get_result()->num_rows > 0) {
                        $error = "Email '$email' already exists in administrative staff.";
                    } else {
                        $conn->begin_transaction();
                        try {
                            // Insert into administrative_staff
                            $position = 'Head of Department';
                            $insert = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                            $insert->bind_param("sssss", $full_name, $email, $phone, $department, $position);
                            $insert->execute();
                            $staff_id = $conn->insert_id;

                            // Insert into users with role='hod' and related_staff_id
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $user_insert = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'hod', ?)");
                            $user_insert->bind_param("sssi", $username, $email, $hashed, $staff_id);
                            $user_insert->execute();

                            $conn->commit();
                            $success = "Head of Department '$full_name' added successfully for $department.";

                            // Send welcome email
                            if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled()) {
                                $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php' : '/vle-eumw/login.php';
                                $subject = "Welcome to VLE - Head of Department Account";
                                $message = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                                    <div style='background:linear-gradient(135deg,#059669,#047857);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                        <h2 style='margin:0;'>\u2705 Welcome to VLE</h2>
                                        <p style='margin:8px 0 0;opacity:0.9;'>Head of Department Account</p>
                                    </div>
                                    <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                                        <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                        <p>Your <strong>Head of Department</strong> account has been created for the <strong>" . htmlspecialchars($department) . "</strong> department.</p>
                                        <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;margin:16px 0;'>
                                            <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                            <p style='margin:4px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                            <p style='margin:4px 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                                        </div>
                                        <p>As HOD, you can manage department courses, lecturers, students, and course allocations.</p>
                                        <p style='color:#dc2626;font-size:0.9em;'>\u26a0\ufe0f Please change your password after first login.</p>
                                        <div style='text-align:center;margin:20px 0;'>
                                            <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#059669,#047857);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;'>\ud83d\udd11 Login to HOD Portal</a>
                                        </div>
                                        <p style='font-size:0.85em;color:#64748b;text-align:center;'>Or copy this link: <a href='" . htmlspecialchars($login_url) . "' style='color:#059669;'>" . htmlspecialchars($login_url) . "</a></p>
                                    </div>
                                </body></html>";
                                try {
                                    sendEmail($email, $full_name, $subject, $message);
                                } catch (Exception $e) {
                                    // Email failure shouldn't block success
                                }
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Failed to add HOD: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $staff_id = (int)$_POST['staff_id'];
        $current_status = (int)$_POST['current_status'];
        $new_status = $current_status ? 0 : 1;
        $update = $conn->prepare("UPDATE administrative_staff SET is_active = ? WHERE staff_id = ? AND position = 'Head of Department'");
        $update->bind_param("ii", $new_status, $staff_id);
        if ($update->execute() && $update->affected_rows > 0) {
            $success = "HOD has been " . ($new_status ? 'activated' : 'deactivated') . ".";
        } else {
            $error = "Failed to update status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $staff_id = (int)$_POST['staff_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $info_stmt = $conn->prepare("SELECT full_name, email FROM administrative_staff WHERE staff_id = ?");
            $info_stmt->bind_param("i", $staff_id);
            $info_stmt->execute();
            $info = $info_stmt->get_result()->fetch_assoc();
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_staff_id = ? AND role = 'hod'");
            $upd->bind_param("si", $hashed, $staff_id);
            if ($upd->execute() && $upd->affected_rows > 0) {
                if ($info && function_exists('isEmailEnabled') && isEmailEnabled() && function_exists('sendPasswordResetEmail')) {
                    sendPasswordResetEmail($info['email'], $info['full_name'], $new_password, true);
                }
                $success = "Password reset for '" . htmlspecialchars($info['full_name'] ?? 'HOD') . "'.";
            } else {
                $error = "Failed to reset password. No matching user account found.";
            }
        }
    } elseif (isset($_POST['update_hod'])) {
        $staff_id = (int)$_POST['staff_id'];
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } elseif (empty($username)) {
            $error = "Username is required.";
        } elseif (empty($department)) {
            $error = "Department is required for HOD.";
        } else {
            $check = $conn->prepare("SELECT staff_id FROM administrative_staff WHERE email = ? AND staff_id != ?");
            $check->bind_param("si", $email, $staff_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Email already used by another staff record.";
            } else {
                // Check username uniqueness
                $check_user = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND NOT (related_staff_id = ? AND role = 'hod')");
                $check_user->bind_param("si", $username, $staff_id);
                $check_user->execute();
                if ($check_user->get_result()->num_rows > 0) {
                    $error = "Username '$username' is already taken by another user.";
                } else {
                    $upd = $conn->prepare("UPDATE administrative_staff SET full_name = ?, email = ?, phone = ?, department = ? WHERE staff_id = ? AND position = 'Head of Department'");
                    $upd->bind_param("ssssi", $full_name, $email, $phone, $department, $staff_id);
                    if ($upd->execute()) {
                        // Also update email and username in users table
                        $user_upd = $conn->prepare("UPDATE users SET email = ?, username = ? WHERE related_staff_id = ? AND role = 'hod'");
                        if ($user_upd) {
                            $user_upd->bind_param("ssi", $email, $username, $staff_id);
                            $user_upd->execute();
                        }
                        $success = "HOD details updated successfully.";
                    } else {
                        $error = "Failed to update HOD details.";
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_hod'])) {
        $staff_id = (int)$_POST['staff_id'];
        $conn->begin_transaction();
        try {
            $info = $conn->prepare("SELECT full_name FROM administrative_staff WHERE staff_id = ?");
            $info->bind_param("i", $staff_id);
            $info->execute();
            $name = $info->get_result()->fetch_assoc()['full_name'] ?? 'Unknown';

            // Delete user account first
            $del_user = $conn->prepare("DELETE FROM users WHERE related_staff_id = ? AND role = 'hod'");
            $del_user->bind_param("i", $staff_id);
            $del_user->execute();

            // Delete administrative_staff record
            $del = $conn->prepare("DELETE FROM administrative_staff WHERE staff_id = ? AND position = 'Head of Department'");
            $del->bind_param("i", $staff_id);
            $del->execute();

            $conn->commit();
            $success = "HOD '$name' permanently deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to delete: " . $e->getMessage();
        }
    }
}

// Get HODs (position = 'Head of Department')
$query = "
    SELECT s.*, u.username, u.user_id
    FROM administrative_staff s
    LEFT JOIN users u ON s.staff_id = u.related_staff_id AND u.role = 'hod'
    WHERE s.position = 'Head of Department'
    ORDER BY s.department, s.full_name
";
$result = $conn->query($query);
$hods = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Stats
$total = count($hods);
$active = count(array_filter($hods, fn($h) => ($h['is_active'] ?? 0) == 1));
$inactive = $total - $active;
$dept_count = count(array_unique(array_filter(array_column($hods, 'department'))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Heads of Department - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $page_title = "Manage Heads of Department";
    $breadcrumbs = [['title' => 'Heads of Department']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-building me-2" style="color:#0d9488;"></i>Heads of Department</h2>
                <p class="text-muted mb-0">Manage department heads who oversee courses, lecturers, students, and allocations</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHodModal" style="background: linear-gradient(135deg, #0d9488, #0f766e); border: none;">
                <i class="bi bi-person-plus me-1"></i>Add HOD
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
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold" style="color:#0d9488;"><?= $total ?></div>
                        <small class="text-muted">Total HODs</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-success"><?= $active ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-warning"><?= $inactive ?></div>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-info"><?= $dept_count ?></div>
                        <small class="text-muted">Departments Covered</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- HOD Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0d9488, #0f766e);">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>HOD Directory</h5>
                <span class="badge bg-light text-dark"><?= $total ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if ($total > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Contact</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hods as $h): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;font-weight:600;font-size:14px;">
                                            <?= strtoupper(substr($h['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($h['full_name']) ?></strong>
                                            <br><small class="text-muted"><i class="bi bi-building me-1"></i>Head of Department</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                                        <?= htmlspecialchars($h['department'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($h['email']) ?></small>
                                    <?php if (!empty($h['phone'])): ?>
                                    <br><small><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($h['phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($h['username'] ?? 'N/A') ?></code></td>
                                <td>
                                    <?php if ($h['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= !empty($h['hire_date']) ? date('M d, Y', strtotime($h['hire_date'])) : 'N/A' ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editHod(<?= $h['staff_id'] ?>, '<?= htmlspecialchars(addslashes($h['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($h['email']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($h['phone'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($h['department'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($h['username'] ?? ''), ENT_QUOTES) ?>')" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-outline-warning" onclick="openResetPassword(<?= $h['staff_id'] ?>, '<?= htmlspecialchars(addslashes($h['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($h['username'] ?? ''), ENT_QUOTES) ?>')" title="Reset Password"><i class="bi bi-key"></i></button>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?= $h['staff_id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $h['is_active'] ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-outline-<?= $h['is_active'] ? 'warning' : 'success' ?>" onclick="return confirm('<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?> this HOD?')" title="<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $h['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?= $h['staff_id'] ?>">
                                            <button type="submit" name="delete_hod" class="btn btn-outline-danger" onclick="return confirm('PERMANENTLY DELETE this HOD? This will remove their account and staff record. This cannot be undone.')" title="Delete"><i class="bi bi-trash"></i></button>
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
                    <i class="bi bi-building display-4 d-block mb-3"></i>
                    <p>No Heads of Department added yet.</p>
                    <button class="btn text-white" data-bs-toggle="modal" data-bs-target="#addHodModal" style="background: linear-gradient(135deg, #0d9488, #0f766e);"><i class="bi bi-person-plus me-1"></i>Add First HOD</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HOD Responsibilities Info -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Head of Department Responsibilities</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <h6 style="color:#0d9488;"><i class="bi bi-book me-1"></i>Courses</h6>
                        <ul class="small text-muted">
                            <li>View department courses</li>
                            <li>Monitor course content</li>
                            <li>Track enrollment numbers</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-success"><i class="bi bi-person-badge me-1"></i>Lecturers</h6>
                        <ul class="small text-muted">
                            <li>View department lecturers</li>
                            <li>Monitor teaching activity</li>
                            <li>Track workload distribution</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-primary"><i class="bi bi-diagram-3 me-1"></i>Allocations</h6>
                        <ul class="small text-muted">
                            <li>Assign lecturers to courses</li>
                            <li>Manage course allocations</li>
                            <li>Balance workloads</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-info"><i class="bi bi-bar-chart me-1"></i>Reports</h6>
                        <ul class="small text-muted">
                            <li>Department analytics</li>
                            <li>Enrollment reports</li>
                            <li>Lecturer performance</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add HOD Modal -->
    <div class="modal fade" id="addHodModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #0d9488, #0f766e);">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Head of Department</h5>
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
                                <label class="form-label">Department <span class="text-danger">*</span></label>
                                <select class="form-select" name="department" required>
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['department_name']) ?>"><?= htmlspecialchars($dept['department_name']) ?> (<?= htmlspecialchars($dept['department_code']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="add_password" required minlength="8">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePw('add_password',this)"><i class="bi bi-eye"></i></button>
                                </div>
                                <small class="form-text text-muted">Minimum 8 characters</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_hod" class="btn text-white" style="background: linear-gradient(135deg, #0d9488, #0f766e);"><i class="bi bi-check-circle me-1"></i>Add HOD</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit HOD Modal -->
    <div class="modal fade" id="editHodModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #0d9488, #0f766e);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Head of Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="staff_id" id="edit_staff_id">
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
                            <div class="col-md-6">
                                <label class="form-label">Department <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_department" name="department" required>
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['department_name']) ?>"><?= htmlspecialchars($dept['department_name']) ?> (<?= htmlspecialchars($dept['department_code']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_username" name="username" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_hod" class="btn text-white" style="background: linear-gradient(135deg, #0d9488, #0f766e);"><i class="bi bi-save me-1"></i>Update</button>
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
                    <input type="hidden" name="staff_id" id="reset_staff_id">
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
    function editHod(id, fullName, email, phone, department, username) {
        document.getElementById('edit_staff_id').value = id;
        const parts = (fullName || '').trim().split(/\s+/);
        document.getElementById('edit_first_name').value = parts[0] || '';
        document.getElementById('edit_last_name').value = parts.length > 1 ? parts[parts.length - 1] : '';
        document.getElementById('edit_middle_name').value = parts.length > 2 ? parts.slice(1, -1).join(' ') : '';
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_phone').value = phone || '';
        document.getElementById('edit_username').value = username || '';
        // Set department dropdown
        const deptSelect = document.getElementById('edit_department');
        for (let i = 0; i < deptSelect.options.length; i++) {
            if (deptSelect.options[i].value === department) {
                deptSelect.selectedIndex = i;
                break;
            }
        }
        new bootstrap.Modal(document.getElementById('editHodModal')).show();
    }

    function openResetPassword(id, name, username) {
        document.getElementById('reset_staff_id').value = id;
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
