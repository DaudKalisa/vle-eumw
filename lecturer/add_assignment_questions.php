<?php
// add_assignment_questions.php - Add questions to assignment
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();

$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
if (!$assignment_id) {
    header('Location: dashboard.php');
    exit();
}

// Get assignment details
$stmt = $conn->prepare("SELECT a.*, vc.course_name FROM vle_assignments a JOIN vle_courses vc ON a.course_id = vc.course_id WHERE a.assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
if (!$assignment) {
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['questionsData'])) {
    $questions = json_decode($_POST['questionsData'], true);
    
    if (is_array($questions)) {
        foreach ($questions as $q) {
            $q_text = $q['text'] ?? '';
            $q_type = $q['type'] ?? 'short_answer';
            $q_options = isset($q['options']) ? json_encode($q['options']) : null;
            $q_correct = $q['correct'] ?? null;
            
            if ($q_text) {
                $q_stmt = $conn->prepare("INSERT INTO vle_assignment_questions (assignment_id, question_text, question_type, options, correct_answer) VALUES (?, ?, ?, ?, ?)");
                $q_stmt->bind_param("issss", $assignment_id, $q_text, $q_type, $q_options, $q_correct);
                $q_stmt->execute();
                $q_stmt->close();
            }
        }
        $success = "Questions added successfully!";
    }
}

// Get existing questions
$stmt = $conn->prepare("SELECT * FROM vle_assignment_questions WHERE assignment_id = ? ORDER BY question_id");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$questions = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Assignment Questions - VLE System</title>
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
        .divider {
            border-bottom: 1px solid #dadce0;
            margin: 24px 0;
        }
        .existing-question {
            background: #f8f9fa;
            border-left: 4px solid #198754;
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 4px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3><i class="bi bi-question-circle"></i> Add Questions to Assignment</h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($assignment['title']); ?> • <?php echo htmlspecialchars($assignment['course_name']); ?></p>
                    </div>
                    <a href="dashboard.php?course_id=<?php echo $assignment['course_id']; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Questions Form -->
                <form method="POST" id="questionsForm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5><i class="bi bi-plus-circle"></i> New Questions</h5>
                        <button type="button" class="btn btn-primary" onclick="addQuestion()">
                            <i class="bi bi-plus-circle"></i> Add Question
                        </button>
                    </div>
                    
                    <div id="questionsList"></div>
                    
                    <input type="hidden" name="questionsData" id="questionsData">
                    
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-success btn-lg px-5">
                            <i class="bi bi-save"></i> Save Questions
                        </button>
                    </div>
                </form>

                <!-- Existing Questions -->
                <?php if ($questions->num_rows > 0): ?>
                    <div class="divider"></div>
                    <div class="question-card">
                        <h5 class="mb-3"><i class="bi bi-list-check"></i> Existing Questions</h5>
                        <?php while ($q = $questions->fetch_assoc()): ?>
                            <div class="existing-question">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="badge bg-primary me-2"><?php echo ucfirst(str_replace('_', ' ', $q['question_type'])); ?></span>
                                            <strong><?php echo htmlspecialchars($q['question_text']); ?></strong>
                                        </div>
                                        
                                        <?php if (in_array($q['question_type'], ['multiple_choice', 'checkboxes', 'dropdown']) && $q['options']): ?>
                                            <div class="ms-3">
                                                <small class="text-muted d-block mb-1">Options:</small>
                                                <ul class="mb-0">
                                                    <?php foreach (json_decode($q['options'], true) as $opt): ?>
                                                        <li><?php echo htmlspecialchars($opt); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($q['correct_answer']): ?>
                                            <div class="ms-3 mt-2">
                                                <small class="text-muted">Correct Answer:</small>
                                                <span class="badge bg-success"><?php echo htmlspecialchars($q['correct_answer']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questions = [];
        let questionCounter = 0;

        function addQuestion() {
            const id = ++questionCounter;
            const question = {
                id: id,
                text: '',
                type: 'short_answer',
                options: [],
                correct: null
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

            if (questions.length === 0) {
                container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size: 3rem;"></i><p class="mt-3">No questions added yet. Click "Add Question" to start.</p></div>';
                return;
            }

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
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(${q.id})">
                            <i class="bi bi-trash"></i> Delete Question
                        </button>
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
                    const optionEscaped = opt.replace(/'/g, "\\'");
                    html += `
                        <div class="d-flex align-items-center mb-2">
                            <input type="${inputType}" class="form-check-input me-2" name="correct_${q.id}" 
                                   ${q.correct === opt ? 'checked' : ''} 
                                   onchange="setCorrectAnswer(${q.id}, '${optionEscaped}')">
                            <input type="text" class="option-input flex-grow-1" value="${opt}" 
                                   onchange="updateOption(${q.id}, ${i}, this.value)" placeholder="Option ${i + 1}">
                            ${q.options.length > 1 ? `
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeOption(${q.id}, ${i})">
                                    <i class="bi bi-x-lg"></i>
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
            renderQuestions();
        });
    </script>
</body>
</html>