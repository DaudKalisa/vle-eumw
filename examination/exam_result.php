<?php
/**
 * Student Exam Result - Detailed View
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';

$result_id = (int)($_GET['result_id'] ?? 0);
if (!$result_id) { header('Location: exams.php?tab=completed'); exit; }

$stmt = $conn->prepare("
    SELECT er.*, e.exam_name, e.exam_code, e.exam_type, e.total_marks, e.passing_marks, 
           e.allow_review, e.show_results, e.results_published, e.duration_minutes,
           c.course_name, c.course_code,
           es.started_at, es.ended_at, es.session_id
    FROM exam_results er
    JOIN exams e ON er.exam_id = e.exam_id
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    LEFT JOIN exam_sessions es ON er.session_id = es.session_id
    WHERE er.result_id = ? AND er.student_id = ?
");
$stmt->bind_param("is", $result_id, $student_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if (!$result) { header('Location: exams.php?tab=completed'); exit; }

// Block access if results not yet published by examination officer
if (empty($result['results_published'])) {
    header('Location: exams.php?tab=completed&msg=not_published');
    exit;
}

// Get answers with questions if review allowed
$answers = [];
if ($result['allow_review'] && $result['session_id']) {
    $q = $conn->query("
        SELECT ea.*, eq.question_text, eq.question_type, eq.correct_answer, eq.marks, eq.options, eq.explanation
        FROM exam_answers ea
        JOIN exam_questions eq ON ea.question_id = eq.question_id
        WHERE ea.session_id = " . (int)$result['session_id'] . "
        ORDER BY eq.question_order ASC, eq.question_id ASC
    ");
    if ($q) while ($row = $q->fetch_assoc()) $answers[] = $row;
}

// Get violation count for this session
$violations = 0;
if ($result['session_id']) {
    $v = $conn->query("SELECT COUNT(*) as cnt FROM exam_monitoring WHERE session_id = " . (int)$result['session_id'] . " AND event_type IN ('tab_change','fullscreen_exit','violation','copy_attempt')");
    if ($v) $violations = $v->fetch_assoc()['cnt'];
}

// Calculate duration
$duration_taken = '-';
if ($result['started_at'] && $result['ended_at']) {
    $diff = strtotime($result['ended_at']) - strtotime($result['started_at']);
    $duration_taken = floor($diff / 60) . 'm ' . ($diff % 60) . 's';
}

$page_title = "Exam Result";
$breadcrumbs = [['title' => 'Examinations', 'url' => 'exams.php'], ['title' => 'Result']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Result - VLE</title>
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
                <h2 class="vle-page-title"><i class="bi bi-trophy me-2"></i>Examination Result</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($result['exam_name']) ?></p>
            </div>
            <a href="exams.php?tab=completed" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>

        <!-- Result Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-<?= $result['is_passed'] ? 'success' : 'danger' ?> text-white text-center py-4">
                <i class="bi bi-<?= $result['is_passed'] ? 'check-circle' : 'x-circle' ?> display-4 d-block mb-2"></i>
                <h3 class="mb-1"><?= $result['is_passed'] ? 'PASSED' : 'FAILED' ?></h3>
                <p class="mb-0 fs-5">Grade: <strong><?= $result['grade'] ?: 'N/A' ?></strong></p>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-md-3">
                        <div class="display-5 fw-bold text-primary"><?= number_format($result['score'], 1) ?></div>
                        <small class="text-muted">Score / <?= $result['total_marks'] ?></small>
                    </div>
                    <div class="col-md-3">
                        <div class="display-5 fw-bold text-<?= $result['is_passed'] ? 'success' : 'danger' ?>"><?= number_format($result['percentage'], 1) ?>%</div>
                        <small class="text-muted">Percentage</small>
                    </div>
                    <div class="col-md-3">
                        <div class="display-5 fw-bold"><?= $duration_taken ?></div>
                        <small class="text-muted">Time Taken</small>
                    </div>
                    <div class="col-md-3">
                        <div class="display-5 fw-bold text-<?= $violations > 0 ? 'danger' : 'success' ?>"><?= $violations ?></div>
                        <small class="text-muted">Violations</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exam Details -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Exam Details</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><small class="text-muted">Exam Code</small><br><strong><?= htmlspecialchars($result['exam_code']) ?></strong></div>
                    <div class="col-md-4"><small class="text-muted">Type</small><br><strong><?= ['quiz'=>'Quiz','mid_term'=>'Mid-Semester Exam','final'=>'End-Semester Examination','assignment'=>'Assignment'][$result['exam_type']] ?? ucfirst(str_replace('_', ' ', $result['exam_type'])) ?></strong></div>
                    <div class="col-md-4"><small class="text-muted">Course</small><br><strong><?= htmlspecialchars($result['course_code'] ?? 'General') ?></strong></div>
                    <div class="col-md-4"><small class="text-muted">Passing Marks</small><br><strong><?= $result['passing_marks'] ?></strong></div>
                    <div class="col-md-4"><small class="text-muted">Submitted</small><br><strong><?= date('M d, Y h:i A', strtotime($result['submitted_at'])) ?></strong></div>
                    <div class="col-md-4"><small class="text-muted">Duration</small><br><strong><?= $result['duration_minutes'] ?> minutes</strong></div>
                </div>
            </div>
        </div>

        <?php if (!empty($result['remarks'])): ?>
        <div class="alert alert-info">
            <h6><i class="bi bi-chat-left-text me-2"></i>Examiner Remarks</h6>
            <?= nl2br(htmlspecialchars($result['remarks'])) ?>
        </div>
        <?php endif; ?>

        <!-- Answer Review -->
        <?php if (!empty($answers)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Answer Review</h5></div>
            <div class="card-body">
                <?php foreach ($answers as $i => $a): 
                    $is_correct = $a['is_correct'];
                ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <strong>Q<?= $i + 1 ?>. <?= htmlspecialchars($a['question_text']) ?></strong>
                        <span class="badge bg-<?= $is_correct ? 'success' : 'danger' ?>">
                            <?= number_format($a['marks_obtained'], 1) ?> / <?= $a['marks'] ?>
                        </span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <small class="text-muted">Your Answer:</small>
                            <div class="p-2 rounded bg-<?= $is_correct ? 'success' : 'danger' ?> bg-opacity-10">
                                <?= htmlspecialchars($a['answer_text'] ?: '(No answer)') ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Correct Answer:</small>
                            <div class="p-2 rounded bg-success bg-opacity-10">
                                <?= htmlspecialchars($a['correct_answer']) ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($a['explanation']): ?>
                        <div class="mt-2 p-2 bg-info bg-opacity-10 rounded">
                            <small><i class="bi bi-lightbulb me-1"></i><strong>Explanation:</strong> <?= htmlspecialchars($a['explanation']) ?></small>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!$result['allow_review']): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-lock me-2"></i>Answer review is not available for this examination.
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
