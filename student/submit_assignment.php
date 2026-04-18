<?php
// submit_assignment.php - Student assignment submission
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

// Get assignment details
$stmt = $conn->prepare("
    SELECT a.*, vc.course_name, vc.course_id, vc.lecturer_id,
           l.full_name as lecturer_name, l.email as lecturer_email
    FROM vle_assignments a
    JOIN vle_courses vc ON a.course_id = vc.course_id
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    WHERE a.assignment_id = ? AND a.is_active = TRUE
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$assignment = $result->fetch_assoc();

// Check if student is enrolled in the course
$stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
$stmt->bind_param("si", $student_id, $assignment['course_id']);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

// Check if already submitted
$stmt = $conn->prepare("SELECT * FROM vle_submissions WHERE assignment_id = ? AND student_id = ?");
$stmt->bind_param("is", $assignment_id, $student_id);
$stmt->execute();
$existing_submission = $stmt->get_result()->fetch_assoc();

// Fetch assignment questions
$questions = [];
$asst_sections = [];
if ($assignment_id) {
    $qstmt = $conn->prepare("SELECT * FROM vle_assignment_questions WHERE assignment_id = ? ORDER BY section_id, question_order, question_id");
    $qstmt->bind_param("i", $assignment_id);
    $qstmt->execute();
    $qres = $qstmt->get_result();
    while ($row = $qres->fetch_assoc()) {
        $questions[] = $row;
    }
    $qstmt->close();

    // Fetch sections (table may not exist if no assignments created yet)
    $sec_stmt = @$conn->prepare("SELECT * FROM assignment_sections WHERE assignment_id = ? ORDER BY section_order");
    if ($sec_stmt) {
        $sec_stmt->bind_param("i", $assignment_id);
        $sec_stmt->execute();
        $sec_res = $sec_stmt->get_result();
        while ($row = $sec_res->fetch_assoc()) {
            $asst_sections[] = $row;
        }
        $sec_stmt->close();
    }
}

// Fetch existing answers if submission exists
$existing_answers = [];
if ($existing_submission) {
    $astmt = $conn->prepare("SELECT * FROM vle_assignment_answers WHERE assignment_id = ? AND student_id = ?");
    $astmt->bind_param("is", $assignment_id, $student_id);
    $astmt->execute();
    $ares = $astmt->get_result();
    while ($row = $ares->fetch_assoc()) {
        $existing_answers[$row['question_id']] = $row['answer_text'];
    }
    $astmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text_content = trim($_POST['text_content'] ?? '');
    $file_path = null;
    $file_name = null;
    $error = null;

    // Handle file upload
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $file_name = basename($_FILES['submission_file']['name']);
        
        // Create student-specific directory structure
        $base_upload_dir = '../uploads/submissions/';
        
        // Ensure base directory exists
        if (!is_dir($base_upload_dir)) {
            if (!mkdir($base_upload_dir, 0755, true)) {
                $error = "Failed to create base upload directory: " . $base_upload_dir;
            }
        }
        
        if (empty($error)) {
            $student_dir = $base_upload_dir . time() . '_' . $student_id . '/';
            
            // Create the student directory if it doesn't exist
            if (!is_dir($student_dir)) {
                if (!mkdir($student_dir, 0755, true)) {
                    $error = "Failed to create student directory: " . $student_dir;
                }
            }
            
            if (empty($error)) {
                $file_path = time() . '_' . $student_id . '/' . $file_name;
                $target_path = $base_upload_dir . $file_path;
                
                // Double check that the target directory exists
                $target_dir = dirname($target_path);
                if (!is_dir($target_dir)) {
                    if (!mkdir($target_dir, 0755, true)) {
                        $error = "Failed to create final target directory: " . $target_dir;
                    }
                }
                
                if (empty($error)) {
                    if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $target_path)) {
                        $error = "Failed to move uploaded file from " . $_FILES['submission_file']['tmp_name'] . " to " . $target_path . ". Check directory permissions.";
                    }
                }
            }
        }
    }

    if (empty($error)) {
        if ($existing_submission) {
            $stmt = $conn->prepare("UPDATE vle_submissions SET submission_date = NOW(), file_path = ?, file_name = ?, text_content = ?, status = 'submitted' WHERE submission_id = ?");
            $stmt->bind_param("sssi", $file_path, $file_name, $text_content, $existing_submission['submission_id']);
        } else {
            $stmt = $conn->prepare("INSERT INTO vle_submissions (assignment_id, student_id, file_path, file_name, text_content) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $assignment_id, $student_id, $file_path, $file_name, $text_content);
        }

        if ($stmt->execute()) {
            $submission_id = $existing_submission ? $existing_submission['submission_id'] : $conn->insert_id;
            $success = "Assignment submitted successfully!";

            // ─── AUTO INTEGRITY CHECK (Plagiarism + AI) ───
            try {
                require_once '../includes/integrity_check.php';
                $engine = new IntegrityCheckEngine($conn);

                // Extract text from uploaded file or text content
                $check_text = '';
                if ($file_path) {
                    $abs_path = '../uploads/submissions/' . $file_path;
                    if (file_exists($abs_path)) {
                        $ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
                        if ($ext === 'txt') $check_text = file_get_contents($abs_path);
                        elseif ($ext === 'docx') $check_text = $engine->extractDocxText($abs_path);
                        elseif ($ext === 'pdf') $check_text = $engine->extractPdfText($abs_path);
                        elseif ($ext === 'doc') $check_text = $engine->extractDocText($abs_path);
                        elseif ($ext === 'odt') $check_text = $engine->extractOdtText($abs_path);
                    }
                }
                if (!empty($text_content)) {
                    $check_text .= "\n" . strip_tags($text_content);
                }
                $check_text = trim($check_text);

                if (strlen($check_text) >= 30) {
                    // Ensure score columns exist
                    $conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS plagiarism_score DECIMAL(5,2) DEFAULT NULL");
                    $conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS ai_score DECIMAL(5,2) DEFAULT NULL");
                    $conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS check_date DATETIME DEFAULT NULL");

                    $ic_result = $engine->checkSubmission($check_text, $submission_id, [
                        'type' => 'assignment',
                        'assignment_id' => $assignment_id,
                        'student_id' => $student_id,
                    ]);

                    $ic_plag = $ic_result['plagiarism']['score'];
                    $ic_ai   = $ic_result['ai']['score'];

                    $ic_save = $conn->prepare("UPDATE vle_submissions SET plagiarism_score = ?, ai_score = ?, check_date = NOW() WHERE submission_id = ?");
                    $ic_save->bind_param("ddi", $ic_plag, $ic_ai, $submission_id);
                    $ic_save->execute();
                    $ic_save->close();

                    $success .= " Integrity check complete — Plagiarism: " . round($ic_plag, 1) . "%, AI: " . round($ic_ai, 1) . "%.";
                }
            } catch (\Throwable $ic_err) {
                // Integrity check failure should not block submission
                error_log("Auto integrity check failed for submission $submission_id: " . $ic_err->getMessage());
            }

            // Save answers to assignment questions
            if (isset($_POST['answers']) && is_array($_POST['answers'])) {
                foreach ($_POST['answers'] as $qid => $ans) {
                    $qtype = null;
                    $correct_answer = null;
                    
                    foreach ($questions as $q) {
                        if ($q['question_id'] == $qid) {
                            $qtype = $q['question_type'];
                            $correct_answer = $q['correct_answer'];
                            break;
                        }
                    }
                    
                    $answer_text = is_array($ans) ? json_encode($ans) : trim($ans);
                    $is_correct = null;
                    
                    // Check if answer is correct for auto-gradeable types
                    if ($qtype === 'multiple_choice' && $correct_answer !== null && $correct_answer !== '') {
                        $is_correct = ($answer_text == $correct_answer) ? 1 : 0;
                    } elseif ($qtype === 'checkboxes' && $correct_answer !== null && $correct_answer !== '') {
                        $is_correct = ($answer_text == $correct_answer) ? 1 : 0;
                    } elseif ($qtype === 'true_false' && $correct_answer !== null && $correct_answer !== '') {
                        $is_correct = (strtolower(trim($answer_text)) === strtolower(trim($correct_answer))) ? 1 : 0;
                    } elseif ($qtype === 'dropdown' && $correct_answer !== null && $correct_answer !== '') {
                        $is_correct = ($answer_text == $correct_answer) ? 1 : 0;
                    }
                    
                    // Delete previous answer if exists
                    $del_stmt = $conn->prepare("DELETE FROM vle_assignment_answers WHERE question_id = ? AND assignment_id = ? AND student_id = ?");
                    $del_stmt->bind_param("iis", $qid, $assignment_id, $student_id);
                    $del_stmt->execute();
                    $del_stmt->close();
                    
                    // Insert new answer
                    $anstmt = $conn->prepare("INSERT INTO vle_assignment_answers (question_id, assignment_id, student_id, answer_text, is_correct) VALUES (?, ?, ?, ?, ?)");
                    $anstmt->bind_param("iissi", $qid, $assignment_id, $student_id, $answer_text, $is_correct);
                    $anstmt->execute();
                    $anstmt->close();
                }
            }

            // Auto-grade: if all questions are auto-gradeable, compute weighted score by marks
            $auto_gradeable_types = ['multiple_choice', 'checkboxes', 'true_false', 'dropdown'];
            $total_questions = count($questions);
            $auto_gradeable_count = 0;
            foreach ($questions as $q) {
                if (in_array($q['question_type'], $auto_gradeable_types) && $q['correct_answer'] !== null && $q['correct_answer'] !== '') {
                    $auto_gradeable_count++;
                }
            }

            if ($auto_gradeable_count > 0 && $auto_gradeable_count === $total_questions) {
                // Fetch answers with marks per question
                $score_stmt = $conn->prepare("SELECT aa.question_id, aa.is_correct, COALESCE(aq.marks, 1) as marks FROM vle_assignment_answers aa JOIN vle_assignment_questions aq ON aa.question_id = aq.question_id WHERE aa.assignment_id = ? AND aa.student_id = ?");
                $score_stmt->bind_param("is", $assignment_id, $student_id);
                $score_stmt->execute();
                $score_result = $score_stmt->get_result();

                $earned_marks = 0;
                $total_marks = 0;
                while ($row = $score_result->fetch_assoc()) {
                    $total_marks += (int)$row['marks'];
                    if ($row['is_correct'] == 1) {
                        $earned_marks += (int)$row['marks'];
                    }
                }
                $score_stmt->close();

                $max_score = (int)$assignment['max_score'];
                $auto_score = ($total_marks > 0) ? round(($earned_marks / $total_marks) * $max_score, 2) : 0;

                $grade_stmt = $conn->prepare("UPDATE vle_submissions SET score = ?, status = 'graded', graded_date = NOW() WHERE submission_id = ?");
                $grade_stmt->bind_param("di", $auto_score, $submission_id);
                $grade_stmt->execute();
                $grade_stmt->close();

                $success = "Assignment submitted and auto-graded! Your score: " . $auto_score . "/" . $max_score;
            }

            // Get student details
            $student_query = $conn->prepare("SELECT full_name, email FROM students WHERE student_id = ?");
            $student_query->bind_param("s", $student_id);
            $student_query->execute();
            $student_data = $student_query->get_result()->fetch_assoc();
            $student_query->close();

            // Send email notification
            if (isset($assignment['lecturer_email']) && isset($student_data['email']) && 
                $assignment['lecturer_email'] && $student_data['email']) {
                sendAssignmentSubmissionEmail(
                    $student_data['email'],
                    $student_data['full_name'],
                    $assignment['lecturer_email'],
                    $assignment['lecturer_name'],
                    $assignment['title'],
                    $assignment['course_name'],
                    $submission_id
                );
            }

            // Refresh existing submission
            $stmt = $conn->prepare("SELECT * FROM vle_submissions WHERE assignment_id = ? AND student_id = ?");
            $stmt->bind_param("is", $assignment_id, $student_id);
            $stmt->execute();
            $existing_submission = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Refresh existing answers
            $existing_answers = [];
            $astmt = $conn->prepare("SELECT * FROM vle_assignment_answers WHERE assignment_id = ? AND student_id = ?");
            $astmt->bind_param("is", $assignment_id, $student_id);
            $astmt->execute();
            $ares = $astmt->get_result();
            while ($row = $ares->fetch_assoc()) {
                $existing_answers[$row['question_id']] = $row['answer_text'];
            }
            $astmt->close();
        } else {
            $error = "Failed to submit assignment: " . $conn->error;
        }
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Assignment - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .question-card {
            background: white;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
            transition: box-shadow 0.2s;
        }
        .question-card:hover {
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        }
        .info-card {
            background: #f8f9fa;
            border-left: 4px solid #1a73e8;
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        .submission-status {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        .graded-status {
            background: #d1e7dd;
            border-left: 4px solid #198754;
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        .question-label {
            font-weight: 500;
            margin-bottom: 12px;
            color: #202124;
        }
        .form-check-input:checked {
            background-color: #1a73e8;
            border-color: #1a73e8;
        }
        .badge-info {
            background-color: #e8f0fe;
            color: #1967d2;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        /* Integrity Check Styles */
        .integrity-results {
            background: #f8f9fa;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 16px;
            display: none;
        }
        .integrity-results.show { display: block; }
        .integrity-results h6 { font-weight: 600; color: #202124; margin-bottom: 16px; }
        .integrity-bar-group { margin-bottom: 14px; }
        .integrity-bar-group label { font-size: 13px; font-weight: 500; color: #5f6368; display: flex; justify-content: space-between; margin-bottom: 4px; }
        .integrity-bar-group label span { font-weight: 700; }
        .integrity-bar { height: 10px; border-radius: 5px; background: #e8eaed; overflow: hidden; }
        .integrity-bar-fill { height: 100%; border-radius: 5px; transition: width 0.8s ease; }
        .integrity-scanning { text-align: center; padding: 24px; color: #5f6368; }
        .integrity-scanning .spinner-border { width: 1.5rem; height: 1.5rem; margin-bottom: 8px; }
        .integrity-error { color: #d93025; font-size: 13px; margin-top: 8px; }
        .integrity-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-low { background: #e6f4ea; color: #137333; }
        .badge-medium { background: #fef7e0; color: #b06000; }
        .badge-high { background: #fce8e6; color: #c5221f; }
    </style>
</head>
<body class="bg-light">
    <?php 
    // Set up breadcrumb navigation
    $page_title = "Submit Assignment";
    $breadcrumbs = [
        ['title' => 'Course Access', 'url' => 'courses.php'],
        ['title' => htmlspecialchars($assignment['course_name']), 'url' => 'course_content.php?course_id=' . $assignment['course_id']],
        ['title' => 'Submit Assignment']
    ];
    include 'header_nav.php'; 
    ?>
    
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <!-- Assignment Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <?php if (isset($assignment['time_limit']) && $assignment['time_limit'] > 0): ?>
                        <div class="alert alert-info" id="timerAlert">
                            <i class="bi bi-clock"></i> <b>Time Remaining:</b> <span id="timerDisplay"></span>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h3><i class="bi bi-file-earmark-text"></i> <?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <p class="text-muted mb-0">
                            <i class="bi bi-book"></i> <?php echo htmlspecialchars($assignment['course_name']); ?>
                            <?php if ($assignment['week_number']): ?>
                                • Week <?php echo $assignment['week_number']; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="dashboard.php?course_id=<?php echo $assignment['course_id']; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Course
                    </a>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Assignment Info Card -->
                <div class="question-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5><i class="bi bi-info-circle"></i> Assignment Details</h5>
                        <div>
                            <span class="badge-info"><?php echo ucfirst(str_replace('_', ' ', $assignment['assignment_type'])); ?></span>
                            <span class="badge-info ms-2"><i class="bi bi-star"></i> <?php echo $assignment['max_score']; ?> points</span>
                        </div>
                    </div>
                    
                    <?php if ($assignment['description']): ?>
                        <div class="mb-3">
                            <h6 class="text-muted small mb-2">INSTRUCTIONS</h6>
                            <?php
                            $desc = trim($assignment['description']);
                            $desc_lines = preg_split('/\r?\n/', $desc);
                            $list_items = array_filter(array_map('trim', $desc_lines));
                            
                            if (count($list_items) > 1) {
                                echo '<ol class="mb-0">';
                                foreach ($list_items as $item) {
                                    echo '<li>' . htmlspecialchars($item) . '</li>';
                                }
                                echo '</ol>';
                            } else {
                                echo '<p class="mb-0">' . nl2br(htmlspecialchars($desc)) . '</p>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>

                    <div class="row mt-3">
                        <?php if ($assignment['due_date']): ?>
                            <div class="col-md-6">
                                <small class="text-muted d-block">Due Date</small>
                                <strong><i class="bi bi-calendar-event"></i> <?php echo date('M d, Y - h:i A', strtotime($assignment['due_date'])); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Passing Score</small>
                            <strong><i class="bi bi-check-circle"></i> <?php echo $assignment['passing_score']; ?> points</strong>
                        </div>
                    </div>

                    <?php if ($assignment['file_path']): ?>
                        <div class="mt-3 p-3 bg-light rounded">
                            <h6 class="mb-2"><i class="bi bi-paperclip"></i> Attached File</h6>
                            <a href="../uploads/<?php echo htmlspecialchars($assignment['file_path']); ?>" 
                               target="_blank" class="btn btn-sm btn-primary">
                                <i class="bi bi-download"></i> Download: <?php echo htmlspecialchars($assignment['file_name']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Submission Status -->
                <?php if ($existing_submission && $existing_submission['status'] === 'graded'): ?>
                    <div class="graded-status">
                        <h5><i class="bi bi-check2-circle"></i> Graded</h5>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <h3 class="mb-0"><?php echo $existing_submission['score']; ?><small class="text-muted">/ <?php echo $assignment['max_score']; ?></small></h3>
                                <small class="text-muted">Your Score</small>
                            </div>
                            <?php if ($existing_submission['feedback']): ?>
                                <div class="col-md-12 mt-3">
                                    <h6>Lecturer Feedback:</h6>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($existing_submission['feedback'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($existing_submission['marked_file_path'])): ?>
                            <div class="mt-3">
                                <a href="../uploads/marked_assignments/<?php echo htmlspecialchars($existing_submission['marked_file_path']); ?>" 
                                   class="btn btn-success" target="_blank">
                                    <i class="bi bi-eye"></i> Review Marked Assignment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($existing_submission): ?>
                    <div class="submission-status">
                        <h6><i class="bi bi-clock-history"></i> Previous Submission</h6>
                        <p class="mb-2"><strong>Submitted:</strong> <?php echo date('M d, Y - h:i A', strtotime($existing_submission['submission_date'])); ?></p>
                        <p class="mb-0"><strong>Status:</strong> <span class="badge bg-warning"><?php echo ucfirst($existing_submission['status']); ?></span></p>
                    </div>
                <?php endif; ?>

                <!-- Submission Form -->
                <?php if (!isset($success) && !$existing_submission): ?>
                <form method="POST" enctype="multipart/form-data" id="assignmentForm">
                    <?php if (count($questions) > 0): ?>
                        <!-- Interactive Questions Mode -->
                        <div class="question-card">
                            <h5 class="mb-4"><i class="bi bi-list-check"></i> Assignment Questions</h5>
                            
                            <?php
                            // Group questions: top-level vs sub-questions
                            $top_questions = array_filter($questions, function($q) { return empty($q['parent_question_id']); });
                            $sub_map = [];
                            foreach ($questions as $q) {
                                if (!empty($q['parent_question_id'])) {
                                    $sub_map[$q['parent_question_id']][] = $q;
                                }
                            }
                            // Group by section
                            $by_section = [];
                            $unsectioned = [];
                            foreach ($top_questions as $q) {
                                if (!empty($q['section_id'])) {
                                    $by_section[$q['section_id']][] = $q;
                                } else {
                                    $unsectioned[] = $q;
                                }
                            }
                            $qnum = 0;
                            ?>

                            <?php foreach ($asst_sections as $sec): ?>
                                <div class="p-3 mb-3 rounded text-white" style="background: linear-gradient(135deg, #1e3a5f, #2d5a87);">
                                    <h6 class="mb-1">Section <?php echo htmlspecialchars($sec['section_label']); ?>: <?php echo htmlspecialchars($sec['section_title']); ?></h6>
                                    <?php if (!empty($sec['instructions'])): ?>
                                        <small class="text-white-50"><?php echo htmlspecialchars($sec['instructions']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($sec['total_marks']): ?>
                                        <span class="badge bg-light text-dark ms-2"><?php echo $sec['total_marks']; ?> marks</span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $sec_qs = $by_section[$sec['section_id']] ?? [];
                                foreach ($sec_qs as $q):
                                    $qnum++;
                                    $q_id = $q['question_id'];
                                    $q_marks = $q['marks'] ?? 1;
                                ?>
                                <div class="mb-4 pb-4" style="border-bottom: 1px solid #dadce0;">
                                    <div class="question-label">
                                        <span class="badge bg-primary me-2"><?php echo $qnum; ?></span>
                                        <?php echo htmlspecialchars($q['question_text']); ?>
                                        <span class="badge bg-info ms-2"><?php echo $q_marks; ?> mk</span>
                                    </div>
                                    <?php include __DIR__ . '/_render_question_input.php'; ?>
                                    <?php if (!empty($sub_map[$q_id])): ?>
                                        <?php foreach ($sub_map[$q_id] as $sub):
                                            $q = $sub;
                                            $q_id = $sub['question_id'];
                                            $q_marks = $sub['marks'] ?? 1;
                                        ?>
                                        <div class="ms-4 mt-3 p-3 bg-light border-start border-3 border-info rounded">
                                            <div class="question-label">
                                                <span class="badge bg-secondary me-1">(<?php echo htmlspecialchars($sub['sub_label'] ?? '?'); ?>)</span>
                                                <?php echo htmlspecialchars($sub['question_text']); ?>
                                                <span class="badge bg-info ms-2"><?php echo $q_marks; ?> mk</span>
                                            </div>
                                            <?php include __DIR__ . '/_render_question_input.php'; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>

                            <?php foreach ($unsectioned as $q):
                                $qnum++;
                                $q_id = $q['question_id'];
                                $q_marks = $q['marks'] ?? 1;
                            ?>
                                <div class="mb-4 pb-4" style="border-bottom: 1px solid #dadce0;">
                                    <div class="question-label">
                                        <span class="badge bg-primary me-2"><?php echo $qnum; ?></span>
                                        <?php echo htmlspecialchars($q['question_text']); ?>
                                        <span class="badge bg-info ms-2"><?php echo $q_marks; ?> mk</span>
                                    </div>
                                    <?php include __DIR__ . '/_render_question_input.php'; ?>
                                    <?php if (!empty($sub_map[$q_id])): ?>
                                        <?php foreach ($sub_map[$q_id] as $sub):
                                            $q = $sub;
                                            $q_id = $sub['question_id'];
                                            $q_marks = $sub['marks'] ?? 1;
                                        ?>
                                        <div class="ms-4 mt-3 p-3 bg-light border-start border-3 border-info rounded">
                                            <div class="question-label">
                                                <span class="badge bg-secondary me-1">(<?php echo htmlspecialchars($sub['sub_label'] ?? '?'); ?>)</span>
                                                <?php echo htmlspecialchars($sub['question_text']); ?>
                                                <span class="badge bg-info ms-2"><?php echo $q_marks; ?> mk</span>
                                            </div>
                                            <?php include __DIR__ . '/_render_question_input.php'; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                    <?php else: ?>
                        <!-- Essay Mode -->
                        <div class="question-card">
                            <h5 class="mb-3"><i class="bi bi-pencil-square"></i> Your Submission</h5>
                            
                            <div class="mb-4">
                                <label class="form-label">Text Response</label>
                                <textarea class="form-control" id="text_content" name="text_content" rows="10"><?php echo htmlspecialchars($existing_submission['text_content'] ?? ''); ?></textarea>
                                <div class="form-text">
                                    <i class="bi bi-info-circle"></i> You can format your text using the editor above
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-file-earmark-arrow-up"></i> File Upload (Optional)
                                </label>
                                <input type="file" class="form-control" name="submission_file" id="submissionFileInput">
                                <div class="form-text">Upload documents, PDFs, images, or other files. Supported files (PDF, DOCX, DOC, TXT) will be auto-checked for plagiarism &amp; AI content.</div>
                            </div>

                            <!-- Integrity Check Results -->
                            <div class="integrity-results" id="integrityResults">
                                <div id="integrityScanningState" class="integrity-scanning">
                                    <div class="spinner-border text-primary" role="status"></div>
                                    <div>Scanning your document for plagiarism &amp; AI content...</div>
                                </div>
                                <div id="integrityResultsContent" style="display:none;">
                                    <h6><i class="bi bi-shield-check"></i> Document Integrity Check</h6>
                                    <div class="integrity-bar-group">
                                        <label>Plagiarism Similarity <span id="plagiarismScore">0%</span></label>
                                        <div class="integrity-bar"><div class="integrity-bar-fill" id="plagiarismBar" style="width:0%"></div></div>
                                        <div class="mt-1"><span class="integrity-badge" id="plagiarismBadge"></span></div>
                                    </div>
                                    <div class="integrity-bar-group">
                                        <label>AI Content Detection <span id="aiScore">0%</span></label>
                                        <div class="integrity-bar"><div class="integrity-bar-fill" id="aiBar" style="width:0%"></div></div>
                                        <div class="mt-1"><span class="integrity-badge" id="aiBadge"></span></div>
                                    </div>
                                    <div class="form-text mt-2" id="wordCountInfo"></div>
                                </div>
                                <div id="integrityError" class="integrity-error" style="display:none;"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Submit Button -->
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                            <i class="bi bi-send"></i> Submit Assignment
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Disable submit button on form submission
    (function() {
        var form = document.getElementById('assignmentForm');
        var btn = document.getElementById('submitBtn');
        if (form && btn) {
            form.addEventListener('submit', function() {
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Submitted';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
            });
        }
    })();
    </script>
        <?php $time_limit = isset($assignment['time_limit']) ? (int)$assignment['time_limit'] : 0; ?>
        <?php if ($time_limit > 0): ?>
        <script>
        // Timer logic
        let timeLimit = <?php echo $time_limit; ?> * 60; // seconds
        let timerDisplay = document.getElementById('timerDisplay');
        let timerAlert = document.getElementById('timerAlert');
        let form = document.getElementById('assignmentForm');
        let timer = localStorage.getItem('assignment_timer_<?php echo $assignment_id; ?>');
        let startTime = timer ? parseInt(timer) : Math.floor(Date.now() / 1000);
        localStorage.setItem('assignment_timer_<?php echo $assignment_id; ?>', startTime);

        let timerInterval;
        function updateTimer() {
            let now = Math.floor(Date.now() / 1000);
            let elapsed = now - startTime;
            let remaining = timeLimit - elapsed;
            if (remaining <= 0) {
                timerDisplay.textContent = '00:00';
                timerAlert.classList.remove('alert-info');
                timerAlert.classList.add('alert-danger');
                timerAlert.innerHTML = '<i class="bi bi-clock"></i> <b>Time is up!</b> Your answers will be submitted.';
                clearInterval(timerInterval);
                form.submit();
            } else {
                let min = Math.floor(remaining / 60).toString().padStart(2, '0');
                let sec = (remaining % 60).toString().padStart(2, '0');
                timerDisplay.textContent = min + ':' + sec;
            }
        }
        timerInterval = setInterval(updateTimer, 1000);
        updateTimer();
        // Clear timer on submit
        form.addEventListener('submit', function() {
            localStorage.removeItem('assignment_timer_<?php echo $assignment_id; ?>');
            clearInterval(timerInterval);
        });
        </script>
        <?php endif; ?>
    <script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>
    <script>
        // ─── Integrity Check on File Upload ───
        (function() {
            const fileInput = document.getElementById('submissionFileInput');
            const resultsBox = document.getElementById('integrityResults');
            if (!fileInput || !resultsBox) return;

            const scanningState = document.getElementById('integrityScanningState');
            const resultsContent = document.getElementById('integrityResultsContent');
            const errorDiv = document.getElementById('integrityError');
            const checkableExts = ['pdf', 'docx', 'doc', 'txt'];

            fileInput.addEventListener('change', function() {
                const file = this.files[0];
                if (!file) { resultsBox.classList.remove('show'); return; }

                const ext = file.name.split('.').pop().toLowerCase();
                if (!checkableExts.includes(ext)) {
                    resultsBox.classList.remove('show');
                    return;
                }

                // Show scanning state
                resultsBox.classList.add('show');
                scanningState.style.display = '';
                resultsContent.style.display = 'none';
                errorDiv.style.display = 'none';

                const formData = new FormData();
                formData.append('check_file', file);
                formData.append('assignment_id', '<?php echo (int)$assignment_id; ?>');

                // Include text content if available
                const textEl = document.getElementById('text_content');
                if (textEl && textEl.value.trim()) {
                    formData.append('text_content', textEl.value);
                }

                fetch('../api/check_upload.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        scanningState.style.display = 'none';
                        if (!data.success) {
                            errorDiv.textContent = data.error || 'Check failed.';
                            errorDiv.style.display = '';
                            return;
                        }
                        resultsContent.style.display = '';
                        renderBar('plagiarism', data.plagiarism_score);
                        renderBar('ai', data.ai_score);
                        document.getElementById('wordCountInfo').textContent = 'Word count: ' + (data.word_count || 0);
                    })
                    .catch(() => {
                        scanningState.style.display = 'none';
                        errorDiv.textContent = 'Could not complete integrity check. You may still submit.';
                        errorDiv.style.display = '';
                    });
            });

            function renderBar(type, score) {
                score = parseFloat(score) || 0;
                const bar = document.getElementById(type === 'plagiarism' ? 'plagiarismBar' : 'aiBar');
                const label = document.getElementById(type === 'plagiarism' ? 'plagiarismScore' : 'aiScore');
                const badge = document.getElementById(type === 'plagiarism' ? 'plagiarismBadge' : 'aiBadge');

                label.textContent = score.toFixed(1) + '%';
                bar.style.width = Math.min(score, 100) + '%';

                let color, badgeClass, badgeText;
                if (type === 'plagiarism') {
                    if (score <= 15) { color = '#34a853'; badgeClass = 'badge-low'; badgeText = 'Low similarity'; }
                    else if (score <= 30) { color = '#fbbc04'; badgeClass = 'badge-medium'; badgeText = 'Moderate similarity'; }
                    else { color = '#ea4335'; badgeClass = 'badge-high'; badgeText = 'High similarity'; }
                } else {
                    if (score <= 20) { color = '#34a853'; badgeClass = 'badge-low'; badgeText = 'Likely human'; }
                    else if (score <= 40) { color = '#fbbc04'; badgeClass = 'badge-medium'; badgeText = 'Mixed signals'; }
                    else { color = '#ea4335'; badgeClass = 'badge-high'; badgeText = 'Likely AI-generated'; }
                }

                bar.style.backgroundColor = color;
                badge.className = 'integrity-badge ' + badgeClass;
                badge.textContent = badgeText;
            }
        })();

        const textContentElement = document.querySelector('#text_content');
        if (textContentElement) {
            ClassicEditor.create(textContentElement, {
                toolbar: [
                    'heading', '|', 'bold', 'italic', 'underline', 'strikethrough', 
                    'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor',
                    '|', 'bulletedList', 'numberedList', 'blockQuote', 'alignment', 
                    '|', 'link', 'insertTable', 'undo', 'redo', 'removeFormat'
                ],
                fontFamily: {
                    options: [
                        'default', 'Arial, Helvetica, sans-serif', 
                        'Courier New, Courier, monospace', 'Georgia, serif', 
                        'Times New Roman, Times, serif', 'Verdana, Geneva, sans-serif'
                    ]
                },
                fontSize: {
                    options: [10, 12, 14, 'default', 18, 20, 24]
                }
            }).catch(error => { 
                console.error('CKEditor error:', error); 
            });
        }
    </script>
</body>
</html>