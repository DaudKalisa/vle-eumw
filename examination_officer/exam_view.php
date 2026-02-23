<?php
/**
 * View Examination Details - Examination Officer
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

$exam_id = (int)($_GET['id'] ?? 0);
if (!$exam_id) { header('Location: manage_exams.php'); exit(); }

$stmt = $conn->prepare("
    SELECT e.*, c.course_name, c.course_code
    FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE e.exam_id = ?
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
if (!$exam) { header('Location: manage_exams.php'); exit(); }

// Stats
$q_count = $conn->query("SELECT COUNT(*) as c, SUM(marks) as m FROM exam_questions WHERE exam_id = $exam_id")->fetch_assoc();
$session_count = $conn->query("SELECT COUNT(*) as c FROM exam_sessions WHERE exam_id = $exam_id")->fetch_assoc()['c'];
$active_sessions = $conn->query("SELECT COUNT(*) as c FROM exam_sessions WHERE exam_id = $exam_id AND status = 'in_progress'")->fetch_assoc()['c'];
$result_stats = $conn->query("SELECT COUNT(*) as c, AVG(percentage) as avg_pct, MAX(percentage) as max_pct, MIN(percentage) as min_pct, SUM(is_passed) as passed FROM exam_results WHERE exam_id = $exam_id")->fetch_assoc();
$token_stats = $conn->query("SELECT COUNT(*) as total, SUM(is_used) as used FROM exam_tokens WHERE exam_id = $exam_id")->fetch_assoc();
$violation_count = $conn->query("SELECT COUNT(*) as c FROM exam_monitoring em JOIN exam_sessions es ON em.session_id = es.session_id WHERE es.exam_id = $exam_id AND em.event_type = 'violation'")->fetch_assoc()['c'];

// Recent sessions
$recent_sessions = [];
$result = $conn->query("
    SELECT es.*, s.full_name as student_name, s.student_id as sid,
           er.score, er.percentage, er.is_passed
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    LEFT JOIN exam_results er ON es.session_id = er.session_id
    WHERE es.exam_id = $exam_id
    ORDER BY es.started_at DESC LIMIT 20
");
if ($result) { while ($row = $result->fetch_assoc()) $recent_sessions[] = $row; }

$now = time();
$start = strtotime($exam['start_time']);
$end = strtotime($exam['end_time']);
if (!$exam['is_active']) { $status = 'Inactive'; $status_color = 'secondary'; }
elseif ($now < $start) { $status = 'Scheduled'; $status_color = 'info'; }
elseif ($now >= $start && $now <= $end) { $status = 'Live'; $status_color = 'success'; }
else { $status = 'Ended'; $status_color = 'dark'; }

$created = isset($_GET['created']);

$page_title = $exam['exam_name'];
$breadcrumbs = [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['title' => $exam['exam_code']]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($exam['exam_name']) ?> - VLE</title>
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
        <?php if ($created): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Examination created successfully! Add questions and generate tokens to get started.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
            <div>
                <h2 class="vle-page-title mb-1"><?= htmlspecialchars($exam['exam_name']) ?></h2>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-<?= $status_color ?>"><?= $status ?></span>
                    <span class="badge bg-<?= ['quiz'=>'info','mid_term'=>'warning','final'=>'danger','assignment'=>'primary'][$exam['exam_type']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_','-',$exam['exam_type'])) ?></span>
                    <span class="text-muted"><?= htmlspecialchars($exam['exam_code']) ?></span>
                    <?php if ($exam['course_name']): ?>
                        <span class="text-muted">| <?= htmlspecialchars($exam['course_code'] . ' - ' . $exam['course_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 mt-2 mt-md-0">
                <?php if ($result_stats['c'] > 0): ?>
                    <?php if ($exam['results_published']): ?>
                        <button type="button" class="btn btn-warning" onclick="togglePublish(0)"><i class="bi bi-eye-slash me-1"></i>Unpublish Results</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-success" onclick="togglePublish(1)"><i class="bi bi-send me-1"></i>Publish Results</button>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="exam_edit.php?id=<?= $exam_id ?>" class="btn btn-warning"><i class="bi bi-pencil me-1"></i>Edit</a>
                <a href="question_bank.php?exam_id=<?= $exam_id ?>" class="btn btn-info text-white"><i class="bi bi-question-circle me-1"></i>Questions</a>
                <a href="exam_tokens.php?exam_id=<?= $exam_id ?>" class="btn btn-outline-primary"><i class="bi bi-key me-1"></i>Tokens</a>
                <a href="manage_exams.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-primary"><?= $q_count['c'] ?? 0 ?></h3>
                    <small class="text-muted">Questions</small>
                    <small class="d-block text-muted"><?= $q_count['m'] ?? 0 ?>/<?= $exam['total_marks'] ?> marks</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-success"><?= $token_stats['total'] ?? 0 ?></h3>
                    <small class="text-muted">Tokens</small>
                    <small class="d-block text-muted"><?= $token_stats['used'] ?? 0 ?> used</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-info"><?= $session_count ?></h3>
                    <small class="text-muted">Attempts</small>
                    <?php if ($active_sessions > 0): ?>
                        <small class="d-block text-danger"><i class="bi bi-broadcast"></i> <?= $active_sessions ?> live</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-warning"><?= number_format($result_stats['avg_pct'] ?? 0, 1) ?>%</h3>
                    <small class="text-muted">Avg Score</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-success"><?= $result_stats['passed'] ?? 0 ?>/<?= $result_stats['c'] ?? 0 ?></h3>
                    <small class="text-muted">Passed</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card border-0 shadow-sm text-center py-3 h-100">
                    <h3 class="mb-0 text-danger"><?= $violation_count ?></h3>
                    <small class="text-muted">Violations</small>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Exam Details -->
            <div class="col-md-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Exam Details</h5></div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr><th width="40%">Start Time</th><td><?= date('M d, Y h:i A', $start) ?></td></tr>
                            <tr><th>End Time</th><td><?= date('M d, Y h:i A', $end) ?></td></tr>
                            <tr><th>Duration</th><td><?= $exam['duration_minutes'] ?> minutes</td></tr>
                            <tr><th>Total Marks</th><td><?= $exam['total_marks'] ?></td></tr>
                            <tr><th>Passing Marks</th><td><?= $exam['passing_marks'] ?> (<?= round(($exam['passing_marks']/$exam['total_marks'])*100) ?>%)</td></tr>
                            <tr><th>Max Attempts</th><td><?= $exam['max_attempts'] ?></td></tr>
                            <tr>
                                <th>Results Status</th>
                                <td id="publishStatus">
                                    <?php if ($exam['results_published']): ?>
                                        <span class="badge bg-success py-2 px-3"><i class="bi bi-check-circle me-1"></i>Published</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark py-2 px-3"><i class="bi bi-lock me-1"></i>Not Published</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Security Settings</h5></div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-<?= $exam['require_camera'] ? 'danger' : 'light text-dark' ?> py-2 px-3"><i class="bi bi-camera-video me-1"></i>Camera <?= $exam['require_camera'] ? 'ON' : 'OFF' ?></span>
                            <span class="badge bg-<?= $exam['require_token'] ? 'warning text-dark' : 'light text-dark' ?> py-2 px-3"><i class="bi bi-key me-1"></i>Token <?= $exam['require_token'] ? 'Required' : 'Not Required' ?></span>
                            <span class="badge bg-primary py-2 px-3"><i class="bi bi-window-dock me-1"></i>Tab Detection ON</span>
                            <span class="badge bg-primary py-2 px-3"><i class="bi bi-fullscreen me-1"></i>Fullscreen Enforced</span>
                            <span class="badge bg-<?= $exam['shuffle_questions'] ? 'success' : 'light text-dark' ?> py-2 px-3"><i class="bi bi-shuffle me-1"></i>Shuffle Q <?= $exam['shuffle_questions'] ? 'ON' : 'OFF' ?></span>
                            <span class="badge bg-<?= $exam['shuffle_options'] ? 'success' : 'light text-dark' ?> py-2 px-3"><i class="bi bi-shuffle me-1"></i>Shuffle Opt <?= $exam['shuffle_options'] ? 'ON' : 'OFF' ?></span>
                        </div>
                        <?php if ($exam['instructions']): ?>
                            <hr>
                            <h6>Student Instructions:</h6>
                            <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($exam['instructions'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Sessions -->
            <div class="col-md-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Exam Sessions</h5>
                        <a href="exam_results.php?exam_id=<?= $exam_id ?>" class="btn btn-sm btn-outline-light">All Results</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_sessions)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-people display-4 d-block mb-3"></i>
                                <p>No exam sessions yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Started</th>
                                            <th>Status</th>
                                            <th>Score</th>
                                            <th>Result</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_sessions as $s): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($s['sid']) ?></small>
                                            </td>
                                            <td>
                                                <?= date('M d', strtotime($s['started_at'])) ?>
                                                <br><small class="text-muted"><?= date('h:i A', strtotime($s['started_at'])) ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $s_colors = ['in_progress' => 'warning', 'completed' => 'success', 'abandoned' => 'danger', 'timed_out' => 'secondary'];
                                                ?>
                                                <span class="badge bg-<?= $s_colors[$s['status']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $s['status'])) ?></span>
                                            </td>
                                            <td><?= $s['score'] !== null ? $s['score'] . '/' . $exam['total_marks'] : '-' ?></td>
                                            <td>
                                                <?php if ($s['percentage'] !== null): ?>
                                                    <span class="badge bg-<?= $s['is_passed'] ? 'success' : 'danger' ?>"><?= number_format($s['percentage'], 1) ?>%</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function togglePublish(publish) {
        const action = publish ? 'publish' : 'unpublish';
        if (!confirm(`Are you sure you want to ${action} results for this exam? ${publish ? 'Students will be able to see their grades.' : 'Students will no longer see their grades.'}`)) return;
        
        fetch('publish_results.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ exam_id: <?= $exam_id ?>, publish: publish })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to update.');
            }
        })
        .catch(() => alert('Network error.'));
    }
    </script>
</body>
</html>
