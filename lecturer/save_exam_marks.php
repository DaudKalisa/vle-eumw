<?php
/**
 * Save Exam Marks - AJAX Endpoint
 * Receives marks from the lecturer marking interface and updates exam_answers + exam_results
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

header('Content-Type: application/json');

$conn = getDbConnection();
$user = getCurrentUser();
$lecturer_id = $user['related_lecturer_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

$exam_id = (int)($input['exam_id'] ?? 0);
$session_id = (int)($input['session_id'] ?? 0);
$student_id = $input['student_id'] ?? '';
$result_id = (int)($input['result_id'] ?? 0);
$marks = $input['marks'] ?? [];

if (!$exam_id || !$session_id || empty($student_id) || empty($marks)) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

// Verify this exam belongs to a course this lecturer teaches
$stmt = $conn->prepare("
    SELECT e.exam_id, e.total_marks, e.passing_marks 
    FROM exams e 
    JOIN vle_courses c ON e.course_id = c.course_id 
    WHERE e.exam_id = ? AND c.lecturer_id = ?
");
$stmt->bind_param("ii", $exam_id, $lecturer_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();

if (!$exam) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: exam does not belong to your course']);
    exit();
}

// Verify session belongs to this exam
$stmt = $conn->prepare("SELECT session_id FROM exam_sessions WHERE session_id = ? AND exam_id = ? AND status = 'completed'");
$stmt->bind_param("ii", $session_id, $exam_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Invalid or incomplete exam session']);
    exit();
}

// Get all questions to validate marks
$questions = [];
$result = $conn->query("SELECT question_id, marks, question_type FROM exam_questions WHERE exam_id = $exam_id");
if ($result) while ($row = $result->fetch_assoc()) {
    $questions[$row['question_id']] = $row;
}

$conn->begin_transaction();
try {
    $total_obtained = 0;
    
    // Update each answer's marks
    foreach ($marks as $question_id => $awarded_marks) {
        $question_id = (int)$question_id;
        $awarded_marks = (float)$awarded_marks;
        
        if (!isset($questions[$question_id])) continue;
        
        // Clamp marks to valid range
        $max_marks = (float)$questions[$question_id]['marks'];
        $awarded_marks = max(0, min($awarded_marks, $max_marks));
        $total_obtained += $awarded_marks;
        
        // Determine is_correct: full marks = 1, zero = 0, partial = 1
        $is_correct = $awarded_marks > 0 ? 1 : 0;
        
        // Update or insert the answer record
        $check = $conn->prepare("SELECT answer_id FROM exam_answers WHERE session_id = ? AND question_id = ?");
        $check->bind_param("ii", $session_id, $question_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE exam_answers SET marks_obtained = ?, is_correct = ? WHERE session_id = ? AND question_id = ?");
            $stmt->bind_param("diii", $awarded_marks, $is_correct, $session_id, $question_id);
            $stmt->execute();
        }
    }
    
    // Calculate final results
    $total_marks = (float)$exam['total_marks'];
    $passing_marks = (float)$exam['passing_marks'];
    $percentage = $total_marks > 0 ? round(($total_obtained / $total_marks) * 100, 2) : 0;
    $is_passed = $total_obtained >= $passing_marks ? 1 : 0;
    $grade = getGradeLetter($percentage);
    $status = $is_passed ? 'passed' : 'failed';
    $now = date('Y-m-d H:i:s');
    $user_id = $user['user_id'];
    
    if ($result_id) {
        // Update existing result
        $stmt = $conn->prepare("
            UPDATE exam_results SET 
                marks_obtained = ?, score = ?, percentage = ?, grade = ?, 
                is_passed = ?, status = ?, reviewed_by = ?, reviewed_at = ?
            WHERE result_id = ?
        ");
        // d=marks_obtained, d=score, d=percentage, s=grade, i=is_passed, s=status, i=reviewed_by, s=reviewed_at, i=result_id
        $stmt->bind_param("dddsisisi", 
            $total_obtained, $total_obtained, $percentage, $grade,
            $is_passed, $status, $user_id, $now, $result_id
        );
        $stmt->execute();
    } else {
        // Insert new result if none exists
        $stmt = $conn->prepare("
            INSERT INTO exam_results (exam_id, student_id, session_id, total_marks, marks_obtained, score, percentage, grade, is_passed, status, reviewed_by, reviewed_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        // i=exam_id, s=student_id, i=session_id, d=total_marks, d=marks_obtained, d=score, d=percentage, s=grade, i=is_passed, s=status, i=reviewed_by, s=reviewed_at
        $stmt->bind_param("isiddddsisis",
            $exam_id, $student_id, $session_id, $total_marks, $total_obtained, $total_obtained, $percentage, $grade, $is_passed, $status, $user_id, $now
        );
        $stmt->execute();
    }
    
    $conn->commit();
    
    // Find next unmarked session for this exam
    $stmt = $conn->prepare("
        SELECT es.session_id 
        FROM exam_sessions es 
        LEFT JOIN exam_results er ON es.session_id = er.session_id
        WHERE es.exam_id = ? AND es.status = 'completed' AND (er.reviewed_by IS NULL) AND es.session_id != ?
        ORDER BY es.ended_at ASC 
        LIMIT 1
    ");
    $stmt->bind_param("ii", $exam_id, $session_id);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'message' => "Marks saved! Score: $total_obtained/$total_marks ($percentage%) - Grade: $grade - " . ($is_passed ? 'PASSED' : 'FAILED'),
        'total_obtained' => $total_obtained,
        'percentage' => $percentage,
        'grade' => $grade,
        'is_passed' => $is_passed,
        'next_session_id' => $next ? $next['session_id'] : null
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error saving marks: ' . $e->getMessage()]);
}

function getGradeLetter($percentage) {
    if ($percentage >= 85) return 'A+';
    if ($percentage >= 75) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 65) return 'B';
    if ($percentage >= 60) return 'C+';
    if ($percentage >= 55) return 'C';
    if ($percentage >= 50) return 'C-';
    if ($percentage >= 45) return 'D';
    if ($percentage >= 40) return 'E';
    return 'F';
}
