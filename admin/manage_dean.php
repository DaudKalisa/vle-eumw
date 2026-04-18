<?php
/**
 * Manage Deans - Admin Panel
 * CRUD operations for Dean users linked to administrative_staff table
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$success = '';
$error = '';

// Get faculties for dropdown
$faculties = [];
$fac_result = $conn->query("SELECT faculty_id, faculty_name, faculty_code FROM faculties ORDER BY faculty_name");
if ($fac_result) {
    while ($row = $fac_result->fetch_assoc()) {
        $faculties[] = $row;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_dean'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $faculty = trim($_POST['faculty'] ?? '');
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
        } elseif (empty($faculty)) {
            $error = "Faculty is required for Dean.";
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
                            $position = 'Dean';
                            $insert = $conn->prepare("INSERT INTO administrative_staff (full_name, email, phone, department, position, hire_date, is_active) VALUES (?, ?, ?, ?, ?, CURDATE(), 1)");
                            $insert->bind_param("sssss", $full_name, $email, $phone, $faculty, $position);
                            $insert->execute();
                            $staff_id = $conn->insert_id;

                            // Insert into users with role='dean' and related_staff_id
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $user_insert = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'dean', ?)");
                            $user_insert->bind_param("sssi", $username, $email, $hashed, $staff_id);
                            $user_insert->execute();

                            $conn->commit();
                            $success = "Dean '$full_name' added successfully for $faculty.";

                            // Send welcome email
                            if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled()) {
                                $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php' : '/vle-eumw/login.php';
                                $subject = "Welcome to VLE - Dean Account";
                                $message = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                                    <div style='background:linear-gradient(135deg,#7c2d12,#9a3412);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                        <h2 style='margin:0;'>&#x2705; Welcome to VLE</h2>
                                        <p style='margin:8px 0 0;opacity:0.9;'>Dean Account</p>
                                    </div>
                                    <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                                        <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                        <p>Your <strong>Dean</strong> account has been created for the <strong>" . htmlspecialchars($faculty) . "</strong> faculty.</p>
                                        <div style='background:#fff7ed;border:1px solid #fed7aa;padding:16px;border-radius:8px;margin:16px 0;'>
                                            <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                            <p style='margin:4px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                            <p style='margin:4px 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                                        </div>
                                        <p><a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#7c2d12,#9a3412);color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;'>Login Now</a></p>
                                        <p style='color:#64748b;font-size:13px;'>Please change your password after first login.</p>
                                    </div>
                                </body></html>";
                                sendEmail($email, $subject, $message);
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Error adding Dean: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    // Toggle active status
    if (isset($_POST['toggle_status'])) {
        $staff_id = intval($_POST['staff_id']);
        $current = intval($_POST['current_status']);
        $new_status = $current ? 0 : 1;
        $stmt = $conn->prepare("UPDATE administrative_staff SET is_active = ? WHERE staff_id = ? AND position = 'Dean'");
        $stmt->bind_param("ii", $new_status, $staff_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = $new_status ? "Dean activated." : "Dean deactivated.";
        } else {
            $error = "Failed to update status.";
        }
    }

    // Reset password
    if (isset($_POST['reset_password'])) {
        $staff_id = intval($_POST['staff_id']);
        $new_pw = $_POST['new_password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';
        if (empty($new_pw) || strlen($new_pw) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($new_pw !== $confirm_pw) {
            $error = "Passwords do not match.";
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE related_staff_id = ? AND role = 'dean'");
            $stmt->bind_param("si", $hashed, $staff_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Password reset successfully.";
            } else {
                $error = "Failed to reset password. User account may not exist.";
            }
        }
    }

    // Update dean
    if (isset($_POST['update_dean'])) {
        $staff_id = intval($_POST['staff_id']);
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $faculty = trim($_POST['faculty'] ?? '');

        if (empty($first_name) || empty($last_name) || empty($email) || empty($faculty)) {
            $error = "Name, email, and faculty are required.";
        } else {
            $stmt = $conn->prepare("UPDATE administrative_staff SET full_name=?, email=?, phone=?, department=? WHERE staff_id=? AND position='Dean'");
            $stmt->bind_param("ssssi", $full_name, $email, $phone, $faculty, $staff_id);
            if ($stmt->execute()) {
                // Also sync email to users table
                $sync = $conn->prepare("UPDATE users SET email=? WHERE related_staff_id=? AND role='dean'");
                $sync->bind_param("si", $email, $staff_id);
                $sync->execute();
                $success = "Dean updated successfully.";
            } else {
                $error = "Failed to update dean.";
            }
        }
    }

    // Delete dean
    if (isset($_POST['delete_dean'])) {
        $staff_id = intval($_POST['staff_id']);
        $conn->begin_transaction();
        try {
            $del_user = $conn->prepare("DELETE FROM users WHERE related_staff_id = ? AND role = 'dean'");
            $del_user->bind_param("i", $staff_id);
            $del_user->execute();

            $del_staff = $conn->prepare("DELETE FROM administrative_staff WHERE staff_id = ? AND position = 'Dean'");
            $del_staff->bind_param("i", $staff_id);
            $del_staff->execute();

            $conn->commit();
            $success = "Dean deleted successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error deleting dean: " . $e->getMessage();
        }
    }
}

// Fetch all deans
$deans = [];
$result = $conn->query("SELECT s.*, u.username, u.user_id FROM administrative_staff s LEFT JOIN users u ON s.staff_id = u.related_staff_id AND u.role = 'dean' WHERE s.position = 'Dean' ORDER BY s.department, s.full_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $deans[] = $row;
    }
}

// Stats
$total = count($deans);
$active = 0;
$inactive = 0;
$faculty_count = 0;
$faculty_set = [];
foreach ($deans as $d) {
    if ($d['is_active']) $active++;
    else $inactive++;
    if (!empty($d['department']) && !in_array($d['department'], $faculty_set)) {
        $faculty_set[] = $d['department'];
        $faculty_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Deans - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $page_title = "Manage Deans";
    $breadcrumbs = [['title' => 'Deans']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-award me-2" style="color:#9a3412;"></i>Deans</h2>
                <p class="text-muted mb-0">Manage faculty deans who oversee academic departments and programs</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeanModal" style="background: linear-gradient(135deg, #9a3412, #7c2d12); border: none;">
                <i class="bi bi-person-plus me-1"></i>Add Dean
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
                        <div class="display-5 fw-bold" style="color:#9a3412;"><?= $total ?></div>
                        <small class="text-muted">Total Deans</small>
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
                        <div class="display-5 fw-bold text-info"><?= $faculty_count ?></div>
                        <small class="text-muted">Faculties Covered</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dean Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #9a3412, #7c2d12);">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Dean Directory</h5>
                <span class="badge bg-light text-dark"><?= $total ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if ($total > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Faculty</th>
                                <th>Contact</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deans as $d): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:linear-gradient(135deg,#9a3412,#7c2d12);color:#fff;font-weight:600;font-size:14px;">
                                            <?= strtoupper(substr($d['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($d['full_name']) ?></strong>
                                            <br><small class="text-muted"><i class="bi bi-award me-1"></i>Dean</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background:linear-gradient(135deg,#9a3412,#7c2d12);">
                                        <?= htmlspecialchars($d['department'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($d['email']) ?></small>
                                    <?php if (!empty($d['phone'])): ?>
                                    <br><small><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($d['phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($d['username'] ?? 'N/A') ?></code></td>
                                <td>
                                    <?php if ($d['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= !empty($d['hire_date']) ? date('M d, Y', strtotime($d['hire_date'])) : 'N/A' ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editDean(<?= $d['staff_id'] ?>, '<?= htmlspecialchars(addslashes($d['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($d['email']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($d['phone'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($d['department'] ?? ''), ENT_QUOTES) ?>')" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-outline-warning" onclick="openResetPassword(<?= $d['staff_id'] ?>, '<?= htmlspecialchars(addslashes($d['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($d['username'] ?? ''), ENT_QUOTES) ?>')" title="Reset Password"><i class="bi bi-key"></i></button>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?= $d['staff_id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $d['is_active'] ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-outline-<?= $d['is_active'] ? 'warning' : 'success' ?>" onclick="return confirm('<?= $d['is_active'] ? 'Deactivate' : 'Activate' ?> this Dean?')" title="<?= $d['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $d['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="staff_id" value="<?= $d['staff_id'] ?>">
                                            <button type="submit" name="delete_dean" class="btn btn-outline-danger" onclick="return confirm('PERMANENTLY DELETE this Dean? This will remove their account and staff record. This cannot be undone.')" title="Delete"><i class="bi bi-trash"></i></button>
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
                    <i class="bi bi-award display-4 d-block mb-3"></i>
                    <p>No Deans added yet.</p>
                    <button class="btn text-white" data-bs-toggle="modal" data-bs-target="#addDeanModal" style="background: linear-gradient(135deg, #9a3412, #7c2d12);"><i class="bi bi-person-plus me-1"></i>Add First Dean</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dean Responsibilities Info -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Dean Responsibilities</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <h6 style="color:#9a3412;"><i class="bi bi-building me-1"></i>Faculty Oversight</h6>
                        <ul class="small text-muted">
                            <li>Oversee faculty operations</li>
                            <li>Strategic planning</li>
                            <li>Quality assurance</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-success"><i class="bi bi-diagram-3 me-1"></i>Departments</h6>
                        <ul class="small text-muted">
                            <li>Supervise department heads</li>
                            <li>Coordinate across departments</li>
                            <li>Resource allocation</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-primary"><i class="bi bi-mortarboard me-1"></i>Academic Programs</h6>
                        <ul class="small text-muted">
                            <li>Approve new programs</li>
                            <li>Curriculum oversight</li>
                            <li>Accreditation management</li>
                        </ul>
                    </div>
                    <div class="col-md-3">
                        <h6 class="text-info"><i class="bi bi-bar-chart me-1"></i>Reports</h6>
                        <ul class="small text-muted">
                            <li>Faculty analytics</li>
                            <li>Performance reviews</li>
                            <li>Budget oversight</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Dean Modal -->
    <div class="modal fade" id="addDeanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #9a3412, #7c2d12);">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Dean</h5>
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
                                <label class="form-label">Faculty <span class="text-danger">*</span></label>
                                <select class="form-select" name="faculty" required>
                                    <option value="">-- Select Faculty --</option>
                                    <?php foreach ($faculties as $fac): ?>
                                    <option value="<?= htmlspecialchars($fac['faculty_name']) ?>"><?= htmlspecialchars($fac['faculty_name']) ?> (<?= htmlspecialchars($fac['faculty_code']) ?>)</option>
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
                        <button type="submit" name="add_dean" class="btn text-white" style="background: linear-gradient(135deg, #9a3412, #7c2d12);"><i class="bi bi-check-circle me-1"></i>Add Dean</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Dean Modal -->
    <div class="modal fade" id="editDeanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #9a3412, #7c2d12);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Dean</h5>
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
                            <div class="col-12">
                                <label class="form-label">Faculty <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_faculty" name="faculty" required>
                                    <option value="">-- Select Faculty --</option>
                                    <?php foreach ($faculties as $fac): ?>
                                    <option value="<?= htmlspecialchars($fac['faculty_name']) ?>"><?= htmlspecialchars($fac['faculty_name']) ?> (<?= htmlspecialchars($fac['faculty_code']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_dean" class="btn text-white" style="background: linear-gradient(135deg, #9a3412, #7c2d12);"><i class="bi bi-save me-1"></i>Update</button>
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
    function editDean(id, fullName, email, phone, faculty) {
        document.getElementById('edit_staff_id').value = id;
        const parts = (fullName || '').trim().split(/\s+/);
        document.getElementById('edit_first_name').value = parts[0] || '';
        document.getElementById('edit_last_name').value = parts.length > 1 ? parts[parts.length - 1] : '';
        document.getElementById('edit_middle_name').value = parts.length > 2 ? parts.slice(1, -1).join(' ') : '';
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_phone').value = phone || '';
        // Set faculty dropdown
        const facSelect = document.getElementById('edit_faculty');
        for (let i = 0; i < facSelect.options.length; i++) {
            if (facSelect.options[i].value === faculty) {
                facSelect.selectedIndex = i;
                break;
            }
        }
        new bootstrap.Modal(document.getElementById('editDeanModal')).show();
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
