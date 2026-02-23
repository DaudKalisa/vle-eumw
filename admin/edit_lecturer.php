<?php
// edit_lecturer.php - Admin edit lecturer details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$lecturer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get lecturer details with username
$stmt = $conn->prepare("SELECT l.*, u.username FROM lecturers l LEFT JOIN users u ON l.email = u.email WHERE l.lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage_lecturers.php');
    exit();
}

$lecturer = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lecturer'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username'] ?? '');
    $department = trim($_POST['department']);
    $program = trim($_POST['program'] ?? '');
    $position = trim($_POST['position']);
    $gender = trim($_POST['gender'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $office = trim($_POST['office'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    
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
            $new_filename = 'lecturer_' . $lecturer_id . '_' . time() . '.' . $file_ext;
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
    
    // Update lecturer details
    $stmt = $conn->prepare("UPDATE lecturers SET full_name = ?, email = ?, department = ?, program = ?, position = ?, gender = ?, phone = ?, office = ?, bio = ?, profile_picture = ? WHERE lecturer_id = ?");
    $stmt->bind_param("ssssssssssi", $full_name, $email, $department, $program, $position, $gender, $phone, $office, $bio, $profile_picture, $lecturer_id);
    
    if ($stmt->execute()) {
        // Update user email and username if exists
        $user_stmt = $conn->prepare("UPDATE users SET email = ?, username = ? WHERE email = (SELECT email FROM lecturers WHERE lecturer_id = ?)");
        $user_stmt->bind_param("ssi", $email, $username, $lecturer_id);
        $user_stmt->execute();
        
        $success = "Lecturer details updated successfully!";
        
        // Refresh lecturer data with username
        $stmt = $conn->prepare("SELECT l.*, u.username FROM lecturers l LEFT JOIN users u ON l.email = u.email WHERE l.lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();
        $lecturer = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Failed to update lecturer details.";
    }
}

// Get all departments
$departments = [];
$dept_result = $conn->query("SELECT * FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Lecturer - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-person-fill-gear"></i> Edit Lecturer</h2>
            <a href="manage_lecturers.php" class="btn btn-secondary">Back to Manage Lecturers</a>
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
                        <?php if ($lecturer['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($lecturer['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                                 style="width: 200px; height: 200px; font-size: 80px;">
                                <i class="bi bi-person-badge"></i>
                            </div>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($lecturer['full_name']); ?></h5>
                        <p class="text-muted">ID: <?php echo $lecturer['lecturer_id']; ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Lecturer Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="lecturer_id" class="form-label">Lecturer ID</label>
                                    <input type="text" class="form-control" id="lecturer_id" value="<?php echo $lecturer['lecturer_id']; ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($lecturer['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($lecturer['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($lecturer['username'] ?? ''); ?>" required>
                                    <small class="text-muted">Login username for the system</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($lecturer['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-3">
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
                                    <label for="program" class="form-label">Department Assigned *</label>
                                    <select class="form-select" id="program" name="program" required onchange="updateDepartmentCode()">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept['department_name']); ?>"
                                                    data-code="<?php echo htmlspecialchars($dept['department_code']); ?>"
                                                    <?php echo ($lecturer['program'] ?? '') == $dept['department_name'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Department Code</label>
                                    <input type="text" class="form-control" id="department" name="department" 
                                           value="<?php echo htmlspecialchars($lecturer['department']); ?>" 
                                           readonly style="background-color: #e9ecef; cursor: not-allowed;" 
                                           placeholder="Auto-filled">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="position" class="form-label">Position *</label>
                                    <input type="text" class="form-control" id="position" name="position" 
                                           value="<?php echo htmlspecialchars($lecturer['position']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="office" class="form-label">Office Location *</label>
                                <select class="form-select" id="office" name="office" required>
                                    <option value="">Select Office Location</option>
                                    <option value="Mzuzu Campus" <?php echo ($lecturer['office'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                    <option value="Lilongwe Campus" <?php echo ($lecturer['office'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                    <option value="Blantyre Campus" <?php echo ($lecturer['office'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="bio" class="form-label">Biography</label>
                                <textarea class="form-control" id="bio" name="bio" rows="3"><?php echo htmlspecialchars($lecturer['bio'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="profile_picture" class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_lecturer" class="btn btn-success">
                                    <i class="bi bi-save"></i> Update Lecturer
                                </button>
                                <a href="manage_lecturers.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateDepartmentCode() {
        const programSelect = document.getElementById('program');
        const departmentInput = document.getElementById('department');
        const selectedOption = programSelect.options[programSelect.selectedIndex];
        
        if (selectedOption.value) {
            const code = selectedOption.getAttribute('data-code');
            departmentInput.value = code;
        } else {
            departmentInput.value = '';
        }
    }
    </script>
</body>
</html>
