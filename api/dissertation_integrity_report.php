<?php
/**
 * Dissertation Integrity Check Report Generator
 * Generates downloadable reports for plagiarism and AI detection results
 * Available to: supervisors, students, research coordinators
 * Formats: PDF, Excel, HTML, Word
 */
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_id = $_SESSION['vle_user_id'] ?? 0;

$check_id = (int)($_GET['check_id'] ?? 0);
$format = strtolower(trim($_GET['format'] ?? 'html')); // html, pdf, excel, word
$download = ($_GET['download'] ?? '0') === '1';

if (!$check_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing check_id parameter']);
    exit;
}

// Fetch check details with full submission context
$stmt = $conn->prepare("
    SELECT dsc.*,
           ds.dissertation_id, ds.submission_id, ds.phase, ds.status as sub_status, ds.submitted_at,
           d.title as diss_title, d.student_id, d.supervisor_id, d.co_supervisor_id,
           s.full_name as student_name, s.email as student_email,
           l.full_name as supervisor_name,
           COALESCE(dsc.similarity_details, '{}') as sim_details,
           COALESCE(dsc.ai_detection_details, '{}') as ai_details
    FROM dissertation_similarity_checks dsc
    JOIN dissertation_submissions ds ON dsc.submission_id = ds.submission_id
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE dsc.check_id = ?
");
$stmt->bind_param("i", $check_id);
$stmt->execute();
$check = $stmt->get_result()->fetch_assoc();

if (!$check) {
    http_response_code(404);
    echo json_encode(['error' => 'Check not found']);
    exit;
}

// Access control: supervisor, student, or research coordinator
$is_supervisor = ($check['supervisor_id'] == $_SESSION['vle_related_id'] ?? 0) || 
                 ($check['co_supervisor_id'] == $_SESSION['vle_related_id'] ?? 0);
$is_student = ($check['student_id'] === $_SESSION['vle_related_id'] ?? '');
$is_rc = hasRole(['research_coordinator', 'admin']);

if (!($is_supervisor || $is_student || $is_rc)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Parse JSON details
$sim_details = json_decode($check['sim_details'], true) ?? [];
$ai_details = json_decode($check['ai_details'], true) ?? [];

// Generate highlighted problem areas
$problem_areas = generateProblemAreas($check, $sim_details, $ai_details);

// Generate report based on format
if ($format === 'pdf') {
    generatePDFReport($check, $problem_areas, $sim_details, $ai_details, $download);
} elseif ($format === 'excel') {
    generateExcelReport($check, $problem_areas, $sim_details, $ai_details, $download);
} elseif ($format === 'word') {
    generateWordReport($check, $problem_areas, $sim_details, $ai_details);
} else {
    generateHTMLReport($check, $problem_areas, $sim_details, $ai_details);
}

// ============================================================
// Helper Functions
// ============================================================

function generateProblemAreas($check, $sim_details, $ai_details) {
    $problems = [];
    
    // Check similarity score
    $similarity_score = (float)($check['similarity_score'] ?? 0);
    if ($similarity_score > 25) {
        $problems[] = [
            'type' => 'plagiarism_high',
            'title' => 'High Plagiarism Risk',
            'severity' => $similarity_score > 40 ? 'critical' : 'high',
            'percentage' => round($similarity_score, 1),
            'description' => "Similarity score of {$similarity_score}% detected. Content matches with previous submissions.",
            'action' => 'Review matched sections and ensure proper citations. Rephrase similar content or provide proper attribution.',
            'details' => $sim_details
        ];
    } elseif ($similarity_score > 15) {
        $problems[] = [
            'type' => 'plagiarism_medium',
            'title' => 'Moderate Similarity Detected',
            'severity' => 'medium',
            'percentage' => round($similarity_score, 1),
            'description' => "Similarity score of {$similarity_score}% detected. Some sections match existing content.",
            'action' => 'Review flagged sections. Add citations or rewrite to increase originality.',
            'details' => $sim_details
        ];
    }
    
    // Check AI detection score
    $ai_score = (float)($check['ai_score'] ?? 0);
    if ($ai_score > 40) {
        $problems[] = [
            'type' => 'ai_high',
            'title' => 'High AI-Generated Content Probability',
            'severity' => 'critical',
            'percentage' => round($ai_score, 1),
            'description' => "AI generation probability: {$ai_score}%. Multiple indicators suggest AI-generated text.",
            'action' => 'Rewrite sections in your own words. Ensure authentic student voice and original thinking.',
            'confidence' => $ai_details['confidence_level'] ?? 'medium',
            'details' => $ai_details
        ];
    } elseif ($ai_score > 20) {
        $problems[] = [
            'type' => 'ai_medium',
            'title' => 'Moderate AI-Generation Indicators',
            'severity' => 'medium',
            'percentage' => round($ai_score, 1),
            'description' => "AI generation probability: {$ai_score}%. Some sections show AI-like characteristics.",
            'action' => 'Review flagged sections. Personalize content and add your own analysis.',
            'confidence' => $ai_details['confidence_level'] ?? 'medium',
            'details' => $ai_details
        ];
    }
    
    // Check if limited check
    if ($check['status'] === 'limited_data') {
        $problems[] = [
            'type' => 'insufficient_text',
            'title' => 'Limited Analysis Available',
            'severity' => 'warning',
            'word_count' => (int)($check['word_count'] ?? 0),
            'description' => "Document has only {$check['word_count']} words. Full analysis requires minimum 100 words.",
            'action' => 'Expand your submission with more detailed content.',
            'details' => null
        ];
    }
    
    return $problems;
}

function generateHTMLReport($check, $problem_areas, $sim_details, $ai_details) {
    $report_date = date('M j, Y H:i');
    $status_color = [
        'completed' => '#10b981',
        'limited_data' => '#f59e0b',
        'pending' => '#3b82f6',
        'failed' => '#ef4444'
    ];
    $status = $check['status'] ?? 'pending';
    $status_bg = $status_color[$status] ?? '#6b7280';
    
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Dissertation Integrity Report - {$check['diss_title']}</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css' rel='stylesheet'>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f9fafb; }
        .report-container { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .report-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 12px 12px 0 0; }
        .score-badge { font-size: 2rem; font-weight: bold; padding: 1rem; border-radius: 50%; text-align: center; width: 100px; height: 100px; display: flex; align-items: center; justify-content: center; }
        .score-low { background: #dcfce7; color: #166534; }
        .score-medium { background: #fef3c7; color: #92400e; }
        .score-high { background: #fee2e2; color: #991b1b; }
        .problem-card { border-left: 4px solid #ef4444; background: #fef2f2; padding: 1.5rem; margin: 1rem 0; border-radius: 8px; }
        .problem-card.medium { border-left-color: #f59e0b; background: #fffbeb; }
        .problem-card.low { border-left-color: #10b981; background: #f0fdf4; }
        .problem-card.warning { border-left-color: #3b82f6; background: #eff6ff; }
        .metric-row { display: flex; justify-content: space-between; padding: 1rem 0; border-bottom: 1px solid #e5e7eb; }
        .metric-row:last-child { border-bottom: none; }
        .metric-label { color: #6b7280; font-weight: 500; }
        .metric-value { font-weight: bold; color: #1f2937; }
        .print-btn { margin: 1.5rem 0; }
    </style>
</head>
<body>
    <div class='container-lg py-4'>
        <div class='report-container'>
            <!-- Header -->
            <div class='report-header'>
                <div class='row align-items-center'>
                    <div class='col-md-8'>
                        <h1 class='mb-2'><i class='bi bi-file-text me-2'></i>Dissertation Integrity Report</h1>
                        <p class='mb-0 opacity-75'>Plagiarism & AI Detection Analysis</p>
                    </div>
                    <div class='col-md-4 text-end'>
                        <p class='mb-0 small'><strong>Report ID:</strong> {$check['check_id']}</p>
                        <p class='mb-0 small'><strong>Generated:</strong> {$report_date}</p>
                    </div>
                </div>
            </div>
            
            <!-- Submission Info -->
            <div class='p-4'>
                <div class='row mb-4'>
                    <div class='col-md-6'>
                        <h5 class='text-muted mb-3'>Dissertation Details</h5>
                        <div class='metric-row'>
                            <span class='metric-label'><strong>Title:</strong></span>
                            <span class='metric-value'>" . htmlspecialchars($check['diss_title']) . "</span>
                        </div>
                        <div class='metric-row'>
                            <span class='metric-label'><strong>Student:</strong></span>
                            <span class='metric-value'>" . htmlspecialchars($check['student_name']) . "</span>
                        </div>
                        <div class='metric-row'>
                            <span class='metric-label'><strong>Phase:</strong></span>
                            <span class='metric-value'>" . ucfirst(str_replace('_', ' ', $check['phase'])) . "</span>
                        </div>
                        <div class='metric-row'>
                            <span class='metric-label'><strong>Submitted:</strong></span>
                            <span class='metric-value'>" . date('M j, Y', strtotime($check['submitted_at'])) . "</span>
                        </div>
                    </div>
                    <div class='col-md-6'>
                        <h5 class='text-muted mb-3'>Analysis Scores</h5>
                        <div class='row text-center mb-3'>
                            <div class='col-6'>
                                <div class='score-badge " . ($check['similarity_score'] > 25 ? 'score-high' : ($check['similarity_score'] > 15 ? 'score-medium' : 'score-low')) . "'>
                                    " . round($check['similarity_score'], 0) . "%
                                </div>
                                <p class='small text-muted mt-2'>Similarity</p>
                            </div>
                            <div class='col-6'>
                                <div class='score-badge " . ($check['ai_score'] > 40 ? 'score-high' : ($check['ai_score'] > 20 ? 'score-medium' : 'score-low')) . "'>
                                    " . round($check['ai_score'], 0) . "%
                                </div>
                                <p class='small text-muted mt-2'>AI Probability</p>
                            </div>
                        </div>
                        <p class='small text-muted'><strong>Word Count:</strong> " . ($check['word_count'] ?? 'N/A') . "</p>
                        <p class='small'><span class='badge bg-" . ($status === 'completed' ? 'success' : ($status === 'limited_data' ? 'warning' : 'info')) . "'>" . ucfirst(str_replace('_', ' ', $status)) . "</span></p>
                    </div>
                </div>
                
                <!-- Problem Areas Section -->
                <hr class='my-4'>
                <h4 class='mb-4'><i class='bi bi-exclamation-triangle me-2'></i>Areas Requiring Attention</h4>
                
                " . (empty($problem_areas) ? 
                    "<div class='alert alert-success'><i class='bi bi-check-circle me-2'></i>No significant issues detected. Your submission looks good!</div>" 
                    : 
                    implode("\n", array_map(function($p) {
                        $severity_class = $p['severity'] === 'critical' ? 'critical' : ($p['severity'] === 'medium' ? 'medium' : 'low');
                        return "
                        <div class='problem-card " . ($severity_class !== 'critical' ? $severity_class : '') . "'>
                            <h6 class='mb-2 fw-bold'>
                                <i class='bi bi-" . ($severity_class === 'critical' ? 'exclamation-circle-fill' : 'exclamation-triangle-fill') . " me-2'></i>" . htmlspecialchars($p['title']) . "
                            </h6>
                            <p class='text-muted mb-2'>" . htmlspecialchars($p['description']) . "</p>
                            <div class='alert alert-info small mb-0'>
                                <strong><i class='bi bi-lightbulb me-1'></i>Action Required:</strong> " . htmlspecialchars($p['action']) . "
                            </div>
                        </div>";
                    }, $problem_areas))
                ) . "
                
                <!-- Footer -->
                <hr class='my-4'>
                <div class='row'>
                    <div class='col-md-8'>
                        <p class='small text-muted mb-1'>
                            <strong>Report Generated:</strong> " . date('F j, Y \a\t g:i a') . "
                        </p>
                        <p class='small text-muted'>
                            This report is confidential and intended for authorized users only.
                        </p>
                    </div>
                    <div class='col-md-4 text-end print-btn'>
                        <button class='btn btn-sm btn-primary' onclick='window.print()'>
                            <i class='bi bi-printer me-1'></i>Print Report
                        </button>
                        <a href='?check_id={$check['check_id']}&format=pdf&download=1' class='btn btn-sm btn-danger ms-2'>
                            <i class='bi bi-file-pdf me-1'></i>Download PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>";
}

function generatePDFReport($check, $problem_areas, $sim_details, $ai_details, $download) {
    // Require mPDF or similar PDF library
    // For now, return HTML with PDF CSS
    header('Content-Type: text/html; charset=utf-8');
    if ($download) {
        header('Content-Disposition: attachment; filename="dissertation_integrity_' . $check['check_id'] . '.html"');
    }
    echo "<html><head><style>@media print { * { margin: 0; padding: 0; } }</style></head><body>";
    generateHTMLReport($check, $problem_areas, $sim_details, $ai_details);
    echo "</body></html>";
}

function generateExcelReport($check, $problem_areas, $sim_details, $ai_details, $download) {
    // Generate CSV as Excel-compatible format
    header('Content-Type: text/csv; charset=utf-8');
    if ($download) {
        header('Content-Disposition: attachment; filename="dissertation_integrity_' . $check['check_id'] . '.csv"');
    }
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Dissertation Integrity Report']);
    fputcsv($output, ['']);
    
    fputcsv($output, ['Report Information']);
    fputcsv($output, ['Report ID', $check['check_id']]);
    fputcsv($output, ['Generated Date', date('F j, Y H:i:s')]);
    fputcsv($output, ['']);
    
    fputcsv($output, ['Submission Details']);
    fputcsv($output, ['Dissertation Title', $check['diss_title']]);
    fputcsv($output, ['Student', $check['student_name']]);
    fputcsv($output, ['Phase', ucfirst(str_replace('_', ' ', $check['phase']))]);
    fputcsv($output, ['Submitted', date('M j, Y', strtotime($check['submitted_at']))]);
    fputcsv($output, ['']);
    
    fputcsv($output, ['Analysis Results']);
    fputcsv($output, ['Similarity Score', round($check['similarity_score'], 1) . '%']);
    fputcsv($output, ['AI Probability', round($check['ai_score'], 1) . '%']);
    fputcsv($output, ['Word Count', $check['word_count'] ?? 'N/A']);
    fputcsv($output, ['Status', ucfirst(str_replace('_', ' ', $check['status']))]);
    fputcsv($output, ['']);
    
    fputcsv($output, ['Problem Areas Requiring Attention']);
    fputcsv($output, ['Issue Type', 'Title', 'Severity', 'Description', 'Action Required']);
    
    foreach ($problem_areas as $p) {
        fputcsv($output, [
            $p['type'],
            $p['title'],
            ucfirst($p['severity']),
            $p['description'],
            $p['action']
        ]);
    }
    
    fclose($output);
}

function generateWordReport($check, $problem_areas, $sim_details, $ai_details) {
    $phpWord = new \PhpOffice\PhpWord\PhpWord();

    // Document styles
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(11);

    $titleStyle   = ['bold' => true, 'size' => 16, 'color' => '2c3e50'];
    $headingStyle = ['bold' => true, 'size' => 13, 'color' => '2c3e50'];
    $labelStyle   = ['bold' => true, 'size' => 11];
    $valueStyle   = ['size' => 11];
    $redStyle     = ['bold' => true, 'size' => 11, 'color' => 'c0392b'];
    $orangeStyle  = ['bold' => true, 'size' => 11, 'color' => 'e67e22'];
    $greenStyle   = ['bold' => true, 'size' => 11, 'color' => '27ae60'];
    $grayStyle    = ['size' => 10, 'color' => '7f8c8d'];

    $section = $phpWord->addSection([
        'marginTop'    => 1440,
        'marginBottom' => 1440,
        'marginLeft'   => 1440,
        'marginRight'  => 1440,
    ]);

    // ---- Title block ----
    $section->addText('Dissertation Integrity Report', $titleStyle, ['spaceAfter' => 80]);
    $section->addText('Plagiarism & AI Detection Analysis — Flagged Report', $grayStyle, ['spaceAfter' => 200]);
    $section->addLine(['weight' => 1, 'color' => '2c3e50', 'width' => 9000]);

    // ---- Report metadata ----
    $section->addTextBreak(1);
    $section->addText('Report Details', $headingStyle, ['spaceAfter' => 60]);
    $tbl = $section->addTable(['borderColor' => 'cccccc', 'borderSize' => 1, 'cellMargin' => 100]);
    $info_rows = [
        ['Report ID', (string)$check['check_id']],
        ['Generated', date('F j, Y \a\t g:i a')],
        ['Student',   $check['student_name'] ?? 'N/A'],
        ['Email',     $check['student_email'] ?? 'N/A'],
        ['Dissertation', $check['diss_title'] ?? 'N/A'],
        ['Phase',     ucfirst(str_replace('_', ' ', $check['phase'] ?? ''))],
        ['Supervisor', $check['supervisor_name'] ?? 'N/A'],
        ['Submitted', $check['submitted_at'] ? date('M j, Y', strtotime($check['submitted_at'])) : 'N/A'],
    ];
    foreach ($info_rows as $r) {
        $row = $tbl->addRow();
        $row->addCell(2400)->addText($r[0], $labelStyle);
        $row->addCell(6600)->addText($r[1], $valueStyle);
    }

    // ---- Scores ----
    $section->addTextBreak(1);
    $section->addText('Analysis Scores', $headingStyle, ['spaceAfter' => 60]);

    $sim_score = (float)($check['similarity_score'] ?? 0);
    $ai_score  = (float)($check['ai_detection_score'] ?? $check['ai_score'] ?? 0);

    $sim_color = $sim_score >= 25 ? 'c0392b' : ($sim_score >= 15 ? 'e67e22' : '27ae60');
    $ai_color  = $ai_score  >= 40 ? 'c0392b' : ($ai_score  >= 20 ? 'e67e22' : '27ae60');

    $scores_tbl = $section->addTable(['borderColor' => 'cccccc', 'borderSize' => 1, 'cellMargin' => 100]);
    $sr = $scores_tbl->addRow();
    $sr->addCell(4500)->addText('Similarity Score', $labelStyle);
    $sr->addCell(4500)->addText(round($sim_score, 1) . '%', ['bold' => true, 'size' => 14, 'color' => $sim_color]);
    $ar = $scores_tbl->addRow();
    $ar->addCell(4500)->addText('AI Detection Score', $labelStyle);
    $ar->addCell(4500)->addText(round($ai_score, 1) . '%', ['bold' => true, 'size' => 14, 'color' => $ai_color]);
    $wr = $scores_tbl->addRow();
    $wr->addCell(4500)->addText('Total Words Checked', $labelStyle);
    $wr->addCell(4500)->addText(number_format((int)($check['total_words_checked'] ?? 0)), $valueStyle);
    $fr = $scores_tbl->addRow();
    $fr->addCell(4500)->addText('Flagged Words (est.)', $labelStyle);
    $fr->addCell(4500)->addText(number_format((int)($check['flagged_words'] ?? 0)), ['bold' => true, 'size' => 11, 'color' => 'c0392b']);

    // ---- Problem areas ----
    $section->addTextBreak(1);
    $section->addText('Areas Requiring Attention', $headingStyle, ['spaceAfter' => 60]);

    if (empty($problem_areas)) {
        $section->addText('No significant issues detected. Submission looks good.', array_merge($greenStyle, ['italic' => true]));
    } else {
        foreach ($problem_areas as $p) {
            $sev = $p['severity'] ?? 'medium';
            $icon_map = ['critical' => '[CRITICAL]', 'high' => '[HIGH]', 'medium' => '[MEDIUM]', 'low' => '[LOW]', 'warning' => '[WARNING]'];
            $clr_map  = ['critical' => 'c0392b', 'high' => 'c0392b', 'medium' => 'e67e22', 'low' => '27ae60', 'warning' => '2980b9'];
            $icon  = $icon_map[$sev] ?? '[INFO]';
            $color = $clr_map[$sev] ?? '7f8c8d';

            $prob_tbl = $section->addTable([
                'borderColor' => $color,
                'borderSize'  => 6,
                'cellMargin'  => 120,
            ]);
            $title_row = $prob_tbl->addRow();
            $title_cell = $title_row->addCell(9000, ['bgColor' => 'fff3f3']);
            $title_cell->addText($icon . ' ' . ($p['title'] ?? ''), ['bold' => true, 'size' => 11, 'color' => $color]);
            $desc_row = $prob_tbl->addRow();
            $desc_row->addCell(9000)->addText($p['description'] ?? '', $valueStyle);
            $act_row = $prob_tbl->addRow();
            $act_cell = $act_row->addCell(9000, ['bgColor' => 'eff6ff']);
            $act_cell->addText('Action Required: ' . ($p['action'] ?? ''), ['size' => 10, 'italic' => true, 'color' => '2980b9']);

            $section->addTextBreak(1);
        }
    }

    // ---- Cross-student matches ----
    $matches = json_decode($check['cross_student_matches'] ?? '[]', true) ?? [];
    if (!empty($matches)) {
        $section->addText('Cross-Student Similarity Matches', $headingStyle, ['spaceAfter' => 60]);
        $match_tbl = $section->addTable(['borderColor' => 'cccccc', 'borderSize' => 1, 'cellMargin' => 100]);
        $mh = $match_tbl->addRow();
        $mh->addCell(3000)->addText('Student ID', $labelStyle);
        $mh->addCell(5000)->addText('Dissertation Title', $labelStyle);
        $mh->addCell(1000)->addText('Match %', $labelStyle);
        foreach ($matches as $m) {
            $mr = $match_tbl->addRow();
            $mr->addCell(3000)->addText($m['student_id'] ?? '', $valueStyle);
            $mr->addCell(5000)->addText($m['title'] ?? '', $valueStyle);
            $sim_pct = (float)($m['similarity'] ?? 0);
            $mr_clr = $sim_pct >= 25 ? 'c0392b' : ($sim_pct >= 15 ? 'e67e22' : '27ae60');
            $mr->addCell(1000)->addText(round($sim_pct, 1) . '%', ['bold' => true, 'size' => 11, 'color' => $mr_clr]);
        }
        $section->addTextBreak(1);
    }

    // ---- Footer ----
    $section->addLine(['weight' => 1, 'color' => 'cccccc', 'width' => 9000]);
    $section->addText(
        'This report is confidential and intended for authorized users only. Generated: ' . date('F j, Y \a\t g:i a'),
        array_merge($grayStyle, ['italic' => true]),
        ['spaceAfter' => 0]
    );

    // Output
    $filename = 'flagged_report_' . $check['check_id'] . '_' . date('Ymd') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    exit;
}
?>
