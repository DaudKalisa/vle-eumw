<?php
/**
 * Examination Clearance Certificate - PDF Download (A5 Landscape)
 * Uses mPDF to generate a single-page A5 landscape PDF certificate
 * Finance: generates 2-page PDF (student copy + office copy)
 * Student: generates 1-page PDF
 */
require_once '../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_role = $_SESSION['vle_role'] ?? '';

// Allow finance, admin, super_admin AND students (for their own)
if (!in_array($user_role, ['finance', 'admin', 'super_admin', 'student', 'exam_clearance_student'])) {
    header('Location: ../login.php');
    exit;
}

$clearance_id = (int)($_GET['id'] ?? 0);
if (!$clearance_id) {
    header('Location: ' . ($user_role === 'student' ? '../student/exam_clearance.php' : 'exam_clearance_students.php'));
    exit;
}

$stmt = $conn->prepare("SELECT ecs.*, COALESCE(u.username, 'Finance Officer') as cleared_by_name FROM exam_clearance_students ecs LEFT JOIN users u ON ecs.cleared_by = u.user_id WHERE ecs.clearance_id = ? AND ecs.status = 'cleared'");
$stmt->bind_param("i", $clearance_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    echo '<div style="padding:20px;font-family:Arial;color:red">Student not found or not yet cleared.</div>';
    exit;
}

// Security: students can only view their own certificate
if ($user_role === 'student') {
    $sid = $_SESSION['vle_related_id'] ?? '';
    if ($student['student_id'] !== $sid) {
        header('Location: ../student/exam_clearance.php');
        exit;
    }
}
if ($user_role === 'exam_clearance_student') {
    // External exam clearance students: match by email
    $ecs_email = $user['email'] ?? '';
    if ($student['email'] !== $ecs_email) {
        header('Location: ../student/exam_clearance.php');
        exit;
    }
}

$is_finance = in_array($user_role, ['finance', 'admin', 'super_admin']);
$cleared_date = date('jS F, Y', strtotime($student['cleared_at']));
$academic_year = date('Y', strtotime($student['cleared_at'])) . '/' . (date('Y', strtotime($student['cleared_at'])) + 1);
$tuition_amount = $student['invoiced_amount'] - ($student['registration_fee'] ?? 0);
$registration_fee = $student['registration_fee'] ?? 0;
$amount_paid = (float)($student['amount_paid'] ?? $student['amount_claimed'] ?? 0);
$clearance_type = $student['clearance_type'] ?? 'endsemester';

// Color theme based on clearance type
$is_midsemester = ($clearance_type === 'midsemester');
$theme_color = $is_midsemester ? '#f59e0b' : '#10b981';
$theme_color_dark = $is_midsemester ? '#d97706' : '#059669';
$theme_badge_bg = $is_midsemester ? '#fef3c7' : '#d1fae5';
$theme_badge_text = $is_midsemester ? '#92400e' : '#065f46';
$clearance_title = $is_midsemester ? 'MID-SEMESTER EXAMINATION CLEARANCE CERTIFICATE' : 'END-OF-SEMESTER EXAMINATION CLEARANCE CERTIFICATE';
$clearance_label = $is_midsemester ? 'Mid-Semester' : 'End-of-Semester';

// University settings
$university_name = "Exploits University";
$university_address = "P.O. Box 123, Mzuzu, Malawi";
$university_phone = "+265 1 234 567";
$university_email = "finance@exploitsuniversity.edu";
$university_website = "www.exploitsuniversity.edu";

$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $settings = $settings_query->fetch_assoc();
    $university_name = $settings['university_name'] ?? $university_name;
    $university_address = $settings['address'] ?? $university_address;
    $university_phone = $settings['phone'] ?? $university_phone;
    $university_email = $settings['email'] ?? $university_email;
    $university_website = $settings['website'] ?? $university_website;
}
$conn->close();

// Logo path for embedding
$logo_path = __DIR__ . '/../assets/img/Logo.png';
$logo_html = '';
if (file_exists($logo_path)) {
    $logo_html = '<img src="' . $logo_path . '" style="height:35px;margin-bottom:3px;">';
}

// Build verification URL for QR code
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_path = dirname(dirname($_SERVER['SCRIPT_NAME']));
$verify_url = $protocol . '://' . $host . rtrim($base_path, '/') . '/verify_certificate.php?cert=' . urlencode($student['certificate_number']);

// Build certificate HTML for one page
function buildCertificateHTML($student, $copy_label, $copy_suffix, $vars) {
    extract($vars);
    
    $html = '
    <div style="font-family:Arial,sans-serif;font-size:9px;color:#333;position:relative;">
        
        <!-- Header -->
        <div style="background:linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);color:white;padding:8px 10px;text-align:center;">
            ' . ($copy_label ? '<div style="position:absolute;top:5px;right:10px;font-size:7px;color:rgba(255,255,255,0.7);font-weight:bold;text-transform:uppercase;letter-spacing:1px;">' . $copy_label . '</div>' : '') . '
            ' . $logo_html . '
            <div style="font-size:14px;font-weight:bold;letter-spacing:1px;margin:0;">' . htmlspecialchars($university_name) . '</div>
            <div style="font-size:8px;opacity:0.9;margin-top:1px;">' . htmlspecialchars($university_address) . '</div>
            <div style="font-size:8px;opacity:0.9;">' . htmlspecialchars($university_phone) . ' | ' . htmlspecialchars($university_email) . '</div>
        </div>
        
        <!-- Title Bar -->
        <div style="background:' . $theme_color . ';color:white;text-align:center;padding:5px;font-size:11px;font-weight:bold;letter-spacing:2px;">
            &#10004; ' . $clearance_title . '
        </div>
        
        <!-- Body -->
        <div style="padding:6px 12px;">
            
            <!-- Certificate Number -->
            <div style="background:#f8f9fa;border:1.5px dashed #dee2e6;padding:4px;text-align:center;border-radius:4px;margin-bottom:6px;">
                <div style="color:#1e3c72;font-weight:bold;font-size:9px;">Certificate No: ' . htmlspecialchars($student['certificate_number']) . $copy_suffix . ' | Issued: ' . $cleared_date . '</div>
            </div>
            
            <!-- Body Text -->
            <div style="text-align:center;font-size:10px;line-height:1.5;margin:4px 8px;">
                This is to certify that
                <div style="font-size:14px;font-weight:bold;color:#1e3c72;margin:2px 0;padding-bottom:2px;border-bottom:1px solid #ccc;">' . htmlspecialchars($student['full_name']) . '</div>
                has been cleared for ' . strtolower($clearance_label) . ' examinations having met all required financial obligations for the academic year ' . $academic_year . '.
            </div>
            
            <!-- Info Columns -->
            <table style="width:100%;margin-top:4px;" cellpadding="0" cellspacing="0">
                <tr><td style="width:50%;vertical-align:top;padding-right:6px;">
                    <div style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:2px;font-size:9px;font-weight:bold;margin-bottom:3px;">Student Information</div>
                    <table style="width:100%;font-size:8px;" cellpadding="1" cellspacing="0">
                        <tr><td style="font-weight:600;color:#555;width:42%;">Student ID:</td><td><strong>' . htmlspecialchars($student['student_id']) . '</strong></td></tr>
                        <tr><td style="font-weight:600;color:#555;">Full Name:</td><td>' . htmlspecialchars($student['full_name']) . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Program:</td><td>' . htmlspecialchars($student['program'] ?: ucfirst($student['program_type'])) . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Department:</td><td>' . htmlspecialchars($student['department'] ?: '—') . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Campus:</td><td>' . htmlspecialchars($student['campus'] ?: '—') . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Year of Study:</td><td>Year ' . $student['year_of_study'] . '</td></tr>
                    </table>
                </td><td style="width:50%;vertical-align:top;padding-left:6px;">
                    <div style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:2px;font-size:9px;font-weight:bold;margin-bottom:3px;">Fee Breakdown</div>
                    <table style="width:100%;font-size:8px;" cellpadding="1" cellspacing="0">
                        <tr><td style="font-weight:600;color:#555;width:48%;">Tuition Fee:</td><td>MWK ' . number_format($tuition_amount, 2) . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Registration Fee:</td><td>MWK ' . number_format($registration_fee, 2) . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;border-top:1px solid #ddd;padding-top:2px;"><strong>Total Invoiced:</strong></td><td style="border-top:1px solid #ddd;padding-top:2px;"><strong>MWK ' . number_format($student['invoiced_amount'], 2) . '</strong></td></tr>
                        <tr><td style="font-weight:600;color:#555;"><strong>Amount Paid:</strong></td><td style="color:' . $theme_color_dark . '"><strong>MWK ' . number_format($amount_paid, 2) . '</strong></td></tr>
                        <tr><td style="font-weight:600;color:#555;">Balance:</td><td>MWK ' . number_format($student['balance'], 2) . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Clearance Type:</td><td><strong>' . $clearance_label . '</strong></td></tr>
                    </table>
                </td></tr>
            </table>
            
            <!-- Status Box -->
            <div style="background:linear-gradient(135deg, ' . $theme_color . ' 0%, ' . $theme_color_dark . ' 100%);color:white;padding:5px;border-radius:6px;text-align:center;margin:5px 0;">
                <div style="font-size:7px;opacity:0.9;">' . $clearance_label . ' Clearance Status</div>
                <div style="font-size:13px;font-weight:bold;margin:1px 0;">&#10004; CLEARED FOR EXAMINATIONS</div>
                <div style="font-size:7px;opacity:0.9;">Amount Paid: MWK ' . number_format($amount_paid, 2) . ' | Academic Year ' . $academic_year . ' | ' . ucfirst($student['program_type']) . ' Program</div>
            </div>
            
            <!-- Verification & Details -->
            <table style="width:100%;margin-top:3px;" cellpadding="0" cellspacing="0">
                <tr><td style="width:50%;vertical-align:top;padding-right:6px;">
                    <div style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:2px;font-size:9px;font-weight:bold;margin-bottom:3px;">Verification</div>
                    <table style="width:100%;font-size:8px;" cellpadding="1" cellspacing="0">
                        <tr><td style="font-weight:600;color:#555;width:42%;">Status:</td><td><span style="display:inline-block;padding:1px 8px;border-radius:50px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;font-size:7px;background:' . $theme_badge_bg . ';color:' . $theme_badge_text . ';border:1.5px solid ' . $theme_color . ';">CLEARED</span></td></tr>
                        <tr><td style="font-weight:600;color:#555;">Cleared By:</td><td><strong>' . htmlspecialchars($student['cleared_by_name'] ?? 'Finance Officer') . '</strong></td></tr>
                        <tr><td style="font-weight:600;color:#555;">Date:</td><td>' . date('M d, Y h:i A', strtotime($student['cleared_at'])) . '</td></tr>
                    </table>
                </td><td style="width:50%;vertical-align:top;padding-left:6px;">
                    <div style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:2px;font-size:9px;font-weight:bold;margin-bottom:3px;">Details</div>
                    <table style="width:100%;font-size:8px;" cellpadding="1" cellspacing="0">
                        <tr><td style="font-weight:600;color:#555;width:42%;">Certificate No:</td><td><strong>' . htmlspecialchars($student['certificate_number']) . '</strong></td></tr>
                        <tr><td style="font-weight:600;color:#555;">Semester:</td><td>' . htmlspecialchars($student['semester'] ?? '—') . '</td></tr>
                        <tr><td style="font-weight:600;color:#555;">Entry Type:</td><td>' . htmlspecialchars($student['entry_type'] ?? '—') . '</td></tr>
                    </table>
                </td></tr>
            </table>
            
            <!-- Signatures & QR Code -->
            <div style="margin-top:6px;padding-top:5px;border-top:1px dashed #dee2e6;">
                <table style="width:100%;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="width:22%;text-align:center;padding:0 4px;vertical-align:bottom;">
                            <div style="height:30px;"></div>
                            <div style="border-bottom:1px solid #333;width:90%;margin:0 auto;"></div>
                            <div style="font-size:7px;font-weight:bold;margin-top:2px;">Director of Corporate Services</div>
                            <div style="font-size:6px;color:#666;">Finance Department</div>
                        </td>
                        <td style="width:22%;text-align:center;padding:0 4px;vertical-align:bottom;">
                            <div style="height:30px;"></div>
                            <div style="border-bottom:1px solid #333;width:90%;margin:0 auto;"></div>
                            <div style="font-size:7px;font-weight:bold;margin-top:2px;">Dean of Commerce</div>
                            <div style="font-size:6px;color:#666;">Faculty of Commerce</div>
                        </td>
                        <td style="width:22%;text-align:center;padding:0 4px;vertical-align:bottom;">
                            <div style="height:30px;"></div>
                            <div style="border-bottom:1px solid #333;width:90%;margin:0 auto;"></div>
                            <div style="font-size:7px;font-weight:bold;margin-top:2px;">Head of Department</div>
                            <div style="font-size:6px;color:#666;">Academic Department</div>
                        </td>
                        <td style="width:22%;text-align:center;padding:0 4px;vertical-align:bottom;">
                            <div style="height:30px;"></div>
                            <div style="border-bottom:1px solid #333;width:90%;margin:0 auto;"></div>
                            <div style="font-size:7px;font-weight:bold;margin-top:2px;">Dean of Students</div>
                            <div style="font-size:6px;color:#666;">Student Affairs</div>
                        </td>
                        <td style="width:12%;text-align:center;vertical-align:bottom;padding-left:4px;">
                            <barcode code="' . htmlspecialchars($verify_url) . '" type="QR" class="barcode" size="0.75" error="M" disableborder="1" />
                            <div style="font-size:5px;color:#888;margin-top:1px;">Scan to verify</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background:#f8f9fa;padding:3px 12px;border-top:1px solid #dee2e6;font-size:6px;color:#888;">
            <strong>Notice:</strong> Computer-generated certificate. Verify at ' . htmlspecialchars($university_email) . ' | Present at examination venue. | Generated: ' . date('Y-m-d H:i:s') . ' | ' . htmlspecialchars($university_website) . '
        </div>
    </div>';
    
    return $html;
}

// Variables to pass to the builder function
$vars = compact(
    'logo_html', 'university_name', 'university_address', 'university_phone', 'university_email', 'university_website',
    'theme_color', 'theme_color_dark', 'theme_badge_bg', 'theme_badge_text',
    'clearance_title', 'clearance_label', 'cleared_date', 'academic_year',
    'tuition_amount', 'registration_fee', 'amount_paid', 'is_midsemester', 'verify_url'
);

// Create mPDF instance - A5 Landscape
$mpdfTempDir = dirname(__DIR__) . '/uploads/mpdf_tmp';
if (!is_dir($mpdfTempDir)) mkdir($mpdfTempDir, 0777, true);

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => [210, 148], // A5 Landscape (width x height in mm)
        'margin_left' => 5,
        'margin_right' => 5,
        'margin_top' => 5,
        'margin_bottom' => 3,
        'default_font' => 'arial',
        'default_font_size' => 9,
        'tempDir' => $mpdfTempDir,
    ]);

    $mpdf->SetTitle('Exam Clearance Certificate - ' . $student['full_name']);
    $mpdf->SetAuthor(htmlspecialchars($university_name));

    // Student copy
    $mpdf->WriteHTML(buildCertificateHTML($student, '', '', $vars));

    // Finance: add office copy on page 2
    if ($is_finance) {
        $mpdf->AddPage();
        $mpdf->WriteHTML(buildCertificateHTML($student, 'Office Copy', ' (Office Copy)', $vars));
    }

    // Safe filename
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $student['full_name']);
    $filename = 'Exam_Clearance_' . $student['certificate_number'] . '_' . $safe_name . '.pdf';

    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Exception $e) {
    echo '<div style="padding:20px;font-family:Arial;color:red"><strong>PDF Generation Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
