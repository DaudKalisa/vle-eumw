<?php
/**
 * Submit Exam - AJAX Endpoint
 * Processes exam submission, auto-grades MCQ/TF, calculates results
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$session_id = (int)($input['session_id'] ?? 0);
$exam_id = (int)($input['exam_id'] ?? 0);

if (!$session_id || !$exam_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Verify session
$stmt = $conn->prepare("SELECT * FROM exam_sessions WHERE session_id = ? AND student_id = ? AND status = 'in_progress'");
$stmt->bind_param("is", $session_id, $student_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

// Get exam details
$exam = $conn->query("SELECT * FROM exams WHERE exam_id = $exam_id")->fetch_assoc();
if (!$exam) {
    echo json_encode(['success' => false, 'message' => 'Exam not found']);
    exit;
}

$conn->begin_transaction();
try {
    // Get all questions for this exam
    $questions = [];
    $q = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id");
    while ($row = $q->fetch_assoc()) $questions[$row['question_id']] = $row;

    // Get all answers for this session
    $answers = [];
    $a = $conn->query("SELECT * FROM exam_answers WHERE session_id = $session_id");
    while ($row = $a->fetch_assoc()) $answers[$row['question_id']] = $row;

    $total_score = 0;
    $total_possible = 0;

    foreach ($questions as $qid => $question) {
        $total_possible += $question['marks'];
        $answer_text = $answers[$qid]['answer_text'] ?? '';
        $correct_answer = $question['correct_answer'] ?? '';
        $is_correct = 0;
        $marks_obtained = 0;

        switch ($question['question_type']) {
            case 'multiple_choice':
            case 'true_false':
                if (strtolower(trim($answer_text)) === strtolower(trim($correct_answer))) {
                    $is_correct = 1;
                    $marks_obtained = $question['marks'];
                }
                break;

            case 'multiple_answer':
                $student_ans = json_decode($answer_text, true) ?: [];
                $correct_ans = json_decode($correct_answer, true) ?: [];
                sort($student_ans);
                sort($correct_ans);
                if ($student_ans == $correct_ans) {
                    $is_correct = 1;
                    $marks_obtained = $question['marks'];
                } elseif (!empty($student_ans) && !empty($correct_ans)) {
                    // Partial marks
                    $correct_count = count(array_intersect($student_ans, $correct_ans));
                    $wrong_count = count(array_diff($student_ans, $correct_ans));
                    $partial = max(0, ($correct_count - $wrong_count) / count($correct_ans));
                    $marks_obtained = round($partial * $question['marks'], 2);
                }
                break;

            case 'short_answer':
                if (strtolower(trim($answer_text)) === strtolower(trim($correct_answer))) {
                    $is_correct = 1;
                    $marks_obtained = $question['marks'];
                }
                break;

            case 'essay':
                // Essays require manual grading - score 0 for now
                $marks_obtained = 0;
                break;
        }

        $total_score += $marks_obtained;

        // Update answer record
        if (isset($answers[$qid])) {
            $conn->query("UPDATE exam_answers SET is_correct = $is_correct, marks_obtained = $marks_obtained WHERE answer_id = " . $answers[$qid]['answer_id']);
        }
    }

    // Calculate percentage and grade
    $percentage = $total_possible > 0 ? ($total_score / $total_possible) * 100 : 0;
    $is_passed = ($total_score >= $exam['passing_marks']) ? 1 : 0;

    // Determine grade
    if ($percentage >= 70) $grade = 'A';
    elseif ($percentage >= 60) $grade = 'B';
    elseif ($percentage >= 50) $grade = 'C';
    elseif ($percentage >= 40) $grade = 'D';
    else $grade = 'F';

    // Check for essay questions (mark as pending if any)
    $has_essay = false;
    foreach ($questions as $q) {
        if ($q['question_type'] === 'essay') { $has_essay = true; break; }
    }

    // Insert result
    $stmt = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, session_id, score, percentage, is_passed, grade, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("isiddis", $exam_id, $student_id, $session_id, $total_score, $percentage, $is_passed, $grade);
    $stmt->execute();
    $result_id = $conn->insert_id;

    // Update session status
    $conn->query("UPDATE exam_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = $session_id");

    $conn->commit();

    // Don't expose score/grade to student - results only visible after exam officer publishes
    echo json_encode([
        'success' => true,
        'submitted' => true,
        'message' => 'Your exam has been submitted successfully. Results will be available after they are published by the examination office.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Submission error: ' . $e->getMessage()]);
}
