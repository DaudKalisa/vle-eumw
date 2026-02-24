<?php
// profile.php - Admin profile page with editable details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$admin_data = null;
$success = '';
$error = '';

// Find admin/lecturer record for this user
$admin_id = null;

// First check related_lecturer_id from the user record
if (!empty($user['related_lecturer_id'])) {
    $admin_id = $user['related_lecturer_id'];
}

// Fallback: try to find by email in lecturers table
if (empty($admin_id) && !empty($user['email'])) {
    $stmt = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $user['email']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $admin_id = $row['lecturer_id'];
    }
}

// Fetch admin details if found
if ($admin_id) {
    $stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $admin_data = $row;
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $office = trim($_POST['office'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    
    // Convert empty gender to NULL for ENUM column
    $gender = !empty($gender) && in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
    
    if (empty($full_name)) {
        $error = "Full name is required.";
    } else {
        if ($admin_data) {
            // Update existing admin record in lecturers table
            $stmt = $conn->prepare("UPDATE lecturers SET full_name = ?, phone = ?, position = ?, office = ?, gender = ? WHERE lecturer_id = ?");
            $stmt->bind_param("sssssi", $full_name, $phone, $position, $office, $gender, $admin_id);
            
            if ($stmt->execute()) {
                $success = "Profile updated successfully!";
                // Refresh admin data
                $stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
                $stmt->bind_param("i", $admin_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin_data = $result->fetch_assoc();
            } else {
                $error = "Failed to update profile: " . $conn->error;
            }
        } else {
            // Create new admin record in lecturers table
            $stmt = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, position, office, gender, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 'staff', 1)");
            $stmt->bind_param("ssssss", $full_name, $user['email'], $phone, $position, $office, $gender);
            
            if ($stmt->execute()) {
                $admin_id = $conn->insert_id;
                
                // Update users table to link to this lecturer record
                $update_user = $conn->prepare("UPDATE users SET related_lecturer_id = ? WHERE user_id = ?");
                $update_user->bind_param("ii", $admin_id, $user['user_id']);
                $update_user->execute();
                
                $success = "Profile created successfully!";
                
                // Fetch the new admin data
                $stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
                $stmt->bind_param("i", $admin_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin_data = $result->fetch_assoc();
            } else {
                $error = "Failed to create profile: " . $conn->error;
            }
        }
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = '../uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (in_array($file_ext, $allowed_ext)) {
        $new_filename = 'admin_' . $admin_id . '_' . time() . '.' . $file_ext;
        $target_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
            // Delete old profile picture if exists
            if (!empty($admin_data['profile_picture']) && file_exists($upload_dir . $admin_data['profile_picture'])) {
                unlink($upload_dir . $admin_data['profile_picture']);
            }
            
            // Update database
            $stmt = $conn->prepare("UPDATE lecturers SET profile_picture = ? WHERE lecturer_id = ?");
            $stmt->bind_param("si", $new_filename, $admin_id);
            $stmt->execute();
            
            $success = "Profile picture updated successfully!";
            $admin_data['profile_picture'] = $new_filename;
        } else {
            $error = "Failed to upload profile picture.";
        }
    } else {
        $error = "Invalid file type. Allowed: JPG, JPEG, PNG, GIF";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .profile-picture-container {
            position: relative;
            display: inline-block;
        }
        .profile-picture {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 4px solid #dee2e6;
        }
        .profile-picture-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.6);
            color: white;
            text-align: center;
            padding: 8px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .profile-picture-container:hover .profile-picture-overlay {
            opacity: 1;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'profile';
    $pageTitle = 'My Profile';
    $breadcrumbs = [['title' => 'My Profile']];
    include 'header_nav.php'; 
    ?>
    
    <div class="vle-content">
        <div class="container py-4">
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
            
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i>My Profile</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Profile Picture Column -->
                                <div class="col-md-4 text-center mb-4">
                                    <form method="POST" enctype="multipart/form-data" id="pictureForm">
                                        <div class="profile-picture-container">
                                            <?php if (!empty($admin_data['profile_picture']) && file_exists('../uploads/profiles/' . $admin_data['profile_picture'])): ?>
                                                <img src="../uploads/profiles/<?php echo htmlspecialchars($admin_data['profile_picture']); ?>" 
                                                     class="rounded-circle profile-picture" alt="Profile Picture">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center profile-picture">
                                                    <i class="bi bi-person" style="font-size: 4rem;"></i>
                                                </div>
                                            <?php endif; ?>
                                            <label for="profile_picture" class="profile-picture-overlay rounded-bottom">
                                                <i class="bi bi-camera me-1"></i>Change Photo
                                            </label>
                                        </div>
                                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*" 
                                               class="d-none" onchange="document.getElementById('pictureForm').submit();">
                                    </form>
                                    
                                    <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($admin_data['full_name'] ?? $user['display_name'] ?? $user['username']); ?></h4>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($admin_data['position'] ?? 'Administrator'); ?></p>
                                    <span class="badge bg-danger">Administrator</span>
                                    
                                    <hr class="my-3">
                                    
                                    <div class="text-start">
                                        <p class="mb-2"><i class="bi bi-envelope text-primary me-2"></i><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></p>
                                        <?php if (!empty($admin_data['phone'])): ?>
                                            <p class="mb-2"><i class="bi bi-telephone text-primary me-2"></i><?php echo htmlspecialchars($admin_data['phone']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($admin_data['office'])): ?>
                                            <p class="mb-0"><i class="bi bi-geo-alt text-primary me-2"></i><?php echo htmlspecialchars($admin_data['office']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Edit Form Column -->
                                <div class="col-md-8">
                                    <h5 class="mb-3"><i class="bi bi-pencil-square me-2"></i>Edit Profile Details</h5>
                                    
                                    <form method="POST">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="full_name" class="form-label">Full Name *</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                                       value="<?php echo htmlspecialchars($admin_data['full_name'] ?? $user['display_name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="email" class="form-label">Email</label>
                                                <input type="email" class="form-control" id="email" 
                                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" readonly>
                                                <small class="text-muted">Email cannot be changed</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="phone" class="form-label">Phone Number</label>
                                                <input type="text" class="form-control" id="phone" name="phone" 
                                                       value="<?php echo htmlspecialchars($admin_data['phone'] ?? ''); ?>"
                                                       placeholder="e.g., +265 999 123 456">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="position" class="form-label">Position/Title</label>
                                                <input type="text" class="form-control" id="position" name="position" 
                                                       value="<?php echo htmlspecialchars($admin_data['position'] ?? 'System Administrator'); ?>"
                                                       placeholder="e.g., System Administrator">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="office" class="form-label">Office Location</label>
                                                <select class="form-select" id="office" name="office">
                                                    <option value="">Select Office</option>
                                                    <option value="Mzuzu Campus" <?php echo ($admin_data['office'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                                    <option value="Lilongwe Campus" <?php echo ($admin_data['office'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                                    <option value="Blantyre Campus" <?php echo ($admin_data['office'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                                    <option value="Head Office" <?php echo ($admin_data['office'] ?? '') == 'Head Office' ? 'selected' : ''; ?>>Head Office</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="gender" class="form-label">Gender</label>
                                                <select class="form-select" id="gender" name="gender">
                                                    <option value="">Select Gender</option>
                                                    <option value="Male" <?php echo ($admin_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="Female" <?php echo ($admin_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="Other" <?php echo ($admin_data['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" readonly>
                                                <small class="text-muted">Username cannot be changed</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Role</label>
                                                <input type="text" class="form-control" value="Administrator" readonly>
                                            </div>
                                        </div>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="submit" name="update_profile" class="btn btn-primary">
                                                <i class="bi bi-save me-2"></i>Save Changes
                                            </button>
                                            <a href="../change_password.php" class="btn btn-outline-warning">
                                                <i class="bi bi-key me-2"></i>Change Password
                                            </a>
                                            <a href="dashboard.php" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                                            </a>
                                        </div>
                                    </form>
                                </div>
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
