<?php
// gradebook.php - Professional Gradebook for lecturers
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Grading scale function
function getGradeInfo($score) {
    if ($score === null) {
        return ['letter' => 'N/A', 'description' => 'Not Graded', 'gpa' => 0, 'color' => 'secondary'];
    }
    
    if ($score >= 85) {
        return ['letter' => 'A+', 'description' => 'High Distinction', 'gpa' => 4.0, 'color' => 'success'];
    } elseif ($score >= 75) {
        return ['letter' => 'A', 'description' => 'Distinction', 'gpa' => 3.7, 'color' => 'success'];
    } elseif ($score >= 70) {
        return ['letter' => 'B+', 'description' => 'High Credit', 'gpa' => 3.3, 'color' => 'info'];
    } elseif ($score >= 65) {
        return ['letter' => 'B', 'description' => 'Credit', 'gpa' => 3.0, 'color' => 'info'];
    } elseif ($score >= 60) {
        return ['letter' => 'C+', 'description' => 'High Pass', 'gpa' => 2.7, 'color' => 'primary'];
    } elseif ($score >= 55) {
        return ['letter' => 'C', 'description' => 'Satisfactory Pass', 'gpa' => 2.3, 'color' => 'primary'];
    } elseif ($score >= 50) {
        return ['letter' => 'C-', 'description' => 'Bare Pass', 'gpa' => 2.0, 'color' => 'warning'];
    } elseif ($score >= 45) {
        return ['letter' => 'D', 'description' => 'Marginal Failure', 'gpa' => 1.0, 'color' => 'danger'];
    } elseif ($score >= 40) {
        return ['letter' => 'E', 'description' => 'Failure', 'gpa' => 0.5, 'color' => 'danger'];
    } else {
        return ['letter' => 'F', 'description' => 'Undoubted Failure', 'gpa' => 0.0, 'color' => 'danger'];
    }
}

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission'])) {
    $submission_id = (int)$_POST['submission_id'];
    $score = isset($_POST['score']) ? (float)$_POST['score'] : null;
    $feedback = trim($_POST['feedback']);
    
    // Handle marked assignment file upload
    $marked_file_path = null;
    $marked_file_name = null;
    
    if (isset($_FILES['marked_file']) && $_FILES['marked_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/marked_assignments/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $marked_file_name = basename($_FILES['marked_file']['name']);
        $marked_file_path = time() . '_marked_' . $marked_file_name;
        $target_path = $upload_dir . $marked_file_path;
        
        if (!move_uploaded_file($_FILES['marked_file']['tmp_name'], $target_path)) {
            $marked_file_path = null;
            $marked_file_name = null;
        }
    }

    // Get submission and student details
    $details_stmt = $conn->prepare("
        SELECT vs.*, va.title as assignment_title, va.course_id,
               s.email as student_email, s.full_name as student_name,
               l.email as lecturer_email, l.full_name as lecturer_name,
               vc.course_name
        FROM vle_submissions vs
        JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
        JOIN students s ON vs.student_id = s.student_id
        JOIN vle_courses vc ON va.course_id = vc.course_id
        LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
        WHERE vs.submission_id = ?
    ");
    $details_stmt->bind_param("i", $submission_id);
    $details_stmt->execute();
    $submission_details = $details_stmt->get_result()->fetch_assoc();
    
    // Update query to include marked assignment file
    if ($marked_file_path) {
        $stmt = $conn->prepare("UPDATE vle_submissions SET score = ?, feedback = ?, graded_by = ?, graded_date = NOW(), status = 'graded', marked_file_path = ?, marked_file_name = ?, marked_file_notified = 0 WHERE submission_id = ?");
        $stmt->bind_param("dsissi", $score, $feedback, $user['user_id'], $marked_file_path, $marked_file_name, $submission_id);
    } else {
        $stmt = $conn->prepare("UPDATE vle_submissions SET score = ?, feedback = ?, graded_by = ?, graded_date = NOW(), status = 'graded' WHERE submission_id = ?");
        $stmt->bind_param("dsii", $score, $feedback, $user['user_id'], $submission_id);
    }
    
    if ($stmt->execute() && $submission_details) {
        $grade_info = getGradeInfo($score);
        
        // Send email notification
        if ($submission_details['student_email'] && $submission_details['lecturer_email']) {
            sendGradeNotificationEmail(
                $submission_details['student_email'],
                $submission_details['student_name'],
                $submission_details['lecturer_email'],
                $submission_details['lecturer_name'],
                $submission_details['assignment_title'],
                $submission_details['course_name'],
                $score,
                $grade_info['letter'],
                $feedback,
                $submission_details['course_id']
            );
        }
    }

    header("Location: gradebook.php?course_id=$course_id&success=1");
    exit();
}

// Get assignments and submissions
$assignments = [];
$result = $conn->query("SELECT * FROM vle_assignments WHERE course_id = $course_id ORDER BY week_number, due_date");
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}

// Get enrolled students
$students = [];
$result = $conn->query("
    SELECT s.student_id, s.full_name, ve.enrollment_id
    FROM vle_enrollments ve
    JOIN students s ON ve.student_id = s.student_id
    WHERE ve.course_id = $course_id
    ORDER BY s.full_name
");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get grades data
$grades = [];
foreach ($assignments as $assignment) {
    foreach ($students as $student) {
        $stmt = $conn->prepare("
            SELECT vs.*
            FROM vle_submissions vs
            WHERE vs.assignment_id = ? AND vs.student_id = ?
        ");
        $stmt->bind_param("is", $assignment['assignment_id'], $student['student_id']);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc();

        $grades[$assignment['assignment_id']][$student['student_id']] = $submission;
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
    <title>Gradebook - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .question-card {
            background: white;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            transition: box-shadow 0.2s;
        }
        .question-card:hover {
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        }
        .answer-card {
            background: #f8f9fa;
            border-left: 4px solid #1a73e8;
            padding: 12px;
            margin-top: 8px;
            border-radius: 4px;
        }
        .correct-answer {
            background: #d1e7dd;
            border-left: 4px solid #198754;
        }
        .incorrect-answer {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .submission-preview {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 12px;
            background: #fff;
        }
        .badge-score {
            font-size: 0.9rem;
            padding: 6px 12px;
        }
        .grading-panel {
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid mt-4 mb-5">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3><i class="bi bi-clipboard-data"></i> Gradebook</h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($course['course_name']); ?></p>
                    </div>
                    <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> Grade saved successfully and student notified!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($assignments)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No assignments created yet.
                    </div>
                <?php elseif (empty($students)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No students enrolled yet.
                    </div>
                <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width: 200px;">Student</th>
                                            <?php foreach ($assignments as $assignment): ?>
                                                <th class="text-center" style="min-width: 150px;">
                                                    <?php echo htmlspecialchars($assignment['title']); ?><br>
                                                    <small class="text-muted">Max: <?php echo $assignment['max_score']; ?> pts</small>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="text-center" style="min-width: 100px;">Overall</th>
                                            <th class="text-center" style="min-width: 100px;">Grade</th>
                                            <th class="text-center" style="min-width: 80px;">GPA</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($student['full_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($student['student_id']); ?></small>
                                                </td>
                                                <?php
                                                $total_score = 0;
                                                $total_max = 0;
                                                foreach ($assignments as $assignment):
                                                    $submission = $grades[$assignment['assignment_id']][$student['student_id']] ?? null;
                                                    $total_max += $assignment['max_score'];
                                                    if ($submission && $submission['score'] !== null) {
                                                        $total_score += $submission['score'];
                                                    }
                                                ?>
                                                    <td class="text-center">
                                                        <?php if ($submission): ?>
                                                            <?php if ($submission['status'] === 'graded'): ?>
                                                                <?php 
                                                                $percentage = ($submission['score'] / $assignment['max_score']) * 100;
                                                                $grade_info = getGradeInfo($percentage);
                                                                ?>
                                                                <span class="badge bg-<?php echo $grade_info['color']; ?> badge-score mb-1">
                                                                    <?php echo $grade_info['letter']; ?>
                                                                </span><br>
                                                                <small><strong><?php echo $submission['score']; ?></strong>/<?php echo $assignment['max_score']; ?></small><br>
                                                                <small class="text-muted">(<?php echo number_format($percentage, 1); ?>%)</small><br>
                                                                <button class="btn btn-sm btn-outline-primary mt-2" 
                                                                        onclick="viewSubmission(<?php echo $submission['submission_id']; ?>, <?php echo $assignment['assignment_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', '<?php echo addslashes($assignment['title']); ?>')">
                                                                    <i class="bi bi-pencil"></i> Review
                                                                </button>
                                                            <?php elseif ($submission['status'] === 'submitted'): ?>
                                                                <span class="badge bg-warning text-dark mb-2">Pending</span><br>
                                                                <button class="btn btn-sm btn-success mt-1" 
                                                                        onclick="viewSubmission(<?php echo $submission['submission_id']; ?>, <?php echo $assignment['assignment_id']; ?>, '<?php echo addslashes($student['full_name']); ?>', '<?php echo addslashes($assignment['title']); ?>')">
                                                                    <i class="bi bi-check-circle"></i> Grade Now
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Not Submitted</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <?php
                                                $overall_percentage = 0;
                                                $overall_grade_info = ['letter' => 'N/A', 'gpa' => 0, 'color' => 'secondary'];
                                                if ($total_max > 0) {
                                                    $overall_percentage = ($total_score / $total_max) * 100;
                                                    $overall_grade_info = getGradeInfo($overall_percentage);
                                                }
                                                ?>
                                                <td class="text-center">
                                                    <strong class="fs-5"><?php echo $total_max > 0 ? number_format($overall_percentage, 1) . '%' : 'N/A'; ?></strong>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo $overall_grade_info['color']; ?> badge-score">
                                                        <?php echo $overall_grade_info['letter']; ?>
                                                    </span><br>
                                                    <small class="text-muted"><?php echo $overall_grade_info['description']; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <strong class="fs-5"><?php echo number_format($overall_grade_info['gpa'], 2); ?></strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Submission Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-check"></i> Grade Assignment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left Column - Submission Content -->
                        <div class="col-lg-7">
                            <div class="mb-3">
                                <h6 class="text-muted mb-2">STUDENT</h6>
                                <h5 id="studentName"></h5>
                            </div>
                            <div class="mb-3">
                                <h6 class="text-muted mb-2">ASSIGNMENT</h6>
                                <h5 id="assignmentTitle"></h5>
                            </div>
                            
                            <div id="submissionContent"></div>
                        </div>

                        <!-- Right Column - Grading Panel -->
                        <div class="col-lg-5">
                            <div class="grading-panel">
                                <form method="POST" enctype="multipart/form-data" id="gradeForm">
                                    <input type="hidden" name="submission_id" id="submission_id">
                                    
                                    <!-- Grading Scale Reference -->
                                    <div class="question-card mb-3">
                                        <h6 class="mb-2"><i class="bi bi-award"></i> Grading Scale</h6>
                                        <div class="small">
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="mb-1">A+ (85-100) = 4.0</div>
                                                    <div class="mb-1">A (75-84) = 3.7</div>
                                                    <div class="mb-1">B+ (70-74) = 3.3</div>
                                                    <div class="mb-1">B (65-69) = 3.0</div>
                                                    <div class="mb-1">C+ (60-64) = 2.7</div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="mb-1">C (55-59) = 2.3</div>
                                                    <div class="mb-1">C- (50-54) = 2.0</div>
                                                    <div class="mb-1">D (45-49) = 1.0</div>
                                                    <div class="mb-1">E (40-44) = 0.5</div>
                                                    <div class="mb-1">F (0-39) = 0.0</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Score Input -->
                                    <div class="question-card mb-3">
                                        <label for="score" class="form-label fw-bold">
                                            <i class="bi bi-star"></i> Score (0-100) *
                                        </label>
                                        <input type="number" class="form-control form-control-lg" 
                                               id="score" name="score" step="0.01" min="0" max="100" 
                                               required onchange="updateGradePreview()">
                                        <div id="gradePreview" class="mt-3"></div>
                                    </div>

                                    <!-- Feedback -->
                                    <div class="question-card mb-3">
                                        <label for="feedback" class="form-label fw-bold">
                                            <i class="bi bi-chat-left-text"></i> Feedback
                                        </label>
                                        <textarea class="form-control" id="feedback" name="feedback" 
                                                  rows="4" placeholder="Provide feedback to help the student improve..."></textarea>
                                    </div>

                                    <!-- Marked File Upload -->
                                    <div class="question-card mb-3">
                                        <label for="marked_file" class="form-label fw-bold">
                                            <i class="bi bi-file-earmark-arrow-up"></i> Upload Marked Assignment
                                        </label>
                                        <input type="file" class="form-control" id="marked_file" 
                                               name="marked_file" accept=".pdf,.doc,.docx,.txt,.zip">
                                        <div class="form-text">Optional: Upload annotated/marked file</div>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="grade_submission" class="btn btn-success btn-lg">
                                            <i class="bi bi-check-circle"></i> Save Grade & Notify Student
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function getGradePreview(score) {
            if (score >= 85) return {letter: 'A+', gpa: 4.0, desc: 'High Distinction', color: 'success'};
            if (score >= 75) return {letter: 'A', gpa: 3.7, desc: 'Distinction', color: 'success'};
            if (score >= 70) return {letter: 'B+', gpa: 3.3, desc: 'High Credit', color: 'info'};
            if (score >= 65) return {letter: 'B', gpa: 3.0, desc: 'Credit', color: 'info'};
            if (score >= 60) return {letter: 'C+', gpa: 2.7, desc: 'High Pass', color: 'primary'};
            if (score >= 55) return {letter: 'C', gpa: 2.3, desc: 'Satisfactory Pass', color: 'primary'};
            if (score >= 50) return {letter: 'C-', gpa: 2.0, desc: 'Bare Pass', color: 'warning'};
            if (score >= 45) return {letter: 'D', gpa: 1.0, desc: 'Marginal Failure', color: 'danger'};
            if (score >= 40) return {letter: 'E', gpa: 0.5, desc: 'Failure', color: 'danger'};
            return {letter: 'F', gpa: 0.0, desc: 'Undoubted Failure', color: 'danger'};
        }
        
        function updateGradePreview() {
            const score = parseFloat(document.getElementById('score').value);
            const preview = document.getElementById('gradePreview');
            
            if (isNaN(score) || score < 0 || score > 100) {
                preview.innerHTML = '';
                return;
            }
            
            const grade = getGradePreview(score);
            const passStatus = score >= 50 
                ? '<span class="badge bg-success ms-2">PASS</span>' 
                : '<span class="badge bg-danger ms-2">FAIL</span>';
            
            preview.innerHTML = `
                <div class="alert alert-${grade.color} mb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">${grade.letter}</h4>
                            <small>${grade.desc}</small>
                        </div>
                        <div class="text-end">
                            <h4 class="mb-0">GPA: ${grade.gpa.toFixed(1)}</h4>
                            ${passStatus}
                        </div>
                    </div>
                </div>
            `;
        }

        function viewSubmission(submissionId, assignmentId, studentName, assignmentTitle) {
            document.getElementById('submission_id').value = submissionId;
            document.getElementById('studentName').textContent = studentName;
            document.getElementById('assignmentTitle').textContent = assignmentTitle;
            document.getElementById('score').value = '';
            document.getElementById('feedback').value = '';
            document.getElementById('gradePreview').innerHTML = '';
            
            // Load submission content via AJAX
            fetch(`get_submission_details.php?submission_id=${submissionId}&assignment_id=${assignmentId}`)
                .then(response => response.json())
                .then(data => {
                    displaySubmissionContent(data);
                    
                    // If already graded, populate form
                    if (data.submission && data.submission.score !== null) {
                        document.getElementById('score').value = data.submission.score;
                        document.getElementById('feedback').value = data.submission.feedback || '';
                        updateGradePreview();
                    }
                })
                .catch(error => {
                    console.error('Error loading submission:', error);
                    document.getElementById('submissionContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading submission details.</div>';
                });
            
            new bootstrap.Modal(document.getElementById('reviewModal')).show();
        }

        function displaySubmissionContent(data) {
            const container = document.getElementById('submissionContent');
            let html = '';

            // Display files
            if (data.submission && data.submission.file_path) {
                html += `
                    <div class="question-card mb-3">
                        <h6><i class="bi bi-paperclip"></i> Submitted File</h6>
                        <p class="mb-2"><strong>${data.submission.file_name}</strong></p>
                        <a href="../uploads/submissions/${data.submission.file_path}" 
                           class="btn btn-primary" target="_blank">
                            <i class="bi bi-download"></i> View/Download File
                        </a>
                    </div>
                `;
            }

            // Display text content
            if (data.submission && data.submission.text_content) {
                html += `
                    <div class="question-card mb-3">
                        <h6><i class="bi bi-file-text"></i> Text Submission</h6>
                        <div class="submission-preview">
                            ${data.submission.text_content}
                        </div>
                    </div>
                `;
            }

            // Display question answers
            if (data.questions && data.questions.length > 0) {
                html += '<div class="question-card"><h6><i class="bi bi-list-check"></i> Question Answers</h6></div>';
                
                data.questions.forEach((q, index) => {
                    const answer = data.answers.find(a => a.question_id == q.question_id);
                    const answerText = answer ? answer.answer_text : 'No answer provided';
                    
                    let answerClass = 'answer-card';
                    let statusBadge = '';
                    
                    if (q.question_type === 'multiple_choice' && answer) {
                        if (answer.is_correct === 1) {
                            answerClass = 'answer-card correct-answer';
                            statusBadge = '<span class="badge bg-success float-end">Correct</span>';
                        } else if (answer.is_correct === 0) {
                            answerClass = 'answer-card incorrect-answer';
                            statusBadge = '<span class="badge bg-danger float-end">Incorrect</span>';
                        }
                    }
                    
                    html += `
                        <div class="question-card mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge bg-primary me-2">${index + 1}</span>
                                    <strong>${q.question_text}</strong>
                                </div>
                                ${statusBadge}
                            </div>
                            
                            ${q.question_type === 'multiple_choice' && q.options ? `
                                <div class="small text-muted mb-2">
                                    <strong>Options:</strong> ${JSON.parse(q.options).join(', ')}
                                </div>
                            ` : ''}
                            
                            ${q.correct_answer ? `
                                <div class="small text-muted mb-2">
                                    <strong>Correct Answer:</strong> 
                                    <span class="badge bg-success">${q.correct_answer}</span>
                                </div>
                            ` : ''}
                            
                            <div class="${answerClass}">
                                <strong>Student's Answer:</strong><br>
                                ${formatAnswer(answerText, q.question_type)}
                            </div>
                        </div>
                    `;
                });
            }

            // If no content at all
            if (!html) {
                html = '<div class="alert alert-warning">No submission content available.</div>';
            }

            container.innerHTML = html;
        }

        function formatAnswer(answerText, questionType) {
            if (!answerText || answerText === 'No answer provided') {
                return '<em class="text-muted">No answer provided</em>';
            }

            // Try to parse JSON for checkbox answers
            try {
                const parsed = JSON.parse(answerText);
                if (Array.isArray(parsed)) {
                    return parsed.map(item => `<span class="badge bg-secondary me-1">${item}</span>`).join('');
                }
            } catch (e) {
                // Not JSON, continue
            }

            // For regular text, preserve formatting
            return answerText.replace(/\n/g, '<br>');
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>