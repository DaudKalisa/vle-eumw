<?php
/**
 * Research Coordinator - Print Marking Sheet
 * Generates printable marking sheets for proposal and final defense
 * 
 * Usage: marking_sheet.php?defense_id=X  or  marking_sheet.php?dissertation_id=X&type=proposal|final
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$conn = getDbConnection();

$defense_id = (int)($_GET['defense_id'] ?? 0);
$dissertation_id = (int)($_GET['dissertation_id'] ?? 0);
$defense_type = $_GET['type'] ?? 'proposal';

// Validate defense_type
if (!in_array($defense_type, ['proposal', 'final'])) {
    $defense_type = 'proposal';
}

$defense = null;
$dissertation = null;
$student = null;
$supervisor = null;

if ($defense_id) {
    $stmt = $conn->prepare("
        SELECT dd.*, d.title, d.topic_area, d.student_id, d.program, d.program_type,
               d.academic_year, d.supervisor_id, d.co_supervisor_id,
               s.full_name AS student_name, s.email AS student_email,
               l.full_name AS supervisor_name, l.email AS supervisor_email
        FROM dissertation_defense dd
        JOIN dissertations d ON dd.dissertation_id = d.dissertation_id
        LEFT JOIN students s ON d.student_id = s.student_id
        LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
        WHERE dd.defense_id = ?
    ");
    $stmt->bind_param("i", $defense_id);
    $stmt->execute();
    $defense = $stmt->get_result()->fetch_assoc();
    if ($defense) {
        $defense_type = $defense['defense_type'] ?? $defense_type;
        $dissertation_id = $defense['dissertation_id'];
    }
} elseif ($dissertation_id) {
    $stmt = $conn->prepare("
        SELECT d.*, s.full_name AS student_name, s.email AS student_email,
               l.full_name AS supervisor_name, l.email AS supervisor_email
        FROM dissertations d
        LEFT JOIN students s ON d.student_id = s.student_id
        LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
        WHERE d.dissertation_id = ?
    ");
    $stmt->bind_param("i", $dissertation_id);
    $stmt->execute();
    $defense = $stmt->get_result()->fetch_assoc();
}

if (!$defense) {
    die('<div style="text-align:center;padding:60px;font-family:Arial,sans-serif"><h2>Dissertation not found</h2><p>Please go back and select a valid defense or dissertation.</p><a href="defense_management.php">Back to Defense Management</a></div>');
}

// Get co-supervisor name if exists
$co_supervisor_name = '';
if (!empty($defense['co_supervisor_id'])) {
    $cs = $conn->prepare("SELECT full_name FROM lecturers WHERE lecturer_id = ?");
    $cs->bind_param("i", $defense['co_supervisor_id']);
    $cs->execute();
    $csr = $cs->get_result()->fetch_assoc();
    $co_supervisor_name = $csr['full_name'] ?? '';
}

// Get panel members from defense if available
$panel_members = [];
if (!empty($defense['panel_members'])) {
    $panel_members = json_decode($defense['panel_members'], true) ?: [];
}

$is_proposal = ($defense_type === 'proposal');
$title = $is_proposal ? 'DISSERTATION PROPOSAL DEFENSE MARKING SHEET' : 'DISSERTATION MARKING SHEET';

// Marking criteria based on university template
if ($is_proposal) {
    $criteria = [
        ['TOPIC' . "\n" . '(Clear and Concise Topic)', 5],
        ['INTRODUCTION' . "\n" . '(Context, problem statement, objectives and study justification)', 10],
        ['LITERATURE REVIEW' . "\n" . '(Theory guiding the study is present and relevant, Identification of previous studies, clear concise)', 10],
        ['CONCEPTUAL FRAMEWORK' . "\n" . '(Conceptual framework showing variables related to the research)', 5],
        ['RESEARCH METHODOLOGY' . "\n" . '(Appropriateness of Research Approach design, study population, sampling techniques and sample size, Data collection and data analysis, study limitations and ethical issues involved)', 10],
        ['ORGANIZATION AND PRESENTATION' . "\n" . '(Clear & Logical research structure, excellent use of language and Skillful presentations)', 5],
        ['RESPONSE TO QUESTIONS', 5],
    ];
} else {
    $criteria = [
        ['TOPIC' . "\n" . '(Clear, Concise and Relevant Research Topic)', 5],
        ['INTRODUCTION' . "\n" . '(Background, context, problem statement, objectives, research questions and study justification)', 10],
        ['LITERATURE REVIEW' . "\n" . '(Comprehensive and current literature, theory guiding the study, identification of previous studies, critical analysis and synthesis)', 10],
        ['CONCEPTUAL FRAMEWORK' . "\n" . '(Conceptual framework showing variables related to the research, clearly linked to objectives)', 5],
        ['RESEARCH METHODOLOGY' . "\n" . '(Appropriateness of Research Approach design, study population, sampling techniques and sample size, data collection, data analysis, validity & reliability, study limitations and ethical issues)', 10],
        ['RESULTS, FINDINGS AND DISCUSSION' . "\n" . '(Presentation and analysis of findings, link to objectives, discussion with reference to literature)', 10],
        ['CONCLUSION AND RECOMMENDATIONS' . "\n" . '(Summary of key findings, practical recommendations, suggestions for future research)', 5],
        ['ORGANIZATION AND PRESENTATION' . "\n" . '(Clear & Logical research structure, excellent use of language, skillful presentation and defense)', 5],
        ['RESPONSE TO QUESTIONS', 5],
    ];
}

// Remove zero-mark items from total but keep for display
$total_marks = 0;
foreach ($criteria as $item) {
    $total_marks += $item[1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
    <style>
        @page { size: A4; margin: 15mm 20mm; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.4; color: #000; background: #f5f5f5; }
        .sheet { width: 210mm; min-height: 297mm; margin: 10mm auto; background: #fff; padding: 15mm 20mm; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px double #000; padding-bottom: 15px; }
        .header img { height: 60px; margin-bottom: 8px; }
        .header h1 { font-size: 16pt; letter-spacing: 1px; margin-bottom: 4px; }
        .header h2 { font-size: 13pt; font-weight: normal; color: #333; }
        .header h3 { font-size: 12pt; font-weight: bold; margin-top: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-table { width: 100%; margin: 15px 0; border-collapse: collapse; }
        .info-table td { padding: 5px 8px; font-size: 11pt; vertical-align: top; }
        .info-table .label { font-weight: bold; white-space: nowrap; width: 160px; }
        .info-table .value { border-bottom: 1px dotted #999; }
        .marking-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .marking-table th, .marking-table td { border: 1px solid #000; padding: 6px 8px; font-size: 10pt; }
        .marking-table th { background: #f0f0f0; font-weight: bold; text-align: center; }
        .marking-table .criteria-name { font-weight: bold; line-height: 1.3; }
        .marking-table .criteria-desc { font-weight: normal; font-size: 9pt; color: #333; }
        .marking-table .max-col { width: 80px; text-align: center; }
        .marking-table .mark-col { width: 80px; text-align: center; }
        .marking-table .total-row { background: #d0d0d0; font-weight: bold; font-size: 11pt; }
        .grade-section { margin: 20px 0; }
        .grade-table { width: 100%; border-collapse: collapse; }
        .grade-table td { border: 1px solid #000; padding: 5px 10px; font-size: 10pt; text-align: center; }
        .grade-table th { border: 1px solid #000; padding: 5px 10px; font-size: 10pt; background: #f0f0f0; font-weight: bold; }
        .signature-section { margin-top: 30px; }
        .sig-row { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .sig-block { width: 45%; }
        .sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 4px; }
        .sig-label { font-size: 10pt; color: #333; }
        .comments-box { border: 1px solid #000; min-height: 60px; padding: 8px; margin: 10px 0; font-size: 10pt; }
        .result-section { margin: 15px 0; padding: 10px; border: 2px solid #000; }
        .result-section label { font-size: 11pt; margin-right: 20px; }
        .checkbox { display: inline-block; width: 14px; height: 14px; border: 1.5px solid #000; margin-right: 5px; vertical-align: middle; }
        .btn-print { position: fixed; bottom: 20px; right: 20px; padding: 12px 30px; background: #1a237e; color: #fff; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,.2); z-index: 100; }
        .btn-print:hover { background: #283593; }
        .btn-back { position: fixed; bottom: 20px; right: 180px; padding: 12px 30px; background: #666; color: #fff; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,.2); z-index: 100; text-decoration: none; }
        .btn-back:hover { background: #555; }
    </style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">🖨️ Print Marking Sheet</button>
<a href="defense_management.php" class="btn-back no-print">← Back</a>

<div class="sheet">
    <!-- Header -->
    <div class="header">
        <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
        <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
        <h2>Faculty of Research and Innovation</h2>
        <h3><?= $title ?></h3>
    </div>

    <!-- Student Information -->
    <table class="info-table">
        <tr>
            <td class="label">STUDENT NAME:</td>
            <td class="value" colspan="3"><?= htmlspecialchars($defense['student_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="label">STUDENT ID:</td>
            <td class="value"><?= htmlspecialchars($defense['student_id'] ?? '') ?></td>
            <td class="label" style="width:140px">PROGRAM:</td>
            <td class="value"><?= htmlspecialchars($defense['program'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="label">PRESENTATION DATE:</td>
            <td class="value"><?= !empty($defense['defense_date']) ? date('j/m/Y', strtotime($defense['defense_date'])) : '___/___/______' ?></td>
            <td class="label">TIME:</td>
            <td class="value"><?= !empty($defense['defense_date']) ? date('h:i A', strtotime($defense['defense_date'])) : '________________' ?></td>
        </tr>
        <tr>
            <td class="label">NAME OF SUPERVISOR:</td>
            <td class="value" colspan="3"><?= htmlspecialchars($defense['supervisor_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="label">YEAR OF STUDY:</td>
            <td class="value"><?= htmlspecialchars($defense['academic_year'] ?? date('Y')) ?></td>
            <td class="label">VENUE:</td>
            <td class="value"><?= htmlspecialchars($defense['venue'] ?? '________________') ?></td>
        </tr>
        <tr>
            <td class="label">TOPIC:</td>
            <td class="value" colspan="3" style="font-weight:bold"><?= htmlspecialchars($defense['title'] ?? '') ?></td>
        </tr>
    </table>

    <!-- Marking Criteria -->
    <p style="font-size:11pt;font-weight:bold;margin:10px 0 5px">MARKING CRITERIA: &nbsp; Assessment Criteria <?= $total_marks ?> Marks Total</p>
    <table class="marking-table">
        <thead>
            <tr>
                <th style="text-align:left">CRITERIA</th>
                <th class="max-col">MARKS ASSIGNED</th>
                <th class="mark-col">MARKS AWARDED</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($criteria as $item):
                $parts = explode("\n", $item[0]);
                $name = $parts[0];
                $desc = $parts[1] ?? '';
            ?>
                <tr>
                    <td>
                        <span class="criteria-name"><?= htmlspecialchars($name) ?></span>
                        <?php if ($desc): ?>
                            <br><span class="criteria-desc"><?= htmlspecialchars($desc) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="max-col"><?= $item[1] > 0 ? $item[1] : '' ?></td>
                    <td class="mark-col"></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td>TOTAL MARKS</td>
                <td class="max-col"><?= $total_marks ?></td>
                <td class="mark-col"></td>
            </tr>
        </tbody>
    </table>

    <!-- General Comments -->
    <p style="font-size:11pt;margin-top:15px"><strong>GENERAL COMMENTS:</strong></p>
    <div class="comments-box" style="min-height:80px">
        ......................................................................................................................................................................................................................................................................................................................................................................
    </div>

    <!-- Result -->
    <div class="result-section">
        <strong>RESULT:</strong><br><br>
        <label><span class="checkbox"></span> Pass</label>
        <label><span class="checkbox"></span> Conditional Pass (See conditions below)</label>
        <label><span class="checkbox"></span> Major Revision Required</label>
        <label><span class="checkbox"></span> Fail</label>
    </div>

    <!-- Panel Signatures (3 Panelists) -->
    <div class="signature-section">
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div style="margin-bottom:20px;display:flex;align-items:flex-end;gap:20px">
            <div style="flex:0 0 auto">
                <strong style="font-size:10pt">Panelist <?= $i ?>:</strong>
            </div>
            <div style="flex:1;border-bottom:1px solid #000;min-width:200px">&nbsp;</div>
            <div style="flex:0 0 auto;font-size:10pt">Signature:</div>
            <div style="flex:0 0 120px;border-bottom:1px solid #000">&nbsp;</div>
            <div style="flex:0 0 auto;font-size:10pt">Date: ___/___/<?= date('Y') ?></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

</body>
</html>
