<?php
/**
 * Dean Portal - Students Overview
 * View and monitor students in the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Detect program column name in students table
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query
$where = [];
$params = [];
$types = "";

if ($filter_program) {
    $where[] = "s.$program_col = ?";
    $params[] = $filter_program;
    $types .= "s";
}

if ($filter_year) {
    $where[] = "s.year_of_study = ?";
    $params[] = $filter_year;
    $types .= "i";
}

if ($filter_search) {
    $where[] = "(s.full_name LIKE ? OR s.email LIKE ? OR s.student_id LIKE ?)";
    $search = "%$filter_search%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count total
$count_sql = "SELECT COUNT(*) as total FROM students s $where_sql";
if (!empty($params)) {
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total / $per_page);

// Get students
$sql = "SELECT s.*, p.program_name, p.program_code
        FROM students s
        LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
        $where_sql
        ORDER BY s.full_name
        LIMIT $per_page OFFSET $offset";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$students = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get programs for filter
$programs = [];
$prog_result = $conn->query("SELECT program_id, program_code, program_name FROM programs ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Stats
$stats_result = $conn->query("SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN year_of_study = 1 THEN 1 END) as year1,
    COUNT(CASE WHEN year_of_study = 2 THEN 1 END) as year2,
    COUNT(CASE WHEN year_of_study = 3 THEN 1 END) as year3,
    COUNT(CASE WHEN year_of_study = 4 THEN 1 END) as year4
    FROM students");
$stats = $stats_result ? $stats_result->fetch_assoc() : ['total' => 0, 'year1' => 0, 'year2' => 0, 'year3' => 0, 'year4' => 0];

$page_title = "Students";
$breadcrumbs = [['title' => 'Students']];
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
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-primary"><?= number_format($stats['total']) ?></div>
                        <small class="text-muted">Total Students</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-success"><?= number_format($stats['year1']) ?></div>
                        <small class="text-muted">Year 1</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-info"><?= number_format($stats['year2']) ?></div>
                        <small class="text-muted">Year 2</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-warning"><?= number_format($stats['year3']) ?></div>
                        <small class="text-muted">Year 3</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-danger"><?= number_format($stats['year4']) ?></div>
                        <small class="text-muted">Year 4</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-secondary"><?= count($programs) ?></div>
                        <small class="text-muted">Programs</small>
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
                            <option value="<?= htmlspecialchars($prog['program_code']) ?>" <?= $filter_program === $prog['program_code'] ? 'selected' : '' ?>><?= htmlspecialchars($prog['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <option value="1" <?= $filter_year == '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year == '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year == '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year == '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Search by name, email, or student ID...">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Students</h5>
                <span class="badge bg-primary"><?= number_format($total) ?> students found</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th>Email</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No students found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-weight: 700;">
                                            <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                                        </div>
                                        <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($student['student_id']) ?></code></td>
                                <td><?= htmlspecialchars($student['program_name'] ?? $student['program_of_study'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-info">Year <?= $student['year_of_study'] ?? 'N/A' ?></span></td>
                                <td><?= htmlspecialchars($student['email']) ?></td>
                                <td><?= htmlspecialchars($student['phone'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&search=<?= urlencode($filter_search) ?>">Previous</a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&search=<?= urlencode($filter_search) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&search=<?= urlencode($filter_search) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center small text-muted mt-2">
                    Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total) ?> of <?= number_format($total) ?> students
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
