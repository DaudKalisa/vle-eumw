<?php
// examination_manager/create_exam.php - Create New Exam
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get examination manager ID
$managerResult = $conn->query("SELECT manager_id FROM examination_managers WHERE email = '{$user['email']}'");
$managerId = $managerResult ? $managerResult->fetch_assoc()['manager_id'] : null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $examData = [
        'exam_code' => strtoupper(trim($_POST['exam_code'])),
        'exam_name' => trim($_POST['exam_name']),
        'course_id' => (int)$_POST['course_id'],
        'lecturer_id' => (int)$_POST['lecturer_id'],
        'exam_type' => $_POST['exam_type'],
        'description' => trim($_POST['description']),
        'total_questions' => (int)$_POST['total_questions'],
        'total_marks' => (int)$_POST['total_marks'],
        'passing_marks' => (int)$_POST['passing_marks'],
        'duration_minutes' => (int)$_POST['duration_minutes'],
        'start_time' => $_POST['start_time'],
        'end_time' => $_POST['end_time'],
        'instructions' => trim($_POST['instructions']),
        'exam_manager_id' => $managerId
    ];

    // Validate required fields
    $errors = [];
    if (empty($examData['exam_code'])) $errors[] = "Exam code is required";
    if (empty($examData['exam_name'])) $errors[] = "Exam name is required";
    if ($examData['course_id'] <= 0) $errors[] = "Please select a course";
    if ($examData['lecturer_id'] <= 0) $errors[] = "Please select a lecturer";
    if ($examData['duration_minutes'] <= 0) $errors[] = "Duration must be greater than 0";
    if (strtotime($examData['start_time']) >= strtotime($examData['end_time'])) {
        $errors[] = "End time must be after start time";
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Insert exam
            $stmt = $conn->prepare("INSERT INTO exams (exam_code, exam_name, course_id, lecturer_id, exam_manager_id, exam_type, description, total_questions, total_marks, passing_marks, duration_minutes, start_time, end_time, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiisssiiiisss",
                $examData['exam_code'],
                $examData['exam_name'],
                $examData['course_id'],
                $examData['lecturer_id'],
                $examData['exam_manager_id'],
                $examData['exam_type'],
                $examData['description'],
                $examData['total_questions'],
                $examData['total_marks'],
                $examData['passing_marks'],
                $examData['duration_minutes'],
                $examData['start_time'],
                $examData['end_time'],
                $examData['instructions']
            );

            if ($stmt->execute()) {
                $examId = $conn->insert_id;

                // Handle questions if provided
                if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                    foreach ($_POST['questions'] as $qIndex => $question) {
                        if (!empty($question['text'])) {
                            $questionType = $question['type'];
                            $options = null;

                            if ($questionType === 'multiple_choice' && isset($question['options'])) {
                                $options = json_encode(array_filter($question['options']));
                            }

                            $stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_number, question_text, question_type, options, correct_answer, marks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $marks = (int)($question['marks'] ?? 1);
                            $stmt->bind_param("iissssi", $examId, $qIndex, $question['text'], $questionType, $options, $question['correct_answer'], $marks);
                            $stmt->execute();
                        }
                    }
                }

                $conn->commit();
                $success = "Exam created successfully! <a href='generate_tokens.php?exam_id=$examId' class='btn btn-sm btn-success ms-2'>Generate Tokens</a>";
            } else {
                throw new Exception("Failed to create exam");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create exam: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get courses and lecturers for dropdowns
$courses = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_name");
$lecturers = $conn->query("SELECT lecturer_id, full_name, department FROM lecturers WHERE is_active = 1 ORDER BY full_name");

$pageTitle = "Create New Exam";
$breadcrumbs = [['title' => 'Create Exam']];
include 'header_nav.php';
?>
<style>
    .question-card {
        border: 1px solid var(--vle-border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        background: var(--vle-card-bg);
    }
    .option-input {
        margin-bottom: 8px;
    }
</style>
    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-plus-circle me-2"></i>Create New Exam</h1>
                    <p class="text-muted mb-0">Set up a new examination with questions and settings</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" id="examForm">
            <div class="row">
                <!-- Basic Information -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Exam Code *</label>
                                    <input type="text" class="form-control" name="exam_code" required
                                           placeholder="e.g., CS101-MID-2024">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Name *</label>
                                    <input type="text" class="form-control" name="exam_name" required
                                           placeholder="e.g., Computer Science Mid-Term Exam">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Course *</label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="">Select Course</option>
                                        <?php while ($course = $courses->fetch_assoc()): ?>
                                            <option value="<?php echo $course['course_id']; ?>">
                                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Lecturer *</label>
                                    <select class="form-select" name="lecturer_id" required>
                                        <option value="">Select Lecturer</option>
                                        <?php
                                        $lecturers->data_seek(0); // Reset pointer
                                        while ($lecturer = $lecturers->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $lecturer['lecturer_id']; ?>">
                                                <?php echo htmlspecialchars($lecturer['full_name'] . ' (' . $lecturer['department'] . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Type</label>
                                    <select class="form-select" name="exam_type">
                                        <option value="mid_term">Mid-Term Exam</option>
                                        <option value="final">Final Exam</option>
                                        <option value="quiz">Quiz</option>
                                        <option value="assignment">Assignment</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="3"
                                              placeholder="Brief description of the exam..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exam Settings -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Exam Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Total Questions</label>
                                    <input type="number" class="form-control" name="total_questions" min="1" value="10">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Total Marks</label>
                                    <input type="number" class="form-control" name="total_marks" min="1" value="100">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Passing Marks</label>
                                    <input type="number" class="form-control" name="passing_marks" min="1" value="50">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Duration (minutes) *</label>
                                    <input type="number" class="form-control" name="duration_minutes" min="1" required value="120">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Start Time *</label>
                                    <input type="datetime-local" class="form-control" name="start_time" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Time *</label>
                                    <input type="datetime-local" class="form-control" name="end_time" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Exam Instructions</label>
                                    <textarea class="form-control" name="instructions" rows="4" placeholder="Instructions for students..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Questions Section -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-question-circle me-2"></i>Questions</h5>
                            <button type="button" class="btn btn-sm btn-primary" id="addQuestionBtn">
                                <i class="bi bi-plus"></i> Add Question
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="questionsContainer">
                                <!-- Questions will be added here dynamically -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 text-center">
                <button type="submit" name="create_exam" class="btn btn-success btn-lg">
                    <i class="bi bi-check-circle"></i> Create Exam
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questionCount = 0;

        document.getElementById('addQuestionBtn').addEventListener('click', function() {
            addQuestion();
        });

        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questionsContainer');

            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6>Question ${questionCount}</h6>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeQuestion(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="mb-3">
                    <textarea class="form-control" name="questions[${questionCount}][text]" rows="3"
                              placeholder="Enter question text..." required></textarea>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <select class="form-select" name="questions[${questionCount}][type]"
                                onchange="changeQuestionType(this, ${questionCount})" required>
                            <option value="multiple_choice">Multiple Choice</option>
                            <option value="true_false">True/False</option>
                            <option value="short_answer">Short Answer</option>
                            <option value="essay">Essay</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="number" class="form-control" name="questions[${questionCount}][marks]"
                               placeholder="Marks" min="1" value="1" required>
                    </div>
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="questions[${questionCount}][correct_answer]"
                               placeholder="Correct answer" required>
                    </div>
                </div>
                <div class="options-container mt-3" id="options-${questionCount}">
                    <!-- Options will be added here for multiple choice -->
                </div>
            `;

            container.appendChild(questionCard);
            changeQuestionType(questionCard.querySelector('select'), questionCount);
        }

        function changeQuestionType(select, questionNum) {
            const type = select.value;
            const optionsContainer = document.getElementById(`options-${questionNum}`);
            const correctAnswerInput = select.closest('.question-card').querySelector('input[name*="[correct_answer]"]');

            optionsContainer.innerHTML = '';

            if (type === 'multiple_choice') {
                correctAnswerInput.placeholder = 'Correct option (A, B, C, D)';
                addMultipleChoiceOptions(optionsContainer, questionNum);
            } else if (type === 'true_false') {
                correctAnswerInput.placeholder = 'Correct answer (True/False)';
            } else {
                correctAnswerInput.placeholder = 'Correct answer';
            }
        }

        function addMultipleChoiceOptions(container, questionNum) {
            const options = ['A', 'B', 'C', 'D'];
            options.forEach(option => {
                const optionDiv = document.createElement('div');
                optionDiv.className = 'option-input';
                optionDiv.innerHTML = `
                    <input type="text" class="form-control" name="questions[${questionNum}][options][${option}]"
                           placeholder="Option ${option}" required>
                `;
                container.appendChild(optionDiv);
            });
        }

        function removeQuestion(button) {
            button.closest('.question-card').remove();
            questionCount--;
            updateQuestionNumbers();
        }

        function updateQuestionNumbers() {
            const cards = document.querySelectorAll('.question-card');
            cards.forEach((card, index) => {
                card.querySelector('h6').textContent = `Question ${index + 1}`;
            });
        }

        // Add first question by default
        addQuestion();
    </script>
</body>
</html>