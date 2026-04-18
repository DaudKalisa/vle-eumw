<?php
/**
 * ODL Coordinator Profile Page
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get coordinator profile
$coordinator = null;
$stmt = $conn->prepare("SELECT * FROM odl_coordinators WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user['user_id']);
    $stmt->execute();
    $coordinator = $stmt->get_result()->fetch_assoc();
}

// Handle profile update
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    
    // Handle profile picture upload
    $profile_picture = $coordinator['profile_picture'] ?? null;
    if (!empty($_FILES['profile_picture']['name'])) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $filename = 'odl_' . $user['user_id'] . '_' . time() . '.' . $ext;
        
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $filename)) {
            $profile_picture = $filename;
        }
    }
    
    if ($coordinator) {
        $stmt = $conn->prepare("UPDATE odl_coordinators SET full_name = ?, email = ?, phone = ?, department = ?, profile_picture = ? WHERE user_id = ?");
        $stmt->bind_param("sssssi", $full_name, $email, $phone, $department, $profile_picture, $user['user_id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO odl_coordinators (user_id, full_name, email, phone, department, profile_picture) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user['user_id'], $full_name, $email, $phone, $department, $profile_picture);
    }
    
    if ($stmt->execute()) {
        $message = 'Profile updated successfully!';
        $message_type = 'success';
        
        // Refresh coordinator data
        $stmt = $conn->prepare("SELECT * FROM odl_coordinators WHERE user_id = ?");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $coordinator = $stmt->get_result()->fetch_assoc();
    } else {
        $message = 'Failed to update profile: ' . $conn->error;
        $message_type = 'danger';
    }
}

$page_title = 'My Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .profile-card { border-radius: 12px; overflow: hidden; }
        .profile-header { background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); color: white; padding: 40px 20px; text-align: center; }
        .profile-avatar { width: 120px; height: 120px; border-radius: 50%; border: 4px solid white; object-fit: cover; }
        .profile-avatar-placeholder { width: 120px; height: 120px; border-radius: 50%; border: 4px solid white; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 48px; margin: 0 auto; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="card profile-card">
                    <div class="profile-header">
                        <?php if (!empty($coordinator['profile_picture']) && file_exists('../uploads/profiles/' . $coordinator['profile_picture'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($coordinator['profile_picture']) ?>" class="profile-avatar" alt="Profile">
                        <?php else: ?>
                        <div class="profile-avatar-placeholder">
                            <?= strtoupper(substr($coordinator['full_name'] ?? 'O', 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <h4 class="mt-3 mb-1"><?= htmlspecialchars($coordinator['full_name'] ?? 'ODL Coordinator') ?></h4>
                        <p class="mb-0 opacity-75"><?= htmlspecialchars($coordinator['position'] ?? 'ODL Coordinator') ?></p>
                    </div>
                    
                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($coordinator['full_name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($coordinator['email'] ?? $user['email'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($coordinator['phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($coordinator['department'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Profile Picture</label>
                                    <input type="file" name="profile_picture" class="form-control" accept="image/*">
                                    <small class="text-muted">Accepted formats: JPG, PNG, GIF (max 2MB)</small>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="d-flex justify-content-between">
                                <a href="../change_password.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-key me-1"></i>Change Password
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Account Info -->
                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Account Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Username:</strong> <?= htmlspecialchars($user['username'] ?? 'N/A') ?></p>
                                <p class="mb-1"><strong>Role:</strong> <span class="badge bg-primary">ODL Coordinator</span></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Account Created:</strong> <?= isset($coordinator['created_at']) ? date('M j, Y', strtotime($coordinator['created_at'])) : 'N/A' ?></p>
                                <p class="mb-1"><strong>Last Updated:</strong> <?= isset($coordinator['updated_at']) ? date('M j, Y', strtotime($coordinator['updated_at'])) : 'N/A' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
