<?php
// student/register_courses.php - Student self-service course registration
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'];

// Get student details
$student_stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$student_stmt->bind_param("s", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found!");
}

$success = '';
$error = '';

// Create table if it doesn't exist
$tableCheck = $conn->query("SHOW TABLES LIKE 'course_registration_requests'");
if ($tableCheck->num_rows == 0) {
    $createTable = "CREATE TABLE course_registration_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(20) NOT NULL,
        course_id INT NOT NULL,
        semester VARCHAR(50) NOT NULL,
        academic_year VARCHAR(50) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_date TIMESTAMP NULL,
        admin_notes TEXT NULL,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
        INDEX idx_status (status),
        INDEX idx_student (student_id),
        INDEX idx_course (course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createTable);
}

// Handle course registration request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_courses'])) {
    $selected_courses = $_POST['selected_courses'] ?? [];
    
    if (empty($selected_courses)) {
        $error = "Please select at least one course to register!";
    } else {
        $success_count = 0;
        $error_messages = [];
        
        foreach ($selected_courses as $course_data) {
            list($course_id, $semester, $academic_year) = explode('|', $course_data);
            $course_id = (int)$course_id;
            
            // Check if course exists and get details
            $course_check = $conn->prepare("SELECT c.*, l.full_name as lecturer_name 
                                            FROM vle_courses c 
                                            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                                            WHERE c.course_id = ?");
            $course_check->bind_param("i", $course_id);
            $course_check->execute();
            $course_result = $course_check->get_result();
            
            if ($course_result->num_rows === 0) {
                $error_messages[] = "Invalid course selected (ID: $course_id)";
                continue;
            }
            
            $course = $course_result->fetch_assoc();
            
            // Check if already enrolled
            $enrolled_check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $enrolled_check->bind_param("si", $student['student_id'], $course_id);
            $enrolled_check->execute();
            
            if ($enrolled_check->get_result()->num_rows > 0) {
                $error_messages[] = htmlspecialchars($course['course_code']) . ": Already enrolled";
                continue;
            }
            
            // Check if already requested
            $request_check = $conn->prepare("SELECT request_id, status FROM course_registration_requests 
                                            WHERE student_id = ? AND course_id = ? AND status = 'pending'");
            $request_check->bind_param("si", $student['student_id'], $course_id);
            $request_check->execute();
            
            if ($request_check->get_result()->num_rows > 0) {
                $error_messages[] = htmlspecialchars($course['course_code']) . ": Already requested";
                continue;
            }
            
            // Check 7-course limit
            $count_check = $conn->prepare("SELECT COUNT(*) as course_count FROM vle_enrollments e 
                                          INNER JOIN semester_courses sc ON e.course_id = sc.course_id 
                                          WHERE e.student_id = ? AND sc.semester = ? AND sc.academic_year = ?");
            $count_check->bind_param("sss", $student['student_id'], $semester, $academic_year);
            $count_check->execute();
            $count_result = $count_check->get_result()->fetch_assoc();
            
            $pending_count = $conn->prepare("SELECT COUNT(*) as pending_count FROM course_registration_requests 
                                            WHERE student_id = ? AND semester = ? AND academic_year = ? AND status = 'pending'");
            $pending_count->bind_param("sss", $student['student_id'], $semester, $academic_year);
            $pending_count->execute();
            $pending_result = $pending_count->get_result()->fetch_assoc();
            
            $total_courses = $count_result['course_count'] + $pending_result['pending_count'] + $success_count;
            
            if ($total_courses >= 7) {
                $error_messages[] = htmlspecialchars($course['course_code']) . ": 7-course limit reached";
                continue;
            }
            
            // Insert registration request
            $insert_stmt = $conn->prepare("INSERT INTO course_registration_requests 
                                          (student_id, course_id, semester, academic_year, status) 
                                          VALUES (?, ?, ?, ?, 'pending')");
            $insert_stmt->bind_param("siss", $student['student_id'], $course_id, $semester, $academic_year);
            
            if ($insert_stmt->execute()) {
                $success_count++;
            } else {
                $error_messages[] = htmlspecialchars($course['course_code']) . ": Database error";
            }
        }
        
        if ($success_count > 0) {
            $success = "Successfully submitted $success_count course registration request(s)!";
        }
        
        if (!empty($error_messages)) {
            $error = "Some courses were not requested: " . implode(", ", $error_messages);
        }
    }
}

// Handle single course registration (legacy support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_course'])) {
    $course_id = (int)$_POST['course_id'];
    $semester = $_POST['semester'];
    $academic_year = $_POST['academic_year'];
    
    // Check if course exists and get details
    $course_check = $conn->prepare("SELECT c.*, l.full_name as lecturer_name 
                                    FROM vle_courses c 
                                    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                                    WHERE c.course_id = ?");
    $course_check->bind_param("i", $course_id);
    $course_check->execute();
    $course_result = $course_check->get_result();
    
    if ($course_result->num_rows === 0) {
        $error = "Invalid course selected!";
    } else {
        $course = $course_result->fetch_assoc();
        
        // Validate program and year match
        if ($course['program_of_study'] !== $student['program']) {
            $error = "This course is for " . htmlspecialchars($course['program_of_study']) . " program. Your program is " . htmlspecialchars($student['program']) . ".";
        } elseif ($course['year_of_study'] != $student['year_of_study']) {
            $error = "This course is for Year " . $course['year_of_study'] . " students. You are in Year " . $student['year_of_study'] . ".";
        } else {
            // Check if already enrolled
            $enrolled_check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $enrolled_check->bind_param("si", $student['student_id'], $course_id);
            $enrolled_check->execute();
            
            if ($enrolled_check->get_result()->num_rows > 0) {
                $error = "You are already enrolled in this course!";
            } else {
                // Check if already requested
                $request_check = $conn->prepare("SELECT request_id, status FROM course_registration_requests 
                                                WHERE student_id = ? AND course_id = ? AND status = 'pending'");
                $request_check->bind_param("si", $student['student_id'], $course_id);
                $request_check->execute();
                
                if ($request_check->get_result()->num_rows > 0) {
                    $error = "You already have a pending request for this course!";
                } else {
                    // Check 7-course limit for the semester
                    $count_check = $conn->prepare("SELECT COUNT(*) as course_count FROM vle_enrollments e 
                                                  INNER JOIN semester_courses sc ON e.course_id = sc.course_id 
                                                  WHERE e.student_id = ? AND sc.semester = ? AND sc.academic_year = ?");
                    $count_check->bind_param("sss", $student['student_id'], $semester, $academic_year);
                    $count_check->execute();
                    $count_result = $count_check->get_result()->fetch_assoc();
                    
                    // Also count pending requests for the same semester
                    $pending_count = $conn->prepare("SELECT COUNT(*) as pending_count FROM course_registration_requests 
                                                    WHERE student_id = ? AND semester = ? AND academic_year = ? AND status = 'pending'");
                    $pending_count->bind_param("sss", $student['student_id'], $semester, $academic_year);
                    $pending_count->execute();
                    $pending_result = $pending_count->get_result()->fetch_assoc();
                    
                    $total_courses = $count_result['course_count'] + $pending_result['pending_count'];
                    
                    if ($total_courses >= 7) {
                        $error = "You have reached the maximum of 7 courses for this semester (including pending requests)!";
                    } else {
                        // Insert registration request
                        $insert_stmt = $conn->prepare("INSERT INTO course_registration_requests 
                                                      (student_id, course_id, semester, academic_year, status) 
                                                      VALUES (?, ?, ?, ?, 'pending')");
                        $insert_stmt->bind_param("siss", $student['student_id'], $course_id, $semester, $academic_year);
                        
                        if ($insert_stmt->execute()) {
                            $success = "Registration request submitted successfully! Your request for <strong>" . 
                                      htmlspecialchars($course['course_name']) . "</strong> is pending administrator approval.";
                        } else {
                            $error = "Error submitting request: " . $conn->error;
                        }
                    }
                }
            }
        }
    }
}

// Get available semester courses (not already enrolled or requested)
$available_courses_query = "SELECT c.*, l.full_name as lecturer_name, sc.semester, sc.academic_year,
                            CASE 
                                WHEN e.enrollment_id IS NOT NULL THEN 'enrolled'
                                WHEN r.request_id IS NOT NULL THEN r.status
                                ELSE 'available'
                            END as enrollment_status
                            FROM vle_courses c
                            INNER JOIN semester_courses sc ON c.course_id = sc.course_id
                            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
                            LEFT JOIN vle_enrollments e ON c.course_id = e.course_id AND e.student_id = ?
                            LEFT JOIN course_registration_requests r ON c.course_id = r.course_id 
                                                                      AND r.student_id = ? 
                                                                      AND r.status = 'pending'
                            WHERE sc.is_active = TRUE
                            ORDER BY c.program_of_study, c.year_of_study, sc.semester, c.course_code";

$available_stmt = $conn->prepare($available_courses_query);
$available_stmt->bind_param("ss", $student['student_id'], $student['student_id']);
$available_stmt->execute();
$available_courses = $available_stmt->get_result();

// Get student's registration requests
$requests_query = "SELECT r.*, c.course_name, c.course_code, l.full_name as lecturer_name,
                   u.username as reviewer_name
                   FROM course_registration_requests r
                   INNER JOIN vle_courses c ON r.course_id = c.course_id
                   LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
                   LEFT JOIN users u ON r.reviewed_by = u.user_id
                   WHERE r.student_id = ?
                   ORDER BY r.request_date DESC";

$requests_stmt = $conn->prepare($requests_query);
$requests_stmt->bind_param("s", $student['student_id']);
$requests_stmt->execute();
$requests = $requests_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Registration - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .course-card {
            transition: all 0.3s;
            border-left: 4px solid #0d6efd;
        }
        .course-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
        }
        .semester-badge {
            font-size: 0.75rem;
        }
        .enrolled { border-left-color: #198754; opacity: 0.7; }
        .pending { border-left-color: #ffc107; }
        .available { border-left-color: #0d6efd; }
        
        /* Sticky submission bar */
        .submission-bar {
            position: sticky;
            bottom: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 -4px 12px rgba(0,0,0,0.15);
            animation: slideUp 0.3s ease-out;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .submission-bar .btn-submit {
            font-size: 1.1rem;
            padding: 0.75rem 2rem;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .submission-bar .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
        }
        
        .submission-bar .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

    <div class="container mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h2><i class="bi bi-journal-plus"></i> Course Registration</h2>
                <p class="text-muted">Request enrollment in courses for the current semester</p>
                <div class="alert alert-info">
                    <strong><i class="bi bi-info-circle"></i> Student Info:</strong> 
                    <?= htmlspecialchars($student['full_name']) ?> | 
                    <?= htmlspecialchars($student['program']) ?> | 
                    Year <?= $student['year_of_study'] ?> | 
                    Semester <?= $student['semester'] ?>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- My Registration Requests -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> My Registration Requests</h5>
            </div>
            <div class="card-body">
                <?php if ($requests->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Lecturer</th>
                                    <th>Semester</th>
                                    <th>Request Date</th>
                                    <th>Status</th>
                                    <th>Admin Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($req = $requests->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($req['course_code']) ?></strong></td>
                                        <td><?= htmlspecialchars($req['course_name']) ?></td>
                                        <td><?= htmlspecialchars($req['lecturer_name'] ?? 'Not Assigned') ?></td>
                                        <td>
                                            <span class="badge bg-info semester-badge">
                                                <?= htmlspecialchars($req['semester']) ?> <?= htmlspecialchars($req['academic_year']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($req['request_date'])) ?></td>
                                        <td>
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <span class="badge bg-warning status-badge">
                                                    <i class="bi bi-clock"></i> Pending
                                                </span>
                                            <?php elseif ($req['status'] === 'approved'): ?>
                                                <span class="badge bg-success status-badge">
                                                    <i class="bi bi-check-circle"></i> Approved
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger status-badge">
                                                    <i class="bi bi-x-circle"></i> Rejected
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($req['admin_notes']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($req['admin_notes']) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">You have not submitted any registration requests yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Available Courses -->
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-book"></i> All Available Courses for Registration</h5>
                <span class="badge bg-light text-dark">
                    Your Program: <?= htmlspecialchars($student['program']) ?> | Year <?= $student['year_of_study'] ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <strong>Note:</strong> You can register for courses from any department or year. 
                    Maximum of 7 courses per semester. Select courses from the list below and click "Submit Selected Courses" button.
                </div>

                <?php if ($available_courses->num_rows > 0): ?>
                    <form method="POST" id="registrationForm">
                    
                    <!-- Selection Actions Bar -->
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded">
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">
                                <i class="bi bi-check-all"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">
                                <i class="bi bi-x-square"></i> Deselect All
                            </button>
                            <span class="ms-3 badge bg-info" id="selectionBadge">
                                <i class="bi bi-check2-square"></i> <span id="selectedCount">0</span> course(s) selected
                            </span>
                        </div>
                        <div>
                            <button type="submit" name="request_courses" class="btn btn-success btn-lg" id="submitBtn" disabled>
                                <i class="bi bi-send-fill"></i> Submit Selected Courses
                            </button>
                        </div>
                    </div>

                    <!-- Courses Table -->
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-primary">
                                <tr>
                                    <th width="5%">Select</th>
                                    <th width="10%">Code</th>
                                    <th width="25%">Course Name</th>
                                    <th width="15%">Lecturer</th>
                                    <th width="10%">Credits</th>
                                    <th width="15%">Semester</th>
                                    <th width="10%">Year</th>
                                    <th width="10%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php 
                        // Reset data pointer
                        $available_courses->data_seek(0);
                        while ($course = $available_courses->fetch_assoc()): 
                        ?>
                                <?php
                                // Only show courses for current year and semester, or next semester
                                $student_year = (int)$student['year_of_study'];
                                $student_semester = (int)$student['semester'];
                                $course_year = (int)$course['year_of_study'];
                                $course_semester = (int)$course['semester'];
                                if ($course_year == $student_year && ($course_semester == $student_semester || $course_semester == $student_semester + 1)):
                                ?>
                                <tr class="<?= $course['enrollment_status'] === 'available' ? '' : 'table-secondary' ?>">
                                    <td class="text-center">
                                        <?php if ($course['enrollment_status'] === 'available'): ?>
                                            <input class="form-check-input course-checkbox" type="checkbox" 
                                                   name="selected_courses[]" 
                                                   value="<?= $course['course_id'] ?>|<?= htmlspecialchars($course['semester']) ?>|<?= htmlspecialchars($course['academic_year']) ?>"
                                                   id="course<?= $course['course_id'] ?>">
                                        <?php else: ?>
                                            <i class="bi bi-dash-circle text-muted"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($course['course_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($course['course_name']) ?></td>
                                    <td><?= htmlspecialchars($course['lecturer_name'] ?? 'Not Assigned') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($course['credits'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= htmlspecialchars($course['semester']) ?> <?= htmlspecialchars($course['academic_year']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">Year <?= $course['year_of_study'] ?></td>
                                    <td class="text-center">
                                        <?php if ($course['enrollment_status'] === 'enrolled'): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Enrolled
                                            </span>
                                        <?php elseif ($course['enrollment_status'] === 'pending'): ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-clock"></i> Pending
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-plus-circle"></i> Available
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                </tr>
                        <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Bottom Submit Button -->
                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" name="request_courses" class="btn btn-success btn-lg" id="submitBtn2" disabled>
                            <i class="bi bi-send-fill"></i> Submit Selected Courses for Registration
                        </button>
                    </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No courses are currently available for registration in your program and year.
                        Please contact your administrator.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sticky Submission Bar (Appears when courses are selected) -->
        <div class="submission-bar d-none" id="stickySubmissionBar">
            <div class="container">
                <div class="row align-items-center py-3">
                    <div class="col-md-6">
                        <div class="text-white">
                            <h5 class="mb-1">
                                <i class="bi bi-check2-square"></i> Course Selection
                            </h5>
                            <p class="mb-0">
                                <span id="stickyCount" class="fs-4 fw-bold">0</span> course(s) selected
                                <span class="ms-3 small" id="stickyWarning"></span>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="button" class="btn btn-outline-light me-2" onclick="document.getElementById('deselectAllBtn').click()">
                            <i class="bi bi-x-circle"></i> Clear Selection
                        </button>
                        <button type="button" class="btn btn-warning btn-submit pulse-animation" id="stickySubmitBtn" onclick="document.getElementById('submitBtn').click()">
                            <i class="bi bi-send-fill"></i> Submit Registration Request
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Course selection functionality
        const checkboxes = document.querySelectorAll('.course-checkbox');
        const selectedCount = document.getElementById('selectedCount');
        const submitBtn = document.getElementById('submitBtn');
        const submitBtn2 = document.getElementById('submitBtn2');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const stickyBar = document.getElementById('stickySubmissionBar');
        const stickyCount = document.getElementById('stickyCount');
        const stickyWarning = document.getElementById('stickyWarning');
        const stickySubmitBtn = document.getElementById('stickySubmitBtn');
        
        function updateCount() {
            const checked = document.querySelectorAll('.course-checkbox:checked').length;
            selectedCount.textContent = checked;
            stickyCount.textContent = checked;
            submitBtn.disabled = checked === 0;
            submitBtn2.disabled = checked === 0;
            
            // Show/hide sticky submission bar
            if (checked > 0) {
                stickyBar.classList.remove('d-none');
                stickySubmitBtn.disabled = false;
            } else {
                stickyBar.classList.add('d-none');
            }
            
            // Update selection badge color
            const badge = document.getElementById('selectionBadge');
            if (checked === 0) {
                badge.className = 'ms-3 badge bg-secondary';
            } else if (checked >= 7) {
                badge.className = 'ms-3 badge bg-warning text-dark';
                stickyWarning.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Maximum limit reached';
                stickyWarning.className = 'ms-3 small badge bg-danger';
            } else {
                badge.className = 'ms-3 badge bg-info';
                stickyWarning.innerHTML = '<i class="bi bi-info-circle"></i> ' + (7 - checked) + ' more available';
                stickyWarning.className = 'ms-3 small badge bg-success';
            }
        }
        
        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateCount);
        });
        
        selectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = true;
            });
            updateCount();
        });
        
        deselectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            updateCount();
        });
        
        // Confirm submission
        document.getElementById('registrationForm').addEventListener('submit', (e) => {
            const count = document.querySelectorAll('.course-checkbox:checked').length;
            if (count > 7) {
                e.preventDefault();
                alert('⚠️ Warning: You have selected ' + count + ' courses. Maximum is 7 courses per semester.\n\nPlease deselect ' + (count - 7) + ' course(s) before submitting.');
                return false;
            }
            if (count === 0) {
                e.preventDefault();
                alert('Please select at least one course before submitting.');
                return false;
            }
            
            // Get list of selected course codes
            const selectedCourses = [];
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    const row = cb.closest('tr');
                    const courseCode = row.querySelector('td:nth-child(2)').textContent.trim();
                    const courseName = row.querySelector('td:nth-child(3)').textContent.trim();
                    selectedCourses.push(courseCode + ' - ' + courseName);
                }
            });
            
            const message = '📋 You are about to submit registration requests for ' + count + ' course(s):\n\n' + 
                          selectedCourses.map((c, i) => (i + 1) + '. ' + c).join('\n') + 
                          '\n\nDo you want to proceed?';
            
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
        
        // Scroll to top button in sticky bar
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    </script>
</body>
</html>
