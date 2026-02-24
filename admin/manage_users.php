<?php
// manage_users.php - Admin manage all system users
require_once '../includes/auth.php';
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
                $success = "Password reset successfully! User will be required to change password on next login.";
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
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE user_id = ?");
                $stmt->bind_param("sssi", $username, $email, $role, $user_id);
                
                if ($stmt->execute()) {
                    $success = "User updated successfully!";
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

if ($filter_role !== 'all') {
    $where_conditions[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= 's';
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
          s.student_id, l.lecturer_id, f.finance_id
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

// Get counts by role
$role_counts = [];
$count_result = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
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
                            <option value="student" <?php echo $filter_role === 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="lecturer" <?php echo $filter_role === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                            <option value="staff" <?php echo $filter_role === 'staff' ? 'selected' : ''; ?>>Admin/Staff</option>
                            <option value="finance" <?php echo $filter_role === 'finance' ? 'selected' : ''; ?>>Finance</option>
                            <option value="hod" <?php echo $filter_role === 'hod' ? 'selected' : ''; ?>>HOD</option>
                            <option value="dean" <?php echo $filter_role === 'dean' ? 'selected' : ''; ?>>Dean</option>
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
                                                    'dean' => 'bg-dark'
                                                ];
                                                $role_color = $role_colors[$u['role']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $role_color; ?> role-badge">
                                                    <?php echo ucfirst($u['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($u['is_active']): ?>
                                                    <span class="status-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="status-inactive"><i class="bi bi-x-circle-fill me-1"></i>Inactive</span>
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
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit User</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Username</label>
                                                                <input type="text" class="form-control" name="username" 
                                                                       value="<?php echo htmlspecialchars($u['username']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Email</label>
                                                                <input type="email" class="form-control" name="email" 
                                                                       value="<?php echo htmlspecialchars($u['email']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Role</label>
                                                                <select class="form-select" name="role" required>
                                                                    <option value="student" <?php echo $u['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                                                    <option value="lecturer" <?php echo $u['role'] === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                                                    <option value="staff" <?php echo $u['role'] === 'staff' ? 'selected' : ''; ?>>Admin/Staff</option>
                                                                    <option value="finance" <?php echo $u['role'] === 'finance' ? 'selected' : ''; ?>>Finance</option>
                                                                    <option value="hod" <?php echo $u['role'] === 'hod' ? 'selected' : ''; ?>>HOD</option>
                                                                    <option value="dean" <?php echo $u['role'] === 'dean' ? 'selected' : ''; ?>>Dean</option>
                                                                </select>
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
</body>
</html>
