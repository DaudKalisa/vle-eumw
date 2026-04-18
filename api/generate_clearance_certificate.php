<?php
/**
 * Generate Graduation Clearance Certificate PDF
 * Uses mPDF to create a certificate with all officer signatures
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/clearance_cert_errors.log');
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

// Only completed applications or admin access
$uid = (int)$user['user_id'];
$is_admin = in_array($user['role'], ['admin', 'staff', 'super_admin']);
if ($app['status'] !== 'completed' && !$is_admin) {
    http_response_code(403);
    exit('Clearance not yet completed');
}
if ($app['user_id'] !== $uid && !$is_admin) {
    http_response_code(403);
    exit('Access denied');
}

// Load clearance steps with signatures
$steps = [];
$sr = $conn->query("SELECT * FROM graduation_clearance_steps WHERE application_id = $app_id AND status = 'approved' ORDER BY step_id ASC");
if ($sr) while ($s = $sr->fetch_assoc()) $steps[$s['step_name']] = $s;

// Build PDF
require_once __DIR__ . '/../vendor/autoload.php';

$mpdfTempDir = dirname(__DIR__) . '/uploads/mpdf_tmp';
if (!is_dir($mpdfTempDir)) mkdir($mpdfTempDir, 0777, true);

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_left' => 20, 'margin_right' => 20,
        'margin_top' => 15, 'margin_bottom' => 15,
        'default_font' => 'times', 'default_font_size' => 11,
        'tempDir' => $mpdfTempDir,
    ]);

    $mpdf->SetTitle('Graduation Clearance Certificate');
    $mpdf->SetAuthor('Exploits University of Malawi');

    $full_name = htmlspecialchars(trim($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']));
    $student_id = htmlspecialchars($app['student_id_number'] ?? 'N/A');
    $campus = htmlspecialchars($app['campus'] ?? 'N/A');
    $program = htmlspecialchars($app['program'] ?? 'N/A');
    $yr_entry = $app['year_of_entry'] ?? 'N/A';
    $yr_comp  = $app['year_of_completion'] ?? 'N/A';
    $classification = htmlspecialchars($app['classification'] ?? 'N/A');
    $gpa = $app['gpa'] ? number_format($app['gpa'], 2) : 'N/A';
    $cert_date = date('jS F, Y');
    $cert_no = 'GCC/' . date('Y') . '/' . str_pad($app_id, 5, '0', STR_PAD_LEFT);

    $logoPath = realpath(__DIR__ . '/../assets/img/Logo.png');
    $logoHtml = ($logoPath && file_exists($logoPath)) ? '<img src="' . $logoPath . '" style="height:70px;">' : '';

    // Build signatures HTML
    $step_titles = [
        'finance'    => 'Finance Officer',
        'ict'        => 'ICT Officer',
        'dean'       => 'Dean',
        'rc'         => 'Research Coordinator',
        'librarian'  => 'Librarian',
        'admin'      => 'Administrator',
        'registrar'  => 'Registrar / Principal',
        'admissions' => 'Admissions Officer',
    ];

    $sig_rows = '';
    $sig_count = 0;
    foreach ($step_titles as $sn => $title) {
        if (!isset($steps[$sn])) continue;
        $s = $steps[$sn];
        $name = htmlspecialchars($s['officer_name'] ?? '');
        $date = $s['actioned_at'] ? date('d/m/Y', strtotime($s['actioned_at'])) : '';
        if ($sig_count % 2 === 0) $sig_rows .= '<tr>';
        $sig_rows .= '<td style="width:50%;padding:10px 15px;vertical-align:top;">';
        $sig_rows .= '<div style="font-weight:bold;font-size:9pt;color:#666;">' . $title . '</div>';
        $sig_rows .= '<div style="border-bottom:1px solid #000;height:25px;margin:5px 0;font-style:italic;">' . $name . '</div>';
        $sig_rows .= '<div style="font-size:8pt;color:#888;">Date: ' . $date . '</div>';
        $sig_rows .= '</td>';
        if ($sig_count % 2 === 1) $sig_rows .= '</tr>';
        $sig_count++;
    }
    if ($sig_count % 2 === 1) $sig_rows .= '<td></td></tr>';

    $html = <<<HTML
<style>
    body { font-family: 'Times New Roman', serif; font-size: 11pt; line-height: 1.6; }
    .header { text-align: center; margin-bottom: 15px; }
    .header h1 { font-size: 18pt; margin: 5px 0; color: #1a1a2e; }
    .header h2 { font-size: 14pt; margin: 3px 0; color: #059669; }
    .cert-border { border: 3px double #059669; padding: 30px; margin: 10px; }
    .field-row { margin: 8px 0; }
    .field-label { font-weight: bold; display: inline; }
    .field-value { color: #000080; display: inline; }
    .sig-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .classification-box { text-align: center; margin: 20px 0; padding: 10px; border: 2px solid #059669; background: #f0fdf4; }
    .classification-box .cls { font-size: 18pt; font-weight: bold; color: #059669; }
    .watermark { position: absolute; top: 40%; left: 20%; font-size: 80pt; color: rgba(5,150,105,0.05); transform: rotate(-30deg); z-index: -1; }
</style>

<div class="watermark">CLEARED</div>

<div class="cert-border">
    <div class="header">
        {$logoHtml}
        <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
        <h2>GRADUATION CLEARANCE CERTIFICATE</h2>
        <div style="font-size:9pt;color:#888;">Certificate No: {$cert_no}</div>
    </div>

    <p style="text-align:center;font-size:12pt;">This is to certify that</p>

    <div style="text-align:center;margin:15px 0;">
        <div style="font-size:16pt;font-weight:bold;color:#1a1a2e;border-bottom:2px solid #000;display:inline-block;padding:5px 40px;">
            {$full_name}
        </div>
    </div>

    <p style="text-align:center;">has successfully completed the graduation clearance process at Exploits University of Malawi.</p>

    <table style="width:100%;border-collapse:collapse;margin:15px 0;">
        <tr>
            <td style="width:50%;padding:5px;"><span class="field-label">Student ID:</span> <span class="field-value">{$student_id}</span></td>
            <td style="width:50%;padding:5px;"><span class="field-label">Campus:</span> <span class="field-value">{$campus}</span></td>
        </tr>
        <tr>
            <td style="padding:5px;"><span class="field-label">Program:</span> <span class="field-value">{$program}</span></td>
            <td style="padding:5px;"><span class="field-label">Academic Period:</span> <span class="field-value">{$yr_entry} – {$yr_comp}</span></td>
        </tr>
    </table>

    <div class="classification-box">
        <div style="font-size:10pt;color:#666;">Overall Classification</div>
        <div class="cls">{$classification}</div>
        <div style="font-size:10pt;">GPA: {$gpa}</div>
    </div>

    <p style="font-size:10pt;text-align:center;color:#555;">
        The student has been cleared by all relevant departments as indicated by the signatures below.
    </p>

    <table class="sig-table">
        {$sig_rows}
    </table>

    <div style="text-align:center;margin-top:25px;padding-top:10px;border-top:1px solid #ccc;">
        <div style="font-size:9pt;color:#888;">Issued on: {$cert_date}</div>
        <div style="font-size:8pt;color:#aaa;margin-top:5px;">
            This document is electronically generated by the Exploits University VLE System.
            Certificate No: {$cert_no}
        </div>
    </div>
</div>
HTML;

    $mpdf->WriteHTML($html);
    ob_end_clean();
    $mpdf->Output('Graduation_Clearance_' . $app_id . '.pdf', 'I');

} catch (\Throwable $e) {
    ob_end_clean();
    error_log('Clearance certificate error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error generating certificate: ' . htmlspecialchars($e->getMessage());
}
