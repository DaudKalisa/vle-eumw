<?php
/**
 * Advanced Plagiarism & AI Content Check API
 * Checks a student submission using the IntegrityCheckEngine with:
 *   - Winnowing fingerprinting + multi-level n-gram (3/4/5/8-gram)
 *   - Cross-database plagiarism (all assignments + dissertations)
 *   - Sentence-level matching + containment analysis
 *   - 14-metric AI detection (perplexity, burstiness, entropy, Zipf, hapax, etc.)
 * Returns JSON with plagiarism_score, ai_score (0-100), and detailed breakdown.
 */
require_once '../includes/auth.php';
require_once '../includes/integrity_check.php';
requireLogin();
requireRole(['lecturer', 'admin']);

header('Content-Type: application/json');

$conn = getDbConnection();
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;

if (!$submission_id) {
    echo json_encode(['success' => false, 'error' => 'Missing submission_id']);
    exit;
}

// Verify lecturer access
$user = getCurrentUser();
$stmt = $conn->prepare("
    SELECT vs.*, va.assignment_id as a_id, va.course_id, vc.lecturer_id
    FROM vle_submissions vs
    JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
    JOIN vle_courses vc ON va.course_id = vc.course_id
    WHERE vs.submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    echo json_encode(['success' => false, 'error' => 'Submission not found']);
    exit;
}

// Ensure columns exist
$conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS plagiarism_score DECIMAL(5,2) DEFAULT NULL");
$conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS ai_score DECIMAL(5,2) DEFAULT NULL");
$conn->query("ALTER TABLE vle_submissions ADD COLUMN IF NOT EXISTS check_date DATETIME DEFAULT NULL");

// ─── EXECUTE ADVANCED CHECKS ───
$engine = new IntegrityCheckEngine($conn);
$text = $engine->extractSubmissionText($submission);

if (strlen($text) < 30) {
    echo json_encode([
        'success' => false,
        'error' => 'Submission has insufficient text content to analyze. Only file-based (PDF, DOCX, TXT) and text submissions can be checked.'
    ]);
    exit;
}

$result = $engine->checkSubmission($text, $submission_id, [
    'type' => 'assignment',
    'assignment_id' => (int)$submission['a_id'],
    'student_id' => $submission['student_id'],
]);

$plagiarism_score = $result['plagiarism']['score'];
$ai_score = $result['ai']['score'];

// Save results
$save = $conn->prepare("UPDATE vle_submissions SET plagiarism_score = ?, ai_score = ?, check_date = NOW() WHERE submission_id = ?");
$save->bind_param("ddi", $plagiarism_score, $ai_score, $submission_id);
$save->execute();

echo json_encode([
    'success' => true,
    'plagiarism_score' => $plagiarism_score,
    'ai_score' => $ai_score,
    'word_count' => $result['plagiarism']['word_count'] ?? str_word_count($text),
    'check_date' => date('M j, Y H:i'),
    'plagiarism_details' => [
        'method' => $result['plagiarism']['details']['method'] ?? '',
        'corpus_size' => $result['plagiarism']['details']['corpus_size'] ?? 0,
        'matched_sentences' => $result['plagiarism']['matched_sentences'] ?? 0,
        'total_sentences' => $result['plagiarism']['total_sentences'] ?? 0,
        'flagged_words' => $result['plagiarism']['flagged_words'] ?? 0,
        'top_matches' => array_slice($result['plagiarism']['matches'] ?? [], 0, 5),
    ],
    'ai_details' => [
        'method' => $result['ai']['details']['method'] ?? '',
        'confidence' => $result['ai']['confidence'] ?? 'low',
        'metrics_evaluated' => $result['ai']['details']['metrics_evaluated'] ?? 0,
        'high_signals' => $result['ai']['details']['high_signals'] ?? 0,
        'indicators' => array_map(fn($ind) => [
            'label' => $ind['label'],
            'score' => $ind['score'],
        ], $result['ai']['indicators'] ?? []),
    ],
]);
