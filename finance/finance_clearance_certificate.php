<?php
/**
 * Finance Clearance Certificate
 * A5 size, 2 certificates per A4 page when printed
 * Includes university logo, signature lines for:
 *   - Director of Corporate Services
 *   - Dean of Commerce
 *   - Dean of Students
 *   - Head of Department
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$clearance_id = (int)($_GET['id'] ?? 0);
if (!$clearance_id) {
    header('Location: Finance_clearence_students.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM finance_clearance_students WHERE clearance_id = ? AND status = 'cleared'");
$stmt->bind_param("i", $clearance_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header('Location: Finance_clearence_students.php?error=not_cleared');
    exit;
}

// Get university settings
$university_name = "Eastern University of Malawi and the World";
$university_address = "P.O. Box 123, Mzuzu, Malawi";
$university_phone = "+265 1 234 567";
$university_email = "info@eumw.edu";
$university_website = "www.eumw.edu";

$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $s = $settings_query->fetch_assoc();
    $university_name = $s['university_name'] ?? $university_name;
    $university_address = $s['address'] ?? $university_address;
    $university_phone = $s['phone'] ?? $university_phone;
    $university_email = $s['email'] ?? $university_email;
    $university_website = $s['website'] ?? $university_website;
}

// Get cleared by user
$cleared_by_name = 'Finance Officer';
if ($student['cleared_by']) {
    $u_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $u_stmt->bind_param("i", $student['cleared_by']);
    $u_stmt->execute();
    $u_row = $u_stmt->get_result()->fetch_assoc();
    if ($u_row) $cleared_by_name = $u_row['username'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Clearance Certificate - <?= htmlspecialchars($student['certificate_number']) ?></title>
    <style>
        /* Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: #f0f0f0;
            color: #1a1a1a;
        }
        
        /* Screen controls */
        .no-print {
            text-align: center;
            padding: 15px;
            background: #333;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .no-print button {
            padding: 10px 25px;
            margin: 0 5px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-print { background: #f59e0b; color: #000; }
        .btn-back { background: #6b7280; color: #fff; }
        
        /* A4 page container - holds 2 A5 certificates */
        .page-container {
            width: 210mm;
            margin: 10px auto;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        /* Each certificate is A5 (148.5mm × 210mm), but landscape on A4 portrait = 210mm wide × 148.5mm tall */
        .certificate {
            width: 210mm;
            height: 148.5mm;
            padding: 8mm 12mm;
            position: relative;
            overflow: hidden;
            border-bottom: 2px dashed #ccc;
            page-break-inside: avoid;
        }
        .certificate:last-child {
            border-bottom: none;
        }
        
        /* Decorative border */
        .cert-border {
            position: absolute;
            top: 4mm;
            left: 4mm;
            right: 4mm;
            bottom: 4mm;
            border: 2px solid #1a5276;
            border-radius: 4px;
        }
        .cert-border-inner {
            position: absolute;
            top: 6mm;
            left: 6mm;
            right: 6mm;
            bottom: 6mm;
            border: 1px solid #d4a843;
        }
        
        /* Content area */
        .cert-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            padding: 4mm;
        }
        
        /* Header with logo */
        .cert-header {
            text-align: center;
            margin-bottom: 3mm;
        }
        .cert-header img {
            height: 40px;
            margin-bottom: 2mm;
        }
        .cert-header h1 {
            font-size: 14pt;
            color: #1a5276;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1mm;
        }
        .cert-header .address {
            font-size: 7pt;
            color: #666;
            margin-bottom: 2mm;
        }
        .cert-header .cert-title {
            font-size: 16pt;
            font-weight: bold;
            color: #d4a843;
            text-transform: uppercase;
            letter-spacing: 3px;
            border-top: 1px solid #d4a843;
            border-bottom: 1px solid #d4a843;
            padding: 2mm 0;
            margin: 2mm auto;
            display: inline-block;
        }
        
        /* Certificate body */
        .cert-body {
            flex: 1;
            text-align: center;
            font-size: 9pt;
            line-height: 1.6;
            padding: 0 8mm;
        }
        .cert-body .intro {
            font-size: 9pt;
            color: #555;
            margin-bottom: 2mm;
        }
        .cert-body .student-name {
            font-size: 15pt;
            font-weight: bold;
            color: #1a5276;
            margin: 2mm 0;
            border-bottom: 1px solid #1a5276;
            display: inline-block;
            padding: 0 15mm 1mm;
        }
        .cert-body .student-id {
            font-size: 9pt;
            color: #666;
            margin-bottom: 2mm;
        }
        .cert-body .details-table {
            margin: 2mm auto;
            font-size: 8pt;
            text-align: left;
            width: auto;
        }
        .cert-body .details-table td {
            padding: 0.5mm 4mm;
        }
        .cert-body .details-table td:first-child {
            color: #888;
            font-weight: normal;
        }
        .cert-body .details-table td:last-child {
            font-weight: bold;
        }
        .cert-body .cert-text {
            font-size: 9pt;
            margin: 2mm 0;
        }
        .cert-number {
            font-size: 8pt;
            color: #888;
            margin-top: 1mm;
        }
        
        /* Signatures section - 2 rows of 2 */
        .cert-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2mm 15mm;
            padding: 0 8mm;
            margin-top: auto;
        }
        .sig-block {
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #333;
            margin: 6mm 5mm 1mm;
        }
        .sig-title {
            font-size: 7.5pt;
            font-weight: bold;
            color: #1a5276;
        }
        .sig-role {
            font-size: 6.5pt;
            color: #888;
        }
        
        /* Footer */
        .cert-footer {
            text-align: center;
            font-size: 6.5pt;
            color: #999;
            margin-top: 2mm;
            padding-top: 1mm;
            border-top: 1px solid #eee;
        }
        
        /* Print styles */
        @media print {
            .no-print { display: none !important; }
            body { background: white; margin: 0; padding: 0; }
            .page-container {
                width: 210mm;
                height: 297mm;
                margin: 0;
                box-shadow: none;
            }
            .certificate {
                border-bottom: none;
            }
            /* Dashed cut line between certificates */
            .certificate:first-child::after {
                content: '✂ - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -';
                position: absolute;
                bottom: -2mm;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 8pt;
                color: #ccc;
                letter-spacing: 2px;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<!-- Screen Controls -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print Certificate (2 per page)</button>
    <button class="btn-back" onclick="history.back()">← Back</button>
    <button class="btn-back" onclick="window.location='Finance_clearence_students.php'">📋 Students List</button>
</div>

<!-- A4 Page with 2 A5 Certificates -->
<div class="page-container">
    
    <?php for ($copy = 1; $copy <= 2; $copy++): ?>
    <!-- Certificate Copy <?= $copy ?> -->
    <div class="certificate">
        <div class="cert-border"></div>
        <div class="cert-border-inner"></div>
        
        <div class="cert-content">
            <!-- Header -->
            <div class="cert-header">
                <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
                <h1><?= htmlspecialchars($university_name) ?></h1>
                <div class="address"><?= htmlspecialchars($university_address) ?> | <?= htmlspecialchars($university_phone) ?> | <?= htmlspecialchars($university_email) ?></div>
                <div class="cert-title">Finance Clearance Certificate</div>
            </div>
            
            <!-- Body -->
            <div class="cert-body">
                <div class="intro">This is to certify that</div>
                <div class="student-name"><?= htmlspecialchars($student['full_name']) ?></div>
                <div class="student-id">Student ID: <?= htmlspecialchars($student['student_id']) ?></div>
                
                <table class="details-table" style="margin: 2mm auto;">
                    <tr><td>Program:</td><td><?= htmlspecialchars($student['program'] ?: ucfirst($student['program_type'])) ?></td></tr>
                    <tr><td>Department:</td><td><?= htmlspecialchars($student['department'] ?: '—') ?></td></tr>
                    <tr><td>Campus:</td><td><?= htmlspecialchars($student['campus'] ?: '—') ?></td></tr>
                    <tr><td>Year of Study:</td><td>Year <?= $student['year_of_study'] ?></td></tr>
                </table>
                
                <div class="cert-text">
                    has been financially cleared having met all financial obligations to the University.<br>
                    Total Amount Paid: <strong>MWK <?= number_format($student['amount_claimed'], 2) ?></strong>
                </div>
                
                <div class="cert-number">
                    Certificate No: <strong><?= htmlspecialchars($student['certificate_number']) ?></strong> &nbsp;|&nbsp;
                    Date Issued: <strong><?= date('jS F, Y', strtotime($student['cleared_at'])) ?></strong>
                    <?php if ($copy === 2): ?>&nbsp;|&nbsp; <em>Student Copy</em><?php else: ?>&nbsp;|&nbsp; <em>University Copy</em><?php endif; ?>
                </div>
            </div>
            
            <!-- Signatures - 2 rows × 2 columns -->
            <div class="cert-signatures">
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-title">Director of Corporate Services</div>
                    <div class="sig-role">Signature & Date</div>
                </div>
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-title">Dean of Commerce</div>
                    <div class="sig-role">Signature & Date</div>
                </div>
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-title">Dean of Students</div>
                    <div class="sig-role">Signature & Date</div>
                </div>
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-title">Head of Department</div>
                    <div class="sig-role">Signature & Date</div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="cert-footer">
                <?= htmlspecialchars($university_name) ?> | <?= htmlspecialchars($university_website) ?> | This certificate is valid only with all signatures and university stamp.
            </div>
        </div>
    </div>
    <?php endfor; ?>
    
</div>

</body>
</html>
