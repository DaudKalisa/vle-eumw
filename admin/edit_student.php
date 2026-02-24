<?php
// edit_student.php - Admin edit student details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

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
    
    // Validate year_of_registration - must be a valid year or null
    $year_of_registration = trim($_POST['year_of_registration'] ?? '');
    $year_of_registration = (!empty($year_of_registration) && is_numeric($year_of_registration)) ? (int)$year_of_registration : null;
    
    // Validate semester value - must be 'One' or 'Two' (ENUM constraint)
    $semester = trim($_POST['semester'] ?? 'One');
    $semester = in_array($semester, ['One', 'Two']) ? $semester : 'One';
    
    // Validate gender - must be 'Male', 'Female', or 'Other' (ENUM constraint)
    $gender = trim($_POST['gender'] ?? '');
    $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
    
    $national_id = strtoupper(trim($_POST['national_id'] ?? '')); // Auto-capitalize
    
    // Validate National ID - max 8 characters
    if (!empty($national_id) && strlen($national_id) > 8) {
        $error = "National ID must be 8 characters or less.";
    }
    
    // Check for duplicate National ID (if provided, exclude current student)
    if (!isset($error) && !empty($national_id)) {
        $nid_check = $conn->prepare("SELECT student_id FROM students WHERE national_id = ? AND student_id != ?");
        $nid_check->bind_param("ss", $national_id, $student_id);
        $nid_check->execute();
        if ($nid_check->get_result()->num_rows > 0) {
            $error = "This National ID '" . htmlspecialchars($national_id) . "' is already registered to another student.";
        }
        $nid_check->close();
    }
    
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate program_type - must match ENUM values
    $program_type = trim($_POST['program_type'] ?? 'degree');
    $program_type = in_array($program_type, ['degree', 'professional', 'masters', 'doctorate']) ? $program_type : 'degree';
    
    // Validate student_type - must match ENUM values
    $student_type = trim($_POST['student_type'] ?? 'new_student');
    $student_type = in_array($student_type, ['new_student', 'continuing']) ? $student_type : 'new_student';
    
    // Validate student_status - must match ENUM values
    $student_status = trim($_POST['student_status'] ?? 'active');
    $student_status = in_array($student_status, ['active', 'graduated', 'suspended', 'withdrawn']) ? $student_status : 'active';
    
    // Calculate academic_level from year_of_study and semester
    $academic_level = $year_of_study . '/' . ($semester === 'Two' ? '2' : '1');
    
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
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, email = ?, department = ?, program = ?, year_of_study = ?, campus = ?, year_of_registration = ?, semester = ?, gender = ?, national_id = ?, phone = ?, address = ?, profile_picture = ?, program_type = ?, student_type = ?, student_status = ?, academic_level = ? WHERE student_id = ?");
    $stmt->bind_param("ssssisssssssssssss", $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $profile_picture, $program_type, $student_type, $student_status, $academic_level, $student_id);
    
    if ($stmt->execute()) {
        // Check if program_type or student_type changed - if so, update financial account
        if ($student['program_type'] !== $program_type || ($student['student_type'] ?? 'new_student') !== $student_type) {
            // Get fee settings
            $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
            $fee_settings = $fee_query->fetch_assoc();
            
            // Calculate new expected total based on program type and student type
            // Continuing students are exempt from application fee
            $application_fee = ($student_type === 'continuing') ? 0 : ($fee_settings['application_fee'] ?? 5500);
            
            // Use student_type and program_type based registration fee
            // Professional courses: K10,000 flat rate
            // Other programs: K39,500 for new, K35,000 for continuing
            if ($program_type === 'professional') {
                $registration_fee = 10000; // Professional course flat rate
            } else {
                $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                $registration_fee = ($student_type === 'continuing') ? $continuing_reg_fee : $new_student_reg_fee;
            }
            
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
        
        // Redirect back to manage students page
        header("Location: manage_students.php?success=" . urlencode("Student details updated successfully!"));
        exit();
    } else {
        $error = "Failed to update student details.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>

<body>
    <?php 
    $currentPage = 'edit_student';
    $pageTitle = 'Edit Student';
    $breadcrumbs = [['title' => 'Students', 'url' => 'manage_students.php'], ['title' => 'Edit Student']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="container mt-4">

        <?php if (isset($success)): ?>
            <div class="alert alert-success fade show">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger fade show">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Profile Card -->
            <div class="col-lg-4 col-md-5">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-primary text-white text-center">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Profile</h5>
                        </div>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($student['profile_picture']): ?>
                            <img src="../uploads/profiles/<?php echo htmlspecialchars($student['profile_picture']); ?>" class="img-fluid rounded-circle mb-3 border border-3 border-primary" style="max-width: 180px; max-height: 180px; object-fit: cover;" alt="Profile Picture">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 180px; height: 180px; font-size: 70px;">
                                <i class="bi bi-person-circle"></i>
                            </div>
                        <?php endif; ?>
                        <h5 class="fw-bold mt-2 mb-0"><?php echo htmlspecialchars($student['full_name']); ?></h5>
                        <div class="text-muted small">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></div>
                                                <!-- Profile Actions -->
                                                <div class="d-grid gap-2 my-3">
                                                    <button type="button" class="btn btn-outline-warning btn-sm w-100" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"><i class="bi bi-key-fill"></i> Reset Password</button>
                                                <!-- Reset Password Modal -->
                                                <div class="modal fade" id="resetPasswordModal" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-warning">
                                                                <h5 class="modal-title text-white"><i class="bi bi-key-fill"></i> Reset Password</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST" action="manage_students.php">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student['student_id']); ?>">
                                                                    <div class="alert alert-info">
                                                                        <strong>Student:</strong> <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                                    </div>
                                                                    <div class="alert alert-warning">
                                                                        <i class="bi bi-exclamation-triangle"></i> This will reset the password to the <strong>default password</strong> for this student.<br>
                                                                        <strong>Default password:</strong> <span class="text-danger">password123</span>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="reset_password" class="btn btn-warning">
                                                                        <i class="bi bi-check-circle"></i> Reset to Default
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                </div>

                                                <!-- Settings Modal -->
                                                <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-primary text-white">
                                                                <h5 class="modal-title" id="settingsModalLabel"><i class="bi bi-palette"></i> Profile Theme Settings</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <form id="themeSettingsForm">
                                                                <div class="modal-body">
                                                                    <label class="form-label">Choose Color Theme</label>
                                                                    <div class="d-flex flex-wrap gap-2">
                                                                        <button type="button" class="theme-btn btn btn-light border" data-theme="default" style="background:#f8f9fa;">Default</button>
                                                                        <button type="button" class="theme-btn btn btn-dark border" data-theme="dark" style="background:#181a1b; color:#fff;">Dark</button>
                                                                        <button type="button" class="theme-btn btn border" data-theme="blue" style="background:#1e3c72; color:#fff;">Blue</button>
                                                                        <button type="button" class="theme-btn btn border" data-theme="green" style="background:#43e97b; color:#222;">Green</button>
                                                                        <button type="button" class="theme-btn btn border" data-theme="pink" style="background:#fa709a; color:#fff;">Pink</button>
                                                                    </div>
                                                                    <div class="form-text mt-2">Theme is saved for your next login.</div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                <script>
                                                // Theme switching logic
                                                document.addEventListener('DOMContentLoaded', function() {
                                                    const themeBtns = document.querySelectorAll('.theme-btn');
                                                    themeBtns.forEach(btn => {
                                                        btn.addEventListener('click', function() {
                                                            const theme = this.getAttribute('data-theme');
                                                            localStorage.setItem('vle_profile_theme', theme);
                                                            applyTheme(theme);
                                                        });
                                                    });
                                                    function applyTheme(theme) {
                                                        document.body.classList.remove('theme-dark', 'theme-blue', 'theme-green', 'theme-pink');
                                                        switch (theme) {
                                                            case 'dark': document.body.classList.add('theme-dark'); break;
                                                            case 'blue': document.body.classList.add('theme-blue'); break;
                                                            case 'green': document.body.classList.add('theme-green'); break;
                                                            case 'pink': document.body.classList.add('theme-pink'); break;
                                                            default: break;
                                                        }
                                                    }
                                                    // Load saved theme
                                                    const savedTheme = localStorage.getItem('vle_profile_theme');
                                                    if (savedTheme) applyTheme(savedTheme);
                                                });
                                                </script>
                                                <style>
                                                body.theme-dark { background: #181a1b !important; color: #f1f1f1 !important; }
                                                body.theme-dark .card, body.theme-dark .modal-content { background: #23272b !important; color: #f1f1f1 !important; }
                                                body.theme-dark .navbar, body.theme-dark .card-header, body.theme-dark .modal-header { background: #111827 !important; color: #fff !important; }
                                                body.theme-blue { background: #e8f0fe !important; color: #1e3c72 !important; }
                                                body.theme-blue .card-header, body.theme-blue .modal-header { background: #1e3c72 !important; color: #fff !important; }
                                                body.theme-blue .card, body.theme-blue .modal-content { background: #fafdff !important; color: #1e3c72 !important; }
                                                body.theme-green { background: #e6fbe8 !important; color: #222 !important; }
                                                body.theme-green .card-header, body.theme-green .modal-header { background: #43e97b !important; color: #222 !important; }
                                                body.theme-green .card, body.theme-green .modal-content { background: #fafdff !important; color: #222 !important; }
                                                body.theme-pink { background: #fff0f6 !important; color: #fa709a !important; }
                                                body.theme-pink .card-header, body.theme-pink .modal-header { background: #fa709a !important; color: #fff !important; }
                                                body.theme-pink .card, body.theme-pink .modal-content { background: #fffafd !important; color: #fa709a !important; }
                                                </style>
                        <!-- Academic Info Summary Card -->
                        <div class="card mt-4 text-start shadow-sm">
                            <div class="card-header bg-info text-white py-2 px-3">
                                <span class="fw-semibold"><i class="bi bi-mortarboard"></i> Academic Info</span>
                                <button class="btn btn-sm btn-light float-end py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#academicInfoCollapse" aria-expanded="true" aria-controls="academicInfoCollapse">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse show" id="academicInfoCollapse">
                                <div class="card-body py-2 px-3">
                                    <ul class="list-unstyled mb-0">
                                        <li><strong>Program of Study:</strong> <?php echo htmlspecialchars($student['department_name'] ?? $student['department'] ?? ''); ?></li>
                                        <li><strong>Department:</strong> <?php echo htmlspecialchars($student['program'] ?? ''); ?></li>
                                        <li><strong>Program Type:</strong> <?php echo htmlspecialchars(ucfirst($student['program_type'] ?? '')); ?></li>
                                        <li><strong>Campus:</strong> <?php echo htmlspecialchars($student['campus'] ?? ''); ?></li>
                                        <li><strong>Year of Study:</strong> <?php echo htmlspecialchars($student['year_of_study'] ?? ''); ?></li>
                                        <li><strong>Year of Registration:</strong> <?php echo htmlspecialchars($student['year_of_registration'] ?? ''); ?></li>
                                        <li><strong>Semester:</strong> <?php echo htmlspecialchars($student['semester'] ?? ''); ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="profile_picture" class="form-label">Change Profile Picture</label>
                            <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                            <small class="text-muted">JPG, JPEG, PNG, GIF (Max 5MB)</small>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Edit Form Card -->
            <div class="col-lg-8 col-md-7">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Edit Student Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" autocomplete="off">
                            <!-- Personal Info -->
                            <h6 class="text-primary border-bottom pb-1 mb-3">Personal Information</h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($student['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($student['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($student['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($student['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="national_id" class="form-label">National ID Number <small class="text-muted">(Max 8 chars)</small></label>
                                    <input type="text" class="form-control" id="national_id" name="national_id" value="<?php echo htmlspecialchars($student['national_id'] ?? ''); ?>" maxlength="8" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                                    <div class="form-text">Must be unique. No duplicates allowed.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <!-- Academic Info -->
                            <h6 class="text-primary border-bottom pb-1 mb-3 mt-4">Academic Information</h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Department *</label>
                                    <select class="form-select" id="department" name="department" required onchange="updateDepartmentField()">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept['department_id']); ?>" data-name="<?php echo htmlspecialchars($dept['department_name']); ?>" <?php echo $student['department'] == $dept['department_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['department_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="program" class="form-label">Program *</label>
                                    <select class="form-select bg-light" id="program" name="program" required>
                                        <option value="<?php echo htmlspecialchars($student['program'] ?? ''); ?>" selected><?php echo htmlspecialchars($student['program'] ?? 'Select Department First'); ?></option>
                                    </select>
                                    <small class="text-muted">e.g. Bachelor of Business Administration</small>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="program_type" class="form-label">Program Type *</label>
                                    <select class="form-select" id="program_type" name="program_type" required onchange="updateDepartmentField(); updateFeeDisplay();">
                                        <option value="degree" <?php echo ($student['program_type'] ?? 'degree') == 'degree' ? 'selected' : ''; ?>>Degree (K500,000)</option>
                                        <option value="professional" <?php echo ($student['program_type'] ?? '') == 'professional' ? 'selected' : ''; ?>>Professional (K200,000)</option>
                                        <option value="masters" <?php echo ($student['program_type'] ?? '') == 'masters' ? 'selected' : ''; ?>>Masters (K1,100,000)</option>
                                        <option value="doctorate" <?php echo ($student['program_type'] ?? '') == 'doctorate' ? 'selected' : ''; ?>>Doctorate (K2,200,000)</option>
                                    </select>
                                    <small class="text-muted">Determines tuition fees</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="campus" class="form-label">Campus *</label>
                                    <select class="form-select" id="campus" name="campus" required>
                                        <option value="Mzuzu Campus" <?php echo ($student['campus'] ?? '') == 'Mzuzu Campus' ? 'selected' : ''; ?>>Mzuzu Campus</option>
                                        <option value="Lilongwe Campus" <?php echo ($student['campus'] ?? '') == 'Lilongwe Campus' ? 'selected' : ''; ?>>Lilongwe Campus</option>
                                        <option value="Blantyre Campus" <?php echo ($student['campus'] ?? '') == 'Blantyre Campus' ? 'selected' : ''; ?>>Blantyre Campus</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
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
                                    <input type="number" class="form-control" id="year_of_registration" name="year_of_registration" min="2000" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($student['year_of_registration'] ?? ''); ?>" placeholder="e.g., <?php echo date('Y'); ?>">
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="semester" class="form-label">Semester *</label>
                                    <select class="form-select" id="semester" name="semester" required>
                                        <option value="One" <?php echo ($student['semester'] ?? 'One') == 'One' ? 'selected' : ''; ?>>Semester One</option>
                                        <option value="Two" <?php echo ($student['semester'] ?? '') == 'Two' ? 'selected' : ''; ?>>Semester Two</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="student_type" class="form-label">Student Type *</label>
                                    <select class="form-select" id="student_type" name="student_type" required onchange="updateFeeDisplay()">
                                        <option value="new_student" <?php echo ($student['student_type'] ?? 'new_student') == 'new_student' ? 'selected' : ''; ?>>New Student</option>
                                        <option value="continuing" <?php echo ($student['student_type'] ?? '') == 'continuing' ? 'selected' : ''; ?>>Continuing Student</option>
                                    </select>
                                    <small class="text-muted" id="fee_info">
                                        <?php
                                        $st = $student['student_type'] ?? 'new_student';
                                        $pt = $student['program_type'] ?? 'degree';
                                        $reg = ($pt === 'professional') ? '10,000' : (($st === 'continuing') ? '35,000' : '39,500');
                                        $app = ($st === 'continuing') ? 'Exempt' : '5,500';
                                        $tuition = ($pt === 'professional') ? '200,000' : (($pt === 'masters') ? '1,100,000' : (($pt === 'doctorate') ? '2,200,000' : '500,000'));
                                        echo "Registration: K$reg | App Fee: K$app | Tuition: K$tuition";
                                        ?>
                                    </small>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="academic_level" class="form-label">Academic Level</label>
                                    <input type="text" class="form-control bg-light" id="academic_level" name="academic_level" 
                                           value="<?php echo htmlspecialchars($student['academic_level'] ?? ($student['year_of_study'] . '/' . ($student['semester'] === 'Two' ? '2' : '1'))); ?>" readonly>
                                    <small class="text-muted">Format: Year/Semester (e.g., 1/1, 2/2)</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="student_status" class="form-label">Student Status</label>
                                    <select class="form-select" id="student_status" name="student_status">
                                        <option value="active" <?php echo ($student['student_status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="graduated" <?php echo ($student['student_status'] ?? '') == 'graduated' ? 'selected' : ''; ?>>Graduated (Graduand)</option>
                                        <option value="suspended" <?php echo ($student['student_status'] ?? '') == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        <option value="withdrawn" <?php echo ($student['student_status'] ?? '') == 'withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
                                    </select>
                                </div>
                            </div>
                            <!-- Account Info -->
                            <h6 class="text-primary border-bottom pb-1 mb-3 mt-4">Account Information</h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($student['username'] ?? ''); ?>" required>
                                    <small class="text-muted">Login username for the system</small>
                                </div>
                            </div>
                            <!-- Address -->
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                            </div>
                            <!-- Sticky Buttons -->
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end sticky-bottom bg-white pt-3 pb-2" style="z-index:2;">
                                <button type="submit" name="update_student" class="btn btn-primary px-4">
                                    <i class="bi bi-save"></i> Update Student
                                </button>
                                <a href="manage_students.php" class="btn btn-secondary px-4">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update fee display based on program type and student type
        function updateFeeDisplay() {
            const studentType = document.getElementById('student_type');
            const programType = document.getElementById('program_type');
            const feeInfo = document.getElementById('fee_info');
            
            if (studentType && programType && feeInfo) {
                let regFee = '39,500';
                let appFee = '5,500';
                let tuition = '500,000';
                
                // Check if professional course - flat K10,000 registration
                if (programType.value === 'professional') {
                    regFee = '10,000';
                    tuition = '200,000';
                } else if (studentType.value === 'continuing') {
                    regFee = '35,000';
                }
                
                // Continuing students exempt from application fee
                if (studentType.value === 'continuing') {
                    appFee = 'Exempt';
                }
                
                // Update tuition based on program type
                switch(programType.value) {
                    case 'professional': tuition = '200,000'; break;
                    case 'masters': tuition = '1,100,000'; break;
                    case 'doctorate': tuition = '2,200,000'; break;
                    default: tuition = '500,000';
                }
                
                feeInfo.textContent = 'Registration: K' + regFee + ' | App Fee: K' + appFee + ' | Tuition: K' + tuition;
            }
        }
        
        function updateDepartmentField() {
            const departmentSelect = document.getElementById('department');
            const programSelect = document.getElementById('program');
            const programType = document.getElementById('program_type');
            
            if (!departmentSelect.value) {
                programSelect.innerHTML = '<option value="">Select Department First</option>';
                return;
            }
            
            // Get the department name
            const selectedOption = departmentSelect.options[departmentSelect.selectedIndex];
            const deptName = selectedOption.getAttribute('data-name');
            const type = programType.value;
            
            // Generate proper program name based on program type
            let programPrefix = 'Bachelor of';
            switch(type) {
                case 'professional':
                    programPrefix = 'Professional Certificate in';
                    break;
                case 'masters':
                    programPrefix = 'Master of';
                    break;
                case 'doctorate':
                    programPrefix = 'Doctor of Philosophy in';
                    break;
                default:
                    programPrefix = 'Bachelor of';
            }
            
            const fullProgramName = programPrefix + ' ' + deptName;
            
            // Update program dropdown
            programSelect.innerHTML = '<option value="' + fullProgramName + '" selected>' + fullProgramName + '</option>';
        }
    </script>
</body>
</html>
