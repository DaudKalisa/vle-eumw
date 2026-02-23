<?php
// edit_student.php - Admin edit student details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$student_id = isset($_GET['id']) ? trim($_GET['id']) : '';

// Get student details with username
$stmt = $conn->prepare("SELECT s.*, u.username FROM students s LEFT JOIN users u ON s.student_id = u.related_student_id WHERE s.student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage_students.php');
    exit();
}

$student = $result->fetch_assoc();

// Get all departments/programs for dropdown
$departments = [];
$dept_query = "SELECT department_id, department_code, department_name FROM departments ORDER BY department_name";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username'] ?? '');
    $department = trim($_POST['department']);
    $program = trim($_POST['program'] ?? '');
    $year_of_study = (int)$_POST['year_of_study'];
    $campus = trim($_POST['campus'] ?? 'Mzuzu Campus');
    $year_of_registration = trim($_POST['year_of_registration'] ?? '');
    $semester = trim($_POST['semester'] ?? 'One');
    $gender = trim($_POST['gender'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $program_type = trim($_POST['program_type'] ?? 'degree');
    
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
            $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
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
    
    // Update student details
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, email = ?, department = ?, program = ?, year_of_study = ?, campus = ?, year_of_registration = ?, semester = ?, gender = ?, national_id = ?, phone = ?, address = ?, profile_picture = ?, program_type = ? WHERE student_id = ?");
    $stmt->bind_param("ssssissssssssss", $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $profile_picture, $program_type, $student_id);
    
    if ($stmt->execute()) {
        // Check if program_type changed - if so, update financial account
        if ($student['program_type'] !== $program_type) {
            // Calculate new expected total based on program type
            $application_fee = 5500;
            $registration_fee = 39500;
            
            switch ($program_type) {
                case 'degree':
                    $tuition = 500000;
                    break;
                case 'professional':
                    $tuition = 200000;
                    break;
                case 'masters':
                    $tuition = 1100000;
                    break;
                case 'doctorate':
                    $tuition = 2200000;
                    break;
                default:
                    $tuition = 500000; // default to degree
            }
            
            $new_expected_total = $application_fee + $registration_fee + $tuition;
            
            // Check if student_finances record exists
            $check_finance = $conn->prepare("SELECT student_id, total_paid FROM student_finances WHERE student_id = ?");
            $check_finance->bind_param("s", $student_id);
            $check_finance->execute();
            $finance_result = $check_finance->get_result();
            
            if ($finance_result->num_rows > 0) {
                // Update existing record
                $finance_data = $finance_result->fetch_assoc();
                $current_paid = $finance_data['total_paid'] ?? 0;
                $new_balance = $new_expected_total - $current_paid;
                $new_percentage = $new_expected_total > 0 ? round(($current_paid / $new_expected_total) * 100) : 0;
                
                // Calculate content access weeks
                $content_weeks = 0;
                if ($new_percentage >= 100) $content_weeks = 52;
                elseif ($new_percentage >= 75) $content_weeks = 13;
                elseif ($new_percentage >= 50) $content_weeks = 9;
                elseif ($new_percentage >= 25) $content_weeks = 4;
                
                $update_finance = $conn->prepare("UPDATE student_finances SET expected_total = ?, balance = ?, payment_percentage = ?, content_access_weeks = ? WHERE student_id = ?");
                $update_finance->bind_param("ddiis", $new_expected_total, $new_balance, $new_percentage, $content_weeks, $student_id);
                $update_finance->execute();
            } else {
                // Create new finance record
                $create_finance = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, ?, 0, ?, 0, 0)");
                $create_finance->bind_param("sdd", $student_id, $new_expected_total, $new_expected_total);
                $create_finance->execute();
            }
        }
        
        // Update user email and username if exists
        $user_stmt = $conn->prepare("UPDATE users SET email = ?, username = ? WHERE related_student_id = ?");
        $user_stmt->bind_param("sss", $email, $username, $student_id);
        $user_stmt->execute();
        
        $success = "Student details updated successfully!";
        
        // Refresh student data with username
        $stmt = $conn->prepare("SELECT s.*, u.username FROM students s LEFT JOIN users u ON s.student_id = u.related_student_id WHERE s.student_id = ?");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Failed to update student details.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-person-fill-gear"></i> Edit Student</h2>
            <a href="manage_students.php" class="btn btn-secondary">Back to Manage Students</a>
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
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Profile Picture</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($student['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                 class="img-fluid rounded-circle mb-3" 
                                 style="max-width: 200px; max-height: 200px; object-fit: cover;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                                 style="width: 200px; height: 200px; font-size: 80px;">
                                <i class="bi bi-person-circle"></i>
                            </div>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($student['full_name']); ?></h5>
                        <p class="text-muted">ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Student Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="student_id" class="form-label">Student ID</label>
                                    <input type="text" class="form-control" id="student_id" value="<?php echo htmlspecialchars($student['student_id']); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" 
                                           value="<?php echo htmlspecialchars($student['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($student['username'] ?? ''); ?>" required>
                                    <small class="text-muted">Login username for the system</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                                </div>
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
                                           value="<?php echo htmlspecialchars($student['national_id'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Program of Study *</label>
                                    <select class="form-select" id="department" name="department" required onchange="updateDepartmentField()">
                                        <option value="">Select Program</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept['department_id']); ?>" 
                                                    data-name="<?php echo htmlspecialchars($dept['department_name']); ?>"
                                                    <?php echo $student['department'] == $dept['department_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">e.g., Bachelors of Business Administration</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="program" class="form-label">Department *</label>
                                    <select class="form-select bg-light" id="program" name="program" required>
                                        <option value="<?php echo htmlspecialchars($student['program'] ?? ''); ?>" selected>
                                            <?php echo htmlspecialchars($student['program'] ?? 'Select Program First'); ?>
                                        </option>
                                    </select>
                                    <small class="text-muted">Auto-populated from Program of Study</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="program_type" class="form-label">Program Type *</label>
                                    <select class="form-select" id="program_type" name="program_type" required>
                                        <option value="degree" <?php echo ($student['program_type'] ?? 'degree') == 'degree' ? 'selected' : ''; ?>>Degree (K500,000)</option>
                                        <option value="professional" <?php echo ($student['program_type'] ?? '') == 'professional' ? 'selected' : ''; ?>>Professional (K200,000)</option>
                                        <option value="masters" <?php echo ($student['program_type'] ?? '') == 'masters' ? 'selected' : ''; ?>>Masters (K1,100,000)</option>
                                        <option value="doctorate" <?php echo ($student['program_type'] ?? '') == 'doctorate' ? 'selected' : ''; ?>>Doctorate (K2,200,000)</option>
                                    </select>
                                    <small class="text-muted">Determines tuition fees</small>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="campus" class="form-label">Campus *</label>
                                    <select class="form-select" id="campus" name="campus" required>
                                        <option value="Mzuzu Campus" <?php echo ($student['campus'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                        <option value="Lilongwe Campus" <?php echo ($student['campus'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                        <option value="Blantyre Campus" <?php echo ($student['campus'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="year_of_study" class="form-label">Year of Study *</label>
                                    <select class="form-select" id="year_of_study" name="year_of_study" required>
                                        <option value="1" <?php echo $student['year_of_study'] == 1 ? 'selected' : ''; ?>>Year 1</option>
                                        <option value="2" <?php echo $student['year_of_study'] == 2 ? 'selected' : ''; ?>>Year 2</option>
                                        <option value="3" <?php echo $student['year_of_study'] == 3 ? 'selected' : ''; ?>>Year 3</option>
                                        <option value="4" <?php echo $student['year_of_study'] == 4 ? 'selected' : ''; ?>>Year 4</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="year_of_registration" class="form-label">Year of Registration</label>
                                    <input type="number" class="form-control" id="year_of_registration" name="year_of_registration" 
                                           min="2000" max="<?php echo date('Y'); ?>" 
                                           value="<?php echo htmlspecialchars($student['year_of_registration'] ?? ''); ?>" 
                                           placeholder="e.g., <?php echo date('Y'); ?>">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="semester" class="form-label">Semester *</label>
                                    <select class="form-select" id="semester" name="semester" required>
                                        <option value="One" <?php echo ($student['semester'] ?? 'One') == 'One' ? 'selected' : ''; ?>>Semester One</option>
                                        <option value="Two" <?php echo ($student['semester'] ?? '') == 'Two' ? 'selected' : ''; ?>>Semester Two</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="profile_picture" class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_student" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Update Student
                                </button>
                                <a href="manage_students.php" class="btn btn-secondary">Cancel</a>
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
