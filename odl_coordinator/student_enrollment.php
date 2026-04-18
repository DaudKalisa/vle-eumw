<?php
/**
 * ODL Coordinator - Student Enrollment
 * Allocate students to courses
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

$success_message = '';
$error_message = '';

// Selected course
$selected_course = $_GET['course'] ?? '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enroll':
                $student_id = trim($_POST['student_id']);
                $course_id = (int)$_POST['course_id'];
                
                // Check if already enrolled
                $check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                $check->bind_param("si", $student_id, $course_id);
                $check->execute();
                
                if ($check->get_result()->num_rows > 0) {
                    $error_message = "Student is already enrolled in this course.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                    $stmt->bind_param("si", $student_id, $course_id);
                    if ($stmt->execute()) {
                        $success_message = "Student enrolled successfully.";
                    } else {
                        $error_message = "Failed to enroll student.";
                    }
                }
                break;
                
            case 'unenroll':
                $enrollment_id = (int)$_POST['enrollment_id'];
                $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE enrollment_id = ?");
                $stmt->bind_param("i", $enrollment_id);
                if ($stmt->execute()) {
                    $success_message = "Student unenrolled successfully.";
                } else {
                    $error_message = "Failed to unenroll student.";
                }
                break;
                
            case 'bulk_enroll':
                $course_id = (int)$_POST['course_id'];
                $student_ids = $_POST['student_ids'] ?? [];
                
                if (!empty($student_ids)) {
                    $success_count = 0;
                    $skip_count = 0;
                    foreach ($student_ids as $student_id) {
                        // Check if already enrolled
                        $check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                        $check->bind_param("si", $student_id, $course_id);
                        $check->execute();
                        
                        if ($check->get_result()->num_rows === 0) {
                            $stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                            $stmt->bind_param("si", $student_id, $course_id);
                            if ($stmt->execute()) {
                                $success_count++;
                            }
                        } else {
                            $skip_count++;
                        }
                    }
                    $success_message = "Enrolled $success_count students." . ($skip_count > 0 ? " Skipped $skip_count already enrolled." : "");
                } else {
                    $error_message = "No students selected.";
                }
                break;
                
            case 'bulk_unenroll':
                $enrollment_ids = $_POST['enrollment_ids'] ?? [];
                
                if (!empty($enrollment_ids)) {
                    $success_count = 0;
                    foreach ($enrollment_ids as $enrollment_id) {
                        $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE enrollment_id = ?");
                        $stmt->bind_param("i", $enrollment_id);
                        if ($stmt->execute()) {
                            $success_count++;
                        }
                    }
                    $success_message = "Unenrolled $success_count students.";
                } else {
                    $error_message = "No enrollments selected.";
                }
                break;
        }
    }
}

// Get courses
$courses = [];
$result = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses ORDER BY course_code");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get current enrollments for selected course
$enrollments = [];
if ($selected_course) {
    $stmt = $conn->prepare("
        SELECT e.*, s.full_name, s.email, s.phone, s.program
        FROM vle_enrollments e
        JOIN students s ON e.student_id = s.student_id
        WHERE e.course_id = ?
        ORDER BY s.full_name
    ");
    $stmt->bind_param("i", $selected_course);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $enrollments[] = $row;
    }
}

// Get all students (for enrollment dropdown)
$students = [];
$search = $_GET['search'] ?? '';
$student_sql = "SELECT student_id, full_name, email, program FROM students";
if ($search) {
    $student_sql .= " WHERE full_name LIKE '%" . $conn->real_escape_string($search) . "%' OR student_id LIKE '%" . $conn->real_escape_string($search) . "%'";
}
$student_sql .= " ORDER BY full_name LIMIT 100";
$result = $conn->query($student_sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get enrolled student IDs for the selected course (to exclude from available list)
$enrolled_ids = array_column($enrollments, 'student_id');

$page_title = 'Student Enrollment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Enrollment - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-people me-2"></i>Student Enrollment</h1>
                <p class="text-muted mb-0">Allocate students to courses</p>
            </div>
            <a href="manage_courses.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Courses
            </a>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Course Selection -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Select Course</label>
                        <select name="course" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select a Course --</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['course_id'] ?>" <?= $selected_course == $c['course_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($selected_course): ?>
                    <div class="col-md-4">
                        <label class="form-label">Search Students</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by name or ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <?php if ($selected_course): ?>
        <div class="row">
            <!-- Enrolled Students -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-person-check me-2"></i>Enrolled Students (<?= count($enrollments) ?>)</h6>
                    </div>
                    <div class="card-body p-0">
                        <form method="POST" id="unenrollForm">
                            <input type="hidden" name="action" value="bulk_unenroll">
                            <div class="p-2 border-bottom bg-light">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Unenroll selected students?')">
                                    <i class="bi bi-x-lg me-1"></i>Unenroll Selected
                                </button>
                            </div>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th style="width: 30px;"><input type="checkbox" id="selectEnrolled" onchange="toggleEnrolled()"></th>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Enrolled</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($enrollments)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No students enrolled</td></tr>
                                        <?php else: ?>
                                        <?php foreach ($enrollments as $e): ?>
                                        <tr>
                                            <td><input type="checkbox" name="enrollment_ids[]" value="<?= $e['enrollment_id'] ?>" class="enrolled-checkbox"></td>
                                            <td><code><?= htmlspecialchars($e['student_id']) ?></code></td>
                                            <td>
                                                <?= htmlspecialchars($e['full_name']) ?>
                                                <div class="small text-muted"><?= htmlspecialchars($e['program'] ?? '') ?></div>
                                            </td>
                                            <td><small><?= date('M j, Y', strtotime($e['enrollment_date'])) ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Available Students -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-person-plus me-2"></i>Available Students</h6>
                    </div>
                    <div class="card-body p-0">
                        <form method="POST" id="enrollForm">
                            <input type="hidden" name="action" value="bulk_enroll">
                            <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                            <div class="p-2 border-bottom bg-light">
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Enroll selected students?')">
                                    <i class="bi bi-plus-lg me-1"></i>Enroll Selected
                                </button>
                            </div>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th style="width: 30px;"><input type="checkbox" id="selectAvailable" onchange="toggleAvailable()"></th>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Program</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $available = array_filter($students, fn($s) => !in_array($s['student_id'], $enrolled_ids));
                                        if (empty($available)): 
                                        ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No available students found</td></tr>
                                        <?php else: ?>
                                        <?php foreach ($available as $s): ?>
                                        <tr>
                                            <td><input type="checkbox" name="student_ids[]" value="<?= htmlspecialchars($s['student_id']) ?>" class="available-checkbox"></td>
                                            <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                                            <td><small><?= htmlspecialchars($s['program'] ?? '-') ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Add -->
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Enroll</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="enroll">
                    <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                    <div class="col-md-4">
                        <label class="form-label">Student ID</label>
                        <input type="text" name="student_id" class="form-control" placeholder="Enter student ID" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-lg me-1"></i>Enroll
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>Please select a course to manage student enrollments.
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleEnrolled() {
            const checkboxes = document.querySelectorAll('.enrolled-checkbox');
            const selectAll = document.getElementById('selectEnrolled');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
        
        function toggleAvailable() {
            const checkboxes = document.querySelectorAll('.available-checkbox');
            const selectAll = document.getElementById('selectAvailable');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
    </script>
</body>
</html>
