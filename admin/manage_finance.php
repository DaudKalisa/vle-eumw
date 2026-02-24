<?php
// manage_finance.php - Admin manage finance users
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Check if finance_users table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'finance_users'")->num_rows > 0;
if (!$table_exists) {
    // Redirect to setup page
    echo "<div class='alert alert-warning m-4'>Finance users table not found. <a href='../setup_finance_table.php'>Click here to create it</a>.</div>";
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_finance'])) {
        $finance_code = 'FIN' . date('Y') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $full_name = trim($_POST['first_name']) . ' ' . trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $position = trim($_POST['position'] ?? 'Finance Officer');
        $phone = trim($_POST['phone'] ?? '');
        $raw_gender = trim($_POST['gender'] ?? '');
        // Validate gender - must match ENUM values or be NULL
        $valid_genders = ['Male', 'Female', 'Other'];
        $gender = in_array($raw_gender, $valid_genders) ? $raw_gender : null;
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        // Check if email already exists
        $check_email = $conn->prepare("SELECT email COLLATE utf8mb4_general_ci as email FROM finance_users WHERE email = ? UNION SELECT email COLLATE utf8mb4_general_ci FROM students WHERE email = ? UNION SELECT email FROM users WHERE email = ?");
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
                $stmt = $conn->prepare("INSERT INTO finance_users (finance_code, full_name, email, password, department, position, phone, gender, is_active) VALUES (?, ?, ?, ?, 'Finance Department', ?, ?, ?, TRUE)");
                $stmt->bind_param("sssssss", $finance_code, $full_name, $email, $password, $position, $phone, $gender);
                
                if ($stmt->execute()) {
                    $new_finance_id = $conn->insert_id;
                    
                    // Create user entry in users table with password_hash column
                    $user_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_finance_id, must_change_password) VALUES (?, ?, ?, 'finance', ?, 1)");
                    $user_stmt->bind_param("sssi", $username, $email, $password, $new_finance_id);
                    
                    if ($user_stmt->execute()) {
                        // Send welcome email with credentials
                        if (isEmailEnabled()) {
                            sendFinanceWelcomeEmail($email, $full_name, $username, $_POST['password'], $position);
                        }
                        $success = "Finance user added successfully! Username: " . $username . " | Code: " . $finance_code;
                    } else {
                        $error = "Finance user added but failed to create user account. Error: " . $user_stmt->error;
                    }
                } else {
                    $error = "Failed to add finance user. Error: " . $stmt->error;
                }
            }
        }
    } elseif (isset($_POST['delete_finance'])) {
        $finance_id = $_POST['finance_id'];
        
        // Get email before deleting
        $stmt = $conn->prepare("SELECT email FROM finance_users WHERE finance_id = ?");
        $stmt->bind_param("i", $finance_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $email = $row['email'];
            
            // Delete from finance_users table
            $stmt = $conn->prepare("DELETE FROM finance_users WHERE finance_id = ?");
            $stmt->bind_param("i", $finance_id);
            
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
        
        $stmt = $conn->prepare("UPDATE finance_users SET is_active = ? WHERE finance_id = ?");
        $stmt->bind_param("ii", $new_status, $finance_id);
        
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
            // Get finance user info for email
            $finance_info_stmt = $conn->prepare("SELECT full_name, email FROM finance_users WHERE finance_id = ?");
            $finance_info_stmt->bind_param("i", $finance_id);
            $finance_info_stmt->execute();
            $finance_info = $finance_info_stmt->get_result()->fetch_assoc();
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE finance_users SET password = ? WHERE finance_id = ?");
            $stmt->bind_param("si", $hashed_password, $finance_id);
            
            if ($stmt->execute()) {
                // Also update users table password
                if ($finance_info) {
                    $user_update = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_finance_id = ?");
                    $user_update->bind_param("si", $hashed_password, $finance_id);
                    $user_update->execute();
                    
                    // Send password reset notification email
                    if (isEmailEnabled()) {
                        sendPasswordResetEmail($finance_info['email'], $finance_info['full_name'], $new_password, true);
                    }
                }
                $success = "Password reset successfully!";
            } else {
                $error = "Failed to reset password.";
            }
        }
    }
}

// Get all finance users with username
$finance_users = [];
$query = "SELECT f.*, u.username FROM finance_users f LEFT JOIN users u ON f.email COLLATE utf8mb4_general_ci = u.email ORDER BY f.finance_id DESC";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $finance_users[] = $row;
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Finance Users - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'manage_finance';
    $pageTitle = 'Manage Finance Users';
    $breadcrumbs = [['title' => 'Finance Users']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-cash-coin me-2"></i>Manage Finance Users</h1>
                    <p class="text-muted mb-0">Add and manage finance officers and accounting staff</p>
                </div>
                <button type="button" class="btn btn-vle-primary" data-bs-toggle="modal" data-bs-target="#addFinanceModal">
                    <i class="bi bi-person-plus-fill"></i> Add New Finance User
                </button>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert vle-alert-error alert-dismissible fade show">
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
                                <th>Finance Code</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Position</th>
                                <th>Department</th>
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
                                        <td><strong><?php echo htmlspecialchars($finance['finance_code'] ?? 'FIN-' . $finance['finance_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($finance['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($finance['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($finance['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($finance['position'] ?? 'Finance Officer'); ?></td>
                                        <td><?php echo htmlspecialchars($finance['department'] ?? 'Finance Department'); ?></td>
                                        <td><?php echo htmlspecialchars($finance['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($finance['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo $finance['finance_id']; ?>" title="Reset Password">
                                                <i class="bi bi-key-fill"></i>
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="finance_id" value="<?php echo $finance['finance_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $finance['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-sm btn-<?php echo $finance['is_active'] ? 'secondary' : 'success'; ?>" title="Toggle Status">
                                                    <i class="bi bi-toggle-<?php echo $finance['is_active'] ? 'on' : 'off'; ?>"></i>
                                                </button>
                                            </form>
                                            <a href="edit_finance.php?id=<?php echo $finance['finance_id']; ?>" class="btn btn-sm btn-primary" title="Edit">
                                                <i class="bi bi-pencil-fill"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this finance user?');">
                                                <input type="hidden" name="finance_id" value="<?php echo $finance['finance_id']; ?>">
                                                <button type="submit" name="delete_finance" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Password Reset Modal -->
                                    <div class="modal fade" id="resetPasswordModal<?php echo $finance['finance_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reset Password - <?php echo htmlspecialchars($finance['full_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="finance_id" value="<?php echo $finance['finance_id']; ?>">
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
                                <input type="text" class="form-control" id="fin_first_name" name="first_name" required oninput="generateFinanceCredentials()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="fin_last_name" name="last_name" required oninput="generateFinanceCredentials()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" id="fin_email" name="email" required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" id="fin_username" name="username" required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Position/Title *</label>
                                <select class="form-select" name="position" required>
                                    <option value="">Select Position</option>
                                    <option value="University President">University President</option>
                                    <option value="Vice President">Vice President</option>
                                    <option value="Director of Corporate Services">Director of Corporate Services</option>
                                    <option value="Senior Accountant">Senior Accountant</option>
                                    <option value="Accountant">Accountant</option>
                                    <option value="Assistant Accountant">Assistant Accountant</option>
                                    <option value="Cashier">Cashier</option>
                                    <option value="Finance Officer">Finance Officer</option>
                                </select>
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
    <script>
    // Auto-generate username and email from first, middle, and last name
    function generateFinanceCredentials() {
        const firstName = document.getElementById('fin_first_name').value.trim().toLowerCase();
        const middleName = document.getElementById('fin_middle_name')?.value.trim().toLowerCase() || '';
        const lastName = document.getElementById('fin_last_name').value.trim().toLowerCase();
        
        if (firstName && lastName) {
            // Username: first initial + middle initial + surname (e.g., daud kalisa phiri = dkphiri)
            const middleInitial = middleName ? middleName.charAt(0) : '';
            const username = firstName.charAt(0) + middleInitial + lastName.replace(/\s+/g, '');
            document.getElementById('fin_username').value = username;
            
            // Email: username@exploitsonline.com
            document.getElementById('fin_email').value = username + '@exploitsonline.com';
        }
    }
    </script>
</body>
</html>
