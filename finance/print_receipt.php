<?php
// finance/print_receipt.php - Print payment receipts (58mm & A4)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();

// Get transaction ID
$transaction_id = $_GET['id'] ?? '';
$format = $_GET['format'] ?? '58mm'; // 58mm or a4

if (empty($transaction_id)) {
    header('Location: student_finances.php');
    exit;
}

// Get transaction details
$query = "SELECT pt.*, s.student_id, s.full_name, s.email, s.phone, d.department_name, d.department_code
          FROM payment_transactions pt
          JOIN students s ON pt.student_id = s.student_id
          LEFT JOIN departments d ON s.department = d.department_id
          WHERE pt.transaction_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Transaction not found");
}

$transaction = $result->fetch_assoc();

// Get university settings
$uni_result = $conn->query("SELECT * FROM university_settings LIMIT 1");
$university = $uni_result->fetch_assoc();

if (!$university) {
    $university = [
        'university_name' => 'Exploits University',
        'address_po_box' => 'P.O.Box 301752',
        'address_area' => 'Area 4',
        'address_street' => '',
        'address_city' => 'Lilongwe',
        'address_country' => 'Malawi',
        'phone' => '',
        'email' => '',
        'logo_path' => '',
        'receipt_footer_text' => 'Thank you for your payment'
    ];
}

// Format payment type
$payment_types = [
    'registration' => 'Registration Fee',
    'application' => 'Application Fee',
    'tuition' => 'Tuition Fee',
    'payment' => 'General Payment',
    'installment_1' => 'Tuition - Installment 1',
    'installment_2' => 'Tuition - Installment 2',
    'installment_3' => 'Tuition - Installment 3',
    'installment_4' => 'Tuition - Installment 4',
    'full_payment' => 'Full Tuition Payment',
    'other' => 'Other Payment'
];
$payment_type_label = $payment_types[$transaction['payment_type']] ?? ucwords(str_replace('_', ' ', $transaction['payment_type']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt #<?php echo $transaction['transaction_id']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
            @page { margin: 0; }
        }
        
        <?php if ($format === '58mm'): ?>
        /* 58mm Thermal Receipt Style */
        body {
            font-family: 'Courier New', monospace;
            margin: 0;
            padding: 0;
        }
        .receipt-58mm {
            width: 58mm;
            padding: 5mm;
            margin: 0 auto;
            background: white;
        }
        .receipt-58mm .logo {
            text-align: center;
            margin-bottom: 10px;
        }
        .receipt-58mm .logo img {
            max-width: 80px;
            max-height: 80px;
        }
        .receipt-58mm .header {
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .receipt-58mm .header h1 {
            font-size: 14px;
            font-weight: bold;
            margin: 5px 0;
        }
        .receipt-58mm .header p {
            font-size: 10px;
            margin: 2px 0;
            line-height: 1.3;
        }
        .receipt-58mm .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            margin: 3px 0;
        }
        .receipt-58mm .info-label {
            font-weight: bold;
        }
        .receipt-58mm .divider {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }
        .receipt-58mm .amount-section {
            text-align: center;
            padding: 10px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            margin: 10px 0;
        }
        .receipt-58mm .amount-label {
            font-size: 11px;
            font-weight: bold;
        }
        .receipt-58mm .amount-value {
            font-size: 16px;
            font-weight: bold;
        }
        .receipt-58mm .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px dashed #000;
        }
        <?php else: ?>
        /* A4 Receipt Style */
        body {
            font-family: 'Arial', sans-serif;
        }
        .receipt-a4 {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .receipt-a4 .letterhead {
            border-bottom: 3px solid #0066cc;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .receipt-a4 .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .receipt-a4 .logo-section img {
            max-width: 120px;
            max-height: 120px;
        }
        .receipt-a4 .university-info h1 {
            color: #0066cc;
            font-size: 28px;
            margin: 0 0 10px 0;
        }
        .receipt-a4 .university-info p {
            margin: 3px 0;
            font-size: 13px;
            color: #666;
        }
        .receipt-a4 .receipt-title {
            text-align: center;
            background: #0066cc;
            color: white;
            padding: 15px;
            margin: 30px 0;
            border-radius: 5px;
        }
        .receipt-a4 .receipt-title h2 {
            margin: 0;
            font-size: 24px;
        }
        .receipt-a4 .info-table {
            width: 100%;
            margin: 20px 0;
        }
        .receipt-a4 .info-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .receipt-a4 .info-label {
            font-weight: bold;
            width: 200px;
            color: #333;
        }
        .receipt-a4 .amount-box {
            background: #f8f9fa;
            border: 2px solid #0066cc;
            border-radius: 8px;
            padding: 25px;
            margin: 30px 0;
            text-align: center;
        }
        .receipt-a4 .amount-box .label {
            font-size: 16px;
            color: #666;
            margin-bottom: 10px;
        }
        .receipt-a4 .amount-box .amount {
            font-size: 36px;
            font-weight: bold;
            color: #0066cc;
        }
        .receipt-a4 .signature-section {
            margin-top: 80px;
            display: flex;
            justify-content: space-between;
        }
        .receipt-a4 .signature-box {
            text-align: center;
            width: 200px;
        }
        .receipt-a4 .signature-line {
            border-top: 2px solid #000;
            margin-top: 60px;
            padding-top: 5px;
        }
        .receipt-a4 .footer {
            margin-top: 50px;
            text-align: center;
            padding-top: 20px;
            border-top: 2px solid #eee;
            color: #666;
            font-size: 12px;
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <div class="no-print text-center p-3 bg-light">
        <button onclick="window.print()" class="btn btn-primary me-2">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
        <?php if ($format === '58mm'): ?>
            <a href="?id=<?php echo $transaction_id; ?>&format=a4" class="btn btn-secondary me-2">Switch to A4 Format</a>
        <?php else: ?>
            <a href="?id=<?php echo $transaction_id; ?>&format=58mm" class="btn btn-secondary me-2">Switch to 58mm Format</a>
        <?php endif; ?>
        <a href="student_finances.php" class="btn btn-outline-secondary">Back to Finance</a>
    </div>

    <?php if ($format === '58mm'): ?>
    <!-- 58mm Thermal Receipt -->
    <div class="receipt-58mm">
        <?php if ($university['logo_path'] && file_exists('../' . $university['logo_path'])): ?>
        <div class="logo">
            <img src="../<?php echo htmlspecialchars($university['logo_path']); ?>" alt="Logo">
        </div>
        <?php endif; ?>
        
        <div class="header">
            <h1><?php echo strtoupper($university['university_name']); ?></h1>
            <?php if ($university['address_po_box']): ?>
            <p><?php echo htmlspecialchars($university['address_po_box']); ?></p>
            <?php endif; ?>
            <?php if ($university['address_area']): ?>
            <p><?php echo htmlspecialchars($university['address_area']); ?></p>
            <?php endif; ?>
            <?php if ($university['address_street']): ?>
            <p><?php echo htmlspecialchars($university['address_street']); ?></p>
            <?php endif; ?>
            <p><?php echo htmlspecialchars($university['address_city']); ?>, <?php echo htmlspecialchars($university['address_country']); ?></p>
            <?php if ($university['phone']): ?>
            <p>Tel: <?php echo htmlspecialchars($university['phone']); ?></p>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin: 10px 0;">
            <strong style="font-size: 12px;">PAYMENT RECEIPT</strong>
        </div>

        <div class="info-row">
            <span class="info-label">Receipt No:</span>
            <span>#<?php echo str_pad($transaction['transaction_id'], 6, '0', STR_PAD_LEFT); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Date:</span>
            <span><?php echo date('d/m/Y H:i', strtotime($transaction['payment_date'])); ?></span>
        </div>

        <div class="divider"></div>

        <div class="info-row">
            <span class="info-label">Student ID:</span>
            <span><?php echo htmlspecialchars($transaction['student_id']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Name:</span>
        </div>
        <div style="font-size: 11px; margin: 2px 0 5px 0;">
            <?php echo htmlspecialchars($transaction['full_name']); ?>
        </div>
        <div class="info-row">
            <span class="info-label">Program:</span>
            <span><?php echo htmlspecialchars($transaction['department_code'] ?? 'N/A'); ?></span>
        </div>

        <div class="divider"></div>

        <div class="info-row">
            <span class="info-label">Payment For:</span>
        </div>
        <div style="font-size: 11px; margin: 2px 0 5px 0;">
            <?php echo htmlspecialchars($payment_type_label); ?>
        </div>
        <div class="info-row">
            <span class="info-label">Method:</span>
            <span><?php echo htmlspecialchars(ucfirst($transaction['payment_method'])); ?></span>
        </div>
        <?php if ($transaction['reference_number']): ?>
        <div class="info-row">
            <span class="info-label">Ref No:</span>
            <span><?php echo htmlspecialchars($transaction['reference_number']); ?></span>
        </div>
        <?php endif; ?>

        <div class="amount-section">
            <div class="amount-label">AMOUNT PAID</div>
            <div class="amount-value">K <?php echo number_format($transaction['amount'], 2); ?></div>
        </div>

        <div style="font-size: 10px; margin: 10px 0;">
            <div class="info-row">
                <span class="info-label">Received By:</span>
            </div>
            <div style="margin: 2px 0;">
                <?php echo htmlspecialchars($transaction['recorded_by']); ?>
            </div>
        </div>

        <div class="footer">
            <p><?php echo htmlspecialchars($university['receipt_footer_text']); ?></p>
            <p style="margin-top: 10px;">This is an official receipt</p>
            <?php if ($university['email']): ?>
            <p><?php echo htmlspecialchars($university['email']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- A4 Receipt -->
    <div class="receipt-a4">
        <div class="letterhead">
            <div class="logo-section">
                <?php if ($university['logo_path'] && file_exists('../' . $university['logo_path'])): ?>
                <img src="../<?php echo htmlspecialchars($university['logo_path']); ?>" alt="University Logo">
                <?php endif; ?>
                <div class="university-info">
                    <h1><?php echo htmlspecialchars($university['university_name']); ?></h1>
                    <?php if ($university['address_po_box']): ?>
                    <p><strong><?php echo htmlspecialchars($university['address_po_box']); ?></strong></p>
                    <?php endif; ?>
                    <p>
                        <?php 
                        $address_parts = array_filter([
                            $university['address_area'],
                            $university['address_street'],
                            $university['address_city'],
                            $university['address_country']
                        ]);
                        echo htmlspecialchars(implode(', ', $address_parts));
                        ?>
                    </p>
                    <?php if ($university['phone'] || $university['email']): ?>
                    <p>
                        <?php if ($university['phone']): ?>Tel: <?php echo htmlspecialchars($university['phone']); ?><?php endif; ?>
                        <?php if ($university['phone'] && $university['email']): ?> | <?php endif; ?>
                        <?php if ($university['email']): ?>Email: <?php echo htmlspecialchars($university['email']); ?><?php endif; ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="receipt-title">
            <h2>OFFICIAL PAYMENT RECEIPT</h2>
            <p style="margin: 5px 0 0 0;">Receipt No: #<?php echo str_pad($transaction['transaction_id'], 8, '0', STR_PAD_LEFT); ?></p>
        </div>

        <div style="text-align: right; margin-bottom: 20px; color: #666;">
            <strong>Date:</strong> <?php echo date('F d, Y', strtotime($transaction['payment_date'])); ?><br>
            <strong>Time:</strong> <?php echo date('h:i A', strtotime($transaction['payment_date'])); ?>
        </div>

        <table class="info-table">
            <tr>
                <td class="info-label">Student ID:</td>
                <td><?php echo htmlspecialchars($transaction['student_id']); ?></td>
            </tr>
            <tr>
                <td class="info-label">Student Name:</td>
                <td><?php echo htmlspecialchars($transaction['full_name']); ?></td>
            </tr>
            <tr>
                <td class="info-label">Program:</td>
                <td><?php echo htmlspecialchars($transaction['department_name'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($transaction['department_code'] ?? 'N/A'); ?>)</td>
            </tr>
            <tr>
                <td class="info-label">Email:</td>
                <td><?php echo htmlspecialchars($transaction['email']); ?></td>
            </tr>
            <?php if ($transaction['phone']): ?>
            <tr>
                <td class="info-label">Phone:</td>
                <td><?php echo htmlspecialchars($transaction['phone']); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <table class="info-table" style="margin-top: 30px;">
            <tr>
                <td class="info-label">Payment For:</td>
                <td><strong><?php echo htmlspecialchars($payment_type_label); ?></strong></td>
            </tr>
            <tr>
                <td class="info-label">Payment Method:</td>
                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $transaction['payment_method']))); ?></td>
            </tr>
            <?php if ($transaction['reference_number']): ?>
            <tr>
                <td class="info-label">Reference Number:</td>
                <td><?php echo htmlspecialchars($transaction['reference_number']); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($transaction['notes']): ?>
            <tr>
                <td class="info-label">Notes:</td>
                <td><?php echo htmlspecialchars($transaction['notes']); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <div class="amount-box">
            <div class="label">TOTAL AMOUNT PAID</div>
            <div class="amount">K <?php echo number_format($transaction['amount'], 2); ?></div>
            <div class="label" style="margin-top: 5px; font-size: 14px;">
                (<?php echo ucwords(convertNumberToWords($transaction['amount'])); ?> Kwacha Only)
            </div>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">
                    <?php echo htmlspecialchars($transaction['recorded_by']); ?><br>
                    Finance Officer
                </div>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    Student Signature
                </div>
            </div>
        </div>

        <div class="footer">
            <p><strong><?php echo htmlspecialchars($university['receipt_footer_text']); ?></strong></p>
            <p>This is a computer-generated receipt and is valid without signature.</p>
            <p>For any queries, please contact the Finance Office.</p>
            <?php if ($university['website']): ?>
            <p style="margin-top: 10px;"><?php echo htmlspecialchars($university['website']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Helper function to convert number to words
function convertNumberToWords($number) {
    $ones = array('', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine');
    $tens = array('', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety');
    $teens = array('ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen');
    
    $number = intval($number);
    
    if ($number < 10) {
        return $ones[$number];
    } elseif ($number < 20) {
        return $teens[$number - 10];
    } elseif ($number < 100) {
        return $tens[intval($number / 10)] . ' ' . $ones[$number % 10];
    } elseif ($number < 1000) {
        return $ones[intval($number / 100)] . ' hundred ' . convertNumberToWords($number % 100);
    } elseif ($number < 1000000) {
        return convertNumberToWords(intval($number / 1000)) . ' thousand ' . convertNumberToWords($number % 1000);
    } else {
        return convertNumberToWords(intval($number / 1000000)) . ' million ' . convertNumberToWords($number % 1000000);
    }
}
?>
