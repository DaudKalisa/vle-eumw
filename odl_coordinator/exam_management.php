<?php
/**
 * ODL Coordinator - Exam Management Page
 * Manage examinations similar to examination officer
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

// Handle status actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_status'])) {
        $exam_id = (int)$_POST['exam_id'];
        $stmt = $conn->prepare("UPDATE exams SET is_active = NOT is_active, updated_at = NOW() WHERE exam_id = ?");
        $stmt->bind_param("i", $exam_id);
        $stmt->execute() ? $success_message = "Exam status updated." : $error_message = "Failed to update status.";
    }
    
    if (isset($_POST['publish_results'])) {
        $exam_id = (int)$_POST['exam_id'];
        $stmt = $conn->prepare("UPDATE exams SET results_published = TRUE, updated_at = NOW() WHERE exam_id = ?");
        $stmt->bind_param("i", $exam_id);
        $stmt->execute() ? $success_message = "Results published successfully." : $error_message = "Failed to publish results.";
    }
}

// Filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_course = $_GET['course'] ?? '';
$filter_type = $_GET['type'] ?? '';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($filter_status === 'upcoming') {
    $where_clauses[] = "e.start_time > NOW()";
} elseif ($filter_status === 'ongoing') {
    $where_clauses[] = "e.start_time <= NOW() AND e.end_time >= NOW()";
} elseif ($filter_status === 'completed') {
    $where_clauses[] = "e.end_time < NOW()";
} elseif ($filter_status === 'active') {
    $where_clauses[] = "e.is_active = TRUE";
} elseif ($filter_status === 'inactive') {
    $where_clauses[] = "e.is_active = FALSE";
}

if ($filter_course) {
    $where_clauses[] = "e.course_id = ?";
    $params[] = $filter_course;
    $types .= "i";
}

if ($filter_type) {
    $where_clauses[] = "e.exam_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$sql = "
    SELECT e.*, c.course_code, c.course_name,
           (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.exam_id) as total_attempts,
           (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.exam_id AND es.status = 'completed') as completed_attempts,
           (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.exam_id AND es.status = 'in_progress') as ongoing_attempts,
           (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.exam_id AND er.is_passed = 1) as passed_count,
           (SELECT AVG(percentage) FROM exam_results er WHERE er.exam_id = e.exam_id) as avg_score
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    $where_sql
    ORDER BY e.start_time DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$exams = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $exams[] = $row;
    }
}

// Get courses for filter
$courses = [];
$course_result = $conn->query("SELECT DISTINCT c.course_id, c.course_code, c.course_name FROM vle_courses c JOIN exams e ON c.course_id = e.course_id ORDER BY c.course_name");
if ($course_result) {
    while ($row = $course_result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Statistics
$stats = [];
$stat_result = $conn->query("SELECT COUNT(*) as total FROM exams");
$stats['total'] = $stat_result ? $stat_result->fetch_assoc()['total'] : 0;

$stat_result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE start_time > NOW()");
$stats['upcoming'] = $stat_result ? $stat_result->fetch_assoc()['total'] : 0;

$stat_result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE start_time <= NOW() AND end_time >= NOW()");
$stats['ongoing'] = $stat_result ? $stat_result->fetch_assoc()['total'] : 0;

$stat_result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE end_time < NOW() AND results_published = FALSE");
$stats['results_pending'] = $stat_result ? $stat_result->fetch_assoc()['total'] : 0;

$stat_result = $conn->query("SELECT COUNT(*) as total FROM exam_sessions WHERE status = 'in_progress'");
$stats['active_sessions'] = $stat_result ? $stat_result->fetch_assoc()['total'] : 0;

$page_title = 'Exam Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Management - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .stat-card { padding: 20px; border-radius: 12px; text-align: center; }
        .exam-row { transition: all 0.2s; }
        .exam-row:hover { background: #f8f9fa; }
        .status-badge { font-size: 11px; padding: 4px 10px; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-journal-text me-2"></i>Exam Management</h1>
                <p class="text-muted mb-0">Monitor and manage examinations</p>
            </div>
            <div class="d-flex gap-2">
                <a href="exam_reports.php" class="btn btn-outline-primary">
                    <i class="bi bi-graph-up me-1"></i>Exam Reports
                </a>
                <a href="../examination_officer/exam_create.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Create Exam
                </a>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card bg-primary bg-opacity-10 border border-primary">
                    <i class="bi bi-journal-text display-6 text-primary"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['total']) ?></div>
                    <small class="text-muted">Total Exams</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card bg-info bg-opacity-10 border border-info">
                    <i class="bi bi-calendar-event display-6 text-info"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['upcoming']) ?></div>
                    <small class="text-muted">Upcoming</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card bg-success bg-opacity-10 border border-success">
                    <i class="bi bi-play-circle display-6 text-success"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['ongoing']) ?></div>
                    <small class="text-muted">Ongoing</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card bg-warning bg-opacity-10 border border-warning">
                    <i class="bi bi-hourglass-split display-6 text-warning"></i>
                    <div class="h3 mb-0 mt-2"><?= number_format($stats['results_pending']) ?></div>
                    <small class="text-muted">Results Pending</small>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="upcoming" <?= $filter_status === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="ongoing" <?= $filter_status === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Course</label>
                        <select name="course" class="form-select form-select-sm">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['course_id'] ?>" <?= $filter_course == $course['course_id'] ? 'selected' : '' ?>><?= htmlspecialchars($course['course_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="midterm" <?= $filter_type === 'midterm' ? 'selected' : '' ?>>Midterm</option>
                            <option value="final" <?= $filter_type === 'final' ? 'selected' : '' ?>>Final</option>
                            <option value="quiz" <?= $filter_type === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                            <option value="supplementary" <?= $filter_type === 'supplementary' ? 'selected' : '' ?>>Supplementary</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="exam_management.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Exams Table -->
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-list me-2"></i>Examinations (<?= count($exams) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($exams)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Exam</th>
                                <th>Course</th>
                                <th>Schedule</th>
                                <th class="text-center">Attempts</th>
                                <th class="text-center">Pass Rate</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exams as $exam): ?>
                            <?php
                                $now = time();
                                $start = strtotime($exam['start_time']);
                                $end = strtotime($exam['end_time']);
                                
                                if ($now < $start) {
                                    $time_status = 'upcoming';
                                    $time_class = 'info';
                                } elseif ($now >= $start && $now <= $end) {
                                    $time_status = 'ongoing';
                                    $time_class = 'success';
                                } else {
                                    $time_status = 'completed';
                                    $time_class = 'secondary';
                                }
                                
                                $pass_rate = $exam['completed_attempts'] > 0 ? round(($exam['passed_count'] / $exam['completed_attempts']) * 100) : 0;
                            ?>
                            <tr class="exam-row">
                                <td>
                                    <strong><?= htmlspecialchars($exam['title'] ?? $exam['exam_title'] ?? 'Untitled Exam') ?></strong>
                                    <div class="small text-muted">
                                        <span class="badge bg-light text-dark"><?= ucfirst($exam['exam_type'] ?? 'exam') ?></span>
                                        <?= $exam['duration_minutes'] ?> mins &bull; <?= $exam['total_marks'] ?? 0 ?> marks
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($exam['course_code'] ?? 'N/A') ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($exam['course_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <small><?= date('M j, Y', $start) ?></small>
                                    <div class="small text-muted"><?= date('g:i a', $start) ?> - <?= date('g:i a', $end) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $exam['completed_attempts'] ?></span>
                                    <?php if ($exam['ongoing_attempts'] > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= $exam['ongoing_attempts'] ?> active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($exam['completed_attempts'] > 0): ?>
                                    <div class="progress" style="height: 6px; width: 60px; margin: 0 auto;">
                                        <div class="progress-bar bg-<?= $pass_rate >= 70 ? 'success' : ($pass_rate >= 50 ? 'warning' : 'danger') ?>" style="width: <?= $pass_rate ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?= $pass_rate ?>%</small>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge status-badge bg-<?= $time_class ?>"><?= ucfirst($time_status) ?></span>
                                    <?php if (!$exam['is_active']): ?>
                                    <span class="badge status-badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                    <?php if ($exam['results_published']): ?>
                                    <span class="badge status-badge bg-success">Published</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="../examination_officer/exam_view.php?id=<?= $exam['exam_id'] ?>" class="btn btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="../examination_officer/exam_results.php?id=<?= $exam['exam_id'] ?>" class="btn btn-outline-success" title="Results">
                                            <i class="bi bi-list-check"></i>
                                        </a>
                                        <?php if ($time_status === 'completed' && !$exam['results_published']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                            <button type="submit" name="publish_results" class="btn btn-outline-info" title="Publish Results" onclick="return confirm('Publish results for this exam?')">
                                                <i class="bi bi-megaphone"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-outline-<?= $exam['is_active'] ? 'warning' : 'success' ?>" title="<?= $exam['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $exam['is_active'] ? 'pause' : 'play' ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x display-1 text-muted"></i>
                    <p class="mt-3 text-muted">No exams found matching your criteria</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Active Sessions Alert -->
        <?php if ($stats['active_sessions'] > 0): ?>
        <div class="alert alert-warning mt-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong><?= $stats['active_sessions'] ?> active exam session(s)</strong> currently in progress.
                <a href="../examination_officer/monitoring.php" class="alert-link">View monitoring dashboard</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
