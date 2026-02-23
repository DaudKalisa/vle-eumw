<?php
/**
 * Create New Examination - Examination Officer
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

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

    // Validation
    if (empty($exam_code) || empty($exam_name) || empty($start_time) || empty($end_time)) {
        $error_message = "Please fill in all required fields.";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $error_message = "End time must be after start time.";
    } elseif ($passing_marks > $total_marks) {
        $error_message = "Passing marks cannot exceed total marks.";
    } else {
        // Check for duplicate exam code
        $check = $conn->prepare("SELECT exam_id FROM exams WHERE exam_code = ?");
        $check->bind_param("s", $exam_code);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error_message = "Exam code '$exam_code' already exists.";
        } else {
            $created_by = $_SESSION['vle_user_id'];
            $stmt = $conn->prepare("
                INSERT INTO exams (exam_code, exam_name, exam_type, course_id, description, instructions,
                    start_time, end_time, duration_minutes, total_marks, passing_marks, max_attempts,
                    shuffle_questions, shuffle_options, show_results, allow_review, require_camera, require_token,
                    is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssissssiiiiiiiiiiis",
                $exam_code, $exam_name, $exam_type, $course_id, $description, $instructions,
                $start_time, $end_time, $duration_minutes, $total_marks, $passing_marks, $max_attempts,
                $shuffle_questions, $shuffle_options, $show_results, $allow_review, $require_camera, $require_token,
                $is_active, $created_by
            );
            
            if ($stmt->execute()) {
                $new_exam_id = $conn->insert_id;
                header("Location: exam_view.php?id=$new_exam_id&created=1");
                exit();
            } else {
                $error_message = "Error creating exam: " . $conn->error;
            }
        }
    }
}

// Get courses for dropdown
$courses = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_name")->fetch_all(MYSQLI_ASSOC);

$page_title = "Create Examination";
$breadcrumbs = [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['title' => 'Create New']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Examination - VLE</title>
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
                <h2 class="vle-page-title"><i class="bi bi-plus-circle me-2"></i>Create New Examination</h2>
                <p class="text-muted mb-0">Set up a new examination with questions, settings and security options</p>
            </div>
            <a href="manage_exams.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form method="POST" id="examForm">
            <div class="row g-4">
                <!-- Main Details -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Exam Code <span class="text-danger">*</span></label>
                                    <input type="text" name="exam_code" class="form-control" required placeholder="e.g. EXAM-2026-001" value="<?= htmlspecialchars($_POST['exam_code'] ?? '') ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Exam Name <span class="text-danger">*</span></label>
                                    <input type="text" name="exam_name" class="form-control" required placeholder="e.g. Final Examination - Computer Science" value="<?= htmlspecialchars($_POST['exam_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Type</label>
                                    <select name="exam_type" class="form-select">
                                        <option value="quiz">Quiz</option>
                                        <option value="mid_term">Mid-Term Exam</option>
                                        <option value="final" selected>Final Exam</option>
                                        <option value="assignment">Assignment</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Course</label>
                                    <select name="course_id" class="form-select">
                                        <option value="">-- General (No Course) --</option>
                                        <?php foreach ($courses as $c): ?>
                                            <option value="<?= $c['course_id'] ?>"><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="2" placeholder="Brief description of the examination"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Instructions for Students</label>
                                    <textarea name="instructions" class="form-control" rows="3" placeholder="Instructions that students will see before starting the exam"><?= htmlspecialchars($_POST['instructions'] ?? 'Read each question carefully before answering. You cannot go back to previous questions once submitted. Ensure stable internet connectivity. Do not switch tabs or leave the exam window.') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Schedule & Marks</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="start_time" class="form-control" required value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="end_time" class="form-control" required value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Duration (minutes)</label>
                                    <input type="number" name="duration_minutes" class="form-control" min="5" max="600" value="<?= htmlspecialchars($_POST['duration_minutes'] ?? 60) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Total Marks</label>
                                    <input type="number" name="total_marks" class="form-control" min="1" value="<?= htmlspecialchars($_POST['total_marks'] ?? 100) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Passing Marks</label>
                                    <input type="number" name="passing_marks" class="form-control" min="1" value="<?= htmlspecialchars($_POST['passing_marks'] ?? 40) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Sidebar -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Exam Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Max Attempts</label>
                                <input type="number" name="max_attempts" class="form-control" min="1" max="10" value="1">
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active"><strong>Active</strong></label>
                                <small class="d-block text-muted">Make exam immediately available</small>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="shuffle_questions" id="shuffle_questions" checked>
                                <label class="form-check-label" for="shuffle_questions">Shuffle Questions</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="shuffle_options" id="shuffle_options" checked>
                                <label class="form-check-label" for="shuffle_options">Shuffle Answer Options</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="show_results" id="show_results" checked>
                                <label class="form-check-label" for="show_results">Show Results After Submission</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="allow_review" id="allow_review">
                                <label class="form-check-label" for="allow_review">Allow Answer Review</label>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm border-danger mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Security & Invigilation</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="require_camera" id="require_camera" checked>
                                <label class="form-check-label" for="require_camera"><strong>Camera Invigilation</strong></label>
                                <small class="d-block text-muted">Auto-capture webcam snapshots during exam. Students must grant camera access.</small>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="require_token" id="require_token" checked>
                                <label class="form-check-label" for="require_token"><strong>Require Access Token</strong></label>
                                <small class="d-block text-muted">Students need a unique token to start the exam</small>
                            </div>
                            <div class="alert alert-info py-2 mb-0">
                                <small><i class="bi bi-info-circle me-1"></i>Tab-switching detection and fullscreen enforcement are enabled by default for all exams.</small>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-check-circle me-2"></i>Create Examination
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
