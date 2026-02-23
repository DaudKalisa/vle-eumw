<?php
// add_assignment.php - Add assignment to VLE course
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();

// Get course_id and week from URL
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$week = isset($_GET['week']) ? (int)$_GET['week'] : 0;

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("is", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignment_mode = $_POST['assignment_mode'] ?? 'essay';
    $assignment_type = $_POST['assignment_type'] ?? 'formative';
    $max_score = (int)($_POST['max_score'] ?? 100);
    $passing_score = (int)($_POST['passing_score'] ?? 50);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $time_limit = (int)($_POST['time_limit'] ?? 0);
    $file_path = null;
    $file_name = null;

    // Handle file upload for essay questions
    if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_name = basename($_FILES['assignment_file']['name']);
        $file_path = time() . '_' . $file_name;
        $target_path = $upload_dir . $file_path;

        if (!move_uploaded_file($_FILES['assignment_file']['tmp_name'], $target_path)) {
            $error = "Failed to upload file.";
        }
    }

    if (empty($error) && !empty($title)) {
        // Insert assignment (with time_limit)
        $stmt = $conn->prepare("INSERT INTO vle_assignments (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, time_limit, file_path, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssiisiss", $course_id, $week, $title, $description, $assignment_type, $max_score, $passing_score, $due_date, $time_limit, $file_path, $file_name);

        if ($stmt->execute()) {
            $assignment_id = $stmt->insert_id;

            // If questions mode, save questions
            if ($assignment_mode === 'questions' && !empty($_POST['questionsData'])) {
                $questions = json_decode($_POST['questionsData'], true);
                
                if (is_array($questions)) {
                    foreach ($questions as $q) {
                        $q_text = $q['text'] ?? '';
                        $q_type = $q['type'] ?? 'short_answer';
                        $q_options = isset($q['options']) ? json_encode($q['options']) : null;
                        $q_correct = $q['correct'] ?? null;
                        $q_required = isset($q['required']) ? 1 : 0;
                        
                        $q_stmt = $conn->prepare("INSERT INTO vle_assignment_questions (assignment_id, question_text, question_type, options, correct_answer, is_required) VALUES (?, ?, ?, ?, ?, ?)");
                        $q_stmt->bind_param("issssi", $assignment_id, $q_text, $q_type, $q_options, $q_correct, $q_required);
                        $q_stmt->execute();
                        $q_stmt->close();
                    }
                }
            }

            $success = "Assignment created successfully!";
            echo '<script>setTimeout(function(){ window.location = "dashboard.php?course_id=' . $course_id . '"; }, 2000);</script>';
        } else {
            $error = "Failed to add assignment: " . $conn->error;
        }
    } elseif (empty($title)) {
        $error = "Title is required.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assignment - VLE System</title>
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
        .question-card.focused {
            border-left: 6px solid #1a73e8;
        }
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }
        .question-input {
            border: none;
            border-bottom: 1px solid #dadce0;
            padding: 8px 0;
            font-size: 16px;
            width: 100%;
            outline: none;
        }
        .question-input:focus {
            border-bottom: 2px solid #1a73e8;
        }
        .option-input {
            border: none;
            border-bottom: 1px dotted #dadce0;
            padding: 8px 0;
            width: 100%;
            outline: none;
        }
        .option-input:focus {
            border-bottom: 1px solid #1a73e8;
        }
        .add-option-btn {
            color: #1a73e8;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 0;
            font-size: 14px;
        }
        .add-option-btn:hover {
            background: #f1f3f4;
        }
        .question-type-select {
            border: 1px solid #dadce0;
            border-radius: 4px;
            padding: 8px 12px;
            background: white;
            cursor: pointer;
        }
        .mode-card {
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #e0e0e0;
        }
        .mode-card:hover {
            border-color: #1a73e8;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mode-card.active {
            border-color: #1a73e8;
            background: #e8f0fe;
        }
        .divider {
            border-bottom: 1px solid #dadce0;
            margin: 24px 0;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3><i class="bi bi-file-earmark-plus"></i> Create Assignment</h3>
                    <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Cancel
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

                <form method="POST" enctype="multipart/form-data" id="assignmentForm">
                    <!-- Basic Info Card -->
                    <div class="question-card">
                        <div class="mb-3">
                            <input type="text" class="form-control form-control-lg" name="title" placeholder="Assignment Title" required>
                        </div>
                        <div class="mb-3">
                            <textarea class="form-control" name="description" rows="2" placeholder="Description (optional)"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Course</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($course['course_name']); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Week</label>
                                <input type="text" class="form-control" value="Week <?php echo $week; ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment Mode Selection -->
                    <div class="question-card">
                        <h5 class="mb-3">Assignment Type</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="mode-card card h-100 active" onclick="selectMode('essay')" id="essayCard">
                                    <div class="card-body text-center">
                                        <i class="bi bi-file-text" style="font-size: 3rem; color: #1a73e8;"></i>
                                        <h5 class="mt-3">Essay Question</h5>
                                        <p class="text-muted small">Students write text or upload files</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="mode-card card h-100" onclick="selectMode('questions')" id="questionsCard">
                                    <div class="card-body text-center">
                                        <i class="bi bi-list-check" style="font-size: 3rem; color: #1a73e8;"></i>
                                        <h5 class="mt-3">Interactive Questions</h5>
                                        <p class="text-muted small">Create multiple choice, checkboxes, etc.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="assignment_mode" id="assignment_mode" value="essay">
                    </div>

                    <!-- Questions Section (Hidden by default) -->
                    <div id="questionsSection" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5><i class="bi bi-question-circle"></i> Questions</h5>
                            <button type="button" class="btn btn-primary" onclick="addQuestion()">
                                <i class="bi bi-plus-circle"></i> Add Question
                            </button>
                        </div>
                        <div id="questionsList"></div>
                    </div>

                    <!-- Settings Card -->
                    <div class="question-card">
                        <h5 class="mb-3">Assignment Settings</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assignment Category</label>
                                <select class="form-select" name="assignment_type">
                                    <option value="formative">Formative (Practice)</option>
                                    <option value="summative">Summative (Graded)</option>
                                    <option value="mid_sem">Mid Semester Exam</option>
                                    <option value="final_exam">Final Exam</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="datetime-local" class="form-control" name="due_date">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Maximum Score</label>
                                <input type="number" class="form-control" name="max_score" value="100" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Passing Score</label>
                                <input type="number" class="form-control" name="passing_score" value="50" min="0" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assignment Time Limit (minutes)</label>
                                <input type="number" class="form-control" name="time_limit" min="0" placeholder="e.g. 60">
                                <div class="form-text">Set to 0 for no time limit. Students will have this much time to complete the assignment after starting.</div>
                            </div>
                        </div>
                        <div id="fileUploadSection">
                            <label class="form-label">Attach File (Optional)</label>
                            <input type="file" class="form-control" name="assignment_file">
                            <div class="form-text">Upload instructions, reference materials, or documents</div>
                        </div>
                    </div>

                    <input type="hidden" name="questionsData" id="questionsData">

                    <!-- Submit Button -->
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-check-circle"></i> Create Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questions = [];
        let questionCounter = 0;

        function selectMode(mode) {
            document.getElementById('assignment_mode').value = mode;
            
            // Update card styles
            document.getElementById('essayCard').classList.remove('active');
            document.getElementById('questionsCard').classList.remove('active');
            
            if (mode === 'essay') {
                document.getElementById('essayCard').classList.add('active');
                document.getElementById('questionsSection').style.display = 'none';
                document.getElementById('fileUploadSection').style.display = 'block';
            } else {
                document.getElementById('questionsCard').classList.add('active');
                document.getElementById('questionsSection').style.display = 'block';
                document.getElementById('fileUploadSection').style.display = 'none';
            }
        }

        function addQuestion() {
            const id = ++questionCounter;
            const question = {
                id: id,
                text: '',
                type: 'short_answer',
                options: [],
                correct: null,
                required: true
            };
            questions.push(question);
            renderQuestions();
        }

        function removeQuestion(id) {
            questions = questions.filter(q => q.id !== id);
            renderQuestions();
        }

        function updateQuestion(id, field, value) {
            const question = questions.find(q => q.id === id);
            if (question) {
                question[field] = value;
                if (field === 'type') {
                    // Reset options when type changes
                    if (['multiple_choice', 'checkboxes', 'dropdown'].includes(value)) {
                        question.options = ['Option 1'];
                    } else {
                        question.options = [];
                    }
                    renderQuestions();
                }
                saveQuestionsData();
            }
        }

        function addOption(questionId) {
            const question = questions.find(q => q.id === questionId);
            if (question) {
                question.options.push('Option ' + (question.options.length + 1));
                renderQuestions();
            }
        }

        function removeOption(questionId, optionIndex) {
            const question = questions.find(q => q.id === questionId);
            if (question && question.options.length > 1) {
                question.options.splice(optionIndex, 1);
                renderQuestions();
            }
        }

        function updateOption(questionId, optionIndex, value) {
            const question = questions.find(q => q.id === questionId);
            if (question) {
                question.options[optionIndex] = value;
                saveQuestionsData();
            }
        }

        function setCorrectAnswer(questionId, value) {
            const question = questions.find(q => q.id === questionId);
            if (question) {
                question.correct = value;
                saveQuestionsData();
            }
        }

        function renderQuestions() {
            const container = document.getElementById('questionsList');
            container.innerHTML = '';

            questions.forEach((q, index) => {
                const card = document.createElement('div');
                card.className = 'question-card';
                card.innerHTML = `
                    <div class="question-header">
                        <div style="flex: 1;">
                            <input type="text" class="question-input" placeholder="Question ${index + 1}" 
                                   value="${q.text}" onchange="updateQuestion(${q.id}, 'text', this.value)">
                        </div>
                        <div class="ms-3">
                            <select class="question-type-select" onchange="updateQuestion(${q.id}, 'type', this.value)">
                                <option value="short_answer" ${q.type === 'short_answer' ? 'selected' : ''}>Short Answer</option>
                                <option value="paragraph" ${q.type === 'paragraph' ? 'selected' : ''}>Paragraph</option>
                                <option value="multiple_choice" ${q.type === 'multiple_choice' ? 'selected' : ''}>Multiple Choice</option>
                                <option value="checkboxes" ${q.type === 'checkboxes' ? 'selected' : ''}>Checkboxes</option>
                                <option value="dropdown" ${q.type === 'dropdown' ? 'selected' : ''}>Dropdown</option>
                            </select>
                        </div>
                    </div>
                    
                    ${renderQuestionOptions(q)}
                    
                    <div class="divider"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(${q.id})">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" ${q.required ? 'checked' : ''} 
                                   onchange="updateQuestion(${q.id}, 'required', this.checked)">
                            <label class="form-check-label">Required</label>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });

            saveQuestionsData();
        }

        function renderQuestionOptions(q) {
            if (['multiple_choice', 'checkboxes', 'dropdown'].includes(q.type)) {
                let html = '<div class="mb-3">';
                
                q.options.forEach((opt, i) => {
                    const inputType = q.type === 'multiple_choice' ? 'radio' : 'checkbox';
                    html += `
                        <div class="d-flex align-items-center mb-2">
                            <input type="${inputType}" class="form-check-input me-2" name="correct_${q.id}" 
                                   ${q.correct === opt ? 'checked' : ''} 
                                   onchange="setCorrectAnswer(${q.id}, '${opt}')">
                            <input type="text" class="option-input flex-grow-1" value="${opt}" 
                                   onchange="updateOption(${q.id}, ${i}, this.value)" placeholder="Option ${i + 1}">
                            ${q.options.length > 1 ? `
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeOption(${q.id}, ${i})">
                                    <i class="bi bi-x"></i>
                                </button>
                            ` : ''}
                        </div>
                    `;
                });
                
                html += `
                    <button type="button" class="add-option-btn" onclick="addOption(${q.id})">
                        <i class="bi bi-plus"></i> Add option
                    </button>
                    <div class="form-text mt-2">
                        <i class="bi bi-info-circle"></i> Select the correct answer by clicking the ${q.type === 'multiple_choice' ? 'radio button' : 'checkbox'}
                    </div>
                </div>
                `;
                return html;
            } else if (q.type === 'short_answer') {
                return '<input type="text" class="form-control" placeholder="Short answer text" disabled>';
            } else if (q.type === 'paragraph') {
                return '<textarea class="form-control" rows="3" placeholder="Long answer text" disabled></textarea>';
            }
            return '';
        }

        function saveQuestionsData() {
            document.getElementById('questionsData').value = JSON.stringify(questions);
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            selectMode('essay');
        });
    </script>
</body>
</html>