<?php
/**
 * HOD - Department Courses
 * View and manage courses within the HOD's department
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['hod', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get HOD department
$hod_department = '';
if (!empty($user['related_staff_id'])) {
    $stmt = $conn->prepare("SELECT department FROM administrative_staff WHERE staff_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['related_staff_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $hod_department = $row['department'] ?? '';
    }
}

// Filters
$filter_year = $_GET['year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
$where = ["1=1"];
$params = [];
$types = '';

if ($hod_department) {
    $where[] = "c.program_of_study LIKE ?";
    $params[] = '%' . $hod_department . '%';
    $types .= 's';
}

if ($filter_year) {
    $where[] = "(c.year_of_study = ? OR FIND_IN_SET(?, COALESCE(c.applicable_years, '')))";
    $params[] = $filter_year;
    $params[] = $filter_year;
    $types .= 'ii';
}

if ($filter_semester) {
    $where[] = "(c.semester = ? OR c.semester = 'Both')";
    $params[] = $filter_semester;
    $types .= 's';
}

if ($filter_status === '1') {
    $where[] = "c.is_active = 1";
} elseif ($filter_status === '0') {
    $where[] = "c.is_active = 0";
}

if ($search) {
    $where[] = "(c.course_code LIKE ? OR c.course_name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';
}

$sql = "SELECT c.*, l.full_name as lecturer_name,
        (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled_count
        FROM vle_courses c
        LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.year_of_study, c.semester, c.course_code";

$stmt = $conn->prepare($sql);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = "Department Courses";
$breadcrumbs = [['title' => 'Courses']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses - HOD Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-book me-2 text-primary"></i>Department Courses</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($hod_department ?: 'All Departments') ?> — <?= count($courses) ?> course(s)</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Code or name..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All</option>
                            <option value="One" <?= $filter_semester === 'One' ? 'selected' : '' ?>>Semester 1</option>
                            <option value="Two" <?= $filter_semester === 'Two' ? 'selected' : '' ?>>Semester 2</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="courses.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Courses Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-book display-4 d-block mb-3"></i>
                    <p>No courses found.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th>Year</th>
                                <th>Semester</th>
                                <th>Lecturer</th>
                                <th>Enrolled</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $c): 
                                $allYears = [$c['year_of_study']];
                                if (!empty($c['applicable_years'])) {
                                    $allYears = array_merge($allYears, array_map('trim', explode(',', $c['applicable_years'])));
                                }
                                $allYears = array_unique($allYears); sort($allYears);
                                $semDisplay = ($c['semester'] === 'Both') ? 'Sem 1 & 2' : 'Sem ' . ($c['semester'] === 'Two' ? '2' : '1');
                            ?>
                            <tr>
                                <td><code class="fw-bold"><?= htmlspecialchars($c['course_code']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($c['course_name']) ?></strong>
                                    <?php if (!empty($c['program_of_study'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($c['program_of_study']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($allYears as $y): ?>
                                    <span class="badge bg-secondary">Y<?= $y ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td><span class="badge bg-info"><?= $semDisplay ?></span></td>
                                <td>
                                    <?php if ($c['lecturer_name']): ?>
                                    <span class="text-success"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($c['lecturer_name']) ?></span>
                                    <?php else: ?>
                                    <span class="text-danger"><i class="bi bi-person-x me-1"></i>Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-primary"><?= $c['enrolled_count'] ?></span></td>
                                <td>
                                    <?php if ($c['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
