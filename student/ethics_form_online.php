<?php
/**
 * Student - Online Ethics Form Submission
 * Allows students to fill the Research Ethics Application for Defense Form online
 * and submit it for supervisor review. Generates a PDF from the submitted data.
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['student', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';
$message = '';
$error = '';

// Get student info
$student = null;
if ($student_id) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
}
if (!$student) { header('Location: dashboard.php'); exit; }

$dissertation_id = (int)($_GET['dissertation_id'] ?? $_POST['dissertation_id'] ?? 0);

// Get dissertation
$dissertation = null;
$stmt = $conn->prepare("
    SELECT d.*, l.full_name AS supervisor_name, l.email AS supervisor_email
    FROM dissertations d
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE d.dissertation_id = ? AND d.student_id = ? AND d.is_active = 1
");
$stmt->bind_param("is", $dissertation_id, $student_id);
$stmt->execute();
$dissertation = $stmt->get_result()->fetch_assoc();

if (!$dissertation) {
    header('Location: dissertation.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_ethics_online') {
    // Collect all form data
    $form_data = [
        'research_description' => trim($_POST['research_description'] ?? ''),
        'start_date' => trim($_POST['start_date'] ?? ''),
        'duration' => trim($_POST['duration'] ?? ''),
        'end_date' => trim($_POST['end_date'] ?? ''),
        'research_location' => trim($_POST['research_location'] ?? ''),
        'data_methods' => $_POST['data_methods'] ?? [],
        'data_methods_other' => trim($_POST['data_methods_other'] ?? ''),
        'target_population' => trim($_POST['target_population'] ?? ''),
        'sample_size' => trim($_POST['sample_size'] ?? ''),
        'sampling_technique' => trim($_POST['sampling_technique'] ?? ''),
        'recruitment_method' => trim($_POST['recruitment_method'] ?? ''),
        'vulnerable_groups' => $_POST['vulnerable_groups'] ?? [],
        'vulnerable_safeguards' => trim($_POST['vulnerable_safeguards'] ?? ''),
        'consent_method' => $_POST['consent_method'] ?? [],
        'info_sheet' => $_POST['info_sheet'] ?? 'no',
        'right_to_withdraw' => $_POST['right_to_withdraw'] ?? 'no',
        'risks' => trim($_POST['risks'] ?? ''),
        'risk_mitigation' => trim($_POST['risk_mitigation'] ?? ''),
        'benefits' => trim($_POST['benefits'] ?? ''),
        'compensation' => $_POST['compensation'] ?? 'no',
        'compensation_detail' => trim($_POST['compensation_detail'] ?? ''),
        'confidentiality' => trim($_POST['confidentiality'] ?? ''),
        'data_storage' => trim($_POST['data_storage'] ?? ''),
        'data_access' => trim($_POST['data_access'] ?? ''),
        'retention_period' => trim($_POST['retention_period'] ?? ''),
        'data_disposal' => trim($_POST['data_disposal'] ?? ''),
    ];

    if (empty($form_data['research_description'])) {
        $error = 'Please provide a description of your research project.';
    } else {
        // Generate application number
        $app_number = 'READF/' . date('Y') . '/' . str_pad($dissertation_id, 4, '0', STR_PAD_LEFT) . '/' . strtoupper(substr(md5(uniqid()), 0, 4));

        // Generate PDF from the form data
        require_once '../vendor/autoload.php';
        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A4',
            'margin_left' => 20, 'margin_right' => 20,
            'margin_top' => 15, 'margin_bottom' => 15,
            'default_font' => 'times', 'default_font_size' => 11,
        ]);

        $studentName = htmlspecialchars($student['full_name'] ?? '');
        $studentEmail = htmlspecialchars($student['email'] ?? '');
        $studentIdStr = htmlspecialchars($student['student_id'] ?? '');
        $supervisorName = htmlspecialchars($dissertation['supervisor_name'] ?? '');
        $campus = htmlspecialchars($student['campus'] ?? 'Main Campus');
        $program = htmlspecialchars($student['program'] ?? $dissertation['program'] ?? '');
        $programType = htmlspecialchars($student['program_type'] ?? $dissertation['program_type'] ?? '');
        $researchTitle = htmlspecialchars($dissertation['title'] ?? '');
        $academicYear = htmlspecialchars($dissertation['academic_year'] ?? date('Y'));
        $dateReceived = date('jS F, Y');

        $logoPath = realpath(__DIR__ . '/../assets/img/Logo.png');
        $logoHtml = ($logoPath && file_exists($logoPath)) ? '<img src="' . $logoPath . '" style="height:60px;">' : '';

        // Helper to format checkbox display
        $chk = function($val, $list) { return in_array($val, $list) ? '&#9745;' : '&#9744;'; };
        $yn = function($val) { return $val === 'yes' ? '&#9745; Yes &nbsp; &#9744; No' : '&#9744; Yes &nbsp; &#9745; No'; };

        $methodsList = '';
        $allMethods = ['questionnaires' => 'Questionnaires / Surveys', 'interviews' => 'Interviews', 'focus_groups' => 'Focus Group Discussions', 'observation' => 'Observation', 'document_analysis' => 'Document / Archival Analysis', 'experiments' => 'Experiments / Tests', 'case_study' => 'Case Study'];
        foreach ($allMethods as $k => $v) {
            $methodsList .= '<p style="margin:3px 0;">' . $chk($k, $form_data['data_methods']) . ' ' . $v . '</p>';
        }
        $otherMethod = !empty($form_data['data_methods_other']) ? '<p style="margin:3px 0;">&#9745; Other: ' . htmlspecialchars($form_data['data_methods_other']) . '</p>' : '<p style="margin:3px 0;">&#9744; Other</p>';

        $vulnList = '';
        $allVuln = ['children' => 'Children (under 18)', 'elderly' => 'Elderly persons', 'disabled' => 'Persons with disabilities', 'pregnant' => 'Pregnant women', 'prisoners' => 'Prisoners / Detained persons', 'mental_health' => 'Persons with mental health conditions', 'employees' => 'Employees of researcher\'s organization', 'none' => 'None of the above'];
        foreach ($allVuln as $k => $v) {
            $vulnList .= '<p style="margin:3px 0;">' . $chk($k, $form_data['vulnerable_groups']) . ' ' . $v . '</p>';
        }

        $consentList = '';
        $allConsent = ['written' => 'Written consent form', 'verbal' => 'Verbal consent', 'online' => 'Online consent', 'parental' => 'Parental / Guardian consent'];
        foreach ($allConsent as $k => $v) {
            $consentList .= '<p style="margin:3px 0;">' . $chk($k, $form_data['consent_method']) . ' ' . $v . '</p>';
        }

        $e = function($key) use ($form_data) { return htmlspecialchars($form_data[$key] ?? ''); };

        $html = <<<HTML
<style>
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; line-height: 1.5; }
    .header { text-align: center; margin-bottom: 10px; }
    .header h1 { font-size: 16pt; margin: 5px 0; }
    .header h2 { font-size: 13pt; margin: 3px 0; }
    .section-title { background: #e0e0e0; padding: 8px 12px; font-weight: bold; font-size: 11pt; margin: 15px 0 5px 0; border: 1px solid #999; }
    .field-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
    .field-table td { padding: 5px 8px; font-size: 10.5pt; border-bottom: 1px dotted #999; vertical-align: top; }
    .field-table .label { font-weight: bold; width: 240px; border-bottom: 1px solid #ccc; }
    .field-table .value { color: #000080; }
    .answer-box { border: 1px solid #ccc; padding: 8px; margin: 5px 0; min-height: 50px; background: #fafafa; }
    h4 { font-size: 11pt; margin: 12px 0 5px 0; }
    .sig-line { border-bottom: 1px solid #000; width: 60%; height: 25px; display: inline-block; }
    .sig-date { border-bottom: 1px solid #000; width: 25%; height: 25px; display: inline-block; margin-left: 10px; }
    .small-text { font-size: 9pt; color: #555; }
</style>

<div class="header">
    {$logoHtml}
    <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
    <h2>RESEARCH ETHICS APPLICATION FOR DEFENCE FORM (READF)</h2>
</div>

<table style="width:100%"><tr>
    <td style="text-align:right;width:70%"><strong>Application Number:</strong></td>
    <td style="color:#000080;font-weight:bold">{$app_number}</td>
</tr><tr>
    <td style="text-align:right"><strong>Date:</strong></td>
    <td style="color:#000080">{$dateReceived}</td>
</tr></table>

<div class="section-title">SECTION A: APPLICANT DETAILS</div>
<table class="field-table">
    <tr><td class="label">Student's Name:</td><td class="value">{$studentName}</td></tr>
    <tr><td class="label">Student's E-mail:</td><td class="value">{$studentEmail}</td></tr>
    <tr><td class="label">Student's ID #:</td><td class="value">{$studentIdStr}</td></tr>
    <tr><td class="label">Supervisor's Name:</td><td class="value">{$supervisorName}</td></tr>
    <tr><td class="label">University Campus:</td><td class="value">{$campus}</td></tr>
    <tr><td class="label">Program of Study:</td><td class="value">{$program} ({$programType})</td></tr>
    <tr><td class="label">Academic Year:</td><td class="value">{$academicYear}</td></tr>
    <tr><td class="label">Research Project Title:</td><td class="value"><strong>{$researchTitle}</strong></td></tr>
</table>

<div class="section-title">SECTION B: RESEARCH PROJECT OVERVIEW</div>
<h4>B.1 Brief description of the research project:</h4>
<div class="answer-box">{$e('research_description')}</div>
<h4>B.2 Research Timeline:</h4>
<table class="field-table">
    <tr><td class="label">Start Date:</td><td class="value">{$e('start_date')}</td></tr>
    <tr><td class="label">Duration:</td><td class="value">{$e('duration')}</td></tr>
    <tr><td class="label">End Date:</td><td class="value">{$e('end_date')}</td></tr>
</table>
<h4>B.3 Research Location:</h4>
<div class="answer-box">{$e('research_location')}</div>
<h4>B.4 Data Collection Methods:</h4>
{$methodsList}{$otherMethod}

<div class="section-title">SECTION C: RESEARCH PARTICIPANTS</div>
<h4>C.1 Target Population:</h4>
<div class="answer-box">{$e('target_population')}</div>
<h4>C.2 Sample Details:</h4>
<table class="field-table">
    <tr><td class="label">Sample Size:</td><td class="value">{$e('sample_size')}</td></tr>
    <tr><td class="label">Sampling Technique:</td><td class="value">{$e('sampling_technique')}</td></tr>
</table>
<h4>C.3 Recruitment Method:</h4>
<div class="answer-box">{$e('recruitment_method')}</div>
<h4>C.4 Vulnerable Groups:</h4>
{$vulnList}
<h4>C.5 Safeguards for Vulnerable Groups:</h4>
<div class="answer-box">{$e('vulnerable_safeguards')}</div>

<div class="section-title">SECTION D: INFORMED CONSENT</div>
<h4>D.1 Consent Method:</h4>
{$consentList}
<h4>D.2 Information sheet provided: {$yn($form_data['info_sheet'])}</h4>
<h4>D.3 Right to withdraw explained: {$yn($form_data['right_to_withdraw'])}</h4>

<div class="section-title">SECTION E: RISKS AND BENEFITS</div>
<h4>E.1 Potential Risks:</h4>
<div class="answer-box">{$e('risks')}</div>
<h4>E.2 Risk Mitigation:</h4>
<div class="answer-box">{$e('risk_mitigation')}</div>
<h4>E.3 Benefits to participants:</h4>
<div class="answer-box">{$e('benefits')}</div>
<h4>E.4 Compensation: {$yn($form_data['compensation'])} {$e('compensation_detail')}</h4>

<div class="section-title">SECTION F: CONFIDENTIALITY AND DATA MANAGEMENT</div>
<h4>F.1 Confidentiality measures:</h4>
<div class="answer-box">{$e('confidentiality')}</div>
<h4>F.2 Data storage:</h4>
<div class="answer-box">{$e('data_storage')}</div>
<h4>F.3 Data access:</h4>
<div class="answer-box">{$e('data_access')}</div>
<h4>F.4 Retention period:</h4>
<div class="answer-box">{$e('retention_period')}</div>
<h4>F.5 Data disposal:</h4>
<div class="answer-box">{$e('data_disposal')}</div>

<div class="section-title">SECTION G: DECLARATION BY APPLICANT</div>
<p>I hereby declare that the information provided is accurate. I will conduct the research in accordance with ethical principles of Exploits University of Malawi.</p>
<p><strong>Student:</strong> {$studentName} &nbsp;&nbsp; <strong>Date:</strong> {$dateReceived}</p>

<div class="section-title">SECTION H: SUPERVISOR'S RECOMMENDATION</div>
<p><strong>Supervisor's Name:</strong> {$supervisorName}</p>
<p><strong>Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>

<div class="section-title">SECTION I: ACCOUNTS DEPARTMENT CLEARANCE</div>
<p><strong>Name:</strong> <span class="sig-line"></span></p>
<p><strong>Signature:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>

<div class="section-title">SECTION J: EUREC DECISION</div>
<p>&#9744; APPROVED &nbsp; &#9744; APPROVED WITH CONDITIONS &nbsp; &#9744; REFERRED BACK &nbsp; &#9744; NOT APPROVED</p>
<p><strong>Comments:</strong></p>
<div class="answer-box" style="min-height:60px"></div>
<p><strong>Research Coordinator:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>
<p><strong>Registrar:</strong> <span class="sig-line"></span> <strong>Date:</strong> <span class="sig-date"></span></p>

<p class="small-text" style="text-align:center;margin-top:15px;border-top:1px solid #ccc;padding-top:8px;">
    Application No: <strong>{$app_number}</strong> | Generated: {$dateReceived} | Exploits University of Malawi
</p>
HTML;

        $mpdf->WriteHTML($html);

        // Save PDF to uploads
        $upload_dir = '../uploads/dissertations/ethics/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $safe_id = str_replace('/', '_', $student_id);
        $pdfFilename = 'readf_' . $safe_id . '_' . time() . '.pdf';
        $pdfPath = $upload_dir . $pdfFilename;
        $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
        $dbPath = 'uploads/dissertations/ethics/' . $pdfFilename;

        // Save to dissertation_ethics table
        $uid = $_SESSION['vle_user_id'] ?? 0;
        $summary = substr($form_data['research_description'], 0, 1000);
        
        // Auto-create columns if needed
        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS submitted_by INT DEFAULT NULL");
        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS research_summary TEXT DEFAULT NULL");
        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS application_number VARCHAR(50) DEFAULT NULL");
        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS form_data JSON DEFAULT NULL");

        $json = json_encode($form_data, JSON_UNESCAPED_UNICODE);
        $ins = $conn->prepare("
            INSERT INTO dissertation_ethics (dissertation_id, submitted_by, ethics_form_path, research_summary, application_number, form_data, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $ins->bind_param("iissss", $dissertation_id, $uid, $dbPath, $summary, $app_number, $json);

        if ($ins->execute()) {
            $conn->query("UPDATE dissertations SET status = 'ethics_submitted', updated_at = NOW() WHERE dissertation_id = " . (int)$dissertation_id);
            $message = "Ethics application submitted successfully! Application No: <strong>$app_number</strong>";
        } else {
            $error = 'Failed to save ethics application. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ethics Form - Online Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .form-section { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .form-section h5 { color: #1a237e; border-bottom: 2px solid #1a237e; padding-bottom: 8px; margin-bottom: 15px; }
        .form-section h6 { color: #333; margin-top: 15px; }
        .prefilled { background: #e8f5e9; border-color: #a5d6a7; }
        .prefilled-badge { font-size: 0.7rem; }
    </style>
</head>
<body>

<?php include 'header_nav.php'; ?>

<div class="container py-4">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <!-- Header -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h4 class="mb-1"><i class="bi bi-file-earmark-medical me-2"></i>Research Ethics Application Form (READF)</h4>
                    <p class="text-muted mb-0">Complete all sections below. Pre-filled fields are from your dissertation record.</p>
                </div>
                <a href="dissertation.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-1"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <div class="mt-2">
                    <a href="dissertation.php" class="btn btn-sm btn-success">Return to Dissertation</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$message): ?>
            <form method="POST">
                <input type="hidden" name="action" value="submit_ethics_online">
                <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">

                <!-- Section A: Pre-filled Applicant Details -->
                <div class="form-section prefilled">
                    <h5><i class="bi bi-person me-2"></i>Section A: Applicant Details <span class="badge bg-success prefilled-badge ms-2">Auto-filled from system</span></h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Student's Name</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($student['full_name'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Student's Email</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($student['email'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Student ID #</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($student['student_id'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Supervisor</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($dissertation['supervisor_name'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Campus</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($student['campus'] ?? 'Main Campus') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Program of Study</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars(($student['program'] ?? '') . ' (' . ($student['program_type'] ?? '') . ')') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Research Title</label>
                            <input type="text" class="form-control fw-bold" value="<?= htmlspecialchars($dissertation['title'] ?? '') ?>" readonly>
                        </div>
                    </div>
                </div>

                <!-- Section B: Research Overview -->
                <div class="form-section">
                    <h5><i class="bi bi-journal-text me-2"></i>Section B: Research Project Overview</h5>
                    
                    <h6>B.1 Brief description of the research project (aims, objectives, methodology) <span class="text-danger">*</span></h6>
                    <textarea name="research_description" class="form-control mb-3" rows="5" required placeholder="Describe your research aims, objectives, and methodology..."></textarea>

                    <h6>B.2 Research Timeline</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Duration</label>
                            <input type="text" name="duration" class="form-control" placeholder="e.g. 3 months">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>

                    <h6>B.3 Where will the research be conducted?</h6>
                    <textarea name="research_location" class="form-control mb-3" rows="2" placeholder="Location(s) where data collection will take place..."></textarea>

                    <h6>B.4 Data collection methods (select all that apply)</h6>
                    <div class="row g-2 mb-3">
                        <?php
                        $methods = ['questionnaires' => 'Questionnaires / Surveys', 'interviews' => 'Interviews', 'focus_groups' => 'Focus Group Discussions', 'observation' => 'Observation', 'document_analysis' => 'Document / Archival Analysis', 'experiments' => 'Experiments / Tests', 'case_study' => 'Case Study'];
                        foreach ($methods as $k => $v): ?>
                        <div class="col-md-6"><div class="form-check">
                            <input class="form-check-input" type="checkbox" name="data_methods[]" value="<?= $k ?>" id="dm_<?= $k ?>">
                            <label class="form-check-label" for="dm_<?= $k ?>"><?= $v ?></label>
                        </div></div>
                        <?php endforeach; ?>
                        <div class="col-md-6">
                            <input type="text" name="data_methods_other" class="form-control form-control-sm" placeholder="Other (specify)">
                        </div>
                    </div>
                </div>

                <!-- Section C: Participants -->
                <div class="form-section">
                    <h5><i class="bi bi-people me-2"></i>Section C: Research Participants</h5>
                    
                    <h6>C.1 Who are the participants? Describe the target population:</h6>
                    <textarea name="target_population" class="form-control mb-3" rows="3" placeholder="Describe your target population..."></textarea>

                    <h6>C.2 Sample Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Expected Sample Size</label>
                            <input type="text" name="sample_size" class="form-control" placeholder="e.g. 150 respondents">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sampling Technique</label>
                            <input type="text" name="sampling_technique" class="form-control" placeholder="e.g. Stratified random sampling">
                        </div>
                    </div>

                    <h6>C.3 How will participants be recruited?</h6>
                    <textarea name="recruitment_method" class="form-control mb-3" rows="2" placeholder="Describe recruitment method..."></textarea>

                    <h6>C.4 Vulnerable groups involved (select if applicable)</h6>
                    <div class="row g-2 mb-3">
                        <?php
                        $vulns = ['children' => 'Children (under 18)', 'elderly' => 'Elderly persons', 'disabled' => 'Persons with disabilities', 'pregnant' => 'Pregnant women', 'prisoners' => 'Prisoners / Detained', 'mental_health' => 'Mental health conditions', 'employees' => 'Employees of own organization', 'none' => 'None of the above'];
                        foreach ($vulns as $k => $v): ?>
                        <div class="col-md-6"><div class="form-check">
                            <input class="form-check-input" type="checkbox" name="vulnerable_groups[]" value="<?= $k ?>" id="vg_<?= $k ?>">
                            <label class="form-check-label" for="vg_<?= $k ?>"><?= $v ?></label>
                        </div></div>
                        <?php endforeach; ?>
                    </div>

                    <h6>C.5 Safeguards for vulnerable groups (if applicable)</h6>
                    <textarea name="vulnerable_safeguards" class="form-control mb-3" rows="2" placeholder="Additional safeguards..."></textarea>
                </div>

                <!-- Section D: Informed Consent -->
                <div class="form-section">
                    <h5><i class="bi bi-hand-thumbs-up me-2"></i>Section D: Informed Consent</h5>
                    
                    <h6>D.1 How will informed consent be obtained?</h6>
                    <div class="row g-2 mb-3">
                        <?php
                        $consents = ['written' => 'Written consent form', 'verbal' => 'Verbal consent', 'online' => 'Online consent', 'parental' => 'Parental / Guardian consent'];
                        foreach ($consents as $k => $v): ?>
                        <div class="col-md-6"><div class="form-check">
                            <input class="form-check-input" type="checkbox" name="consent_method[]" value="<?= $k ?>" id="cm_<?= $k ?>">
                            <label class="form-check-label" for="cm_<?= $k ?>"><?= $v ?></label>
                        </div></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">D.2 Information sheet provided?</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="info_sheet" value="yes" id="is_yes">
                                    <label class="form-check-label" for="is_yes">Yes</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="info_sheet" value="no" id="is_no" checked>
                                    <label class="form-check-label" for="is_no">No</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">D.3 Right to withdraw explained?</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="right_to_withdraw" value="yes" id="rw_yes">
                                    <label class="form-check-label" for="rw_yes">Yes</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="right_to_withdraw" value="no" id="rw_no" checked>
                                    <label class="form-check-label" for="rw_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section E: Risks and Benefits -->
                <div class="form-section">
                    <h5><i class="bi bi-exclamation-triangle me-2"></i>Section E: Risks and Benefits</h5>
                    
                    <h6>E.1 Potential risks to participants (physical, psychological, social, economic)</h6>
                    <textarea name="risks" class="form-control mb-3" rows="3" placeholder="Describe any potential risks..."></textarea>

                    <h6>E.2 How will risks be minimized?</h6>
                    <textarea name="risk_mitigation" class="form-control mb-3" rows="3" placeholder="Describe risk mitigation measures..."></textarea>

                    <h6>E.3 Direct benefits to participants</h6>
                    <textarea name="benefits" class="form-control mb-3" rows="2" placeholder="Describe any benefits..."></textarea>

                    <h6>E.4 Compensation</h6>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="compensation" value="yes" id="comp_yes">
                            <label class="form-check-label" for="comp_yes">Yes</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="compensation" value="no" id="comp_no" checked>
                            <label class="form-check-label" for="comp_no">No</label>
                        </div>
                        <input type="text" name="compensation_detail" class="form-control form-control-sm" style="max-width:300px" placeholder="If yes, describe...">
                    </div>
                </div>

                <!-- Section F: Confidentiality -->
                <div class="form-section">
                    <h5><i class="bi bi-shield-lock me-2"></i>Section F: Confidentiality and Data Management</h5>
                    
                    <h6>F.1 How will confidentiality and anonymity be ensured?</h6>
                    <textarea name="confidentiality" class="form-control mb-3" rows="3" placeholder="Describe confidentiality measures..."></textarea>

                    <h6>F.2 How will research data be stored securely?</h6>
                    <textarea name="data_storage" class="form-control mb-3" rows="2" placeholder="Describe data storage measures..."></textarea>

                    <h6>F.3 Who will have access to the data?</h6>
                    <textarea name="data_access" class="form-control mb-3" rows="2" placeholder="List who will have access..."></textarea>

                    <h6>F.4 Data retention period</h6>
                    <input type="text" name="retention_period" class="form-control mb-3" placeholder="e.g. 5 years after completion of study">

                    <h6>F.5 How will data be disposed of?</h6>
                    <textarea name="data_disposal" class="form-control mb-3" rows="2" placeholder="Describe data disposal method..."></textarea>
                </div>

                <!-- Declaration -->
                <div class="form-section" style="background:#fff3cd; border-color:#ffc107;">
                    <h5><i class="bi bi-pen me-2"></i>Section G: Declaration</h5>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="declaration" required>
                        <label class="form-check-label" for="declaration">
                            I hereby declare that the information provided in this application is accurate and complete. I will conduct the research in accordance with the ethical principles and guidelines of Exploits University of Malawi. I understand that failure to comply may result in withdrawal of ethics approval.
                        </label>
                    </div>
                </div>

                <!-- Submit -->
                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send me-2"></i>Submit Ethics Application
                    </button>
                    <a href="dissertation.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
