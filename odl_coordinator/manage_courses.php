<?php
/**
 * ODL Coordinator - Course Management
 * Manage courses for ODL program
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $course_code = trim($_POST['course_code']);
                $course_name = trim($_POST['course_name']);
                $description = trim($_POST['description']);
                $credits = (int)$_POST['credits'];
                $program = trim($_POST['program_of_study']);
                $year_of_study = (int)$_POST['year_of_study'];
                $semester = $_POST['semester'];
                $total_weeks = (int)$_POST['total_weeks'];
                
                // Check for duplicate
                $check = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_code = ?");
                $check->bind_param("s", $course_code);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error_message = "Course code '$course_code' already exists.";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO vle_courses (course_code, course_name, description, credits, program_of_study, year_of_study, semester, total_weeks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("sssisiss", $course_code, $course_name, $description, $credits, $program, $year_of_study, $semester, $total_weeks);
                    if ($stmt->execute()) {
                        $success_message = "Course added successfully.";
                    } else {
                        $error_message = "Failed to add course: " . $conn->error;
                    }
                }
                break;
                
            case 'edit':
                $course_id = (int)$_POST['course_id'];
                $course_code = trim($_POST['course_code']);
                $course_name = trim($_POST['course_name']);
                $description = trim($_POST['description']);
                $credits = (int)$_POST['credits'];
                $program = trim($_POST['program_of_study']);
                $year_of_study = (int)$_POST['year_of_study'];
                $semester = $_POST['semester'];
                $total_weeks = (int)$_POST['total_weeks'];
                
                $stmt = $conn->prepare("
                    UPDATE vle_courses 
                    SET course_code = ?, course_name = ?, description = ?, credits = ?, 
                        program_of_study = ?, year_of_study = ?, semester = ?, total_weeks = ?
                    WHERE course_id = ?
                ");
                $stmt->bind_param("sssisissi", $course_code, $course_name, $description, $credits, $program, $year_of_study, $semester, $total_weeks, $course_id);
                if ($stmt->execute()) {
                    $success_message = "Course updated successfully.";
                } else {
                    $error_message = "Failed to update course.";
                }
                break;
                
            case 'delete':
                $course_id = (int)$_POST['course_id'];
                // Check for enrollments
                $check = $conn->query("SELECT COUNT(*) as c FROM vle_enrollments WHERE course_id = $course_id");
                $count = $check->fetch_assoc()['c'];
                if ($count > 0) {
                    $error_message = "Cannot delete course with $count enrolled students. Remove enrollments first.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM vle_courses WHERE course_id = ?");
                    $stmt->bind_param("i", $course_id);
                    if ($stmt->execute()) {
                        $success_message = "Course deleted successfully.";
                    } else {
                        $error_message = "Failed to delete course.";
                    }
                }
                break;
        }
    }
}

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if ($filter_program) {
    $where[] = "c.program_of_study = ?";
    $params[] = $filter_program;
    $types .= "s";
}
if ($filter_year) {
    $where[] = "c.year_of_study = ?";
    $params[] = $filter_year;
    $types .= "i";
}
if ($filter_search) {
    $where[] = "(c.course_code LIKE ? OR c.course_name LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $types .= "ss";
}

$where_sql = implode(" AND ", $where);

$sql = "
    SELECT c.*, l.full_name as lecturer_name,
           (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled_count
    FROM vle_courses c
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    WHERE $where_sql
    ORDER BY c.course_code
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$courses = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get programs for filter
$programs = [];
$prog_result = $conn->query("SELECT DISTINCT program_of_study FROM vle_courses WHERE program_of_study IS NOT NULL AND program_of_study != '' ORDER BY program_of_study");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row['program_of_study'];
    }
}

// Get lecturers for allocation dropdown
$lecturers = [];
$lect_result = $conn->query("SELECT lecturer_id, full_name FROM lecturers ORDER BY full_name");
if ($lect_result) {
    while ($row = $lect_result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

$page_title = 'Course Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Management - ODL Coordinator</title>
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
                <h1 class="h3 mb-1"><i class="bi bi-book me-2"></i>Course Management</h1>
                <p class="text-muted mb-0">Manage ODL program courses</p>
            </div>
            <div>
                <a href="course_allocation.php" class="btn btn-outline-primary me-2">
                    <i class="bi bi-person-gear me-1"></i>Allocate Lecturers
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Course
                </button>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= count($courses) ?></div>
                        <small>Total Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= count(array_filter($courses, fn($c) => $c['lecturer_id'])) ?></div>
                        <small>With Lecturer</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= count(array_filter($courses, fn($c) => !$c['lecturer_id'])) ?></div>
                        <small>No Lecturer</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= array_sum(array_column($courses, 'enrolled_count')) ?></div>
                        <small>Total Enrollments</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Program</label>
                        <select name="program" class="form-select form-select-sm">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $filter_program === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <option value="1" <?= $filter_year == '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year == '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year == '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year == '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Code or name..." value="<?= htmlspecialchars($filter_search) ?>">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="manage_courses.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Courses Table -->
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0">Courses (<?= count($courses) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th>Program</th>
                                <th>Year/Sem</th>
                                <th>Lecturer</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No courses found</td></tr>
                            <?php else: ?>
                            <?php foreach ($courses as $c): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($c['course_code']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($c['course_name']) ?></strong>
                                    <?php if (!empty($c['credits'])): ?>
                                    <div class="small text-muted"><?= $c['credits'] ?> credits</div>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= htmlspecialchars($c['program_of_study'] ?? '-') ?></small></td>
                                <td>
                                    <?php
                                        $yrs = [$c['year_of_study'] ?? '?'];
                                        if (!empty($c['applicable_years'])) $yrs = array_merge($yrs, array_map('trim', explode(',', $c['applicable_years'])));
                                        $yrs = array_unique($yrs); sort($yrs);
                                    ?>
                                    <span class="badge bg-secondary">Y<?= implode(',', $yrs) ?></span>
                                    <span class="badge bg-outline-secondary border"><?= $c['semester'] === 'Both' ? 'S1&2' : 'S' . ($c['semester'] === 'Two' ? '2' : '1') ?></span>
                                </td>
                                <td>
                                    <?php if ($c['lecturer_name']): ?>
                                    <span class="text-success"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($c['lecturer_name']) ?></span>
                                    <?php else: ?>
                                    <span class="text-danger"><i class="bi bi-person-x me-1"></i>Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-info"><?= $c['enrolled_count'] ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="editCourse(<?= htmlspecialchars(json_encode($c)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="student_enrollment.php?course=<?= $c['course_id'] ?>" class="btn btn-sm btn-outline-success" title="Manage Students">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Course Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add New Course</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Course Code <span class="text-danger">*</span></label>
                                <input type="text" name="course_code" class="form-control" required placeholder="e.g., CS101">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Course Name <span class="text-danger">*</span></label>
                                <input type="text" name="course_name" class="form-control" required placeholder="e.g., Introduction to Programming">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Program of Study</label>
                                <input type="text" name="program_of_study" class="form-control" placeholder="e.g., Computer Science">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Credits</label>
                                <input type="number" name="credits" class="form-control" value="3" min="1" max="20">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Year</label>
                                <select name="year_of_study" class="form-select">
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Semester</label>
                                <select name="semester" class="form-select">
                                    <option value="One">One</option>
                                    <option value="Two">Two</option>
                                    <option value="Both">Both</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Weeks</label>
                                <input type="number" name="total_weeks" class="form-control" value="16" min="1">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Course Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="course_id" id="edit_course_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Course</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Course Code <span class="text-danger">*</span></label>
                                <input type="text" name="course_code" id="edit_course_code" class="form-control" required>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Course Name <span class="text-danger">*</span></label>
                                <input type="text" name="course_name" id="edit_course_name" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Program of Study</label>
                                <input type="text" name="program_of_study" id="edit_program" class="form-control">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Credits</label>
                                <input type="number" name="credits" id="edit_credits" class="form-control" min="1" max="20">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Year</label>
                                <select name="year_of_study" id="edit_year" class="form-select">
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Semester</label>
                                <select name="semester" id="edit_semester" class="form-select">
                                    <option value="One">One</option>
                                    <option value="Two">Two</option>
                                    <option value="Both">Both</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Weeks</label>
                                <input type="number" name="total_weeks" id="edit_weeks" class="form-control" min="1">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editCourse(course) {
            document.getElementById('edit_course_id').value = course.course_id;
            document.getElementById('edit_course_code').value = course.course_code;
            document.getElementById('edit_course_name').value = course.course_name;
            document.getElementById('edit_description').value = course.description || '';
            document.getElementById('edit_program').value = course.program_of_study || '';
            document.getElementById('edit_credits').value = course.credits || 3;
            document.getElementById('edit_year').value = course.year_of_study || 1;
            document.getElementById('edit_semester').value = course.semester || 'One';
            document.getElementById('edit_weeks').value = course.total_weeks || 16;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    </script>
</body>
</html>
