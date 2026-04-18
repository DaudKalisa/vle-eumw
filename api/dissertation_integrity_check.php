<?php
/**
 * Advanced Dissertation Integrity Check API
 * Runs advanced plagiarism (Winnowing + multi-ngram + sentence matching) and
 * AI-content detection (14-metric weighted analysis) on a dissertation submission
 * using IntegrityCheckEngine.
 *
 * Access: Supervisor / Co-supervisor / Admin
 * Method: POST
 * Params: submission_id (int)
 * Returns: JSON
 */

// Buffer all output so PHP warnings/notices cannot corrupt the JSON body
ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ERROR);

try {

require_once '../includes/auth.php';
require_once '../includes/integrity_check.php';
requireLogin();
requireRole(['lecturer', 'staff', 'admin']);

header('Content-Type: application/json');

$conn = getDbConnection();
$user = getCurrentUser();

// Only lecturers (supervisors) and admins
$user_id  = (int)($user['user_id'] ?? 0);
$role     = $user['role'] ?? '';

// Resolve lecturer_id for access control
$lecturer_id = 0;
if ($role !== 'admin') {
    $lecturer_id = (int)($user['related_lecturer_id'] ?? 0);
    if (!$lecturer_id) {
        echo json_encode(['success' => false, 'error' => 'Supervisor profile not found.']);
        exit;
    }
}

$submission_id = (int)($_POST['submission_id'] ?? 0);
if (!$submission_id) {
    echo json_encode(['success' => false, 'error' => 'Missing submission_id.']);
    exit;
}

// Load submission + dissertation (enforce supervisor ownership)
if ($role === 'admin') {
    $stmt = $conn->prepare("
        SELECT ds.*, d.dissertation_id, d.title as diss_title, d.student_id,
               d.supervisor_id, d.co_supervisor_id
        FROM dissertation_submissions ds
        JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
        WHERE ds.submission_id = ?
    ");
    $stmt->bind_param("i", $submission_id);
} else {
    $stmt = $conn->prepare("
        SELECT ds.*, d.dissertation_id, d.title as diss_title, d.student_id,
               d.supervisor_id, d.co_supervisor_id
        FROM dissertation_submissions ds
        JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
        WHERE ds.submission_id = ?
          AND (d.supervisor_id = ? OR d.co_supervisor_id = ?)
    ");
    $stmt->bind_param("iii", $submission_id, $lecturer_id, $lecturer_id);
}
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();

if (!$sub) {
    echo json_encode(['success' => false, 'error' => 'Submission not found or you are not the assigned supervisor.']);
    exit;
}

// ─── TEXT EXTRACTION via Engine ──────────────────────────────────────────────
$engine = new IntegrityCheckEngine($conn);
$text = $engine->extractDissertationText($sub);

if (strlen($text) < 30) {
    echo json_encode([
        'success' => false,
        'error'   => 'The submission has insufficient readable text (minimum 30 characters). '
                   . 'Only DOCX, ODT, TXT, RTF, DOC and PDF files are analysed.'
    ]);
    exit;
}

// ─── EXECUTE ADVANCED CHECKS ────────────────────────────────────────────────
$result = $engine->checkSubmission($text, $submission_id, [
    'type' => 'dissertation',
    'dissertation_id' => (int)$sub['dissertation_id'],
]);

$similarity_score = $result['plagiarism']['score'];
$ai_score         = $result['ai']['score'];
$word_count       = $result['plagiarism']['word_count'] ?? str_word_count($text);

// Build cross_matches from engine top_matches
$cross_matches = $result['plagiarism']['details']['top_matches'] ?? [];

$sim_details = json_encode([
    'method'            => $result['plagiarism']['details']['method'] ?? 'winnowing + multi-ngram + sentence',
    'corpus_size'       => $result['plagiarism']['details']['corpus_size'] ?? 0,
    'matched_sentences' => $result['plagiarism']['matched_sentences'] ?? 0,
    'total_sentences'   => $result['plagiarism']['total_sentences'] ?? 0,
    'word_count'        => $word_count,
]);
$ai_details = json_encode([
    'confidence'        => $result['ai']['confidence'] ?? 'low',
    'metrics_evaluated' => $result['ai']['details']['metrics_evaluated'] ?? 0,
    'high_signals'      => $result['ai']['details']['high_signals'] ?? [],
    'indicators'        => $result['ai']['indicators'] ?? [],
]);
$cross_json = json_encode($cross_matches);
$flagged    = (int)($word_count * ($similarity_score / 100));
$phase      = $sub['phase'] ?? '';
$diss_id    = (int)$sub['dissertation_id'];

// Insert integrity check record
$ins = $conn->prepare("
    INSERT INTO dissertation_similarity_checks
        (dissertation_id, submission_id, phase, similarity_score, ai_detection_score,
         similarity_details, ai_detection_details, cross_student_matches,
         total_words_checked, flagged_words, status, checked_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
");
$ins->bind_param("iisddsssis",
    $diss_id, $submission_id, $phase,
    $similarity_score, $ai_score,
    $sim_details, $ai_details, $cross_json,
    $word_count, $flagged
);

if (!$ins->execute()) {
    echo json_encode(['success' => false, 'error' => 'Failed to save check results: ' . $conn->error]);
    exit;
}

$check_id = $conn->insert_id;

// Link check to submission
$upd = $conn->prepare("UPDATE dissertation_submissions SET similarity_check_id = ? WHERE submission_id = ?");
$upd->bind_param("ii", $check_id, $submission_id);
$upd->execute();

// Determine badge classes
$sim_badge = $similarity_score >= 25 ? 'bg-danger' : ($similarity_score >= 15 ? 'bg-warning text-dark' : 'bg-success');
$ai_badge  = $ai_score >= 40 ? 'bg-danger' : ($ai_score >= 20 ? 'bg-warning text-dark' : 'bg-success');
$sim_ring  = $similarity_score >= 25 ? '#fecaca' : ($similarity_score >= 15 ? '#fef3c7' : '#d1fae5');
$ai_ring   = $ai_score >= 40 ? '#fecaca' : ($ai_score >= 20 ? '#fef3c7' : '#d1fae5');

ob_end_clean();
echo json_encode([
    'success'          => true,
    'check_id'         => $check_id,
    'similarity_score' => $similarity_score,
    'ai_score'         => $ai_score,
    'ai_confidence'    => $result['ai']['confidence'] ?? 'low',
    'word_count'       => $word_count,
    'flagged_words'    => $flagged,
    'cross_matches'    => $cross_matches,
    'checked_at'       => date('M j, Y H:i'),
    'sim_badge'        => $sim_badge,
    'ai_badge'         => $ai_badge,
    'sim_ring'         => $sim_ring,
    'ai_ring'          => $ai_ring,
], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
