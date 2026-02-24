<?php
/**
 * Exam Marking - Lecturer View
 * Lists all exams for courses assigned to this lecturer, with marking status
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$user = getCurrentUser();
$lecturer_id = $user['related_lecturer_id'] ?? 0;

if (!$lecturer_id) {
    header('Location: dashboard.php');
    exit();
}

// Get lecturer's courses
$courses = [];
$result = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE lecturer_id = $lecturer_id ORDER BY course_name");
if ($result) while ($row = $result->fetch_assoc()) $courses[] = $row;
$course_ids = array_column($courses, 'course_id');
$course_list = !empty($course_ids) ? implode(',', array_map('intval', $course_ids)) : '0';

// Filter
$filter_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$filter_status = $_GET['status'] ?? 'all';

$where = "e.course_id IN ($course_list)";
if ($filter_course) $where .= " AND e.course_id = $filter_course";

// Get exams with statistics
$exams = [];
$result = $conn->query("
    SELECT e.*, c.course_code, c.course_name,
        (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.exam_id) as total_questions,
        (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.exam_id AND question_type IN ('essay', 'short_answer')) as manual_questions,
        (SELECT COUNT(DISTINCT es.student_id) FROM exam_sessions es WHERE es.exam_id = e.exam_id AND es.status = 'completed') as submissions_count,
        (SELECT COUNT(DISTINCT er.student_id) FROM exam_results er WHERE er.exam_id = e.exam_id AND er.reviewed_by IS NOT NULL) as marked_count,
        (SELECT COUNT(DISTINCT es2.student_id) FROM exam_sessions es2 WHERE es2.exam_id = e.exam_id AND es2.status = 'completed' 
            AND es2.student_id NOT IN (SELECT er2.student_id FROM exam_results er2 WHERE er2.exam_id = e.exam_id AND er2.reviewed_by IS NOT NULL)) as unmarked_count
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE $where
    ORDER BY e.end_time DESC
");
if ($result) while ($row = $result->fetch_assoc()) {
    if ($filter_status === 'pending' && $row['unmarked_count'] == 0) continue;
    if ($filter_status === 'completed' && $row['unmarked_count'] > 0) continue;
    $exams[] = $row;
}

// Stats
$total_exams = count($exams);
$total_pending = 0;
$total_marked = 0;
foreach ($exams as $ex) {
    $total_pending += $ex['unmarked_count'];
    $total_marked += $ex['marked_count'];
}

$page_title = "Exam Marking";
$breadcrumbs = [['title' => 'Exam Marking']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Marking - VLE Lecturer</title>
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
                <h2 class="vle-page-title"><i class="bi bi-pencil-square me-2"></i>Exam Marking</h2>
                <p class="text-muted mb-0">Grade student examination submissions for your courses</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                            <i class="bi bi-journal-text text-primary fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Exams</div>
                            <div class="fw-bold fs-4"><?= $total_exams ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                            <i class="bi bi-hourglass-split text-warning fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Pending Marking</div>
                            <div class="fw-bold fs-4"><?= $total_pending ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                            <i class="bi bi-check-circle text-success fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Marked</div>
                            <div class="fw-bold fs-4"><?= $total_marked ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Filter by Course</label>
                        <select name="course_id" class="form-select form-select-sm">
                            <option value="0">All Courses</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['course_id'] ?>" <?= $filter_course == $c['course_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Marking Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Exams List -->
        <?php if (empty($exams)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-journal-x display-1 text-muted mb-3"></i>
                    <h5 class="text-muted">No Examinations Found</h5>
                    <p class="text-muted">No exams match your current filters, or no exams have been created for your courses yet.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($exams as $exam): ?>
                    <?php
                        $progress = $exam['submissions_count'] > 0 
                            ? round(($exam['marked_count'] / $exam['submissions_count']) * 100) 
                            : 0;
                        $has_manual = $exam['manual_questions'] > 0;
                        $is_ended = strtotime($exam['end_time']) < time();
                        $type_colors = [
                            'quiz' => 'info',
                            'mid_term' => 'warning',
                            'final' => 'danger',
                            'assignment' => 'primary'
                        ];
                        $badge_color = $type_colors[$exam['exam_type']] ?? 'secondary';
                    ?>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-<?= $badge_color ?> me-1"><?= ['quiz'=>'Quiz','mid_term'=>'Mid-Semester Exam','final'=>'End-Semester Examination','assignment'=>'Assignment'][$exam['exam_type']] ?? ucfirst(str_replace('_', ' ', $exam['exam_type'])) ?></span>
                                        <?php if (!$is_ended): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Ended</span>
                                        <?php endif; ?>
                                        <?php if ($has_manual): ?>
                                            <span class="badge bg-warning text-dark">Manual Grading Required</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= htmlspecialchars($exam['exam_code']) ?></small>
                                </div>
                                <h5 class="card-title mb-1"><?= htmlspecialchars($exam['exam_name']) ?></h5>
                                <p class="text-muted small mb-3">
                                    <i class="bi bi-book me-1"></i><?= htmlspecialchars($exam['course_code'] . ' - ' . $exam['course_name']) ?>
                                </p>
                                
                                <!-- Stats Row -->
                                <div class="row text-center mb-3">
                                    <div class="col-4">
                                        <div class="fw-bold"><?= $exam['total_questions'] ?></div>
                                        <small class="text-muted">Questions</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold"><?= $exam['submissions_count'] ?></div>
                                        <small class="text-muted">Submissions</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold text-<?= $exam['unmarked_count'] > 0 ? 'warning' : 'success' ?>">
                                            <?= $exam['marked_count'] ?>/<?= $exam['submissions_count'] ?>
                                        </div>
                                        <small class="text-muted">Marked</small>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="progress mb-3" style="height: 6px;">
                                    <div class="progress-bar bg-<?= $progress >= 100 ? 'success' : 'warning' ?>" style="width: <?= $progress ?>%"></div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>Ended: <?= date('M d, Y', strtotime($exam['end_time'])) ?>
                                    </small>
                                    <?php if ($exam['submissions_count'] > 0): ?>
                                        <a href="mark_exam.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-sm btn-<?= $exam['unmarked_count'] > 0 ? 'warning' : 'outline-success' ?>">
                                            <i class="bi bi-<?= $exam['unmarked_count'] > 0 ? 'pencil-square' : 'eye' ?> me-1"></i>
                                            <?= $exam['unmarked_count'] > 0 ? 'Mark (' . $exam['unmarked_count'] . ')' : 'View Marks' ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>No submissions</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
