<?php
// manage_examination_officers.php - Admin manage examination officers
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Ensure examination_officer role exists in ENUM
try {
    $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col_check && $row = $col_check->fetch_assoc()) {
        if (strpos($row['Type'], 'examination_officer') === false) {
            preg_match("/enum\((.*)\)/", $row['Type'], $matches);
            if (!empty($matches[1])) {
                $new_enum = str_replace(")", ",'examination_officer')", "enum(" . $matches[1] . ")");
                $conn->query("ALTER TABLE users MODIFY COLUMN role " . $new_enum . " DEFAULT 'student'");
            }
        }
    }
} catch (Exception $e) { /* silently continue */ }

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_officer'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? 'Academic Affairs');
        $position = 'Examination Officer';
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        // Validate required fields
        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } elseif (empty($username)) {
            $error = "Username is required.";
        } elseif (empty($password)) {
            $error = "Password is required.";
        } else {
            // Check if username already exists
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Username '$username' already exists. Please choose a different username.";
            } else {
                // Check if email already exists in users table
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = "Email '$email' already exists. Please use a different email.";
                } else {
                    // Check if email exists in examination_managers table
                    $check_stmt = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ?");
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        $error = "Email '$email' already exists for an examination officer.";
                    } else {
                        // Start transaction
                        $conn->begin_transaction();

                        try {
                            // Insert into examination_managers table
                            $insert_stmt = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position) VALUES (?, ?, ?, ?, ?)");
                            $insert_stmt->bind_param("sssss", $full_name, $email, $phone, $department, $position);
                            $insert_stmt->execute();
                            $manager_id = $conn->insert_id;

                            // Create user account
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, 'examination_officer', ?)");
                            $user_stmt->bind_param("sssi", $username, $email, $hashed_password, $manager_id);
                            $user_stmt->execute();

                            $conn->commit();
                            $success = "Examination officer '$full_name' has been added successfully.";

                            // Send welcome email
                            $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php' : '/vle-eumw/login.php';
                            $subject = "Welcome to VLE - Examination Officer Account";
                            $message = "
                            <html>
                            <body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                                <div style='background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                    <h2 style='margin:0;'>\u2705 Welcome to VLE</h2>
                                    <p style='margin:8px 0 0;opacity:0.9;'>Examination Officer Account</p>
                                </div>
                                <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                    <p>Your <strong>Examination Officer</strong> account has been created successfully.</p>
                                    <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;margin:16px 0;'>
                                        <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                        <p style='margin:4px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                        <p style='margin:4px 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                                    </div>
                                    <p style='color:#dc2626;font-size:0.9em;'>\u26a0\ufe0f Please change your password after first login for security purposes.</p>
                                    <div style='text-align:center;margin:20px 0;'>
                                        <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;'>\ud83d\udd11 Login to Your Portal</a>
                                    </div>
                                    <p style='font-size:0.85em;color:#64748b;text-align:center;'>Or copy this link: <a href='" . htmlspecialchars($login_url) . "' style='color:#2563eb;'>" . htmlspecialchars($login_url) . "</a></p>
                                </div>
                            </body>
                            </html>
                            ";

                            sendEmail($email, $full_name, $subject, $message);

                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Failed to add examination officer: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $manager_id = (int)$_POST['manager_id'];
        $current_status = (int)$_POST['current_status'];
        $new_status = $current_status ? 0 : 1;

        $update_stmt = $conn->prepare("UPDATE examination_managers SET is_active = ? WHERE manager_id = ?");
        $update_stmt->bind_param("ii", $new_status, $manager_id);

        if ($update_stmt->execute()) {
            $status_text = $new_status ? 'activated' : 'deactivated';
            $success = "Examination officer has been $status_text successfully.";
        } else {
            $error = "Failed to update examination officer status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $manager_id = (int)$_POST['manager_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } else {
            // Get officer info for email
            $officer_info_stmt = $conn->prepare("SELECT full_name, email FROM examination_managers WHERE manager_id = ?");
            $officer_info_stmt->bind_param("i", $manager_id);
            $officer_info_stmt->execute();
            $officer_info = $officer_info_stmt->get_result()->fetch_assoc();

            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update in users table (exam officers are linked via related_staff_id)
            $user_update = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_staff_id = ?");
            $user_update->bind_param("si", $hashed_password, $manager_id);

            if ($user_update->execute() && $user_update->affected_rows > 0) {
                // Send password reset notification email
                if ($officer_info && isEmailEnabled()) {
                    sendPasswordResetEmail($officer_info['email'], $officer_info['full_name'], $new_password, true);
                }
                $success = "Password for '" . htmlspecialchars($officer_info['full_name'] ?? 'Officer') . "' has been reset successfully!";
            } else {
                $error = "Failed to reset password. No matching user account found for this officer.";
            }
        }
    } elseif (isset($_POST['update_officer'])) {
        $manager_id = (int)$_POST['manager_id'];
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $position = trim($_POST['position'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } else {
            // Check if email is already used by another officer
            $check_stmt = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ? AND manager_id != ?");
            $check_stmt->bind_param("si", $email, $manager_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Email '$email' is already used by another examination officer.";
            } else {
                $update_stmt = $conn->prepare("UPDATE examination_managers SET full_name = ?, email = ?, phone = ?, department = ?, position = ? WHERE manager_id = ?");
                $update_stmt->bind_param("sssssi", $full_name, $email, $phone, $department, $position, $manager_id);

                if ($update_stmt->execute()) {
                    $success = "Examination officer details updated successfully.";
                } else {
                    $error = "Failed to update examination officer details.";
                }
            }
        }
    } elseif (isset($_POST['delete_officer'])) {
        $manager_id = (int)$_POST['manager_id'];
        
        $conn->begin_transaction();
        try {
            // Get officer info for logging
            $info_stmt = $conn->prepare("SELECT full_name, email FROM examination_managers WHERE manager_id = ?");
            $info_stmt->bind_param("i", $manager_id);
            $info_stmt->execute();
            $officer_info = $info_stmt->get_result()->fetch_assoc();
            
            // Delete from users table (linked via related_staff_id)
            $delete_user_stmt = $conn->prepare("DELETE FROM users WHERE related_staff_id = ? AND role IN ('examination_manager','examination_officer')");
            $delete_user_stmt->bind_param("i", $manager_id);
            $delete_user_stmt->execute();
            
            // Delete from examination_managers table
            $delete_manager_stmt = $conn->prepare("DELETE FROM examination_managers WHERE manager_id = ?");
            $delete_manager_stmt->bind_param("i", $manager_id);
            $delete_manager_stmt->execute();
            
            $conn->commit();
            $success = "Examination officer '" . ($officer_info['full_name'] ?? 'Unknown') . "' has been permanently deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to delete examination officer: " . $e->getMessage();
        }
    }
}

// Get examination officers only (position = 'Examination Officer' from examination_managers table)
$query = "
    SELECT em.*, u.username, u.user_id, u.additional_roles,
           (SELECT COUNT(*) FROM exams WHERE created_by = u.user_id) as exams_count,
           (SELECT COUNT(*) FROM exam_sessions WHERE exam_id IN (SELECT exam_id FROM exams WHERE created_by = u.user_id)) as sessions_count
    FROM examination_managers em
    LEFT JOIN users u ON em.manager_id = u.related_staff_id AND u.role IN ('examination_officer','examination_manager')
    WHERE em.position = 'Examination Officer'
    ORDER BY em.full_name
";

$result = $conn->query($query);
$officers = $result->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_query = "
    SELECT
        COUNT(*) as total_officers,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_officers,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_officers
    FROM examination_managers
    WHERE position = 'Examination Officer'
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Examination Officers - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $page_title = "Manage Examination Officers";
    $breadcrumbs = [['title' => 'Examination Officers']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-shield-check me-2"></i>Examination Officers</h2>
                <p class="text-muted mb-0">Manage examination officer accounts — officers handle day-to-day exam operations</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfficerModal">
                <i class="bi bi-person-plus me-1"></i>Add Officer
            </button>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-primary"><?= $stats['total_officers'] ?? 0 ?></div>
                        <small class="text-muted">Total Officers</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-success"><?= $stats['active_officers'] ?? 0 ?></div>
                        <small class="text-muted">Active Officers</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-warning"><?= $stats['inactive_officers'] ?? 0 ?></div>
                        <small class="text-muted">Inactive Officers</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Officers Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Officer Directory</h5>
                <span class="badge bg-light text-dark"><?= count($officers) ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if (count($officers) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Officer</th>
                                    <th>Contact</th>
                                    <th>Department</th>
                                    <th>Username</th>
                                    <th>Stats</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officers as $officer): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($officer['full_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($officer['position']) ?></small>
                                    </td>
                                    <td>
                                        <small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($officer['email']) ?></small>
                                        <?php if (!empty($officer['phone'])): ?>
                                            <br><small><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($officer['phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= htmlspecialchars($officer['department']) ?></small></td>
                                    <td><code><?= htmlspecialchars($officer['username'] ?? 'N/A') ?></code></td>
                                    <td>
                                        <span class="badge bg-primary me-1"><?= $officer['exams_count'] ?> exams</span>
                                        <span class="badge bg-info"><?= $officer['sessions_count'] ?> sessions</span>
                                    </td>
                                    <td>
                                        <?php if ($officer['is_active']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($officer['created_at'])) ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="editOfficer(<?= $officer['manager_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-warning" onclick="openResetPassword(<?= $officer['manager_id'] ?>, '<?= htmlspecialchars(addslashes($officer['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($officer['username'] ?? ''), ENT_QUOTES) ?>')" title="Reset Password">
                                                <i class="bi bi-key"></i>
                                            </button>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="manager_id" value="<?= $officer['manager_id'] ?>">
                                                <input type="hidden" name="current_status" value="<?= $officer['is_active'] ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-outline-<?= $officer['is_active'] ? 'warning' : 'success' ?>" 
                                                        onclick="return confirm('<?= $officer['is_active'] ? 'Deactivate' : 'Activate' ?> this officer?')"
                                                        title="<?= $officer['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi bi-<?= $officer['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                </button>
                                            </form>
                                            <?php if ($officer['manager_id']): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="manager_id" value="<?= $officer['manager_id'] ?>">
                                                <button type="submit" name="delete_officer" class="btn btn-outline-danger" 
                                                        onclick="return confirm('Are you sure you want to PERMANENTLY DELETE this examination officer? This action cannot be undone.');"
                                                        title="Delete Permanently">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-people display-4 d-block mb-3"></i>
                        <p>No examination officers yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfficerModal">
                            <i class="bi bi-person-plus me-1"></i>Add First Officer
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Officer Modal -->
    <div class="modal fade" id="addOfficerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Examination Officer</h5>
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
                        <button type="submit" name="add_officer" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Add Officer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Officer Modal -->
    <div class="modal fade" id="editOfficerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Examination Officer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="editOfficerForm">
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
                        <button type="submit" name="update_officer" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update Officer</button>
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
                            <i class="bi bi-info-circle me-2"></i>
                            Resetting password for: <strong id="reset_officer_name"></strong>
                            <br><small class="text-muted">Username: <code id="reset_officer_username"></code></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="reset_new_password" required minlength="6" placeholder="Minimum 6 characters">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('reset_new_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="reset_confirm_password" required minlength="6" placeholder="Re-enter password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('reset_confirm_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback" id="password_mismatch" style="display:none;">Passwords do not match!</div>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generatePassword()">
                                <i class="bi bi-shuffle me-1"></i>Generate Random Password
                            </button>
                        </div>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            The officer will be required to change their password on next login.
                            <?php if (isEmailEnabled()): ?>
                            A notification email will be sent with the new password.
                            <?php endif; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function openResetPassword(managerId, fullName, username) {
        document.getElementById('reset_manager_id').value = managerId;
        document.getElementById('reset_officer_name').textContent = fullName;
        document.getElementById('reset_officer_username').textContent = username || 'N/A';
        document.getElementById('reset_new_password').value = '';
        document.getElementById('reset_confirm_password').value = '';
        document.getElementById('password_mismatch').style.display = 'none';
        new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
    }

    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }

    function generatePassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('reset_new_password').value = password;
        document.getElementById('reset_confirm_password').value = password;
        // Show password so admin can copy it
        document.getElementById('reset_new_password').type = 'text';
        document.getElementById('reset_confirm_password').type = 'text';
    }

    // Validate password match on form submit
    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        const pw = document.getElementById('reset_new_password').value;
        const cpw = document.getElementById('reset_confirm_password').value;
        if (pw !== cpw) {
            e.preventDefault();
            document.getElementById('password_mismatch').style.display = 'block';
            document.getElementById('reset_confirm_password').classList.add('is-invalid');
            return false;
        }
    });

    function editOfficer(managerId) {
        fetch('get_examination_officer.php?id=' + managerId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('edit_manager_id').value = data.officer.manager_id;
                    // Split full_name into first, middle, last
                    const nameParts = (data.officer.full_name || '').trim().split(/\s+/);
                    const firstName = nameParts[0] || '';
                    const lastName = nameParts.length > 1 ? nameParts[nameParts.length - 1] : '';
                    const middleName = nameParts.length > 2 ? nameParts.slice(1, -1).join(' ') : '';
                    document.getElementById('edit_first_name').value = firstName;
                    document.getElementById('edit_middle_name').value = middleName;
                    document.getElementById('edit_last_name').value = lastName;
                    document.getElementById('edit_email').value = data.officer.email;
                    document.getElementById('edit_phone').value = data.officer.phone || '';
                    document.getElementById('edit_department').value = data.officer.department;
                    new bootstrap.Modal(document.getElementById('editOfficerModal')).show();
                } else {
                    alert('Failed to load officer details');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to load officer details');
            });
    }
    </script>
</body>
</html>
