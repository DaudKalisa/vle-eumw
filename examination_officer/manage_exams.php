<?php
/**
 * Manage Examinations - Examination Officer
 * List, create, edit, delete exams
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

// Handle exam deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_exam'])) {
    $exam_id = (int)$_POST['exam_id'];
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM exam_monitoring WHERE session_id IN (SELECT session_id FROM exam_sessions WHERE exam_id = $exam_id)");
        $conn->query("DELETE FROM exam_answers WHERE session_id IN (SELECT session_id FROM exam_sessions WHERE exam_id = $exam_id)");
        $conn->query("DELETE FROM exam_results WHERE exam_id = $exam_id");
        $conn->query("DELETE FROM exam_sessions WHERE exam_id = $exam_id");
        $conn->query("DELETE FROM exam_tokens WHERE exam_id = $exam_id");
        $conn->query("DELETE FROM exam_questions WHERE exam_id = $exam_id");
        $stmt = $conn->prepare("DELETE FROM exams WHERE exam_id = ?");
        $stmt->bind_param("i", $exam_id);
        $stmt->execute();
        $conn->commit();
        $success_message = "Exam and all related data deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting exam: " . $e->getMessage();
    }
}

// Handle status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $exam_id = (int)$_POST['exam_id'];
    $stmt = $conn->prepare("UPDATE exams SET is_active = NOT is_active, updated_at = NOW() WHERE exam_id = ?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute() ? $success_message = "Exam status updated." : $error_message = "Failed to update status.";
}

// Handle force end exam (terminate all in-progress sessions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_end_exam'])) {
    $exam_id = (int)$_POST['exam_id'];
    $conn->begin_transaction();
    try {
        // Get exam details
        $exam_stmt = $conn->prepare("SELECT * FROM exams WHERE exam_id = ?");
        $exam_stmt->bind_param("i", $exam_id);
        $exam_stmt->execute();
        $exam_data = $exam_stmt->get_result()->fetch_assoc();

        if (!$exam_data) throw new Exception("Exam not found.");

        // Get all in-progress sessions
        $sessions_result = $conn->query("SELECT * FROM exam_sessions WHERE exam_id = $exam_id AND status = 'in_progress'");
        $ended_count = 0;

        // Get all questions for grading
        $questions = [];
        $q_result = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id");
        while ($qrow = $q_result->fetch_assoc()) $questions[$qrow['question_id']] = $qrow;

        while ($sess = $sessions_result->fetch_assoc()) {
            $sid = $sess['session_id'];
            $student_id = $sess['student_id'];

            // Check if result already exists
            $check_result = $conn->prepare("SELECT result_id FROM exam_results WHERE session_id = ?");
            $check_result->bind_param("i", $sid);
            $check_result->execute();
            if ($check_result->get_result()->num_rows > 0) {
                // Already has a result, just mark session as completed
                $conn->query("UPDATE exam_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = $sid");
                $ended_count++;
                continue;
            }

            // Get student's answers
            $answers = [];
            $a_result = $conn->query("SELECT * FROM exam_answers WHERE session_id = $sid");
            while ($arow = $a_result->fetch_assoc()) $answers[$arow['question_id']] = $arow;

            $total_score = 0;
            $total_possible = 0;

            foreach ($questions as $qid => $question) {
                $total_possible += $question['marks'];
                $answer_text = $answers[$qid]['answer_text'] ?? '';
                $correct_answer = $question['correct_answer'] ?? '';
                $marks_obtained = 0;
                $is_correct = 0;

                switch ($question['question_type']) {
                    case 'multiple_choice':
                    case 'true_false':
                        if (strtolower(trim($answer_text)) === strtolower(trim($correct_answer))) {
                            $is_correct = 1;
                            $marks_obtained = $question['marks'];
                        }
                        break;
                    case 'multiple_answer':
                        $student_ans = json_decode($answer_text, true) ?: [];
                        $correct_ans = json_decode($correct_answer, true) ?: [];
                        sort($student_ans); sort($correct_ans);
                        if ($student_ans == $correct_ans) {
                            $is_correct = 1;
                            $marks_obtained = $question['marks'];
                        } elseif (!empty($student_ans) && !empty($correct_ans)) {
                            $cc = count(array_intersect($student_ans, $correct_ans));
                            $wc = count(array_diff($student_ans, $correct_ans));
                            $marks_obtained = round(max(0, ($cc - $wc) / count($correct_ans)) * $question['marks'], 2);
                        }
                        break;
                    case 'short_answer':
                        if (strtolower(trim($answer_text)) === strtolower(trim($correct_answer))) {
                            $is_correct = 1;
                            $marks_obtained = $question['marks'];
                        }
                        break;
                    case 'essay':
                        $marks_obtained = 0;
                        break;
                }
                $total_score += $marks_obtained;
                if (isset($answers[$qid])) {
                    $conn->query("UPDATE exam_answers SET is_correct = $is_correct, marks_obtained = $marks_obtained WHERE answer_id = " . $answers[$qid]['answer_id']);
                }
            }

            $percentage = $total_possible > 0 ? ($total_score / $total_possible) * 100 : 0;
            $is_passed = ($total_score >= $exam_data['passing_marks']) ? 1 : 0;
            if ($percentage >= 70) $grade = 'A';
            elseif ($percentage >= 60) $grade = 'B';
            elseif ($percentage >= 50) $grade = 'C';
            elseif ($percentage >= 40) $grade = 'D';
            else $grade = 'F';

            $ins = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, session_id, score, percentage, is_passed, grade, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->bind_param("isiddis", $exam_id, $student_id, $sid, $total_score, $percentage, $is_passed, $grade);
            $ins->execute();

            $conn->query("UPDATE exam_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = $sid");
            $ended_count++;
        }

        // Set exam end_time to now (so it shows as ended)
        $conn->query("UPDATE exams SET end_time = NOW(), updated_at = NOW() WHERE exam_id = $exam_id AND end_time > NOW()");

        $conn->commit();
        $success_message = "Exam forcefully ended. $ended_count active session(s) terminated and auto-graded.";
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error force-ending exam: " . $e->getMessage();
    }
}

// Get filter values
$filter_status = $_GET['status'] ?? '';
$filter_course = $_GET['course'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

$where = ["1=1"];
$params = [];
$types = "";

if ($filter_status === 'active') {
    $where[] = "e.is_active = 1 AND e.end_time > NOW()";
} elseif ($filter_status === 'inactive') {
    $where[] = "e.is_active = 0";
} elseif ($filter_status === 'ended') {
    $where[] = "e.end_time < NOW()";
} elseif ($filter_status === 'ongoing') {
    $where[] = "e.is_active = 1 AND e.start_time <= NOW() AND e.end_time > NOW()";
}

if ($filter_course) {
    $where[] = "e.course_id = ?";
    $params[] = $filter_course;
    $types .= "i";
}

if ($filter_type) {
    $where[] = "e.exam_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($search) {
    $where[] = "(e.exam_name LIKE ? OR e.exam_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$where_clause = implode(" AND ", $where);

$query = "
    SELECT e.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.exam_id) as question_count,
           (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.exam_id) as attempt_count,
           (SELECT COUNT(*) FROM exam_tokens WHERE exam_id = e.exam_id AND is_used = 0) as unused_tokens
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE $where_clause
    ORDER BY e.created_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$courses = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_name")->fetch_all(MYSQLI_ASSOC);

$total_exams = count($exams);
$active_count = 0;
$ongoing_count = 0;
$total_attempts = 0;
$now = time();
foreach ($exams as $exam) {
    if ($exam['is_active'] && strtotime($exam['end_time']) > $now) $active_count++;
    if ($exam['is_active'] && strtotime($exam['start_time']) <= $now && strtotime($exam['end_time']) > $now) $ongoing_count++;
    $total_attempts += $exam['attempt_count'];
}

$page_title = "Manage Examinations";
$breadcrumbs = [['title' => 'Examinations']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Examinations - VLE</title>
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
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-journal-text me-2"></i>Manage Examinations</h2>
                <p class="text-muted mb-0">Create, manage and monitor all examinations</p>
            </div>
            <a href="exam_create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Create New Exam
            </a>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <h3 class="mb-0 text-primary"><?= $total_exams ?></h3>
                    <small class="text-muted">Total Exams</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <h3 class="mb-0 text-success"><?= $active_count ?></h3>
                    <small class="text-muted">Active Exams</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <h3 class="mb-0 text-danger"><?= $ongoing_count ?></h3>
                    <small class="text-muted">Currently Live</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <h3 class="mb-0 text-info"><?= $total_attempts ?></h3>
                    <small class="text-muted">Total Attempts</small>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Exam name or code..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="ongoing" <?= $filter_status === 'ongoing' ? 'selected' : '' ?>>Currently Live</option>
                            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="ended" <?= $filter_status === 'ended' ? 'selected' : '' ?>>Ended</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <option value="quiz" <?= $filter_type === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                            <option value="mid_term" <?= $filter_type === 'mid_term' ? 'selected' : '' ?>>Mid-Semester Exam</option>
                            <option value="final" <?= $filter_type === 'final' ? 'selected' : '' ?>>End-Semester Examination</option>
                            <option value="assignment" <?= $filter_type === 'assignment' ? 'selected' : '' ?>>Assignment</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Course</label>
                        <select name="course" class="form-select">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['course_id'] ?>" <?= $filter_course == $c['course_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="manage_exams.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Exams Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($exams)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-journal-x display-4 d-block mb-3"></i>
                        <p>No examinations found.</p>
                        <a href="exam_create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Create First Exam</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Exam</th>
                                    <th>Course</th>
                                    <th>Type</th>
                                    <th>Schedule</th>
                                    <th>Duration</th>
                                    <th>Questions</th>
                                    <th>Attempts</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exams as $exam): 
                                    $start = strtotime($exam['start_time']);
                                    $end = strtotime($exam['end_time']);
                                    if (!$exam['is_active']) {
                                        $status = '<span class="badge bg-secondary">Inactive</span>';
                                    } elseif ($now < $start) {
                                        $status = '<span class="badge bg-info">Scheduled</span>';
                                    } elseif ($now >= $start && $now <= $end) {
                                        $status = '<span class="badge bg-success"><i class="bi bi-broadcast me-1"></i>Live</span>';
                                    } else {
                                        $status = '<span class="badge bg-dark">Ended</span>';
                                    }
                                    $type_colors = ['quiz' => 'info', 'mid_term' => 'warning', 'final' => 'danger', 'assignment' => 'primary'];
                                ?>
                                <tr>
                                    <td>
                                        <a href="exam_view.php?id=<?= $exam['exam_id'] ?>" class="text-decoration-none">
                                            <strong><?= htmlspecialchars($exam['exam_name']) ?></strong>
                                        </a>
                                        <br><small class="text-muted"><?= htmlspecialchars($exam['exam_code']) ?></small>
                                        <?php if ($exam['require_camera']): ?><span class="badge bg-danger ms-1" title="Camera Required"><i class="bi bi-camera-video-fill"></i></span><?php endif; ?>
                                        <?php if ($exam['require_token']): ?><span class="badge bg-warning text-dark ms-1" title="Token Required"><i class="bi bi-key-fill"></i></span><?php endif; ?>
                                    </td>
                                    <td><?= $exam['course_name'] ? htmlspecialchars($exam['course_code']) : '<em>General</em>' ?></td>
                                    <td><span class="badge bg-<?= $type_colors[$exam['exam_type']] ?? 'secondary' ?>"><?= ['quiz'=>'Quiz','mid_term'=>'Mid-Semester Exam','final'=>'End-Semester Examination','assignment'=>'Assignment'][$exam['exam_type']] ?? ucfirst(str_replace('_', '-', $exam['exam_type'])) ?></span></td>
                                    <td>
                                        <small><?= date('M d, Y', $start) ?></small><br>
                                        <small class="text-muted"><?= date('h:i A', $start) ?> - <?= date('h:i A', $end) ?></small>
                                    </td>
                                    <td><?= $exam['duration_minutes'] ?> min</td>
                                    <td><span class="badge bg-light text-dark"><?= $exam['question_count'] ?></span></td>
                                    <td><span class="badge bg-light text-dark"><?= $exam['attempt_count'] ?></span></td>
                                    <td><?= $status ?>
                                        <?php if (!empty($exam['results_published'])): ?>
                                            <br><span class="badge bg-success mt-1"><i class="bi bi-megaphone me-1"></i>Results Published</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="exam_view.php?id=<?= $exam['exam_id'] ?>" class="btn btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                            <a href="exam_edit.php?id=<?= $exam['exam_id'] ?>" class="btn btn-outline-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                            <a href="question_bank.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-outline-info" title="Questions"><i class="bi bi-question-circle"></i></a>
                                            <?php if ($exam['is_active'] && $now >= $start && $now <= $end): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('FORCE END this exam?\n\nThis will:\n• Terminate ALL in-progress sessions\n• Auto-grade and save all student answers\n• Mark the exam as ended\n\nThis action cannot be undone!')">
                                                <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                                <button type="submit" name="force_end_exam" class="btn btn-danger" title="Force End Exam">
                                                    <i class="bi bi-stop-circle-fill"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle exam status?')">
                                                <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-outline-<?= $exam['is_active'] ? 'secondary' : 'success' ?>" title="<?= $exam['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi bi-<?= $exam['is_active'] ? 'pause' : 'play' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('DELETE this exam and ALL related data? This cannot be undone!')">
                                                <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                                <button type="submit" name="delete_exam" class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
