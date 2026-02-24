<?php
// profile.php - Finance user profile page with editable details and profile picture
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance']);

$conn = getDbConnection();
$user = getCurrentUser();

$finance_user = null;
$success = '';
$error = '';

// Find finance user record
$finance_id = null;

// Check related_finance_id from the user record
if (!empty($user['related_finance_id'])) {
    $finance_id = $user['related_finance_id'];
}

// Fallback: try to find by email in finance_users table
if (empty($finance_id) && !empty($user['email'])) {
    $table_check = $conn->query("SHOW TABLES LIKE 'finance_users'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT finance_id FROM finance_users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $user['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $finance_id = $row['finance_id'];
        }
    }
}

// Fetch finance user details if found
if ($finance_id) {
    $stmt = $conn->prepare("SELECT * FROM finance_users WHERE finance_id = ?");
    $stmt->bind_param("i", $finance_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $finance_user = $row;
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Convert empty gender to NULL for ENUM column
    $gender = !empty($gender) && in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
    
    if (empty($full_name)) {
        $error = "Full name is required.";
    } else {
        if ($finance_user) {
            // Update existing finance user record
            $stmt = $conn->prepare("UPDATE finance_users SET full_name = ?, phone = ?, department = ?, position = ?, gender = ?, national_id = ?, address = ? WHERE finance_id = ?");
            $stmt->bind_param("sssssssi", $full_name, $phone, $department, $position, $gender, $national_id, $address, $finance_id);
            
            if ($stmt->execute()) {
                $success = "Profile updated successfully!";
                // Refresh finance user data
                $stmt = $conn->prepare("SELECT * FROM finance_users WHERE finance_id = ?");
                $stmt->bind_param("i", $finance_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $finance_user = $result->fetch_assoc();
            } else {
                $error = "Failed to update profile: " . $conn->error;
            }
        } else {
            // Create new finance user record
            $finance_code = 'FIN' . date('Y') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("INSERT INTO finance_users (finance_code, full_name, email, phone, department, position, gender, national_id, address, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("sssssssss", $finance_code, $full_name, $user['email'], $phone, $department, $position, $gender, $national_id, $address);
            
            if ($stmt->execute()) {
                $finance_id = $conn->insert_id;
                
                // Update users table to link to this finance record
                $update_user = $conn->prepare("UPDATE users SET related_finance_id = ? WHERE user_id = ?");
                $update_user->bind_param("ii", $finance_id, $user['user_id']);
                $update_user->execute();
                
                $success = "Profile created successfully!";
                
                // Fetch the new finance data
                $stmt = $conn->prepare("SELECT * FROM finance_users WHERE finance_id = ?");
                $stmt->bind_param("i", $finance_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $finance_user = $result->fetch_assoc();
            } else {
                $error = "Failed to create profile: " . $conn->error;
            }
        }
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK && $finance_id) {
    $upload_dir = '../uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (in_array($file_ext, $allowed_ext)) {
        // Check file size (max 5MB)
        if ($_FILES['profile_picture']['size'] <= 5 * 1024 * 1024) {
            $new_filename = 'finance_' . $finance_id . '_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                // Delete old profile picture if exists
                if (!empty($finance_user['profile_picture']) && file_exists($upload_dir . $finance_user['profile_picture'])) {
                    unlink($upload_dir . $finance_user['profile_picture']);
                }
                
                // Update database
                $stmt = $conn->prepare("UPDATE finance_users SET profile_picture = ? WHERE finance_id = ?");
                $stmt->bind_param("si", $new_filename, $finance_id);
                $stmt->execute();
                
                $success = "Profile picture updated successfully!";
                $finance_user['profile_picture'] = $new_filename;
            } else {
                $error = "Failed to upload profile picture.";
            }
        } else {
            $error = "File size too large. Maximum 5MB allowed.";
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
    <title>My Profile - VLE Finance</title>
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
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i>My Profile</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Profile Picture Column -->
                                <div class="col-md-4 text-center mb-4">
                                    <form method="POST" enctype="multipart/form-data" id="pictureForm">
                                        <div class="profile-picture-container">
                                            <?php if (!empty($finance_user['profile_picture']) && file_exists('../uploads/profiles/' . $finance_user['profile_picture'])): ?>
                                                <img src="../uploads/profiles/<?php echo htmlspecialchars($finance_user['profile_picture']); ?>" 
                                                     class="rounded-circle profile-picture" alt="Profile Picture">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center profile-picture">
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
                                    
                                    <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($finance_user['full_name'] ?? $user['display_name'] ?? $user['username']); ?></h4>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($finance_user['position'] ?? 'Finance Officer'); ?></p>
                                    <span class="badge bg-success">Finance</span>
                                    <?php if (!empty($finance_user['finance_code'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($finance_user['finance_code']); ?></small>
                                    <?php endif; ?>
                                    
                                    <hr class="my-3">
                                    
                                    <div class="text-start">
                                        <p class="mb-2"><i class="bi bi-envelope text-success me-2"></i><?php echo htmlspecialchars($user['email'] ?? $finance_user['email'] ?? 'N/A'); ?></p>
                                        <?php if (!empty($finance_user['phone'])): ?>
                                            <p class="mb-2"><i class="bi bi-telephone text-success me-2"></i><?php echo htmlspecialchars($finance_user['phone']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($finance_user['department'])): ?>
                                            <p class="mb-0"><i class="bi bi-building text-success me-2"></i><?php echo htmlspecialchars($finance_user['department']); ?></p>
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
                                                       value="<?php echo htmlspecialchars($finance_user['full_name'] ?? $user['display_name'] ?? ''); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="email" class="form-label">Email</label>
                                                <input type="email" class="form-control" id="email" 
                                                       value="<?php echo htmlspecialchars($user['email'] ?? $finance_user['email'] ?? ''); ?>" readonly>
                                                <small class="text-muted">Email cannot be changed</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="phone" class="form-label">Phone Number</label>
                                                <input type="text" class="form-control" id="phone" name="phone" 
                                                       value="<?php echo htmlspecialchars($finance_user['phone'] ?? ''); ?>"
                                                       placeholder="e.g., +265 999 123 456">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="gender" class="form-label">Gender</label>
                                                <select class="form-select" id="gender" name="gender">
                                                    <option value="">Select Gender</option>
                                                    <option value="Male" <?php echo ($finance_user['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="Female" <?php echo ($finance_user['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="Other" <?php echo ($finance_user['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="national_id" class="form-label">National ID</label>
                                                <input type="text" class="form-control" id="national_id" name="national_id" 
                                                       value="<?php echo htmlspecialchars($finance_user['national_id'] ?? ''); ?>"
                                                       placeholder="Enter national ID number">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="department" class="form-label">Department</label>
                                                <input type="text" class="form-control" id="department" name="department" 
                                                       value="<?php echo htmlspecialchars($finance_user['department'] ?? 'Finance Department'); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="position" class="form-label">Position</label>
                                                <input type="text" class="form-control" id="position" name="position" 
                                                       value="<?php echo htmlspecialchars($finance_user['position'] ?? 'Finance Officer'); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" readonly>
                                                <small class="text-muted">Username cannot be changed</small>
                                            </div>
                                            <div class="col-12">
                                                <label for="address" class="form-label">Address</label>
                                                <textarea class="form-control" id="address" name="address" rows="2" 
                                                          placeholder="Enter your address"><?php echo htmlspecialchars($finance_user['address'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="submit" name="update_profile" class="btn btn-success">
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
