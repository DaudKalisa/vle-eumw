<?php
/**
 * Mark Exam - Lecturer Grading Interface
 * Shows all student submissions for a specific exam with grading controls
 * Supports both viewing auto-graded MCQ/TF and manually grading essay/short_answer
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$user = getCurrentUser();
$lecturer_id = $user['related_lecturer_id'] ?? 0;
$exam_id = (int)($_GET['exam_id'] ?? 0);
$student_filter = $_GET['student'] ?? '';

if (!$lecturer_id || !$exam_id) {
    header('Location: exam_marking.php');
    exit();
}

// Verify this exam belongs to a course this lecturer teaches
$stmt = $conn->prepare("
    SELECT e.*, c.course_code, c.course_name 
    FROM exams e 
    JOIN vle_courses c ON e.course_id = c.course_id 
    WHERE e.exam_id = ? AND c.lecturer_id = ?
");
$stmt->bind_param("ii", $exam_id, $lecturer_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();

if (!$exam) {
    header('Location: exam_marking.php');
    exit();
}

// Get all questions for this exam
$questions = [];
$result = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY question_order ASC, question_id ASC");
if ($result) while ($row = $result->fetch_assoc()) {
    $row['options_arr'] = json_decode($row['options'] ?? '[]', true) ?: [];
    $questions[$row['question_id']] = $row;
}

// Get all completed sessions with student info
$sessions_query = "
    SELECT es.session_id, es.student_id, es.started_at, es.ended_at, es.status,
           s.full_name as student_name, s.email as student_email,
           er.result_id, er.score, er.percentage, er.is_passed, er.grade, er.reviewed_by, er.reviewed_at
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    LEFT JOIN exam_results er ON es.session_id = er.session_id
    WHERE es.exam_id = ? AND es.status = 'completed'
";
if ($student_filter) {
    $sessions_query .= " AND (s.full_name LIKE ? OR es.student_id LIKE ?)";
}
$sessions_query .= " ORDER BY er.reviewed_by IS NULL DESC, es.ended_at DESC";

$stmt = $conn->prepare($sessions_query);
if ($student_filter) {
    $search = "%$student_filter%";
    $stmt->bind_param("iss", $exam_id, $search, $search);
} else {
    $stmt->bind_param("i", $exam_id);
}
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get selected session for detailed marking
$selected_session = null;
$selected_answers = [];
$session_id = (int)($_GET['session_id'] ?? 0);

if ($session_id) {
    // Find this session in our list
    foreach ($sessions as $s) {
        if ($s['session_id'] == $session_id) {
            $selected_session = $s;
            break;
        }
    }
    
    if ($selected_session) {
        // Load all answers for this session
        $result = $conn->query("SELECT * FROM exam_answers WHERE session_id = $session_id ORDER BY question_id ASC");
        if ($result) while ($row = $result->fetch_assoc()) {
            $selected_answers[$row['question_id']] = $row;
        }
    }
}

// Grading helper
function getGradeLetter($percentage) {
    if ($percentage >= 85) return 'A+';
    if ($percentage >= 75) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 65) return 'B';
    if ($percentage >= 60) return 'C+';
    if ($percentage >= 55) return 'C';
    if ($percentage >= 50) return 'C-';
    if ($percentage >= 45) return 'D';
    if ($percentage >= 40) return 'E';
    return 'F';
}

// Stats
$total_submissions = count($sessions);
$marked_submissions = 0;
$unmarked_submissions = 0;
foreach ($sessions as $s) {
    if ($s['reviewed_by']) $marked_submissions++;
    else $unmarked_submissions++;
}

$page_title = "Mark Exam";
$breadcrumbs = [
    ['title' => 'Exam Marking', 'url' => 'exam_marking.php'],
    ['title' => htmlspecialchars($exam['exam_code'])]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark: <?= htmlspecialchars($exam['exam_name']) ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .answer-card { border-left: 4px solid var(--vle-border); transition: var(--vle-transition); }
        .answer-card.correct { border-left-color: #198754; }
        .answer-card.incorrect { border-left-color: #dc3545; }
        .answer-card.pending { border-left-color: #ffc107; }
        .answer-card.essay { border-left-color: #0d6efd; }
        .student-list .student-item { padding: 10px 15px; border-bottom: 1px solid var(--vle-border); cursor: pointer; transition: var(--vle-transition); }
        .student-list .student-item:hover { background: rgba(30,60,114,0.05); }
        .student-list .student-item.active { background: rgba(30,60,114,0.1); border-left: 3px solid var(--vle-primary); }
        .student-list .student-item.marked { opacity: 0.7; }
        .marks-input { width: 80px; text-align: center; font-weight: 600; }
        .question-type-badge { font-size: 0.7rem; }
        .essay-answer { background: #f8f9fa; border-radius: 8px; padding: 15px; font-family: 'Georgia', serif; line-height: 1.8; white-space: pre-wrap; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <!-- Exam Header -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1"><i class="bi bi-pencil-square me-2"></i><?= htmlspecialchars($exam['exam_name']) ?></h4>
                        <p class="text-muted mb-0">
                            <span class="badge bg-secondary me-1"><?= htmlspecialchars($exam['exam_code']) ?></span>
                            <i class="bi bi-book me-1"></i><?= htmlspecialchars($exam['course_code'] . ' - ' . $exam['course_name']) ?>
                            <span class="ms-2"><i class="bi bi-trophy me-1"></i>Total: <?= $exam['total_marks'] ?> marks | Pass: <?= $exam['passing_marks'] ?></span>
                        </p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-primary fs-6"><?= $total_submissions ?> Submissions</span>
                        <span class="badge bg-<?= $unmarked_submissions > 0 ? 'warning' : 'success' ?> fs-6 ms-1">
                            <?= $unmarked_submissions > 0 ? "$unmarked_submissions Pending" : 'All Marked' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Panel: Student List -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <form method="GET" class="d-flex">
                            <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                            <input type="text" name="student" class="form-control form-control-sm me-2" placeholder="Search student..." value="<?= htmlspecialchars($student_filter) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                        </form>
                    </div>
                    <div class="student-list" style="max-height: 70vh; overflow-y: auto;">
                        <?php if (empty($sessions)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox display-4"></i>
                                <p class="mt-2">No submissions yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($sessions as $s): ?>
                                <a href="?exam_id=<?= $exam_id ?>&session_id=<?= $s['session_id'] ?><?= $student_filter ? '&student=' . urlencode($student_filter) : '' ?>" 
                                   class="student-item d-block text-decoration-none text-dark <?= $session_id == $s['session_id'] ? 'active' : '' ?> <?= $s['reviewed_by'] ? 'marked' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($s['student_id']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($s['reviewed_by']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check me-1"></i><?= round($s['percentage'] ?? 0) ?>%</span>
                                                <br><small class="text-muted"><?= $s['grade'] ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i><?= date('M d, h:i A', strtotime($s['ended_at'] ?? $s['started_at'])) ?>
                                    </small>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Marking Area -->
            <div class="col-lg-8">
                <?php if (!$selected_session): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-arrow-left-circle display-1 text-muted mb-3"></i>
                            <h5 class="text-muted">Select a Student</h5>
                            <p class="text-muted">Click on a student from the list to view and grade their answers.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Student Info Bar -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($selected_session['student_name']) ?></strong>
                                    <span class="text-muted ms-2">(<?= htmlspecialchars($selected_session['student_id']) ?>)</span>
                                </div>
                                <div>
                                    <span class="fw-bold" id="totalScore">0</span> / <?= $exam['total_marks'] ?>
                                    <span class="ms-2 badge bg-secondary" id="gradeDisplay">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Questions & Answers -->
                    <form id="markingForm">
                        <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                        <input type="hidden" name="session_id" value="<?= $session_id ?>">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($selected_session['student_id']) ?>">
                        <input type="hidden" name="result_id" value="<?= $selected_session['result_id'] ?? '' ?>">

                        <?php $q_num = 0; foreach ($questions as $qid => $q): $q_num++; ?>
                            <?php
                                $answer = $selected_answers[$qid] ?? null;
                                $answer_text = $answer['answer_text'] ?? '';
                                $is_auto = in_array($q['question_type'], ['multiple_choice', 'true_false']);
                                $is_manual = in_array($q['question_type'], ['essay', 'short_answer', 'multiple_answer']);
                                $current_marks = $answer ? (float)$answer['marks_obtained'] : 0;
                                $is_correct = $answer ? $answer['is_correct'] : null;
                                
                                if ($is_auto && $answer) {
                                    $card_class = $is_correct ? 'correct' : 'incorrect';
                                } elseif ($is_manual) {
                                    $card_class = ($answer && $is_correct !== null) ? ($is_correct ? 'correct' : 'pending') : 'essay';
                                } else {
                                    $card_class = 'pending';
                                }
                                
                                $type_labels = [
                                    'multiple_choice' => ['MCQ', 'primary'],
                                    'true_false' => ['T/F', 'info'],
                                    'short_answer' => ['Short', 'warning'],
                                    'essay' => ['Essay', 'danger'],
                                    'multiple_answer' => ['Multi', 'secondary']
                                ];
                                [$type_label, $type_color] = $type_labels[$q['question_type']] ?? ['Q', 'secondary'];
                            ?>
                            <div class="card border-0 shadow-sm mb-3 answer-card <?= $card_class ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge bg-<?= $type_color ?> question-type-badge me-1"><?= $type_label ?></span>
                                            <strong>Question <?= $q_num ?></strong>
                                            <span class="text-muted small ms-1">(<?= $q['marks'] ?> marks)</span>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <?php if ($is_auto): ?>
                                                <?php if ($is_correct): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Auto-marked: <?= $current_marks ?>/<?= $q['marks'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Incorrect: 0/<?= $q['marks'] ?></span>
                                                <?php endif; ?>
                                                <input type="hidden" name="marks[<?= $qid ?>]" value="<?= $current_marks ?>" class="mark-value" data-max="<?= $q['marks'] ?>">
                                            <?php else: ?>
                                                <div class="input-group input-group-sm" style="width: 140px;">
                                                    <input type="number" name="marks[<?= $qid ?>]" value="<?= $current_marks ?>" 
                                                           min="0" max="<?= $q['marks'] ?>" step="0.5"
                                                           class="form-control marks-input mark-value" data-max="<?= $q['marks'] ?>"
                                                           onchange="recalcTotal()">
                                                    <span class="input-group-text">/ <?= $q['marks'] ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Question Text -->
                                    <div class="mb-3 p-2 bg-light rounded">
                                        <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                                    </div>

                                    <?php if ($is_auto): ?>
                                        <!-- Auto-graded: Show options with correct/selected -->
                                        <?php if (!empty($q['options_arr'])): ?>
                                            <div class="mb-2">
                                                <?php foreach ($q['options_arr'] as $idx => $opt): ?>
                                                    <?php
                                                        $is_selected = (strtolower(trim($answer_text)) === strtolower(trim($opt)));
                                                        $is_answer = (strtolower(trim($q['correct_answer'])) === strtolower(trim($opt)));
                                                    ?>
                                                    <div class="d-flex align-items-center mb-1 p-2 rounded <?= $is_answer ? 'bg-success bg-opacity-10' : ($is_selected && !$is_answer ? 'bg-danger bg-opacity-10' : '') ?>">
                                                        <?php if ($is_selected && $is_answer): ?>
                                                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                                                        <?php elseif ($is_selected): ?>
                                                            <i class="bi bi-x-circle-fill text-danger me-2"></i>
                                                        <?php elseif ($is_answer): ?>
                                                            <i class="bi bi-check-circle text-success me-2"></i>
                                                        <?php else: ?>
                                                            <i class="bi bi-circle text-muted me-2"></i>
                                                        <?php endif; ?>
                                                        <span><?= htmlspecialchars($opt) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p><strong>Student's Answer:</strong> <?= htmlspecialchars($answer_text ?: '(No answer)') ?></p>
                                            <p><strong>Correct Answer:</strong> <?= htmlspecialchars($q['correct_answer']) ?></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Manual grading: Show student's answer -->
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold text-muted">Student's Answer:</label>
                                            <?php if (empty($answer_text)): ?>
                                                <div class="text-muted fst-italic p-3 bg-light rounded">(No answer provided)</div>
                                            <?php else: ?>
                                                <div class="essay-answer"><?= nl2br(htmlspecialchars($answer_text)) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($q['correct_answer'])): ?>
                                            <div class="mb-2">
                                                <label class="form-label small fw-bold text-muted">Model Answer / Key Points:</label>
                                                <div class="p-2 border rounded bg-success bg-opacity-10 small">
                                                    <?= nl2br(htmlspecialchars($q['correct_answer'])) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($q['explanation'])): ?>
                                            <details class="mt-2">
                                                <summary class="text-muted small"><i class="bi bi-lightbulb me-1"></i>Marking Guide</summary>
                                                <div class="p-2 mt-1 bg-info bg-opacity-10 rounded small">
                                                    <?= nl2br(htmlspecialchars($q['explanation'])) ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Submit Marking -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-0">
                                            Total: <span id="totalScoreBottom" class="text-primary">0</span> / <?= $exam['total_marks'] ?>
                                            <span class="ms-2 badge bg-secondary" id="gradeDisplayBottom">-</span>
                                            <span class="ms-2 badge" id="passDisplay">-</span>
                                        </h5>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary me-2" onclick="window.location.href='mark_exam.php?exam_id=<?= $exam_id ?>'">
                                            <i class="bi bi-arrow-left me-1"></i>Back
                                        </button>
                                        <button type="button" id="saveMarksBtn" class="btn btn-primary" onclick="saveMarks()">
                                            <i class="bi bi-save me-1"></i>Save Marks
                                        </button>
                                    </div>
                                </div>
                                <div id="saveStatus" class="mt-2"></div>
                            </div>
                        </div>
                    </form>

                    <script>
                    const totalMarks = <?= $exam['total_marks'] ?>;
                    const passingMarks = <?= $exam['passing_marks'] ?>;

                    function recalcTotal() {
                        let total = 0;
                        document.querySelectorAll('.mark-value').forEach(el => {
                            total += parseFloat(el.value) || 0;
                        });
                        const pct = totalMarks > 0 ? (total / totalMarks * 100).toFixed(1) : 0;
                        const passed = total >= passingMarks;
                        const grade = getGrade(pct);

                        document.getElementById('totalScore').textContent = total;
                        document.getElementById('totalScoreBottom').textContent = total;
                        
                        ['gradeDisplay', 'gradeDisplayBottom'].forEach(id => {
                            const el = document.getElementById(id);
                            el.textContent = grade + ' (' + pct + '%)';
                            el.className = 'ms-2 badge bg-' + (pct >= 50 ? 'success' : 'danger');
                        });
                        
                        const passEl = document.getElementById('passDisplay');
                        passEl.textContent = passed ? 'PASSED' : 'FAILED';
                        passEl.className = 'ms-2 badge bg-' + (passed ? 'success' : 'danger');
                    }

                    function getGrade(pct) {
                        if (pct >= 85) return 'A+';
                        if (pct >= 75) return 'A';
                        if (pct >= 70) return 'B+';
                        if (pct >= 65) return 'B';
                        if (pct >= 60) return 'C+';
                        if (pct >= 55) return 'C';
                        if (pct >= 50) return 'C-';
                        if (pct >= 45) return 'D';
                        if (pct >= 40) return 'E';
                        return 'F';
                    }

                    function saveMarks() {
                        const btn = document.getElementById('saveMarksBtn');
                        const status = document.getElementById('saveStatus');
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
                        
                        const formData = new FormData(document.getElementById('markingForm'));
                        const data = {};
                        data.exam_id = formData.get('exam_id');
                        data.session_id = formData.get('session_id');
                        data.student_id = formData.get('student_id');
                        data.result_id = formData.get('result_id');
                        data.marks = {};
                        
                        document.querySelectorAll('.mark-value').forEach(el => {
                            const name = el.name.match(/marks\[(\d+)\]/);
                            if (name) data.marks[name[1]] = parseFloat(el.value) || 0;
                        });

                        fetch('save_exam_marks.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(data)
                        })
                        .then(r => r.json())
                        .then(result => {
                            if (result.success) {
                                status.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>' + result.message + '</div>';
                                // Move to next unmarked student
                                if (result.next_session_id) {
                                    setTimeout(() => {
                                        window.location.href = '?exam_id=<?= $exam_id ?>&session_id=' + result.next_session_id;
                                    }, 1000);
                                } else {
                                    setTimeout(() => location.reload(), 1000);
                                }
                            } else {
                                status.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>' + result.message + '</div>';
                            }
                        })
                        .catch(err => {
                            status.innerHTML = '<div class="alert alert-danger py-2">Network error. Please try again.</div>';
                        })
                        .finally(() => {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-save me-1"></i>Save Marks';
                        });
                    }

                    // Initial calculation
                    recalcTotal();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
