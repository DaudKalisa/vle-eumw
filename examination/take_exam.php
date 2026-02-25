<?php
/**
 * Take Exam - Student Examination Interface
 * Features: Camera invigilation, tab-switching prevention, fullscreen enforcement,
 *           AJAX auto-save, timer, question navigation
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';
$now = date('Y-m-d H:i:s');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$session_id = (int)($_GET['session_id'] ?? 0);
$token_input = trim($_POST['token'] ?? '');
$error = '';
$exam = null;
$session = null;
$questions = [];

// --- Resume existing session ---
if ($session_id > 0) {
    $stmt = $conn->prepare("SELECT es.*, e.* FROM exam_sessions es JOIN exams e ON es.exam_id = e.exam_id WHERE es.session_id = ? AND es.student_id = ? AND es.status = 'in_progress'");
    $stmt->bind_param("is", $session_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc();
    if (!$session) { $error = 'Session not found or already completed.'; }
    else { $exam_id = $session['exam_id']; }
}

// --- Load exam ---
if ($exam_id > 0 && !$error) {
    $stmt = $conn->prepare("SELECT * FROM exams WHERE exam_id = ? AND is_active = 1");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    if (!$exam) $error = 'Examination not found or is inactive.';
}

if ($exam && !$error) {
    // Check time window
    if ($now < $exam['start_time']) $error = 'This examination has not started yet.';
    if ($now > $exam['end_time'] && !$session) $error = 'This examination window has closed.';
}

// --- Payment-based access control ---
if ($exam && !$error) {
    // Fetch student payment percentage
    $fin_stmt = $conn->prepare("SELECT sf.total_paid, sf.expected_total, sf.payment_percentage 
        FROM student_finances sf WHERE sf.student_id = ?");
    $fin_pct = 0;
    if ($fin_stmt) {
        $fin_stmt->bind_param('s', $student_id);
        $fin_stmt->execute();
        $fin_row = $fin_stmt->get_result()->fetch_assoc();
        if ($fin_row) {
            $f_total_paid    = (float) $fin_row['total_paid'];
            $f_expected_total = (float) $fin_row['expected_total'];
            $fin_pct = $f_expected_total > 0 ? round(($f_total_paid / $f_expected_total) * 100) : (int) $fin_row['payment_percentage'];
        }
    }

    $exam_type = $exam['exam_type'] ?? 'mid_term';

    if ($fin_pct < 50) {
        $error = 'exam_payment_blocked';
        $payment_error_msg = "Your fee payment is at {$fin_pct}%. You must pay at least 50% of your total fees before you can access any examination. Please pay your fees and submit proof of payment for finance to approve.";
    } elseif ($exam_type === 'final' && $fin_pct < 100) {
        $error = 'exam_payment_blocked';
        $payment_error_msg = "Your fee payment is at {$fin_pct}%. End-semester examinations require 100% fee payment. Please complete your fee payment and submit proof for finance to approve.";
    }
}
if ($exam && $exam['require_token'] && !$session && !$error) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_input) {
        $stmt = $conn->prepare("SELECT * FROM exam_tokens WHERE exam_id = ? AND token = ? AND is_used = 0 AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->bind_param("is", $exam_id, $token_input);
        $stmt->execute();
        $token_row = $stmt->get_result()->fetch_assoc();
        if (!$token_row) {
            $error = 'Invalid or expired token.';
        } else {
            // Mark token as used
            $conn->query("UPDATE exam_tokens SET is_used = 1, used_by = '$student_id', used_at = '$now' WHERE token_id = " . $token_row['token_id']);
        }
    } elseif (!$session) {
        // Show token form
        $need_token = true;
    }
}

// --- Check max attempts ---
if ($exam && !$session && !$error && !isset($need_token)) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM exam_sessions WHERE exam_id = ? AND student_id = ? AND status = 'completed'");
    $stmt->bind_param("is", $exam_id, $student_id);
    $stmt->execute();
    $attempts = $stmt->get_result()->fetch_assoc()['cnt'];
    if ($attempts >= $exam['max_attempts']) $error = 'You have reached the maximum number of attempts for this examination.';
}

// --- Create new session (if starting fresh) ---
if ($exam && !$session && !$error && !isset($need_token)) {
    // Ensure token_id allows NULL
    $conn->query("ALTER TABLE exam_sessions MODIFY COLUMN token_id INT NULL DEFAULT NULL");
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO exam_sessions (exam_id, student_id, token_id, started_at, status, time_remaining, ip_address, user_agent) VALUES (?, ?, NULL, NOW(), 'in_progress', ?, ?, ?)");
    $time_rem = $exam['duration_minutes'] * 60;
    $stmt->bind_param("isiss", $exam_id, $student_id, $time_rem, $ip, $ua);
    $stmt->execute();
    $session_id = $conn->insert_id;
    $session = ['session_id' => $session_id, 'started_at' => $now, 'time_remaining' => $time_rem];
}

// --- Load questions ---
if ($exam && $session_id > 0 && !$error && !isset($need_token)) {
    $order = $exam['shuffle_questions'] ? 'RAND()' : 'question_order ASC, question_id ASC';
    $result = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY $order");
    if ($result) while ($row = $result->fetch_assoc()) {
        // Decode options
        $row['options_array'] = json_decode($row['options'] ?? '[]', true) ?: [];
        // Load saved answer
        $ans_stmt = $conn->prepare("SELECT answer_text FROM exam_answers WHERE session_id = ? AND question_id = ?");
        $ans_stmt->bind_param("ii", $session_id, $row['question_id']);
        $ans_stmt->execute();
        $ans_row = $ans_stmt->get_result()->fetch_assoc();
        $row['saved_answer'] = $ans_row['answer_text'] ?? '';
        $questions[] = $row;
    }

    // Calculate time remaining
    if (isset($session['started_at'])) {
        $elapsed = time() - strtotime($session['started_at']);
        $total_seconds = $exam['duration_minutes'] * 60;
        $time_remaining = max(0, $total_seconds - $elapsed);
    } else {
        $time_remaining = ($session['time_remaining'] ?? $exam['duration_minutes'] * 60);
    }
}

// CAMERA INVIGILATION: Always enforce camera for exam integrity
// Even if the exam was created without require_camera, we force it ON for all exams
if ($exam) {
    $exam['require_camera'] = 1;
}

$page_title = "Take Exam";
$breadcrumbs = [['title' => 'Examinations', 'url' => 'exams.php'], ['title' => 'Take Exam']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($exam['exam_name'] ?? 'Take Exam') ?> - VLE Examination</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body.exam-mode { overflow: hidden; }
        .exam-topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1050;
            background: linear-gradient(135deg, var(--vle-primary), var(--vle-primary-dark, #162d59));
            color: #fff; padding: 10px 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: var(--vle-shadow-md);
        }
        .exam-topbar .timer { font-size: 1.5rem; font-weight: 700; font-family: 'Courier New', monospace; }
        .exam-topbar .timer.warning { color: #ffc107; animation: pulse-timer 1s infinite; }
        .exam-topbar .timer.danger { color: #dc3545; animation: pulse-timer 0.5s infinite; }
        @keyframes pulse-timer { 0%,100%{opacity:1;} 50%{opacity:0.5;} }
        .exam-container { margin-top: 70px; height: calc(100vh - 70px); display: flex; }
        .exam-sidebar { width: 280px; background: var(--vle-bg-card, #fff); border-right: 1px solid var(--vle-border); overflow-y: auto; padding: 15px; flex-shrink: 0; }
        .exam-main { flex: 1; overflow-y: auto; padding: 25px; }
        .question-nav-btn { width: 42px; height: 42px; border-radius: 8px; border: 2px solid var(--vle-border); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; margin: 3px; cursor: pointer; transition: var(--vle-transition); }
        .question-nav-btn:hover { border-color: var(--vle-primary); }
        .question-nav-btn.active { background: var(--vle-primary); color: #fff; border-color: var(--vle-primary); }
        .question-nav-btn.answered { background: #198754; color: #fff; border-color: #198754; }
        .question-card { display: none; }
        .question-card.active { display: block; }
        .camera-feed { position: fixed; bottom: 15px; left: 15px; width: 180px; height: 135px; border-radius: 10px; overflow: hidden; border: 3px solid var(--vle-primary); z-index: 1060; box-shadow: var(--vle-shadow-lg); }
        .camera-feed video { width: 100%; height: 100%; object-fit: cover; }
        .camera-feed .camera-status { position: absolute; top: 5px; left: 5px; font-size: 0.65rem; background: rgba(0,0,0,0.6); color: #fff; padding: 2px 6px; border-radius: 4px; }
        .camera-feed .recording-dot { display: inline-block; width: 8px; height: 8px; background: #dc3545; border-radius: 50%; animation: blink-dot 1s infinite; margin-right: 4px; }
        @keyframes blink-dot { 0%,100%{opacity:1;} 50%{opacity:0.2;} }
        /* Camera Pre-Check Overlay */
        .camera-precheck-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 3000;
            background: linear-gradient(135deg, #0d1b2a, #1b2838);
            color: #fff; display: flex; align-items: center; justify-content: center;
            flex-direction: column; text-align: center;
        }
        .camera-precheck-overlay .precheck-icon {
            width: 120px; height: 120px; border-radius: 50%;
            background: rgba(255,255,255,0.1); display: flex; align-items: center;
            justify-content: center; margin: 0 auto 30px; font-size: 3rem;
            animation: pulse-cam 2s infinite;
        }
        @keyframes pulse-cam { 0%,100%{box-shadow:0 0 0 0 rgba(13,110,253,0.5);} 50%{box-shadow:0 0 0 20px rgba(13,110,253,0);} }
        .camera-precheck-overlay .preview-box {
            width: 320px; height: 240px; border-radius: 12px; overflow: hidden;
            border: 3px solid #0d6efd; margin: 20px auto; background: #000;
        }
        .camera-precheck-overlay .preview-box video { width: 100%; height: 100%; object-fit: cover; }
        .violation-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 2000; background: rgba(220,53,69,0.95); color: #fff; display: none; align-items: center; justify-content: center; flex-direction: column; }
        .option-label { display: block; padding: 12px 15px; border: 2px solid var(--vle-border); border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: var(--vle-transition); }
        .option-label:hover { border-color: var(--vle-primary); background: rgba(30,60,114,0.05); }
        .option-label input:checked + .option-text { font-weight: 600; }
        .option-label:has(input:checked) { border-color: var(--vle-primary); background: rgba(30,60,114,0.1); }
    </style>
</head>
<body>

<?php if ($error): ?>
    <?php include '../student/header_nav.php'; ?>
    <div class="vle-content">
        <?php if ($error === 'exam_payment_blocked'): ?>
        <!-- Payment Required Block -->
        <div class="card border-0 shadow-sm mx-auto" style="max-width: 700px; margin-top: 40px;">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#dc3545,#c82333);display:inline-flex;align-items:center;justify-content:center;">
                        <i class="bi bi-shield-lock-fill text-white" style="font-size:2.5rem;"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-danger mb-3">Examination Access Denied</h3>
                <p class="text-muted mb-4" style="max-width:500px;margin:0 auto;"><?= htmlspecialchars($payment_error_msg ?? 'Insufficient fee payment.') ?></p>
                
                <div class="card bg-light border-0 mx-auto mb-4" style="max-width:400px;">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-1"></i>Payment Requirements</h6>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Mid-semester Exams, Quizzes &amp; Assignments</span>
                            <span class="badge bg-warning text-dark">&ge; 50%</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>End-Semester Examinations</span>
                            <span class="badge bg-danger">100%</span>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="<?= $_student_base ?? '../student/' ?>submit_payment.php" class="btn btn-danger">
                        <i class="bi bi-credit-card me-1"></i>Pay Fees &amp; Submit Proof of Payment
                    </a>
                    <a href="exams.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Examinations
                    </a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
        <a href="exams.php" class="btn btn-primary"><i class="bi bi-arrow-left me-1"></i>Back to Examinations</a>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php exit; endif; ?>

<?php if (isset($need_token)): ?>
    <?php include '../student/header_nav.php'; ?>
    <div class="vle-content">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white"><h5 class="mb-0"><i class="bi bi-key me-2"></i>Token Required</h5></div>
                    <div class="card-body">
                        <p>This examination requires a valid access token to begin. Please enter the token provided by your examination officer.</p>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Access Token</label>
                                <input type="text" name="token" class="form-control form-control-lg text-center" placeholder="Enter your token" required autofocus style="letter-spacing: 3px; font-family: monospace;">
                            </div>
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-unlock me-1"></i>Verify & Start Exam</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php exit; endif; ?>

<!-- ===== CAMERA PRE-CHECK OVERLAY ===== -->
<div class="camera-precheck-overlay" id="cameraPrecheckOverlay">
    <div id="cameraRequestStep">
        <div class="precheck-icon">
            <i class="bi bi-camera-video"></i>
        </div>
        <h2 class="mb-3">Camera Required for Invigilation</h2>
        <p class="text-white-50 mb-4" style="max-width: 500px;">
            This examination requires your camera to be active throughout the entire exam 
            for invigilation purposes. Periodic snapshots will be captured and reviewed by 
            your examination officer.
        </p>
        <div class="preview-box mb-3" id="previewBox">
            <video id="previewVideo" autoplay muted playsinline style="display:none;"></video>
            <div id="previewPlaceholder" class="d-flex align-items-center justify-content-center h-100">
                <div>
                    <i class="bi bi-camera-video-off display-4 text-muted"></i>
                    <p class="text-muted mt-2 mb-0">Camera preview will appear here</p>
                </div>
            </div>
        </div>
        <div id="cameraPermissionStatus" class="mb-4">
            <div class="spinner-border spinner-border-sm text-info me-2" role="status"></div>
            <span class="text-info">Requesting camera access...</span>
        </div>
        <button class="btn btn-primary btn-lg px-5" id="proceedToExamBtn" disabled onclick="proceedToExam()">
            <i class="bi bi-play-circle me-2"></i>Proceed to Examination
        </button>
        <div class="mt-3" id="cameraDeniedBlock" style="display:none;">
            <div class="alert alert-danger d-inline-block" style="max-width:500px;">
                <i class="bi bi-exclamation-octagon me-2"></i>
                <strong>Camera access was denied!</strong><br>
                You must allow camera access to take this examination. Please:
                <ol class="text-start mt-2 mb-2">
                    <li>Click the camera icon in your browser's address bar</li>
                    <li>Select "Allow" for camera access</li>
                    <li>Click "Retry Camera" below</li>
                </ol>
            </div>
            <br>
            <button class="btn btn-warning mt-2" onclick="retryCameraAccess()">
                <i class="bi bi-arrow-clockwise me-2"></i>Retry Camera Access
            </button>
            <a href="exams.php" class="btn btn-outline-light mt-2 ms-2">
                <i class="bi bi-arrow-left me-2"></i>Go Back
            </a>
        </div>
    </div>
</div>

<!-- ===== EXAM INTERFACE ===== -->

<!-- Top Bar -->
<div class="exam-topbar">
    <div>
        <strong><?= htmlspecialchars($exam['exam_name']) ?></strong>
        <br><small><?= htmlspecialchars($exam['exam_code']) ?></small>
    </div>
    <div class="text-center">
        <div class="timer" id="timer">--:--:--</div>
        <small>Time Remaining</small>
    </div>
    <div class="text-end">
        <button class="btn btn-danger btn-sm" onclick="confirmSubmit()"><i class="bi bi-send me-1"></i>Submit Exam</button>
    </div>
</div>

<div class="exam-container">
    <!-- Sidebar: Question Navigator -->
    <div class="exam-sidebar d-none d-lg-block">
        <h6 class="mb-3"><i class="bi bi-grid me-2"></i>Questions</h6>
        <div class="question-navigator mb-3">
            <?php foreach ($questions as $i => $q): ?>
                <div class="question-nav-btn <?= $i === 0 ? 'active' : '' ?> <?= !empty($q['saved_answer']) ? 'answered' : '' ?>" 
                     data-index="<?= $i ?>" onclick="goToQuestion(<?= $i ?>)">
                    <?= $i + 1 ?>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <div class="small text-muted mb-2">
            <span class="d-inline-block me-2" style="width:14px;height:14px;background:#198754;border-radius:3px;vertical-align:middle;"></span> Answered
            <br>
            <span class="d-inline-block me-2" style="width:14px;height:14px;border:2px solid var(--vle-border);border-radius:3px;vertical-align:middle;"></span> Not Answered
        </div>
        <hr>
        <div class="small">
            <div class="d-flex justify-content-between mb-1"><span>Answered:</span><strong id="answeredCount">0</strong></div>
            <div class="d-flex justify-content-between mb-1"><span>Remaining:</span><strong id="remainingCount"><?= count($questions) ?></strong></div>
            <div class="d-flex justify-content-between"><span>Total:</span><strong><?= count($questions) ?></strong></div>
        </div>
        <?php if ($exam['require_camera']): ?>
        <hr>
        <div class="small text-muted">
            <i class="bi bi-camera-video text-danger me-1"></i>Camera Active
            <div id="cameraStatus" class="mt-1 text-success">Initializing...</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content: Questions -->
    <div class="exam-main">
        <!-- Mobile question nav (floating) -->
        <div class="d-lg-none mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <button class="btn btn-outline-primary btn-sm" id="prevBtnMobile" onclick="prevQuestion()"><i class="bi bi-arrow-left"></i></button>
                <span class="fw-bold">Question <span id="currentQMobile">1</span> / <?= count($questions) ?></span>
                <button class="btn btn-outline-primary btn-sm" id="nextBtnMobile" onclick="nextQuestion()"><i class="bi bi-arrow-right"></i></button>
            </div>
        </div>

        <?php if (!empty($exam['instructions'])): ?>
        <div class="alert alert-info mb-4" id="examInstructions">
            <h6><i class="bi bi-info-circle me-2"></i>Instructions</h6>
            <?= nl2br(htmlspecialchars($exam['instructions'])) ?>
            <hr>
            <small class="text-muted">
                <i class="bi bi-shield-lock me-1"></i>This exam is monitored. Tab switching, copy attempts, and right-clicks are recorded.
                <?= $exam['require_camera'] ? ' Your camera will capture periodic snapshots for invigilation.' : '' ?>
            </small>
        </div>
        <?php endif; ?>

        <?php foreach ($questions as $i => $q): ?>
        <div class="question-card <?= $i === 0 ? 'active' : '' ?>" data-index="<?= $i ?>">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between">
                    <span><i class="bi bi-question-circle me-2"></i>Question <?= $i + 1 ?> of <?= count($questions) ?></span>
                    <span class="badge bg-light text-dark"><?= $q['marks'] ?> mark(s)</span>
                </div>
                <div class="card-body">
                    <div class="mb-4" style="font-size: 1.1rem; line-height: 1.6;">
                        <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                    </div>

                    <?php if ($q['question_type'] === 'multiple_choice'): ?>
                        <?php foreach ($q['options_array'] as $oi => $opt): 
                            $opt_text = is_array($opt) ? ($opt['text'] ?? $opt['option'] ?? '') : $opt;
                        ?>
                        <label class="option-label">
                            <input type="radio" name="answer_<?= $q['question_id'] ?>" value="<?= htmlspecialchars($opt_text) ?>" 
                                   class="form-check-input me-2" onchange="saveAnswer(<?= $q['question_id'] ?>, this.value, <?= $i ?>)"
                                   <?= $q['saved_answer'] === $opt_text ? 'checked' : '' ?>>
                            <span class="option-text"><?= htmlspecialchars($opt_text) ?></span>
                        </label>
                        <?php endforeach; ?>

                    <?php elseif ($q['question_type'] === 'multiple_answer'): ?>
                        <?php 
                        $saved_answers = json_decode($q['saved_answer'], true) ?: [];
                        foreach ($q['options_array'] as $oi => $opt): 
                            $opt_text = is_array($opt) ? ($opt['text'] ?? $opt['option'] ?? '') : $opt;
                        ?>
                        <label class="option-label">
                            <input type="checkbox" name="answer_<?= $q['question_id'] ?>[]" value="<?= htmlspecialchars($opt_text) ?>"
                                   class="form-check-input me-2" onchange="saveMultiAnswer(<?= $q['question_id'] ?>, <?= $i ?>)"
                                   <?= in_array($opt_text, $saved_answers) ? 'checked' : '' ?>>
                            <span class="option-text"><?= htmlspecialchars($opt_text) ?></span>
                        </label>
                        <?php endforeach; ?>

                    <?php elseif ($q['question_type'] === 'true_false'): ?>
                        <label class="option-label">
                            <input type="radio" name="answer_<?= $q['question_id'] ?>" value="True" class="form-check-input me-2"
                                   onchange="saveAnswer(<?= $q['question_id'] ?>, 'True', <?= $i ?>)" <?= $q['saved_answer'] === 'True' ? 'checked' : '' ?>>
                            <span class="option-text">True</span>
                        </label>
                        <label class="option-label">
                            <input type="radio" name="answer_<?= $q['question_id'] ?>" value="False" class="form-check-input me-2"
                                   onchange="saveAnswer(<?= $q['question_id'] ?>, 'False', <?= $i ?>)" <?= $q['saved_answer'] === 'False' ? 'checked' : '' ?>>
                            <span class="option-text">False</span>
                        </label>

                    <?php elseif ($q['question_type'] === 'short_answer'): ?>
                        <input type="text" class="form-control" id="answer_<?= $q['question_id'] ?>" 
                               value="<?= htmlspecialchars($q['saved_answer']) ?>" placeholder="Type your answer"
                               onblur="saveAnswer(<?= $q['question_id'] ?>, this.value, <?= $i ?>)">

                    <?php elseif ($q['question_type'] === 'essay'): ?>
                        <textarea class="form-control" id="answer_<?= $q['question_id'] ?>" rows="8" placeholder="Write your essay answer..."
                                  onblur="saveAnswer(<?= $q['question_id'] ?>, this.value, <?= $i ?>)"><?= htmlspecialchars($q['saved_answer']) ?></textarea>
                        <small class="text-muted mt-1 d-block">Auto-saves when you click outside the text area</small>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between">
                    <button class="btn btn-outline-secondary" onclick="prevQuestion()" <?= $i === 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-arrow-left me-1"></i>Previous
                    </button>
                    <?php if ($i < count($questions) - 1): ?>
                        <button class="btn btn-primary" onclick="nextQuestion()">
                            Next<i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    <?php else: ?>
                        <button class="btn btn-danger" onclick="confirmSubmit()">
                            <i class="bi bi-send me-1"></i>Submit Exam
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Camera Feed (Always active for invigilation) -->
<div class="camera-feed" id="cameraFeed" style="display:none;">
    <video id="cameraVideo" autoplay muted playsinline></video>
    <div class="camera-status"><span class="recording-dot"></span> REC</div>
</div>
<canvas id="snapshotCanvas" style="display:none;"></canvas>

<!-- Violation Overlay -->
<div class="violation-overlay" id="violationOverlay">
    <div class="text-center">
        <i class="bi bi-exclamation-triangle display-1 mb-3"></i>
        <h2>Warning: Suspicious Activity Detected</h2>
        <p class="fs-5" id="violationMessage">You have left the examination window. This event has been recorded.</p>
        <p>Your examination officer has been notified. Please return to the exam immediately.</p>
        <button class="btn btn-light btn-lg mt-3" onclick="dismissViolation()"><i class="bi bi-arrow-return-left me-2"></i>Return to Exam</button>
    </div>
</div>

<!-- Submit Confirmation Modal -->
<div class="modal fade" id="submitModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="bi bi-send me-2"></i>Submit Examination</h5></div>
            <div class="modal-body">
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Once submitted, you cannot change your answers.</div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between"><span>Total Questions:</span><strong><?= count($questions) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Answered:</span><strong id="modalAnswered">0</strong></div>
                    <div class="d-flex justify-content-between text-danger"><span>Unanswered:</span><strong id="modalUnanswered"><?= count($questions) ?></strong></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>
                <button type="button" class="btn btn-danger" onclick="submitExam()"><i class="bi bi-send me-1"></i>Confirm Submit</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== CONFIGURATION =====
const SESSION_ID = <?= $session_id ?>;
const EXAM_ID = <?= $exam_id ?>;
const TOTAL_QUESTIONS = <?= count($questions) ?>;
const TIME_REMAINING = <?= $time_remaining ?? 0 ?>;
const REQUIRE_CAMERA = true; // Camera is ALWAYS required for invigilation
const SNAPSHOT_INTERVAL = 30000; // Camera capture every 30 seconds
const BASE_URL = '';

let currentQuestion = 0;
let answeredSet = new Set();
let timerSeconds = TIME_REMAINING;
let violationCount = 0;
let cameraStream = null;

// Track initially answered questions
<?php foreach ($questions as $i => $q): ?>
<?php if (!empty($q['saved_answer'])): ?>
answeredSet.add(<?= $i ?>);
<?php endif; ?>
<?php endforeach; ?>

// ===== TIMER =====
function updateTimer() {
    if (timerSeconds <= 0) { autoSubmit(); return; }
    timerSeconds--;
    const h = Math.floor(timerSeconds / 3600);
    const m = Math.floor((timerSeconds % 3600) / 60);
    const s = timerSeconds % 60;
    const display = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    const timerEl = document.getElementById('timer');
    timerEl.textContent = display;
    timerEl.className = 'timer' + (timerSeconds < 60 ? ' danger' : (timerSeconds < 300 ? ' warning' : ''));
}
setInterval(updateTimer, 1000);
updateTimer();

// ===== QUESTION NAVIGATION =====
function goToQuestion(index) {
    document.querySelectorAll('.question-card').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.question-nav-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.question-card[data-index="${index}"]`).classList.add('active');
    const navBtn = document.querySelector(`.question-nav-btn[data-index="${index}"]`);
    if (navBtn) navBtn.classList.add('active');
    currentQuestion = index;
    if (document.getElementById('currentQMobile')) document.getElementById('currentQMobile').textContent = index + 1;
}

function nextQuestion() { if (currentQuestion < TOTAL_QUESTIONS - 1) goToQuestion(currentQuestion + 1); }
function prevQuestion() { if (currentQuestion > 0) goToQuestion(currentQuestion - 1); }

function updateCounts() {
    const answered = answeredSet.size;
    if (document.getElementById('answeredCount')) document.getElementById('answeredCount').textContent = answered;
    if (document.getElementById('remainingCount')) document.getElementById('remainingCount').textContent = TOTAL_QUESTIONS - answered;
    if (document.getElementById('modalAnswered')) document.getElementById('modalAnswered').textContent = answered;
    if (document.getElementById('modalUnanswered')) document.getElementById('modalUnanswered').textContent = TOTAL_QUESTIONS - answered;
}
updateCounts();

// ===== SAVE ANSWER (AJAX) =====
function saveAnswer(questionId, answer, index) {
    if (answer && answer.trim() !== '') {
        answeredSet.add(index);
    } else {
        answeredSet.delete(index);
    }
    const navBtn = document.querySelector(`.question-nav-btn[data-index="${index}"]`);
    if (navBtn) navBtn.classList.toggle('answered', answeredSet.has(index));
    updateCounts();

    fetch('save_answer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ session_id: SESSION_ID, question_id: questionId, answer: answer })
    }).catch(err => console.error('Save failed:', err));
}

function saveMultiAnswer(questionId, index) {
    const checkboxes = document.querySelectorAll(`input[name="answer_${questionId}[]"]:checked`);
    const values = Array.from(checkboxes).map(cb => cb.value);
    saveAnswer(questionId, JSON.stringify(values), index);
}

// ===== SUBMIT EXAM =====
function confirmSubmit() {
    updateCounts();
    new bootstrap.Modal(document.getElementById('submitModal')).show();
}

function submitExam() {
    fetch('submit_exam.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ session_id: SESSION_ID, exam_id: EXAM_ID })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
            window.location.href = 'exams.php?tab=completed&msg=submitted';
        } else {
            alert(data.message || 'Submission failed. Please try again.');
        }
    })
    .catch(() => alert('Network error. Please check your connection and try again.'));
}

function autoSubmit() {
    alert('Time is up! Your exam will be submitted automatically.');
    submitExam();
}

// ===== FULLSCREEN ENFORCEMENT =====
function enterFullscreen() {
    const el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen();
    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    else if (el.msRequestFullscreen) el.msRequestFullscreen();
    document.body.classList.add('exam-mode');
}

// Request fullscreen on first interaction
document.addEventListener('click', function initFS() {
    enterFullscreen();
    document.removeEventListener('click', initFS);
}, { once: true });

// ===== TAB-SWITCHING PREVENTION =====
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        violationCount++;
        logMonitoring('tab_change', { count: violationCount, timestamp: new Date().toISOString() });
        showViolation('You switched away from the exam tab. This has been recorded as a violation.');
    } else {
        logMonitoring('window_focus', { returned: new Date().toISOString() });
    }
});

window.addEventListener('blur', function() {
    logMonitoring('window_blur', { timestamp: new Date().toISOString() });
});

document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement) {
        violationCount++;
        logMonitoring('fullscreen_exit', { count: violationCount });
        showViolation('You exited fullscreen mode. Please return to fullscreen.');
        setTimeout(enterFullscreen, 2000);
    }
});

// Prevent copy / right-click
document.addEventListener('copy', function(e) {
    e.preventDefault();
    logMonitoring('copy_attempt', {});
});

document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    logMonitoring('right_click', {});
});

// Prevent keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Block Ctrl+C, Ctrl+V, Ctrl+A, Ctrl+P, Alt+Tab (can only log), F12, Ctrl+Shift+I
    if ((e.ctrlKey && ['c','v','a','p','u'].includes(e.key.toLowerCase())) || 
        e.key === 'F12' ||
        (e.ctrlKey && e.shiftKey && e.key === 'I')) {
        e.preventDefault();
        logMonitoring('violation', { key: e.key, ctrl: e.ctrlKey, shift: e.shiftKey });
    }
});

function showViolation(msg) {
    document.getElementById('violationMessage').textContent = msg;
    document.getElementById('violationOverlay').style.display = 'flex';
}

function dismissViolation() {
    document.getElementById('violationOverlay').style.display = 'none';
    enterFullscreen();
}

// ===== MONITORING LOG =====
function logMonitoring(eventType, eventData) {
    fetch('log_monitoring.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ session_id: SESSION_ID, event_type: eventType, event_data: eventData })
    }).catch(err => console.error('Log failed:', err));
}

// ===== CAMERA INVIGILATION (Always Active) =====
let cameraReady = false;
let preCheckStream = null;

// Pre-check: Request camera access before exam loads
async function requestCameraPreCheck() {
    const statusEl = document.getElementById('cameraPermissionStatus');
    const deniedBlock = document.getElementById('cameraDeniedBlock');
    const proceedBtn = document.getElementById('proceedToExamBtn');
    const previewVideo = document.getElementById('previewVideo');
    const previewPlaceholder = document.getElementById('previewPlaceholder');
    
    try {
        preCheckStream = await navigator.mediaDevices.getUserMedia({ 
            video: { width: 320, height: 240, facingMode: 'user' }, 
            audio: false 
        });
        
        // Show preview
        previewVideo.srcObject = preCheckStream;
        previewVideo.style.display = 'block';
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
        
        // Update status
        statusEl.innerHTML = '<i class="bi bi-check-circle-fill text-success me-2"></i><span class="text-success">Camera active - You are being recorded</span>';
        proceedBtn.disabled = false;
        deniedBlock.style.display = 'none';
        cameraReady = true;
        
        // Log camera granted
        logMonitoring('camera_snapshot', { type: 'camera_granted', timestamp: new Date().toISOString() });
        
    } catch(err) {
        console.error('Camera pre-check error:', err);
        statusEl.innerHTML = '<i class="bi bi-x-circle-fill text-danger me-2"></i><span class="text-danger">Camera access denied</span>';
        deniedBlock.style.display = 'block';
        proceedBtn.disabled = true;
        cameraReady = false;
        
        logMonitoring('violation', { type: 'camera_denied', error: err.message });
    }
}

function retryCameraAccess() {
    // Stop any existing stream
    if (preCheckStream) {
        preCheckStream.getTracks().forEach(t => t.stop());
        preCheckStream = null;
    }
    document.getElementById('cameraPermissionStatus').innerHTML = 
        '<div class="spinner-border spinner-border-sm text-info me-2" role="status"></div><span class="text-info">Retrying camera access...</span>';
    document.getElementById('cameraDeniedBlock').style.display = 'none';
    requestCameraPreCheck();
}

function proceedToExam() {
    if (!cameraReady) return;
    
    // Hide pre-check overlay
    document.getElementById('cameraPrecheckOverlay').style.display = 'none';
    
    // Transfer stream to exam camera feed
    const cameraFeed = document.getElementById('cameraFeed');
    const examVideo = document.getElementById('cameraVideo');
    
    if (preCheckStream) {
        cameraStream = preCheckStream;
        examVideo.srcObject = cameraStream;
        cameraFeed.style.display = 'block';
        
        if (document.getElementById('cameraStatus')) {
            document.getElementById('cameraStatus').textContent = 'Camera active';
            document.getElementById('cameraStatus').className = 'mt-1 text-success';
        }
        
        // Start periodic snapshots
        captureSnapshot();
        setInterval(captureSnapshot, SNAPSHOT_INTERVAL);
        
        // Monitor camera stream - if it stops, show violation
        cameraStream.getVideoTracks()[0].onended = function() {
            logMonitoring('violation', { type: 'camera_stopped', timestamp: new Date().toISOString() });
            showViolation('Your camera has been disconnected! This is a serious violation. Please reconnect your camera immediately.');
            if (document.getElementById('cameraStatus')) {
                document.getElementById('cameraStatus').textContent = 'Camera LOST!';
                document.getElementById('cameraStatus').className = 'mt-1 text-danger';
            }
        };
    }
    
    // Request fullscreen
    enterFullscreen();
}

function captureSnapshot() {
    if (!cameraStream) return;
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('snapshotCanvas');
    canvas.width = 320;
    canvas.height = 240;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, 320, 240);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
    
    fetch('upload_snapshot.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ session_id: SESSION_ID, image: dataUrl })
    }).catch(err => console.error('Snapshot upload failed:', err));
}

// Start camera pre-check immediately
requestCameraPreCheck();

// ===== PERIODIC SESSION UPDATE =====
setInterval(function() {
    fetch('update_session.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ session_id: SESSION_ID, time_remaining: timerSeconds })
    }).catch(err => console.error('Session update failed:', err));
}, 30000); // Every 30 seconds
</script>
</body>
</html>
