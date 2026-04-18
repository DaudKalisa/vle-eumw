<?php
/**
 * ODL Coordinator - Student Verification Page
 * Verify student registration, login activity, and course access
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Detect program column name in students table
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

// Filter parameters
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_program = $_GET['program'] ?? '';
$filter_activity = $_GET['activity'] ?? '';

// Build query
$where_clauses = ["s.is_active = TRUE"];
$params = [];
$types = "";

if ($search) {
    $where_clauses[] = "(s.student_id LIKE ? OR s.full_name LIKE ? OR s.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($filter_program) {
    $where_clauses[] = "s.$program_col = ?";
    $params[] = $filter_program;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Activity subquery
$activity_having = "";
if ($filter_activity === 'active_7') {
    $activity_having = "HAVING last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter_activity === 'active_30') {
    $activity_having = "HAVING last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filter_activity === 'inactive') {
    $activity_having = "HAVING last_login IS NULL OR last_login < DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filter_activity === 'never') {
    $activity_having = "HAVING last_login IS NULL";
}

$sql = "
    SELECT s.*, p.program_name,
           u.user_id, u.username, u.is_active as user_active,
           (SELECT MAX(login_time) FROM login_history lh WHERE lh.user_id = u.user_id) as last_login,
           (SELECT COUNT(*) FROM login_history lh WHERE lh.user_id = u.user_id) as login_count,
           (SELECT COUNT(*) FROM vle_enrollments ve WHERE ve.student_id = s.student_id) as enrolled_courses,
           (SELECT COUNT(*) FROM vle_submissions vs WHERE vs.student_id = s.student_id) as submissions
    FROM students s
    LEFT JOIN users u ON s.student_id = u.related_student_id
    LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
    $where_sql
    GROUP BY s.student_id
    $activity_having
    ORDER BY s.full_name
    LIMIT 100
";

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

// Get programs for filter - get distinct values from students table
$programs = [];
$prog_result = $conn->query("SELECT DISTINCT s.$program_col as program_value, COALESCE(p.program_name, s.$program_col) as program_name 
                             FROM students s 
                             LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
                             WHERE s.$program_col IS NOT NULL AND s.$program_col != ''
                             ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Statistics
$stats = [];
$total_result = $conn->query("SELECT COUNT(*) as total FROM students WHERE is_active = TRUE");
$stats['total'] = $total_result ? $total_result->fetch_assoc()['total'] : 0;

$active_7_result = $conn->query("
    SELECT COUNT(DISTINCT s.student_id) as total 
    FROM students s 
    JOIN users u ON s.student_id = u.related_student_id
    JOIN login_history lh ON u.user_id = lh.user_id
    WHERE s.is_active = TRUE AND lh.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stats['active_7'] = $active_7_result ? $active_7_result->fetch_assoc()['total'] : 0;

$active_30_result = $conn->query("
    SELECT COUNT(DISTINCT s.student_id) as total 
    FROM students s 
    JOIN users u ON s.student_id = u.related_student_id
    JOIN login_history lh ON u.user_id = lh.user_id
    WHERE s.is_active = TRUE AND lh.login_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stats['active_30'] = $active_30_result ? $active_30_result->fetch_assoc()['total'] : 0;

$never_result = $conn->query("
    SELECT COUNT(*) as total 
    FROM students s 
    LEFT JOIN users u ON s.student_id = u.related_student_id
    WHERE s.is_active = TRUE AND u.user_id NOT IN (SELECT DISTINCT user_id FROM login_history)
");
$stats['never_logged'] = $never_result ? $never_result->fetch_assoc()['total'] : 0;

$page_title = 'Student Verification';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Verification - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .stat-card {
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .student-row { transition: all 0.2s; }
        .student-row:hover { background: #f8f9fa; }
        .activity-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .activity-active { background: #2ecc71; }
        .activity-inactive { background: #f39c12; }
        .activity-never { background: #e74c3c; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-person-check me-2"></i>Student Verification</h1>
                <p class="text-muted mb-0">Verify student registration, login activity, and course access</p>
            </div>
            <a href="student_progress.php" class="btn btn-outline-primary">
                <i class="bi bi-graph-up me-1"></i>View Progress Reports
            </a>
        </div>
        
        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card bg-primary bg-opacity-10 border border-primary">
                    <i class="bi bi-people display-6 text-primary"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['total']) ?></div>
                    <small class="text-muted">Total Students</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success bg-opacity-10 border border-success">
                    <i class="bi bi-check-circle display-6 text-success"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['active_7']) ?></div>
                    <small class="text-muted">Active (7 days)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-warning bg-opacity-10 border border-warning">
                    <i class="bi bi-clock display-6 text-warning"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['active_30']) ?></div>
                    <small class="text-muted">Active (30 days)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-danger bg-opacity-10 border border-danger">
                    <i class="bi bi-exclamation-triangle display-6 text-danger"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['never_logged']) ?></div>
                    <small class="text-muted">Never Logged In</small>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Search Student</label>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="ID, Name, or Email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Program</label>
                        <select name="program" class="form-select form-select-sm">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog['program_value']) ?>" <?= $filter_program == $prog['program_value'] ? 'selected' : '' ?>><?= htmlspecialchars($prog['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Activity</label>
                        <select name="activity" class="form-select form-select-sm">
                            <option value="">All Activity</option>
                            <option value="active_7" <?= $filter_activity === 'active_7' ? 'selected' : '' ?>>Active (7 days)</option>
                            <option value="active_30" <?= $filter_activity === 'active_30' ? 'selected' : '' ?>>Active (30 days)</option>
                            <option value="inactive" <?= $filter_activity === 'inactive' ? 'selected' : '' ?>>Inactive (30+ days)</option>
                            <option value="never" <?= $filter_activity === 'never' ? 'selected' : '' ?>>Never Logged In</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
                        <a href="student_verification.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="button" class="btn btn-success btn-sm" onclick="exportStudents()">
                            <i class="bi bi-download me-1"></i>Export
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list me-2"></i>Students (<?= count($students) ?>)</h6>
                <span class="badge bg-light text-dark">Showing max 100 results</span>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($students)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Login Activity</th>
                                <th class="text-center">Courses</th>
                                <th class="text-center">Submissions</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                            <?php 
                                $last_login = $student['last_login'] ? strtotime($student['last_login']) : null;
                                $now = time();
                                $days_since = $last_login ? floor(($now - $last_login) / 86400) : null;
                                
                                if ($last_login === null) {
                                    $activity_class = 'activity-never';
                                    $activity_label = 'Never';
                                } elseif ($days_since <= 7) {
                                    $activity_class = 'activity-active';
                                    $activity_label = 'Active';
                                } elseif ($days_since <= 30) {
                                    $activity_class = 'activity-inactive';
                                    $activity_label = 'Inactive';
                                } else {
                                    $activity_class = 'activity-never';
                                    $activity_label = 'Dormant';
                                }
                            ?>
                            <tr class="student-row">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px; color: white; font-size: 14px;">
                                            <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                                            <div class="small text-muted"><?= htmlspecialchars($student['student_id']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($student['program_name'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <span class="activity-indicator <?= $activity_class ?>"></span>
                                    <span class="ms-1"><?= $activity_label ?></span>
                                    <div class="small text-muted"><?= $student['login_count'] ?> logins</div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $student['enrolled_courses'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= $student['submissions'] ?></span>
                                </td>
                                <td>
                                    <?php if ($last_login): ?>
                                    <small><?= date('M j, Y', $last_login) ?></small>
                                    <div class="small text-muted"><?= date('g:i a', $last_login) ?></div>
                                    <?php else: ?>
                                    <span class="text-danger small">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewStudent('<?= $student['student_id'] ?>')" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="student_access_log.php?student=<?= urlencode($student['student_id']) ?>" class="btn btn-sm btn-outline-info" title="Access Log">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-search display-1 text-muted"></i>
                    <p class="mt-3 text-muted">No students found matching your criteria</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- View Student Modal -->
    <div class="modal fade" id="studentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentModalBody">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const studentModal = new bootstrap.Modal(document.getElementById('studentModal'));
    
    function viewStudent(studentId) {
        document.getElementById('studentModalBody').innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>';
        studentModal.show();
        
        fetch('get_student_details.php?id=' + encodeURIComponent(studentId))
            .then(r => r.text())
            .then(html => {
                document.getElementById('studentModalBody').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('studentModalBody').innerHTML = '<div class="alert alert-danger">Failed to load student details</div>';
            });
    }
    
    function exportStudents() {
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'csv');
        window.location.href = 'export_students.php?' + params.toString();
    }
    </script>
</body>
</html>
