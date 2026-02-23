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
if ($assignment_id) {
    $qstmt = $conn->prepare("SELECT * FROM vle_assignment_questions WHERE assignment_id = ? ORDER BY question_id");
    $qstmt->bind_param("i", $assignment_id);
    $qstmt->execute();
    $qres = $qstmt->get_result();
    while ($row = $qres->fetch_assoc()) {
        $questions[] = $row;
    }
    $qstmt->close();
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
        $upload_dir = '../uploads/submissions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = basename($_FILES['submission_file']['name']);
        $file_path = time() . '_' . $student_id . '_' . $file_name;
        $target_path = $upload_dir . $file_path;
        
        if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $target_path)) {
            $error = "Failed to upload file.";
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
                    
                    // Check if answer is correct for multiple choice and checkboxes
                    if ($qtype === 'multiple_choice' && $correct_answer) {
                        $is_correct = ($answer_text == $correct_answer) ? 1 : 0;
                    } elseif ($qtype === 'checkboxes' && $correct_answer) {
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
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Assignment - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
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
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <?php if ($assignment['time_limit'] > 0): ?>
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
                            
                            <?php foreach ($questions as $index => $q): ?>
                                <div class="mb-4 pb-4" style="border-bottom: 1px solid #dadce0;">
                                    <div class="question-label">
                                        <span class="badge bg-primary me-2"><?php echo ($index + 1); ?></span>
                                        <?php echo htmlspecialchars($q['question_text']); ?>
                                    </div>
                                    
                                    <?php if ($q['question_type'] === 'multiple_choice' && !empty($q['options'])): ?>
                                        <?php 
                                        $opts = json_decode($q['options'], true);
                                        $saved_answer = $existing_answers[$q['question_id']] ?? '';
                                        if (is_array($opts)): 
                                            foreach ($opts as $oi => $opt): 
                                        ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" 
                                                       name="answers[<?php echo $q['question_id']; ?>]" 
                                                       id="q_<?php echo $q['question_id']; ?>_opt_<?php echo $oi; ?>" 
                                                       value="<?php echo htmlspecialchars($opt); ?>"
                                                       <?php echo ($saved_answer == $opt) ? 'checked' : ''; ?>
                                                       required>
                                                <label class="form-check-label" 
                                                       for="q_<?php echo $q['question_id']; ?>_opt_<?php echo $oi; ?>">
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </label>
                                            </div>
                                        <?php 
                                            endforeach; 
                                        endif; 
                                        ?>
                                        
                                    <?php elseif ($q['question_type'] === 'checkboxes' && !empty($q['options'])): ?>
                                        <?php 
                                        $opts = json_decode($q['options'], true);
                                        $saved_answer = $existing_answers[$q['question_id']] ?? '[]';
                                        $saved_array = json_decode($saved_answer, true) ?: [];
                                        if (is_array($opts)): 
                                            foreach ($opts as $oi => $opt): 
                                        ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="answers[<?php echo $q['question_id']; ?>][]" 
                                                       id="q_<?php echo $q['question_id']; ?>_opt_<?php echo $oi; ?>" 
                                                       value="<?php echo htmlspecialchars($opt); ?>"
                                                       <?php echo in_array($opt, $saved_array) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" 
                                                       for="q_<?php echo $q['question_id']; ?>_opt_<?php echo $oi; ?>">
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </label>
                                            </div>
                                        <?php 
                                            endforeach; 
                                        endif; 
                                        ?>
                                        
                                    <?php elseif ($q['question_type'] === 'dropdown' && !empty($q['options'])): ?>
                                        <?php 
                                        $opts = json_decode($q['options'], true);
                                        $saved_answer = $existing_answers[$q['question_id']] ?? '';
                                        ?>
                                        <select class="form-select" name="answers[<?php echo $q['question_id']; ?>]" required>
                                            <option value="">-- Select an option --</option>
                                            <?php if (is_array($opts)): 
                                                foreach ($opts as $opt): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>"
                                                        <?php echo ($saved_answer == $opt) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </option>
                                            <?php 
                                                endforeach; 
                                            endif; 
                                            ?>
                                        </select>
                                        
                                    <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                        <?php $saved_answer = $existing_answers[$q['question_id']] ?? ''; ?>
                                        <input type="text" class="form-control" 
                                               name="answers[<?php echo $q['question_id']; ?>]" 
                                               value="<?php echo htmlspecialchars($saved_answer); ?>"
                                               placeholder="Type your answer here..." 
                                               required>
                                               
                                    <?php else: ?>
                                        <?php $saved_answer = $existing_answers[$q['question_id']] ?? ''; ?>
                                        <textarea class="form-control" 
                                                  name="answers[<?php echo $q['question_id']; ?>]" 
                                                  rows="4" 
                                                  placeholder="Type your answer here..." 
                                                  required><?php echo htmlspecialchars($saved_answer); ?></textarea>
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
                                <input type="file" class="form-control" name="submission_file">
                                <div class="form-text">Upload documents, PDFs, images, or other files</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Submit Button -->
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-send"></i> Submit Assignment
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
        <?php if ($assignment['time_limit'] > 0): ?>
        <script>
        // Timer logic
        let timeLimit = <?php echo (int)$assignment['time_limit']; ?> * 60; // seconds
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