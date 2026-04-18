<?php
/**
 * Dean Portal - Courses Overview
 * View and monitor courses in the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Determine which table to use
$course_table = 'vle_courses';
$table_check = $conn->query("SHOW TABLES LIKE 'vle_courses'");
if (!$table_check || $table_check->num_rows == 0) {
    $table_check = $conn->query("SHOW TABLES LIKE 'courses'");
    if ($table_check && $table_check->num_rows > 0) {
        $course_table = 'courses';
    }
}

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_lecturer = $_GET['lecturer'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query
$where = [];
$params = [];
$types = "";

if ($filter_program) {
    $where[] = "c.program_of_study = ?";
    $params[] = $filter_program;
    $types .= "s";
}

if ($filter_lecturer) {
    $where[] = "c.lecturer_id = ?";
    $params[] = $filter_lecturer;
    $types .= "i";
}

if ($filter_search) {
    $where[] = "(c.course_code LIKE ? OR c.course_name LIKE ?)";
    $search = "%$filter_search%";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get courses
$sql = "SELECT c.*, l.full_name as lecturer_name,
        (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled_students
        FROM $course_table c
        LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
        $where_sql
        ORDER BY c.course_code";

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
$prog_result = $conn->query("SELECT DISTINCT program_of_study FROM $course_table WHERE program_of_study IS NOT NULL ORDER BY program_of_study");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row['program_of_study'];
    }
}

// Get lecturers for filter
$lecturers = [];
$lec_result = $conn->query("SELECT lecturer_id, full_name FROM lecturers ORDER BY full_name");
if ($lec_result) {
    while ($row = $lec_result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Stats
$total_courses = count($courses);
$active_courses = 0;
$total_enrollments = 0;
foreach ($courses as $c) {
    if (($c['is_active'] ?? 1) == 1) $active_courses++;
    $total_enrollments += $c['enrolled_students'] ?? 0;
}

$page_title = "Courses";
$breadcrumbs = [['title' => 'Courses']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-primary"><?= $total_courses ?></div>
                        <small class="text-muted">Total Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success"><?= $active_courses ?></div>
                        <small class="text-muted">Active Courses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= $total_enrollments ?></div>
                        <small class="text-muted">Total Enrollments</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog) ?>" <?= $filter_program === $prog ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lecturer</label>
                        <select name="lecturer" class="form-select">
                            <option value="">All Lecturers</option>
                            <?php foreach ($lecturers as $lec): ?>
                            <option value="<?= $lec['lecturer_id'] ?>" <?= $filter_lecturer == $lec['lecturer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($lec['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Search by course code or name...">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Courses Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-book me-2"></i>Faculty Courses</h5>
                <span class="badge bg-primary"><?= $total_courses ?> courses</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Program</th>
                                <th>Lecturer</th>
                                <th>Year/Sem</th>
                                <th>Students</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No courses found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($courses as $course): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($course['course_code']) ?></strong></td>
                                <td><?= htmlspecialchars($course['course_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($course['program_of_study'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($course['lecturer_name'] ?? 'Unassigned') ?></td>
                                <td>Year <?= $course['year_of_study'] ?? 1 ?>, Sem <?= $course['semester'] ?? 1 ?></td>
                                <td><span class="badge bg-info"><?= $course['enrolled_students'] ?? 0 ?></span></td>
                                <td>
                                    <?php if (($course['is_active'] ?? 1) == 1): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
