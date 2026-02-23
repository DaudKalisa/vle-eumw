<?php
// examination_manager/get_session_details.php - Get detailed session information
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();

$sessionId = (int)$_GET['session_id'];

if (!$sessionId) {
    http_response_code(400);
    echo 'Invalid session ID';
    exit;
}

// Get session information
$query = "
    SELECT
        es.*,
        e.title as exam_title,
        e.duration_minutes,
        s.first_name,
        s.last_name,
        s.student_number,
        s.email
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN students s ON es.student_id = s.student_id
    WHERE es.session_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    echo '<div class="alert alert-danger">Session not found</div>';
    exit;
}

// Get monitoring events for this session
$eventsQuery = "
    SELECT * FROM exam_monitoring
    WHERE session_id = ?
    ORDER BY timestamp ASC
";

$eventsStmt = $conn->prepare($eventsQuery);
$eventsStmt->bind_param("i", $sessionId);
$eventsStmt->execute();
$events = $eventsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get student answers
$answersQuery = "
    SELECT
        ea.*,
        eq.question_text,
        eq.question_type,
        eq.correct_answer
    FROM exam_answers ea
    JOIN exam_questions eq ON ea.question_id = eq.question_id
    WHERE ea.session_id = ?
    ORDER BY ea.answered_at ASC
";

$answersStmt = $conn->prepare($answersQuery);
$answersStmt->bind_param("i", $sessionId);
$answersStmt->execute();
$answers = $answersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="row">
    <div class="col-md-6">
        <h6>Session Information</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Student:</strong></td>
                <td><?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?> (<?php echo htmlspecialchars($session['student_number']); ?>)</td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td><?php echo htmlspecialchars($session['email']); ?></td>
            </tr>
            <tr>
                <td><strong>Exam:</strong></td>
                <td><?php echo htmlspecialchars($session['exam_title']); ?></td>
            </tr>
            <tr>
                <td><strong>Duration:</strong></td>
                <td><?php echo $session['duration_minutes']; ?> minutes</td>
            </tr>
            <tr>
                <td><strong>Started:</strong></td>
                <td><?php echo $session['started_at'] ? date('Y-m-d H:i:s', strtotime($session['started_at'])) : 'Not started'; ?></td>
            </tr>
            <tr>
                <td><strong>Last Activity:</strong></td>
                <td><?php echo $session['last_activity'] ? date('Y-m-d H:i:s', strtotime($session['last_activity'])) : 'N/A'; ?></td>
            </tr>
            <tr>
                <td><strong>Status:</strong></td>
                <td>
                    <span class="badge <?php echo $session['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $session['is_active'] ? 'Active' : 'Completed'; ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="col-md-6">
        <h6>Monitoring Events (<?php echo count($events); ?>)</h6>
        <div style="max-height: 300px; overflow-y: auto;">
            <?php if (empty($events)): ?>
                <p class="text-muted">No monitoring events recorded</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($events as $event): ?>
                    <li class="list-group-item py-1">
                        <small>
                            <strong><?php echo date('H:i:s', strtotime($event['timestamp'])); ?>:</strong>
                            <?php echo htmlspecialchars($event['event_type']); ?>
                            <?php if ($event['event_data']): ?>
                                <br><code><?php echo htmlspecialchars($event['event_data']); ?></code>
                            <?php endif; ?>
                        </small>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <h6>Student Answers (<?php echo count($answers); ?>)</h6>
        <div style="max-height: 300px; overflow-y: auto;">
            <?php if (empty($answers)): ?>
                <p class="text-muted">No answers submitted yet</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Answer</th>
                                <th>Correct</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($answers as $answer): ?>
                            <tr>
                                <td>
                                    <small><?php echo htmlspecialchars(substr($answer['question_text'], 0, 50)) . '...'; ?></small>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars($answer['answer_text']); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $isCorrect = false;
                                    if ($answer['question_type'] === 'multiple_choice') {
                                        $isCorrect = $answer['answer_text'] === $answer['correct_answer'];
                                    }
                                    ?>
                                    <span class="badge <?php echo $isCorrect ? 'bg-success' : 'bg-warning'; ?>">
                                        <?php echo $isCorrect ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                                <td>
                                    <small><?php echo date('H:i:s', strtotime($answer['answered_at'])); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>