<?php
/**
 * Advanced Pre-Submission Integrity Check API (Student-facing)
 * Accepts a temporary file upload via AJAX, runs advanced plagiarism & AI checks
 * using IntegrityCheckEngine (Winnowing + multi-ngram + 14-metric AI detection).
 * Returns results. Does NOT save the file or results to the database.
 */
require_once '../includes/auth.php';
require_once '../includes/integrity_check.php';
requireLogin();
requireRole(['student']);

header('Content-Type: application/json');

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];
$assignment_id = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;

if (!$assignment_id) {
    echo json_encode(['success' => false, 'error' => 'Missing assignment_id']);
    exit;
}

// Verify student is enrolled in the course for this assignment
$stmt = $conn->prepare("
    SELECT va.assignment_id, va.course_id
    FROM vle_assignments va
    JOIN vle_enrollments ve ON va.course_id = ve.course_id
    WHERE va.assignment_id = ? AND ve.student_id = ?
");
$stmt->bind_param("is", $assignment_id, $student_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ─── TEXT EXTRACTION ───
$engine = new IntegrityCheckEngine($conn);
$text = '';

// Extract from uploaded file
if (!empty($_FILES['check_file']) && $_FILES['check_file']['error'] === UPLOAD_ERR_OK) {
    $tmp_path = $_FILES['check_file']['tmp_name'];
    $original_name = basename($_FILES['check_file']['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    $allowed = ['txt', 'pdf', 'doc', 'docx', 'odt'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Unsupported file type. Only TXT, PDF, DOC, DOCX, ODT can be checked.']);
        exit;
    }

    if ($ext === 'txt') $text .= file_get_contents($tmp_path) . "\n";
    elseif ($ext === 'docx') $text .= $engine->extractDocxText($tmp_path) . "\n";
    elseif ($ext === 'pdf') $text .= $engine->extractPdfText($tmp_path) . "\n";
    elseif ($ext === 'doc') $text .= $engine->extractDocText($tmp_path) . "\n";
    elseif ($ext === 'odt') $text .= $engine->extractOdtText($tmp_path) . "\n";
}

// Also include text_content if provided
if (!empty($_POST['text_content'])) {
    $text .= strip_tags($_POST['text_content']) . "\n";
}

$text = trim($text);

if (strlen($text) < 30) {
    echo json_encode([
        'success' => false,
        'error' => 'Insufficient text content to analyze. Upload a PDF, DOCX, DOC, ODT, or TXT file with enough text.'
    ]);
    exit;
}

// ─── EXECUTE ADVANCED CHECKS ───
$result = $engine->checkSubmission($text, 0, [
    'type' => 'assignment',
    'assignment_id' => $assignment_id,
    'student_id' => $student_id,
]);

$plagiarism_score = $result['plagiarism']['score'];
$ai_score = $result['ai']['score'];

echo json_encode([
    'success' => true,
    'plagiarism_score' => $plagiarism_score,
    'ai_score' => $ai_score,
    'word_count' => $result['plagiarism']['word_count'] ?? str_word_count($text),
    'ai_confidence' => $result['ai']['confidence'] ?? 'low',
    'plagiarism_details' => [
        'corpus_size' => $result['plagiarism']['details']['corpus_size'] ?? 0,
        'matched_sentences' => $result['plagiarism']['matched_sentences'] ?? 0,
        'total_sentences' => $result['plagiarism']['total_sentences'] ?? 0,
    ],
    'ai_details' => [
        'metrics_evaluated' => $result['ai']['details']['metrics_evaluated'] ?? 0,
        'indicators' => array_map(fn($ind) => [
            'label' => $ind['label'],
            'score' => $ind['score'],
        ], $result['ai']['indicators'] ?? []),
    ],
]);
