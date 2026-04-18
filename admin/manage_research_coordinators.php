<?php
/**
 * Admin - Manage Research Coordinators
 * Add, edit, toggle, reset password, delete research coordinators
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$success = '';
$error = '';

// Ensure research_coordinator role exists in ENUM
try {
    $col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($col_check && $row = $col_check->fetch_assoc()) {
        if (strpos($row['Type'], 'research_coordinator') === false) {
            preg_match("/enum\((.*)\)/", $row['Type'], $matches);
            if (!empty($matches[1])) {
                $new_enum = str_replace(")", ",'research_coordinator')", "enum(" . $matches[1] . ")");
                $conn->query("ALTER TABLE users MODIFY COLUMN role " . $new_enum . " DEFAULT 'student'");
            }
        }
    }
} catch (Exception $e) { /* silently continue */ }

// Ensure research_coordinators table exists
$conn->query("CREATE TABLE IF NOT EXISTS research_coordinators (
    coordinator_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    department VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure dissertations table exists (needed for coordinator query)
$conn->query("CREATE TABLE IF NOT EXISTS dissertations (
    dissertation_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    coordinator_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    status VARCHAR(50) DEFAULT 'topic_submission',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_coordinator'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
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
            // Check username
            $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Username '$username' already exists.";
            } else {
                // Check email in users
                $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check->bind_param("s", $email);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error = "Email '$email' already exists in the system.";
                } else {
                    // Check email in research_coordinators
                    $check = $conn->prepare("SELECT coordinator_id FROM research_coordinators WHERE email = ?");
                    $check->bind_param("s", $email);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $error = "Email '$email' already exists for a research coordinator.";
                    } else {
                        $conn->begin_transaction();
                        try {
                            // Create user account first to get user_id
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'research_coordinator')");
                            $user_stmt->bind_param("sss", $username, $email, $hashed);
                            $user_stmt->execute();
                            $user_id = $conn->insert_id;

                            // Insert into research_coordinators
                            $rc_stmt = $conn->prepare("INSERT INTO research_coordinators (user_id, full_name, email, phone, department) VALUES (?, ?, ?, ?, ?)");
                            $rc_stmt->bind_param("issss", $user_id, $full_name, $email, $phone, $department);
                            $rc_stmt->execute();
                            $coordinator_id = $conn->insert_id;

                            // Link user to coordinator via related_staff_id
                            $link_stmt = $conn->prepare("UPDATE users SET related_staff_id = ? WHERE user_id = ?");
                            $link_stmt->bind_param("ii", $coordinator_id, $user_id);
                            $link_stmt->execute();

                            $conn->commit();
                            $success = "Research coordinator '$full_name' has been added successfully.";

                            // Send welcome email
                            $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php' : '/vle-eumw/login.php';
                            $subject = "Welcome to VLE - Research Coordinator Account";
                            $message = "
                            <html>
                            <body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                                <div style='background:linear-gradient(135deg,#7c3aed,#6d28d9);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                                    <h2 style='margin:0;'>✅ Welcome to VLE</h2>
                                    <p style='margin:8px 0 0;opacity:0.9;'>Research Coordinator Account</p>
                                </div>
                                <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                                    <p>Your <strong>Research Coordinator</strong> account has been created. You can now manage student dissertations, assign supervisors, review submissions, and run similarity checks.</p>
                                    <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;margin:16px 0;'>
                                        <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                        <p style='margin:4px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                        <p style='margin:4px 0;'><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                                    </div>
                                    <p style='color:#dc2626;font-size:0.9em;'>⚠️ Please change your password after first login for security purposes.</p>
                                    <div style='text-align:center;margin:20px 0;'>
                                        <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;'>🔑 Login to Your Portal</a>
                                    </div>
                                </div>
                            </body>
                            </html>";
                            sendEmail($email, $full_name, $subject, $message);

                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Failed to add research coordinator: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $current_status = (int)$_POST['current_status'];
        $new_status = $current_status ? 0 : 1;

        $stmt = $conn->prepare("UPDATE research_coordinators SET is_active = ? WHERE coordinator_id = ?");
        $stmt->bind_param("ii", $new_status, $coordinator_id);
        if ($stmt->execute()) {
            // Also toggle user account
            $toggle_stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE related_staff_id = ? AND role = 'research_coordinator'");
            $toggle_stmt->bind_param("ii", $new_status, $coordinator_id);
            $toggle_stmt->execute();
            $success = "Research coordinator has been " . ($new_status ? 'activated' : 'deactivated') . " successfully.";
        } else {
            $error = "Failed to update status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } else {
            $info_stmt = $conn->prepare("SELECT full_name, email FROM research_coordinators WHERE coordinator_id = ?");
            $info_stmt->bind_param("i", $coordinator_id);
            $info_stmt->execute();
            $rc_info = $info_stmt->get_result()->fetch_assoc();

            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $user_update = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_staff_id = ? AND role = 'research_coordinator'");
            $user_update->bind_param("si", $hashed, $coordinator_id);

            if ($user_update->execute() && $user_update->affected_rows > 0) {
                if ($rc_info && function_exists('isEmailEnabled') && isEmailEnabled()) {
                    sendPasswordResetEmail($rc_info['email'], $rc_info['full_name'], $new_password, true);
                }
                $success = "Password for '" . htmlspecialchars($rc_info['full_name'] ?? '') . "' has been reset.";
            } else {
                $error = "Failed to reset password. No matching user account found.";
            }
        }
    } elseif (isset($_POST['update_coordinator'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
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
            $check = $conn->prepare("SELECT coordinator_id FROM research_coordinators WHERE email = ? AND coordinator_id != ?");
            $check->bind_param("si", $email, $coordinator_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Email '$email' is already used by another coordinator.";
            } else {
                $stmt = $conn->prepare("UPDATE research_coordinators SET full_name = ?, email = ?, phone = ?, department = ? WHERE coordinator_id = ?");
                $stmt->bind_param("ssssi", $full_name, $email, $phone, $department, $coordinator_id);
                if ($stmt->execute()) {
                    // Update email in users table too
                    $email_stmt = $conn->prepare("UPDATE users SET email = ? WHERE related_staff_id = ? AND role = 'research_coordinator'");
                    $email_stmt->bind_param("si", $email, $coordinator_id);
                    $email_stmt->execute();
                    $success = "Research coordinator details updated successfully.";
                } else {
                    $error = "Failed to update details.";
                }
            }
        }
    } elseif (isset($_POST['delete_coordinator'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $conn->begin_transaction();
        try {
            $info_q = $conn->prepare("SELECT full_name FROM research_coordinators WHERE coordinator_id = ?");
            $info_q->bind_param("i", $coordinator_id);
            $info_q->execute();
            $info = $info_q->get_result()->fetch_assoc();

            $del_user = $conn->prepare("DELETE FROM users WHERE related_staff_id = ? AND role = 'research_coordinator'");
            $del_user->bind_param("i", $coordinator_id);
            $del_user->execute();

            $del_rc = $conn->prepare("DELETE FROM research_coordinators WHERE coordinator_id = ?");
            $del_rc->bind_param("i", $coordinator_id);
            $del_rc->execute();
            $conn->commit();
            $success = "Research coordinator '" . ($info['full_name'] ?? 'Unknown') . "' deleted.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to delete: " . $e->getMessage();
        }
    }
}

// Get all research coordinators
$coordinators = [];
$query = "
    SELECT rc.*, u.username, u.user_id as uid, u.is_active as user_active,
           (SELECT COUNT(*) FROM dissertations WHERE coordinator_id = rc.coordinator_id AND is_active = 1) as dissertation_count
    FROM research_coordinators rc
    LEFT JOIN users u ON rc.user_id = u.user_id
    ORDER BY rc.full_name
";
$result = $conn->query($query);
if ($result) while ($row = $result->fetch_assoc()) $coordinators[] = $row;

// Get departments for dropdown
$departments = [];
$dept_r = $conn->query("SELECT DISTINCT department FROM research_coordinators WHERE department IS NOT NULL AND department != '' ORDER BY department");
if ($dept_r) while ($row = $dept_r->fetch_assoc()) $departments[] = $row['department'];
// Also from lecturers
$dept_r2 = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
if ($dept_r2) while ($row = $dept_r2->fetch_assoc()) {
    if (!in_array($row['department'], $departments)) $departments[] = $row['department'];
}
sort($departments);

$page_title = 'Manage Research Coordinators';
$breadcrumbs = [['title' => 'Research Coordinators']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .coordinator-card { transition: all 0.2s; }
        .coordinator-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="bi bi-mortarboard me-2"></i>Research Coordinators</h3>
            <p class="text-muted mb-0">Manage accounts for dissertation research coordinators</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-person-plus me-2"></i>Add Coordinator
        </button>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <h4 class="mb-0"><?= count($coordinators) ?></h4>
                        <small class="text-muted">Total Coordinators</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-person-check"></i></div>
                    <div>
                        <h4 class="mb-0"><?= count(array_filter($coordinators, fn($c) => $c['is_active'])) ?></h4>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-person-x"></i></div>
                    <div>
                        <h4 class="mb-0"><?= count(array_filter($coordinators, fn($c) => !$c['is_active'])) ?></h4>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-journal-text"></i></div>
                    <div>
                        <h4 class="mb-0"><?= array_sum(array_column($coordinators, 'dissertation_count')) ?></h4>
                        <small class="text-muted">Dissertations Managed</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Coordinators Table -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($coordinators)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-plus text-muted" style="font-size:3rem;"></i>
                    <h5 class="mt-3 text-muted">No Research Coordinators Yet</h5>
                    <p class="text-muted">Click "Add Coordinator" to create the first research coordinator account.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Username</th>
                            <th>Department</th>
                            <th>Phone</th>
                            <th>Dissertations</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coordinators as $i => $rc): ?>
                        <?php $name_parts = explode(' ', $rc['full_name'], 3); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;font-weight:700;">
                                        <?= strtoupper(substr($rc['full_name'], 0, 1)) ?>
                                    </div>
                                    <strong><?= htmlspecialchars($rc['full_name']) ?></strong>
                                </div>
                            </td>
                            <td><small><?= htmlspecialchars($rc['email']) ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars($rc['username'] ?? 'N/A') ?></small></td>
                            <td><small><?= htmlspecialchars($rc['department'] ?? '-') ?></small></td>
                            <td><small><?= htmlspecialchars($rc['phone'] ?? '-') ?></small></td>
                            <td><span class="badge bg-info"><?= $rc['dissertation_count'] ?></span></td>
                            <td>
                                <?php if ($rc['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $rc['coordinator_id'] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="toggle_status" value="1">
                                        <input type="hidden" name="coordinator_id" value="<?= $rc['coordinator_id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= $rc['is_active'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $rc['is_active'] ? 'warning' : 'success' ?>" title="<?= $rc['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <i class="bi bi-<?= $rc['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                        </button>
                                    </form>
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resetModal<?= $rc['coordinator_id'] ?>" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $rc['coordinator_id'] ?>" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?= $rc['coordinator_id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Research Coordinator</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="update_coordinator" value="1">
                                            <input type="hidden" name="coordinator_id" value="<?= $rc['coordinator_id'] ?>">
                                            <div class="row g-2 mb-3">
                                                <div class="col-5">
                                                    <label class="form-label">First Name *</label>
                                                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($name_parts[0] ?? '') ?>" required>
                                                </div>
                                                <div class="col-3">
                                                    <label class="form-label">Middle</label>
                                                    <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($name_parts[1] ?? '') ?>">
                                                </div>
                                                <div class="col-4">
                                                    <label class="form-label">Last Name *</label>
                                                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($name_parts[2] ?? ($name_parts[1] ?? '')) ?>" required>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email *</label>
                                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($rc['email']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone</label>
                                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($rc['phone'] ?? '') ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Department</label>
                                                <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($rc['department'] ?? '') ?>" list="deptList">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Reset Password Modal -->
                        <div class="modal fade" id="resetModal<?= $rc['coordinator_id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-sm">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset Password</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="reset_password" value="1">
                                            <input type="hidden" name="coordinator_id" value="<?= $rc['coordinator_id'] ?>">
                                            <p class="small text-muted">Reset password for <strong><?= htmlspecialchars($rc['full_name']) ?></strong></p>
                                            <div class="mb-3">
                                                <label class="form-label">New Password</label>
                                                <input type="password" name="new_password" class="form-control" required minlength="6">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Confirm Password</label>
                                                <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Modal -->
                        <div class="modal fade" id="deleteModal<?= $rc['coordinator_id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-sm">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Delete Coordinator</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="delete_coordinator" value="1">
                                            <input type="hidden" name="coordinator_id" value="<?= $rc['coordinator_id'] ?>">
                                            <p>Are you sure you want to permanently delete <strong><?= htmlspecialchars($rc['full_name']) ?></strong>?</p>
                                            <p class="text-danger small">This will also remove their user account. This action cannot be undone!</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Coordinator Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Research Coordinator</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_coordinator" value="1">
                    
                    <h6 class="fw-bold text-muted mb-3"><i class="bi bi-person me-1"></i>Personal Information</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+265...">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" list="deptList" placeholder="e.g., Research & Postgraduate Studies">
                    </div>
                    
                    <hr>
                    <h6 class="fw-bold text-muted mb-3"><i class="bi bi-shield-lock me-1"></i>Login Credentials</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" class="form-control" id="genPassword" required minlength="6">
                                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('genPassword').value = Math.random().toString(36).slice(-8) + Math.random().toString(36).slice(-4).toUpperCase();">
                                    <i class="bi bi-shuffle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-2"></i>Add Coordinator</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Department Datalist -->
<datalist id="deptList">
    <?php foreach ($departments as $d): ?>
    <option value="<?= htmlspecialchars($d) ?>">
    <?php endforeach; ?>
    <option value="Research & Postgraduate Studies">
    <option value="Academic Affairs">
    <option value="Quality Assurance">
</datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
