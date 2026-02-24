<?php
// profile.php - Lecturer profile management
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

// Get lecturer details
$stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = trim($_POST['phone'] ?? '');
    $office = trim($_POST['office'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
    
    // Handle profile picture upload
    $profile_picture = $lecturer['profile_picture'];
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_exts)) {
            // Check file size (5MB max)
            if ($_FILES['profile_picture']['size'] <= 5242880) {
                $new_filename = 'lecturer_' . $lecturer_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                    // Delete old profile picture if exists
                    if ($profile_picture && file_exists('../uploads/profiles/' . $profile_picture)) {
                        unlink('../uploads/profiles/' . $profile_picture);
                    }
                    $profile_picture = $new_filename;
                    $success = "Profile picture updated successfully!";
                } else {
                    $error = "Failed to upload profile picture.";
                }
            } else {
                $error = "File size exceeds 5MB limit.";
            }
        } else {
            $error = "Invalid file format. Only JPG, JPEG, PNG, and GIF allowed.";
        }
    }
    
    // Update profile
    if (!isset($error)) {
        $stmt = $conn->prepare("UPDATE lecturers SET phone = ?, office = ?, bio = ?, gender = ?, profile_picture = ? WHERE lecturer_id = ?");
        $stmt->bind_param("sssssi", $phone, $office, $bio, $gender, $profile_picture, $lecturer_id);
        
        if ($stmt->execute()) {
            if (!isset($success)) $success = "Profile updated successfully!";
            // Refresh lecturer data
            $stmt = $conn->prepare("SELECT * FROM lecturers WHERE lecturer_id = ?");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
            $lecturer = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Failed to update profile.";
        }
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'profile';
    $pageTitle = 'My Profile';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-person-badge me-2"></i>My Profile</h1>
            <p class="text-muted mb-0">Manage your profile information</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
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

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-image"></i> Profile Picture</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($lecturer['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($lecturer['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3 shadow" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover; border: 4px solid #198754;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow"
                                 style="width: 200px; height: 200px; font-size: 80px; border: 4px solid #6c757d;">
                                <i class="bi bi-person-badge"></i>
                            </div>
                        <?php endif; ?>
                        <h4><?php echo htmlspecialchars($lecturer['full_name']); ?></h4>
                        <p class="text-muted mb-1">Lecturer ID: <?php echo $lecturer['lecturer_id']; ?></p>
                        <p class="text-muted mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($lecturer['email']); ?></p>
                    </div>
                </div>

                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Professional Info</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Program of Study:</strong><br><?php echo htmlspecialchars($lecturer['department']); ?></p>
                        <p class="mb-2"><strong>Department:</strong><br><?php echo htmlspecialchars($lecturer['program'] ?? 'Not Set'); ?></p>
                        <p class="mb-2"><strong>Position:</strong><br><?php echo htmlspecialchars($lecturer['position']); ?></p>
                        <?php if ($lecturer['office']): ?>
                            <p class="mb-0"><strong>Office:</strong><br><?php echo htmlspecialchars($lecturer['office']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Profile</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" 
                                       value="<?php echo htmlspecialchars($lecturer['full_name']); ?>" disabled>
                                <small class="text-muted">Contact administrator to change your name</small>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" 
                                       value="<?php echo htmlspecialchars($lecturer['email']); ?>" disabled>
                                <small class="text-muted">Contact administrator to change your email</small>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($lecturer['phone'] ?? ''); ?>" 
                                           placeholder="Enter your phone number">
                                </div>
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($lecturer['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($lecturer['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($lecturer['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="office" class="form-label">Office Location *</label>
                                    <select class="form-select" id="office" name="office" required>
                                        <option value="">Select Office Location</option>
                                        <option value="Mzuzu Campus" <?php echo ($lecturer['office'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                        <option value="Lilongwe Campus" <?php echo ($lecturer['office'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                        <option value="Blantyre Campus" <?php echo ($lecturer['office'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="bio" class="form-label">Biography</label>
                                <textarea class="form-control" id="bio" name="bio" rows="4" 
                                          placeholder="Write a brief biography about yourself, your research interests, and teaching philosophy..."><?php echo htmlspecialchars($lecturer['bio'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="profile_picture" class="form-label">
                                    <i class="bi bi-camera-fill"></i> Update Profile Picture
                                </label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" 
                                       accept="image/jpeg,image/jpg,image/png,image/gif">
                                <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="update_profile" class="btn btn-success btn-lg">
                                    <i class="bi bi-save"></i> Update Profile
                                </button>
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
