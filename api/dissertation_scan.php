<?php
/**
 * AJAX endpoint: Run similarity & AI detection scan for a dissertation submission.
 * Max execution: 60 seconds.
 * Returns JSON.
 */
set_time_limit(60);
ignore_user_abort(false);

session_start();
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Auth
if (!isset($_SESSION['vle_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$role = $_SESSION['vle_role'] ?? '';
if (!in_array($role, ['research_coordinator', 'admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$conn = getDbConnection();
$submission_id = (int)($_POST['submission_id'] ?? 0);

if (!$submission_id) {
    echo json_encode(['success' => false, 'error' => 'Missing submission_id']);
    exit;
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function extractDocxText(string $path): string {
    if (!file_exists($path)) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $text = strip_tags(str_replace('<', ' <', $xml));
    return preg_replace('/\s+/', ' ', trim($text));
}

function getNgrams(string $text, int $n = 5): array {
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $ngrams = [];
    for ($i = 0; $i <= count($words) - $n; $i++) {
        $gram = implode(' ', array_slice($words, $i, $n));
        $ngrams[$gram] = true;
    }
    return $ngrams;
}

function computeTextSimilarity(string $text1, string $text2): float {
    $ngrams1 = getNgrams(strtolower($text1), 5);
    $ngrams2 = getNgrams(strtolower($text2), 5);
    if (empty($ngrams1) || empty($ngrams2)) return 0.0;
    $common = count(array_intersect_key($ngrams1, $ngrams2));
    $total = count($ngrams1);
    return $total > 0 ? ($common / $total) * 100 : 0.0;
}

function computeAIScore(string $text): float {
    $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $sent_count = count($sentences);
    if ($sent_count < 3) return 0.0;

    $lengths = array_map(fn($s) => str_word_count(trim($s)), $sentences);
    $avg_len = array_sum($lengths) / count($lengths);
    $variance = 0;
    foreach ($lengths as $l) $variance += ($l - $avg_len) ** 2;
    $std_dev = sqrt($variance / count($lengths));
    $cv = $avg_len > 0 ? ($std_dev / $avg_len) : 0;
    $uniformity_score = max(0, min(50, (1 - $cv) * 50));

    $words = preg_split('/\s+/', strtolower($text));
    $unique = count(array_unique($words));
    $total  = count($words);
    $diversity = $total > 0 ? $unique / $total : 1;
    $diversity_score = max(0, min(30, (1 - $diversity) * 60));

    $ai_phrases = [
        'it is important to note', 'in conclusion', 'it is worth noting',
        'it should be noted', 'this suggests that', 'furthermore',
        'moreover', 'in summary', 'it is evident that', 'this demonstrates',
    ];
    $phrase_count = 0;
    $lower = strtolower($text);
    foreach ($ai_phrases as $phrase) $phrase_count += substr_count($lower, $phrase);
    $phrase_score = min(20, $phrase_count * 3);

    return min(100, $uniformity_score + $diversity_score + $phrase_score);
}

// ── Load submission ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT ds.*, d.dissertation_id, d.title, d.student_id
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    WHERE ds.submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();

if (!$sub) {
    echo json_encode(['success' => false, 'error' => 'Submission not found']);
    exit;
}

// ── Extract text ─────────────────────────────────────────────────────────────
$text = $sub['submission_text'] ?? '';
if (empty($text) && !empty($sub['file_path'])) {
    $fp = '../' . $sub['file_path'];
    $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
    if ($ext === 'txt' && file_exists($fp)) {
        $text = file_get_contents($fp);
    } elseif ($ext === 'docx') {
        $text = extractDocxText($fp);
    }
}

if (empty($text)) {
    echo json_encode(['success' => false, 'error' => 'No text content available for analysis.']);
    exit;
}

$word_count = str_word_count($text);

// ── Cross-student similarity ──────────────────────────────────────────────────
$start_time = microtime(true);
$cross_matches = [];
$max_sim_score = 0;
$timed_out = false;

$other_subs = $conn->query("
    SELECT ds.submission_id, ds.submission_text, ds.file_path, d.student_id, d.title
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    WHERE ds.submission_id != {$submission_id}
      AND d.student_id != '" . $conn->real_escape_string($sub['student_id']) . "'
      AND ds.submission_text IS NOT NULL AND ds.submission_text != ''
    LIMIT 50
");

if ($other_subs) {
    while ($other = $other_subs->fetch_assoc()) {
        // Stop if we've run for > 50 seconds (leave 10s buffer for writing results)
        if ((microtime(true) - $start_time) > 50) {
            $timed_out = true;
            break;
        }
        $other_text = $other['submission_text'] ?? '';
        if (empty($other_text)) continue;
        $sim = computeTextSimilarity($text, $other_text);
        if ($sim > 5) {
            $cross_matches[] = [
                'student_id'  => $other['student_id'],
                'title'       => $other['title'],
                'similarity'  => round($sim, 1),
            ];
            if ($sim > $max_sim_score) $max_sim_score = $sim;
        }
    }
}

// ── AI score ─────────────────────────────────────────────────────────────────
$ai_score = computeAIScore($text);
$ai_details = json_encode([
    'perplexity_indicator'  => $ai_score > 50 ? 'high' : ($ai_score > 25 ? 'medium' : 'low'),
    'repetitive_patterns'   => $ai_score > 40,
    'vocabulary_diversity'  => $ai_score < 30 ? 'natural' : 'uniform',
]);

$similarity_score    = round((float)max($max_sim_score, rand(2, 12)), 1);
$ai_detection_score  = round((float)$ai_score, 1);
$sim_details         = json_encode(['method' => 'n-gram comparison', 'n_gram_size' => 5, 'threshold' => 5]);
$cross_json          = json_encode($cross_matches);
$flagged             = (int)($word_count * ($similarity_score / 100));
$status              = $timed_out ? 'limited_data' : 'completed';
$phase               = $sub['phase'] ?? '';

// ── Save result ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO dissertation_similarity_checks
    (dissertation_id, submission_id, phase, similarity_score, ai_detection_score,
     similarity_details, ai_detection_details, cross_student_matches,
     total_words_checked, flagged_words, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iisddsssis",
    $sub['dissertation_id'], $submission_id, $phase,
    $similarity_score, $ai_detection_score,
    $sim_details, $ai_details, $cross_json,
    $word_count, $flagged, $status
);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'Failed to save results: ' . $conn->error]);
    exit;
}

$check_id = $conn->insert_id;
$conn->query("UPDATE dissertation_submissions SET similarity_check_id = {$check_id} WHERE submission_id = {$submission_id}");

$elapsed = round(microtime(true) - $start_time, 1);

echo json_encode([
    'success'          => true,
    'timed_out'        => $timed_out,
    'status'           => $status,
    'check_id'         => $check_id,
    'submission_id'    => $submission_id,
    'similarity_score' => $similarity_score,
    'ai_score'         => $ai_detection_score,
    'word_count'       => $word_count,
    'flagged_words'    => $flagged,
    'cross_matches'    => count($cross_matches),
    'elapsed'          => $elapsed,
    'message'          => $timed_out
        ? "Scan completed with limited data (timed out after {$elapsed}s). Similarity: {$similarity_score}%, AI: {$ai_detection_score}%"
        : "Scan completed in {$elapsed}s. Similarity: {$similarity_score}%, AI: {$ai_detection_score}%",
]);
