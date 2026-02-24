<?php
// student/take_exam.php - Take an Examination
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get student info
$student_id = $user['related_student_id'] ?? 0;
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header("Location: exams.php?error=no_student");
    exit();
}

// Get exam
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$exam_id) {
    header("Location: exams.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM exams WHERE exam_id = ? AND is_active = 1");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();

if (!$exam) {
    header("Location: exams.php?error=not_found");
    exit();
}

// Check timing
$now = time();
$start = strtotime($exam['start_time']);
$end = strtotime($exam['end_time']);

if ($now < $start) {
    header("Location: exams.php?error=not_started");
    exit();
}

if ($now > $end) {
    header("Location: exams.php?error=ended");
    exit();
}

// Check attempts
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM exam_sessions WHERE exam_id = ? AND student_id = ?");
$stmt->bind_param("ii", $exam_id, $student_id);
$stmt->execute();
$attempt_count = $stmt->get_result()->fetch_assoc()['count'];

if ($attempt_count >= $exam['max_attempts']) {
    header("Location: exams.php?error=max_attempts");
    exit();
}

$error_message = '';
$session_id = 0;

// Check for existing active session
$stmt = $conn->prepare("SELECT * FROM exam_sessions WHERE exam_id = ? AND student_id = ? AND status = 'in_progress' ORDER BY started_at DESC LIMIT 1");
$stmt->bind_param("ii", $exam_id, $student_id);
$stmt->execute();
$existing_session = $stmt->get_result()->fetch_assoc();

if ($existing_session) {
    $session_id = $existing_session['session_id'];
} elseif (isset($_POST['start_exam'])) {
    // Token validation for token-required exams
    if ($exam['require_token']) {
        $token = trim($_POST['token'] ?? '');
        $stmt = $conn->prepare("SELECT * FROM exam_tokens WHERE exam_id = ? AND token = ? AND is_used = 0");
        $stmt->bind_param("is", $exam_id, $token);
        $stmt->execute();
        $token_result = $stmt->get_result()->fetch_assoc();
        
        if (!$token_result) {
            $error_message = "Invalid or already used token.";
        } else {
            // Mark token as used
            $stmt = $conn->prepare("UPDATE exam_tokens SET is_used = 1, used_by = ?, used_at = NOW() WHERE token_id = ?");
            $stmt->bind_param("ii", $student_id, $token_result['token_id']);
            $stmt->execute();
            
            // Create session
            $ip = $_SERVER['REMOTE_ADDR'];
            $ua = $_SERVER['HTTP_USER_AGENT'];
            $stmt = $conn->prepare("INSERT INTO exam_sessions (exam_id, student_id, token_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiis", $exam_id, $student_id, $token_result['token_id'], $ip, $ua);
            $stmt->execute();
            $session_id = $conn->insert_id;
        }
    } else {
        // Create session without token
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];
        $stmt = $conn->prepare("INSERT INTO exam_sessions (exam_id, student_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $exam_id, $student_id, $ip, $ua);
        $stmt->execute();
        $session_id = $conn->insert_id;
    }
}

// Handle answer submission via AJAX
if (isset($_POST['submit_answer']) && $session_id) {
    $question_id = (int)$_POST['question_id'];
    $answer = $_POST['answer'] ?? '';
    
    // Check if answer already exists
    $stmt = $conn->prepare("SELECT answer_id FROM exam_answers WHERE session_id = ? AND question_id = ?");
    $stmt->bind_param("ii", $session_id, $question_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        $stmt = $conn->prepare("UPDATE exam_answers SET answer_text = ?, answered_at = NOW() WHERE answer_id = ?");
        $stmt->bind_param("si", $answer, $existing['answer_id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO exam_answers (session_id, question_id, answer_text) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $session_id, $question_id, $answer);
    }
    $stmt->execute();
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }
}

// Handle exam submission
if (isset($_POST['submit_exam']) && $session_id) {
    // Get questions and calculate score
    $stmt = $conn->prepare("
        SELECT eq.question_id, eq.correct_answer, eq.marks, ea.answer_text
        FROM exam_questions eq
        LEFT JOIN exam_answers ea ON eq.question_id = ea.question_id AND ea.session_id = ?
        WHERE eq.exam_id = ?
    ");
    $stmt->bind_param("ii", $session_id, $exam_id);
    $stmt->execute();
    $questions_result = $stmt->get_result();
    
    $total_score = 0;
    while ($q = $questions_result->fetch_assoc()) {
        $student_answer = strtolower(trim($q['answer_text'] ?? ''));
        $correct_answer = strtolower(trim($q['correct_answer'] ?? ''));
        $is_correct = ($student_answer === $correct_answer) ? 1 : 0;
        $marks_obtained = $is_correct ? $q['marks'] : 0;
        $total_score += $marks_obtained;
        
        // Update answer record
        $stmt2 = $conn->prepare("UPDATE exam_answers SET is_correct = ?, marks_obtained = ? WHERE session_id = ? AND question_id = ?");
        $stmt2->bind_param("idii", $is_correct, $marks_obtained, $session_id, $q['question_id']);
        $stmt2->execute();
    }
    
    $percentage = $exam['total_marks'] > 0 ? ($total_score / $exam['total_marks']) * 100 : 0;
    $is_passed = $total_score >= $exam['passing_marks'] ? 1 : 0;
    
    // Assign grade letter
    if ($percentage >= 70) $grade = 'A';
    elseif ($percentage >= 60) $grade = 'B';
    elseif ($percentage >= 50) $grade = 'C';
    elseif ($percentage >= 40) $grade = 'D';
    else $grade = 'F';
    
    // Save result
    $stmt = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, session_id, score, percentage, is_passed, grade) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiidis", $exam_id, $student_id, $session_id, $total_score, $percentage, $is_passed, $grade);
    $stmt->execute();
    
    // Update session status
    $stmt = $conn->prepare("UPDATE exam_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    
    header("Location: exam_result.php?id=$exam_id&session=$session_id");
    exit();
}

// Get questions if session started
$questions = [];
$existing_answers = [];
if ($session_id) {
    $order_by = $exam['shuffle_questions'] ? 'RAND()' : 'question_order ASC, question_id ASC';
    $stmt = $conn->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY $order_by");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get existing answers
    $stmt = $conn->prepare("SELECT question_id, answer_text FROM exam_answers WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $answers_result = $stmt->get_result();
    while ($a = $answers_result->fetch_assoc()) {
        $existing_answers[$a['question_id']] = $a['answer_text'];
    }
}

// Get question count for pre-start
$question_count = count($questions);
if (!$session_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM exam_questions WHERE exam_id = ?");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $question_count = $stmt->get_result()->fetch_assoc()['count'];
}

// Calculate time remaining
$time_remaining = $exam['duration_minutes'] * 60;
if ($session_id && $existing_session) {
    $elapsed = time() - strtotime($existing_session['started_at']);
    $time_remaining = max(0, ($exam['duration_minutes'] * 60) - $elapsed);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> - Examination</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .exam-header { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; padding: 1rem; position: sticky; top: 0; z-index: 100; }
        .timer { font-size: 1.5rem; font-weight: bold; }
        .timer.warning { color: #ffc107; }
        .timer.danger { color: #dc3545; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .question-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        .question-number { width: 40px; height: 40px; background: #1e3c72; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .option-btn { display: block; width: 100%; text-align: left; padding: 1rem; margin-bottom: 0.5rem; border: 2px solid #dee2e6; border-radius: 8px; background: white; transition: all 0.2s; cursor: pointer; }
        .option-btn:hover { border-color: #1e3c72; background: #f8f9fa; }
        .option-btn.selected { border-color: #1e3c72; background: #e8f0fe; }
        .question-nav { position: sticky; top: 80px; }
        .q-nav-btn { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #dee2e6; background: white; margin: 3px; cursor: pointer; transition: all 0.2s; }
        .q-nav-btn:hover { border-color: #1e3c72; }
        .q-nav-btn.answered { background: #1e3c72; color: white; border-color: #1e3c72; }
        .q-nav-btn.current { border-color: #ffc107; border-width: 3px; }
    </style>
</head>
<body>
    <?php if (!$session_id): ?>
    <!-- Start Exam Screen -->
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i><?php echo htmlspecialchars($exam['exam_name']); ?></h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?></div>
                        <?php endif; ?>
                        
                        <div class="mb-4">
                            <h5>Exam Information</h5>
                            <table class="table table-bordered">
                                <tr><th width="150">Code</th><td><?php echo htmlspecialchars($exam['exam_code']); ?></td></tr>
                                <tr><th>Duration</th><td><?php echo $exam['duration_minutes']; ?> minutes</td></tr>
                                <tr><th>Total Marks</th><td><?php echo $exam['total_marks']; ?></td></tr>
                                <tr><th>Passing Marks</th><td><?php echo $exam['passing_marks']; ?></td></tr>
                                <tr><th>Questions</th><td><?php echo $question_count; ?></td></tr>
                                <tr><th>Attempts</th><td><?php echo $attempt_count + 1; ?>/<?php echo $exam['max_attempts']; ?></td></tr>
                            </table>
                        </div>
                        
                        <?php if ($exam['instructions']): ?>
                        <div class="mb-4">
                            <h5>Instructions</h5>
                            <div class="alert alert-info mb-0"><?php echo nl2br(htmlspecialchars($exam['instructions'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Once you start the exam, the timer will begin and cannot be paused. Do not close or refresh the browser.
                        </div>
                        
                        <form method="POST">
                            <?php if ($exam['require_token']): ?>
                            <div class="mb-3">
                                <label class="form-label">Access Token <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="token" placeholder="Enter your access token" required>
                                <small class="text-muted">Ask your lecturer for the access token.</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2">
                                <a href="exams.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                                <button type="submit" name="start_exam" class="btn btn-primary flex-grow-1">
                                    <i class="bi bi-play-fill me-1"></i>Start Examination
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Exam Interface -->
    <div class="exam-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><?php echo htmlspecialchars($exam['exam_name']); ?></h5>
                    <small><?php echo htmlspecialchars($exam['exam_code']); ?></small>
                </div>
                <div class="text-center">
                    <div class="timer" id="timer">--:--</div>
                    <small>Time Remaining</small>
                </div>
                <button type="button" class="btn btn-warning" onclick="confirmSubmit()">
                    <i class="bi bi-send me-1"></i>Submit Exam
                </button>
            </div>
        </div>
    </div>
    
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-lg-9">
                <form method="POST" id="examForm">
                    <?php foreach ($questions as $index => $question): 
                        $options = json_decode($question['options'], true) ?? [];
                        $current_answer = $existing_answers[$question['question_id']] ?? '';
                    ?>
                    <div class="question-card p-4" id="question-<?php echo $index + 1; ?>">
                        <div class="d-flex gap-3 mb-3">
                            <div class="question-number"><?php echo $index + 1; ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></span>
                                    <span class="badge bg-secondary"><?php echo $question['marks']; ?> mark<?php echo $question['marks'] > 1 ? 's' : ''; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <p class="fs-5 mb-4"><?php echo nl2br(htmlspecialchars($question['question_text'])); ?></p>
                        
                        <?php if ($question['question_type'] === 'multiple_choice' && !empty($options)): ?>
                            <div class="options">
                                <?php foreach ($options as $i => $opt): ?>
                                <button type="button" class="option-btn <?php echo $current_answer === $opt ? 'selected' : ''; ?>"
                                        onclick="selectOption(<?php echo $question['question_id']; ?>, this, '<?php echo htmlspecialchars(addslashes($opt)); ?>')">
                                    <span class="fw-bold me-2"><?php echo chr(65 + $i); ?>.</span>
                                    <?php echo htmlspecialchars($opt); ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($question['question_type'] === 'true_false'): ?>
                            <div class="options">
                                <button type="button" class="option-btn <?php echo $current_answer === 'True' ? 'selected' : ''; ?>"
                                        onclick="selectOption(<?php echo $question['question_id']; ?>, this, 'True')">
                                    <span class="fw-bold me-2">A.</span> True
                                </button>
                                <button type="button" class="option-btn <?php echo $current_answer === 'False' ? 'selected' : ''; ?>"
                                        onclick="selectOption(<?php echo $question['question_id']; ?>, this, 'False')">
                                    <span class="fw-bold me-2">B.</span> False
                                </button>
                            </div>
                        <?php else: ?>
                            <textarea class="form-control" rows="4" placeholder="Type your answer here..."
                                      onchange="saveTextAnswer(<?php echo $question['question_id']; ?>, this.value)"><?php echo htmlspecialchars($current_answer); ?></textarea>
                        <?php endif; ?>
                        
                        <input type="hidden" name="answers[<?php echo $question['question_id']; ?>]" 
                               id="answer-<?php echo $question['question_id']; ?>" 
                               value="<?php echo htmlspecialchars($current_answer); ?>">
                    </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="submit_exam" value="1">
                </form>
            </div>
            
            <div class="col-lg-3">
                <div class="question-nav card p-3">
                    <h6 class="mb-3">Question Navigator</h6>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($questions as $index => $question): ?>
                        <button type="button" class="q-nav-btn <?php echo !empty($existing_answers[$question['question_id']]) ? 'answered' : ''; ?>" 
                                id="nav-<?php echo $question['question_id']; ?>"
                                onclick="scrollToQuestion(<?php echo $index + 1; ?>)">
                            <?php echo $index + 1; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <div class="small">
                        <div class="d-flex align-items-center mb-2">
                            <div class="q-nav-btn answered" style="width:25px;height:25px;margin:0 8px 0 0;"></div>
                            <span>Answered</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="q-nav-btn" style="width:25px;height:25px;margin:0 8px 0 0;"></div>
                            <span>Not Answered</span>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <strong id="answeredCount">0</strong> / <?php echo count($questions); ?> answered
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Submit Modal -->
    <div class="modal fade" id="submitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Submit Examination</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to submit your exam?</p>
                    <p id="answerSummary" class="fw-bold"></p>
                    <p class="text-danger"><small><i class="bi bi-exclamation-circle me-1"></i>You cannot change your answers after submission.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Exam</button>
                    <button type="button" class="btn btn-primary" onclick="submitExam()"><i class="bi bi-send me-1"></i>Submit</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let timeRemaining = <?php echo $time_remaining; ?>;
        const totalQuestions = <?php echo count($questions); ?>;
        
        function updateTimer() {
            if (timeRemaining <= 0) {
                alert('Time is up! Your exam will be submitted automatically.');
                submitExam();
                return;
            }
            
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            const timerEl = document.getElementById('timer');
            timerEl.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeRemaining <= 60) {
                timerEl.className = 'timer danger';
            } else if (timeRemaining <= 300) {
                timerEl.className = 'timer warning';
            } else {
                timerEl.className = 'timer';
            }
            
            timeRemaining--;
        }
        
        setInterval(updateTimer, 1000);
        updateTimer();
        
        function updateAnsweredCount() {
            const count = document.querySelectorAll('.q-nav-btn.answered').length;
            document.getElementById('answeredCount').textContent = count;
        }
        
        function selectOption(questionId, button, answer) {
            const container = button.parentElement;
            container.querySelectorAll('.option-btn').forEach(btn => btn.classList.remove('selected'));
            button.classList.add('selected');
            
            document.getElementById('answer-' + questionId).value = answer;
            document.getElementById('nav-' + questionId).classList.add('answered');
            
            updateAnsweredCount();
            saveAnswer(questionId, answer);
        }
        
        function saveTextAnswer(questionId, answer) {
            document.getElementById('answer-' + questionId).value = answer;
            const navBtn = document.getElementById('nav-' + questionId);
            if (answer.trim() !== '') {
                navBtn.classList.add('answered');
            } else {
                navBtn.classList.remove('answered');
            }
            updateAnsweredCount();
            saveAnswer(questionId, answer);
        }
        
        function saveAnswer(questionId, answer) {
            fetch('take_exam.php?id=<?php echo $exam_id; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'submit_answer=1&question_id=' + questionId + '&answer=' + encodeURIComponent(answer)
            });
        }
        
        function scrollToQuestion(num) {
            document.getElementById('question-' + num).scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        function confirmSubmit() {
            const answered = document.querySelectorAll('.q-nav-btn.answered').length;
            document.getElementById('answerSummary').innerHTML = 
                '<i class="bi bi-check-circle text-success me-1"></i>Answered: ' + answered + '/' + totalQuestions +
                '<br><i class="bi bi-x-circle text-danger me-1"></i>Unanswered: ' + (totalQuestions - answered);
            new bootstrap.Modal(document.getElementById('submitModal')).show();
        }
        
        function submitExam() {
            window.onbeforeunload = null;
            document.getElementById('examForm').submit();
        }
        
        // Prevent accidental navigation
        window.onbeforeunload = function() {
            return "Your exam is in progress. Are you sure you want to leave?";
        };
        
        document.getElementById('examForm').onsubmit = function() {
            window.onbeforeunload = null;
        };
        
        // Initial count
        updateAnsweredCount();
    </script>
    <?php endif; ?>
</body>
</html>
