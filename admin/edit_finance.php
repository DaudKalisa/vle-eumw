<?php
// edit_finance.php - Admin edit finance user details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$finance_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if finance_users table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'finance_users'")->num_rows > 0;
if (!$table_exists) {
    echo "<div class='alert alert-warning m-4'>Finance users table not found. <a href='../setup_finance_table.php'>Click here to create it</a>.</div>";
    exit;
}

// Get finance user details with username
$stmt = $conn->prepare("SELECT f.*, u.username FROM finance_users f LEFT JOIN users u ON f.email COLLATE utf8mb4_general_ci = u.email WHERE f.finance_id = ?");
$stmt->bind_param("i", $finance_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage_finance.php');
    exit();
}

$finance = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_finance'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username'] ?? '');
    $position = trim($_POST['position']);
    $gender = trim($_POST['gender'] ?? '');
    $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
    $phone = trim($_POST['phone'] ?? '');
    $old_email = $finance['email'];
    
    // Handle profile picture upload
    $profile_picture = $finance['profile_picture'];
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_exts)) {
            $new_filename = 'finance_' . $finance_id . '_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                // Delete old profile picture if exists
                if ($profile_picture && file_exists('../uploads/profiles/' . $profile_picture)) {
                    unlink('../uploads/profiles/' . $profile_picture);
                }
                $profile_picture = $new_filename;
            }
        }
    }
    
    // Update finance user details
    $stmt = $conn->prepare("UPDATE finance_users SET full_name = ?, email = ?, position = ?, gender = ?, phone = ?, profile_picture = ? WHERE finance_id = ?");
    $stmt->bind_param("ssssssi", $full_name, $email, $position, $gender, $phone, $profile_picture, $finance_id);
    
    if ($stmt->execute()) {
        // Update user email and username if exists
        $user_stmt = $conn->prepare("UPDATE users SET email = ?, username = ? WHERE email = ?");
        $user_stmt->bind_param("sss", $email, $username, $old_email);
        $user_stmt->execute();
        
        // Redirect back to manage finance page
        header("Location: manage_finance.php?success=" . urlencode("Finance user details updated successfully!"));
        exit();
    } else {
        $error = "Failed to update finance user details.";
    }
}

// Note: Don't close $conn until page is done
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Finance User - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-cash-coin"></i> Edit Finance User</h2>
            <a href="manage_finance.php" class="btn btn-secondary">Back to Finance Users</a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Profile Picture</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($finance['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($finance['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                                 style="width: 200px; height: 200px; font-size: 80px;">
                                <i class="bi bi-cash-coin"></i>
                            </div>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($finance['full_name']); ?></h5>
                        <p class="text-muted">Code: <?php echo htmlspecialchars($finance['finance_code'] ?? 'FIN-' . $finance['finance_id']); ?></p>
                        <span class="badge bg-success">Finance Officer</span>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Finance User Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="finance_code" class="form-label">Finance Code</label>
                                    <input type="text" class="form-control" id="finance_code" value="<?php echo htmlspecialchars($finance['finance_code'] ?? 'FIN-' . $finance['finance_id']); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($finance['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($finance['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($finance['username'] ?? ''); ?>" required>
                                    <small class="text-muted">Login username for the system</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($finance['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($finance['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($finance['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($finance['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="position" class="form-label">Position/Title *</label>
                                    <select class="form-select" id="position" name="position" required>
                                        <option value="">Select Position</option>
                                        <option value="University President" <?php echo ($finance['position'] ?? '') == 'University President' ? 'selected' : ''; ?>>University President</option>
                                        <option value="Vice President" <?php echo ($finance['position'] ?? '') == 'Vice President' ? 'selected' : ''; ?>>Vice President</option>
                                        <option value="Director of Corporate Services" <?php echo ($finance['position'] ?? '') == 'Director of Corporate Services' ? 'selected' : ''; ?>>Director of Corporate Services</option>
                                        <option value="Senior Accountant" <?php echo ($finance['position'] ?? '') == 'Senior Accountant' ? 'selected' : ''; ?>>Senior Accountant</option>
                                        <option value="Accountant" <?php echo ($finance['position'] ?? '') == 'Accountant' ? 'selected' : ''; ?>>Accountant</option>
                                        <option value="Assistant Accountant" <?php echo ($finance['position'] ?? '') == 'Assistant Accountant' ? 'selected' : ''; ?>>Assistant Accountant</option>
                                        <option value="Cashier" <?php echo ($finance['position'] ?? '') == 'Cashier' ? 'selected' : ''; ?>>Cashier</option>
                                        <option value="Finance Officer" <?php echo ($finance['position'] ?? '') == 'Finance Officer' ? 'selected' : ''; ?>>Finance Officer</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($finance['department'] ?? 'Finance Department'); ?>" disabled>
                                    <small class="text-muted">Finance users are assigned to the Finance Department</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="profile_picture" class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_finance" class="btn btn-success">
                                    <i class="bi bi-save"></i> Update Finance User
                                </button>
                                <a href="manage_finance.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
