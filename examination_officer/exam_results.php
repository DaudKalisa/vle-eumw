<?php
/**
 * Exam Results - Examination Officer
 * View and analyze all exam results
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

$exam_id = (int)($_GET['exam_id'] ?? 0);
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Get exam if specified
$exam = null;
if ($exam_id) {
    $stmt = $conn->prepare("SELECT e.*, c.course_name, c.course_code FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id WHERE e.exam_id = ?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
}

// Build results query
$where = ["1=1"];
$params = [];
$types = "";

if ($exam_id) {
    $where[] = "er.exam_id = ?";
    $params[] = $exam_id;
    $types .= "i";
}
if ($filter_status === 'passed') {
    $where[] = "er.is_passed = 1";
} elseif ($filter_status === 'failed') {
    $where[] = "er.is_passed = 0";
}
if ($search) {
    $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$where_clause = implode(" AND ", $where);

$query = "
    SELECT er.*, s.full_name as student_name, s.student_id as sid, s.email as student_email,
           e.exam_name, e.exam_code, e.total_marks, e.passing_marks, c.course_name, c.course_code,
           es.started_at, es.ended_at, es.status as session_status,
           TIMESTAMPDIFF(MINUTE, es.started_at, es.ended_at) as duration_minutes,
           (SELECT COUNT(*) FROM exam_monitoring em WHERE em.session_id = es.session_id AND em.event_type = 'violation') as violation_count
    FROM exam_results er
    JOIN students s ON er.student_id = s.student_id
    JOIN exams e ON er.exam_id = e.exam_id
    JOIN exam_sessions es ON er.session_id = es.session_id
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE $where_clause
    ORDER BY er.submitted_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Statistics
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r['is_passed']));
$failed = $total - $passed;
$avg_score = $total > 0 ? array_sum(array_column($results, 'percentage')) / $total : 0;
$highest = $total > 0 ? max(array_column($results, 'percentage')) : 0;
$lowest = $total > 0 ? min(array_column($results, 'percentage')) : 0;

// Grade distribution
$grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
foreach ($results as $r) {
    $pct = $r['percentage'];
    if ($pct >= 75) $grades['A']++;
    elseif ($pct >= 65) $grades['B']++;
    elseif ($pct >= 50) $grades['C']++;
    elseif ($pct >= 40) $grades['D']++;
    else $grades['F']++;
}

$all_exams = $conn->query("SELECT exam_id, exam_code, exam_name FROM exams ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$page_title = "Exam Results";
$breadcrumbs = $exam ? [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['url' => "exam_view.php?id=$exam_id", 'title' => $exam['exam_code']], ['title' => 'Results']] : [['title' => 'All Results']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-graph-up me-2"></i>Examination Results</h2>
                <?php if ($exam): ?>
                    <p class="text-muted mb-0">
                        <?= htmlspecialchars($exam['exam_name']) ?>
                        <?php if (!empty($exam['results_published'])): ?>
                            <span class="badge bg-success ms-2"><i class="bi bi-check-circle me-1"></i>Published</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark ms-2"><i class="bi bi-lock me-1"></i>Not Published</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php if ($exam_id): ?>
                <a href="exam_view.php?id=<?= $exam_id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Exam</a>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-primary"><?= $total ?></h3><small class="text-muted">Total</small></div></div>
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-success"><?= $passed ?></h3><small class="text-muted">Passed</small></div></div>
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-danger"><?= $failed ?></h3><small class="text-muted">Failed</small></div></div>
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-info"><?= number_format($avg_score, 1) ?>%</h3><small class="text-muted">Average</small></div></div>
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-success"><?= number_format($highest, 1) ?>%</h3><small class="text-muted">Highest</small></div></div>
            <div class="col-6 col-md-2"><div class="card border-0 shadow-sm text-center py-3"><h3 class="mb-0 text-danger"><?= number_format($lowest, 1) ?>%</h3><small class="text-muted">Lowest</small></div></div>
        </div>

        <!-- Grade Distribution -->
        <?php if ($total > 0): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Grade Distribution</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php 
                    $grade_colors = ['A' => 'success', 'B' => 'info', 'C' => 'primary', 'D' => 'warning', 'F' => 'danger'];
                    $grade_labels = ['A' => '75-100%', 'B' => '65-74%', 'C' => '50-64%', 'D' => '40-49%', 'F' => '0-39%'];
                    foreach ($grades as $g => $count): 
                        $pct = $total > 0 ? ($count / $total) * 100 : 0;
                    ?>
                    <div class="col">
                        <div class="text-center">
                            <h4 class="text-<?= $grade_colors[$g] ?>"><?= $g ?></h4>
                            <div class="progress mb-2" style="height: 40px;">
                                <div class="progress-bar bg-<?= $grade_colors[$g] ?>" style="width: <?= max($pct, 5) ?>%"><?= $count ?></div>
                            </div>
                            <small class="text-muted"><?= $grade_labels[$g] ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Exam</label>
                        <select name="exam_id" class="form-select">
                            <option value="">All Exams</option>
                            <?php foreach ($all_exams as $e): ?>
                                <option value="<?= $e['exam_id'] ?>" <?= $exam_id == $e['exam_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['exam_code'] . ' - ' . $e['exam_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="passed" <?= $filter_status === 'passed' ? 'selected' : '' ?>>Passed</option>
                            <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Search Student</label>
                        <input type="text" name="search" class="form-control" placeholder="Name or ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-search"></i></button>
                        <a href="exam_results.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($results)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-clipboard-data display-4 d-block mb-3"></i>
                        <p>No results found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Exam</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Violations</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($r['student_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($r['sid']) ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($r['exam_code']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($r['course_code'] ?? 'General') ?></small>
                                    </td>
                                    <td><?= $r['score'] ?>/<?= $r['total_marks'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="width:80px; height:20px;">
                                                <div class="progress-bar bg-<?= $r['is_passed'] ? 'success' : 'danger' ?>" style="width: <?= min($r['percentage'], 100) ?>%"></div>
                                            </div>
                                            <span><?= number_format($r['percentage'], 1) ?>%</span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-<?= $r['is_passed'] ? 'success' : 'danger' ?>"><?= $r['is_passed'] ? 'Passed' : 'Failed' ?></span></td>
                                    <td><?= $r['duration_minutes'] ?? '-' ?> min</td>
                                    <td>
                                        <?php if ($r['violation_count'] > 0): ?>
                                            <span class="badge bg-danger"><?= $r['violation_count'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Clean</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($r['submitted_at'])) ?></td>
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
