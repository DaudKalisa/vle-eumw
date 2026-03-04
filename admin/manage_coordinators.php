<?php
// admin/manage_coordinators.php - Manage ODL Coordinators
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

$success = '';
$error = '';

// Ensure odl_coordinators table exists
$conn->query("CREATE TABLE IF NOT EXISTS odl_coordinators (
    coordinator_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(100),
    position VARCHAR(100) DEFAULT 'ODL Coordinator',
    campus VARCHAR(50) DEFAULT 'all',
    profile_picture VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_campus (campus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ensure campus column exists (for existing tables)
$col_check = $conn->query("SHOW COLUMNS FROM odl_coordinators LIKE 'campus'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE odl_coordinators ADD COLUMN campus VARCHAR(50) DEFAULT 'all' AFTER position");
    $conn->query("ALTER TABLE odl_coordinators ADD INDEX idx_campus (campus)");
}

// Campus options
$campus_options = [
    'all' => 'All Campuses',
    'blantyre' => 'Blantyre Campus',
    'lilongwe' => 'Lilongwe Campus',
    'mzuzu' => 'Mzuzu Campus',
    'odel' => 'ODeL Campus',
    'postgraduate' => 'Postgraduate Campus'
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_coordinator'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? 'Open Distance Learning');
        $position = trim($_POST['position'] ?? 'ODL Coordinator');
        $campus = trim($_POST['campus'] ?? 'all');
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        if (empty($full_name)) {
            $error = "Full name is required.";
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
                $error = "Username '$username' already exists.";
            } else {
                // Check if email already exists
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = "Email '$email' already exists in users table.";
                } else {
                    $conn->begin_transaction();
                    try {
                        // Create user account with odl_coordinator role
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Check if odl_coordinator role exists in enum
                        $role_check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
                        $role_row = $role_check->fetch_assoc();
                        $role_value = 'odl_coordinator';
                        if (strpos($role_row['Type'], 'odl_coordinator') === false) {
                            // Role not in enum, use 'staff' as fallback
                            $role_value = 'staff';
                        }
                        
                        $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                        $user_stmt->bind_param("ssss", $username, $email, $hashed_password, $role_value);
                        $user_stmt->execute();
                        $user_id = $conn->insert_id;

                        // Insert into odl_coordinators table
                        $insert_stmt = $conn->prepare("INSERT INTO odl_coordinators (user_id, full_name, email, phone, department, position, campus) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $insert_stmt->bind_param("issssss", $user_id, $full_name, $email, $phone, $department, $position, $campus);
                        $insert_stmt->execute();

                        $conn->commit();
                        $success = "Coordinator '$full_name' has been added successfully.";

                        // Send welcome email
                        if (function_exists('isEmailEnabled') && isEmailEnabled()) {
                            $subject = "Welcome to VLE - ODL Coordinator Account";
                            $message = "
                            <html><body>
                                <h2>Welcome to the Virtual Learning Environment</h2>
                                <p>Dear $full_name,</p>
                                <p>Your ODL Coordinator account has been created successfully.</p>
                                <p><strong>Login Details:</strong></p>
                                <ul>
                                    <li><strong>Username:</strong> $username</li>
                                    <li><strong>Email:</strong> $email</li>
                                    <li><strong>Password:</strong> $password</li>
                                </ul>
                                <p>Please change your password after first login for security purposes.</p>
                                <p>Best regards,<br>VLE Administration Team</p>
                            </body></html>";
                            @sendEmail($email, $full_name, $subject, $message);
                        }

                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Failed to add coordinator: " . $e->getMessage();
                    }
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $current_status = (int)$_POST['current_status'];
        $new_status = $current_status ? 0 : 1;

        $update_stmt = $conn->prepare("UPDATE odl_coordinators SET is_active = ? WHERE coordinator_id = ?");
        $update_stmt->bind_param("ii", $new_status, $coordinator_id);

        if ($update_stmt->execute()) {
            // Also update the user account
            $conn->query("UPDATE users u JOIN odl_coordinators c ON u.user_id = c.user_id SET u.is_active = $new_status WHERE c.coordinator_id = $coordinator_id");
            $status_text = $new_status ? 'activated' : 'deactivated';
            $success = "Coordinator has been $status_text successfully.";
        } else {
            $error = "Failed to update coordinator status.";
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
            $coord_stmt = $conn->prepare("SELECT c.full_name, c.email, c.user_id FROM odl_coordinators c WHERE c.coordinator_id = ?");
            $coord_stmt->bind_param("i", $coordinator_id);
            $coord_stmt->execute();
            $coord_info = $coord_stmt->get_result()->fetch_assoc();

            if ($coord_info) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $user_update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $user_update->bind_param("si", $hashed_password, $coord_info['user_id']);

                if ($user_update->execute() && $user_update->affected_rows > 0) {
                    if (function_exists('isEmailEnabled') && isEmailEnabled()) {
                        @sendPasswordResetEmail($coord_info['email'], $coord_info['full_name'], $new_password, true);
                    }
                    $success = "Password for '" . htmlspecialchars($coord_info['full_name']) . "' has been reset successfully!";
                } else {
                    $error = "Failed to reset password. No matching user account found.";
                }
            } else {
                $error = "Coordinator not found.";
            }
        }
    } elseif (isset($_POST['update_coordinator'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $campus = trim($_POST['campus'] ?? 'all');

        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } else {
            $check_stmt = $conn->prepare("SELECT coordinator_id FROM odl_coordinators WHERE email = ? AND coordinator_id != ?");
            $check_stmt->bind_param("si", $email, $coordinator_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Email '$email' is already used by another coordinator.";
            } else {
                $update_stmt = $conn->prepare("UPDATE odl_coordinators SET full_name = ?, email = ?, phone = ?, department = ?, position = ?, campus = ? WHERE coordinator_id = ?");
                $update_stmt->bind_param("ssssssi", $full_name, $email, $phone, $department, $position, $campus, $coordinator_id);

                if ($update_stmt->execute()) {
                    // Also update email in users table
                    $conn->query("UPDATE users u JOIN odl_coordinators c ON u.user_id = c.user_id SET u.email = '" . $conn->real_escape_string($email) . "' WHERE c.coordinator_id = $coordinator_id");
                    $success = "Coordinator details updated successfully.";
                } else {
                    $error = "Failed to update coordinator details.";
                }
            }
        }
    } elseif (isset($_POST['delete_coordinator'])) {
        $coordinator_id = (int)$_POST['coordinator_id'];
        $admin_password = $_POST['admin_password'] ?? '';

        if (empty($admin_password)) {
            $error = "Admin password is required to delete a coordinator.";
        } else {
            $uid = $_SESSION['user_id'] ?? 0;
            $pw_stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $pw_stmt->bind_param('i', $uid);
            $pw_stmt->execute();
            $pw_user = $pw_stmt->get_result()->fetch_assoc();

            if (!$pw_user || !password_verify($admin_password, $pw_user['password_hash'])) {
                $error = "Incorrect admin password. Deletion cancelled.";
            } else {
                $conn->begin_transaction();
                try {
                    // Get user_id first
                    $get_stmt = $conn->prepare("SELECT user_id, full_name FROM odl_coordinators WHERE coordinator_id = ?");
                    $get_stmt->bind_param("i", $coordinator_id);
                    $get_stmt->execute();
                    $coord = $get_stmt->get_result()->fetch_assoc();

                    if ($coord) {
                        // Delete from odl_coordinators
                        $del_stmt = $conn->prepare("DELETE FROM odl_coordinators WHERE coordinator_id = ?");
                        $del_stmt->bind_param("i", $coordinator_id);
                        $del_stmt->execute();

                        // Delete user account
                        $del_user = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                        $del_user->bind_param("i", $coord['user_id']);
                        $del_user->execute();

                        $conn->commit();
                        $success = "Coordinator '" . htmlspecialchars($coord['full_name']) . "' has been deleted.";
                    } else {
                        $error = "Coordinator not found.";
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to delete coordinator: " . $e->getMessage();
                }
            }
        }
    }
}

// Get all coordinators
$query = "
    SELECT c.*, u.username, u.user_id as uid, u.is_active as user_active, u.last_login
    FROM odl_coordinators c
    LEFT JOIN users u ON c.user_id = u.user_id
    ORDER BY c.created_at DESC
";
$result = $conn->query($query);
$coordinators = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Statistics
$total = count($coordinators);
$active = 0;
$inactive = 0;
foreach ($coordinators as $c) {
    if ($c['is_active']) $active++;
    else $inactive++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Coordinators - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $page_title = "Manage Coordinators";
    $breadcrumbs = [['title' => 'Coordinators']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-person-video3 me-2"></i>ODL Coordinators</h2>
                <p class="text-muted mb-0">Manage Open Distance Learning coordinator accounts</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCoordinatorModal">
                <i class="bi bi-person-plus me-1"></i>Add Coordinator
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
                        <div class="display-5 fw-bold text-primary"><?= $total ?></div>
                        <small class="text-muted">Total Coordinators</small>
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

        <!-- Coordinators Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header text-white d-flex justify-content-between align-items-center" style="background: var(--vle-gradient-primary);">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Coordinator Directory</h5>
                <span class="badge bg-light text-dark"><?= $total ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if (count($coordinators) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Coordinator</th>
                                    <th>Contact</th>
                                    <th>Campus</th>
                                    <th>Department</th>
                                    <th>Username</th>
                                    <th>Last Login</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coordinators as $coord): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                 style="width:36px;height:36px;background:linear-gradient(135deg, #f59e0b, #d97706);color:#fff;font-weight:600;font-size:14px;">
                                                <?= strtoupper(substr($coord['full_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($coord['full_name']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($coord['position']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($coord['email']) ?></small>
                                        <?php if (!empty($coord['phone'])): ?>
                                            <br><small><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($coord['phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $campus_val = $coord['campus'] ?? 'all';
                                        $campus_label = $campus_options[$campus_val] ?? ucfirst($campus_val);
                                        if ($campus_val === 'all'): ?>
                                            <span class="badge bg-primary"><i class="bi bi-globe me-1"></i><?= $campus_label ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark"><i class="bi bi-building me-1"></i><?= $campus_label ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= htmlspecialchars($coord['department'] ?? 'N/A') ?></small></td>
                                    <td><code><?= htmlspecialchars($coord['username'] ?? 'N/A') ?></code></td>
                                    <td>
                                        <?php if (!empty($coord['last_login'])): ?>
                                            <small><?= date('M d, Y H:i', strtotime($coord['last_login'])) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Never</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($coord['is_active']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($coord['created_at'])) ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="editCoordinator(<?= $coord['coordinator_id'] ?>)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-warning" onclick="openResetPassword(<?= $coord['coordinator_id'] ?>, '<?= htmlspecialchars(addslashes($coord['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($coord['username'] ?? ''), ENT_QUOTES) ?>')" title="Reset Password">
                                                <i class="bi bi-key"></i>
                                            </button>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="coordinator_id" value="<?= $coord['coordinator_id'] ?>">
                                                <input type="hidden" name="current_status" value="<?= $coord['is_active'] ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-outline-<?= $coord['is_active'] ? 'secondary' : 'success' ?>" 
                                                        onclick="return confirm('<?= $coord['is_active'] ? 'Deactivate' : 'Activate' ?> this coordinator?')"
                                                        title="<?= $coord['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi bi-<?= $coord['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                                </button>
                                            </form>
                                            <button class="btn btn-outline-danger" onclick="openDeleteModal(<?= $coord['coordinator_id'] ?>, '<?= htmlspecialchars(addslashes($coord['full_name']), ENT_QUOTES) ?>')" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-person-video3 display-4 d-block mb-3"></i>
                        <p>No coordinators yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCoordinatorModal">
                            <i class="bi bi-person-plus me-1"></i>Add First Coordinator
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Coordinator Modal -->
    <div class="modal fade" id="addCoordinatorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: var(--vle-gradient-primary);">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Coordinator</h5>
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
                                <input type="text" class="form-control" name="department" value="Open Distance Learning">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position</label>
                                <input type="text" class="form-control" name="position" value="ODL Coordinator">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Campus Assignment <span class="text-danger">*</span></label>
                                <select class="form-select" name="campus" required>
                                    <?php foreach ($campus_options as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select "All Campuses" to manage across all locations</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="add_password" required minlength="8">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('add_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <small class="form-text text-muted">Minimum 8 characters</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_coordinator" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Add Coordinator</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Coordinator Modal -->
    <div class="modal fade" id="editCoordinatorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background: var(--vle-gradient-primary);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Coordinator</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="editCoordinatorForm">
                    <input type="hidden" name="coordinator_id" id="edit_coordinator_id">
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
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" id="edit_department" name="department">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position</label>
                                <input type="text" class="form-control" id="edit_position" name="position">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Campus Assignment <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_campus" name="campus" required>
                                    <?php foreach ($campus_options as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_coordinator" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update</button>
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
                    <input type="hidden" name="coordinator_id" id="reset_coordinator_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Resetting password for: <strong id="reset_coord_name"></strong>
                            <br><small class="text-muted">Username: <code id="reset_coord_username"></code></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="reset_new_password" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('reset_new_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password" id="reset_confirm_password" required minlength="6">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('reset_confirm_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback" id="password_mismatch" style="display:none;">Passwords do not match!</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generatePassword()">
                            <i class="bi bi-shuffle me-1"></i>Generate Random Password
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Coordinator</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="coordinator_id" id="delete_coordinator_id">
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            You are about to permanently delete coordinator: <strong id="delete_coord_name"></strong>
                            <br>This will also delete their user account. This action cannot be undone.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Enter your admin password to confirm <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="admin_password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_coordinator" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Coordinator data for edit modal
    const coordinators = <?= json_encode($coordinators) ?>;

    function editCoordinator(coordId) {
        const coord = coordinators.find(c => c.coordinator_id == coordId);
        if (coord) {
            document.getElementById('edit_coordinator_id').value = coord.coordinator_id;
            // Split full_name into first, middle, last
            const nameParts = (coord.full_name || '').trim().split(/\s+/);
            const firstName = nameParts[0] || '';
            const lastName = nameParts.length > 1 ? nameParts[nameParts.length - 1] : '';
            const middleName = nameParts.length > 2 ? nameParts.slice(1, -1).join(' ') : '';
            document.getElementById('edit_first_name').value = firstName;
            document.getElementById('edit_middle_name').value = middleName;
            document.getElementById('edit_last_name').value = lastName;
            document.getElementById('edit_email').value = coord.email;
            document.getElementById('edit_phone').value = coord.phone || '';
            document.getElementById('edit_department').value = coord.department || '';
            document.getElementById('edit_position').value = coord.position || '';
            document.getElementById('edit_campus').value = coord.campus || 'all';
            new bootstrap.Modal(document.getElementById('editCoordinatorModal')).show();
        }
    }

    function openResetPassword(coordId, fullName, username) {
        document.getElementById('reset_coordinator_id').value = coordId;
        document.getElementById('reset_coord_name').textContent = fullName;
        document.getElementById('reset_coord_username').textContent = username || 'N/A';
        document.getElementById('reset_new_password').value = '';
        document.getElementById('reset_confirm_password').value = '';
        document.getElementById('password_mismatch').style.display = 'none';
        new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
    }

    function openDeleteModal(coordId, fullName) {
        document.getElementById('delete_coordinator_id').value = coordId;
        document.getElementById('delete_coord_name').textContent = fullName;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
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
        document.getElementById('reset_new_password').type = 'text';
        document.getElementById('reset_confirm_password').type = 'text';
    }

    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        const pw = document.getElementById('reset_new_password').value;
        const cpw = document.getElementById('reset_confirm_password').value;
        if (pw !== cpw) {
            e.preventDefault();
            document.getElementById('password_mismatch').style.display = 'block';
            document.getElementById('reset_confirm_password').classList.add('is-invalid');
        }
    });
    </script>
</body>
</html>
