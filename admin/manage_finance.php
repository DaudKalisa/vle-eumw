<?php
// manage_finance.php - Admin manage finance users
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_finance'])) {
        $finance_id = 'FIN' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $full_name = trim($_POST['first_name']) . ' ' . trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $position = trim($_POST['position']);
        $office = trim($_POST['office']);
        $phone = trim($_POST['phone'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        // Check if email already exists
        $check_email = $conn->prepare("SELECT email FROM lecturers WHERE email = ? UNION SELECT email FROM students WHERE email = ? UNION SELECT email FROM users WHERE email = ?");
        $check_email->bind_param("sss", $email, $email, $email);
        $check_email->execute();
        $result = $check_email->get_result();
        
        if ($result->num_rows > 0) {
            $error = "This email already exists in the system. Please use a different email.";
        } else {
            // Check if username already exists
            $check_username = $conn->prepare("SELECT username FROM users WHERE username = ?");
            $check_username->bind_param("s", $username);
            $check_username->execute();
            $username_result = $check_username->get_result();
            
            if ($username_result->num_rows > 0) {
                $error = "This username already exists. Please choose a different username.";
            } else {
                $stmt = $conn->prepare("INSERT INTO lecturers (lecturer_id, full_name, email, password, department, position, office, phone, gender, role, is_active) VALUES (?, ?, ?, ?, 'Finance Department', ?, ?, ?, ?, 'finance', TRUE)");
                $stmt->bind_param("ssssssss", $finance_id, $full_name, $email, $password, $position, $office, $phone, $gender);
                
                if ($stmt->execute()) {
                    // Create user entry in users table with password_hash column
                    $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'finance')");
                    $user_stmt->bind_param("sss", $username, $email, $password);
                    
                    if ($user_stmt->execute()) {
                        $success = "Finance user added successfully! Username: " . $username . " | ID: " . $finance_id;
                    } else {
                        $error = "Finance user added to lecturers but failed to create user account. Error: " . $user_stmt->error;
                    }
                } else {
                    $error = "Failed to add finance user. Error: " . $stmt->error;
                }
            }
        }
    } elseif (isset($_POST['delete_finance'])) {
        $finance_id = $_POST['finance_id'];
        
        // Get email before deleting
        $stmt = $conn->prepare("SELECT email FROM lecturers WHERE lecturer_id = ? AND role = 'finance'");
        $stmt->bind_param("s", $finance_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $email = $row['email'];
            
            // Delete from lecturers table
            $stmt = $conn->prepare("DELETE FROM lecturers WHERE lecturer_id = ? AND role = 'finance'");
            $stmt->bind_param("s", $finance_id);
            
            if ($stmt->execute()) {
                // Delete from users table
                $user_stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
                $user_stmt->bind_param("s", $email);
                $user_stmt->execute();
                
                $success = "Finance user deleted successfully!";
            } else {
                $error = "Failed to delete finance user.";
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $finance_id = $_POST['finance_id'];
        $new_status = (int)$_POST['new_status'];
        
        $stmt = $conn->prepare("UPDATE lecturers SET is_active = ? WHERE lecturer_id = ? AND role = 'finance'");
        $stmt->bind_param("is", $new_status, $finance_id);
        
        if ($stmt->execute()) {
            $success = "Finance user status updated successfully!";
        } else {
            $error = "Failed to update status.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $finance_id = $_POST['finance_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE lecturers SET password = ? WHERE lecturer_id = ? AND role = 'finance'");
            $stmt->bind_param("ss", $hashed_password, $finance_id);
            
            if ($stmt->execute()) {
                $success = "Password reset successfully!";
            } else {
                $error = "Failed to reset password.";
            }
        }
    }
}

// Get all finance users with username
$finance_users = [];
$query = "SELECT l.*, u.username FROM lecturers l LEFT JOIN users u ON l.email = u.email WHERE l.role = 'finance' ORDER BY l.lecturer_id DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $finance_users[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Finance Users - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand img {
            height: 40px;
            width: auto;
            margin-right: 10px;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../pictures/logo.bmp" alt="VLE Logo">
                <span>VLE Admin - Finance Users</span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="bi bi-cash-coin text-success"></i> Manage Finance Users</h2>
                <p class="text-muted mb-0">Add and manage finance officers and accounting staff</p>
            </div>
            <div>
                <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#addFinanceModal">
                    <i class="bi bi-person-plus-fill"></i> Add New Finance User
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase">Total Finance Users</h6>
                        <h3 class="mb-0 text-success"><?php echo count($finance_users); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-info">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase">Active Users</h6>
                        <h3 class="mb-0 text-info"><?php echo count(array_filter($finance_users, function($f) { return $f['is_active']; })); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-warning">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase">Inactive Users</h6>
                        <h3 class="mb-0 text-warning"><?php echo count(array_filter($finance_users, function($f) { return !$f['is_active']; })); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Finance Users List -->
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-people-fill"></i> All Finance Users (<?php echo count($finance_users); ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Finance ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Position</th>
                                <th>Office</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($finance_users)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                        <p class="mb-0">No finance users found. Add your first finance user above.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($finance_users as $finance): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($finance['lecturer_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($finance['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($finance['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($finance['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($finance['position'] ?? 'Finance Officer'); ?></td>
                                        <td><?php echo htmlspecialchars($finance['office'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($finance['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($finance['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo $finance['lecturer_id']; ?>" title="Reset Password">
                                                <i class="bi bi-key-fill"></i>
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="finance_id" value="<?php echo $finance['lecturer_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $finance['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-sm btn-<?php echo $finance['is_active'] ? 'secondary' : 'success'; ?>" title="Toggle Status">
                                                    <i class="bi bi-toggle-<?php echo $finance['is_active'] ? 'on' : 'off'; ?>"></i>
                                                </button>
                                            </form>
                                            <a href="edit_finance.php?id=<?php echo $finance['lecturer_id']; ?>" class="btn btn-sm btn-primary" title="Edit">
                                                <i class="bi bi-pencil-fill"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this finance user?');">
                                                <input type="hidden" name="finance_id" value="<?php echo $finance['lecturer_id']; ?>">
                                                <button type="submit" name="delete_finance" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Password Reset Modal -->
                                    <div class="modal fade" id="resetPasswordModal<?php echo $finance['lecturer_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reset Password - <?php echo htmlspecialchars($finance['full_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="finance_id" value="<?php echo $finance['lecturer_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">New Password *</label>
                                                            <input type="password" class="form-control" name="new_password" required minlength="6">
                                                            <small class="text-muted">Minimum 6 characters</small>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Confirm Password *</label>
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Finance User Modal -->
    <div class="modal fade" id="addFinanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Add New Finance User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position/Title *</label>
                                <input type="text" class="form-control" name="position" placeholder="e.g., Finance Officer" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Office Location *</label>
                                <select class="form-select" name="office" required>
                                    <option value="">Select Office</option>
                                    <option value="Mzuzu Campus">Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                    <option value="Head Office">Head Office</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone">
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
                            <div class="col-md-4">
                                <label class="form-label">Password *</label>
                                <input type="password" class="form-control" name="password" required minlength="6">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <small class="text-muted me-auto">ID will be auto-generated (e.g., FIN0001)</small>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_finance" class="btn btn-success">
                            <i class="bi bi-plus-circle"></i> Add Finance User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
