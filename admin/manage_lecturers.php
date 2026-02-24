<?php
// manage_lecturers.php - Admin manage lecturers
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_lecturer'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $department = trim($_POST['department']);
        $program = trim($_POST['program'] ?? '');
        $position = trim($_POST['position']);
        $gender = trim($_POST['gender'] ?? '');
        $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null;
        $phone = trim($_POST['phone'] ?? '');
        $phone2 = trim($_POST['phone2'] ?? '');
        $phone3 = trim($_POST['phone3'] ?? '');
        // Combine phone numbers
        $all_phones = array_filter([$phone, $phone2, $phone3]);
        $phone_combined = implode(', ', $all_phones);
        $office = trim($_POST['office'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        // Check for duplicate username
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $error = "Username '$username' already exists. Please choose a different username.";
        } else {
            // Check for duplicate email
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Email '$email' is already registered. Please use a different email.";
            } else {
                // Add to lecturers table
                $stmt = $conn->prepare("INSERT INTO lecturers (full_name, email, department, program, position, gender, phone, office, bio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssss", $full_name, $email, $department, $program, $position, $gender, $phone_combined, $office, $bio);
                $stmt->execute();
                $lecturer_id = $conn->insert_id;

                // Create user account
                $password_hash = password_hash('password123', PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_lecturer_id, must_change_password) VALUES (?, ?, ?, 'lecturer', ?, 1)");
                $stmt->bind_param("sssi", $username, $email, $password_hash, $lecturer_id);
                $stmt->execute();

                // Send welcome email with credentials
                $temp_password = 'password123';
                if (isEmailEnabled()) {
                    sendLecturerWelcomeEmail($email, $full_name, $username, $temp_password, $department, $position);
                }

                header("Location: manage_lecturers.php?success=Lecturer+added+successfully");
                exit();
            }
        }
    } elseif (isset($_POST['delete_lecturer'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];

        // Delete submissions that reference assignments for courses taught by this lecturer
        try {
            $stmt = $conn->prepare("DELETE FROM vle_submissions WHERE assignment_id IN (SELECT assignment_id FROM vle_assignments WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?))");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete assignments that belong to courses taught by this lecturer
        try {
            $stmt = $conn->prepare("DELETE FROM vle_assignments WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete enrollments for courses taught by this lecturer (references vle_courses)
        try {
            $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete weekly content for courses taught by this lecturer (references vle_courses)
        try {
            $stmt = $conn->prepare("DELETE FROM vle_weekly_content WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete announcements for courses taught by this lecturer (if exists)
        try {
            $stmt = $conn->prepare("DELETE FROM vle_announcements WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete course materials for courses taught by this lecturer (if exists)
        try {
            $stmt = $conn->prepare("DELETE FROM vle_course_materials WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete live sessions for courses taught by this lecturer (if exists)
        try {
            $stmt = $conn->prepare("DELETE FROM vle_live_sessions WHERE course_id IN (SELECT course_id FROM vle_courses WHERE lecturer_id = ?)");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
        } catch (Exception $e) { /* Table may not exist */ }

        // Delete courses assigned to this lecturer
        $stmt = $conn->prepare("DELETE FROM vle_courses WHERE lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();

        // Delete from users associated with this lecturer
        $stmt = $conn->prepare("DELETE FROM users WHERE related_lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();

        // Finally delete the lecturer record
        $stmt = $conn->prepare("DELETE FROM lecturers WHERE lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();

        header("Location: manage_lecturers.php");
        exit();
    } elseif (isset($_POST['reset_password'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        if ($new_password === $confirm_password) {
            // Get lecturer info for email
            $lecturer_info_stmt = $conn->prepare("SELECT l.full_name, l.email FROM lecturers l WHERE l.lecturer_id = ?");
            $lecturer_info_stmt->bind_param("i", $lecturer_id);
            $lecturer_info_stmt->execute();
            $lecturer_info = $lecturer_info_stmt->get_result()->fetch_assoc();
            
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE related_lecturer_id = ?");
            $stmt->bind_param("si", $password_hash, $lecturer_id);
            
            if ($stmt->execute()) {
                // Send password reset notification email
                if (isEmailEnabled() && $lecturer_info) {
                    sendPasswordResetEmail($lecturer_info['email'], $lecturer_info['full_name'], $new_password, true);
                }
                $success = "Password reset successfully for " . htmlspecialchars($lecturer_info['full_name'] ?? "lecturer ID: $lecturer_id");
            } else {
                $error = "Failed to reset password.";
            }
        } else {
            $error = "Passwords do not match!";
        }
    } elseif (isset($_POST['assign_courses'])) {
        $lecturer_id = (int)$_POST['lecturer_id'];
        $course_ids = $_POST['course_ids'] ?? [];
        
        // First, unassign all courses from this lecturer
        $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = NULL WHERE lecturer_id = ?");
        $stmt->bind_param("i", $lecturer_id);
        $stmt->execute();
        
        // Then assign selected courses
        $assigned_count = 0;
        if (!empty($course_ids)) {
            $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
            foreach ($course_ids as $course_id) {
                $course_id = (int)$course_id;
                $stmt->bind_param("ii", $lecturer_id, $course_id);
                if ($stmt->execute()) {
                    $assigned_count++;
                }
            }
        }
        
        $success = "$assigned_count course(s) assigned successfully to lecturer!";
    }
}

// Get all lecturers with usernames (only lecturer role, excluding admin and finance)
$lecturers = [];
$result = $conn->query("SELECT l.*, u.username, u.role 
                        FROM lecturers l 
                        LEFT JOIN users u ON l.lecturer_id = u.related_lecturer_id 
                        WHERE (u.role = 'lecturer' OR u.role IS NULL)
                        ORDER BY l.full_name");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

// Get all departments
$departments = [];
$dept_result = $conn->query("SELECT * FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Get all courses for assignment modals
$all_courses = [];
$courses_result = $conn->query("SELECT course_id, course_code, course_name, program_of_study, year_of_study, lecturer_id FROM vle_courses WHERE is_active = TRUE ORDER BY program_of_study, year_of_study, course_code");
if ($courses_result) {
    while ($row = $courses_result->fetch_assoc()) {
        $all_courses[] = $row;
    }
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lecturers - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .card-header-lecturers {
            background: var(--vle-gradient-primary) !important;
            border: none;
            color: white;
        }
    </style>
</head>

<body>
    <?php 
    $breadcrumbs = [['title' => 'Manage Lecturers']];
    include 'header_nav.php'; 
    ?>
    
    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-person-badge me-2"></i>Manage Lecturers</h2>
            <div>
                <button type="button" class="btn btn-vle-accent" data-bs-toggle="modal" data-bs-target="#addLecturerModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Add New Lecturer
                </button>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
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

        <!-- Lecturers List -->
        <div class="card">
            <div class="card-header">
                <h5>All Lecturers (<?php echo count($lecturers); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($lecturers)): ?>
                    <p class="text-muted">No lecturers found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <tr>
                                        <td><?php echo $lecturer['lecturer_id']; ?></td>
                                        <td><?php echo htmlspecialchars($lecturer['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lecturer['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($lecturer['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lecturer['department']); ?></td>
                                        <td><?php echo htmlspecialchars($lecturer['position']); ?></td>
                                        <td>
                                            <a href="edit_lecturer.php?id=<?php echo $lecturer['lecturer_id']; ?>" class="btn btn-sm btn-success">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#assignCoursesModal<?php echo $lecturer['lecturer_id']; ?>">
                                                <i class="bi bi-book-fill"></i> Assign Courses
                                            </button>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo $lecturer['lecturer_id']; ?>">
                                                <i class="bi bi-key-fill"></i> Reset Password
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="lecturer_id" value="<?php echo $lecturer['lecturer_id']; ?>">
                                                <button type="submit" name="delete_lecturer" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    
                                    <!-- Reset Password Modal -->
                                    <div class="modal fade" id="resetPasswordModal<?php echo $lecturer['lecturer_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reset Password for <?php echo htmlspecialchars($lecturer['full_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="lecturer_id" value="<?php echo $lecturer['lecturer_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">New Password *</label>
                                                            <input type="password" class="form-control" name="new_password" required minlength="6">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Confirm Password *</label>
                                                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                                                        </div>
                                                        <small class="text-muted">Password must be at least 6 characters long.</small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="reset_password" class="btn btn-warning">Reset Password</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Assign Courses Modal -->
                                    <div class="modal fade" id="assignCoursesModal<?php echo $lecturer['lecturer_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header bg-info text-white">
                                                    <h5 class="modal-title"><i class="bi bi-book-fill"></i> Assign Courses to <?php echo htmlspecialchars($lecturer['full_name']); ?></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="lecturer_id" value="<?php echo $lecturer['lecturer_id']; ?>">
                                                        <div class="alert alert-info">
                                                            <i class="bi bi-info-circle"></i> Select courses to assign to this lecturer. Lecturers can teach courses from any department or program.
                                                        </div>
                                                        
                                                        <?php if (!empty($all_courses)): ?>
                                                        <div class="row">
                                                            <?php foreach ($all_courses as $course): ?>
                                                                <div class="col-md-6 mb-2">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="course_ids[]" 
                                                                               value="<?php echo $course['course_id']; ?>" 
                                                                               id="course<?php echo $course['course_id']; ?>_lec<?php echo $lecturer['lecturer_id']; ?>"
                                                                               <?php echo ($course['lecturer_id'] == $lecturer['lecturer_id']) ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label" for="course<?php echo $course['course_id']; ?>_lec<?php echo $lecturer['lecturer_id']; ?>">
                                                                            <strong><?php echo htmlspecialchars($course['course_code']); ?></strong> - 
                                                                            <?php echo htmlspecialchars($course['course_name']); ?>
                                                                            <br>
                                                                            <small class="text-muted">
                                                                                <?php echo htmlspecialchars($course['program_of_study']); ?> - Year <?php echo $course['year_of_study']; ?>
                                                                                <?php if ($course['lecturer_id'] && $course['lecturer_id'] != $lecturer['lecturer_id']): ?>
                                                                                    <span class="badge bg-warning">Assigned to other</span>
                                                                                <?php endif; ?>
                                                                            </small>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <?php else: ?>
                                                            <p class="text-muted">No courses available to assign.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="assign_courses" class="btn btn-info">
                                                            <i class="bi bi-save"></i> Save Course Assignments
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Lecturer Modal -->
    <div class="modal fade" id="addLecturerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Add New Lecturer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><h6 class="text-success">Personal Information</h6></div>
                            <div class="col-md-2">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="lec_first_name" name="first_name" required oninput="generateLecturerCredentials()">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="lec_middle_name" name="middle_name" oninput="generateLecturerCredentials()">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="lec_last_name" name="last_name" required oninput="generateLecturerCredentials()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" id="lec_username" name="username" required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            
                            <div class="col-12"><h6 class="text-success mt-2">Contact Information</h6></div>
                            <div class="col-md-3">
                                <label class="form-label">Location *</label>
                                <select class="form-select" name="office" required>
                                    <option value="">Select Location</option>
                                    <option value="Mzuzu Campus">Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="lec_email" name="email" required>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Phone Number 1</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Phone Number 2</label>
                                <input type="text" class="form-control" name="phone2">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Phone Number 3</label>
                                <input type="text" class="form-control" name="phone3">
                            </div>
                            
                            <div class="col-12"><h6 class="text-success mt-2">Professional Information</h6></div>
                            <div class="col-md-4">
                                <label class="form-label">Department *</label>
                                <select class="form-select" id="modal_program" name="program" required onchange="updateModalDepartmentCode()">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept['department_name']); ?>" 
                                                data-code="<?php echo htmlspecialchars($dept['department_code']); ?>">
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Department Code</label>
                                <input type="text" class="form-control bg-light" id="modal_department" name="department" readonly>
                                <small class="text-muted">Auto-filled from department</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Position *</label>
                                <select class="form-select" name="position" required>
                                    <option value="">Select Position</option>
                                    <option value="Associate Lecturer">Associate Lecturer</option>
                                    <option value="Lecturer">Lecturer</option>
                                    <option value="Senior Lecturer">Senior Lecturer</option>
                                    <option value="Head of Department">Head of Department</option>
                                </select>
                            </div>
                            
                            <div class="col-12"><h6 class="text-success mt-2">Additional Information</h6></div>
                            <div class="col-12">
                                <label class="form-label">Biography</label>
                                <textarea class="form-control" name="bio" rows="3"></textarea>
                                <small class="text-muted">Optional: Professional background and academic credentials</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <small class="text-muted me-auto">Default password: password123</small>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_lecturer" class="btn btn-success">
                            <i class="bi bi-plus-circle"></i> Add Lecturer
                        </button>
                    </div>
                </form>
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
    
    function updateModalDepartmentCode() {
        const programSelect = document.getElementById('modal_program');
        const departmentInput = document.getElementById('modal_department');
        const selectedOption = programSelect.options[programSelect.selectedIndex];
        
        if (selectedOption.value) {
            const code = selectedOption.getAttribute('data-code');
            departmentInput.value = code;
        } else {
            departmentInput.value = '';
        }
    }
    
    // Auto-generate username and email from first, middle, and last name
    function generateLecturerCredentials() {
        const firstName = document.getElementById('lec_first_name').value.trim().toLowerCase();
        const middleName = document.getElementById('lec_middle_name')?.value.trim().toLowerCase() || '';
        const lastName = document.getElementById('lec_last_name').value.trim().toLowerCase();
        
        if (firstName && lastName) {
            // Username: first initial + middle initial + surname (e.g., daud kalisa phiri = dkphiri)
            const middleInitial = middleName ? middleName.charAt(0) : '';
            const username = firstName.charAt(0) + middleInitial + lastName.replace(/\s+/g, '');
            document.getElementById('lec_username').value = username;
            
            // Email: username@exploitsonline.com
            document.getElementById('lec_email').value = username + '@exploitsonline.com';
        }
    }
    </script>
</body>
</html>