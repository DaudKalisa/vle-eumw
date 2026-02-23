<?php
// manage_administrators.php - Admin manage system administrators
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

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
        $admin_role = trim($_POST['admin_role'] ?? 'System Administrator');

        // Add to lecturers table with role='staff'
        $stmt = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, position, gender, role) VALUES (?, ?, ?, ?, ?, 'staff')");
        $stmt->bind_param("sssss", $full_name, $email, $phone, $position, $gender);
        
        if ($stmt->execute()) {
            $lecturer_id = $conn->insert_id;

            // Create user account
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_lecturer_id) VALUES (?, ?, ?, 'staff', ?)");
            $stmt->bind_param("sssi", $username, $email, $password_hash, $lecturer_id);
            
            if ($stmt->execute()) {
                $success = "Administrator added successfully! Admin ID: ADM" . str_pad($lecturer_id, 4, '0', STR_PAD_LEFT);
            } else {
                $error = "Failed to create user account. Error: " . $stmt->error;
            }
        } else {
            $error = "Failed to add administrator. Error: " . $stmt->error;
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
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE related_lecturer_id = ?");
            $stmt->bind_param("si", $password_hash, $lecturer_id);
            
            if ($stmt->execute()) {
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Administrators - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-speedometer2"></i> Admin Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="bi bi-shield-fill-check text-warning"></i> Manage Administrators</h2>
                <p class="text-muted mb-0">Manage system administrators and staff accounts</p>
            </div>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="bi bi-person-plus-fill"></i> Add New Administrator
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-warning">
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
                            <div class="col-md-4">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required>
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
                                <input type="email" class="form-control" name="email">
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
</body>
</html>
