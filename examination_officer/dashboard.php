<?php
/**
 * Examination Officer Dashboard
 * Central hub for examination management
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Statistics
$stats = [];

// Total exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams");
$stats['total_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Active exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE is_active = 1 AND end_time > NOW()");
$stats['active_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Ongoing exams (currently in progress)
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE is_active = 1 AND start_time <= NOW() AND end_time > NOW()");
$stats['ongoing_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Total questions across all exams
$result = $conn->query("SELECT COUNT(*) as total FROM exam_questions");
$stats['total_questions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Total sessions / attempts
$result = $conn->query("SELECT COUNT(*) as total FROM exam_sessions");
$stats['total_sessions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Active sessions (students currently writing)
$result = $conn->query("SELECT COUNT(*) as total FROM exam_sessions WHERE status = 'in_progress'");
$stats['active_sessions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Total results graded
$result = $conn->query("SELECT COUNT(*) as total FROM exam_results");
$stats['total_results'] = $result ? $result->fetch_assoc()['total'] : 0;

// Average pass rate
$result = $conn->query("SELECT AVG(is_passed) * 100 as pass_rate FROM exam_results");
$stats['pass_rate'] = $result ? round($result->fetch_assoc()['pass_rate'] ?? 0, 1) : 0;

// Monitoring violations
$result = $conn->query("SELECT COUNT(*) as total FROM exam_monitoring WHERE event_type = 'violation'");
$stats['violations'] = $result ? $result->fetch_assoc()['total'] : 0;

// Pending tokens (unused)
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens WHERE is_used = 0");
$stats['unused_tokens'] = $result ? $result->fetch_assoc()['total'] : 0;

// Recent exams
$recent_exams = [];
$result = $conn->query("
    SELECT e.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.exam_id) as question_count,
           (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.exam_id) as attempt_count
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    ORDER BY e.created_at DESC LIMIT 10
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_exams[] = $row;
    }
}

// Recent monitoring events
$recent_violations = [];
$result = $conn->query("
    SELECT em.*, es.student_id, s.full_name as student_name, ex.exam_name
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams ex ON es.exam_id = ex.exam_id
    WHERE em.event_type IN ('violation', 'tab_change', 'fullscreen_exit')
    ORDER BY em.timestamp DESC LIMIT 10
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_violations[] = $row;
    }
}

// Currently active exam sessions
$active_sessions = [];
$result = $conn->query("
    SELECT es.*, s.full_name as student_name, s.student_id as sid, ex.exam_name, ex.exam_code,
           TIMESTAMPDIFF(MINUTE, es.started_at, NOW()) as minutes_elapsed
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    JOIN exams ex ON es.exam_id = ex.exam_id
    WHERE es.status = 'in_progress'
    ORDER BY es.started_at DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $active_sessions[] = $row;
    }
}

$page_title = "Examination Dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#1e3c72">
    <title>Examination Officer Dashboard - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            background: var(--vle-card-bg);
            border-radius: var(--vle-radius-lg);
            padding: 1.25rem;
            box-shadow: var(--vle-shadow-sm);
            transition: var(--vle-transition);
            border-left: 4px solid var(--accent-color, var(--vle-primary));
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--vle-shadow-md);
            color: inherit;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--vle-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            flex-shrink: 0;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--vle-text-muted);
            font-weight: 500;
        }
        .welcome-card {
            background: var(--vle-gradient-primary);
            border-radius: var(--vle-radius-xl);
            padding: 2rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 40px rgba(30, 60, 114, 0.3);
        }
        .welcome-card h2 { font-weight: 700; }
        .live-badge { animation: pulse-live 2s infinite; }
        @keyframes pulse-live {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="bi bi-shield-check me-2"></i>Welcome, <?= htmlspecialchars($user['display_name'] ?? 'Examination Officer') ?></h2>
                    <p class="mb-1 opacity-75">Examination Officer Dashboard &mdash; <?= date('l, F j, Y') ?></p>
                    <?php if ($stats['active_sessions'] > 0): ?>
                        <span class="badge bg-warning text-dark mt-2"><i class="bi bi-broadcast live-badge me-1"></i><?= $stats['active_sessions'] ?> student(s) currently writing exams</span>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end d-none d-md-block">
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="manage_exams.php" class="btn btn-light"><i class="bi bi-plus-circle me-1"></i>New Exam</a>
                        <a href="monitoring.php" class="btn btn-outline-light"><i class="bi bi-camera-video me-1"></i>Live Monitor</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <a href="manage_exams.php" class="stat-card" style="--accent-color: var(--vle-primary);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-primary);">
                            <i class="bi bi-journal-text"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['total_exams'] ?></div>
                            <div class="stat-label">Total Exams</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="manage_exams.php?status=active" class="stat-card" style="--accent-color: var(--vle-success);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-success);">
                            <i class="bi bi-play-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['ongoing_exams'] ?></div>
                            <div class="stat-label">Ongoing Now</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="question_bank.php" class="stat-card" style="--accent-color: var(--vle-info);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-info);">
                            <i class="bi bi-question-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['total_questions'] ?></div>
                            <div class="stat-label">Questions</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="exam_results.php" class="stat-card" style="--accent-color: #764ba2;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-accent);">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['pass_rate'] ?>%</div>
                            <div class="stat-label">Pass Rate</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="monitoring.php" class="stat-card" style="--accent-color: var(--vle-warning);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-warning);">
                            <i class="bi bi-broadcast"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['active_sessions'] ?></div>
                            <div class="stat-label">Live Sessions</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="--accent-color: #14b8a6;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-teal);">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['total_sessions'] ?></div>
                            <div class="stat-label">Total Attempts</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <a href="exam_tokens.php" class="stat-card" style="--accent-color: #f59e0b;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-orange);">
                            <i class="bi bi-key"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['unused_tokens'] ?></div>
                            <div class="stat-label">Pending Tokens</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="monitoring.php?filter=violations" class="stat-card" style="--accent-color: var(--vle-danger);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background: var(--vle-gradient-danger);">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['violations'] ?></div>
                            <div class="stat-label">Violations</div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Currently Active Sessions -->
            <?php if (!empty($active_sessions)): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-broadcast live-badge me-2"></i>Live Exam Sessions</h5>
                        <a href="monitoring.php" class="btn btn-sm btn-light">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student</th>
                                        <th>Exam</th>
                                        <th>Started</th>
                                        <th>Elapsed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_sessions as $session): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($session['student_name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($session['sid']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($session['exam_name']) ?> <small class="text-muted">(<?= htmlspecialchars($session['exam_code']) ?>)</small></td>
                                        <td><?= date('h:i A', strtotime($session['started_at'])) ?></td>
                                        <td><span class="badge bg-info"><?= $session['minutes_elapsed'] ?> min</span></td>
                                        <td><span class="badge bg-success"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>In Progress</span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Exams -->
            <div class="col-md-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Recent Examinations</h5>
                        <a href="manage_exams.php" class="btn btn-sm btn-outline-light">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_exams)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x display-4 d-block mb-3"></i>
                                <p>No examinations created yet.</p>
                                <a href="exam_create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Create First Exam</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Exam</th>
                                            <th>Course</th>
                                            <th>Questions</th>
                                            <th>Attempts</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_exams as $exam): 
                                            $now = time();
                                            $start = strtotime($exam['start_time']);
                                            $end = strtotime($exam['end_time']);
                                            if (!$exam['is_active']) {
                                                $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                            } elseif ($now < $start) {
                                                $status_badge = '<span class="badge bg-info">Upcoming</span>';
                                            } elseif ($now >= $start && $now <= $end) {
                                                $status_badge = '<span class="badge bg-success"><i class="bi bi-broadcast me-1"></i>Live</span>';
                                            } else {
                                                $status_badge = '<span class="badge bg-dark">Ended</span>';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($exam['exam_name']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($exam['exam_code']) ?></small>
                                            </td>
                                            <td><?= $exam['course_name'] ? htmlspecialchars($exam['course_code']) : '<em>General</em>' ?></td>
                                            <td><span class="badge bg-light text-dark"><?= $exam['question_count'] ?></span></td>
                                            <td><span class="badge bg-light text-dark"><?= $exam['attempt_count'] ?></span></td>
                                            <td><?= $status_badge ?></td>
                                            <td><a href="exam_view.php?id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Violations -->
            <div class="col-md-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Recent Alerts</h5>
                        <a href="monitoring.php" class="btn btn-sm btn-outline-light">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_violations)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-shield-check display-4 d-block mb-3"></i>
                                <p>No violations recorded.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_violations as $v): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <span class="badge bg-<?= $v['event_type'] === 'violation' ? 'danger' : 'warning' ?> me-1">
                                                <?= ucfirst(str_replace('_', ' ', $v['event_type'])) ?>
                                            </span>
                                            <strong class="d-block mt-1"><?= htmlspecialchars($v['student_name']) ?></strong>
                                            <small class="text-muted"><?= htmlspecialchars($v['exam_name']) ?></small>
                                        </div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($v['created_at'])) ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Auto-refresh for live monitoring -->
    <script>
    <?php if ($stats['active_sessions'] > 0): ?>
    setTimeout(() => location.reload(), 30000); // Refresh every 30 seconds when sessions active
    <?php endif; ?>
    </script>
</body>
</html>
