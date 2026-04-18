<?php
/**
 * Examination Clearance Certificate
 * Professional A5 certificate matching the receipt design
 * Finance: prints 2 copies per A4 page (student copy + office copy)
 * Student: prints 1 certificate per page
 */
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_role = $_SESSION['vle_role'] ?? '';

// Allow finance, admin, super_admin AND students (for their own)
if (!in_array($user_role, ['finance', 'admin', 'super_admin', 'student'])) {
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
    echo '<div class="alert alert-danger m-4">Student not found or not yet cleared.</div>';
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
$theme_watermark = $is_midsemester ? 'rgba(245, 158, 11, 0.08)' : 'rgba(16, 185, 129, 0.08)';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Clearance Certificate - <?= htmlspecialchars($student['full_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            body { margin: 0; padding: 0; background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .cert-container { box-shadow: none !important; margin: 0 auto !important; border-radius: 0 !important; }
            @page { size: A5 portrait; margin: 5mm; }
            * { font-size: 10px !important; }
            .cert-header h1 { font-size: 14px !important; }
            .cert-header p { font-size: 8px !important; }
            .cert-title-bar { font-size: 12px !important; padding: 6px !important; }
            .cert-body-text { font-size: 11px !important; }
            .student-name-display { font-size: 16px !important; }
            .amount-box h2 { font-size: 16px !important; }
            .info-section h5 { font-size: 10px !important; }
        }
        
        body {
            background: #f5f5f5;
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 0;
        }
        
        .cert-container {
            width: 148mm;
            min-height: 210mm;
            margin: 15px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            overflow: hidden;
            position: relative;
            page-break-after: always;
        }
        
        .cert-container:last-of-type {
            page-break-after: auto;
        }
        
        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            opacity: 0.06;
            pointer-events: none;
            z-index: 0;
        }
        
        .watermark-text {
            position: absolute;
            top: 62%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-size: 50px;
            font-weight: bold;
            color: <?= $theme_watermark ?>;
            pointer-events: none;
            z-index: 0;
            letter-spacing: 8px;
            white-space: nowrap;
        }
        
        /* Header - gradient matching receipt */
        .cert-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 12px;
            text-align: center;
        }
        
        .cert-header img {
            max-height: 50px;
            margin-bottom: 5px;
        }
        
        .cert-header h1 {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .cert-header p {
            margin: 2px 0 0 0;
            opacity: 0.9;
            font-size: 9px;
        }
        
        /* Title bar - themed color */
        .cert-title-bar {
            background: <?= $theme_color ?>;
            color: white;
            text-align: center;
            padding: 8px;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .cert-body-area {
            padding: 10px 15px;
            position: relative;
            z-index: 1;
        }
        
        /* Certificate number box - dashed border like receipt */
        .cert-number-box {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            padding: 6px;
            text-align: center;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .cert-number-box h4 {
            margin: 0;
            color: #1e3c72;
            font-weight: bold;
            font-size: 11px;
        }
        
        /* Body text */
        .cert-body-text {
            text-align: center;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
            margin: 8px 10px;
        }
        
        .student-name-display {
            display: block;
            font-size: 18px;
            font-weight: bold;
            color: #1e3c72;
            margin: 4px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #ccc;
        }
        
        /* Info sections - side by side like receipt */
        .info-row {
            display: flex;
            gap: 12px;
        }
        
        .info-section {
            flex: 1;
            margin-bottom: 8px;
        }
        
        .info-section h5 {
            color: #1e3c72;
            border-bottom: 2px solid #1e3c72;
            padding-bottom: 3px;
            font-size: 10px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .info-table {
            width: 100%;
        }
        
        .info-table td {
            padding: 2px 0;
            vertical-align: top;
            font-size: 10px;
        }
        
        .info-table td:first-child {
            font-weight: 600;
            color: #555;
            width: 45%;
        }
        
        /* Amount box - themed gradient */
        .amount-box {
            background: linear-gradient(135deg, <?= $theme_color ?> 0%, <?= $theme_color_dark ?> 100%);
            color: white;
            padding: 8px;
            border-radius: 8px;
            text-align: center;
            margin: 8px 0;
        }
        
        .amount-box p {
            margin: 0;
            font-size: 9px;
            opacity: 0.9;
        }
        
        .amount-box h2 {
            margin: 2px 0;
            font-size: 18px;
            font-weight: bold;
        }
        
        /* Verification badge */
        .verified-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 50px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 9px;
            background: <?= $theme_badge_bg ?>;
            color: <?= $theme_badge_text ?>;
            border: 2px solid <?= $theme_color ?>;
        }
        
        /* Signatures - matching receipt style */
        .signature-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 8px;
            border-top: 1px dashed #dee2e6;
            gap: 8px 0;
        }
        
        .signature-box {
            text-align: center;
            width: 48%;
        }
        
        .signature-box .sig-line {
            border-bottom: 1px solid #333;
            margin-bottom: 2px;
            height: 20px;
        }
        
        .signature-box p {
            margin: 0;
            font-size: 9px;
            font-weight: bold;
        }
        
        .signature-box small {
            font-size: 8px;
            color: #666;
        }
        
        /* Footer - matching receipt */
        .cert-footer {
            background: #f8f9fa;
            padding: 6px 15px;
            border-top: 1px solid #dee2e6;
            font-size: 8px;
            color: #666;
        }
        
        /* Copy label */
        .copy-label {
            position: absolute;
            top: 8px;
            right: 12px;
            font-size: 8px;
            color: rgba(255,255,255,0.7);
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            z-index: 2;
        }
        
        /* Print controls */
        .print-controls {
            text-align: center;
            padding: 15px;
            background: #fff;
            margin: 0 auto 10px;
            max-width: 148mm;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .print-controls button, .print-controls a {
            display: inline-block;
            padding: 8px 20px;
            margin: 0 4px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            color: #fff;
        }
        
        .btn-print { background: <?= $theme_color ?>; }
        .btn-print:hover { background: <?= $theme_color_dark ?>; }
        .btn-back { background: #6c757d; color: #fff; }
        .btn-back:hover { background: #5a6268; color: #fff; }
    </style>
</head>
<body>

<div class="no-print print-controls">
    <button class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print Certificate</button>
    <?php if ($is_finance): ?>
        <a class="btn-back" href="exam_clearance_students.php"><i class="bi bi-arrow-left"></i> Back to Students</a>
        <a class="btn-back" href="exam_clearance_review.php?id=<?= $clearance_id ?>"><i class="bi bi-arrow-left"></i> Back to Review</a>
    <?php else: ?>
        <a class="btn-back" href="../student/exam_clearance.php"><i class="bi bi-arrow-left"></i> Back</a>
    <?php endif; ?>
</div>

<?php
// Student portal: 1 certificate only. Finance: 2 copies (Student Copy + Office Copy)
$copies = $is_finance ? [['label' => 'Student Copy', 'suffix' => ''], ['label' => 'Office Copy', 'suffix' => ' (Office Copy)']] : [['label' => '', 'suffix' => '']];

foreach ($copies as $copy):
?>
<!-- Certificate -->
<div class="cert-container">
    <?php if ($copy['label']): ?>
        <div class="copy-label"><?= $copy['label'] ?></div>
    <?php endif; ?>
    
    <!-- Watermark: University Logo -->
    <img src="../assets/img/Logo.png" class="watermark" alt="" onerror="this.style.display='none'">
    <div class="watermark-text">CLEARED</div>
    
    <!-- Header -->
    <div class="cert-header">
        <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
        <h1><?= htmlspecialchars($university_name) ?></h1>
        <p><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($university_address) ?></p>
        <p><i class="bi bi-telephone"></i> <?= htmlspecialchars($university_phone) ?> | <i class="bi bi-envelope"></i> <?= htmlspecialchars($university_email) ?></p>
    </div>
    
    <!-- Title Bar -->
    <div class="cert-title-bar">
        <i class="bi bi-shield-check"></i> <?= $clearance_title ?>
    </div>
    
    <div class="cert-body-area">
        <!-- Certificate Number -->
        <div class="cert-number-box">
            <h4>Certificate No: <?= htmlspecialchars($student['certificate_number']) ?><?= $copy['suffix'] ?> | Issued: <?= $cleared_date ?></h4>
        </div>
        
        <!-- Body Text -->
        <div class="cert-body-text">
            This is to certify that
            <span class="student-name-display"><?= htmlspecialchars($student['full_name']) ?></span>
            has been cleared for <?= strtolower($clearance_label) ?> examinations having met all required financial obligations for the academic year <?= $academic_year ?>.
        </div>
        
        <!-- Student & Clearance Info Side by Side -->
        <div class="info-row">
            <div class="info-section">
                <h5><i class="bi bi-person-circle"></i> Student Information</h5>
                <table class="info-table">
                    <tr><td>Student ID:</td><td><strong><?= htmlspecialchars($student['student_id']) ?></strong></td></tr>
                    <tr><td>Full Name:</td><td><?= htmlspecialchars($student['full_name']) ?></td></tr>
                    <tr><td>Program:</td><td><?= htmlspecialchars($student['program'] ?: ucfirst($student['program_type'])) ?></td></tr>
                    <tr><td>Department:</td><td><?= htmlspecialchars($student['department'] ?: '—') ?></td></tr>
                    <tr><td>Campus:</td><td><?= htmlspecialchars($student['campus'] ?: '—') ?></td></tr>
                    <tr><td>Year of Study:</td><td>Year <?= $student['year_of_study'] ?></td></tr>
                </table>
            </div>
            
            <div class="info-section">
                <h5><i class="bi bi-credit-card"></i> Fee Breakdown</h5>
                <table class="info-table">
                    <tr><td>Tuition Fee:</td><td>MWK <?= number_format($tuition_amount, 2) ?></td></tr>
                    <tr><td>Registration Fee:</td><td>MWK <?= number_format($registration_fee, 2) ?></td></tr>
                    <tr><td style="border-top:1px solid #ddd;padding-top:3px"><strong>Total Invoiced:</strong></td><td style="border-top:1px solid #ddd;padding-top:3px"><strong>MWK <?= number_format($student['invoiced_amount'], 2) ?></strong></td></tr>
                    <tr><td><strong>Amount Paid:</strong></td><td style="color:<?= $theme_color_dark ?>"><strong>MWK <?= number_format($amount_paid, 2) ?></strong></td></tr>
                    <tr><td>Balance:</td><td>MWK <?= number_format($student['balance'], 2) ?></td></tr>
                    <tr><td>Clearance Type:</td><td><strong><?= $clearance_label ?></strong></td></tr>
                </table>
            </div>
        </div>
        
        <!-- Status Box -->
        <div class="amount-box">
            <p style="margin:0;"><?= $clearance_label ?> Clearance Status</p>
            <h2><i class="bi bi-patch-check-fill"></i> CLEARED FOR EXAMINATIONS</h2>
            <p>Amount Paid: MWK <?= number_format($amount_paid, 2) ?> | Academic Year <?= $academic_year ?> | <?= ucfirst($student['program_type']) ?> Program</p>
        </div>
        
        <!-- Verification Info -->
        <div class="info-row">
            <div class="info-section">
                <h5><i class="bi bi-shield-check"></i> Verification</h5>
                <table class="info-table">
                    <tr><td>Status:</td><td><span class="verified-badge">CLEARED</span></td></tr>
                    <tr><td>Cleared By:</td><td><strong><?= htmlspecialchars($student['cleared_by_name'] ?? 'Finance Officer') ?></strong></td></tr>
                    <tr><td>Date:</td><td><?= date('M d, Y h:i A', strtotime($student['cleared_at'])) ?></td></tr>
                </table>
            </div>
            <div class="info-section">
                <h5><i class="bi bi-info-circle"></i> Details</h5>
                <table class="info-table">
                    <tr><td>Certificate No:</td><td><strong><?= htmlspecialchars($student['certificate_number']) ?></strong></td></tr>
                    <tr><td>Semester:</td><td><?= htmlspecialchars($student['semester'] ?? '—') ?></td></tr>
                    <tr><td>Entry Type:</td><td><?= htmlspecialchars($student['entry_type'] ?? '—') ?></td></tr>
                </table>
            </div>
        </div>
        
        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="sig-line"></div>
                <p>Director of Corporate Services</p>
                <small>Finance Department</small>
            </div>
            <div class="signature-box">
                <div class="sig-line"></div>
                <p>Dean of Commerce</p>
                <small>Faculty of Commerce</small>
            </div>
            <div class="signature-box">
                <div class="sig-line"></div>
                <p>Head of Department</p>
                <small>Academic Department</small>
            </div>
            <div class="signature-box">
                <div class="sig-line"></div>
                <p>Dean of Students</p>
                <small>Student Affairs</small>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="cert-footer">
        <div style="display:flex;justify-content:space-between">
            <div>
                <p style="margin:0"><strong>Important:</strong> This is a computer-generated certificate. Verify at <?= htmlspecialchars($university_email) ?></p>
                <p style="margin:0">Present this certificate at the examination venue.</p>
            </div>
            <div style="text-align:right">
                <p style="margin:0"><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
                <p style="margin:0"><?= htmlspecialchars($university_website) ?></p>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</body>
</html>
