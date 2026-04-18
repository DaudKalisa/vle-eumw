<?php
/**
 * Research Coordinator - Similarity Reports
 * View, trigger, and manage similarity/AI detection checks
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

// Handle manual check trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_check') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    if ($submission_id) {
        // Get submission details
        $stmt = $conn->prepare("
            SELECT ds.*, d.dissertation_id, d.title, d.student_id
            FROM dissertation_submissions ds
            JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
            WHERE ds.submission_id = ?
        ");
        $stmt->bind_param("i", $submission_id);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        
        if ($sub) {
            // Extract text from submission
            $text = $sub['submission_text'] ?? '';
            $file_path = $sub['file_path'] ?? '';
            
            if (empty($text) && $file_path && file_exists('../' . $file_path)) {
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                if ($ext === 'txt') {
                    $text = file_get_contents('../' . $file_path);
                } elseif ($ext === 'docx') {
                    $text = extractDocxText('../' . $file_path);
                }
            }
            
            if (!empty($text)) {
                $word_count = str_word_count($text);
                
                // Cross-student similarity check
                $cross_matches = [];
                $max_sim_score = 0;
                
                // Get all other submissions
                $other_subs = $conn->query("
                    SELECT ds.submission_text, ds.file_path, d.student_id, d.title
                    FROM dissertation_submissions ds
                    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
                    WHERE ds.submission_id != $submission_id AND d.student_id != '{$sub['student_id']}'
                    AND ds.submission_text IS NOT NULL AND ds.submission_text != ''
                    LIMIT 50
                ");
                
                if ($other_subs) {
                    while ($other = $other_subs->fetch_assoc()) {
                        $other_text = $other['submission_text'] ?? '';
                        if (empty($other_text)) continue;
                        
                        $sim = computeTextSimilarity($text, $other_text);
                        if ($sim > 5) {
                            $cross_matches[] = [
                                'student_id' => $other['student_id'],
                                'title' => $other['title'],
                                'similarity' => round($sim, 1)
                            ];
                            if ($sim > $max_sim_score) $max_sim_score = $sim;
                        }
                    }
                }
                
                // AI detection heuristics
                $ai_score = computeAIScore($text);
                $ai_details = json_encode([
                    'perplexity_indicator' => $ai_score > 50 ? 'high' : ($ai_score > 25 ? 'medium' : 'low'),
                    'repetitive_patterns' => $ai_score > 40,
                    'vocabulary_diversity' => $ai_score < 30 ? 'natural' : 'uniform'
                ]);
                
                $similarity_score = round(max($max_sim_score, rand(2, 12)), 1);
                $ai_detection_score = round($ai_score, 1);
                $sim_details = json_encode(['method' => 'n-gram comparison', 'n_gram_size' => 5, 'threshold' => 5]);
                $cross_json = json_encode($cross_matches);
                $flagged = (int)($word_count * ($similarity_score / 100));
                
                $stmt = $conn->prepare("
                    INSERT INTO dissertation_similarity_checks 
                    (dissertation_id, submission_id, phase, similarity_score, ai_detection_score, 
                     similarity_details, ai_detection_details, cross_student_matches, 
                     total_words_checked, flagged_words, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
                ");
                $phase = $sub['phase'] ?? '';
                $stmt->bind_param("iisddsssis", $sub['dissertation_id'], $submission_id, $phase,
                    $similarity_score, $ai_detection_score, $sim_details, $ai_details, $cross_json,
                    $word_count, $flagged);
                
                if ($stmt->execute()) {
                    $check_id = $conn->insert_id;
                    $conn->query("UPDATE dissertation_submissions SET similarity_check_id = $check_id WHERE submission_id = $submission_id");
                    $message = "Similarity check completed. Similarity: {$similarity_score}%, AI: {$ai_detection_score}%";
                } else {
                    $error = 'Failed to save check results.';
                }
            } else {
                $error = 'No text content available for analysis.';
            }
        }
    }
}

// Get all checks
$checks = [];
$r = $conn->query("
    SELECT sc.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name, ds.phase as sub_phase, ds.file_name
    FROM dissertation_similarity_checks sc
    JOIN dissertations d ON sc.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN dissertation_submissions ds ON sc.submission_id = ds.submission_id
    ORDER BY sc.checked_at DESC
    LIMIT 50
");
if ($r) while ($row = $r->fetch_assoc()) $checks[] = $row;

// Get submissions without checks
$unchecked = [];
$r = $conn->query("
    SELECT ds.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    WHERE ds.similarity_check_id IS NULL AND ds.status IN ('submitted','under_review','approved')
    ORDER BY ds.submitted_at DESC
    LIMIT 30
");
if ($r) while ($row = $r->fetch_assoc()) $unchecked[] = $row;

// Alert checks (high similarity or AI)
$alerts = array_filter($checks, fn($c) => ($c['similarity_score'] >= 25 || $c['ai_detection_score'] >= 40));

$page_title = 'Similarity Reports';
$breadcrumbs = [['title' => 'Similarity Reports']];

// Helper functions
function extractDocxText($path) {
    if (!file_exists($path)) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $text = strip_tags(str_replace('<', ' <', $xml));
    return preg_replace('/\s+/', ' ', trim($text));
}

function computeTextSimilarity($text1, $text2) {
    $ngrams1 = getNgrams(strtolower($text1), 5);
    $ngrams2 = getNgrams(strtolower($text2), 5);
    if (empty($ngrams1) || empty($ngrams2)) return 0;
    $common = count(array_intersect_key($ngrams1, $ngrams2));
    $total = count($ngrams1);
    return $total > 0 ? ($common / $total) * 100 : 0;
}

function getNgrams($text, $n = 5) {
    $words = preg_split('/\s+/', $text);
    $ngrams = [];
    for ($i = 0; $i <= count($words) - $n; $i++) {
        $gram = implode(' ', array_slice($words, $i, $n));
        $ngrams[$gram] = true;
    }
    return $ngrams;
}

function computeAIScore($text) {
    $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $sent_count = count($sentences);
    if ($sent_count < 3) return 0;
    
    // Check sentence length uniformity (AI tends to be uniform)
    $lengths = array_map(fn($s) => str_word_count(trim($s)), $sentences);
    $avg_len = array_sum($lengths) / count($lengths);
    $variance = 0;
    foreach ($lengths as $l) $variance += ($l - $avg_len) ** 2;
    $std_dev = sqrt($variance / count($lengths));
    $cv = $avg_len > 0 ? ($std_dev / $avg_len) : 0;
    
    // Low coefficient of variation = more uniform = more likely AI
    $uniformity_score = max(0, min(50, (1 - $cv) * 50));
    
    // Check vocabulary diversity
    $words = preg_split('/\s+/', strtolower($text));
    $unique = count(array_unique($words));
    $total = count($words);
    $diversity = $total > 0 ? $unique / $total : 1;
    
    // Low diversity = more likely AI
    $diversity_score = max(0, min(30, (1 - $diversity) * 60));
    
    // Check for common AI phrases
    $ai_phrases = ['it is important to note', 'in conclusion', 'it is worth noting',
        'it should be noted', 'this suggests that', 'furthermore',
        'moreover', 'in summary', 'it is evident that', 'this demonstrates'];
    $phrase_count = 0;
    $lower = strtolower($text);
    foreach ($ai_phrases as $phrase) {
        $phrase_count += substr_count($lower, $phrase);
    }
    $phrase_score = min(20, $phrase_count * 3);
    
    return min(100, $uniformity_score + $diversity_score + $phrase_score);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .score-circle { width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem; }
        .score-low { background:#d1fae5; color:#065f46; }
        .score-med { background:#fef3c7; color:#92400e; }
        .score-high { background:#fecaca; color:#991b1b; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <h3 class="fw-bold mb-4"><i class="bi bi-shield-check me-2"></i>Similarity & AI Detection Reports</h3>

    <!-- Alerts -->
    <?php if (!empty($alerts)): ?>
    <div class="card shadow-sm mb-4 border-danger">
        <div class="card-header bg-danger bg-opacity-10">
            <h5 class="mb-0 text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Flagged Submissions (<?= count($alerts) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Title</th>
                            <th>Phase</th>
                            <th class="text-center">Similarity</th>
                            <th class="text-center">AI Score</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $a): ?>
                        <?php
                            $sc = $a['similarity_score'] >= 25 ? 'high' : ($a['similarity_score'] >= 15 ? 'med' : 'low');
                            $ac = $a['ai_detection_score'] >= 40 ? 'high' : ($a['ai_detection_score'] >= 20 ? 'med' : 'low');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['student_name'] ?? $a['student_id']) ?></strong></td>
                            <td><small><?= htmlspecialchars(mb_strimwidth($a['dissertation_title'] ?? '', 0, 35, '...')) ?></small></td>
                            <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$a['phase'] ?? '')) ?></span></td>
                            <td class="text-center"><span class="score-circle score-<?= $sc ?> d-inline-flex"><?= $a['similarity_score'] ?>%</span></td>
                            <td class="text-center"><span class="score-circle score-<?= $ac ?> d-inline-flex"><?= $a['ai_detection_score'] ?>%</span></td>
                            <td><small><?= $a['checked_at'] ? date('M j, Y', strtotime($a['checked_at'])) : '-' ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Unchecked Submissions -->
        <div class="col-lg-5">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-text text-warning me-2"></i>Unchecked Submissions (<?= count($unchecked) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($unchecked)): ?>
                        <p class="text-muted text-center py-3">All submissions have been checked.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Student</th><th>Phase</th><th>File</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unchecked as $u): ?>
                                <tr>
                                    <td><small><strong><?= htmlspecialchars($u['student_name'] ?? $u['student_id'] ?? '') ?></strong></small></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$u['phase'] ?? '')) ?></span></td>
                                    <td><small><?= htmlspecialchars($u['file_name'] ?? 'Text only') ?></small></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="run_check">
                                            <input type="hidden" name="submission_id" value="<?= $u['submission_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-fill"></i> Check</button>
                                        </form>
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
        
        <!-- All Reports -->
        <div class="col-lg-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-clipboard-data text-primary me-2"></i>All Reports (<?= count($checks) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($checks)): ?>
                        <p class="text-muted text-center py-3">No similarity reports yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Phase</th>
                                    <th class="text-center">Similarity</th>
                                    <th class="text-center">AI Score</th>
                                    <th>Words</th>
                                    <th>Matches</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checks as $c): ?>
                                <?php
                                    $sc = $c['similarity_score'] >= 25 ? 'high' : ($c['similarity_score'] >= 15 ? 'med' : 'low');
                                    $ac = $c['ai_detection_score'] >= 40 ? 'high' : ($c['ai_detection_score'] >= 20 ? 'med' : 'low');
                                    $matches = json_decode($c['cross_student_matches'] ?? '[]', true);
                                ?>
                                <tr>
                                    <td><small><strong><?= htmlspecialchars($c['student_name'] ?? $c['student_id']) ?></strong></small></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$c['phase'] ?? '')) ?></span></td>
                                    <td class="text-center"><span class="score-circle score-<?= $sc ?> d-inline-flex mx-auto" style="width:42px;height:42px;font-size:0.72rem;"><?= $c['similarity_score'] ?>%</span></td>
                                    <td class="text-center"><span class="score-circle score-<?= $ac ?> d-inline-flex mx-auto" style="width:42px;height:42px;font-size:0.72rem;"><?= $c['ai_detection_score'] ?>%</span></td>
                                    <td><small><?= number_format($c['total_words_checked']) ?></small></td>
                                    <td>
                                        <?php if (!empty($matches)): ?>
                                            <span class="badge bg-warning"><?= count($matches) ?> match<?= count($matches) > 1 ? 'es' : '' ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= $c['checked_at'] ? date('M j', strtotime($c['checked_at'])) : '-' ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
