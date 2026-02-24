<?php
/**
 * Lecturer Payment Receipt - Printable receipt with university branding
 * Matching the style of student payment receipts
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'lecturer', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$request_id) {
    header('Location: dashboard.php?error=invalid_request');
    exit;
}

// Get lecturer payment request details
$stmt = $conn->prepare("SELECT lfr.*, l.full_name, l.email, l.phone, l.department, l.position, l.nrc, l.lecturer_id,
                        d.department_name
                        FROM lecturer_finance_requests lfr 
                        LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id 
                        LEFT JOIN departments d ON l.department = d.department_id
                        WHERE lfr.request_id = ?");
$stmt->bind_param('i', $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: dashboard.php?error=not_found');
    exit;
}

$req = $result->fetch_assoc();

// If user is a lecturer, verify they own this request
if ($user['role'] === 'lecturer') {
    $lecturer_id = $_SESSION['vle_related_id'] ?? null;
    if ($req['lecturer_id'] != $lecturer_id) {
        header('Location: ../lecturer/request_finance.php?error=unauthorized');
        exit;
    }
}

if ($req['status'] !== 'paid') {
    header('Location: dashboard.php?error=not_paid');
    exit;
}

// Generate receipt number
$receipt_number = 'LPR-' . date('Y') . '-' . str_pad($request_id, 6, '0', STR_PAD_LEFT);

// Get university settings
$university_name = "Eastern University of Malawi and the World";
$university_address = "P.O. Box 123, Mzuzu, Malawi";
$university_phone = "+265 1 234 567";
$university_email = "finance@eumw.edu";
$university_website = "www.eumw.edu";

$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $settings = $settings_query->fetch_assoc();
    $university_name = $settings['university_name'] ?? $university_name;
    $university_address = ($settings['address_po_box'] ?? '') . ', ' . ($settings['address_area'] ?? '') . ', ' . ($settings['address_city'] ?? '') . ', ' . ($settings['address_country'] ?? '');
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
    <title>Lecturer Payment Receipt - <?php echo $receipt_number; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .receipt-container { box-shadow: none !important; border: none !important; margin: 0 !important; }
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { margin: 0.5cm; size: A4; }
            * { font-size: 11px !important; }
            .receipt-header { padding: 10px !important; }
            .receipt-header img { max-height: 50px !important; margin-bottom: 5px !important; }
            .receipt-header h1 { font-size: 16px !important; }
            .receipt-header p { font-size: 10px !important; margin: 2px 0 !important; }
            .receipt-title { padding: 8px !important; font-size: 14px !important; }
            .receipt-body { padding: 10px 15px !important; }
            .receipt-number { padding: 8px !important; margin-bottom: 10px !important; }
            .receipt-number h4 { font-size: 12px !important; }
            .info-section { margin-bottom: 8px !important; }
            .info-section h5 { font-size: 12px !important; padding-bottom: 3px !important; margin-bottom: 5px !important; }
            .info-table td { padding: 2px 0 !important; font-size: 11px !important; }
            .payment-amount { padding: 10px !important; margin: 10px 0 !important; }
            .payment-amount h2 { font-size: 20px !important; }
            .payment-amount p { font-size: 10px !important; margin: 2px 0 !important; }
            .signature-section { margin-top: 15px !important; padding-top: 10px !important; }
            .signature-box { width: 48% !important; }
            .signature-line { height: 25px !important; }
            .receipt-footer { padding: 8px 15px !important; font-size: 9px !important; }
            .watermark { font-size: 60px !important; }
        }
        
        body {
            background: #f5f5f5;
            font-family: 'Arial', sans-serif;
            font-size: 12px;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 15px;
            text-align: center;
        }
        
        .receipt-header img {
            max-height: 60px;
            margin-bottom: 8px;
        }
        
        .receipt-header h1 {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
        }
        
        .receipt-header p {
            margin: 3px 0 0 0;
            opacity: 0.9;
            font-size: 11px;
        }
        
        .receipt-title {
            background: #17a2b8;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .receipt-body {
            padding: 15px 20px;
        }
        
        .receipt-number {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            padding: 10px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .receipt-number h4 {
            margin: 0;
            color: #1e3c72;
            font-weight: bold;
            font-size: 14px;
        }
        
        .info-section {
            margin-bottom: 12px;
        }
        
        .info-section h5 {
            color: #1e3c72;
            border-bottom: 2px solid #1e3c72;
            padding-bottom: 5px;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        .info-table {
            width: 100%;
        }
        
        .info-table td {
            padding: 3px 0;
            vertical-align: top;
            font-size: 12px;
        }
        
        .info-table td:first-child {
            font-weight: 600;
            color: #555;
            width: 40%;
        }
        
        .payment-amount {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 12px;
            border-radius: 10px;
            text-align: center;
            margin: 12px 0;
        }
        
        .payment-amount h2 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        
        .payment-amount p {
            margin: 3px 0 0 0;
            opacity: 0.9;
            font-size: 11px;
        }
        
        .receipt-footer {
            background: #f8f9fa;
            padding: 10px 20px;
            border-top: 1px solid #dee2e6;
            font-size: 10px;
            color: #666;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #dee2e6;
        }
        
        .signature-box {
            text-align: center;
            width: 45%;
        }
        
        .signature-box p {
            margin: 0;
            font-size: 11px;
        }
        
        .signature-box small {
            font-size: 10px;
        }
        
        .signature-line {
            border-bottom: 1px solid #333;
            margin-bottom: 3px;
            height: 25px;
        }
        
        .signature-image {
            border: 1px solid #dee2e6;
            background: #fff;
            padding: 5px;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .signature-image img {
            max-height: 45px;
            max-width: 100%;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            color: rgba(23, 162, 184, 0.1);
            font-weight: bold;
            pointer-events: none;
            z-index: 0;
        }
        
        .print-buttons {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
        }
        
        /* Two column layout for info sections */
        .info-row {
            display: flex;
            gap: 20px;
        }
        .info-row .info-section {
            flex: 1;
        }
        
        .notes-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <!-- Print/Download Buttons -->
    <div class="print-buttons no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg me-2">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
        <?php if ($user['role'] === 'lecturer'): ?>
            <a href="../lecturer/request_finance.php" class="btn btn-secondary btn-lg">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        <?php elseif ($user['role'] === 'finance' || $user['role'] === 'admin'): ?>
            <a href="finance_manage_requests.php" class="btn btn-secondary btn-lg">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        <?php else: ?>
            <a href="dashboard.php" class="btn btn-secondary btn-lg">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        <?php endif; ?>
    </div>

    <div class="receipt-container position-relative">
        <div class="watermark">PAID</div>
        
        <!-- Header with University Logo -->
        <div class="receipt-header">
            <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
            <h1><?php echo htmlspecialchars($university_name); ?></h1>
            <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($university_address); ?></p>
            <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($university_phone); ?> | <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($university_email); ?></p>
        </div>
        
        <!-- Receipt Title -->
        <div class="receipt-title">
            <i class="bi bi-receipt"></i> LECTURER PAYMENT RECEIPT
        </div>
        
        <div class="receipt-body">
            <!-- Receipt Number -->
            <div class="receipt-number">
                <h4>Receipt No: <?php echo $receipt_number; ?> | Date: <?php echo date('M d, Y'); ?></h4>
            </div>
            
            <!-- Lecturer & Payment Info Side by Side -->
            <div class="info-row">
                <!-- Lecturer Information -->
                <div class="info-section">
                    <h5><i class="bi bi-person-badge"></i> Lecturer Information</h5>
                    <table class="info-table">
                        <tr>
                            <td>Lecturer ID:</td>
                            <td><strong><?php echo htmlspecialchars($req['lecturer_id']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Full Name:</td>
                            <td><?php echo htmlspecialchars($req['full_name']); ?></td>
                        </tr>
                        <tr>
                            <td>Department:</td>
                            <td><?php echo htmlspecialchars($req['department_name'] ?? $req['department']); ?></td>
                        </tr>
                        <tr>
                            <td>Position:</td>
                            <td><?php echo htmlspecialchars($req['position']); ?></td>
                        </tr>
                        <tr>
                            <td>NRC:</td>
                            <td><?php echo htmlspecialchars($req['nrc'] ?? 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Payment Details -->
                <div class="info-section">
                    <h5><i class="bi bi-calendar-check"></i> Payment Period</h5>
                    <table class="info-table">
                        <tr>
                            <td>Period:</td>
                            <td><strong><?php echo date('F Y', mktime(0,0,0,$req['month'],1,$req['year'])); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Modules Taught:</td>
                            <td><?php echo htmlspecialchars($req['total_modules']); ?> module(s)</td>
                        </tr>
                        <tr>
                            <td>Total Hours:</td>
                            <td><?php echo htmlspecialchars($req['total_hours']); ?> hours</td>
                        </tr>
                        <tr>
                            <td>Payment Date:</td>
                            <td><?php echo date('M d, Y', strtotime($req['response_date'])); ?></td>
                        </tr>
                        <tr>
                            <td>Request Date:</td>
                            <td><?php echo date('M d, Y', strtotime($req['request_date'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Payment Amount -->
            <div class="payment-amount">
                <p style="margin:0;">Amount Paid</p>
                <h2>K<?php echo number_format($req['total_amount'], 2); ?></h2>
                <p>(<?php echo ucwords(numberToWords($req['total_amount'])); ?> Kwacha Only)</p>
            </div>
            
            <!-- Contact & Status Info -->
            <div class="info-row">
                <!-- Contact Information -->
                <div class="info-section">
                    <h5><i class="bi bi-telephone"></i> Contact Information</h5>
                    <table class="info-table">
                        <tr>
                            <td>Email:</td>
                            <td><?php echo htmlspecialchars($req['email']); ?></td>
                        </tr>
                        <tr>
                            <td>Phone:</td>
                            <td><?php echo htmlspecialchars($req['phone']); ?></td>
                        </tr>
                    </table>
                </div>
            
                <!-- Verification Details -->
                <div class="info-section">
                    <h5><i class="bi bi-shield-check"></i> Verification</h5>
                    <table class="info-table">
                        <tr>
                            <td>Status:</td>
                            <td><span class="badge bg-info">PAID</span></td>
                        </tr>
                        <tr>
                            <td>Processed By:</td>
                            <td><strong>Finance Department</strong></td>
                        </tr>
                        <tr>
                            <td>Processed Date:</td>
                            <td><?php echo date('M d, Y h:i A', strtotime($req['response_date'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <?php if (!empty($req['additional_notes'])): ?>
            <!-- Additional Notes -->
            <div class="notes-section">
                <strong><i class="bi bi-sticky"></i> Additional Notes:</strong>
                <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($req['additional_notes'])); ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Signatures -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <p><strong>Finance Officer</strong></p>
                    <small>Authorized Signatory</small>
                </div>
                <div class="signature-box">
                    <?php if (!empty($req['signature_path'])): ?>
                        <div class="signature-image">
                            <img src="../uploads/signatures/<?php echo htmlspecialchars($req['signature_path']); ?>" alt="Lecturer Signature">
                        </div>
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <p><strong>Lecturer Signature</strong></p>
                    <small><?php echo htmlspecialchars($req['full_name']); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0"><strong>Important Notice:</strong></p>
                    <p class="mb-0">This is a computer-generated receipt. Please retain for your records.</p>
                    <p class="mb-0">For queries, contact: <?php echo htmlspecialchars($university_email); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-0"><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                    <p class="mb-0"><strong>Receipt ID:</strong> <?php echo $receipt_number; ?></p>
                    <p class="mb-0"><?php echo htmlspecialchars($university_website); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
/**
 * Convert number to words (for amount in words)
 */
function numberToWords($number) {
    $number = (int)$number;
    if ($number == 0) return 'zero';
    
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
             'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    
    $words = '';
    
    if ($number >= 1000000) {
        $words .= numberToWords((int)($number / 1000000)) . ' million ';
        $number %= 1000000;
    }
    
    if ($number >= 1000) {
        $words .= numberToWords((int)($number / 1000)) . ' thousand ';
        $number %= 1000;
    }
    
    if ($number >= 100) {
        $words .= $ones[(int)($number / 100)] . ' hundred ';
        $number %= 100;
    }
    
    if ($number >= 20) {
        $words .= $tens[(int)($number / 10)] . ' ';
        $number %= 10;
    }
    
    if ($number > 0) {
        $words .= $ones[$number] . ' ';
    }
    
    return trim($words);
}
?>