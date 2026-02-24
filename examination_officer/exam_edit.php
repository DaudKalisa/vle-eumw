<?php
/**
 * Edit Examination - Examination Officer
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

$exam_id = (int)($_GET['id'] ?? 0);
if (!$exam_id) { header('Location: manage_exams.php'); exit(); }

// Get existing exam
$stmt = $conn->prepare("SELECT * FROM exams WHERE exam_id = ?");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
if (!$exam) { header('Location: manage_exams.php'); exit(); }

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_code = trim($_POST['exam_code'] ?? '');
    $exam_name = trim($_POST['exam_name'] ?? '');
    $exam_type = $_POST['exam_type'] ?? 'final';
    $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $duration_minutes = (int)($_POST['duration_minutes'] ?? 60);
    $total_marks = (int)($_POST['total_marks'] ?? 100);
    $passing_marks = (int)($_POST['passing_marks'] ?? 40);
    $max_attempts = (int)($_POST['max_attempts'] ?? 1);
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_options = isset($_POST['shuffle_options']) ? 1 : 0;
    $show_results = isset($_POST['show_results']) ? 1 : 0;
    $allow_review = isset($_POST['allow_review']) ? 1 : 0;
    $require_camera = isset($_POST['require_camera']) ? 1 : 0;
    $require_token = isset($_POST['require_token']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($exam_code) || empty($exam_name) || empty($start_time) || empty($end_time)) {
        $error_message = "Please fill in all required fields.";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $error_message = "End time must be after start time.";
    } else {
        // Check duplicate code excluding current
        $check = $conn->prepare("SELECT exam_id FROM exams WHERE exam_code = ? AND exam_id != ?");
        $check->bind_param("si", $exam_code, $exam_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error_message = "Exam code '$exam_code' already exists.";
        } else {
            $stmt = $conn->prepare("
                UPDATE exams SET exam_code=?, exam_name=?, exam_type=?, course_id=?, description=?, instructions=?,
                    start_time=?, end_time=?, duration_minutes=?, total_marks=?, passing_marks=?, max_attempts=?,
                    shuffle_questions=?, shuffle_options=?, show_results=?, allow_review=?, require_camera=?, require_token=?,
                    is_active=?, updated_at=NOW()
                WHERE exam_id=?
            ");
            $stmt->bind_param("sssissssiiiiiiiiiiii",
                $exam_code, $exam_name, $exam_type, $course_id, $description, $instructions,
                $start_time, $end_time, $duration_minutes, $total_marks, $passing_marks, $max_attempts,
                $shuffle_questions, $shuffle_options, $show_results, $allow_review, $require_camera, $require_token,
                $is_active, $exam_id
            );
            if ($stmt->execute()) {
                $success_message = "Examination updated successfully!";
                // Refresh exam data
                $stmt = $conn->prepare("SELECT * FROM exams WHERE exam_id = ?");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                $exam = $stmt->get_result()->fetch_assoc();
            } else {
                $error_message = "Error updating exam: " . $conn->error;
            }
        }
    }
}

$courses = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_name")->fetch_all(MYSQLI_ASSOC);

$page_title = "Edit Examination";
$breadcrumbs = [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['url' => "exam_view.php?id=$exam_id", 'title' => $exam['exam_code']], ['title' => 'Edit']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Examination - VLE</title>
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
                <h2 class="vle-page-title"><i class="bi bi-pencil-square me-2"></i>Edit Examination</h2>
                <p class="text-muted mb-0">Modify examination: <?= htmlspecialchars($exam['exam_name']) ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="question_bank.php?exam_id=<?= $exam_id ?>" class="btn btn-outline-info"><i class="bi bi-question-circle me-1"></i>Questions</a>
                <a href="exam_tokens.php?exam_id=<?= $exam_id ?>" class="btn btn-outline-warning"><i class="bi bi-key me-1"></i>Tokens</a>
                <a href="exam_view.php?id=<?= $exam_id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form method="POST">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Exam Code <span class="text-danger">*</span></label>
                                    <input type="text" name="exam_code" class="form-control" required value="<?= htmlspecialchars($exam['exam_code']) ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Exam Name <span class="text-danger">*</span></label>
                                    <input type="text" name="exam_name" class="form-control" required value="<?= htmlspecialchars($exam['exam_name']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Type</label>
                                    <select name="exam_type" class="form-select">
                                        <?php foreach (['quiz' => 'Quiz', 'mid_term' => 'Mid-Semester Exam', 'final' => 'End-Semester Examination', 'assignment' => 'Assignment'] as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= $exam['exam_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Course</label>
                                    <select name="course_id" class="form-select">
                                        <option value="">-- General --</option>
                                        <?php foreach ($courses as $c): ?>
                                            <option value="<?= $c['course_id'] ?>" <?= $exam['course_id'] == $c['course_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($exam['description'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Instructions</label>
                                    <textarea name="instructions" class="form-control" rows="3"><?= htmlspecialchars($exam['instructions'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Schedule & Marks</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="start_time" class="form-control" required value="<?= date('Y-m-d\TH:i', strtotime($exam['start_time'])) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="end_time" class="form-control" required value="<?= date('Y-m-d\TH:i', strtotime($exam['end_time'])) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Duration (min)</label>
                                    <input type="number" name="duration_minutes" class="form-control" min="5" value="<?= $exam['duration_minutes'] ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Total Marks</label>
                                    <input type="number" name="total_marks" class="form-control" min="1" value="<?= $exam['total_marks'] ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Passing Marks</label>
                                    <input type="number" name="passing_marks" class="form-control" min="1" value="<?= $exam['passing_marks'] ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-gear me-2"></i>Settings</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Max Attempts</label>
                                <input type="number" name="max_attempts" class="form-control" min="1" max="10" value="<?= $exam['max_attempts'] ?>">
                            </div>
                            <?php 
                            $switches = [
                                'is_active' => ['Active', $exam['is_active']],
                                'shuffle_questions' => ['Shuffle Questions', $exam['shuffle_questions']],
                                'shuffle_options' => ['Shuffle Options', $exam['shuffle_options']],
                                'show_results' => ['Show Results', $exam['show_results']],
                                'allow_review' => ['Allow Review', $exam['allow_review']],
                            ];
                            foreach ($switches as $name => [$label, $val]): ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="<?= $name ?>" id="<?= $name ?>" <?= $val ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= $name ?>"><?= $label ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm border-danger mb-4">
                        <div class="card-header bg-danger text-white"><h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Security</h5></div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="require_camera" id="require_camera" <?= $exam['require_camera'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="require_camera"><strong>Camera Invigilation</strong></label>
                                <small class="d-block text-muted">Webcam monitoring during exam</small>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="require_token" id="require_token" <?= $exam['require_token'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="require_token"><strong>Require Token</strong></label>
                                <small class="d-block text-muted">Access token needed to start</small>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-save me-2"></i>Save Changes</button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
