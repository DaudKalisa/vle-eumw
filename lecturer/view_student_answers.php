    <?php include 'lecturer_navbar.php'; ?>
<?php
// view_student_answers.php - Detailed view of student answers
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;

// Get submission details
$stmt = $conn->prepare("
    SELECT vs.*, va.title as assignment_title, va.assignment_id, va.max_score,
           s.full_name as student_name, s.student_id,
           vc.course_name, vc.course_id
    FROM vle_submissions vs
    JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
    JOIN students s ON vs.student_id = s.student_id
    JOIN vle_courses vc ON va.course_id = vc.course_id
    WHERE vs.submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    header('Location: gradebook.php');
    exit();
}

// Get questions
$stmt = $conn->prepare("
    SELECT * FROM vle_assignment_questions 
    WHERE assignment_id = ? 
    ORDER BY question_id
");
$stmt->bind_param("i", $submission['assignment_id']);
$stmt->execute();
$questions = $stmt->get_result();

// Get answers
$answers = [];
$stmt = $conn->prepare("
    SELECT * FROM vle_assignment_answers 
    WHERE assignment_id = ? AND student_id = ?
");
$stmt->bind_param("is", $submission['assignment_id'], $submission['student_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $answers[$row['question_id']] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Answers - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .question-card {
            background: white;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
        }
        .answer-correct {
            background: #d1e7dd;
            border-left: 4px solid #198754;
            padding: 12px;
            border-radius: 4px;
        }
        .answer-incorrect {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 12px;
            border-radius: 4px;
        }
        .answer-neutral {
            background: #f8f9fa;
            border-left: 4px solid #1a73e8;
            padding: 12px;
            border-radius: 4px;
        }
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                    <div>
                        <h3><i class="bi bi-clipboard-check"></i> Student Answers Review</h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['assignment_title']); ?></p>
                    </div>
                    <div>
                        <button onclick="window.print()" class="btn btn-outline-primary me-2">
                            <i class="bi bi-printer"></i> Print
                        </button>
                        <a href="gradebook.php?course_id=<?php echo $submission['course_id']; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Student Info Card -->
                <div class="question-card">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-1">STUDENT</h6>
                            <h5><?php echo htmlspecialchars($submission['student_name']); ?></h5>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['student_id']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-1">SUBMISSION STATUS</h6>
                            <?php if ($submission['status'] === 'graded'): ?>
                                <h5>
                                    <span class="badge bg-success">Graded</span>
                                    <span class="ms-2"><?php echo $submission['score']; ?>/<?php echo $submission['max_score']; ?></span>
                                </h5>
                            <?php else: ?>
                                <h5><span class="badge bg-warning text-dark">Pending Grading</span></h5>
                            <?php endif; ?>
                            <p class="text-muted mb-0">Submitted: <?php echo date('M d, Y h:i A', strtotime($submission['submission_date'])); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Questions and Answers -->
                <?php
                $question_number = 1;
                $correct_count = 0;
                $total_auto_graded = 0;
                
                while ($question = $questions->fetch_assoc()):
                    $answer = $answers[$question['question_id']] ?? null;
                    $answer_text = $answer ? $answer['answer_text'] : '';
                    
                    $is_auto_gradable = in_array($question['question_type'], ['multiple_choice', 'checkboxes']);
                    if ($is_auto_gradable) {
                        $total_auto_graded++;
                        if ($answer && $answer['is_correct'] == 1) {
                            $correct_count++;
                        }
                    }
                ?>
                    <div class="question-card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="flex-grow-1">
                                <h6 class="mb-2">
                                    <span class="badge bg-primary me-2"><?php echo $question_number++; ?></span>
                                    <?php echo htmlspecialchars($question['question_text']); ?>
                                </h6>
                                <span class="badge bg-secondary">
                                    <?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?>
                                </span>
                            </div>
                            <?php if ($is_auto_gradable && $answer): ?>
                                <?php if ($answer['is_correct'] == 1): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle"></i> Correct
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger">
                                        <i class="bi bi-x-circle"></i> Incorrect
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Show options for multiple choice/checkboxes/dropdown -->
                        <?php if (in_array($question['question_type'], ['multiple_choice', 'checkboxes', 'dropdown']) && $question['options']): ?>
                            <div class="mb-3">
                                <small class="text-muted d-block mb-2">Available Options:</small>
                                <div>
                                    <?php
                                    $options = json_decode($question['options'], true);
                                    foreach ($options as $opt):
                                    ?>
                                        <span class="badge bg-light text-dark me-2 mb-1"><?php echo htmlspecialchars($opt); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Show correct answer if available -->
                        <?php if ($question['correct_answer']): ?>
                            <div class="mb-3 p-2 bg-success bg-opacity-10 border border-success rounded">
                                <small class="text-success fw-bold">
                                    <i class="bi bi-check-circle"></i> Correct Answer: 
                                </small>
                                <span class="badge bg-success"><?php echo htmlspecialchars($question['correct_answer']); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Student's Answer -->
                        <div class="mt-3">
                            <strong class="d-block mb-2">Student's Answer:</strong>
                            <?php
                            $answer_class = 'answer-neutral';
                            if ($is_auto_gradable && $answer) {
                                $answer_class = ($answer['is_correct'] == 1) ? 'answer-correct' : 'answer-incorrect';
                            }
                            ?>
                            <div class="<?php echo $answer_class; ?>">
                                <?php if (!$answer_text): ?>
                                    <em class="text-muted">No answer provided</em>
                                <?php else: ?>
                                    <?php
                                    // Try to parse as JSON for checkbox answers
                                    $parsed = json_decode($answer_text, true);
                                    if (is_array($parsed)) {
                                        foreach ($parsed as $item) {
                                            echo '<span class="badge bg-primary me-1">' . htmlspecialchars($item) . '</span>';
                                        }
                                    } else {
                                        echo nl2br(htmlspecialchars($answer_text));
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>

                <!-- Summary Card -->
                <?php if ($total_auto_graded > 0): ?>
                    <div class="question-card bg-light">
                        <h6 class="mb-3"><i class="bi bi-graph-up"></i> Auto-Graded Questions Summary</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h3 class="text-success mb-0"><?php echo $correct_count; ?></h3>
                                    <small class="text-muted">Correct</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h3 class="text-danger mb-0"><?php echo $total_auto_graded - $correct_count; ?></h3>
                                    <small class="text-muted">Incorrect</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h3 class="text-primary mb-0"><?php echo round(($correct_count / $total_auto_graded) * 100, 1); ?>%</h3>
                                    <small class="text-muted">Score</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- File and Text Submissions -->
                <?php if ($submission['file_path'] || $submission['text_content']): ?>
                    <div class="question-card">
                        <h6 class="mb-3"><i class="bi bi-file-earmark"></i> Additional Submissions</h6>
                        
                        <?php if ($submission['file_path']): ?>
                            <div class="mb-3">
                                <strong>Uploaded File:</strong><br>
                                <a href="../uploads/submissions/<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                   class="btn btn-primary mt-2" target="_blank">
                                    <i class="bi bi-download"></i> <?php echo htmlspecialchars($submission['file_name']); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($submission['text_content']): ?>
                            <div>
                                <strong>Text Submission:</strong>
                                <div class="p-3 bg-white border rounded mt-2">
                                    <?php echo $submission['text_content']; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Grade Button -->
                <div class="text-center no-print">
                    <a href="gradebook.php?course_id=<?php echo $submission['course_id']; ?>" 
                       class="btn btn-success btn-lg px-5">
                        <i class="bi bi-pencil"></i> Grade This Submission
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>