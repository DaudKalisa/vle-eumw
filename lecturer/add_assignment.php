<?php
// add_assignment.php - Add assignment to VLE course
require_once '../includes/auth.php';
require_once '../includes/email.php';
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

// Ensure schema is up-to-date
$conn->query("ALTER TABLE vle_assignment_questions ADD COLUMN IF NOT EXISTS marks INT DEFAULT 1 AFTER correct_answer");
$conn->query("ALTER TABLE vle_assignment_questions ADD COLUMN IF NOT EXISTS section_id INT NULL AFTER marks");
$conn->query("ALTER TABLE vle_assignment_questions ADD COLUMN IF NOT EXISTS parent_question_id INT NULL AFTER section_id");
$conn->query("ALTER TABLE vle_assignment_questions ADD COLUMN IF NOT EXISTS sub_label VARCHAR(10) NULL AFTER parent_question_id");
$conn->query("ALTER TABLE vle_assignment_questions ADD COLUMN IF NOT EXISTS question_order INT DEFAULT 0 AFTER sub_label");
$conn->query("CREATE TABLE IF NOT EXISTS assignment_sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    section_label VARCHAR(10) DEFAULT 'A',
    section_title VARCHAR(255) DEFAULT '',
    description TEXT,
    instructions TEXT,
    total_marks INT DEFAULT 0,
    section_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

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
        // Insert assignment (without time_limit)
        $stmt = $conn->prepare("INSERT INTO vle_assignments (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, file_path, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssiisss", $course_id, $week, $title, $description, $assignment_type, $max_score, $passing_score, $due_date, $file_path, $file_name);

        if ($stmt->execute()) {
            $assignment_id = $stmt->insert_id;

            // If questions mode, save sections + questions
            if ($assignment_mode === 'questions') {
                // Save sections first
                $section_id_map = [];
                if (!empty($_POST['sectionsData'])) {
                    $sections = json_decode($_POST['sectionsData'], true);
                    if (is_array($sections)) {
                        foreach ($sections as $sec) {
                            $s_stmt = $conn->prepare("INSERT INTO assignment_sections (assignment_id, section_label, section_title, description, instructions, total_marks, section_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $s_label = $sec['label'] ?? 'A';
                            $s_title = $sec['title'] ?? '';
                            $s_desc = $sec['description'] ?? '';
                            $s_instr = $sec['instructions'] ?? '';
                            $s_marks = (int)($sec['total_marks'] ?? 0);
                            $s_order = (int)($sec['order'] ?? 0);
                            $s_stmt->bind_param("issssii", $assignment_id, $s_label, $s_title, $s_desc, $s_instr, $s_marks, $s_order);
                            $s_stmt->execute();
                            $section_id_map[$sec['id']] = $s_stmt->insert_id;
                            $s_stmt->close();
                        }
                    }
                }

                // Save questions
                if (!empty($_POST['questionsData'])) {
                    $questions = json_decode($_POST['questionsData'], true);
                    $temp_id_map = [];
                    
                    if (is_array($questions)) {
                        // First pass: insert all questions to get real IDs
                        foreach ($questions as $q) {
                            $q_text = $q['text'] ?? '';
                            $q_type = $q['type'] ?? 'short_answer';
                            $q_options = isset($q['options']) && !empty($q['options']) ? json_encode($q['options']) : null;
                            $q_correct = $q['correct'] ?? null;
                            $q_required = isset($q['required']) && $q['required'] ? 1 : 0;
                            $q_marks = isset($q['marks']) ? (int)$q['marks'] : 1;
                            $q_section = (!empty($q['section_id']) && isset($section_id_map[$q['section_id']])) ? $section_id_map[$q['section_id']] : null;
                            $q_sub_label = !empty($q['sub_label']) ? $q['sub_label'] : null;
                            $q_order = (int)($q['order'] ?? 0);
                            
                            $q_stmt = $conn->prepare("INSERT INTO vle_assignment_questions (assignment_id, question_text, question_type, options, correct_answer, marks, is_required, section_id, sub_label, question_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $q_stmt->bind_param("issssiissi", $assignment_id, $q_text, $q_type, $q_options, $q_correct, $q_marks, $q_required, $q_section, $q_sub_label, $q_order);
                            $q_stmt->execute();
                            $temp_id_map[$q['id']] = $q_stmt->insert_id;
                            $q_stmt->close();
                        }
                        
                        // Second pass: set parent_question_id for sub-questions
                        foreach ($questions as $q) {
                            if (!empty($q['parent_id']) && isset($temp_id_map[$q['parent_id']]) && isset($temp_id_map[$q['id']])) {
                                $real_parent = $temp_id_map[$q['parent_id']];
                                $real_id = $temp_id_map[$q['id']];
                                $conn->query("UPDATE vle_assignment_questions SET parent_question_id = $real_parent WHERE question_id = $real_id");
                            }
                        }
                    }
                }
            }

            $success = "Assignment created successfully!";
            
            // Send notification to enrolled students
            if (isEmailEnabled() && $due_date) {
                // Get enrolled students
                $students_stmt = $conn->prepare("
                    SELECT s.full_name, s.email 
                    FROM students s 
                    INNER JOIN vle_enrollments e ON s.student_id = e.student_id 
                    WHERE e.course_id = ? AND e.is_completed = 0
                ");
                $students_stmt->bind_param("i", $course_id);
                $students_stmt->execute();
                $students_result = $students_stmt->get_result();
                
                // Get lecturer name
                $lecturer_name = $user['display_name'] ?? 'Your Instructor';
                
                while ($student = $students_result->fetch_assoc()) {
                    sendNewAssignmentEmail(
                        $student['email'],
                        $student['full_name'],
                        $lecturer_name,
                        $course['course_name'],
                        $title,
                        $due_date,
                        $course_id
                    );
                }
            }
            
            echo '<script>setTimeout(function(){ window.location = "dashboard.php?course_id=' . $course_id . '"; }, 2000);</script>';
        } else {
            $error = "Failed to add assignment: " . $conn->error;
        }
    } elseif (empty($title)) {
        $error = "Title is required.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assignment - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
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
<body>
    <?php include 'header_nav.php'; ?>
    <div class="container mt-2 mb-2">
        <button class="btn btn-outline-secondary mb-2" onclick="window.history.back();"><i class="bi bi-arrow-left"></i> Back</button>
    </div>
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <!-- Header -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0 text-white"><i class="bi bi-file-earmark-plus me-2"></i> Create Assignment</h4>
                            <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-light btn-sm">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                        </div>
                        <p class="mb-0 mt-2 text-white-50"><i class="bi bi-book"></i> <?php echo htmlspecialchars($course['course_name']); ?> - Week <?php echo $week; ?></p>
                    </div>
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
                            <div>
                                <h5 class="mb-0"><i class="bi bi-question-circle"></i> Questions</h5>
                                <small class="text-muted" id="totalMarksDisplay">0 marks (0 questions)</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                                    <i class="bi bi-bookmark-plus"></i> Add Section
                                </button>
                                <button type="button" class="btn btn-primary" onclick="openAddQuestion()">
                                    <i class="bi bi-plus-circle"></i> Add Question
                                </button>
                            </div>
                        </div>
                        <div id="questionsList">
                            <div class="text-center text-muted py-4" id="noQuestionsMsg">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mt-2">No questions added yet. Add a section or question to start.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Add Section Modal -->
                    <div class="modal fade" id="addSectionModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header" style="background: linear-gradient(135deg, #1e3a5f, #2d5a87);">
                                    <h5 class="modal-title text-white"><i class="bi bi-bookmark-plus"></i> Add Section</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Section Label</label>
                                        <input type="text" class="form-control" id="addSecLabel" placeholder="e.g. A, B, C" maxlength="10">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Section Title</label>
                                        <input type="text" class="form-control" id="addSecTitle" placeholder="e.g. Short Answer Questions">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Instructions</label>
                                        <textarea class="form-control" id="addSecInstructions" rows="2" placeholder="Instructions for this section"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Description</label>
                                        <textarea class="form-control" id="addSecDescription" rows="2" placeholder="Optional description"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Total Marks for Section</label>
                                        <input type="number" class="form-control" id="addSecMarks" min="0" value="0">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="saveSection()">
                                        <i class="bi bi-check-circle"></i> Add Section
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Section Modal -->
                    <div class="modal fade" id="editSectionModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-warning">
                                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Section</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Section Label</label>
                                        <input type="text" class="form-control" id="editSecLabel" maxlength="10">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Section Title</label>
                                        <input type="text" class="form-control" id="editSecTitle">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Instructions</label>
                                        <textarea class="form-control" id="editSecInstructions" rows="2"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Description</label>
                                        <textarea class="form-control" id="editSecDescription" rows="2"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Total Marks for Section</label>
                                        <input type="number" class="form-control" id="editSecMarks" min="0">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-warning" onclick="updateSection()">
                                        <i class="bi bi-save"></i> Update Section
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Add Question Modal -->
                    <div class="modal fade" id="addQuestionModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                    <h5 class="modal-title text-white"><i class="bi bi-plus-circle"></i> Add Question</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Section</label>
                                            <select class="form-select" id="addQSection">
                                                <option value="">-- No Section --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Parent Question</label>
                                            <select class="form-select" id="addQParent">
                                                <option value="">-- None (Top-level) --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4" id="addSubLabelGroup" style="display:none;">
                                            <label class="form-label fw-bold">Sub-label</label>
                                            <input type="text" class="form-control" id="addQSubLabel" placeholder="e.g. a, b, c" maxlength="10">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Question Type</label>
                                            <select class="form-select" id="addQType" onchange="toggleAddOptions()">
                                                <option value="multiple_choice">Multiple Choice</option>
                                                <option value="true_false">True / False</option>
                                                <option value="short_answer">Short Answer</option>
                                                <option value="essay">Essay / Paragraph</option>
                                                <option value="checkboxes">Checkboxes (Multiple Answers)</option>
                                                <option value="dropdown">Dropdown</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Marks</label>
                                            <input type="number" class="form-control" id="addQMarks" min="1" value="1" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Required</label>
                                            <select class="form-select" id="addQRequired">
                                                <option value="1">Yes</option>
                                                <option value="0">No</option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label fw-bold">Question Text</label>
                                            <textarea class="form-control" id="addQText" rows="3" placeholder="Enter your question..."></textarea>
                                        </div>

                                        <!-- MC / Checkboxes / Dropdown Options (A-F) -->
                                        <div class="col-12" id="addOptionsSection">
                                            <label class="form-label fw-bold">Answer Options</label>
                                            <div id="addOptionsList">
                                                <div class="input-group mb-2">
                                                    <span class="input-group-text fw-bold">A</span>
                                                    <input type="text" class="form-control add-option-field" placeholder="Option A">
                                                    <div class="input-group-text">
                                                        <input type="radio" name="addCorrectOption" value="0" class="add-mc-radio" checked>
                                                        <input type="checkbox" name="addCorrectCheck" value="0" class="add-ma-check d-none">
                                                    </div>
                                                </div>
                                                <div class="input-group mb-2">
                                                    <span class="input-group-text fw-bold">B</span>
                                                    <input type="text" class="form-control add-option-field" placeholder="Option B">
                                                    <div class="input-group-text">
                                                        <input type="radio" name="addCorrectOption" value="1" class="add-mc-radio">
                                                        <input type="checkbox" name="addCorrectCheck" value="1" class="add-ma-check d-none">
                                                    </div>
                                                </div>
                                                <div class="input-group mb-2">
                                                    <span class="input-group-text fw-bold">C</span>
                                                    <input type="text" class="form-control add-option-field" placeholder="Option C">
                                                    <div class="input-group-text">
                                                        <input type="radio" name="addCorrectOption" value="2" class="add-mc-radio">
                                                        <input type="checkbox" name="addCorrectCheck" value="2" class="add-ma-check d-none">
                                                    </div>
                                                </div>
                                                <div class="input-group mb-2">
                                                    <span class="input-group-text fw-bold">D</span>
                                                    <input type="text" class="form-control add-option-field" placeholder="Option D">
                                                    <div class="input-group-text">
                                                        <input type="radio" name="addCorrectOption" value="3" class="add-mc-radio">
                                                        <input type="checkbox" name="addCorrectCheck" value="3" class="add-ma-check d-none">
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addOptionField()">
                                                <i class="bi bi-plus"></i> Add Option
                                            </button>
                                            <div class="form-text mt-2">
                                                <i class="bi bi-info-circle"></i> Select the correct answer using the radio button (or checkboxes for multiple answers)
                                            </div>
                                        </div>

                                        <!-- True/False -->
                                        <div class="col-12 d-none" id="addTFSection">
                                            <label class="form-label fw-bold">Correct Answer</label>
                                            <select class="form-select" id="addTFAnswer">
                                                <option value="True">True</option>
                                                <option value="False">False</option>
                                            </select>
                                        </div>

                                        <!-- Short Answer correct (optional) -->
                                        <div class="col-12 d-none" id="addShortSection">
                                            <label class="form-label fw-bold">Correct Answer (optional, for auto-grading)</label>
                                            <input type="text" class="form-control" id="addShortAnswer" placeholder="Leave blank for manual grading">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="saveQuestion()">
                                        <i class="bi bi-check-circle"></i> Add Question
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Question Modal -->
                    <div class="modal fade" id="editQuestionModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header bg-warning">
                                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Question</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Section</label>
                                            <select class="form-select" id="editQSection"></select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Parent Question</label>
                                            <select class="form-select" id="editQParent">
                                                <option value="">-- None (Top-level) --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4" id="editSubLabelGroup" style="display:none;">
                                            <label class="form-label fw-bold">Sub-label</label>
                                            <input type="text" class="form-control" id="editQSubLabel" maxlength="10">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Question Type</label>
                                            <select class="form-select" id="editQType" onchange="toggleEditOptions()">
                                                <option value="multiple_choice">Multiple Choice</option>
                                                <option value="true_false">True / False</option>
                                                <option value="short_answer">Short Answer</option>
                                                <option value="essay">Essay / Paragraph</option>
                                                <option value="checkboxes">Checkboxes (Multiple Answers)</option>
                                                <option value="dropdown">Dropdown</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Marks</label>
                                            <input type="number" class="form-control" id="editQMarks" min="1" value="1" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Required</label>
                                            <select class="form-select" id="editQRequired">
                                                <option value="1">Yes</option>
                                                <option value="0">No</option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label fw-bold">Question Text</label>
                                            <textarea class="form-control" id="editQText" rows="3"></textarea>
                                        </div>

                                        <!-- MC / Checkboxes / Dropdown Options -->
                                        <div class="col-12" id="editOptionsSection">
                                            <label class="form-label fw-bold">Answer Options</label>
                                            <div id="editOptionsList"></div>
                                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addEditOptionField()">
                                                <i class="bi bi-plus"></i> Add Option
                                            </button>
                                            <div class="form-text mt-2">
                                                <i class="bi bi-info-circle"></i> Select the correct answer using the radio/checkbox
                                            </div>
                                        </div>

                                        <div class="col-12 d-none" id="editTFSection">
                                            <label class="form-label fw-bold">Correct Answer</label>
                                            <select class="form-select" id="editTFAnswer">
                                                <option value="True">True</option>
                                                <option value="False">False</option>
                                            </select>
                                        </div>

                                        <div class="col-12 d-none" id="editShortSection">
                                            <label class="form-label fw-bold">Correct Answer (optional)</label>
                                            <input type="text" class="form-control" id="editShortAnswer">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-warning" onclick="updateQuestion()">
                                        <i class="bi bi-save"></i> Update Question
                                    </button>
                                </div>
                            </div>
                        </div>
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
                                    <option value="final_exam">End-Semester Examination</option>
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
                    <input type="hidden" name="sectionsData" id="sectionsData">

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
        var questions = [];
        var sections = [];
        var questionCounter = 0;
        var sectionCounter = 0;
        var optionCount = 4;
        var editOptionCount = 0;
        var editingQuestionId = null;
        var editingSectionId = null;

        function selectMode(mode) {
            document.getElementById('assignment_mode').value = mode;
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

        // ═══════════════════════════════════════
        // SECTION MANAGEMENT
        // ═══════════════════════════════════════
        function saveSection() {
            var label = document.getElementById('addSecLabel').value.trim() || String.fromCharCode(65 + sections.length);
            var title = document.getElementById('addSecTitle').value.trim();
            if (!title) { alert('Please enter a section title.'); return; }
            var sec = {
                id: ++sectionCounter,
                label: label,
                title: title,
                instructions: document.getElementById('addSecInstructions').value.trim(),
                description: document.getElementById('addSecDescription').value.trim(),
                total_marks: parseInt(document.getElementById('addSecMarks').value) || 0,
                order: sections.length
            };
            sections.push(sec);
            renderQuestions();
            document.getElementById('addSecLabel').value = '';
            document.getElementById('addSecTitle').value = '';
            document.getElementById('addSecInstructions').value = '';
            document.getElementById('addSecDescription').value = '';
            document.getElementById('addSecMarks').value = '0';
            var modal = bootstrap.Modal.getInstance(document.getElementById('addSectionModal'));
            if (modal) modal.hide();
        }

        function openEditSection(id) {
            var sec = sections.find(function(s) { return s.id === id; });
            if (!sec) return;
            editingSectionId = id;
            document.getElementById('editSecLabel').value = sec.label;
            document.getElementById('editSecTitle').value = sec.title;
            document.getElementById('editSecInstructions').value = sec.instructions || '';
            document.getElementById('editSecDescription').value = sec.description || '';
            document.getElementById('editSecMarks').value = sec.total_marks || 0;
            new bootstrap.Modal(document.getElementById('editSectionModal')).show();
        }

        function updateSection() {
            if (editingSectionId === null) return;
            var sec = sections.find(function(s) { return s.id === editingSectionId; });
            if (!sec) return;
            sec.label = document.getElementById('editSecLabel').value.trim() || sec.label;
            sec.title = document.getElementById('editSecTitle').value.trim() || sec.title;
            sec.instructions = document.getElementById('editSecInstructions').value.trim();
            sec.description = document.getElementById('editSecDescription').value.trim();
            sec.total_marks = parseInt(document.getElementById('editSecMarks').value) || 0;
            renderQuestions();
            var modal = bootstrap.Modal.getInstance(document.getElementById('editSectionModal'));
            if (modal) modal.hide();
            editingSectionId = null;
        }

        function removeSection(id) {
            if (!confirm('Delete this section? Questions in it will become unsectioned.')) return;
            sections = sections.filter(function(s) { return s.id !== id; });
            questions.forEach(function(q) { if (q.section_id === id) q.section_id = null; });
            renderQuestions();
        }

        // ═══════════════════════════════════════
        // TOGGLE OPTIONS (Add / Edit modal)
        // ═══════════════════════════════════════
        function toggleAddOptions() {
            var type = document.getElementById('addQType').value;
            document.getElementById('addOptionsSection').classList.add('d-none');
            document.getElementById('addTFSection').classList.add('d-none');
            document.getElementById('addShortSection').classList.add('d-none');
            if (type === 'multiple_choice' || type === 'checkboxes' || type === 'dropdown') {
                document.getElementById('addOptionsSection').classList.remove('d-none');
                document.querySelectorAll('.add-mc-radio').forEach(function(r) {
                    r.style.display = '';
                    if (type === 'checkboxes') { r.classList.add('d-none'); } else { r.classList.remove('d-none'); }
                });
                document.querySelectorAll('.add-ma-check').forEach(function(c) {
                    c.style.display = '';
                    if (type !== 'checkboxes') { c.classList.add('d-none'); } else { c.classList.remove('d-none'); }
                });
            } else if (type === 'true_false') {
                document.getElementById('addTFSection').classList.remove('d-none');
            } else if (type === 'short_answer') {
                document.getElementById('addShortSection').classList.remove('d-none');
            }
        }

        function toggleEditOptions() {
            var type = document.getElementById('editQType').value;
            document.getElementById('editOptionsSection').classList.add('d-none');
            document.getElementById('editTFSection').classList.add('d-none');
            document.getElementById('editShortSection').classList.add('d-none');
            if (type === 'multiple_choice' || type === 'checkboxes' || type === 'dropdown') {
                document.getElementById('editOptionsSection').classList.remove('d-none');
                document.querySelectorAll('.edit-mc-radio').forEach(function(r) {
                    r.style.display = '';
                    if (type === 'checkboxes') { r.classList.add('d-none'); } else { r.classList.remove('d-none'); }
                });
                document.querySelectorAll('.edit-ma-check').forEach(function(c) {
                    c.style.display = '';
                    if (type !== 'checkboxes') { c.classList.add('d-none'); } else { c.classList.remove('d-none'); }
                });
            } else if (type === 'true_false') {
                document.getElementById('editTFSection').classList.remove('d-none');
            } else if (type === 'short_answer') {
                document.getElementById('editShortSection').classList.remove('d-none');
            }
        }

        // ═══════════════════════════════════════
        // OPTION FIELD HELPERS
        // ═══════════════════════════════════════
        function buildOptionField(prefix, idx, letter, type, value, isCorrectMC, isCorrectMA) {
            var div = document.createElement('div');
            div.className = 'input-group mb-2';
            var radioChecked = isCorrectMC ? ' checked' : '';
            var checkChecked = isCorrectMA ? ' checked' : '';
            var radioHidden = (type === 'checkboxes') ? ' d-none' : '';
            var checkHidden = (type !== 'checkboxes') ? ' d-none' : '';
            div.innerHTML = '<span class="input-group-text fw-bold">' + letter + '</span>' +
                '<input type="text" class="form-control ' + prefix + '-option-field" placeholder="Option ' + letter + '" value="' + escapeAttr(value || '') + '">' +
                '<div class="input-group-text">' +
                '<input type="radio" name="' + prefix + 'CorrectOption" value="' + idx + '" class="' + prefix + '-mc-radio' + radioHidden + '"' + radioChecked + '>' +
                '<input type="checkbox" name="' + prefix + 'CorrectCheck" value="' + idx + '" class="' + prefix + '-ma-check' + checkHidden + '"' + checkChecked + '>' +
                '</div>';
            return div;
        }

        function addOptionField() {
            var letters = 'ABCDEFGHIJ';
            if (optionCount >= 10) return;
            var type = document.getElementById('addQType').value;
            document.getElementById('addOptionsList').appendChild(buildOptionField('add', optionCount, letters[optionCount], type, '', false, false));
            optionCount++;
        }

        function addEditOptionField() {
            var letters = 'ABCDEFGHIJ';
            if (editOptionCount >= 10) return;
            var type = document.getElementById('editQType').value;
            document.getElementById('editOptionsList').appendChild(buildOptionField('edit', editOptionCount, letters[editOptionCount], type, '', false, false));
            editOptionCount++;
        }

        // ═══════════════════════════════════════
        // COLLECT OPTIONS FROM MODAL
        // ═══════════════════════════════════════
        function collectOptionsFromModal(prefix) {
            var type = document.getElementById(prefix + 'QType').value;
            var options = [];
            var correct = null;

            if (type === 'multiple_choice' || type === 'checkboxes' || type === 'dropdown') {
                var fields = document.querySelectorAll('.' + prefix + '-option-field');
                fields.forEach(function(f) { var v = f.value.trim(); if (v) options.push(v); });
                if (options.length < 2) { alert('Please provide at least 2 options.'); return null; }
                if (type === 'checkboxes') {
                    var checked = document.querySelectorAll('.' + prefix + '-ma-check:checked');
                    if (checked.length === 0) { alert('Select at least one correct answer.'); return null; }
                    var correctArr = [];
                    checked.forEach(function(c) { correctArr.push(options[parseInt(c.value)] || ''); });
                    correct = JSON.stringify(correctArr);
                } else {
                    var selected = document.querySelector('input[name="' + prefix + 'CorrectOption"]:checked');
                    correct = selected ? (options[parseInt(selected.value)] || options[0]) : options[0];
                }
            } else if (type === 'true_false') {
                options = ['True', 'False'];
                correct = document.getElementById(prefix + 'TFAnswer').value;
            } else if (type === 'short_answer') {
                correct = document.getElementById(prefix + 'ShortAnswer').value.trim() || null;
            }
            return { options: options, correct: correct };
        }

        // ═══════════════════════════════════════
        // POPULATE SECTION & PARENT DROPDOWNS
        // ═══════════════════════════════════════
        function populateSectionDropdown(selectId, selectedVal) {
            var sel = document.getElementById(selectId);
            sel.innerHTML = '<option value="">-- No Section --</option>';
            sections.forEach(function(s) {
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = 'Section ' + s.label + ': ' + s.title;
                if (s.id == selectedVal) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        function populateParentDropdown(selectId, selectedVal, excludeId) {
            var sel = document.getElementById(selectId);
            sel.innerHTML = '<option value="">-- None (Top-level) --</option>';
            var topLevel = questions.filter(function(q) { return !q.parent_id; });
            topLevel.forEach(function(q) {
                if (excludeId && q.id === excludeId) return;
                var opt = document.createElement('option');
                opt.value = q.id;
                opt.textContent = 'Q: ' + (q.text.length > 50 ? q.text.substring(0, 50) + '...' : q.text);
                if (q.id == selectedVal) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        // ═══════════════════════════════════════
        // ADD QUESTION
        // ═══════════════════════════════════════
        function openAddQuestion(sectionId, parentId) {
            populateSectionDropdown('addQSection', sectionId || '');
            populateParentDropdown('addQParent', parentId || '', null);
            document.getElementById('addQSubLabel').value = '';
            document.getElementById('addSubLabelGroup').style.display = parentId ? '' : 'none';
            if (parentId) {
                var subs = questions.filter(function(q) { return q.parent_id === parentId; });
                var nextLabel = String.fromCharCode(97 + subs.length); // a, b, c...
                document.getElementById('addQSubLabel').value = nextLabel;
            }
            document.getElementById('addQParent').onchange = function() {
                var hasParent = this.value !== '';
                document.getElementById('addSubLabelGroup').style.display = hasParent ? '' : 'none';
                if (hasParent) {
                    var pid = parseInt(this.value);
                    var subs = questions.filter(function(q) { return q.parent_id === pid; });
                    document.getElementById('addQSubLabel').value = String.fromCharCode(97 + subs.length);
                }
            };
            resetAddModal();
            new bootstrap.Modal(document.getElementById('addQuestionModal')).show();
        }

        function saveQuestion() {
            var text = document.getElementById('addQText').value.trim();
            if (!text) { alert('Please enter a question.'); return; }
            var type = document.getElementById('addQType').value;
            var marks = parseInt(document.getElementById('addQMarks').value) || 1;
            var required = document.getElementById('addQRequired').value === '1';
            var sectionId = document.getElementById('addQSection').value ? parseInt(document.getElementById('addQSection').value) : null;
            var parentId = document.getElementById('addQParent').value ? parseInt(document.getElementById('addQParent').value) : null;
            var subLabel = parentId ? (document.getElementById('addQSubLabel').value.trim() || null) : null;

            var result = collectOptionsFromModal('add');
            if (result === null) return;

            var id = ++questionCounter;
            questions.push({
                id: id, text: text, type: type, options: result.options, correct: result.correct,
                marks: marks, required: required, section_id: sectionId, parent_id: parentId,
                sub_label: subLabel, order: questions.length
            });
            renderQuestions();
            var modal = bootstrap.Modal.getInstance(document.getElementById('addQuestionModal'));
            if (modal) modal.hide();
        }

        function resetAddModal() {
            document.getElementById('addQText').value = '';
            document.getElementById('addQType').value = 'multiple_choice';
            document.getElementById('addQMarks').value = '1';
            document.getElementById('addQRequired').value = '1';
            document.getElementById('addShortAnswer').value = '';
            var list = document.getElementById('addOptionsList');
            list.innerHTML = '';
            var letters = 'ABCD';
            for (var i = 0; i < 4; i++) {
                list.appendChild(buildOptionField('add', i, letters[i], 'multiple_choice', '', i === 0, false));
            }
            optionCount = 4;
            toggleAddOptions();
        }

        // ═══════════════════════════════════════
        // EDIT QUESTION
        // ═══════════════════════════════════════
        function openEditQuestion(id) {
            var q = questions.find(function(x) { return x.id === id; });
            if (!q) return;
            editingQuestionId = id;

            populateSectionDropdown('editQSection', q.section_id || '');
            populateParentDropdown('editQParent', q.parent_id || '', id);
            document.getElementById('editQSubLabel').value = q.sub_label || '';
            document.getElementById('editSubLabelGroup').style.display = q.parent_id ? '' : 'none';
            document.getElementById('editQParent').onchange = function() {
                document.getElementById('editSubLabelGroup').style.display = this.value ? '' : 'none';
            };

            document.getElementById('editQText').value = q.text;
            document.getElementById('editQType').value = q.type;
            document.getElementById('editQMarks').value = q.marks || 1;
            document.getElementById('editQRequired').value = q.required ? '1' : '0';

            var list = document.getElementById('editOptionsList');
            list.innerHTML = '';
            editOptionCount = 0;
            var letters = 'ABCDEFGHIJ';

            if (q.type === 'multiple_choice' || q.type === 'checkboxes' || q.type === 'dropdown') {
                var correctArr = [];
                if (q.type === 'checkboxes') { try { correctArr = JSON.parse(q.correct || '[]'); } catch(e) {} }
                q.options.forEach(function(opt, i) {
                    list.appendChild(buildOptionField('edit', i, letters[i], q.type, opt, opt === q.correct, correctArr.indexOf(opt) !== -1));
                    editOptionCount++;
                });
                while (editOptionCount < 4) {
                    list.appendChild(buildOptionField('edit', editOptionCount, letters[editOptionCount], q.type, '', false, false));
                    editOptionCount++;
                }
            } else if (q.type === 'true_false') {
                document.getElementById('editTFAnswer').value = q.correct || 'True';
            } else if (q.type === 'short_answer') {
                document.getElementById('editShortAnswer').value = q.correct || '';
            }

            toggleEditOptions();
            new bootstrap.Modal(document.getElementById('editQuestionModal')).show();
        }

        function updateQuestion() {
            if (editingQuestionId === null) return;
            var q = questions.find(function(x) { return x.id === editingQuestionId; });
            if (!q) return;

            var text = document.getElementById('editQText').value.trim();
            if (!text) { alert('Please enter a question.'); return; }
            var type = document.getElementById('editQType').value;
            var marks = parseInt(document.getElementById('editQMarks').value) || 1;
            var required = document.getElementById('editQRequired').value === '1';
            var result = collectOptionsFromModal('edit');
            if (result === null) return;

            q.text = text;
            q.type = type;
            q.marks = marks;
            q.required = required;
            q.options = result.options;
            q.correct = result.correct;
            q.section_id = document.getElementById('editQSection').value ? parseInt(document.getElementById('editQSection').value) : null;
            q.parent_id = document.getElementById('editQParent').value ? parseInt(document.getElementById('editQParent').value) : null;
            q.sub_label = q.parent_id ? (document.getElementById('editQSubLabel').value.trim() || null) : null;

            renderQuestions();
            var modal = bootstrap.Modal.getInstance(document.getElementById('editQuestionModal'));
            if (modal) modal.hide();
            editingQuestionId = null;
        }

        function removeQuestion(id) {
            // Also remove any sub-questions
            questions = questions.filter(function(q) { return q.id !== id && q.parent_id !== id; });
            renderQuestions();
        }

        // ═══════════════════════════════════════
        // RENDER ALL (SECTIONS + QUESTIONS)
        // ═══════════════════════════════════════
        function renderQuestions() {
            var container = document.getElementById('questionsList');
            var noMsg = document.getElementById('noQuestionsMsg');
            container.innerHTML = '';

            if (questions.length === 0 && sections.length === 0) {
                container.appendChild(noMsg);
                noMsg.style.display = '';
                saveAllData();
                updateTotalMarks();
                return;
            }

            var typeLabels = {
                'multiple_choice': '<i class="bi bi-ui-radios"></i> MC',
                'true_false': '<i class="bi bi-toggle-on"></i> T/F',
                'short_answer': '<i class="bi bi-input-cursor-text"></i> Short',
                'essay': '<i class="bi bi-textarea-resize"></i> Essay',
                'checkboxes': '<i class="bi bi-ui-checks"></i> Checkboxes',
                'dropdown': '<i class="bi bi-menu-button-wide"></i> Dropdown'
            };

            // Helper to render a single question card
            var globalQNum = 0;
            function renderQuestionCard(q, isSubQ) {
                if (!isSubQ) globalQNum++;
                var card = document.createElement('div');
                card.className = isSubQ ? 'ms-4 mb-2 p-3 bg-light border-start border-3 border-info rounded' : 'question-card';

                var optionsHtml = '';
                if (q.options && q.options.length > 0) {
                    var letters = 'ABCDEFGHIJ';
                    optionsHtml = '<div class="row g-2 mt-2">';
                    q.options.forEach(function(opt, i) {
                        var isCorrect = false;
                        if (q.type === 'checkboxes') {
                            try { isCorrect = JSON.parse(q.correct || '[]').indexOf(opt) !== -1; } catch(e) {}
                        } else { isCorrect = (opt === q.correct); }
                        optionsHtml += '<div class="col-md-6"><div class="border rounded p-2' + (isCorrect ? ' border-success bg-success bg-opacity-10' : '') + '">' +
                            (isCorrect ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '') +
                            '<strong>' + letters[i] + '.</strong> ' + escapeHtml(opt) + '</div></div>';
                    });
                    optionsHtml += '</div>';
                }

                var correctDisplay = '';
                if (q.correct && q.type !== 'multiple_choice' && q.type !== 'checkboxes' && q.type !== 'dropdown') {
                    correctDisplay = '<div class="mt-2"><small class="text-success"><i class="bi bi-check-circle"></i> Correct: ' + escapeHtml(String(q.correct)) + '</small></div>';
                }

                var subCount = questions.filter(function(s) { return s.parent_id === q.id; }).length;
                var subBadge = subCount > 0 ? ' <span class="badge bg-info">' + subCount + ' sub-Q</span>' : '';
                var labelText = isSubQ ? '(' + (q.sub_label || '?') + ')' : 'Q' + globalQNum;

                card.innerHTML = '<div class="d-flex justify-content-between align-items-start">' +
                    '<div>' +
                    '<span class="badge bg-dark me-1">' + labelText + '</span>' +
                    '<span class="badge bg-light text-dark">' + (typeLabels[q.type] || q.type) + '</span>' +
                    ' <span class="badge bg-info text-white">' + (q.marks || 1) + ' mk</span>' +
                    subBadge +
                    (q.required ? ' <span class="badge bg-danger ms-1">Required</span>' : '') +
                    '</div>' +
                    '<div class="d-flex gap-1">' +
                    (!isSubQ ? '<button type="button" class="btn btn-sm btn-outline-info" onclick="openAddQuestion(null,' + q.id + ')" title="Add Sub-Question"><i class="bi bi-diagram-3"></i></button>' : '') +
                    '<button type="button" class="btn btn-sm btn-outline-warning" onclick="openEditQuestion(' + q.id + ')" title="Edit"><i class="bi bi-pencil"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(' + q.id + ')" title="Delete"><i class="bi bi-trash"></i></button>' +
                    '</div></div>' +
                    '<div class="mt-2" style="font-size:1.05rem;line-height:1.6">' + escapeHtml(q.text) + '</div>' +
                    optionsHtml + correctDisplay;
                return card;
            }

            // Categorize questions
            var unsectioned = questions.filter(function(q) { return !q.section_id && !q.parent_id; });
            var sectionQuestions = {};
            sections.forEach(function(s) { sectionQuestions[s.id] = []; });
            questions.forEach(function(q) {
                if (q.section_id && !q.parent_id && sectionQuestions[q.section_id]) {
                    sectionQuestions[q.section_id].push(q);
                }
            });

            // Render sections
            sections.forEach(function(sec) {
                // Section banner
                var banner = document.createElement('div');
                banner.className = 'p-3 mb-2 rounded text-white d-flex justify-content-between align-items-center';
                banner.style.background = 'linear-gradient(135deg, #1e3a5f, #2d5a87)';
                banner.innerHTML = '<div>' +
                    '<h6 class="mb-0">Section ' + escapeHtml(sec.label) + ': ' + escapeHtml(sec.title) + '</h6>' +
                    (sec.instructions ? '<small class="text-white-50">' + escapeHtml(sec.instructions) + '</small>' : '') +
                    (sec.total_marks ? ' <span class="badge bg-light text-dark ms-2">' + sec.total_marks + ' marks</span>' : '') +
                    '</div>' +
                    '<div class="d-flex gap-1">' +
                    '<button type="button" class="btn btn-sm btn-outline-light" onclick="openAddQuestion(' + sec.id + ')" title="Add Question to Section"><i class="bi bi-plus-circle"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-warning" onclick="openEditSection(' + sec.id + ')"><i class="bi bi-pencil"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSection(' + sec.id + ')"><i class="bi bi-trash"></i></button>' +
                    '</div>';
                container.appendChild(banner);

                // Section questions
                var secQs = sectionQuestions[sec.id] || [];
                if (secQs.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'text-center text-muted py-2 mb-3';
                    empty.innerHTML = '<small>No questions in this section yet.</small>';
                    container.appendChild(empty);
                } else {
                    secQs.forEach(function(q) {
                        container.appendChild(renderQuestionCard(q, false));
                        // Render sub-questions
                        var subs = questions.filter(function(s) { return s.parent_id === q.id; });
                        subs.forEach(function(sub) {
                            container.appendChild(renderQuestionCard(sub, true));
                        });
                    });
                }
            });

            // Render unsectioned questions
            if (unsectioned.length > 0 && sections.length > 0) {
                var divider = document.createElement('div');
                divider.className = 'p-2 mb-2 rounded bg-secondary bg-opacity-10 text-center';
                divider.innerHTML = '<small class="text-muted fw-bold">Unsectioned Questions</small>';
                container.appendChild(divider);
            }
            unsectioned.forEach(function(q) {
                container.appendChild(renderQuestionCard(q, false));
                var subs = questions.filter(function(s) { return s.parent_id === q.id; });
                subs.forEach(function(sub) {
                    container.appendChild(renderQuestionCard(sub, true));
                });
            });

            saveAllData();
            updateTotalMarks();
        }

        function updateTotalMarks() {
            var total = 0;
            questions.forEach(function(q) { total += (q.marks || 1); });
            var el = document.getElementById('totalMarksDisplay');
            if (el) el.textContent = total + ' marks (' + questions.length + ' questions)';
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function escapeAttr(str) {
            return str.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        function saveAllData() {
            document.getElementById('questionsData').value = JSON.stringify(questions);
            document.getElementById('sectionsData').value = JSON.stringify(sections);
        }

        document.addEventListener('DOMContentLoaded', function() {
            selectMode('essay');
            toggleAddOptions();
        });
    </script>
</body>
</html>