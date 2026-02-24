<?php
/**
 * Student Examinations Listing
 * Shows available, upcoming, and past examinations
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';

$tab = $_GET['tab'] ?? 'available';
$now = date('Y-m-d H:i:s');

// ── Fetch student payment status ──
$payment_percentage = 0;
$total_paid = 0;
$expected_total = 0;
$balance = 0;
$fin_stmt = $conn->prepare("SELECT sf.total_paid, sf.expected_total, sf.balance, sf.payment_percentage 
    FROM student_finances sf WHERE sf.student_id = ?");
if ($fin_stmt) {
    $fin_stmt->bind_param('s', $student_id);
    $fin_stmt->execute();
    $fin_row = $fin_stmt->get_result()->fetch_assoc();
    if ($fin_row) {
        $total_paid      = (float) $fin_row['total_paid'];
        $expected_total   = (float) $fin_row['expected_total'];
        $balance          = (float) $fin_row['balance'];
        $payment_percentage = $expected_total > 0 ? round(($total_paid / $expected_total) * 100) : (int) $fin_row['payment_percentage'];
    }
}

/**
 * Exam access rules based on payment:
 *   >= 50%  → mid_term, quiz, assignment allowed
 *   100%    → final (end-semester) also allowed
 *   < 50%   → no exams — must pay and submit proof
 */
function canAccessExam($exam_type, $pct) {
    if ($pct >= 100) return true;              // full access
    if ($pct >= 50 && in_array($exam_type, ['mid_term', 'quiz', 'assignment'])) return true;
    return false;
}
function examAccessMessage($exam_type, $pct, $balance = 0) {
    if ($pct < 50) return 'You must pay at least 50% of your fees to access any examination. Outstanding balance: <strong>K' . number_format($balance) . '</strong>';
    if ($exam_type === 'final' && $pct < 100) return 'End-semester (final) exams require 100% fee payment. Outstanding balance: <strong>K' . number_format($balance) . '</strong>';
    return '';
}

// Get student enrollments for filtering exams
$enrolled_courses = [];
$result = $conn->query("SELECT course_id FROM vle_enrollments WHERE student_id = '$student_id' AND is_completed = 0");
if ($result) while ($row = $result->fetch_assoc()) $enrolled_courses[] = $row['course_id'];

$course_list = !empty($enrolled_courses) ? implode(',', array_map('intval', $enrolled_courses)) : '0';

// Available exams (active, within time window, not yet completed)
$available = [];
$result = $conn->query("
    SELECT e.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.exam_id) as question_count,
           (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.exam_id AND es.student_id = '$student_id' AND es.status = 'completed') as attempts_made,
           (SELECT session_id FROM exam_sessions es WHERE es.exam_id = e.exam_id AND es.student_id = '$student_id' AND es.status = 'in_progress' LIMIT 1) as active_session
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE e.is_active = 1 AND e.start_time <= '$now' AND e.end_time >= '$now'
    AND (e.course_id IS NULL OR e.course_id IN ($course_list))
    ORDER BY e.end_time ASC
");
if ($result) while ($row = $result->fetch_assoc()) $available[] = $row;

// Upcoming exams
$upcoming = [];
$result = $conn->query("
    SELECT e.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.exam_id) as question_count
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE e.is_active = 1 AND e.start_time > '$now'
    AND (e.course_id IS NULL OR e.course_id IN ($course_list))
    ORDER BY e.start_time ASC
");
if ($result) while ($row = $result->fetch_assoc()) $upcoming[] = $row;

// Past exams / results — get HIGHEST grade per exam (best attempt)
$completed = [];
$result = $conn->query("
    SELECT e.*, c.course_name, c.course_code,
           er.score, er.percentage, er.is_passed, er.grade, er.submitted_at,
           er.result_id, e.results_published,
           att.attempt_count
    FROM exam_results er
    JOIN exams e ON er.exam_id = e.exam_id
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    JOIN (
        SELECT exam_id, MAX(score) as max_score, COUNT(*) as attempt_count
        FROM exam_results
        WHERE student_id = '$student_id'
        GROUP BY exam_id
    ) att ON att.exam_id = er.exam_id AND er.score = att.max_score
    WHERE er.student_id = '$student_id'
    GROUP BY er.exam_id
    ORDER BY er.submitted_at DESC
");
if ($result) while ($row = $result->fetch_assoc()) $completed[] = $row;

$page_title = "My Examinations";
$breadcrumbs = [['title' => 'Examinations']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Examinations - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include '../student/header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-file-earmark-text me-2"></i>My Examinations</h2>
                <p class="text-muted mb-0">View and take your scheduled examinations</p>
            </div>
        </div>

        <!-- Payment Status Banner -->
        <?php if ($payment_percentage < 100): ?>
        <div class="alert <?= $payment_percentage >= 50 ? 'alert-warning' : 'alert-danger' ?> d-flex align-items-start mb-4" role="alert">
            <div class="flex-shrink-0 me-3">
                <i class="bi <?= $payment_percentage >= 50 ? 'bi-exclamation-triangle-fill' : 'bi-shield-lock-fill' ?> fs-3"></i>
            </div>
            <div class="flex-grow-1">
                <?php if ($payment_percentage < 50): ?>
                    <h5 class="alert-heading mb-1"><i class="bi bi-lock-fill me-1"></i>Examination Access Restricted</h5>
                    <p class="mb-2">Your fee payment is at <strong><?= $payment_percentage ?>%</strong> (K<?= number_format($total_paid) ?> of K<?= number_format($expected_total) ?>). You need to pay at least <strong>50%</strong> of your total fees to access mid-semester exams, quizzes and assignments.</p>
                    <div class="mb-2">
                        <strong>Balance Due:</strong> <span class="text-danger fs-5">K<?= number_format($expected_total - $total_paid > 0 ? $expected_total - $total_paid : 0) ?></span>
                    </div>
                    <div>
                        <a href="<?= $_student_base ?? '../student/' ?>submit_payment.php" class="btn btn-danger btn-sm me-2">
                            <i class="bi bi-credit-card me-1"></i>Pay Fees & Submit Proof
                        </a>
                        <a href="<?= $_student_base ?? '../student/' ?>payment_history.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-receipt me-1"></i>View Payment History
                        </a>
                    </div>
                <?php else: ?>
                    <h5 class="alert-heading mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Limited Examination Access</h5>
                    <p class="mb-2">Your fee payment is at <strong><?= $payment_percentage ?>%</strong>. You can access <strong>mid-semester exams, quizzes and assignments</strong>, but <strong>end-semester (final) exams require 100% payment</strong>.</p>
                    <p class="mb-2">Full Outstanding Balance: <strong class="text-danger fs-5">K<?= number_format($balance) ?></strong></p>
                    <div>
                        <a href="<?= $_student_base ?? '../student/' ?>submit_payment.php" class="btn btn-warning btn-sm me-2">
                            <i class="bi bi-credit-card me-1"></i>Complete Payment & Submit Proof
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-6 fw-bold text-primary"><?= count($available) ?></div>
                        <small class="text-muted">Available Now</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-6 fw-bold text-warning"><?= count($upcoming) ?></div>
                        <small class="text-muted">Upcoming</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-6 fw-bold text-success"><?= count($completed) ?></div>
                        <small class="text-muted">Completed</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <?php $msg = $_GET['msg'] ?? ''; ?>
        <?php if ($msg === 'submitted'): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3">
                <i class="bi bi-check-circle me-2"></i><strong>Exam submitted successfully!</strong> Your results will be available once published by the examination office.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($msg === 'not_published'): ?>
            <div class="alert alert-info alert-dismissible fade show mb-3">
                <i class="bi bi-info-circle me-2"></i>Results for this exam have not been published yet. Please check back later.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>" href="?tab=available"><i class="bi bi-clock me-1"></i>Available (<?= count($available) ?>)</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'upcoming' ? 'active' : '' ?>" href="?tab=upcoming"><i class="bi bi-calendar-event me-1"></i>Upcoming (<?= count($upcoming) ?>)</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'completed' ? 'active' : '' ?>" href="?tab=completed"><i class="bi bi-check-circle me-1"></i>Completed (<?= count($completed) ?>)</a></li>
        </ul>

        <!-- Available Exams -->
        <?php if ($tab === 'available'): ?>
        <?php if (empty($available)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-calendar-x display-4 d-block mb-3"></i>
                <p class="mb-0">No examinations are available right now.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
            <?php foreach ($available as $exam): 
                $can_attempt = ($exam['attempts_made'] < $exam['max_attempts']);
                $has_active = !empty($exam['active_session']);
                $time_left = strtotime($exam['end_time']) - time();
                $hours_left = floor($time_left / 3600);
                $mins_left = floor(($time_left % 3600) / 60);
                // Skip exams where student has used all attempts (submitted)
                if (!$can_attempt && !$has_active) continue;
            ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-file-earmark-text me-2"></i><?= htmlspecialchars($exam['exam_name']) ?></span>
                            <span class="badge bg-<?= $exam['exam_type'] === 'final' ? 'danger' : ($exam['exam_type'] === 'mid_term' ? 'warning' : 'info') ?>">
                                <?= ucfirst(str_replace('_', ' ', $exam['exam_type'])) ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($exam['exam_code']) ?></span>
                                <?php if ($exam['course_code']): ?>
                                    <span class="text-muted"><i class="bi bi-book me-1"></i><?= htmlspecialchars($exam['course_code']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><small class="text-muted">Duration:</small><br><strong><?= $exam['duration_minutes'] ?> min</strong></div>
                                <div class="col-6"><small class="text-muted">Questions:</small><br><strong><?= $exam['question_count'] ?></strong></div>
                                <div class="col-6"><small class="text-muted">Total Marks:</small><br><strong><?= $exam['total_marks'] ?></strong></div>
                                <div class="col-6"><small class="text-muted">Passing:</small><br><strong><?= $exam['passing_marks'] ?></strong></div>
                            </div>

                            <!-- Security indicators -->
                            <div class="mb-3">
                                <?php if ($exam['require_camera']): ?>
                                    <span class="badge bg-warning text-dark me-1"><i class="bi bi-camera-video me-1"></i>Camera Required</span>
                                <?php endif; ?>
                                <?php if ($exam['require_token']): ?>
                                    <span class="badge bg-info text-dark me-1"><i class="bi bi-key me-1"></i>Token Required</span>
                                <?php endif; ?>
                                <span class="badge bg-secondary"><i class="bi bi-shield-lock me-1"></i>Tab Monitoring</span>
                            </div>

                            <div class="alert alert-warning py-2 mb-3">
                                <small><i class="bi bi-clock me-1"></i>Closes in <strong><?= $hours_left ?>h <?= $mins_left ?>m</strong></small>
                                <br><small>Attempts: <?= $exam['attempts_made'] ?> / <?= $exam['max_attempts'] ?></small>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top-0">
                            <?php 
                            $exam_allowed = canAccessExam($exam['exam_type'], $payment_percentage);
                            $lock_msg = examAccessMessage($exam['exam_type'], $payment_percentage, $balance);
                            ?>
                            <?php if (!$exam_allowed): ?>
                                <div class="text-center">
                                    <button class="btn btn-secondary w-100 mb-2" disabled>
                                        <i class="bi bi-lock-fill me-1"></i>Payment Required
                                    </button>
                                    <small class="text-danger d-block mb-2"><?= $lock_msg ?></small>
                                    <small class="text-muted d-block mb-2">Outstanding: <strong>K<?= number_format($balance) ?></strong></small>
                                    <a href="<?= $_student_base ?? '../student/' ?>submit_payment.php" class="btn btn-outline-danger btn-sm w-100">
                                        <i class="bi bi-credit-card me-1"></i>Pay Fees & Submit Proof
                                    </a>
                                </div>
                            <?php elseif ($has_active): ?>
                                <a href="take_exam.php?session_id=<?= $exam['active_session'] ?>" class="btn btn-warning w-100">
                                    <i class="bi bi-play-circle me-1"></i>Resume Exam
                                </a>
                            <?php elseif ($can_attempt): ?>
                                <a href="take_exam.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-primary w-100">
                                    <i class="bi bi-pencil-square me-1"></i>Start Exam
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary w-100" disabled>
                                    <i class="bi bi-x-circle me-1"></i>Max Attempts Reached
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Upcoming Exams -->
        <?php if ($tab === 'upcoming'): ?>
        <?php if (empty($upcoming)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-calendar-check display-4 d-block mb-3"></i>
                <p class="mb-0">No upcoming examinations scheduled.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
            <?php foreach ($upcoming as $exam):
                $starts_in = strtotime($exam['start_time']) - time();
                $days = floor($starts_in / 86400);
                $hours = floor(($starts_in % 86400) / 3600);
            ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-dark text-white">
                            <i class="bi bi-calendar-event me-2"></i><?= htmlspecialchars($exam['exam_name']) ?>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-2"><?= htmlspecialchars($exam['exam_code']) ?> <?= $exam['course_code'] ? '| ' . htmlspecialchars($exam['course_code']) : '' ?></p>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><small class="text-muted">Starts:</small><br><strong><?= date('M d, Y h:i A', strtotime($exam['start_time'])) ?></strong></div>
                                <div class="col-6"><small class="text-muted">Duration:</small><br><strong><?= $exam['duration_minutes'] ?> minutes</strong></div>
                                <div class="col-6"><small class="text-muted">Questions:</small><br><strong><?= $exam['question_count'] ?></strong></div>
                                <div class="col-6"><small class="text-muted">Total Marks:</small><br><strong><?= $exam['total_marks'] ?></strong></div>
                            </div>
                            <?php if ($exam['require_camera']): ?>
                                <span class="badge bg-warning text-dark me-1"><i class="bi bi-camera-video me-1"></i>Camera</span>
                            <?php endif; ?>
                            <?php if ($exam['require_token']): ?>
                                <span class="badge bg-info text-dark me-1"><i class="bi bi-key me-1"></i>Token</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white border-top-0">
                            <?php 
                            $upcoming_allowed = canAccessExam($exam['exam_type'], $payment_percentage);
                            ?>
                            <?php if (!$upcoming_allowed): ?>
                            <div class="alert alert-danger py-2 mb-2">
                                <small><i class="bi bi-lock-fill me-1"></i>
                                <?= $exam['exam_type'] === 'final' ? 'Requires 100% fee payment' : 'Requires at least 50% fee payment' ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <div class="alert alert-info py-2 mb-0">
                                <small><i class="bi bi-hourglass-split me-1"></i>Starts in <strong><?= $days > 0 ? $days . 'd ' : '' ?><?= $hours ?>h</strong></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Completed Exams -->
        <?php if ($tab === 'completed'): ?>
        <?php if (empty($completed)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard-check display-4 d-block mb-3"></i>
                <p class="mb-0">You haven't completed any examinations yet.</p>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Exam Results</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Exam</th>
                                    <th>Course</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completed as $r): ?>
                                <?php 
                                    $published = !empty($r['results_published']); 
                                    $attempt_count = $r['attempt_count'] ?? 1;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($r['exam_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($r['exam_code']) ?></small>
                                        <?php if ($attempt_count > 1): ?>
                                            <br><span class="badge bg-info text-dark" style="font-size:10px;"><i class="bi bi-arrow-repeat me-1"></i><?= $attempt_count ?> attempt<?= $attempt_count > 1 ? 's' : '' ?> &mdash; Best result shown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($r['course_code'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($published): ?>
                                            <strong><?= number_format($r['score'], 1) ?></strong> / <?= $r['total_marks'] ?>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="bi bi-lock me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($published): ?>
                                        <div class="progress" style="height: 20px; min-width: 80px;">
                                            <div class="progress-bar bg-<?= $r['is_passed'] ? 'success' : 'danger' ?>" style="width: <?= $r['percentage'] ?>%">
                                                <?= number_format($r['percentage'], 1) ?>%
                                            </div>
                                        </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($published): ?>
                                            <span class="badge bg-<?= $r['is_passed'] ? 'success' : 'danger' ?> fs-6"><?= $r['grade'] ?: '-' ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary fs-6"><i class="bi bi-lock-fill me-1"></i>—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary mb-1"><i class="bi bi-check2-square me-1"></i>Submitted</span>
                                        <?php if ($published): ?>
                                            <br><?php if ($r['is_passed']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Passed</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <br><span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Awaiting Results</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($r['submitted_at'])) ?></small></td>
                                    <td>
                                        <?php if ($published && $r['show_results']): ?>
                                            <a href="exam_result.php?result_id=<?= $r['result_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                        <?php elseif (!$published): ?>
                                            <span class="text-muted"><small><i class="bi bi-clock me-1"></i>Not Published</small></span>
                                        <?php else: ?>
                                            <span class="text-muted"><small>Hidden</small></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
