<?php
/**
 * Outstanding Balances Report - Printable report with university branding
 * Shows all students with outstanding balances
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff', 'admin']);

$conn = getDbConnection();

// Get outstanding balances for all students with additional details
$sql = "SELECT s.student_id, s.full_name, s.email, s.phone, s.program, s.year_of_study, s.semester,
        d.department_name, d.department_code,
        sf.expected_total, sf.total_paid, (sf.expected_total - sf.total_paid) AS outstanding
    FROM students s
    JOIN student_finances sf ON CAST(s.student_id AS CHAR) COLLATE utf8mb4_general_ci = CAST(sf.student_id AS CHAR) COLLATE utf8mb4_general_ci
    LEFT JOIN departments d ON s.department = d.department_id
    WHERE s.is_active = TRUE AND (sf.expected_total - sf.total_paid) > 0
    ORDER BY outstanding DESC";
$result = $conn->query($sql);

$outstandings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Calculate totals
$total_expected = 0;
$total_paid = 0;
$total_outstanding = 0;
foreach ($outstandings as $row) {
    $total_expected += $row['expected_total'];
    $total_paid += $row['total_paid'];
    $total_outstanding += $row['outstanding'];
}

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
    <title>Outstanding Balances - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-container { box-shadow: none !important; border: none !important; margin: 0 !important; }
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { margin: 0.5cm; size: A4 landscape; }
            * { font-size: 10px !important; }
            .print-header { padding: 10px !important; }
            .print-header img { max-height: 40px !important; }
            .print-header h1 { font-size: 14px !important; }
            .print-header p { font-size: 9px !important; margin: 1px 0 !important; }
            .report-title { padding: 6px !important; font-size: 12px !important; }
            .print-body { padding: 10px !important; }
            table th, table td { padding: 4px 6px !important; font-size: 9px !important; }
            .summary-box { padding: 8px !important; margin-bottom: 10px !important; }
            .summary-box h5 { font-size: 11px !important; }
            .print-footer { padding: 6px 10px !important; font-size: 8px !important; }
        }
        
        body {
            background: #f5f5f5;
            font-family: 'Arial', sans-serif;
        }
        
        .print-container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .print-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 15px;
            text-align: center;
        }
        
        .print-header img {
            max-height: 50px;
            margin-bottom: 5px;
        }
        
        .print-header h1 {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
        }
        
        .print-header p {
            margin: 2px 0 0 0;
            opacity: 0.9;
            font-size: 11px;
        }
        
        .report-title {
            background: #dc3545;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .print-body {
            padding: 15px 20px;
        }
        
        .summary-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #dc3545;
        }
        
        .summary-box h5 {
            color: #1e3c72;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .summary-stats {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
            min-width: 150px;
        }
        
        .stat-item .value {
            font-size: 20px;
            font-weight: bold;
            color: #1e3c72;
        }
        
        .stat-item .value.text-danger {
            color: #dc3545 !important;
        }
        
        .stat-item .label {
            font-size: 12px;
            color: #666;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table thead th {
            background: #dc3545;
            color: white;
            border: none;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .table tbody td {
            font-size: 12px;
            vertical-align: middle;
        }
        
        .print-footer {
            background: #f8f9fa;
            padding: 10px 20px;
            border-top: 1px solid #dee2e6;
            font-size: 10px;
            color: #666;
        }
        
        .print-buttons {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
        }
        
        .report-date {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            padding: 8px 15px;
            border-radius: 8px;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .report-date h6 {
            margin: 0;
            color: #1e3c72;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <!-- Print/Action Buttons -->
    <div class="print-buttons no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg me-2">
            <i class="bi bi-printer"></i> Print Report
        </button>
        <a href="dashboard.php" class="btn btn-secondary btn-lg">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <div class="print-container">
        <!-- Header with University Logo -->
        <div class="print-header">
            <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
            <h1><?php echo htmlspecialchars($university_name); ?></h1>
            <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($university_address); ?></p>
            <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($university_phone); ?> | <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($university_email); ?></p>
        </div>
        
        <!-- Report Title -->
        <div class="report-title">
            <i class="bi bi-exclamation-triangle"></i> OUTSTANDING BALANCES REPORT
        </div>
        
        <div class="print-body">
            <!-- Report Date -->
            <div class="text-center">
                <div class="report-date">
                    <h6><i class="bi bi-calendar3"></i> Report Generated: <?php echo date('F d, Y - h:i A'); ?></h6>
                </div>
            </div>
            
            <!-- Summary Statistics -->
            <div class="summary-box">
                <h5><i class="bi bi-bar-chart"></i> Summary</h5>
                <div class="summary-stats">
                    <div class="stat-item">
                        <div class="value"><?php echo count($outstandings); ?></div>
                        <div class="label">Students with Balance</div>
                    </div>
                    <div class="stat-item">
                        <div class="value">K<?php echo number_format($total_expected); ?></div>
                        <div class="label">Total Expected Fees</div>
                    </div>
                    <div class="stat-item">
                        <div class="value text-success">K<?php echo number_format($total_paid); ?></div>
                        <div class="label">Total Amount Paid</div>
                    </div>
                    <div class="stat-item">
                        <div class="value text-danger">K<?php echo number_format($total_outstanding); ?></div>
                        <div class="label">Total Outstanding</div>
                    </div>
                </div>
            </div>
            
            <!-- Outstanding Balances Table -->
            <div class="table-container">
                <table class="table table-bordered table-hover table-sm">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Year</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th class="text-end">Expected</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($outstandings) > 0): ?>
                        <?php $i = 1; foreach ($outstandings as $row): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><strong><?php echo htmlspecialchars($row['student_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['department_name'] ?? $row['program'] ?? 'N/A'); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($row['year_of_study'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                <td class="text-end">K<?php echo number_format($row['expected_total']); ?></td>
                                <td class="text-end text-success">K<?php echo number_format($row['total_paid']); ?></td>
                                <td class="text-end"><strong class="text-danger">K<?php echo number_format($row['outstanding']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Totals Row -->
                        <tr class="table-dark">
                            <td colspan="7" class="text-end"><strong>TOTALS:</strong></td>
                            <td class="text-end"><strong>K<?php echo number_format($total_expected); ?></strong></td>
                            <td class="text-end text-success"><strong>K<?php echo number_format($total_paid); ?></strong></td>
                            <td class="text-end text-danger"><strong>K<?php echo number_format($total_outstanding); ?></strong></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-4"></i><br>No outstanding balances found. All students are fully paid!</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Signature Section -->
            <div class="row mt-4 pt-3" style="border-top: 1px dashed #dee2e6;">
                <div class="col-4 text-center">
                    <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
                    <p class="mb-0"><strong>Prepared By</strong></p>
                    <small class="text-muted">Finance Officer</small>
                </div>
                <div class="col-4 text-center">
                    <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
                    <p class="mb-0"><strong>Verified By</strong></p>
                    <small class="text-muted">Senior Accountant</small>
                </div>
                <div class="col-4 text-center">
                    <div style="border-bottom: 1px solid #333; height: 40px; margin-bottom: 5px;"></div>
                    <p class="mb-0"><strong>Approved By</strong></p>
                    <small class="text-muted">Finance Director</small>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="print-footer">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0"><strong>Note:</strong> This is a computer-generated report.</p>
                    <p class="mb-0">For queries, contact: <?php echo htmlspecialchars($university_email); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-0"><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                    <p class="mb-0"><?php echo htmlspecialchars($university_website); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
