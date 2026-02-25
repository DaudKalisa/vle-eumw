<?php
/**
 * Student Exam Report - PDF Download
 * Generates an official school report card for completed exams
 * Students can only download after results are published
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);
require_once '../vendor/autoload.php';

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';

// --- Determine report type ---
$result_id = (int)($_GET['result_id'] ?? 0);   // Single exam result
$type = $_GET['type'] ?? 'single';             // single | semester | transcript

// Get student details
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) die('Student record not found.');

// Resolve program name
$program_name = $student['program'] ?? '';
if (empty($program_name) || is_numeric($program_name)) {
    $dept_id = !empty($student['department']) ? $student['department'] : $program_name;
    if ($dept_id) {
        $d = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
        $d->bind_param("i", $dept_id);
        $d->execute();
        $dr = $d->get_result()->fetch_assoc();
        $program_name = $dr ? $dr['department_name'] : 'Not Assigned';
    }
}

// --- Build result data ---
$results = [];
$report_title = '';
$report_subtitle = '';

if ($type === 'single' && $result_id) {
    // Single exam result
    $stmt = $conn->prepare("
        SELECT er.*, e.exam_name, e.exam_code, e.exam_type, e.total_marks, e.passing_marks,
               e.duration_minutes, e.results_published,
               c.course_name, c.course_code,
               es.started_at, es.ended_at
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        LEFT JOIN vle_courses c ON e.course_id = c.course_id
        LEFT JOIN exam_sessions es ON er.session_id = es.session_id
        WHERE er.result_id = ? AND er.student_id = ?
    ");
    $stmt->bind_param("is", $result_id, $student_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    
    if (!$r) die('Result not found or access denied.');
    if (empty($r['results_published'])) die('Results have not been published yet.');
    
    $results[] = $r;
    $report_title = htmlspecialchars($r['exam_name']);
    $report_subtitle = 'Examination Result Report';

} elseif ($type === 'semester') {
    // All published results for current semester
    $semester = $_GET['semester'] ?? '';
    $stmt = $conn->prepare("
        SELECT er.*, e.exam_name, e.exam_code, e.exam_type, e.total_marks, e.passing_marks,
               e.duration_minutes, e.results_published,
               c.course_name, c.course_code,
               es.started_at, es.ended_at
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        LEFT JOIN vle_courses c ON e.course_id = c.course_id
        LEFT JOIN exam_sessions es ON er.session_id = es.session_id
        WHERE er.student_id = ? AND e.results_published = 1
        ORDER BY e.exam_type, er.submitted_at ASC
    ");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $all = $stmt->get_result();
    while ($row = $all->fetch_assoc()) $results[] = $row;
    
    $report_title = 'Semester Examination Report';
    $report_subtitle = 'All Published Results';

} elseif ($type === 'transcript') {
    // Full academic transcript - all exams
    $stmt = $conn->prepare("
        SELECT er.*, e.exam_name, e.exam_code, e.exam_type, e.total_marks, e.passing_marks,
               e.duration_minutes, e.results_published,
               c.course_name, c.course_code,
               es.started_at, es.ended_at
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        LEFT JOIN vle_courses c ON e.course_id = c.course_id
        LEFT JOIN exam_sessions es ON er.session_id = es.session_id
        WHERE er.student_id = ? AND e.results_published = 1
        ORDER BY er.submitted_at ASC
    ");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $all = $stmt->get_result();
    while ($row = $all->fetch_assoc()) $results[] = $row;
    
    $report_title = 'Academic Transcript';
    $report_subtitle = 'Complete Examination Record';
}

if (empty($results)) die('No published results found.');

// --- Grade legend ---
$grade_legend = [
    'A' => ['range' => '75-100%', 'desc' => 'Distinction', 'gpa' => '4.0'],
    'B' => ['range' => '65-74%',  'desc' => 'Credit',      'gpa' => '3.0'],
    'C' => ['range' => '50-64%',  'desc' => 'Pass',        'gpa' => '2.0'],
    'D' => ['range' => '40-49%',  'desc' => 'Supplementary','gpa' => '1.0'],
    'F' => ['range' => '0-39%',   'desc' => 'Fail',        'gpa' => '0.0'],
];

// --- Calculate summary ---
$total_exams = count($results);
$total_passed = count(array_filter($results, fn($r) => $r['is_passed']));
$avg_pct = $total_exams > 0 ? array_sum(array_column($results, 'percentage')) / $total_exams : 0;

// Determine overall grade
if ($avg_pct >= 75) $overall_grade = 'A';
elseif ($avg_pct >= 65) $overall_grade = 'B';
elseif ($avg_pct >= 50) $overall_grade = 'C';
elseif ($avg_pct >= 40) $overall_grade = 'D';
else $overall_grade = 'F';

// Logo as base64 for PDF embedding
$logo_path = realpath('../assets/img/Logo.png');
$logo_base64 = '';
if ($logo_path && file_exists($logo_path)) {
    $logo_base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$today = date('F d, Y');
$academic_year = date('Y') . '/' . (date('Y') + 1);
$student_year = $student['year_of_study'] ?? 1;

// --- Build PDF HTML ---
$html = '
<style>
    body { font-family: "Times New Roman", serif; color: #222; font-size: 11pt; }
    .header { text-align: center; border-bottom: 3px double #1a3a6e; padding-bottom: 15px; margin-bottom: 20px; }
    .header img { height: 70px; }
    .uni-name { font-size: 22pt; font-weight: bold; color: #1a3a6e; margin: 5px 0 2px; }
    .uni-motto { font-size: 9pt; color: #666; font-style: italic; }
    .report-title { font-size: 14pt; font-weight: bold; color: #1a3a6e; margin-top: 10px; text-transform: uppercase; letter-spacing: 2px; }
    .student-info { margin: 15px 0; }
    .student-info td { padding: 3px 10px; font-size: 10pt; }
    .student-info .label { color: #555; font-weight: bold; width: 150px; }
    .results-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
    .results-table th { background: #1a3a6e; color: #fff; padding: 8px 6px; font-size: 9pt; text-align: left; border: 1px solid #1a3a6e; }
    .results-table td { padding: 6px; font-size: 9pt; border: 1px solid #ccc; }
    .results-table tr:nth-child(even) { background: #f5f7fa; }
    .grade-a { color: #198754; font-weight: bold; }
    .grade-b { color: #0d6efd; font-weight: bold; }
    .grade-c { color: #6f42c1; font-weight: bold; }
    .grade-d { color: #fd7e14; font-weight: bold; }
    .grade-f { color: #dc3545; font-weight: bold; }
    .summary-box { background: #f0f4f8; border: 1px solid #ccc; border-radius: 6px; padding: 12px; margin: 15px 0; }
    .legend-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    .legend-table td, .legend-table th { padding: 4px 8px; font-size: 8pt; border: 1px solid #ddd; }
    .legend-table th { background: #e9ecef; }
    .footer { text-align: center; font-size: 8pt; color: #888; border-top: 1px solid #ccc; padding-top: 10px; margin-top: 30px; }
    .watermark { position: fixed; top: 45%; left: 25%; opacity: 0.04; font-size: 60pt; color: #1a3a6e; transform: rotate(-30deg); z-index: -1; }
    .stamp-area { margin-top: 30px; }
    .stamp-area td { padding: 5px 20px; vertical-align: bottom; }
    .passed-stamp { color: #198754; font-weight: bold; font-size: 14pt; border: 3px solid #198754; padding: 5px 15px; display: inline-block; }
    .failed-stamp { color: #dc3545; font-weight: bold; font-size: 14pt; border: 3px solid #dc3545; padding: 5px 15px; display: inline-block; }
</style>

<div class="watermark">EXPLOITS UNIVERSITY</div>

<!-- University Header -->
<div class="header">
    ' . ($logo_base64 ? '<img src="' . $logo_base64 . '" alt="Logo">' : '') . '
    <div class="uni-name">EXPLOITS UNIVERSITY MALAWI</div>
    <div class="uni-motto">"Knowledge is Power - Education for Transformation"</div>
    <div style="font-size:9pt; color:#444;">P.O. Box 1234, Lilongwe, Malawi | www.exploitsonline.com</div>
    <div class="report-title">' . $report_subtitle . '</div>
</div>

<!-- Student Information -->
<table class="student-info" width="100%">
    <tr>
        <td class="label">Student Name:</td>
        <td><strong>' . htmlspecialchars($student['full_name']) . '</strong></td>
        <td class="label">Student ID:</td>
        <td><strong>' . htmlspecialchars($student['student_id']) . '</strong></td>
    </tr>
    <tr>
        <td class="label">Programme:</td>
        <td>' . htmlspecialchars($program_name) . '</td>
        <td class="label">Year of Study:</td>
        <td>Year ' . $student_year . '</td>
    </tr>
    <tr>
        <td class="label">Academic Year:</td>
        <td>' . $academic_year . '</td>
        <td class="label">Report Date:</td>
        <td>' . $today . '</td>
    </tr>
</table>
<hr style="border: 1px solid #1a3a6e;">
';

// Results table
$html .= '
<table class="results-table">
    <thead>
        <tr>
            <th style="width:5%;">#</th>
            <th style="width:12%;">Code</th>
            <th style="width:25%;">Examination / Course</th>
            <th style="width:10%;">Type</th>
            <th style="width:10%;">Marks</th>
            <th style="width:10%;">Out Of</th>
            <th style="width:10%;">Percentage</th>
            <th style="width:8%;">Grade</th>
            <th style="width:10%;">Status</th>
        </tr>
    </thead>
    <tbody>';

$exam_types = ['quiz' => 'Quiz', 'mid_term' => 'Mid-Term', 'final' => 'Final Exam', 'assignment' => 'CA'];

foreach ($results as $i => $r) {
    $grade = $r['grade'] ?: 'N/A';
    $grade_class = 'grade-' . strtolower($grade);
    $status = $r['is_passed'] ? '<span style="color:#198754;">Pass</span>' : '<span style="color:#dc3545;">Fail</span>';
    $exam_type_label = $exam_types[$r['exam_type']] ?? ucfirst(str_replace('_', ' ', $r['exam_type']));
    $course_label = $r['course_name'] ? htmlspecialchars($r['course_code'] . ' - ' . $r['course_name']) : htmlspecialchars($r['exam_name']);
    
    $html .= '
        <tr>
            <td>' . ($i + 1) . '</td>
            <td>' . htmlspecialchars($r['exam_code']) . '</td>
            <td>' . $course_label . '</td>
            <td>' . $exam_type_label . '</td>
            <td style="text-align:center;">' . number_format($r['score'], 1) . '</td>
            <td style="text-align:center;">' . $r['total_marks'] . '</td>
            <td style="text-align:center;">' . number_format($r['percentage'], 1) . '%</td>
            <td style="text-align:center;" class="' . $grade_class . '">' . $grade . '</td>
            <td style="text-align:center;">' . $status . '</td>
        </tr>';
}

$html .= '</tbody></table>';

// Summary section
$html .= '
<div class="summary-box">
    <table width="100%">
        <tr>
            <td width="50%">
                <strong>Performance Summary</strong><br>
                Examinations Taken: <strong>' . $total_exams . '</strong><br>
                Passed: <strong style="color:#198754;">' . $total_passed . '</strong> | 
                Failed: <strong style="color:#dc3545;">' . ($total_exams - $total_passed) . '</strong><br>
                Average Score: <strong>' . number_format($avg_pct, 1) . '%</strong><br>
                Overall Grade: <strong class="grade-' . strtolower($overall_grade) . '">' . $overall_grade . ' (' . $grade_legend[$overall_grade]['desc'] . ')</strong>
            </td>
            <td width="50%" style="text-align:right;">
                <strong>GPA Equivalent:</strong> <span style="font-size:18pt; color:#1a3a6e; font-weight:bold;">' . $grade_legend[$overall_grade]['gpa'] . '</span> / 4.0
            </td>
        </tr>
    </table>
</div>';

// Grade Legend
$html .= '
<table class="legend-table">
    <tr><th>Grade</th><th>Range</th><th>Description</th><th>GPA</th></tr>';
foreach ($grade_legend as $g => $info) {
    $html .= '<tr><td class="grade-' . strtolower($g) . '">' . $g . '</td><td>' . $info['range'] . '</td><td>' . $info['desc'] . '</td><td>' . $info['gpa'] . '</td></tr>';
}
$html .= '</table>';

// Signature area
$html .= '
<table class="stamp-area" width="100%">
    <tr>
        <td width="33%" style="text-align:center;">
            <br><br>
            ___________________________<br>
            <small>Examination Officer</small>
        </td>
        <td width="34%" style="text-align:center;">
            ' . ($total_passed === $total_exams ? '<span class="passed-stamp">PASSED</span>' : ($total_passed > 0 ? '<span style="color:#fd7e14; font-weight:bold; font-size:12pt; border:2px solid #fd7e14; padding:4px 12px;">CONDITIONAL</span>' : '<span class="failed-stamp">FAILED</span>')) . '
        </td>
        <td width="33%" style="text-align:center;">
            <br><br>
            ___________________________<br>
            <small>Registrar</small>
        </td>
    </tr>
</table>';

// Footer
$html .= '
<div class="footer">
    This is a computer-generated report from Exploits University Virtual Learning Environment.<br>
    Any alterations render this document invalid. Verify at: vle.exploitsonline.com<br>
    Report Reference: EU-' . strtoupper(substr(md5($student_id . date('Ymd')), 0, 8)) . ' | Generated: ' . date('Y-m-d H:i:s') . '
</div>';

// --- Generate PDF ---
try {
    $mpdf = new \Mpdf\Mpdf([
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_left' => 15,
        'margin_right' => 15,
        'format' => 'A4',
    ]);
    
    $mpdf->SetTitle('Exam Report - ' . $student['full_name']);
    $mpdf->SetAuthor('Exploits University VLE');
    $mpdf->SetCreator('VLE Examination System');
    $mpdf->SetProtection(['print', 'copy'], '', 'admin_password_eu2026');
    
    $mpdf->WriteHTML($html);
    
    $filename = 'Exam_Report_' . preg_replace('/[^A-Za-z0-9_]/', '_', $student['student_id']) . '_' . date('Ymd') . '.pdf';
    $mpdf->Output($filename, 'I');
} catch (Exception $e) {
    die('PDF generation error: ' . $e->getMessage());
}
