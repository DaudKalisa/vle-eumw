<?php
/**
 * Generate Graduation Transcript PDF
 * Shows module grades grouped by academic year with GPA and classification
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/transcript_errors.log');
ob_start();
session_start();
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();

$app_id = (int)($_GET['app_id'] ?? 0);
if (!$app_id) {
    http_response_code(400);
    exit('Missing app_id');
}

// Load application
$stmt = $conn->prepare("SELECT ga.*, ggs.gpa, ggs.classification, ggs.total_credits FROM graduation_applications ga LEFT JOIN graduation_grade_summary ggs ON ga.application_id = ggs.application_id WHERE ga.application_id = ?");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

if (!$app) {
    http_response_code(404);
    exit('Application not found');
}

// Access control
$uid = (int)$user['user_id'];
$is_admin = in_array($user['role'], ['admin', 'staff', 'super_admin']);
if ($app['user_id'] !== $uid && !$is_admin) {
    http_response_code(403);
    exit('Access denied');
}

// Load modules grouped by academic year
$modules = [];
$mr = $conn->query("SELECT * FROM graduation_ict_modules WHERE application_id = $app_id ORDER BY year_of_study ASC, module_code ASC");
if ($mr) while ($m = $mr->fetch_assoc()) $modules[(int)$m['year_of_study']][] = $m;

// Build PDF
require_once __DIR__ . '/../vendor/autoload.php';

$mpdfTempDir = dirname(__DIR__) . '/uploads/mpdf_tmp';
if (!is_dir($mpdfTempDir)) mkdir($mpdfTempDir, 0777, true);

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_left' => 20, 'margin_right' => 20,
        'margin_top' => 15, 'margin_bottom' => 15,
        'default_font' => 'times', 'default_font_size' => 10,
        'tempDir' => $mpdfTempDir,
    ]);

    $mpdf->SetTitle('Academic Transcript');
    $mpdf->SetAuthor('Exploits University of Malawi');

    $full_name = htmlspecialchars(trim($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']));
    $student_id = htmlspecialchars($app['student_id_number'] ?? 'N/A');
    $campus = htmlspecialchars($app['campus'] ?? 'N/A');
    $program = htmlspecialchars($app['program'] ?? 'N/A');
    $yr_entry = $app['year_of_entry'] ?? 'N/A';
    $yr_comp  = $app['year_of_completion'] ?? 'N/A';
    $classification = htmlspecialchars($app['classification'] ?? 'N/A');
    $gpa = $app['gpa'] ? number_format($app['gpa'], 2) : 'N/A';
    $total_credits = $app['total_credits'] ?? 'N/A';
    $print_date = date('jS F, Y');
    $ref_no = 'TR/' . date('Y') . '/' . str_pad($app_id, 5, '0', STR_PAD_LEFT);

    $logoPath = realpath(__DIR__ . '/../assets/img/Logo.png');
    $logoHtml = ($logoPath && file_exists($logoPath)) ? '<img src="' . $logoPath . '" style="height:60px;">' : '';

    // Build module tables
    $moduleHtml = '';
    foreach ($modules as $year => $mods) {
        $year_esc = htmlspecialchars($year);
        $moduleHtml .= '<h4 style="margin:12px 0 5px;color:#1a1a2e;">Academic Year: ' . $year_esc . '</h4>';
        $moduleHtml .= '<table class="mod-table"><thead><tr><th style="width:15%;">Code</th><th style="width:45%;">Module Name</th><th style="width:12%;text-align:center;">Marks</th><th style="width:12%;text-align:center;">Grade</th><th style="width:16%;text-align:center;">Grade Point</th></tr></thead><tbody>';
        $year_total_gp = 0;
        $year_count = 0;
        foreach ($mods as $m) {
            $moduleHtml .= '<tr>';
            $moduleHtml .= '<td>' . htmlspecialchars($m['module_code']) . '</td>';
            $moduleHtml .= '<td>' . htmlspecialchars($m['module_name']) . '</td>';
            $moduleHtml .= '<td style="text-align:center;">' . (int)$m['marks'] . '</td>';
            $moduleHtml .= '<td style="text-align:center;">' . htmlspecialchars($m['grade']) . '</td>';
            $moduleHtml .= '<td style="text-align:center;">' . number_format((float)$m['grade_point'], 1) . '</td>';
            $moduleHtml .= '</tr>';
            $year_total_gp += (float)$m['grade_point'];
            $year_count++;
        }
        $year_gpa = $year_count > 0 ? number_format($year_total_gp / $year_count, 2) : '0.00';
        $moduleHtml .= '<tr class="year-total"><td colspan="4" style="text-align:right;font-weight:bold;">Year GPA:</td><td style="text-align:center;font-weight:bold;">' . $year_gpa . '</td></tr>';
        $moduleHtml .= '</tbody></table>';
    }

    if (empty($modules)) {
        $moduleHtml = '<p style="color:#888;text-align:center;padding:20px;">No module records found.</p>';
    }

    $html = <<<HTML
<style>
    body { font-family: 'Times New Roman', serif; font-size: 10pt; line-height: 1.5; }
    .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #1a1a2e; padding-bottom: 10px; }
    .header h1 { font-size: 16pt; margin: 5px 0; color: #1a1a2e; }
    .header h2 { font-size: 12pt; margin: 3px 0; color: #4a4a6a; }
    .info-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    .info-table td { padding: 3px 5px; font-size: 10pt; }
    .info-label { font-weight: bold; width: 30%; color: #333; }
    .mod-table { width: 100%; border-collapse: collapse; margin: 5px 0; font-size: 9pt; }
    .mod-table th { background: #1a1a2e; color: #fff; padding: 5px 8px; text-align: left; font-size: 9pt; }
    .mod-table td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
    .mod-table tr:nth-child(even) td { background: #f8f8ff; }
    .year-total td { background: #e8e8f0 !important; border-top: 2px solid #1a1a2e; }
    .summary-box { border: 2px solid #1a1a2e; padding: 10px 20px; margin: 15px 0; text-align: center; }
    .summary-box .gpa { font-size: 16pt; font-weight: bold; color: #1a1a2e; }
    .unofficial { color: #cc0000; text-align: center; font-weight: bold; font-size: 11pt; margin: 10px 0; }
</style>

<div class="header">
    {$logoHtml}
    <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
    <h2>ACADEMIC TRANSCRIPT</h2>
    <div style="font-size:8pt;color:#888;">Reference: {$ref_no}</div>
</div>

<div class="unofficial">*** UNOFFICIAL TRANSCRIPT ***</div>

<table class="info-table">
    <tr>
        <td class="info-label">Student Name:</td>
        <td>{$full_name}</td>
        <td class="info-label">Student ID:</td>
        <td>{$student_id}</td>
    </tr>
    <tr>
        <td class="info-label">Programme:</td>
        <td>{$program}</td>
        <td class="info-label">Campus:</td>
        <td>{$campus}</td>
    </tr>
    <tr>
        <td class="info-label">Year of Entry:</td>
        <td>{$yr_entry}</td>
        <td class="info-label">Year of Completion:</td>
        <td>{$yr_comp}</td>
    </tr>
</table>

<hr style="border:1px solid #ddd;">

{$moduleHtml}

<div class="summary-box">
    <div style="font-size:10pt;color:#666;">Cumulative Grade Point Average</div>
    <div class="gpa">{$gpa}</div>
    <div style="font-size:11pt;margin-top:5px;">Classification: <strong>{$classification}</strong></div>
    <div style="font-size:9pt;color:#888;margin-top:3px;">Total Credits: {$total_credits}</div>
</div>

<div style="text-align:center;margin-top:20px;font-size:9pt;color:#888;">
    <p>This is an electronically generated transcript from the Exploits University VLE System.</p>
    <p>Printed on: {$print_date} | Reference: {$ref_no}</p>
</div>
HTML;

    $mpdf->WriteHTML($html);
    ob_end_clean();
    $mpdf->Output('Transcript_' . $app_id . '.pdf', 'I');

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('Transcript generation error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error generating transcript: ' . htmlspecialchars($e->getMessage());
}
