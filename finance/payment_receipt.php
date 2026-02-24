<?php
/**
 * Payment Receipt - Printable receipt with university branding
 * Supports both payment_submissions (student-submitted) and payment_transactions (finance-recorded)
 * Can be accessed by finance officers and students (for approved payments only)
 */
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$user = getCurrentUser();
$user_role = $_SESSION['vle_role'] ?? '';

// Get ID and type (default to submission for backward compatibility)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'submission'; // 'submission' or 'transaction'
$is_new_payment = isset($_GET['new']) && $_GET['new'] == '1'; // Flag for newly recorded payment

if (!$id) {
    header('Location: ' . ($user_role === 'student' ? '../student/payment_history.php' : 'review_payments.php'));
    exit;
}

$receipt = null;

// Try to get from payment_transactions first if type is 'transaction'
if ($type === 'transaction') {
    $query = "SELECT pt.transaction_id as id, pt.student_id, pt.amount, pt.payment_type, 
              pt.payment_method as transaction_type, pt.reference_number as payment_reference,
              pt.payment_date as transaction_date, pt.payment_date as submission_date,
              pt.payment_date as reviewed_date, pt.notes, pt.recorded_by, 
              pt.recorded_by as reviewed_by_username, 'approved' as status,
              s.full_name as student_name, s.email as student_email, 
              s.phone as student_phone, s.program, s.campus, s.year_of_study, s.semester,
              s.program_type, s.gender,
              d.department_name, d.department_code,
              f.faculty_name,
              sf.expected_total, sf.total_paid, sf.balance,
              NULL as bank_name, NULL as finance_id, NULL as reviewed_by
              FROM payment_transactions pt
              JOIN students s ON pt.student_id = s.student_id
              LEFT JOIN departments d ON s.department = d.department_id
              LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
              LEFT JOIN student_finances sf ON pt.student_id = sf.student_id
              WHERE pt.transaction_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $receipt = $result->fetch_assoc();
        $receipt_number = 'TXN-' . date('Y') . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    }
}

// Try payment_submissions if not found or type is 'submission'
if (!$receipt) {
    $query = "SELECT ps.submission_id as id, ps.*, 
              s.student_id, s.full_name as student_name, s.email as student_email, 
              s.phone as student_phone, s.program, s.campus, s.year_of_study, s.semester,
              s.program_type, s.gender,
              d.department_name, d.department_code,
              f.faculty_name,
              u.username as finance_username,
              u2.username as reviewed_by_username,
              sf.expected_total, sf.total_paid, sf.balance
              FROM payment_submissions ps
              JOIN students s ON ps.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
              LEFT JOIN departments d ON s.department = d.department_id
              LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
              LEFT JOIN users u ON ps.finance_id = u.user_id
              LEFT JOIN users u2 ON ps.reviewed_by = u2.user_id
              LEFT JOIN student_finances sf ON ps.student_id COLLATE utf8mb4_general_ci = sf.student_id COLLATE utf8mb4_general_ci
              WHERE ps.submission_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $receipt = $result->fetch_assoc();
        $receipt_number = 'RCP-' . date('Y') . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    }
}

if (!$receipt) {
    header('Location: ' . ($user_role === 'student' ? '../student/payment_history.php' : 'review_payments.php'));
    exit;
}

// Security check: Students can only view their own approved receipts
if ($user_role === 'student') {
    $student_id = $_SESSION['vle_related_id'];
    if ($receipt['student_id'] !== $student_id || $receipt['status'] !== 'approved') {
        header('Location: ../student/payment_history.php?error=unauthorized');
        exit;
    }
}

// Finance officers can view all receipts
if (!in_array($user_role, ['student', 'staff', 'finance'])) {
    header('Location: ../login.php');
    exit;
}

// Get university settings if available
$university_name = "Eastern University of Malawi and the World";
$university_address = "P.O. Box 123, Mzuzu, Malawi";
$university_phone = "+265 1 234 567";
$university_email = "finance@eumw.edu";
$university_website = "www.eumw.edu";

// Try to get settings from database
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
    <title>Payment Receipt - <?php echo $receipt_number; ?></title>
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
            .status-badge { padding: 4px 12px !important; font-size: 10px !important; }
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
            background: #28a745;
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
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
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
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 11px;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 2px solid #ffc107;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
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
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            color: rgba(40, 167, 69, 0.1);
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
    </style>
</head>
<body>
    <!-- Print/Download Buttons -->
    <div class="print-buttons no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg me-2">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
        <a href="<?php echo $user_role === 'student' ? '../student/payment_history.php' : 'review_payments.php'; ?>" class="btn btn-secondary btn-lg">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="receipt-container position-relative">
        <?php if ($receipt['status'] === 'approved'): ?>
            <div class="watermark">PAID</div>
        <?php endif; ?>
        
        <!-- Header with University Logo -->
        <div class="receipt-header">
            <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
            <h1><?php echo htmlspecialchars($university_name); ?></h1>
            <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($university_address); ?></p>
            <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($university_phone); ?> | <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($university_email); ?></p>
        </div>
        
        <!-- Receipt Title -->
        <div class="receipt-title">
            <i class="bi bi-receipt"></i> OFFICIAL PAYMENT RECEIPT
        </div>
        
        <div class="receipt-body">
            <!-- Receipt Number -->
            <div class="receipt-number">
                <h4>Receipt No: <?php echo $receipt_number; ?> | Date: <?php echo date('M d, Y'); ?></h4>
            </div>
            
            <!-- Student & Payment Info Side by Side -->
            <div class="info-row">
                <!-- Student Information -->
                <div class="info-section">
                    <h5><i class="bi bi-person-circle"></i> Student Information</h5>
                    <table class="info-table">
                        <tr>
                            <td>Student ID:</td>
                            <td><strong><?php echo htmlspecialchars($receipt['student_id']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Full Name:</td>
                            <td><?php echo htmlspecialchars($receipt['student_name']); ?></td>
                        </tr>
                        <tr>
                            <td>Program:</td>
                            <td><?php echo htmlspecialchars($receipt['department_name'] ?? $receipt['program']); ?></td>
                        </tr>
                        <tr>
                            <td>Campus:</td>
                            <td><?php echo htmlspecialchars($receipt['campus']); ?></td>
                        </tr>
                        <tr>
                            <td>Year/Semester:</td>
                            <td>Year <?php echo $receipt['year_of_study']; ?> / Sem <?php echo $receipt['semester']; ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Payment Details -->
                <div class="info-section">
                    <h5><i class="bi bi-credit-card"></i> Payment Details</h5>
                    <table class="info-table">
                        <tr>
                            <td>Reference:</td>
                            <td><strong><?php echo htmlspecialchars($receipt['payment_reference']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Trans. Date:</td>
                            <td><?php echo date('M d, Y', strtotime($receipt['transaction_date'])); ?></td>
                        </tr>
                        <tr>
                            <td>Method:</td>
                            <td><?php echo htmlspecialchars($receipt['transaction_type']); ?></td>
                        </tr>
                        <tr>
                            <td>Bank:</td>
                            <td><?php echo htmlspecialchars($receipt['bank_name'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td>Submitted:</td>
                            <td><?php echo date('M d, Y', strtotime($receipt['submission_date'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Payment Amount -->
            <div class="payment-amount">
                <p style="margin:0;">Amount Paid</p>
                <h2>K<?php echo number_format($receipt['amount'], 2); ?></h2>
                <p>(<?php echo ucwords(numberToWords($receipt['amount'])); ?> Kwacha Only)</p>
            </div>
            
            <!-- Status & Account Summary Side by Side -->
            <div class="info-row">
                <!-- Account Summary -->
                <div class="info-section">
                    <h5><i class="bi bi-wallet2"></i> Account Summary</h5>
                    <table class="info-table">
                        <tr>
                            <td>Total Fees:</td>
                            <td>K<?php echo number_format($receipt['expected_total'] ?? 0, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Total Paid:</td>
                            <td class="text-success">K<?php echo number_format($receipt['total_paid'] ?? 0, 2); ?></td>
                        </tr>
                        <tr>
                            <td>Balance:</td>
                            <td class="text-danger">K<?php echo number_format($receipt['balance'] ?? 0, 2); ?></td>
                        </tr>
                    </table>
                </div>
            
                <?php if ($receipt['status'] === 'approved'): ?>
                <!-- Verification Details -->
                <div class="info-section">
                    <h5><i class="bi bi-shield-check"></i> Verification</h5>
                    <table class="info-table">
                        <tr>
                            <td>Status:</td>
                            <td><span class="badge bg-success">APPROVED</span></td>
                        </tr>
                        <tr>
                            <td>Approved By:</td>
                            <td><strong><?php echo htmlspecialchars($receipt['reviewed_by_username'] ?? $receipt['finance_username'] ?? 'Finance Officer'); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Date:</td>
                            <td><?php echo isset($receipt['reviewed_date']) && $receipt['reviewed_date'] ? date('M d, Y h:i A', strtotime($receipt['reviewed_date'])) : date('M d, Y h:i A', strtotime($receipt['transaction_date'] ?? 'now')); ?></td>
                        </tr>
                    </table>
                </div>
                <?php else: ?>
                <div class="info-section text-center">
                    <span class="status-badge status-<?php echo $receipt['status']; ?>">
                        <?php echo strtoupper($receipt['status']); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($receipt['status'] === 'approved'): ?>
            <!-- Signatures -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <p><strong>Finance Officer</strong></p>
                    <small><?php echo htmlspecialchars($receipt['reviewed_by_username'] ?? $receipt['finance_username'] ?? 'Authorized Signatory'); ?></small>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <p><strong>Official Stamp</strong></p>
                    <small>University Seal</small>
                </div>
            </div>
            <?php endif; ?>
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
    
    <?php if ($is_new_payment): ?>
    <!-- Success notification and auto-print for new payment -->
    <div class="no-print position-fixed top-0 start-0 w-100 bg-success text-white py-3 px-4" style="z-index: 9999;" id="successBanner">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-check-circle-fill me-2"></i>
                <strong>Payment Recorded Successfully!</strong> The receipt is ready to print.
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-light btn-sm me-2">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
                <a href="student_finances.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="bi bi-arrow-left"></i> Back to Student Accounts
                </a>
                <a href="record_payment.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-plus-circle"></i> Record Another
                </a>
                <button onclick="document.getElementById('successBanner').remove()" class="btn btn-sm btn-outline-light ms-2">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
    <script>
        // Auto-trigger print dialog for new payments
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
    <?php endif; ?>
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
