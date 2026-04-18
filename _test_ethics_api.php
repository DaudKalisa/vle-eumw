<?php
// Full end-to-end test of ethics form PDF generation (mimicking generate_ethics_form.php)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/config.php';
$conn = getDbConnection();

$dissertation_id = 1;

// Exact query from generate_ethics_form.php
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

if (!$data) { echo "No dissertation found\n"; exit; }

$app_number = 'READF/' . date('Y') . '/' . str_pad($dissertation_id, 4, '0', STR_PAD_LEFT) . '/' . strtoupper(substr(md5(uniqid()), 0, 4));
$date_received = date('jS F, Y');

require_once __DIR__ . '/vendor/autoload.php';

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 15,
        'margin_bottom' => 15,
        'default_font' => 'times',
        'default_font_size' => 11,
    ]);
    echo "mPDF initialized OK\n";
} catch (Exception $e) {
    echo "mPDF init error: " . $e->getMessage() . "\n";
    exit;
}

$mpdf->SetTitle('Research Ethics Application - ' . htmlspecialchars($data['student_name'] ?? ''));
$mpdf->SetAuthor('Exploits University of Malawi');

$studentName = htmlspecialchars($data['student_name'] ?? '');
$studentEmail = htmlspecialchars($data['student_email'] ?? '');
$studentIdStr = htmlspecialchars($data['student_id'] ?? '');
$supervisorName = htmlspecialchars($data['supervisor_name'] ?? '');
$campus = htmlspecialchars($data['campus'] ?? 'Main Campus');
$program = htmlspecialchars($data['student_program'] ?? $data['program'] ?? '');
$programType = htmlspecialchars($data['program_type'] ?? '');
$researchTitle = htmlspecialchars($data['title'] ?? '');
$academicYear = htmlspecialchars($data['academic_year'] ?? date('Y'));

$logoPath = realpath(__DIR__ . '/assets/img/Logo.png');
$logoHtml = '';
if ($logoPath && file_exists($logoPath)) {
    $logoHtml = '<img src="' . $logoPath . '" style="height:60px;">';
    echo "Logo loaded: $logoPath\n";
}

echo "Building HTML...\n";
$html = '<h1>Test - ' . $studentName . '</h1><p>Title: ' . $researchTitle . '</p>';

try {
    $mpdf->WriteHTML($html);
    echo "WriteHTML OK\n";
} catch (Exception $e) {
    echo "WriteHTML error: " . $e->getMessage() . "\n";
    exit;
}

// Now try the full HTML from the actual file
$mpdf2 = new \Mpdf\Mpdf([
    'format' => 'A4',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font' => 'times',
    'default_font_size' => 11,
]);

// Full HTML from generate_ethics_form.php (cover page + section A only for test)
$fullHtml = <<<HTML
<style>
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; line-height: 1.5; }
    .header { text-align: center; margin-bottom: 10px; }
    .header h1 { font-size: 16pt; margin: 5px 0; letter-spacing: 1px; }
    .section-title { background: #e0e0e0; padding: 8px 12px; font-weight: bold; }
    .field-table { width: 100%; border-collapse: collapse; }
    .field-table td { padding: 6px 8px; font-size: 10.5pt; border-bottom: 1px dotted #999; }
    .field-table .label { font-weight: bold; width: 240px; }
    .field-table .value { color: #000080; }
</style>
<div class="header">
    {$logoHtml}
    <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
    <h2>RESEARCH ETHICS APPLICATION FOR DEFENCE FORM</h2>
</div>
<div class="section-title">SECTION A: APPLICANT DETAILS</div>
<table class="field-table">
    <tr><td class="label">Student's Name:</td><td class="value">{$studentName}</td></tr>
    <tr><td class="label">Email:</td><td class="value">{$studentEmail}</td></tr>
    <tr><td class="label">Student ID:</td><td class="value">{$studentIdStr}</td></tr>
    <tr><td class="label">Supervisor:</td><td class="value">{$supervisorName}</td></tr>
    <tr><td class="label">Campus:</td><td class="value">{$campus}</td></tr>
    <tr><td class="label">Program:</td><td class="value">{$program} ({$programType})</td></tr>
    <tr><td class="label">Title:</td><td class="value"><strong>{$researchTitle}</strong></td></tr>
</table>
HTML;

try {
    $mpdf2->WriteHTML($fullHtml);
    $output = $mpdf2->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    echo "Full PDF generated OK! Size: " . strlen($output) . " bytes\n";
} catch (Exception $e) {
    echo "Full PDF error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nAll tests passed!\n";
