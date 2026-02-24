<?php
// manage_administrators.php - Admin manage system administrators
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_admin'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $phone = trim($_POST['phone'] ?? '');
        $position = trim($_POST['position'] ?? 'Administrator');
        $gender = trim($_POST['gender'] ?? '');
        $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
        $admin_role = trim($_POST['admin_role'] ?? 'System Administrator');

        // Validate required fields
        if (empty($first_name) || empty($last_name)) {
            $error = "First name and last name are required.";
        } elseif (empty($username)) {
            $error = "Username is required.";
        } elseif (empty($password)) {
            $error = "Password is required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
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
                    $error = "Email '$email' is already registered. Please use a different email.";
                } else {
                    // Check if email already exists in lecturers table
                    $check_stmt = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE email = ?");
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows > 0) {
                        $error = "Email '$email' is already registered in the staff system. Please use a different email.";
                    }
                }
            }
            $check_stmt->close();
        }

        // Proceed only if no errors
        if (empty($error)) {
            // Add to lecturers table with role='staff'
            $stmt = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, position, gender, role) VALUES (?, ?, ?, ?, ?, 'staff')");
            $stmt->bind_param("sssss", $full_name, $email, $phone, $position, $gender);
        
            if ($stmt->execute()) {
                $lecturer_id = $conn->insert_id;

                // Create user account
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_lecturer_id, must_change_password) VALUES (?, ?, ?, 'staff', ?, 1)");
                $stmt->bind_param("sssi", $username, $email, $password_hash, $lecturer_id);
                
                if ($stmt->execute()) {
                    // Send welcome email with credentials
                    if (isEmailEnabled()) {
                        sendAdminWelcomeEmail($email, $full_name, $username, $password);
                    }
                    $success = "Administrator added successfully! Admin ID: ADM" . str_pad($lecturer_id, 4, '0', STR_PAD_LEFT);
                } else {
                    $error = "Failed to create user account. Error: " . $stmt->error;
                    // Rollback: delete the lecturer record if user creation fails
                    $conn->query("DELETE FROM lecturers WHERE lecturer_id = $lecturer_id");
                }
            } else {
                $error = "Failed to add administrator. Error: " . $stmt->error;
            }
        }
    } elseif (isset($_POST['delete_admin'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];

        // Delete from users and lecturers
        $stmt = $conn->prepare("DELETE FROM users WHERE related_lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM lecturers WHERE lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        
        if ($stmt->execute()) {
            $success = "Administrator deleted successfully!";
        } else {
            $error = "Failed to delete administrator.";
        }
    } elseif (isset($_POST['toggle_status'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];
        $new_status = (int)$_POST['new_status'];
        
        $stmt = $conn->prepare("UPDATE lecturers SET is_active = ? WHERE lecturer_id = ?");
        $stmt->bind_param("ii", $new_status, $lecturer_id);
        
        if ($stmt->execute()) {
            $success = "Administrator status updated successfully!";
        } else {
            $error = "Failed to update status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            // Get admin info for email
            $admin_info_stmt = $conn->prepare("SELECT l.full_name, l.email FROM lecturers l WHERE l.lecturer_id = ?");
            $admin_info_stmt->bind_param("i", $lecturer_id);
            $admin_info_stmt->execute();
            $admin_info = $admin_info_stmt->get_result()->fetch_assoc();
            
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_lecturer_id = ?");
            $stmt->bind_param("si", $password_hash, $lecturer_id);
            
            if ($stmt->execute()) {
                // Send password reset notification email
                if (isEmailEnabled() && $admin_info) {
                    sendPasswordResetEmail($admin_info['email'], $admin_info['full_name'], $new_password, true);
                }
                $success = "Password reset successfully!";
            } else {
                $error = "Failed to reset password.";
            }
        } else {
            $error = "Passwords do not match or are too short (minimum 6 characters)!";
        }
    }
}

// Get all administrators (lecturers with role='staff')
$administrators = [];
$result = $conn->query("SELECT l.*, u.username, u.is_active as user_active 
                        FROM lecturers l 
                        LEFT JOIN users u ON l.lecturer_id = u.related_lecturer_id 
                        WHERE l.role = 'staff' 
                        ORDER BY l.full_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $administrators[] = $row;
    }
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Administrators - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'manage_administrators';
    $pageTitle = 'Manage Administrators';
    $breadcrumbs = [['title' => 'Administrators']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-shield-fill-check me-2"></i>Manage Administrators</h1>
                    <p class="text-muted mb-0">Manage system administrators and staff accounts</p>
                </div>
                <button type="button" class="btn btn-vle-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                    <i class="bi bi-person-plus-fill"></i> Add New Administrator
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert vle-alert-error alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card vle-card border-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-shield-fill-check text-warning" style="font-size: 2.5rem;"></i>
                        <h6 class="text-muted text-uppercase mt-2">Total Administrators</h6>
                        <h3 class="mb-0 text-warning"><?php echo count($administrators); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 2.5rem;"></i>
                        <h6 class="text-muted text-uppercase mt-2">Active</h6>
                        <h3 class="mb-0 text-success">
                            <?php echo count(array_filter($administrators, function($a) { return $a['is_active']; })); ?>
                        </h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 2.5rem;"></i>
                        <h6 class="text-muted text-uppercase mt-2">Inactive</h6>
                        <h3 class="mb-0 text-danger">
                            <?php echo count(array_filter($administrators, function($a) { return !$a['is_active']; })); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Administrators List -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-people-fill"></i> Administrators (<?php echo count($administrators); ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($administrators)): ?>
                    <div class="alert alert-info m-3">
                        <i class="bi bi-info-circle"></i> No administrators found. Add one using the form above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Admin ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Position</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($administrators as $admin): ?>
                                    <tr>
                                        <td><strong>ADM<?php echo str_pad($admin['lecturer_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                        <td><?php echo htmlspecialchars($admin['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($admin['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($admin['position'] ?? 'Administrator'); ?></td>
                                        <td><?php echo htmlspecialchars($admin['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($admin['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo $admin['lecturer_id']; ?>" title="Reset Password">
                                                <i class="bi bi-key-fill"></i>
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="lecturer_id" value="<?php echo $admin['lecturer_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $admin['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-sm btn-<?php echo $admin['is_active'] ? 'warning' : 'success'; ?>" title="Toggle Status">
                                                    <i class="bi bi-toggle-<?php echo $admin['is_active'] ? 'on' : 'off'; ?>"></i>
                                                </button>
                                            </form>
                                            <a href="edit_administrator.php?id=<?php echo $admin['lecturer_id']; ?>" class="btn btn-sm btn-info" title="Edit">
                                                <i class="bi bi-pencil-fill"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this administrator?');">
                                                <input type="hidden" name="lecturer_id" value="<?php echo $admin['lecturer_id']; ?>">
                                                <button type="submit" name="delete_admin" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Password Reset Modal -->
                                    <div class="modal fade" id="resetPasswordModal<?php echo $admin['lecturer_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reset Password - <?php echo htmlspecialchars($admin['full_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="lecturer_id" value="<?php echo $admin['lecturer_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                                                            <input type="password" class="form-control" name="new_password" required minlength="6">
                                                            <small class="text-muted">Minimum 6 characters</small>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
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

    <!-- Add Administrator Modal -->
    <div class="modal fade" id="addAdminModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Add New Administrator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><h6 class="text-warning">Administrator Information</h6></div>
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="admin_first_name" name="first_name" required oninput="generateAdminCredentials()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="admin_middle_name" name="middle_name" oninput="generateAdminCredentials()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="admin_last_name" name="last_name" required oninput="generateAdminCredentials()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="admin_username" name="username" required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required minlength="6">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="position" value="Administrator" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="admin_role">
                                    <option value="System Administrator" selected>System Administrator</option>
                                    <option value="Super Admin">Super Admin</option>
                                    <option value="Academic Administrator">Academic Administrator</option>
                                    <option value="IT Administrator">IT Administrator</option>
                                    <option value="Data Administrator">Data Administrator</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="admin_email" name="email">
                                <small class="text-muted">Auto-generated</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_admin" class="btn btn-warning">
                            <i class="bi bi-plus-circle"></i> Add Administrator
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Auto-generate username and email from first, middle, and last name
    function generateAdminCredentials() {
        const firstName = document.getElementById('admin_first_name').value.trim().toLowerCase();
        const middleName = document.getElementById('admin_middle_name')?.value.trim().toLowerCase() || '';
        const lastName = document.getElementById('admin_last_name').value.trim().toLowerCase();
        
        if (firstName && lastName) {
            // Username: first initial + middle initial + surname (e.g., daud kalisa phiri = dkphiri)
            const middleInitial = middleName ? middleName.charAt(0) : '';
            const username = firstName.charAt(0) + middleInitial + lastName.replace(/\s+/g, '');
            document.getElementById('admin_username').value = username;
            
            // Email: username@exploitsonline.com
            document.getElementById('admin_email').value = username + '@exploitsonline.com';
        }
    }
    </script>
</body>
</html>
