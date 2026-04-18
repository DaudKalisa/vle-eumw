<?php
/**
 * Generate Research Ethics Application for Defense Form (READF)
 * Pre-fills student/dissertation data, generates editable PDF
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/ethics_form_errors.log');
ob_start();
session_start();
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_role = $_SESSION['vle_role'] ?? '';
$student_id = $_SESSION['vle_related_id'] ?? '';

// Allow student, supervisor (lecturer), research_coordinator, admin
if (!in_array($user_role, ['student', 'lecturer', 'research_coordinator', 'admin'])) {
    http_response_code(403);
    exit('Access denied');
}

$dissertation_id = (int)($_GET['dissertation_id'] ?? 0);
if (!$dissertation_id) {
    http_response_code(400);
    exit('Missing dissertation_id');
}

// Get dissertation with student and supervisor info
$stmt = $conn->prepare("
    SELECT d.*, 
           s.full_name AS student_name, s.email AS student_email, s.student_id,
           s.program AS student_program, s.program_type, s.campus,
           l.full_name AS supervisor_name, l.email AS supervisor_email
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE d.dissertation_id = ?
");
$stmt->bind_param("i", $dissertation_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    http_response_code(404);
    exit('Dissertation not found');
}

// Verify access: student can only access own dissertation
if ($user_role === 'student' && $data['student_id'] !== $student_id) {
    http_response_code(403);
    exit('Access denied');
}

// Generate unique application number: READF/YEAR/DISS_ID/RANDOM
$app_number = 'READF/' . date('Y') . '/' . str_pad($dissertation_id, 4, '0', STR_PAD_LEFT) . '/' . strtoupper(substr(md5(uniqid()), 0, 4));
$date_received = date('jS F, Y');

// Build the PDF
require_once __DIR__ . '/../vendor/autoload.php';

$mpdfTempDir = dirname(__DIR__) . '/uploads/mpdf_tmp';
if (!is_dir($mpdfTempDir)) {
    mkdir($mpdfTempDir, 0777, true);
}

try {
$mpdf = new \Mpdf\Mpdf([
    'format' => 'A4',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font' => 'times',
    'default_font_size' => 11,
    'tempDir' => $mpdfTempDir,
]);

$mpdf->SetTitle('Research Ethics Application - ' . htmlspecialchars($data['student_name'] ?? ''));
$mpdf->SetAuthor('Exploits University of Malawi');

// Pre-fill data
$studentName = htmlspecialchars($data['student_name'] ?? '');
$studentEmail = htmlspecialchars($data['student_email'] ?? '');
$studentIdStr = htmlspecialchars($data['student_id'] ?? '');
$supervisorName = htmlspecialchars($data['supervisor_name'] ?? '');
$campus = htmlspecialchars($data['campus'] ?? 'Main Campus');
$program = htmlspecialchars($data['student_program'] ?? $data['program'] ?? '');
$programType = htmlspecialchars($data['program_type'] ?? '');
$researchTitle = htmlspecialchars($data['title'] ?? '');
$academicYear = htmlspecialchars($data['academic_year'] ?? date('Y'));

$logoPath = realpath(__DIR__ . '/../assets/img/Logo.png');
$logoHtml = '';
if ($logoPath && file_exists($logoPath)) {
    // Convert Windows backslashes to forward slashes for mPDF compatibility
    $logoPathFwd = str_replace('\\', '/', $logoPath);
    $logoHtml = '<img src="' . $logoPathFwd . '" style="height:60px;">';
}

$html = <<<HTML
<style>
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; line-height: 1.5; }
    .header { text-align: center; margin-bottom: 10px; }
    .header h1 { font-size: 16pt; margin: 5px 0; letter-spacing: 1px; }
    .header h2 { font-size: 13pt; margin: 3px 0; }
    .header h3 { font-size: 11pt; margin: 3px 0; }
    .app-info { width: 100%; margin: 10px 0; }
    .app-info td { padding: 3px 8px; font-size: 10pt; }
    .section-title { background: #e0e0e0; padding: 8px 12px; font-weight: bold; font-size: 11pt; margin: 15px 0 5px 0; border: 1px solid #999; }
    .field-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
    .field-table td { padding: 6px 8px; font-size: 10.5pt; border-bottom: 1px dotted #999; vertical-align: top; }
    .field-table .label { font-weight: bold; width: 240px; border-bottom: 1px solid #ccc; }
    .field-table .value { color: #000080; }
    .fill-field { border-bottom: 1px dotted #999; min-height: 20px; padding: 2px 0; }
    .text-area { border: 1px solid #999; min-height: 80px; padding: 8px; margin: 5px 0; }
    .text-area-large { border: 1px solid #999; min-height: 120px; padding: 8px; margin: 5px 0; }
    .checkbox-line { margin: 5px 0; font-size: 10.5pt; }
    .checkbox { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #000; margin-right: 6px; vertical-align: middle; }
    .cover-note { background: #f9f9f9; border: 1px solid #ccc; padding: 10px 15px; margin: 10px 0; font-size: 10pt; line-height: 1.6; }
    .sig-section { margin-top: 20px; }
    .sig-block { margin-bottom: 15px; }
    .sig-line { border-bottom: 1px solid #000; width: 60%; height: 25px; display: inline-block; }
    .sig-date { border-bottom: 1px solid #000; width: 25%; height: 25px; display: inline-block; margin-left: 10px; }
    .page-break { page-break-before: always; }
    .small-text { font-size: 9pt; color: #555; }
    .important { color: #cc0000; font-weight: bold; }
    h4 { font-size: 11pt; margin: 12px 0 5px 0; }
    ol, ul { margin: 5px 0 5px 20px; }
    li { margin: 3px 0; font-size: 10.5pt; }
</style>

<!-- PAGE 1: Cover Page -->
<div class="header">
    {$logoHtml}
    <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
    <h2>RESEARCH ETHICS APPLICATION FOR DEFENCE FORM</h2>
    <h3>(READF)</h3>
</div>

<table class="app-info">
    <tr>
        <td style="text-align:right; width:70%"><strong>Application Number:</strong></td>
        <td style="color:#000080; font-weight:bold">{$app_number}</td>
    </tr>
    <tr>
        <td style="text-align:right"><strong>Date Received:</strong></td>
        <td style="color:#000080">{$date_received}</td>
    </tr>
</table>

<div class="cover-note">
    <p>The Research Ethics Application for Defense Form (READF) should be completed by:</p>
    <ul>
        <li>Bachelor's students undertaking undergraduate final year dissertation or projects requiring relevant ethics review and consideration.</li>
        <li>Master's students in academic programmes with research-based dissertation / Thesis</li>
    </ul>
    <p>The forms will be collected through the reception or the office of research coordinator.</p>
    
    <p><strong>Important Notes:</strong></p>
    <p>For students at all levels, the completed form should be submitted to the research coordinator after certified by all relevant authorities including: supervisor, Accounts department. Your supervisor will then review this and provide feedback commentary. Once initial approval is given, then the supervisor certifies by signing and will forward this for final approval by the Exploits University Research Ethics Committee (EUREC).</p>
    
    <p>The office of the registrar will stamp it and provide you with an Approval letter after you successfully defend your proposal.</p>
    
    <p>If you need to supply any supplementary material not specifically requested by the application form, please do so in a separate document. Any additional document(s) should be clearly labelled and attached to this document.</p>
    
    <p>If you have any queries completing the form, please address them to your dissertation / project supervisor.</p>
</div>

<!-- PAGE 2: Section A - Applicant Details -->
<div class="page-break"></div>

<div class="section-title">SECTION A: APPLICANT DETAILS</div>

<table class="field-table">
    <tr><td class="label">Student's Name:</td><td class="value">{$studentName}</td></tr>
    <tr><td class="label">Student's E-mail Address:</td><td class="value">{$studentEmail}</td></tr>
    <tr><td class="label">Student's ID #:</td><td class="value">{$studentIdStr}</td></tr>
    <tr><td class="label">Supervisor's Name:</td><td class="value">{$supervisorName}</td></tr>
    <tr><td class="label">University Campus:</td><td class="value">{$campus}</td></tr>
    <tr><td class="label">Program of Study:</td><td class="value">{$program} ({$programType})</td></tr>
    <tr><td class="label">Academic Year:</td><td class="value">{$academicYear}</td></tr>
    <tr><td class="label">Research Project Title:</td><td class="value"><strong>{$researchTitle}</strong></td></tr>
</table>

<br>
<div class="section-title">SECTION B: RESEARCH PROJECT OVERVIEW</div>

<h4>B.1 Brief description of the research project (aims, objectives, methodology):</h4>
<div class="text-area-large">
</div>

<h4>B.2 What is the anticipated start date and duration of the research?</h4>
<table class="field-table">
    <tr><td class="label">Anticipated Start Date:</td><td class="fill-field"></td></tr>
    <tr><td class="label">Expected Duration:</td><td class="fill-field"></td></tr>
    <tr><td class="label">Expected End Date:</td><td class="fill-field"></td></tr>
</table>

<h4>B.3 Where will the research be conducted?</h4>
<div class="text-area">
</div>

<h4>B.4 What data collection methods will be used? (tick all that apply)</h4>
<div style="margin: 5px 0;">
    <p class="checkbox-line"><span class="checkbox"></span> Questionnaires / Surveys</p>
    <p class="checkbox-line"><span class="checkbox"></span> Interviews (structured / semi-structured / unstructured)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Focus Group Discussions</p>
    <p class="checkbox-line"><span class="checkbox"></span> Observation (participant / non-participant)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Document / Archival Analysis</p>
    <p class="checkbox-line"><span class="checkbox"></span> Experiments / Tests</p>
    <p class="checkbox-line"><span class="checkbox"></span> Case Study</p>
    <p class="checkbox-line"><span class="checkbox"></span> Other (specify): ....................................................................</p>
</div>

<!-- PAGE 3: Section C - Participants -->
<div class="page-break"></div>

<div class="section-title">SECTION C: RESEARCH PARTICIPANTS</div>

<h4>C.1 Who are the participants in this study? Describe the target population:</h4>
<div class="text-area">
</div>

<h4>C.2 How many participants will be involved?</h4>
<table class="field-table">
    <tr><td class="label">Expected Sample Size:</td><td class="fill-field"></td></tr>
    <tr><td class="label">Sampling Technique:</td><td class="fill-field"></td></tr>
</table>

<h4>C.3 How will participants be recruited?</h4>
<div class="text-area">
</div>

<h4>C.4 Does the study involve any of the following vulnerable groups? (tick all that apply)</h4>
<div style="margin: 5px 0;">
    <p class="checkbox-line"><span class="checkbox"></span> Children (under 18 years)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Elderly persons</p>
    <p class="checkbox-line"><span class="checkbox"></span> Persons with disabilities</p>
    <p class="checkbox-line"><span class="checkbox"></span> Pregnant women</p>
    <p class="checkbox-line"><span class="checkbox"></span> Prisoners / Detained persons</p>
    <p class="checkbox-line"><span class="checkbox"></span> Persons with mental health conditions</p>
    <p class="checkbox-line"><span class="checkbox"></span> Employees of the researcher's organization</p>
    <p class="checkbox-line"><span class="checkbox"></span> None of the above</p>
</div>

<h4>C.5 If vulnerable groups are involved, what additional safeguards will be put in place?</h4>
<div class="text-area">
</div>

<!-- PAGE 4: Section D - Informed Consent -->
<div class="page-break"></div>

<div class="section-title">SECTION D: INFORMED CONSENT</div>

<h4>D.1 How will informed consent be obtained from participants?</h4>
<div style="margin: 5px 0;">
    <p class="checkbox-line"><span class="checkbox"></span> Written consent form (attach a copy)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Verbal consent (explain why written consent is not possible)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Online consent (for electronic surveys)</p>
    <p class="checkbox-line"><span class="checkbox"></span> Parental / Guardian consent (for minors)</p>
</div>

<h4>D.2 Will participants be given an information sheet explaining the study? <span class="checkbox"></span> Yes &nbsp;&nbsp; <span class="checkbox"></span> No</h4>
<p style="font-size:10pt"><em>(If yes, attach a copy of the participant information sheet)</em></p>

<h4>D.3 Will participants be informed of their right to withdraw at any time? <span class="checkbox"></span> Yes &nbsp;&nbsp; <span class="checkbox"></span> No</h4>

<div class="section-title">SECTION E: RISKS AND BENEFITS</div>

<h4>E.1 Are there any potential risks to participants (physical, psychological, social, economic)?</h4>
<div class="text-area">
</div>

<h4>E.2 How will these risks be minimized?</h4>
<div class="text-area">
</div>

<h4>E.3 Are there direct benefits to participants? If so, describe:</h4>
<div class="text-area">
</div>

<h4>E.4 Will participants receive any compensation or incentive? <span class="checkbox"></span> Yes &nbsp;&nbsp; <span class="checkbox"></span> No</h4>
<p>If yes, describe: ............................................................................................................................</p>

<!-- PAGE 5: Section F - Confidentiality & Data -->
<div class="page-break"></div>

<div class="section-title">SECTION F: CONFIDENTIALITY AND DATA MANAGEMENT</div>

<h4>F.1 How will participant confidentiality and anonymity be ensured?</h4>
<div class="text-area">
</div>

<h4>F.2 How will research data be stored securely?</h4>
<div class="text-area">
</div>

<h4>F.3 Who will have access to the data?</h4>
<div class="text-area" style="min-height:50px">
</div>

<h4>F.4 How long will the data be retained after the study is completed?</h4>
<table class="field-table">
    <tr><td class="label">Data Retention Period:</td><td class="fill-field"></td></tr>
</table>

<h4>F.5 How will data be disposed of after the retention period?</h4>
<div class="text-area" style="min-height:50px">
</div>

<!-- PAGE 6: Section G - Declarations -->
<div class="page-break"></div>

<div class="section-title">SECTION G: DECLARATION BY APPLICANT</div>

<p>I hereby declare that:</p>
<ol>
    <li>The information provided in this application is accurate and complete to the best of my knowledge.</li>
    <li>I will conduct the research in accordance with the ethical principles and guidelines as set out by Exploits University of Malawi.</li>
    <li>I will obtain appropriate informed consent from all research participants.</li>
    <li>I will ensure confidentiality of all data collected during the research.</li>
    <li>I will report any adverse events or ethical concerns arising during the research to the Research Ethics Committee immediately.</li>
    <li>I understand that failure to comply with ethical standards may result in withdrawal of ethics approval and other disciplinary actions.</li>
</ol>

<div class="sig-section">
    <div class="sig-block">
        <p><strong>Student's Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
    </div>
</div>

<br>
<div class="section-title">SECTION H: SUPERVISOR'S RECOMMENDATION</div>

<p>I have reviewed this application and confirm that:</p>
<ol>
    <li>The research methodology is appropriate for the stated objectives.</li>
    <li>The ethical considerations have been adequately addressed.</li>
    <li>I recommend this application for ethics review by the University Research Ethics Committee.</li>
</ol>

<p><strong>Supervisor's Comments:</strong></p>
<div class="text-area">
</div>

<div class="sig-section">
    <div class="sig-block">
        <p><strong>Supervisor's Name:</strong> {$supervisorName}</p>
        <p><strong>Supervisor's Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
    </div>
</div>

<br>
<div class="section-title">SECTION I: ACCOUNTS DEPARTMENT CLEARANCE</div>

<p>I confirm that the above-named student has settled all financial obligations with the university and is cleared to proceed with their research activities.</p>

<div class="sig-section">
    <div class="sig-block">
        <p><strong>Name:</strong> <span class="sig-line"></span></p>
        <p><strong>Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
        <p><strong>Stamp:</strong></p>
        <div style="border: 1px dashed #999; width: 150px; height: 80px; margin: 5px 0;"></div>
    </div>
</div>

<!-- PAGE 7: Ethics Committee Decision -->
<div class="page-break"></div>

<div class="section-title">SECTION J: EXPLOITS UNIVERSITY RESEARCH ETHICS COMMITTEE (EUREC) DECISION</div>

<p><strong>Decision:</strong></p>
<div style="margin: 10px 0;">
    <p class="checkbox-line"><span class="checkbox"></span> <strong>APPROVED</strong> – The research may proceed as described.</p>
    <p class="checkbox-line"><span class="checkbox"></span> <strong>APPROVED WITH CONDITIONS</strong> – Approval is contingent on the conditions noted below.</p>
    <p class="checkbox-line"><span class="checkbox"></span> <strong>REFERRED BACK</strong> – Revisions are required. Please address comments and resubmit.</p>
    <p class="checkbox-line"><span class="checkbox"></span> <strong>NOT APPROVED</strong> – The application does not meet ethical requirements.</p>
</div>

<p><strong>Committee Comments / Conditions:</strong></p>
<div class="text-area-large">
</div>

<div class="sig-section">
    <div class="sig-block">
        <p><strong>Research Coordinator's Name:</strong> <span class="sig-line"></span></p>
        <p><strong>Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
    </div>
    <br>
    <div class="sig-block">
        <p><strong>Registrar's Name:</strong> <span class="sig-line"></span></p>
        <p><strong>Signature &amp; Stamp:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
    </div>
</div>

<br>
<p class="small-text" style="text-align:center; margin-top:20px; border-top: 1px solid #ccc; padding-top:8px;">
    Application No: <strong>{$app_number}</strong> &nbsp;|&nbsp; 
    Generated: {$date_received} &nbsp;|&nbsp; 
    Exploits University of Malawi – Research Ethics Committee
</p>
HTML;

$mpdf->WriteHTML($html);

// Output as downloadable PDF
$filename = 'READF_' . str_replace('/', '_', $data['student_id']) . '_' . date('Ymd') . '.pdf';
ob_end_clean();
$mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('generate_ethics_form error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 1000)]);
}
