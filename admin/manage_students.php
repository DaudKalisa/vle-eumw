<?php
// manage_students.php - Admin manage students
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

// Handle CSV template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'First Name', 'Middle Name', 'Last Name', 'Username', 'Gender', 'National ID', 
        'Phone', 'Address', 'Campus', 'Program of Study (Dept ID)', 'Department', 
        'Program Type', 'Year of Registration', 'Year of Study', 'Semester', 
        'Entry Type', 'Email'
    ]);
    
    // Sample row
    fputcsv($output, [
        'John', 'Paul', 'Banda', 'jbanda', 'Male', 'MZ12345678', 
        '+265991234567', '123 Main St, Mzuzu', 'Mzuzu Campus', '1', 'Business Administration', 
        'degree', '2026', '1', 'One', 'NE', 'jbanda@example.com'
    ]);
    
    fclose($output);
    exit();
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
        $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
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
        $entry_type = trim($_POST['entry_type'] ?? 'NE');
        
        // Validate department exists
        $dept_query = $conn->prepare("SELECT department_id, department_code, department_name FROM departments WHERE department_id = ?");
        $dept_query->bind_param("i", $department);
        $dept_query->execute();
        $dept_result = $dept_query->get_result();
        
        if ($dept_result->num_rows === 0) {
            $error = "Invalid department selected. Please select a valid department.";
        } else {
            $dept_data = $dept_result->fetch_assoc();
            $dept_code = $dept_data['department_code'];
            
            // Auto-generate Student ID
            // Extract campus code
            $campus_code = 'MZ';
            if (strpos($campus, 'Lilongwe') !== false) {
                $campus_code = 'LL';
            } elseif (strpos($campus, 'Blantyre') !== false) {
                $campus_code = 'BT';
            }
            
            // Get last 2 digits of year
            $year_short = substr($year_of_registration, -2);
            
            // Get next sequential number for this combination
            $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
            $count_query = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
            $next_num = ($count_query->fetch_assoc()['count'] ?? 0) + 1;
            $sequential = str_pad($next_num, 4, '0', STR_PAD_LEFT);
            
            $student_id = $prefix . $sequential;

            try {
                // Add to students table
                $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssissssssssss", $student_id, $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type);
                $stmt->execute();

                // Create user account
                $password_hash = password_hash('password123', PASSWORD_DEFAULT); // Default password
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id) VALUES (?, ?, ?, 'student', ?)");
                $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                $stmt->execute();
                
                // Create student_finances record with invoice
                // Get fee settings
                $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                $fee_settings = $fee_query->fetch_assoc();
                $application_fee = $fee_settings['application_fee'] ?? 5500;
                $registration_fee = $fee_settings['registration_fee'] ?? 39500;
                
                // Determine tuition based on program type
                $tuition = 500000; // Default degree
                switch ($program_type) {
                    case 'professional':
                        $tuition = 200000;
                        break;
                    case 'masters':
                        $tuition = 1100000;
                        break;
                    case 'doctorate':
                        $tuition = 2200000;
                        break;
                }
                
                $expected_total = $application_fee + $registration_fee + $tuition;
                $expected_tuition = $tuition;
                
                // Insert student_finances record
                $stmt = $conn->prepare("INSERT INTO student_finances 
                    (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) 
                    VALUES (?, ?, ?, 0, ?, 0, 0)");
                $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                $stmt->execute();

                $success = "Student added successfully! Student ID: $student_id | Invoice Created: K" . number_format($expected_total);
            } catch (mysqli_sql_exception $e) {
                // Rollback: delete student if user creation failed
                $conn->query("DELETE FROM students WHERE student_id = '$student_id'");
                $error = "Failed to create student: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_student'])) {
        $student_id = $_POST['student_id'];

        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Delete related records in order (child tables first)
            // 1. Delete VLE submissions
            $stmt = $conn->prepare("DELETE FROM vle_submissions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 2. Delete VLE enrollments
            $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 3. Delete payment transactions
            $stmt = $conn->prepare("DELETE FROM payment_transactions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 4. Delete payment submissions
            $stmt = $conn->prepare("DELETE FROM payment_submissions WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 5. Delete course registration requests
            $stmt = $conn->prepare("DELETE FROM course_registration_requests WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 6. Delete student finances
            $stmt = $conn->prepare("DELETE FROM student_finances WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // 7. Delete user account
            $stmt = $conn->prepare("DELETE FROM users WHERE related_student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();

            // 8. Finally delete student record
            $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $success = "Student and all related records deleted successfully!";
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = "Failed to delete student: " . $e->getMessage();
        }
    } elseif (isset($_POST['reset_password'])) {
        $student_id = trim($_POST['student_id']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        // Validate inputs
        if (empty($student_id)) {
            $error = "Student ID is required!";
        } elseif (empty($new_password)) {
            $error = "Password is required!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } else {
            // Check if student exists
            $check_stmt = $conn->prepare("SELECT u.user_id, s.full_name 
                                          FROM users u 
                                          JOIN students s ON u.related_student_id = s.student_id 
                                          WHERE u.related_student_id = ? AND u.role = 'student'");
            $check_stmt->bind_param("s", $student_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                $error = "No user account found for student ID: " . htmlspecialchars($student_id);
            } else {
                $student_info = $check_result->fetch_assoc();
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE related_student_id = ? AND role = 'student'");
                $stmt->bind_param("ss", $password_hash, $student_id);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $success = "Password reset successfully for " . htmlspecialchars($student_info['full_name']) . " (ID: " . htmlspecialchars($student_id) . ")";
                } else {
                    $error = "Failed to reset password. No changes were made.";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    } elseif (isset($_POST['bulk_upload'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            
            $upload_success = 0;
            $upload_errors = [];
            $row_num = 0;
            
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                $row_num++;
                
                try {
                    // Extract data from CSV
                    $first_name = trim($data[0] ?? '');
                    $middle_name = trim($data[1] ?? '');
                    $last_name = trim($data[2] ?? '');
                    $username = trim($data[3] ?? '');
                    $gender = trim($data[4] ?? '');
                    $national_id = trim($data[5] ?? '');
                    $phone = trim($data[6] ?? '');
                    $address = trim($data[7] ?? '');
                    $campus = trim($data[8] ?? 'Mzuzu Campus');
                    $department = trim($data[9] ?? '');
                    $program = trim($data[10] ?? '');
                    $program_type = trim($data[11] ?? 'degree');
                    $year_of_registration = trim($data[12] ?? date('Y'));
                    $year_of_study = (int)($data[13] ?? 1);
                    $semester = trim($data[14] ?? 'One');
                    $entry_type = trim($data[15] ?? 'NE');
                    $email = trim($data[16] ?? '');
                    
                    // Validate required fields
                    if (empty($first_name) || empty($last_name) || empty($username) || empty($department)) {
                        $upload_errors[] = "Row $row_num: Missing required fields (First Name, Last Name, Username, or Department)";
                        continue;
                    }
                    
                    // Create full name
                    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                    
                    // Auto-generate Student ID
                    $dept_query = $conn->prepare("SELECT department_code FROM departments WHERE department_id = ?");
                    $dept_query->bind_param("s", $department);
                    $dept_query->execute();
                    $dept_result = $dept_query->get_result();
                    $dept_code = $dept_result->fetch_assoc()['department_code'] ?? 'UNK';
                    
                    // Extract campus code
                    $campus_code = 'MZ';
                    if (strpos($campus, 'Lilongwe') !== false) {
                        $campus_code = 'LL';
                    } elseif (strpos($campus, 'Blantyre') !== false) {
                        $campus_code = 'BT';
                    }
                    
                    // Get last 2 digits of year
                    $year_short = substr($year_of_registration, -2);
                    
                    // Get next sequential number
                    $prefix = $dept_code . '/' . $year_short . '/' . $campus_code . '/' . $entry_type . '/';
                    $count_query = $conn->query("SELECT COUNT(*) as count FROM students WHERE student_id LIKE '" . $conn->real_escape_string($prefix) . "%'");
                    $next_num = ($count_query->fetch_assoc()['count'] ?? 0) + 1;
                    $sequential = str_pad($next_num, 4, '0', STR_PAD_LEFT);
                    $student_id = $prefix . $sequential;
                    
                    // Insert student
                    $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, email, department, program, year_of_study, campus, year_of_registration, semester, gender, national_id, phone, address, program_type, entry_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssissssssssss", $student_id, $full_name, $email, $department, $program, $year_of_study, $campus, $year_of_registration, $semester, $gender, $national_id, $phone, $address, $program_type, $entry_type);
                    $stmt->execute();
                    
                    // Create user account
                    $password_hash = password_hash('password123', PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_student_id) VALUES (?, ?, ?, 'student', ?)");
                    $stmt->bind_param("ssss", $username, $email, $password_hash, $student_id);
                    $stmt->execute();
                    
                    // Create student_finances record with invoice
                    $fee_query = $conn->query("SELECT * FROM fee_settings LIMIT 1");
                    $fee_settings = $fee_query->fetch_assoc();
                    $application_fee = $fee_settings['application_fee'] ?? 5500;
                    $registration_fee = $fee_settings['registration_fee'] ?? 39500;
                    
                    // Determine tuition based on program type
                    $tuition = 500000; // Default degree
                    switch ($program_type) {
                        case 'professional':
                            $tuition = 200000;
                            break;
                        case 'masters':
                            $tuition = 1100000;
                            break;
                        case 'doctorate':
                            $tuition = 2200000;
                            break;
                    }
                    
                    $expected_total = $application_fee + $registration_fee + $tuition;
                    $expected_tuition = $tuition;
                    
                    // Insert student_finances record
                    $stmt = $conn->prepare("INSERT INTO student_finances 
                        (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) 
                        VALUES (?, ?, ?, 0, ?, 0, 0)");
                    $stmt->bind_param("sddd", $student_id, $expected_total, $expected_tuition, $expected_total);
                    $stmt->execute();
                    
                    $upload_success++;
                } catch (Exception $e) {
                    $upload_errors[] = "Row $row_num: " . $e->getMessage();
                }
            }
            
            fclose($handle);
            
            // Set success/error messages
            if ($upload_success > 0) {
                $success = "Successfully uploaded $upload_success student(s).";
            }
            if (!empty($upload_errors)) {
                $error = "Upload completed with errors: <br>" . implode('<br>', array_slice($upload_errors, 0, 10));
                if (count($upload_errors) > 10) {
                    $error .= "<br>... and " . (count($upload_errors) - 10) . " more errors.";
                }
            }
        } else {
            $error = "Please select a valid CSV file to upload.";
        }
    }
}

// Get all students with usernames
$students = [];
$result = $conn->query("SELECT s.*, u.username, d.department_name, d.department_code 
                        FROM students s 
                        LEFT JOIN users u ON s.student_id = u.related_student_id 
                        LEFT JOIN departments d ON s.department = d.department_id 
                        ORDER BY s.full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get all departments/programs for dropdown
$departments = [];
$dept_query = "SELECT department_id, department_code, department_name FROM departments ORDER BY department_name";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Manage Students</h2>
            <div>
                <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                    <i class="bi bi-person-plus-fill"></i> Add New Student
                </button>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
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

        <!-- Bulk Upload Section -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-upload"></i> Bulk Upload Students</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-3"><i class="bi bi-info-circle"></i> Upload multiple students at once using a CSV file.</p>
                        <a href="?download_template" class="btn btn-outline-success mb-3">
                            <i class="bi bi-download"></i> Download CSV Template
                        </a>
                        <p class="text-muted small">Download the template, fill in student details, and upload the completed file.</p>
                    </div>
                    <div class="col-md-6">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_file" class="form-label">Select CSV File *</label>
                                <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                                <div class="form-text">Only CSV files are accepted</div>
                            </div>
                            <button type="submit" name="bulk_upload" class="btn btn-success">
                                <i class="bi bi-upload"></i> Upload Students
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students List -->
        <div class="card">
            <div class="card-header">
                <h5>All Students (<?php echo count($students); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <p class="text-muted">No students found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Department</th>
                                    <th>Year</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($student['username'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['department_name'] ?? $student['department']); ?></td>
                                        <td><?php echo $student['year_of_study']; ?></td>
                                        <td>
                                            <a href="edit_student.php?id=<?php echo urlencode($student['student_id']); ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo $student['student_id']; ?>">
                                                <i class="bi bi-key-fill"></i> Reset Password
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                <button type="submit" name="delete_student" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    
                                    <!-- Reset Password Modal -->
                                    <div class="modal fade" id="resetPasswordModal<?php echo $student['student_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-warning">
                                                    <h5 class="modal-title text-white"><i class="bi bi-key-fill"></i> Reset Password</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" id="resetPasswordForm<?php echo $student['student_id']; ?>">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student['student_id']); ?>">
                                                        <div class="alert alert-info">
                                                            <strong>Student:</strong> <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">New Password *</label>
                                                            <input type="password" class="form-control" name="new_password" id="newPassword<?php echo $student['student_id']; ?>" placeholder="Enter new password" required minlength="6">
                                                            <div class="form-text">Password must be at least 6 characters long.</div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Confirm Password *</label>
                                                            <input type="password" class="form-control" name="confirm_password" id="confirmPassword<?php echo $student['student_id']; ?>" placeholder="Re-enter password" required minlength="6">
                                                            <div class="invalid-feedback" id="passwordMismatch<?php echo $student['student_id']; ?>">
                                                                Passwords do not match!
                                                            </div>
                                                        </div>
                                                        <div class="alert alert-warning">
                                                            <i class="bi bi-exclamation-triangle"></i> <strong>Note:</strong> The student will need to use this new password to login.
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="reset_password" class="btn btn-warning">
                                                            <i class="bi bi-check-circle"></i> Reset Password
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
            
            // Trigger student ID generation
            generateStudentID();
        }
        
        function updateEntryCode() {
            const entryType = document.getElementById('entry_type').value;
            const entryCodeField = document.getElementById('entry_code');
            entryCodeField.value = entryType;
            generateStudentID();
        }
        
        function generateStudentID() {
            const department = document.getElementById('department');
            const yearOfReg = document.getElementById('year_of_registration').value;
            const campus = document.getElementById('campus').value;
            const entryType = document.getElementById('entry_type').value;
            
            if (!department.value || !yearOfReg || !campus || !entryType) {
                return; // Don't generate if required fields are missing
            }
            
            // Get department code from selected option
            const deptCode = department.options[department.selectedIndex].getAttribute('data-code') || 'UNK';
            
            // Get last 2 digits of year
            const yearShort = yearOfReg.substring(2);
            
            // Extract campus code
            let campusCode = 'MZ';
            if (campus.includes('Lilongwe')) {
                campusCode = 'LL';
            } else if (campus.includes('Blantyre')) {
                campusCode = 'BT';
            }
            
            // Generate preview ID (backend will add sequential number)
            const studentIDField = document.getElementById('student_id');
            studentIDField.value = deptCode + '/' + yearShort + '/' + campusCode + '/' + entryType + '/####';
        }
        
        // Attach listeners to relevant fields
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('department').addEventListener('change', generateStudentID);
            document.getElementById('year_of_registration').addEventListener('change', generateStudentID);
            document.getElementById('campus').addEventListener('change', generateStudentID);
            document.getElementById('entry_type').addEventListener('change', generateStudentID);
            
            // Modal form listeners
            document.getElementById('modal_department').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_year_of_registration').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_campus').addEventListener('change', generateModalStudentID);
            document.getElementById('modal_entry_type').addEventListener('change', generateModalStudentID);
        });
        
        // Functions for modal form
        function updateModalDepartmentField() {
            const department = document.getElementById('modal_department');
            const program = document.getElementById('modal_program');
            
            if (department.value) {
                const selectedOption = department.options[department.selectedIndex];
                const deptName = selectedOption.getAttribute('data-name');
                
                // Remove prefixes to get simplified department name
                let simplifiedName = deptName.replace(/^(Bachelor of|Masters of|Master of|Doctorate in|PhD in|Professional Certificate in)\s+/i, '').trim();
                
                // Clear and populate program select
                program.innerHTML = '<option value="' + simplifiedName + '">' + simplifiedName + '</option>';
                program.value = simplifiedName;
            }
            
            generateModalStudentID();
        }
        
        function updateModalEntryCode() {
            const entryType = document.getElementById('modal_entry_type');
            const entryCode = document.getElementById('modal_entry_code');
            entryCode.value = entryType.value;
            generateModalStudentID();
        }
        
        function generateModalStudentID() {
            const department = document.getElementById('modal_department').value;
            const yearOfReg = document.getElementById('modal_year_of_registration').value;
            const campus = document.getElementById('modal_campus').value;
            const entryType = document.getElementById('modal_entry_type').value;
            
            if (!department || !yearOfReg || !campus || !entryType) {
                return;
            }
            
            const deptElement = document.getElementById('modal_department');
            const deptCode = deptElement.options[deptElement.selectedIndex].getAttribute('data-code') || 'UNK';
            const yearShort = yearOfReg.substring(2);
            
            let campusCode = 'MZ';
            if (campus.includes('Lilongwe')) {
                campusCode = 'LL';
            } else if (campus.includes('Blantyre')) {
                campusCode = 'BT';
            }
            
            const studentIDField = document.getElementById('modal_student_id');
            studentIDField.value = deptCode + '/' + yearShort + '/' + campusCode + '/' + entryType + '/####';
        }
        
        // Password validation for reset password forms
        document.addEventListener('DOMContentLoaded', function() {
            // Get all reset password forms
            const forms = document.querySelectorAll('[id^="resetPasswordForm"]');
            
            forms.forEach(function(form) {
                const studentId = form.querySelector('input[name="student_id"]').value;
                const newPasswordInput = document.getElementById('newPassword' + studentId);
                const confirmPasswordInput = document.getElementById('confirmPassword' + studentId);
                const mismatchDiv = document.getElementById('passwordMismatch' + studentId);
                
                if (!newPasswordInput || !confirmPasswordInput) return;
                
                // Validate on form submit
                form.addEventListener('submit', function(e) {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        confirmPasswordInput.classList.add('is-invalid');
                        if (mismatchDiv) {
                            mismatchDiv.style.display = 'block';
                        }
                        return false;
                    }
                    
                    if (newPassword.length < 6) {
                        e.preventDefault();
                        newPasswordInput.classList.add('is-invalid');
                        alert('Password must be at least 6 characters long!');
                        return false;
                    }
                    
                    return true;
                });
                
                // Real-time validation
                confirmPasswordInput.addEventListener('input', function() {
                    if (this.value && this.value !== newPasswordInput.value) {
                        this.classList.add('is-invalid');
                        if (mismatchDiv) {
                            mismatchDiv.style.display = 'block';
                        }
                    } else {
                        this.classList.remove('is-invalid');
                        if (mismatchDiv) {
                            mismatchDiv.style.display = 'none';
                        }
                    }
                });
            });
            
            // Auto-close modals on success and scroll to message
            <?php if (isset($success) && strpos($success, 'Password reset successfully') !== false): ?>
                // Close all reset password modals
                const modals = document.querySelectorAll('[id^="resetPasswordModal"]');
                modals.forEach(function(modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) {
                        bsModal.hide();
                    }
                });
                
                // Scroll to success message
                window.scrollTo({ top: 0, behavior: 'smooth' });
            <?php endif; ?>
        });
    </script>
    
    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill"></i> Add New Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Line 1: First Name, Middle Name, Last Name, Username -->
                            <div class="col-12"><h6 class="text-primary">Personal Information</h6></div>
                            <div class="col-md-3">
                                <label for="first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First name" required>
                            </div>
                            <div class="col-md-3">
                                <label for="middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Middle name">
                            </div>
                            <div class="col-md-3">
                                <label for="last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last name" required>
                            </div>
                            <div class="col-md-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="Login username" required>
                            </div>
                            
                            <!-- Line 2: Gender, National ID, Phone Number, Home Address -->
                            <div class="col-md-3">
                                <label for="gender" class="form-label">Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="national_id" class="form-label">National ID</label>
                                <input type="text" class="form-control" id="national_id" name="national_id" placeholder="National ID">
                            </div>
                            <div class="col-md-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" placeholder="+265...">
                            </div>
                            <div class="col-md-3">
                                <label for="address" class="form-label">Home Address</label>
                                <input type="text" class="form-control" id="address" name="address" placeholder="Address">
                            </div>
                            
                            <!-- Line 3: Campus, Program of Study, Department, Program Type -->
                            <div class="col-12"><h6 class="text-primary mt-2">Academic Information</h6></div>
                            <div class="col-md-3">
                                <label for="modal_campus" class="form-label">Campus *</label>
                                <select class="form-select" id="modal_campus" name="campus" required>
                                    <option value="Mzuzu Campus" selected>Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_department" class="form-label">Program of Study *</label>
                                <select class="form-select" id="modal_department" name="department" required onchange="updateModalDepartmentField()">
                                    <option value="">Select Program</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept['department_id']); ?>" 
                                                data-code="<?php echo htmlspecialchars($dept['department_code']); ?>"
                                                data-name="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_program" class="form-label">Department *</label>
                                <select class="form-select bg-light" id="modal_program" name="program" required>
                                    <option value="">Select Program First</option>
                                </select>
                                <small class="text-muted">Auto-populated from Program of Study</small>
                            </div>
                            <div class="col-md-3">
                                <label for="program_type" class="form-label">Program Type *</label>
                                <select class="form-select" id="program_type" name="program_type" required>
                                    <option value="degree" selected>Degree</option>
                                    <option value="professional">Professional</option>
                                    <option value="masters">Masters</option>
                                    <option value="doctorate">Doctorate</option>
                                </select>
                            </div>
                            
                            <!-- Line 4: Year of Registration, Year of Study, Semester, Student ID -->
                            <div class="col-md-3">
                                <label for="modal_year_of_registration" class="form-label">Year of Registration *</label>
                                <input type="number" class="form-control" id="modal_year_of_registration" name="year_of_registration" 
                                       min="2000" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label for="year_of_study" class="form-label">Year of Study *</label>
                                <select class="form-select" id="year_of_study" name="year_of_study" required>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="semester" class="form-label">Semester *</label>
                                <select class="form-select" id="semester" name="semester" required>
                                    <option value="One" selected>Semester One</option>
                                    <option value="Two">Semester Two</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="modal_student_id" class="form-label">Student ID *</label>
                                <input type="text" class="form-control bg-light" id="modal_student_id" name="student_id" placeholder="Auto-generated" readonly required>
                                <small class="text-muted">Auto-generated based on selections</small>
                            </div>
                            
                            <!-- Line 5: Email, Entry Level, Entry Code -->
                            <div class="col-12"><h6 class="text-primary mt-2">Additional Information</h6></div>
                            <div class="col-md-4">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="student@example.com" required>
                            </div>
                            <div class="col-md-4">
                                <label for="modal_entry_type" class="form-label">Entry Level *</label>
                                <select class="form-select" id="modal_entry_type" name="entry_type" required onchange="updateModalEntryCode()">
                                    <option value="NE" selected>Normal Entry (NE)</option>
                                    <option value="ME">Mature Entry (ME)</option>
                                    <option value="ODL">Open Distance Learning (ODL)</option>
                                    <option value="PC">Professional Course (PC)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="modal_entry_code" class="form-label">Entry Code *</label>
                                <input type="text" class="form-control" id="modal_entry_code" name="entry_code" value="NE" maxlength="10" required>
                                <small class="text-muted">Editable entry code</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <small class="text-muted me-auto">Default password will be: password123</small>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_student" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Add Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>