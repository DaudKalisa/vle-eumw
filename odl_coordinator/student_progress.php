<?php
/**
 * ODL Coordinator - Student Progress Reports
 * View and analyze student academic progress
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Detect program column name in students table
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

// Build query for students with progress data
$where = ["s.is_active = 1"];
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

if ($search) {
    $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Get students with their progress
$sql = "SELECT s.student_id, s.full_name, s.email, s.$program_col as program, s.year_of_study,
        (SELECT COUNT(*) FROM vle_enrollments ve WHERE ve.student_id = s.student_id) as enrolled_courses,
        (SELECT COUNT(*) FROM vle_submissions vs WHERE vs.student_id = s.student_id) as total_submissions,
        (SELECT COUNT(DISTINCT va.course_id) FROM vle_submissions vs 
            JOIN vle_assignments va ON vs.assignment_id = va.assignment_id 
            WHERE vs.student_id = s.student_id) as courses_with_submissions,
        (SELECT AVG(vs.score) FROM vle_submissions vs WHERE vs.student_id = s.student_id AND vs.score IS NOT NULL) as avg_score
        FROM students s
        $where_sql
        ORDER BY s.full_name
        LIMIT 100";

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
        // Categorize progress status
        $enrolled = (int)$row['enrolled_courses'];
        $submissions = (int)$row['total_submissions'];
        $avg_score = $row['avg_score'] ? round($row['avg_score'], 1) : null;
        
        if ($enrolled == 0) {
            $row['progress_status'] = 'not_enrolled';
            $row['status_class'] = 'secondary';
            $row['status_label'] = 'Not Enrolled';
        } elseif ($submissions == 0) {
            $row['progress_status'] = 'no_activity';
            $row['status_class'] = 'danger';
            $row['status_label'] = 'No Activity';
        } elseif ($avg_score !== null && $avg_score >= 70) {
            $row['progress_status'] = 'excellent';
            $row['status_class'] = 'success';
            $row['status_label'] = 'Excellent';
        } elseif ($avg_score !== null && $avg_score >= 50) {
            $row['progress_status'] = 'good';
            $row['status_class'] = 'info';
            $row['status_label'] = 'Good Progress';
        } else {
            $row['progress_status'] = 'needs_attention';
            $row['status_class'] = 'warning';
            $row['status_label'] = 'Needs Attention';
        }
        
        // Apply status filter
        if ($filter_status && $row['progress_status'] !== $filter_status) {
            continue;
        }
        
        $students[] = $row;
    }
}

// Get programs for filter
$programs = [];
$prog_result = $conn->query("SELECT DISTINCT s.$program_col as program_value FROM students s WHERE s.$program_col IS NOT NULL AND s.$program_col != '' ORDER BY s.$program_col");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row['program_value'];
    }
}

// Summary stats
$stats = [
    'total' => count($students),
    'excellent' => count(array_filter($students, fn($s) => $s['progress_status'] === 'excellent')),
    'good' => count(array_filter($students, fn($s) => $s['progress_status'] === 'good')),
    'needs_attention' => count(array_filter($students, fn($s) => $s['progress_status'] === 'needs_attention')),
    'no_activity' => count(array_filter($students, fn($s) => $s['progress_status'] === 'no_activity')),
    'not_enrolled' => count(array_filter($students, fn($s) => $s['progress_status'] === 'not_enrolled')),
];

$page_title = 'Student Progress Reports';
$breadcrumbs = [
    ['title' => 'Students', 'url' => 'student_verification.php'],
    ['title' => 'Progress Reports']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .stat-card { padding: 1.5rem; border-radius: 0.75rem; text-align: center; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .progress-bar-custom { height: 8px; border-radius: 4px; background: #e9ecef; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.5s; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="student_verification.php">Students</a></li>
                        <li class="breadcrumb-item active">Progress Reports</li>
                    </ol>
                </nav>
                <h1 class="h3 mb-1"><i class="bi bi-graph-up me-2"></i>Student Progress Reports</h1>
                <p class="text-muted mb-0">Analyze student academic progress and engagement</p>
            </div>
            <a href="student_verification.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Students
            </a>
        </div>
        
        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stat-card bg-primary bg-opacity-10 border border-primary">
                    <div class="h3 mb-0"><?= number_format($stats['total']) ?></div>
                    <small class="text-muted">Total Students</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card bg-success bg-opacity-10 border border-success cursor-pointer" onclick="filterByStatus('excellent')">
                    <div class="h3 mb-0 text-success"><?= number_format($stats['excellent']) ?></div>
                    <small class="text-muted">Excellent</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card bg-info bg-opacity-10 border border-info cursor-pointer" onclick="filterByStatus('good')">
                    <div class="h3 mb-0 text-info"><?= number_format($stats['good']) ?></div>
                    <small class="text-muted">Good Progress</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card bg-warning bg-opacity-10 border border-warning cursor-pointer" onclick="filterByStatus('needs_attention')">
                    <div class="h3 mb-0 text-warning"><?= number_format($stats['needs_attention']) ?></div>
                    <small class="text-muted">Needs Attention</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card bg-danger bg-opacity-10 border border-danger cursor-pointer" onclick="filterByStatus('no_activity')">
                    <div class="h3 mb-0 text-danger"><?= number_format($stats['no_activity']) ?></div>
                    <small class="text-muted">No Activity</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card bg-secondary bg-opacity-10 border border-secondary cursor-pointer" onclick="filterByStatus('not_enrolled')">
                    <div class="h3 mb-0 text-secondary"><?= number_format($stats['not_enrolled']) ?></div>
                    <small class="text-muted">Not Enrolled</small>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">Search Student</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, ID, Email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog) ?>" <?= $filter_program === $prog ? 'selected' : '' ?>><?= htmlspecialchars($prog) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year of Study</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= $filter_year == $i ? 'selected' : '' ?>>Year <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Progress Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="excellent" <?= $filter_status === 'excellent' ? 'selected' : '' ?>>Excellent</option>
                            <option value="good" <?= $filter_status === 'good' ? 'selected' : '' ?>>Good Progress</option>
                            <option value="needs_attention" <?= $filter_status === 'needs_attention' ? 'selected' : '' ?>>Needs Attention</option>
                            <option value="no_activity" <?= $filter_status === 'no_activity' ? 'selected' : '' ?>>No Activity</option>
                            <option value="not_enrolled" <?= $filter_status === 'not_enrolled' ? 'selected' : '' ?>>Not Enrolled</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="student_progress.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Student Progress (<?= count($students) ?>)</h5>
                <button class="btn btn-sm btn-outline-success" onclick="exportCSV()">
                    <i class="bi bi-download me-1"></i>Export CSV
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="progressTable">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th class="text-center">Courses</th>
                                <th class="text-center">Submissions</th>
                                <th class="text-center">Avg Score</th>
                                <th class="text-center">Status</th>
                                <th>Progress</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox display-4"></i>
                                    <p class="mt-2 mb-0">No students found matching your criteria</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($students as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($s['student_id']) ?></div>
                                </td>
                                <td><small><?= htmlspecialchars($s['program'] ?? 'N/A') ?></small></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary">Y<?= $s['year_of_study'] ?? '?' ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $s['enrolled_courses'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= $s['total_submissions'] ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($s['avg_score'] !== null): ?>
                                    <span class="fw-bold <?= $s['avg_score'] >= 70 ? 'text-success' : ($s['avg_score'] >= 50 ? 'text-info' : 'text-warning') ?>">
                                        <?= round($s['avg_score'], 1) ?>%
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $s['status_class'] ?>"><?= $s['status_label'] ?></span>
                                </td>
                                <td style="width: 120px;">
                                    <?php 
                                    $progress = 0;
                                    if ($s['enrolled_courses'] > 0) {
                                        $completion = $s['courses_with_submissions'] / $s['enrolled_courses'] * 100;
                                        $progress = min(100, $completion);
                                    }
                                    ?>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill bg-<?= $s['status_class'] ?>" style="width: <?= $progress ?>%;"></div>
                                    </div>
                                    <small class="text-muted"><?= round($progress) ?>% complete</small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewDetails('<?= $s['student_id'] ?>')">
                                        <i class="bi bi-eye"></i>
                                    </button>
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
    
    <!-- Student Details Modal -->
    <div class="modal fade" id="studentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person me-2"></i>Student Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentModalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2 text-muted">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDetails(studentId) {
            const modal = new bootstrap.Modal(document.getElementById('studentModal'));
            document.getElementById('studentModalBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading...</p></div>';
            modal.show();
            
            fetch('get_student_details.php?id=' + studentId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('studentModalBody').innerHTML = html;
                })
                .catch(err => {
                    document.getElementById('studentModalBody').innerHTML = '<div class="alert alert-danger">Failed to load student details</div>';
                });
        }
        
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }
        
        function exportCSV() {
            const table = document.getElementById('progressTable');
            const rows = table.querySelectorAll('tbody tr');
            
            let csv = 'Student ID,Name,Program,Year,Courses,Submissions,Avg Score,Status\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 7) {
                    const studentId = cells[0].querySelector('.small')?.textContent?.trim() || '';
                    const name = cells[0].querySelector('strong')?.textContent?.trim() || '';
                    const program = cells[1].textContent?.trim() || '';
                    const year = cells[2].textContent?.trim() || '';
                    const courses = cells[3].textContent?.trim() || '';
                    const submissions = cells[4].textContent?.trim() || '';
                    const avgScore = cells[5].textContent?.trim() || '';
                    const status = cells[6].textContent?.trim() || '';
                    
                    csv += `"${studentId}","${name}","${program}","${year}","${courses}","${submissions}","${avgScore}","${status}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'student_progress_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }
    </script>
</body>
</html>
