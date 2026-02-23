<?php
/**
 * Question Bank - Examination Officer
 * Manage exam questions (add, edit, delete)
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);

// Get exam info if specific exam
$exam = null;
if ($exam_id) {
    $stmt = $conn->prepare("SELECT e.*, c.course_name, c.course_code FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id WHERE e.exam_id = ?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
}

// Handle question addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $q_exam_id = (int)$_POST['exam_id'];
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $marks = (int)$_POST['marks'];
    $explanation = trim($_POST['explanation'] ?? '');
    
    // Process options for MC/MA
    $options = null;
    $correct_answer = '';
    
    if (in_array($question_type, ['multiple_choice', 'multiple_answer'])) {
        $opts = [];
        for ($i = 0; $i < 6; $i++) {
            $opt_text = trim($_POST['option_' . $i] ?? '');
            if (!empty($opt_text)) {
                $opts[] = $opt_text;
            }
        }
        $options = json_encode($opts);
        
        if ($question_type === 'multiple_choice') {
            $correct_answer = $_POST['correct_option'] ?? '';
        } else {
            $correct_opts = $_POST['correct_options'] ?? [];
            $correct_answer = implode(',', $correct_opts);
        }
    } elseif ($question_type === 'true_false') {
        $options = json_encode(['True', 'False']);
        $correct_answer = $_POST['correct_tf'] ?? 'True';
    } else {
        $correct_answer = trim($_POST['correct_answer'] ?? '');
    }
    
    // Get next order
    $order_result = $conn->query("SELECT MAX(question_order) as max_order FROM exam_questions WHERE exam_id = $q_exam_id");
    $next_order = ($order_result->fetch_assoc()['max_order'] ?? 0) + 1;
    
    $stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_text, question_type, options, correct_answer, marks, question_order, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssiis", $q_exam_id, $question_text, $question_type, $options, $correct_answer, $marks, $next_order, $explanation);
    
    if ($stmt->execute()) {
        $success_message = "Question added successfully!";
    } else {
        $error_message = "Error adding question: " . $conn->error;
    }
}

// Handle question update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $marks = (int)$_POST['marks'];
    $explanation = trim($_POST['explanation'] ?? '');
    
    $options = null;
    $correct_answer = '';
    
    if (in_array($question_type, ['multiple_choice', 'multiple_answer'])) {
        $opts = [];
        for ($i = 0; $i < 6; $i++) {
            $opt_text = trim($_POST['option_' . $i] ?? '');
            if (!empty($opt_text)) $opts[] = $opt_text;
        }
        $options = json_encode($opts);
        $correct_answer = $question_type === 'multiple_choice' ? ($_POST['correct_option'] ?? '') : implode(',', $_POST['correct_options'] ?? []);
    } elseif ($question_type === 'true_false') {
        $options = json_encode(['True', 'False']);
        $correct_answer = $_POST['correct_tf'] ?? 'True';
    } else {
        $correct_answer = trim($_POST['correct_answer'] ?? '');
    }
    
    $stmt = $conn->prepare("UPDATE exam_questions SET question_text=?, question_type=?, options=?, correct_answer=?, marks=?, explanation=? WHERE question_id=?");
    $stmt->bind_param("ssssisi", $question_text, $question_type, $options, $correct_answer, $marks, $explanation, $question_id);
    $stmt->execute() ? $success_message = "Question updated." : $error_message = "Error: " . $conn->error;
}

// Handle question deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    $conn->query("DELETE FROM exam_answers WHERE question_id = $question_id");
    $stmt = $conn->prepare("DELETE FROM exam_questions WHERE question_id = ?");
    $stmt->bind_param("i", $question_id);
    $stmt->execute() ? $success_message = "Question deleted." : $error_message = "Error deleting question.";
}

// Get questions
$questions = [];
if ($exam_id) {
    $result = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY question_order");
    if ($result) while ($row = $result->fetch_assoc()) $questions[] = $row;
}

// Get all exams for dropdown
$all_exams = $conn->query("SELECT exam_id, exam_code, exam_name FROM exams ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$marks_sum = array_sum(array_column($questions, 'marks'));

$page_title = "Question Bank";
$breadcrumbs = $exam ? [['url' => 'manage_exams.php', 'title' => 'Examinations'], ['url' => "exam_view.php?id=$exam_id", 'title' => $exam['exam_code']], ['title' => 'Questions']] : [['title' => 'Question Bank']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank - VLE</title>
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
                <h2 class="vle-page-title"><i class="bi bi-collection me-2"></i>Question Bank</h2>
                <?php if ($exam): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($exam['exam_name']) ?> &mdash; <?= count($questions) ?> questions, <?= $marks_sum ?>/<?= $exam['total_marks'] ?> marks</p>
                    <?php if ($marks_sum != $exam['total_marks']): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Marks mismatch: <?= $marks_sum ?> of <?= $exam['total_marks'] ?> allocated</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ($exam_id): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
                    <a href="exam_view.php?id=<?= $exam_id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Exam</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Exam Selector (if no exam specified) -->
        <?php if (!$exam_id): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">Select Examination</label>
                            <select name="exam_id" class="form-select" required>
                                <option value="">-- Choose an exam --</option>
                                <?php foreach ($all_exams as $e): ?>
                                    <option value="<?= $e['exam_id'] ?>"><?= htmlspecialchars($e['exam_code'] . ' - ' . $e['exam_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-right me-1"></i>Load Questions</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Question List -->
        <?php if ($exam_id): ?>
            <?php if (empty($questions)): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="bi bi-question-circle display-4 d-block mb-3"></i>
                        <p>No questions added yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal"><i class="bi bi-plus-circle me-1"></i>Add First Question</button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($questions as $i => $q): 
                    $opts = $q['options'] ? json_decode($q['options'], true) : [];
                    $type_labels = ['multiple_choice' => 'Multiple Choice', 'multiple_answer' => 'Multiple Answer', 'true_false' => 'True/False', 'short_answer' => 'Short Answer', 'essay' => 'Essay'];
                    $type_colors = ['multiple_choice' => 'primary', 'multiple_answer' => 'info', 'true_false' => 'success', 'short_answer' => 'warning', 'essay' => 'secondary'];
                ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-dark me-2">Q<?= $i + 1 ?></span>
                            <span class="badge bg-<?= $type_colors[$q['question_type']] ?? 'secondary' ?>"><?= $type_labels[$q['question_type']] ?? $q['question_type'] ?></span>
                            <span class="badge bg-light text-dark ms-1"><?= $q['marks'] ?> mark<?= $q['marks'] != 1 ? 's' : '' ?></span>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-warning" onclick="editQuestion(<?= htmlspecialchars(json_encode($q)) ?>)"><i class="bi bi-pencil"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this question?')">
                                <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                                <input type="hidden" name="question_id" value="<?= $q['question_id'] ?>">
                                <button type="submit" name="delete_question" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                        <?php if (!empty($opts)): ?>
                            <div class="row g-2">
                                <?php foreach ($opts as $oi => $opt): 
                                    $is_correct = false;
                                    if ($q['question_type'] === 'multiple_choice') {
                                        $is_correct = ($q['correct_answer'] == $oi);
                                    } elseif ($q['question_type'] === 'multiple_answer') {
                                        $is_correct = in_array($oi, explode(',', $q['correct_answer']));
                                    } elseif ($q['question_type'] === 'true_false') {
                                        $is_correct = ($q['correct_answer'] === $opt);
                                    }
                                ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-2 <?= $is_correct ? 'border-success bg-success bg-opacity-10' : '' ?>">
                                        <?php if ($is_correct): ?><i class="bi bi-check-circle-fill text-success me-1"></i><?php endif; ?>
                                        <?= htmlspecialchars($opt) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($q['question_type'] === 'short_answer' && $q['correct_answer']): ?>
                            <small class="text-muted"><strong>Answer:</strong> <?= htmlspecialchars($q['correct_answer']) ?></small>
                        <?php endif; ?>
                        <?php if ($q['explanation']): ?>
                            <small class="d-block text-info mt-2"><i class="bi bi-lightbulb me-1"></i><?= htmlspecialchars($q['explanation']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Add Question Modal -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Question</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Question Type</label>
                            <select name="question_type" class="form-select" id="addQType" onchange="toggleOptions('add')">
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="multiple_answer">Multiple Answer</option>
                                <option value="true_false">True / False</option>
                                <option value="short_answer">Short Answer</option>
                                <option value="essay">Essay</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Marks</label>
                            <input type="number" name="marks" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Question Text</label>
                            <textarea name="question_text" class="form-control" rows="3" required></textarea>
                        </div>
                        <!-- MC/MA Options -->
                        <div id="addOptionsSection" class="col-12">
                            <label class="form-label">Options</label>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="input-group mb-2">
                                <span class="input-group-text"><?= chr(65 + $i) ?></span>
                                <input type="text" name="option_<?= $i ?>" class="form-control" placeholder="Option <?= chr(65 + $i) ?>" <?= $i < 2 ? '' : '' ?>>
                                <div class="input-group-text">
                                    <input type="radio" name="correct_option" value="<?= $i ?>" class="add-mc-radio" <?= $i === 0 ? 'checked' : '' ?>>
                                    <input type="checkbox" name="correct_options[]" value="<?= $i ?>" class="add-ma-check d-none">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <!-- TF Options -->
                        <div id="addTFSection" class="col-12 d-none">
                            <label class="form-label">Correct Answer</label>
                            <select name="correct_tf" class="form-select">
                                <option value="True">True</option>
                                <option value="False">False</option>
                            </select>
                        </div>
                        <!-- Short Answer -->
                        <div id="addShortSection" class="col-12 d-none">
                            <label class="form-label">Correct Answer</label>
                            <input type="text" name="correct_answer" class="form-control" placeholder="Expected answer">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Explanation (optional)</label>
                            <textarea name="explanation" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_question" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Question Modal -->
    <div class="modal fade" id="editQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content" id="editForm">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                    <input type="hidden" name="question_id" id="edit_qid">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Question Type</label>
                            <select name="question_type" class="form-select" id="editQType" onchange="toggleOptions('edit')">
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="multiple_answer">Multiple Answer</option>
                                <option value="true_false">True / False</option>
                                <option value="short_answer">Short Answer</option>
                                <option value="essay">Essay</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Marks</label>
                            <input type="number" name="marks" class="form-control" id="edit_marks" min="1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Question Text</label>
                            <textarea name="question_text" class="form-control" rows="3" id="edit_text" required></textarea>
                        </div>
                        <div id="editOptionsSection" class="col-12">
                            <label class="form-label">Options</label>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="input-group mb-2">
                                <span class="input-group-text"><?= chr(65 + $i) ?></span>
                                <input type="text" name="option_<?= $i ?>" class="form-control" id="edit_opt_<?= $i ?>">
                                <div class="input-group-text">
                                    <input type="radio" name="correct_option" value="<?= $i ?>" class="edit-mc-radio">
                                    <input type="checkbox" name="correct_options[]" value="<?= $i ?>" class="edit-ma-check d-none">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <div id="editTFSection" class="col-12 d-none">
                            <label class="form-label">Correct Answer</label>
                            <select name="correct_tf" class="form-select" id="edit_tf">
                                <option value="True">True</option>
                                <option value="False">False</option>
                            </select>
                        </div>
                        <div id="editShortSection" class="col-12 d-none">
                            <label class="form-label">Correct Answer</label>
                            <input type="text" name="correct_answer" class="form-control" id="edit_answer">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Explanation</label>
                            <textarea name="explanation" class="form-control" rows="2" id="edit_explanation"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_question" class="btn btn-warning"><i class="bi bi-save me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleOptions(prefix) {
        const type = document.getElementById(prefix + 'QType').value;
        const optSec = document.getElementById(prefix + 'OptionsSection');
        const tfSec = document.getElementById(prefix + 'TFSection');
        const shortSec = document.getElementById(prefix + 'ShortSection');
        
        optSec.classList.add('d-none');
        tfSec.classList.add('d-none');
        shortSec.classList.add('d-none');
        
        if (type === 'multiple_choice' || type === 'multiple_answer') {
            optSec.classList.remove('d-none');
            const mcRadios = document.querySelectorAll('.' + prefix + '-mc-radio');
            const maChecks = document.querySelectorAll('.' + prefix + '-ma-check');
            if (type === 'multiple_choice') {
                mcRadios.forEach(r => r.classList.remove('d-none'));
                maChecks.forEach(c => c.classList.add('d-none'));
            } else {
                mcRadios.forEach(r => r.classList.add('d-none'));
                maChecks.forEach(c => c.classList.remove('d-none'));
            }
        } else if (type === 'true_false') {
            tfSec.classList.remove('d-none');
        } else if (type === 'short_answer') {
            shortSec.classList.remove('d-none');
        }
    }
    
    function editQuestion(q) {
        document.getElementById('edit_qid').value = q.question_id;
        document.getElementById('edit_text').value = q.question_text;
        document.getElementById('edit_marks').value = q.marks;
        document.getElementById('editQType').value = q.question_type;
        document.getElementById('edit_explanation').value = q.explanation || '';
        
        const opts = q.options ? JSON.parse(q.options) : [];
        for (let i = 0; i < 6; i++) {
            const el = document.getElementById('edit_opt_' + i);
            if (el) el.value = opts[i] || '';
        }
        
        if (q.question_type === 'multiple_choice') {
            document.querySelectorAll('.edit-mc-radio').forEach((r, i) => r.checked = (i == q.correct_answer));
        } else if (q.question_type === 'multiple_answer') {
            const correct = q.correct_answer.split(',');
            document.querySelectorAll('.edit-ma-check').forEach((c, i) => c.checked = correct.includes(String(i)));
        } else if (q.question_type === 'true_false') {
            document.getElementById('edit_tf').value = q.correct_answer;
        } else {
            document.getElementById('edit_answer').value = q.correct_answer || '';
        }
        
        toggleOptions('edit');
        new bootstrap.Modal(document.getElementById('editQuestionModal')).show();
    }
    
    // Initialize on load
    toggleOptions('add');
    </script>
</body>
</html>
