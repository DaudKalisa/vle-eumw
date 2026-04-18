<?php
// admin/register_student_courses.php - Admin course registration for students
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$success = '';
$error = '';
$student = null;
$student_id = $_GET['student_id'] ?? $_POST['student_id'] ?? '';

// Get student if ID provided
if (!empty($student_id)) {
    $student_stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $student_stmt->bind_param("s", $student_id);
    $student_stmt->execute();
    $student = $student_stmt->get_result()->fetch_assoc();
    $student_stmt->close();
    
    // Resolve program name if numeric
    if ($student && (empty($student['program']) || is_numeric($student['program']))) {
        $dept_id = !empty($student['department']) ? $student['department'] : $student['program'];
        if ($dept_id) {
            $dept_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $dept_stmt->bind_param("i", $dept_id);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->get_result()->fetch_assoc();
            if ($dept_result) {
                $student['program'] = $dept_result['department_name'];
            }
            $dept_stmt->close();
        }
    }
}

// Handle course enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_courses']) && $student) {
    $selected_courses = $_POST['selected_courses'] ?? [];
    
    if (empty($selected_courses)) {
        $error = "Please select at least one course to enroll!";
    } else {
        $success_count = 0;
        $error_messages = [];
        
        foreach ($selected_courses as $course_id) {
            $course_id = (int)$course_id;
            
            // Check if already enrolled
            $check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $check->bind_param("si", $student['student_id'], $course_id);
            $check->execute();
            
            if ($check->get_result()->num_rows > 0) {
                // Get course name for error
                $c = $conn->query("SELECT course_code FROM vle_courses WHERE course_id = $course_id")->fetch_assoc();
                $error_messages[] = ($c['course_code'] ?? "ID:$course_id") . ": Already enrolled";
                continue;
            }
            
            // Enroll student
            $enroll = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
            $enroll->bind_param("si", $student['student_id'], $course_id);
            
            if ($enroll->execute()) {
                $success_count++;
            } else {
                $c = $conn->query("SELECT course_code FROM vle_courses WHERE course_id = $course_id")->fetch_assoc();
                $error_messages[] = ($c['course_code'] ?? "ID:$course_id") . ": Database error";
            }
        }
        
        if ($success_count > 0) {
            $success = "Successfully enrolled student in $success_count course(s)!";
            
            // Send email notification to student
            try {
                if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled() && !empty($student['email'])) {
                    // Get enrolled course names
                    $enrolled_names = [];
                    foreach ($selected_courses as $cid) {
                        $cn = $conn->query("SELECT course_code, course_name FROM vle_courses WHERE course_id = " . (int)$cid)->fetch_assoc();
                        if ($cn) $enrolled_names[] = $cn['course_code'] . ' - ' . $cn['course_name'];
                    }
                    $course_list_html = '<ul>' . implode('', array_map(fn($n) => "<li>$n</li>", $enrolled_names)) . '</ul>';
                    $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php?redirect_to=' . urlencode('student/dashboard.php') : '/vle-eumw/login.php';
                    $subject = "Course Registration Confirmation - VLE";
                    $message = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                        <div style='background:linear-gradient(135deg,#059669,#10b981);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                            <h2 style='margin:0;'>\u2705 Course Registration Confirmation</h2>
                        </div>
                        <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                            <p>Dear <strong>" . htmlspecialchars($student['full_name']) . "</strong>,</p>
                            <p>You have been enrolled in the following <strong>$success_count course(s)</strong> by the administrator:</p>
                            $course_list_html
                            <p><strong>Student ID:</strong> " . htmlspecialchars($student['student_id']) . "</p>
                            <p><strong>Program:</strong> " . htmlspecialchars($student['program'] ?? 'N/A') . "</p>
                            <div style='text-align:center;margin:20px 0;'>
                                <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;'>\ud83d\udcda Go to My Courses</a>
                            </div>
                            <p style='font-size:0.85em;color:#64748b;text-align:center;'>If you are not logged in, you'll be redirected to login first.</p>
                        </div>
                    </body></html>";
                    sendEmail($student['email'], $student['full_name'], $subject, $message);
                    $success .= " A confirmation email has been sent to the student.";
                }
            } catch (Exception $e) {
                // Email failure shouldn't block success
            }
        }
        if (!empty($error_messages)) {
            $error = "Some courses not enrolled: " . implode(", ", $error_messages);
        }
    }
}

// Handle course deregistration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deregister_courses']) && $student) {
    $deregister_ids = $_POST['deregister_courses_ids'] ?? [];
    
    if (empty($deregister_ids)) {
        $error = "Please select at least one course to deregister!";
    } else {
        $dereg_count = 0;
        $dereg_names = [];
        
        foreach ($deregister_ids as $course_id) {
            $course_id = (int)$course_id;
            
            // Get course info before deleting
            $cn = $conn->query("SELECT course_code, course_name FROM vle_courses WHERE course_id = $course_id")->fetch_assoc();
            
            $del = $conn->prepare("DELETE FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $del->bind_param("si", $student['student_id'], $course_id);
            if ($del->execute() && $del->affected_rows > 0) {
                $dereg_count++;
                if ($cn) $dereg_names[] = $cn['course_code'] . ' - ' . $cn['course_name'];
            }
        }
        
        if ($dereg_count > 0) {
            $success = "Successfully deregistered student from $dereg_count course(s).";
            
            // Send email notification
            try {
                if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled() && !empty($student['email'])) {
                    $course_list_html = '<ul>' . implode('', array_map(fn($n) => "<li>$n</li>", $dereg_names)) . '</ul>';
                    $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php?redirect_to=' . urlencode('student/dashboard.php') : '/vle-eumw/login.php';
                    $subject = "Course Deregistration Notice - VLE";
                    $message = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                        <div style='background:linear-gradient(135deg,#dc2626,#b91c1c);padding:24px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                            <h2 style='margin:0;'>Course Deregistration Notice</h2>
                        </div>
                        <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;'>
                            <p>Dear <strong>" . htmlspecialchars($student['full_name']) . "</strong>,</p>
                            <p>You have been deregistered from the following <strong>$dereg_count course(s)</strong> by the administrator:</p>
                            $course_list_html
                            <p><strong>Student ID:</strong> " . htmlspecialchars($student['student_id']) . "</p>
                            <p>If you believe this was done in error, please contact the administration office.</p>
                            <div style='text-align:center;margin:20px 0;'>
                                <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;'>\ud83d\udcda View My Courses</a>
                            </div>
                            <p style='font-size:0.85em;color:#64748b;text-align:center;'>If you are not logged in, you'll be redirected to login first.</p>
                        </div>
                    </body></html>";
                    sendEmail($student['email'], $student['full_name'], $subject, $message);
                    $success .= " A notification email has been sent to the student.";
                }
            } catch (Exception $e) {
                // Email failure shouldn't block success
            }
            
            // Refresh enrolled courses after deregistration
            header("Location: register_student_courses.php?student_id=" . urlencode($student['student_id']) . "&msg=deregistered&count=$dereg_count");
            exit;
        } else {
            $error = "Failed to deregister courses. They may have already been removed.";
        }
    }
}

// Show redirect success message
if (isset($_GET['msg']) && $_GET['msg'] === 'deregistered') {
    $success = "Successfully deregistered student from " . ((int)($_GET['count'] ?? 0)) . " course(s). A notification email has been sent.";
}

// Get all students for dropdown
$all_students = [];
$result = $conn->query("SELECT student_id, full_name, program, year_of_study FROM students ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $all_students[] = $row;
}

// Get available courses if student selected
$available_courses = [];
$enrolled_courses = [];
$associated_course_ids = [];

if ($student) {
    $student_year = (int)($student['year_of_study'] ?? 1);
    $student_program = $student['program'] ?? '';
    $student_semester = $student['semester'] ?? 'One';
    
    // Get student's program_id for checking course_programs associations
    $student_program_id = 0;
    $prog_stmt = $conn->prepare("SELECT program_id FROM programs WHERE program_name = ? OR program_name LIKE ?");
    $prog_like = '%' . $student_program . '%';
    $prog_stmt->bind_param("ss", $student_program, $prog_like);
    $prog_stmt->execute();
    $prog_result = $prog_stmt->get_result()->fetch_assoc();
    if ($prog_result) {
        $student_program_id = (int)$prog_result['program_id'];
    }
    $prog_stmt->close();
    
    // Get all course IDs associated with student's program via course_programs table
    if ($student_program_id > 0) {
        $assoc_result = $conn->query("SELECT course_id FROM course_programs WHERE program_id = $student_program_id");
        while ($row = $assoc_result->fetch_assoc()) {
            $associated_course_ids[] = (int)$row['course_id'];
        }
    }
    
    // Get all active courses
    $query = "SELECT DISTINCT c.*, l.full_name as lecturer_name,
              CASE WHEN e.enrollment_id IS NOT NULL THEN 1 ELSE 0 END as is_enrolled,
              CASE WHEN cp.id IS NOT NULL THEN 1 ELSE 0 END as is_associated
              FROM vle_courses c
              LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
              LEFT JOIN vle_enrollments e ON c.course_id = e.course_id AND e.student_id = ?
              LEFT JOIN course_programs cp ON c.course_id = cp.course_id AND cp.program_id = ?
              WHERE c.is_active = 1
              ORDER BY c.program_of_study, c.year_of_study, c.semester, c.course_code";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $student['student_id'], $student_program_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if ($row['is_enrolled']) {
            $enrolled_courses[] = $row;
        } else {
            $available_courses[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Student Courses - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .course-card { border-left: 4px solid #0d6efd; transition: all 0.2s; }
        .course-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .course-card.enrolled { border-left-color: #198754; background: #f8fff8; }
        .course-card.my-program { border-left-color: #ffc107; }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [
        ['title' => 'Manage Students', 'url' => 'manage_students.php'],
        ['title' => 'Register Courses']
    ];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-journal-plus me-2"></i>Register Student Courses</h2>
                <p class="text-muted">Enroll students in courses as administrator</p>
            </div>
            <a href="manage_students.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Students
            </a>
        </div>

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

        <!-- Student Selection -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-person-check"></i> Select Student</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Student</label>
                        <select name="student_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select a student --</option>
                            <?php foreach ($all_students as $s): ?>
                                <option value="<?= htmlspecialchars($s['student_id']) ?>" 
                                        <?= ($student_id == $s['student_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?> (<?= htmlspecialchars($s['student_id']) ?>) - <?= htmlspecialchars($s['program'] ?? 'N/A') ?> Year <?= $s['year_of_study'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Load Student
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($student): ?>
        <!-- Student Info -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-person-badge"></i> Student Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3"><strong>Name:</strong> <?= htmlspecialchars($student['full_name']) ?></div>
                    <div class="col-md-3"><strong>ID:</strong> <?= htmlspecialchars($student['student_id']) ?></div>
                    <div class="col-md-3"><strong>Program:</strong> <?= htmlspecialchars($student['program'] ?? 'N/A') ?></div>
                    <div class="col-md-3"><strong>Year/Semester:</strong> Year <?= $student['year_of_study'] ?>, Semester <?= $student['semester'] ?? 'One' ?></div>
                </div>
            </div>
        </div>

        <!-- Currently Enrolled Courses -->
        <?php if (!empty($enrolled_courses)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-check-circle"></i> Currently Enrolled (<?= count($enrolled_courses) ?> courses)</h5>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" class="form-control form-control-sm bg-white" id="searchEnrolled" placeholder="Search enrolled courses..." style="width:220px;" onkeyup="filterEnrolled()">
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="deregisterForm">
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="document.querySelectorAll('.dereg-cb').forEach(cb=>{if(cb.closest('tr').style.display!=='none')cb.checked=true});updateDeregCount()">
                                <i class="bi bi-check-all"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.dereg-cb').forEach(cb=>cb.checked=false);updateDeregCount()">
                                <i class="bi bi-x-square"></i> Deselect All
                            </button>
                            <span class="badge bg-danger align-self-center" id="deregCount" style="display:none;">0 selected</span>
                        </div>
                        <button type="submit" name="deregister_courses" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to DEREGISTER the selected courses from this student? This action cannot be undone.')">
                            <i class="bi bi-x-circle me-1"></i>Deregister Selected
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="enrolledTable">
                            <thead class="table-success">
                                <tr>
                                    <th width="5%"><input type="checkbox" class="form-check-input" onchange="document.querySelectorAll('.dereg-cb').forEach(cb=>{if(cb.closest('tr').style.display!=='none')cb.checked=this.checked});updateDeregCount()"></th>
                                    <th>Code</th>
                                    <th>Course Name</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Semester</th>
                                    <th>Lecturer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrolled_courses as $c): 
                                    $allYears = [$c['year_of_study']];
                                    if (!empty($c['applicable_years'])) {
                                        $allYears = array_merge($allYears, array_map('trim', explode(',', $c['applicable_years'])));
                                    }
                                    $allYears = array_unique($allYears);
                                    sort($allYears);
                                    $yearDisplay = 'Year ' . implode(',', $allYears);
                                    $semDisplay = ($c['semester'] === 'Both') ? 'Sem 1 & 2' : htmlspecialchars($c['semester']);
                                ?>
                                <tr class="enrolled-row">
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input dereg-cb" name="deregister_courses_ids[]" value="<?= $c['course_id'] ?>" onchange="updateDeregCount()">
                                    </td>
                                    <td><strong><?= htmlspecialchars($c['course_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($c['course_name']) ?></td>
                                    <td><small><?= htmlspecialchars($c['program_of_study'] ?? 'N/A') ?></small></td>
                                    <td><?= $yearDisplay ?></td>
                                    <td><?= $semDisplay ?></td>
                                    <td><small><?= htmlspecialchars($c['lecturer_name'] ?? 'N/A') ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Available Courses to Enroll -->
        <div class="card shadow-sm">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-journal-plus"></i> Available Courses (<?= count($available_courses) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($available_courses)): ?>
                <form method="POST">
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                    
                    <!-- Filter Controls -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Filter by Program</label>
                            <select class="form-select form-select-sm" id="filterProgram" onchange="filterCourses()">
                                <option value="">All Programs</option>
                                <?php 
                                $progs = array_unique(array_column($available_courses, 'program_of_study'));
                                sort($progs);
                                foreach ($progs as $p): if($p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Filter by Year</label>
                            <select class="form-select form-select-sm" id="filterYear" onchange="filterCourses()">
                                <option value="">All Years</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Filter by Semester</label>
                            <select class="form-select form-select-sm" id="filterSemester" onchange="filterCourses()">
                                <option value="">All Semesters</option>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Show Courses</label>
                            <select class="form-select form-select-sm" id="filterAssociated" onchange="filterCourses()">
                                <option value="">All Courses</option>
                                <option value="linked">Linked/Matched Only</option>
                                <option value="other">Other Courses</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12 d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                                <i class="bi bi-check-all"></i> Select All Visible
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="selectLinked()">
                                <i class="bi bi-link-45deg"></i> Select Linked/Matched
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                <i class="bi bi-x-square"></i> Deselect All
                            </button>
                            <span class="badge bg-info ms-2" id="selectedCount">0 selected</span>
                            <span class="ms-3 text-muted small">
                                <span class="badge bg-success">Match</span> = Direct program match | 
                                <span class="badge bg-warning text-dark">Linked</span> = Associated via course settings
                            </span>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="coursesTable">
                            <thead class="table-warning sticky-top">
                                <tr>
                                    <th width="5%">Select</th>
                                    <th width="10%">Code</th>
                                    <th width="25%">Course Name</th>
                                    <th width="20%">Program</th>
                                    <th width="8%">Year</th>
                                    <th width="10%">Semester</th>
                                    <th width="10%">Lecturer</th>
                                    <th width="8%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($available_courses as $c): 
                                    $isMyProgram = (stripos($c['program_of_study'] ?? '', $student['program'] ?? '') !== false);
                                    $courseApplicableYears = !empty($c['applicable_years']) ? array_map('trim', explode(',', $c['applicable_years'])) : [];
                                    $isMyYear = ($c['year_of_study'] == $student['year_of_study'] || in_array((string)$student['year_of_study'], $courseApplicableYears));
                                    $isAssociated = !empty($c['is_associated']);
                                    $highlight = ($isMyProgram && $isMyYear) || $isAssociated;
                                    // Build multi-year display
                                    $allYears = [$c['year_of_study']];
                                    if (!empty($c['applicable_years'])) {
                                        $allYears = array_merge($allYears, $courseApplicableYears);
                                    }
                                    $allYears = array_unique($allYears);
                                    sort($allYears);
                                    $yearDisplay = 'Year ' . implode(',', $allYears);
                                    $semDisplay = ($c['semester'] === 'Both') ? 'Sem 1 & 2' : htmlspecialchars($c['semester'] ?? 'N/A');
                                ?>
                                <tr class="course-row <?= $highlight ? 'table-warning' : '' ?>" 
                                    data-program="<?= htmlspecialchars($c['program_of_study'] ?? '') ?>"
                                    data-year="<?= $c['year_of_study'] ?>"
                                    data-semester="<?= htmlspecialchars($c['semester'] ?? '') ?>"
                                    data-associated="<?= $isAssociated ? '1' : '0' ?>">
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input course-checkbox" 
                                               name="selected_courses[]" value="<?= $c['course_id'] ?>"
                                               onchange="updateCount()">
                                    </td>
                                    <td><strong><?= htmlspecialchars($c['course_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($c['course_name']) ?></td>
                                    <td><small><?= htmlspecialchars($c['program_of_study'] ?? 'N/A') ?></small></td>
                                    <td><?= $yearDisplay ?></td>
                                    <td><span class="badge bg-info"><?= $semDisplay ?></span></td>
                                    <td><small><?= htmlspecialchars($c['lecturer_name'] ?? 'N/A') ?></small></td>
                                    <td>
                                        <?php if ($isMyProgram && $isMyYear): ?>
                                            <span class="badge bg-success" title="Direct program match"><i class="bi bi-check-circle"></i> Match</span>
                                        <?php elseif ($isAssociated): ?>
                                            <span class="badge bg-warning text-dark" title="Associated via course settings"><i class="bi bi-link-45deg"></i> Linked</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Other</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" name="enroll_courses" class="btn btn-success btn-lg">
                            <i class="bi bi-person-plus"></i> Enroll Student in Selected Courses
                        </button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No available courses found. The student may already be enrolled in all active courses.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Please select a student to manage course enrollment.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function filterCourses() {
            const program = document.getElementById('filterProgram').value.toLowerCase();
            const year = document.getElementById('filterYear').value;
            const semester = document.getElementById('filterSemester').value;
            const associated = document.getElementById('filterAssociated').value;
            
            document.querySelectorAll('.course-row').forEach(row => {
                const rowProgram = row.dataset.program.toLowerCase();
                const rowYear = row.dataset.year;
                const rowSemester = row.dataset.semester;
                const rowAssociated = row.dataset.associated;
                const isHighlighted = row.classList.contains('table-warning');
                
                const matchProgram = !program || rowProgram.includes(program);
                const matchYear = !year || rowYear === year;
                const matchSemester = !semester || rowSemester === semester;
                
                let matchAssociated = true;
                if (associated === 'linked') {
                    matchAssociated = isHighlighted; // Show only highlighted (matched or linked)
                } else if (associated === 'other') {
                    matchAssociated = !isHighlighted; // Show only non-highlighted
                }
                
                row.style.display = (matchProgram && matchYear && matchSemester && matchAssociated) ? '' : 'none';
            });
        }
        
        function selectAll() {
            document.querySelectorAll('.course-row').forEach(row => {
                if (row.style.display !== 'none') {
                    row.querySelector('.course-checkbox').checked = true;
                }
            });
            updateCount();
        }
        
        function selectLinked() {
            // Select only courses that are highlighted (matched or linked)
            document.querySelectorAll('.course-row').forEach(row => {
                const isHighlighted = row.classList.contains('table-warning');
                if (row.style.display !== 'none' && isHighlighted) {
                    row.querySelector('.course-checkbox').checked = true;
                }
            });
            updateCount();
        }
        
        function deselectAll() {
            document.querySelectorAll('.course-checkbox').forEach(cb => cb.checked = false);
            updateCount();
        }
        
        function updateCount() {
            const count = document.querySelectorAll('.course-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count + ' selected';
        }
        
        function filterEnrolled() {
            const search = document.getElementById('searchEnrolled').value.toLowerCase();
            document.querySelectorAll('.enrolled-row').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        }
        
        function updateDeregCount() {
            const count = document.querySelectorAll('.dereg-cb:checked').length;
            const badge = document.getElementById('deregCount');
            if (count > 0) {
                badge.style.display = 'inline-block';
                badge.textContent = count + ' selected for removal';
            } else {
                badge.style.display = 'none';
            }
        }
    </script>
</body>
</html>
