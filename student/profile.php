<?php
// profile.php - Student profile management
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get student details with department info
$stmt = $conn->prepare("SELECT s.*, d.department_name, d.department_code 
                        FROM students s 
                        LEFT JOIN departments d ON s.department = d.department_id 
                        WHERE s.student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get all departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

// Get enrolled courses/programs
$enrolled_courses = [];
$courses_query = "SELECT c.course_code, c.course_name, e.enrollment_date 
                  FROM vle_enrollments e 
                  JOIN vle_courses c ON e.course_id = c.course_id 
                  WHERE e.student_id = ? 
                  ORDER BY e.enrollment_date DESC";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $enrolled_courses[] = $row;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $program = trim($_POST['program'] ?? '');
    
    // Handle profile picture upload
    $profile_picture = $student['profile_picture'];
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
                $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
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
        $stmt = $conn->prepare("UPDATE students SET phone = ?, address = ?, gender = ?, national_id = ?, profile_picture = ?, department = ?, program = ? WHERE student_id = ?");
        $stmt->bind_param("ssssssss", $phone, $address, $gender, $national_id, $profile_picture, $department, $program, $student_id);
        
        if ($stmt->execute()) {
            if (!isset($success)) $success = "Profile updated successfully!";
            // Refresh student data with department info
            $stmt = $conn->prepare("SELECT s.*, d.department_name, d.department_code 
                                    FROM students s 
                                    LEFT JOIN departments d ON s.department = d.department_id 
                                    WHERE s.student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Failed to update profile.";
        }
    }
}

$user = getCurrentUser();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-person-circle"></i> My Profile</h2>
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
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

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-image"></i> Profile Picture</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($student['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3 shadow" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover; border: 4px solid #0d6efd;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow"
                                 style="width: 200px; height: 200px; font-size: 80px; border: 4px solid #6c757d;">
                                <i class="bi bi-person-circle"></i>
                            </div>
                        <?php endif; ?>
                        <h4><?php echo htmlspecialchars($student['full_name']); ?></h4>
                        <p class="text-muted mb-1">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                        <p class="text-muted mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></p>
                    </div>
                </div>

                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Academic Info</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <strong>Program of Study:</strong><br>
                            <?php echo htmlspecialchars($student['department_name'] ?? 'Not Set'); ?>
                        </p>
                        <p class="mb-2">
                            <strong>Department:</strong><br>
                            <?php echo htmlspecialchars($student['program'] ?? 'Not Set'); ?>
                        </p>
                        <p class="mb-2">
                            <strong>Campus:</strong><br>
                            <?php 
                            $campus = $student['campus'] ?? 'Not Set';
                            $campus_code = '';
                            if ($campus == 'Mzuzu Campus') $campus_code = 'MZ';
                            elseif ($campus == 'Lilongwe Campus') $campus_code = 'LL';
                            elseif ($campus == 'Blantyre Campus') $campus_code = 'BT';
                            echo htmlspecialchars($campus);
                            if ($campus_code) echo ' <span class="badge bg-primary">' . $campus_code . '</span>';
                            ?>
                        </p>
                        <p class="mb-2">
                            <strong>Year of Study:</strong> Year <?php echo $student['year_of_study']; ?> &nbsp;&nbsp;|&nbsp;&nbsp; 
                            <strong>Semester:</strong> Semester <?php echo htmlspecialchars($student['semester'] ?? 'One'); ?>
                        </p>
                        <p class="mb-0">
                            <strong>Year of Registration:</strong><br>
                            <?php echo htmlspecialchars($student['year_of_registration'] ?? 'Not Set'); ?>
                        </p>
                    </div>
                </div>

                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-book-half"></i> Courses Taken (<?php echo count($enrolled_courses); ?>)</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($enrolled_courses)): ?>
                            <p class="text-muted mb-0">No Courses enrolled yet.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($enrolled_courses as $course): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex w-100 justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar-event"></i> 
                                                    Enrolled: <?php echo date('M d, Y', strtotime($course['enrollment_date'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Profile</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" 
                                       value="<?php echo htmlspecialchars($student['full_name']); ?>" disabled>
                                <small class="text-muted">Contact administrator to change your name</small>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" 
                                       value="<?php echo htmlspecialchars($student['email']); ?>" disabled>
                                <small class="text-muted">Contact administrator to change your email</small>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Program of Study</label>
                                    <select class="form-select" id="department" name="department" disabled>
                                        <option value="<?php echo htmlspecialchars($student['department'] ?? ''); ?>" selected>
                                            <?php echo htmlspecialchars($student['department_name'] ?? 'Not Set'); ?> (Locked)
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="program" class="form-label">Department</label>
                                    <select class="form-select bg-light" id="program" name="program" disabled>
                                        <option value="<?php echo htmlspecialchars($student['program'] ?? ''); ?>" selected>
                                            <?php echo htmlspecialchars($student['program'] ?? 'Not Set'); ?> (Locked)
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" 
                                       placeholder="Enter your phone number">
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($student['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($student['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($student['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="national_id" class="form-label">National ID Number</label>
                                    <input type="text" class="form-control" id="national_id" name="national_id" 
                                           value="<?php echo htmlspecialchars($student['national_id'] ?? ''); ?>" 
                                           placeholder="Enter your National ID">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" 
                                          placeholder="Enter your address"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
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
                                <button type="submit" name="update_profile" class="btn btn-primary btn-lg">
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
    <script>
        function updateDepartmentField() {
            const programSelect = document.getElementById('department');
            const departmentSelect = document.getElementById('program');
            
            if (!programSelect.value) {
                departmentSelect.innerHTML = '<option value="">Select Program First</option>';
                return;
            }
            
            // Get the full program name
            const selectedOption = programSelect.options[programSelect.selectedIndex];
            const fullProgramName = selectedOption.getAttribute('data-name');
            
            // Remove common prefixes
            let departmentName = fullProgramName;
            const prefixes = ['Bachelor of ', 'Bachelors of ', 'Masters of ', 'Master of ', 'Doctorate in ', 'PhD in ', 'Certificate in ', 'Diploma in '];
            
            for (let prefix of prefixes) {
                if (departmentName.startsWith(prefix)) {
                    departmentName = departmentName.substring(prefix.length);
                    break;
                }
            }
            
            // Update department dropdown
            departmentSelect.innerHTML = '<option value="' + departmentName + '" selected>' + departmentName + '</option>';
        }
    </script>
</body>
</html>
