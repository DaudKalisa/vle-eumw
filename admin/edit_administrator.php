<?php
// edit_administrator.php - Admin edit administrator details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$admin_id = isset($_GET['id']) ? $_GET['id'] : '';

// Get administrator details with username
$stmt = $conn->prepare("SELECT l.*, u.username FROM lecturers l LEFT JOIN users u ON l.email = u.email WHERE l.lecturer_id = ? AND l.role = 'staff'");
$stmt->bind_param("s", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$admin = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_administrator'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username'] ?? '');
    $position = trim($_POST['position']);
    $gender = trim($_POST['gender'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $office = trim($_POST['office'] ?? '');
    
    // Handle profile picture upload
    $profile_picture = $admin['profile_picture'];
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_exts)) {
            $new_filename = 'admin_' . $admin_id . '_' . time() . '.' . $file_ext;
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
    
    // Update administrator details (keep department as 'Administration')
    $stmt = $conn->prepare("UPDATE lecturers SET full_name = ?, email = ?, position = ?, gender = ?, phone = ?, office = ?, profile_picture = ? WHERE lecturer_id = ? AND role = 'staff'");
    $stmt->bind_param("ssssssss", $full_name, $email, $position, $gender, $phone, $office, $profile_picture, $admin_id);
    
    if ($stmt->execute()) {
        // Update user email and username if exists
        $user_stmt = $conn->prepare("UPDATE users SET email = ?, username = ? WHERE email = (SELECT email FROM lecturers WHERE lecturer_id = ?)");
        $user_stmt->bind_param("sss", $email, $username, $admin_id);
        $user_stmt->execute();
        
        $success = "Administrator details updated successfully!";
        
        // Refresh admin data with username
        $stmt = $conn->prepare("SELECT l.*, u.username FROM lecturers l LEFT JOIN users u ON l.email = u.email WHERE l.lecturer_id = ? AND l.role = 'staff'");
        $stmt->bind_param("s", $admin_id);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Failed to update administrator details.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Administrator - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-shield-fill-check"></i> Edit Administrator</h2>
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
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
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Profile Picture</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($admin['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($admin['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                                 style="width: 200px; height: 200px; font-size: 80px;">
                                <i class="bi bi-shield-fill-check"></i>
                            </div>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($admin['full_name']); ?></h5>
                        <p class="text-muted">ID: <?php echo htmlspecialchars($admin['lecturer_id']); ?></p>
                        <span class="badge bg-warning">Administrator</span>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Administrator Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="admin_id" class="form-label">Administrator ID</label>
                                    <input type="text" class="form-control" id="admin_id" value="<?php echo htmlspecialchars($admin['lecturer_id']); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($admin['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>" required>
                                    <small class="text-muted">Login username for the system</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($admin['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($admin['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($admin['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="position" class="form-label">Position/Title *</label>
                                    <input type="text" class="form-control" id="position" name="position" 
                                           value="<?php echo htmlspecialchars($admin['position'] ?? 'System Administrator'); ?>" required>
                                    <small class="text-muted">e.g., System Administrator, Dean, Director</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="office" class="form-label">Office Location *</label>
                                    <select class="form-select" id="office" name="office" required>
                                        <option value="">Select Office Location</option>
                                        <option value="Mzuzu Campus" <?php echo ($admin['office'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                        <option value="Lilongwe Campus" <?php echo ($admin['office'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                        <option value="Blantyre Campus" <?php echo ($admin['office'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                        <option value="Head Office" <?php echo ($admin['office'] ?? '') == 'Head Office' ? 'selected' : ''; ?>>Head Office</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" value="Administration" disabled>
                                <small class="text-muted">Administrators are assigned to the Administration department</small>
                            </div>

                            <div class="mb-3">
                                <label for="profile_picture" class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_administrator" class="btn btn-warning">
                                    <i class="bi bi-save"></i> Update Administrator
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
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
