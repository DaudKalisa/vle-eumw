<?php
// finance/finance_reports.php - Comprehensive Financial Reports & Analytics
// Includes Balance Sheet, Income Statement, Cash Flow, Trial Balance, and more
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get date range for reports
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Helper function to format currency
function format_currency($amount) {
    return 'K' . number_format($amount ?? 0, 2);
}

// ===== FINANCIAL DATA QUERIES =====

// 1. Revenue Summary (by payment type and source)
$revenue_query = "SELECT payment_type, COUNT(*) as transaction_count, SUM(amount) as total 
                  FROM payment_transactions 
                  WHERE payment_date BETWEEN ? AND ? 
                  GROUP BY payment_type";
$stmt = $conn->prepare($revenue_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$revenue_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 2. Total Revenue
$total_revenue_query = "SELECT SUM(amount) as total FROM payment_transactions WHERE payment_date BETWEEN ? AND ?";
$stmt = $conn->prepare($total_revenue_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$total_revenue = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// 3. Expenses Summary
$expenses_query = "SELECT 'Lecturer Finance Requests' as expense_type, SUM(total_amount) as amount 
                   FROM lecturer_finance_requests 
                   WHERE status = 'paid' AND response_date BETWEEN ? AND ?";
$stmt = $conn->prepare($expenses_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$expenses_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_expenses = 0;
foreach ($expenses_data as $expense) {
    $total_expenses += $expense['amount'] ?? 0;
}

// 4. Net Income
$net_income = $total_revenue - $total_expenses;

// 5. Student Payments Breakdown
$student_payments_query = "SELECT 
                            sf.student_id as student_id,
                            s.full_name as student_name,
                            sf.total_paid,
                            sf.expected_total,
                            (sf.total_paid / NULLIF(sf.expected_total, 0) * 100) as payment_percentage,
                            CASE 
                                WHEN (sf.total_paid / NULLIF(sf.expected_total, 0) * 100) >= 100 THEN 'Full'
                                WHEN (sf.total_paid / NULLIF(sf.expected_total, 0) * 100) >= 75 THEN '75%'
                                WHEN (sf.total_paid / NULLIF(sf.expected_total, 0) * 100) >= 50 THEN '50%'
                                WHEN (sf.total_paid / NULLIF(sf.expected_total, 0) * 100) >= 20 THEN '20%'
                                ELSE '0%'
                            END as payment_status
                           FROM student_finances sf
                           LEFT JOIN students s ON sf.student_id = s.student_id
                           ORDER BY sf.total_paid DESC";
$student_payments = $conn->query($student_payments_query);

// 6. Accounts Receivable (Outstanding balances)
$ar_query = "SELECT COUNT(*) as student_count, SUM(expected_total - total_paid) as outstanding_balance
             FROM student_finances
             WHERE total_paid < expected_total";
$ar_data = $conn->query($ar_query)->fetch_assoc();

// 7. Defaulters List
$defaulters_query = "SELECT s.student_id, s.full_name, s.email, sf.total_paid, sf.expected_total, 
                           (sf.expected_total - sf.total_paid) as balance
                    FROM students s 
                    JOIN student_finances sf ON s.student_id = sf.student_id
                    WHERE sf.payment_percentage = 0 
                    ORDER BY sf.expected_total DESC";
$defaulters = $conn->query($defaulters_query);

// 8. Recent Transactions
$transactions_query = "SELECT pt.*, s.full_name 
                       FROM payment_transactions pt 
                       JOIN students s ON pt.student_id = s.student_id
                       WHERE pt.payment_date BETWEEN ? AND ? 
                       ORDER BY pt.payment_date DESC 
                       LIMIT 100";
$stmt = $conn->prepare($transactions_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$transactions = $stmt->get_result();

// 9. Lecture Finance Requests (Payroll)
$payroll_query = "SELECT lecturer_id, COUNT(*) as request_count, SUM(total_amount) as total_paid
                  FROM lecturer_finance_requests
                  WHERE status = 'paid' AND response_date BETWEEN ? AND ?
                  GROUP BY lecturer_id";
$stmt = $conn->prepare($payroll_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$payroll_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .financial-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 30px 0; margin-bottom: 30px; }
        .report-card { box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: none; margin-bottom: 20px; }
        .report-card .card-header { background: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; }
        .financial-metric { text-align: center; padding: 20px; }
        .financial-metric .metric-value { font-size: 28px; font-weight: bold; color: var(--vle-accent); }
        .financial-metric .metric-label { font-size: 14px; color: #666; margin-top: 5px; }
        .report-table { font-size: 14px; }
        .report-table th { background: #f8f9fa; font-weight: 600; border-top: 2px solid #dee2e6; }
        .positive { color: #28a745; font-weight: 600; }
        .negative { color: #dc3545; font-weight: 600; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'finance_reports';
    $pageTitle = 'Financial Reports';
    include 'header_nav.php'; 
    ?>

    <div class="financial-header">
        <div class="container-fluid">
            <h1 class="h3 mb-1"><i class="bi bi-graph-up me-2"></i>Financial Reports & Analytics</h1>
            <p class="mb-0">Comprehensive accounting and audit-compliant financial statements</p>
        </div>
    </div>

    <div class="vle-content">
        <!-- Date Range & Report Filter -->
        <div class="card report-card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0">Report Settings</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="bi bi-file-earmark"></i> Report Type</label>
                        <select name="report_type" class="form-select">
                            <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
                            <option value="income_statement" <?php echo $report_type === 'income_statement' ? 'selected' : ''; ?>>Income Statement</option>
                            <option value="balance_sheet" <?php echo $report_type === 'balance_sheet' ? 'selected' : ''; ?>>Balance Sheet</option>
                            <option value="cash_flow" <?php echo $report_type === 'cash_flow' ? 'selected' : ''; ?>>Cash Flow</option>
                            <option value="accounts_receivable" <?php echo $report_type === 'accounts_receivable' ? 'selected' : ''; ?>>Accounts Receivable</option>
                            <option value="trial_balance" <?php echo $report_type === 'trial_balance' ? 'selected' : ''; ?>>Trial Balance</option>
                            <option value="audit_trail" <?php echo $report_type === 'audit_trail' ? 'selected' : ''; ?>>Audit Trail</option>
                            <option value="fee_collection" <?php echo $report_type === 'fee_collection' ? 'selected' : ''; ?>>Fee Collection Summary</option>
                            <option value="defaulters" <?php echo $report_type === 'defaulters' ? 'selected' : ''; ?>>Defaulters Report</option>
                            <option value="installment_tracking" <?php echo $report_type === 'installment_tracking' ? 'selected' : ''; ?>>Installment Tracking</option>
                            <option value="revenue_by_program" <?php echo $report_type === 'revenue_by_program' ? 'selected' : ''; ?>>Revenue by Program</option>
                            <option value="payment_method_analysis" <?php echo $report_type === 'payment_method_analysis' ? 'selected' : ''; ?>>Payment Method Analysis</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Generate Report</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report_type === 'overview' || $report_type === ''): ?>
        <!-- ===== FINANCIAL OVERVIEW ===== -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="report-card">
                    <div class="financial-metric">
                        <div class="metric-value positive"><?php echo format_currency($total_revenue); ?></div>
                        <div class="metric-label">Total Revenue</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="report-card">
                    <div class="financial-metric">
                        <div class="metric-value negative"><?php echo format_currency($total_expenses); ?></div>
                        <div class="metric-label">Total Expenses</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="report-card">
                    <div class="financial-metric">
                        <div class="metric-value <?php echo $net_income >= 0 ? 'positive' : 'negative'; ?>"><?php echo format_currency($net_income); ?></div>
                        <div class="metric-label">Net Income</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="report-card">
                    <div class="financial-metric">
                        <div class="metric-value"><?php echo format_currency($ar_data['outstanding_balance'] ?? 0); ?></div>
                        <div class="metric-label">Accounts Receivable</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Breakdown -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card report-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Revenue by Payment Type</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm report-table">
                            <thead>
                                <tr>
                                    <th>Payment Type</th>
                                    <th class="text-end">Transactions</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revenue_data as $revenue): ?>
                                    <tr>
                                        <td><?php echo ucwords(str_replace('_', ' ', $revenue['payment_type'])); ?></td>
                                        <td class="text-end"><?php echo $revenue['transaction_count']; ?></td>
                                        <td class="text-end positive"><?php echo format_currency($revenue['total']); ?></td>
                                        <td class="text-end"><?php echo number_format(($revenue['total'] / $total_revenue * 100), 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <th>TOTAL</th>
                                    <th class="text-end"></th>
                                    <th class="text-end positive"><?php echo format_currency($total_revenue); ?></th>
                                    <th class="text-end">100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Expenses Breakdown -->
            <div class="col-md-6">
                <div class="card report-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-wallet2"></i> Expenses Summary</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm report-table">
                            <thead>
                                <tr>
                                    <th>Expense Type</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses_data as $expense): ?>
                                    <tr>
                                        <td><?php echo $expense['expense_type']; ?></td>
                                        <td class="text-end negative"><?php echo format_currency($expense['amount']); ?></td>
                                        <td class="text-end"><?php echo $total_expenses > 0 ? number_format(($expense['amount'] / $total_expenses * 100), 1) : 0; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <th>TOTAL EXPENSES</th>
                                    <th class="text-end negative"><?php echo format_currency($total_expenses); ?></th>
                                    <th class="text-end">100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="card report-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Recent Transactions (Last 100)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 report-table">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Payment Type</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while ($trans = $transactions->fetch_assoc()): ?>
                                    <tr>
                                        <td><small><?php echo date('M d, Y', strtotime($trans['payment_date'])); ?></small></td>
                                        <td><?php echo htmlspecialchars($trans['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($trans['full_name']); ?></td>
                                        <td><small><?php echo ucwords(str_replace('_', ' ', $trans['payment_type'])); ?></small></td>
                                        <td class="text-end positive"><strong><?php echo format_currency($trans['amount']); ?></strong></td>
                                        <td><small><?php echo ucwords(str_replace('_', ' ', $trans['payment_method'])); ?></small></td>
                                        <td><small><?php echo htmlspecialchars($trans['reference_number'] ?? '-'); ?></small></td>
                                        <td><small><?php echo htmlspecialchars($trans['recorded_by']); ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No transactions in selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($report_type === 'income_statement'): ?>
        <!-- ===== INCOME STATEMENT ===== -->
        <div class="card report-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Income Statement</h5>
                <small class="text-muted">For the period <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 60%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY
                            </th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">Lilongwe, Malawi | Tel: +265 1 123 456 | Email: finance@exploits.ac.mw</small></th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; padding-top: 15px;">Statement of Comprehensive Income</th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">For the period ending <?php echo date('d M Y', strtotime($end_date)); ?></small></th>
                        </tr>
                    </thead>
                </table>
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto; margin-top: 30px;">
                    <tbody>
                        <tr>
                            <td><strong>REVENUE</strong></td>
                            <td></td>
                        </tr>
                        <?php foreach ($revenue_data as $revenue): ?>
                            <tr>
                                <td style="padding-left: 40px;"><?php echo ucwords(str_replace('_', ' ', $revenue['payment_type'])); ?></td>
                                <td class="text-end"><?php echo format_currency($revenue['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px solid #000; border-bottom: 2px solid #000;">
                            <td><strong>TOTAL REVENUE</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($total_revenue); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr>
                            <td><strong>EXPENSES</strong></td>
                            <td></td>
                        </tr>
                        <?php foreach ($expenses_data as $expense): ?>
                            <tr>
                                <td style="padding-left: 40px;"><?php echo $expense['expense_type']; ?></td>
                                <td class="text-end"><?php echo format_currency($expense['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px solid #000; border-bottom: 2px solid #000;">
                            <td><strong>TOTAL EXPENSES</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($total_expenses); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr style="background: #f8f9fa; font-weight: bold;">
                            <td><strong>NET INCOME / (LOSS)</strong></td>
                            <td class="text-end"><strong class="<?php echo $net_income >= 0 ? 'positive' : 'negative'; ?>"><?php echo format_currency($net_income); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'accounts_receivable'): ?>
        <!-- ===== ACCOUNTS RECEIVABLE REPORT ===== -->
        <div class="card report-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-card-checklist"></i> Accounts Receivable (Outstanding Balances)</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="alert alert-warning">
                            <strong>Total Outstanding:</strong><br>
                            <span class="negative" style="font-size: 20px;"><?php echo format_currency($ar_data['outstanding_balance'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info">
                            <strong>Students with Balance:</strong><br>
                            <span style="font-size: 20px; color: #0066cc;"><?php echo $ar_data['student_count'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped report-table">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-end">Amount Due</th>
                                <th class="text-end">Outstanding Balance</th>
                                <th class="text-end">Payment %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $student_payments->data_seek(0);
                            while ($payment = $student_payments->fetch_assoc()): 
                                if ($payment['total_paid'] < $payment['expected_total']):
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['student_name'] ?? 'N/A'); ?></td>
                                    <td class="text-end positive"><?php echo format_currency($payment['total_paid']); ?></td>
                                    <td class="text-end"><?php echo format_currency($payment['expected_total']); ?></td>
                                    <td class="text-end negative"><?php echo format_currency($payment['expected_total'] - $payment['total_paid']); ?></td>
                                    <td class="text-end"><?php echo number_format($payment['payment_percentage'], 1); ?>%</td>
                                </tr>
                            <?php endif; endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'balance_sheet'): ?>
        <!-- ===== BALANCE SHEET ===== -->
        <?php
        // Calculate balance sheet values
        $cash_balance = $total_revenue - $total_expenses; // Net cash from operations
        $accounts_receivable = $ar_data['outstanding_balance'] ?? 0;
        $total_assets = $cash_balance + $accounts_receivable;
        
        // Liabilities (payables to lecturers, pending requests)
        $pending_payables_query = "SELECT SUM(total_amount) as pending FROM lecturer_finance_requests WHERE status = 'pending' OR status = 'approved'";
        $pending_payables = $conn->query($pending_payables_query)->fetch_assoc()['pending'] ?? 0;
        
        $total_liabilities = $pending_payables;
        $equity = $total_assets - $total_liabilities;
        ?>
        <div class="card report-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Balance Sheet</h5>
                <small>As at <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY
                            </th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">Lilongwe, Malawi | Tel: +265 1 123 456 | Email: finance@exploits.ac.mw</small></th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; padding-top: 15px;">STATEMENT OF FINANCIAL POSITION (BALANCE SHEET)</th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">As at <?php echo date('d M Y', strtotime($end_date)); ?></small></th>
                        </tr>
                    </thead>
                </table>
                
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto; margin-top: 30px;">
                    <tbody>
                        <!-- ASSETS SECTION -->
                        <tr style="background: #e3f2fd;">
                            <td colspan="2"><strong>ASSETS</strong></td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Current Assets</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Cash and Cash Equivalents</td>
                            <td class="text-end"><?php echo format_currency($cash_balance); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Accounts Receivable (Student Fees)</td>
                            <td class="text-end"><?php echo format_currency($accounts_receivable); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Prepaid Expenses</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #000;">
                            <td><strong>Total Current Assets</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($total_assets); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Non-Current Assets</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Property, Plant & Equipment</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Intangible Assets</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #000;">
                            <td><strong>Total Non-Current Assets</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(0); ?></strong></td>
                        </tr>
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>TOTAL ASSETS</strong></td>
                            <td class="text-end"><strong class="positive"><?php echo format_currency($total_assets); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <!-- LIABILITIES SECTION -->
                        <tr style="background: #fff3e0;">
                            <td colspan="2"><strong>LIABILITIES</strong></td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Current Liabilities</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Accounts Payable (Lecturers)</td>
                            <td class="text-end"><?php echo format_currency($pending_payables); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Accrued Expenses</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Deferred Revenue</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #000;">
                            <td><strong>Total Current Liabilities</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($total_liabilities); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Non-Current Liabilities</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Long-term Debt</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #000;">
                            <td><strong>Total Non-Current Liabilities</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(0); ?></strong></td>
                        </tr>
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>TOTAL LIABILITIES</strong></td>
                            <td class="text-end"><strong class="negative"><?php echo format_currency($total_liabilities); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <!-- EQUITY SECTION -->
                        <tr style="background: #e8f5e9;">
                            <td colspan="2"><strong>EQUITY</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Retained Earnings</td>
                            <td class="text-end"><?php echo format_currency($equity); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Current Period Net Income</td>
                            <td class="text-end"><?php echo format_currency($net_income); ?></td>
                        </tr>
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>TOTAL EQUITY</strong></td>
                            <td class="text-end"><strong class="positive"><?php echo format_currency($equity); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <tr style="border-top: 3px double #000; background: #e3f2fd;">
                            <td><strong>TOTAL LIABILITIES + EQUITY</strong></td>
                            <td class="text-end"><strong><?php echo format_currency($total_liabilities + $equity); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="mt-4 text-center">
                    <small class="text-muted">
                        <em>Note: This balance sheet reflects data captured in the VLE Finance System. 
                        Fixed assets and long-term liabilities may be recorded in the main university accounting system.</em>
                    </small>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'cash_flow'): ?>
        <!-- ===== CASH FLOW STATEMENT ===== -->
        <?php
        // Cash Flow calculations
        // Operating Activities
        $cash_from_tuition = 0;
        $cash_from_registration = 0;
        $cash_from_application = 0;
        $cash_from_other = 0;
        
        foreach ($revenue_data as $revenue) {
            switch ($revenue['payment_type']) {
                case 'tuition':
                case 'installment_1':
                case 'installment_2':
                case 'installment_3':
                case 'installment_4':
                    $cash_from_tuition += $revenue['total'];
                    break;
                case 'registration_fee':
                    $cash_from_registration += $revenue['total'];
                    break;
                case 'application':
                    $cash_from_application += $revenue['total'];
                    break;
                default:
                    $cash_from_other += $revenue['total'];
            }
        }
        
        // Cash outflows
        $lecturer_payments = $total_expenses;
        
        // Net cash from operating activities
        $net_operating = $total_revenue - $total_expenses;
        
        // Beginning cash balance (simplified - using revenue from before start_date)
        $beginning_cash_query = "SELECT SUM(amount) as total FROM payment_transactions WHERE payment_date < ?";
        $stmt = $conn->prepare($beginning_cash_query);
        $stmt->bind_param("s", $start_date);
        $stmt->execute();
        $beginning_cash = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        
        // Less expenses before start_date
        $beginning_expenses_query = "SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE status = 'paid' AND response_date < ?";
        $stmt = $conn->prepare($beginning_expenses_query);
        $stmt->bind_param("s", $start_date);
        $stmt->execute();
        $beginning_expenses = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        
        $beginning_balance = $beginning_cash - $beginning_expenses;
        $ending_balance = $beginning_balance + $net_operating;
        ?>
        <div class="card report-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> Statement of Cash Flows</h5>
                <small>For the period <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY
                            </th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">Lilongwe, Malawi | Tel: +265 1 123 456 | Email: finance@exploits.ac.mw</small></th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center" style="font-weight: bold; padding-top: 15px;">STATEMENT OF CASH FLOWS</th>
                        </tr>
                        <tr>
                            <th colspan="2" class="text-center"><small class="text-muted">For the period <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></small></th>
                        </tr>
                    </thead>
                </table>
                
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto; margin-top: 30px;">
                    <tbody>
                        <!-- OPERATING ACTIVITIES -->
                        <tr style="background: #e3f2fd;">
                            <td colspan="2"><strong>CASH FLOWS FROM OPERATING ACTIVITIES</strong></td>
                        </tr>
                        <tr>
                            <td colspan="2"><strong>Cash Inflows:</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Tuition Fee Collections</td>
                            <td class="text-end positive"><?php echo format_currency($cash_from_tuition); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Registration Fee Collections</td>
                            <td class="text-end positive"><?php echo format_currency($cash_from_registration); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Application Fee Collections</td>
                            <td class="text-end positive"><?php echo format_currency($cash_from_application); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Other Fee Collections</td>
                            <td class="text-end positive"><?php echo format_currency($cash_from_other); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #ccc;">
                            <td><strong>Total Cash Inflows</strong></td>
                            <td class="text-end"><strong class="positive"><?php echo format_currency($total_revenue); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <tr>
                            <td colspan="2"><strong>Cash Outflows:</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Lecturer Payments</td>
                            <td class="text-end negative">(<?php echo format_currency($lecturer_payments); ?>)</td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Administrative Expenses</td>
                            <td class="text-end negative">(<?php echo format_currency(0); ?>)</td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Other Operating Expenses</td>
                            <td class="text-end negative">(<?php echo format_currency(0); ?>)</td>
                        </tr>
                        <tr style="border-top: 1px solid #ccc;">
                            <td><strong>Total Cash Outflows</strong></td>
                            <td class="text-end"><strong class="negative">(<?php echo format_currency($total_expenses); ?>)</strong></td>
                        </tr>
                        
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>Net Cash from Operating Activities</strong></td>
                            <td class="text-end"><strong class="<?php echo $net_operating >= 0 ? 'positive' : 'negative'; ?>"><?php echo format_currency($net_operating); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <!-- INVESTING ACTIVITIES -->
                        <tr style="background: #fff3e0;">
                            <td colspan="2"><strong>CASH FLOWS FROM INVESTING ACTIVITIES</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Purchase of Equipment</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Sale of Assets</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>Net Cash from Investing Activities</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(0); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <!-- FINANCING ACTIVITIES -->
                        <tr style="background: #e8f5e9;">
                            <td colspan="2"><strong>CASH FLOWS FROM FINANCING ACTIVITIES</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Loans Received</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 40px;">Loan Repayments</td>
                            <td class="text-end"><?php echo format_currency(0); ?></td>
                        </tr>
                        <tr style="border-top: 2px solid #000; background: #f8f9fa;">
                            <td><strong>Net Cash from Financing Activities</strong></td>
                            <td class="text-end"><strong><?php echo format_currency(0); ?></strong></td>
                        </tr>
                        
                        <tr><td colspan="2">&nbsp;</td></tr>
                        
                        <!-- SUMMARY -->
                        <tr style="border-top: 3px double #000; background: #e3f2fd;">
                            <td><strong>NET INCREASE/(DECREASE) IN CASH</strong></td>
                            <td class="text-end"><strong class="<?php echo $net_operating >= 0 ? 'positive' : 'negative'; ?>"><?php echo format_currency($net_operating); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Cash at Beginning of Period</td>
                            <td class="text-end"><?php echo format_currency($beginning_balance); ?></td>
                        </tr>
                        <tr style="border-top: 3px double #000; background: #d4edda;">
                            <td><strong>CASH AT END OF PERIOD</strong></td>
                            <td class="text-end"><strong class="positive" style="font-size: 18px;"><?php echo format_currency($ending_balance); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="mt-4 text-center">
                    <small class="text-muted">
                        <em>Note: This statement uses the Direct Method of reporting cash flows. 
                        Non-cash transactions are not reflected in this statement.</em>
                    </small>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'trial_balance'): ?>
        <!-- ===== TRIAL BALANCE ===== -->
        <div class="card report-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-scale"></i> Trial Balance</h5>
                <small class="text-muted">As at <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 70%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="3" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY
                            </th>
                        </tr>
                        <tr>
                            <th colspan="3" class="text-center"><small class="text-muted">Lilongwe, Malawi | Tel: +265 1 123 456 | Email: finance@exploits.ac.mw</small></th>
                        </tr>
                        <tr>
                            <th colspan="3" class="text-center" style="font-weight: bold; padding-top: 15px;">TRIAL BALANCE</th>
                        </tr>
                        <tr>
                            <th colspan="3" class="text-center"><small class="text-muted">As at <?php echo date('d M Y', strtotime($end_date)); ?></small></th>
                        </tr>
                    </thead>
                    <thead class="table-light">
                        <tr>
                            <th>Account</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Cash & Bank Accounts</strong></td>
                            <td class="text-end"><?php echo format_currency($total_revenue); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Accounts Receivable</strong></td>
                            <td class="text-end"><?php echo format_currency($ar_data['outstanding_balance'] ?? 0); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Expenses</strong></td>
                            <td class="text-end"><?php echo format_currency($total_expenses); ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Revenue Account</strong></td>
                            <td></td>
                            <td class="text-end"><?php echo format_currency($total_revenue); ?></td>
                        </tr>
                        <tr style="border-top: 1px solid #000; border-bottom: 2px solid #000;">
                            <th>TOTALS</th>
                            <th class="text-end"><strong><?php echo format_currency($total_revenue + $total_expenses + $ar_data['outstanding_balance']); ?></strong></th>
                            <th class="text-end"><strong><?php echo format_currency($total_revenue); ?></strong></th>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'audit_trail'): ?>
        <!-- ===== AUDIT TRAIL ===== -->
        <?php
        // Get all financial transactions with details for audit
        $audit_query = "SELECT 
                            'Payment' as transaction_type,
                            pt.transaction_id as ref_id,
                            pt.payment_date as transaction_date,
                            pt.created_at,
                            pt.student_id,
                            s.full_name as party_name,
                            pt.payment_type as description,
                            pt.amount,
                            'Credit' as entry_type,
                            pt.recorded_by as user_action,
                            pt.reference_number
                        FROM payment_transactions pt
                        LEFT JOIN students s ON pt.student_id = s.student_id
                        WHERE pt.payment_date BETWEEN ? AND ?
                        
                        UNION ALL
                        
                        SELECT 
                            'Lecturer Payment' as transaction_type,
                            lfr.request_id as ref_id,
                            lfr.response_date as transaction_date,
                            lfr.request_date as created_at,
                            lfr.lecturer_id as student_id,
                            l.full_name as party_name,
                            CONCAT('Lecturer Finance Request - ', lfr.status) as description,
                            lfr.total_amount as amount,
                            'Debit' as entry_type,
                            CAST(lfr.lecturer_id AS CHAR) as user_action,
                            CONCAT('REQ-', lfr.request_id) as reference_number
                        FROM lecturer_finance_requests lfr
                        LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
                        WHERE lfr.status = 'paid' AND lfr.response_date BETWEEN ? AND ?
                        
                        ORDER BY transaction_date DESC, created_at DESC";
        
        $stmt = $conn->prepare($audit_query);
        $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
        $stmt->execute();
        $audit_results = $stmt->get_result();
        
        $total_credits = 0;
        $total_debits = 0;
        ?>
        <div class="card report-card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-journal-text"></i> Audit Trail Report</h5>
                <small>For the period <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="8" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - AUDIT TRAIL
                            </th>
                        </tr>
                        <tr>
                            <th colspan="8" class="text-center"><small class="text-muted">Complete record of all financial transactions</small></th>
                        </tr>
                    </thead>
                </table>
                
                <div class="table-responsive mt-4">
                    <table class="table table-striped table-hover report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date/Time</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Party</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($audit_results->num_rows > 0): ?>
                                <?php while ($audit = $audit_results->fetch_assoc()): 
                                    if ($audit['entry_type'] === 'Credit') {
                                        $total_credits += $audit['amount'];
                                    } else {
                                        $total_debits += $audit['amount'];
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <small>
                                                <?php echo date('M d, Y', strtotime($audit['transaction_date'])); ?><br>
                                                <span class="text-muted"><?php echo date('h:i A', strtotime($audit['created_at'])); ?></span>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $audit['transaction_type'] === 'Payment' ? 'bg-success' : 'bg-warning'; ?>">
                                                <?php echo $audit['transaction_type']; ?>
                                            </span>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($audit['reference_number'] ?? '-'); ?></small></td>
                                        <td>
                                            <small>
                                                <?php echo htmlspecialchars($audit['party_name'] ?? 'N/A'); ?><br>
                                                <span class="text-muted"><?php echo htmlspecialchars($audit['student_id']); ?></span>
                                            </small>
                                        </td>
                                        <td><small><?php echo ucwords(str_replace('_', ' ', $audit['description'])); ?></small></td>
                                        <td class="text-end">
                                            <?php if ($audit['entry_type'] === 'Debit'): ?>
                                                <strong class="negative"><?php echo format_currency($audit['amount']); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($audit['entry_type'] === 'Credit'): ?>
                                                <strong class="positive"><?php echo format_currency($audit['amount']); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($audit['user_action'] ?? 'System'); ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No transactions in selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr style="border-top: 3px double #000;">
                                <th colspan="5" class="text-end">TOTALS:</th>
                                <th class="text-end negative"><?php echo format_currency($total_debits); ?></th>
                                <th class="text-end positive"><?php echo format_currency($total_credits); ?></th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="5" class="text-end">NET POSITION:</th>
                                <th colspan="2" class="text-center <?php echo ($total_credits - $total_debits) >= 0 ? 'positive' : 'negative'; ?>">
                                    <strong><?php echo format_currency($total_credits - $total_debits); ?></strong>
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">
                                <strong>Report Generated:</strong><br>
                                <?php echo date('F d, Y \a\t h:i:s A'); ?>
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">
                                <strong>Generated By:</strong><br>
                                <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?>
                            </small>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">
                                <strong>Total Entries:</strong><br>
                                <?php 
                                $audit_results->data_seek(0);
                                echo $audit_results->num_rows; 
                                ?> transactions
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'fee_collection'): ?>
        <!-- ===== FEE COLLECTION SUMMARY ===== -->
        <?php
        // Fee collection by program
        $fee_collection_query = "SELECT 
            s.program,
            s.year_of_study,
            COUNT(DISTINCT sf.student_id) as total_students,
            SUM(sf.expected_total) as total_expected,
            SUM(sf.total_paid) as total_collected,
            SUM(sf.expected_total - sf.total_paid) as total_outstanding,
            ROUND(AVG(sf.payment_percentage), 1) as avg_payment_pct,
            SUM(CASE WHEN sf.payment_percentage >= 100 THEN 1 ELSE 0 END) as fully_paid,
            SUM(CASE WHEN sf.payment_percentage > 0 AND sf.payment_percentage < 100 THEN 1 ELSE 0 END) as partial_paid,
            SUM(CASE WHEN sf.payment_percentage = 0 OR sf.payment_percentage IS NULL THEN 1 ELSE 0 END) as not_paid
        FROM student_finances sf
        LEFT JOIN students s ON sf.student_id = s.student_id
        GROUP BY s.program, s.year_of_study
        ORDER BY s.program, s.year_of_study";
        $fee_collection = $conn->query($fee_collection_query);

        // Overall totals
        $fee_totals_query = "SELECT 
            COUNT(DISTINCT sf.student_id) as total_students,
            SUM(sf.expected_total) as grand_expected,
            SUM(sf.total_paid) as grand_collected,
            SUM(sf.expected_total - sf.total_paid) as grand_outstanding,
            ROUND(AVG(sf.payment_percentage), 1) as overall_avg_pct,
            SUM(CASE WHEN sf.payment_percentage >= 100 THEN 1 ELSE 0 END) as total_fully_paid,
            SUM(CASE WHEN sf.payment_percentage = 0 OR sf.payment_percentage IS NULL THEN 1 ELSE 0 END) as total_not_paid
        FROM student_finances sf";
        $fee_totals = $conn->query($fee_totals_query)->fetch_assoc();

        // Collection by year of study
        $year_collection_query = "SELECT 
            s.year_of_study,
            COUNT(DISTINCT sf.student_id) as students,
            SUM(sf.total_paid) as collected,
            SUM(sf.expected_total) as expected
        FROM student_finances sf
        LEFT JOIN students s ON sf.student_id = s.student_id
        GROUP BY s.year_of_study
        ORDER BY s.year_of_study";
        $year_collection = $conn->query($year_collection_query);
        ?>
        <div class="card report-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Fee Collection Summary Report</h5>
                <small>Academic fee collection analysis across all programs</small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="11" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - FEE COLLECTION SUMMARY
                            </th>
                        </tr>
                        <tr>
                            <th colspan="11" class="text-center"><small class="text-muted">Complete breakdown of fee collection by program and year of study</small></th>
                        </tr>
                    </thead>
                </table>

                <!-- Summary Cards -->
                <div class="row mb-4 mt-3">
                    <div class="col-md-3">
                        <div class="alert alert-success mb-0">
                            <strong>Total Collected</strong><br>
                            <span style="font-size: 20px;"><?php echo format_currency($fee_totals['grand_collected'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-danger mb-0">
                            <strong>Total Outstanding</strong><br>
                            <span style="font-size: 20px;"><?php echo format_currency($fee_totals['grand_outstanding'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="alert alert-info mb-0">
                            <strong>Total Students</strong><br>
                            <span style="font-size: 20px;"><?php echo number_format($fee_totals['total_students'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="alert alert-primary mb-0">
                            <strong>Fully Paid</strong><br>
                            <span style="font-size: 20px;"><?php echo number_format($fee_totals['total_fully_paid'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="alert alert-warning mb-0">
                            <strong>Avg Collection</strong><br>
                            <span style="font-size: 20px;"><?php echo $fee_totals['overall_avg_pct'] ?? 0; ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Collection by Year -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-bar-chart"></i> Collection by Year of Study</h6>
                <div class="row mb-4">
                    <?php while ($yc = $year_collection->fetch_assoc()): 
                        $yc_pct = ($yc['expected'] > 0) ? round(($yc['collected'] / $yc['expected']) * 100, 1) : 0;
                    ?>
                    <div class="col-md-3 mb-2">
                        <div class="card">
                            <div class="card-body py-2 px-3">
                                <strong>Year <?php echo $yc['year_of_study']; ?></strong>
                                <span class="float-end badge bg-<?php echo $yc_pct >= 75 ? 'success' : ($yc_pct >= 50 ? 'warning' : 'danger'); ?>"><?php echo $yc_pct; ?>%</span>
                                <div class="progress mt-2" style="height: 8px;">
                                    <div class="progress-bar bg-<?php echo $yc_pct >= 75 ? 'success' : ($yc_pct >= 50 ? 'warning' : 'danger'); ?>" style="width: <?php echo $yc_pct; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo format_currency($yc['collected']); ?> of <?php echo format_currency($yc['expected']); ?> (<?php echo $yc['students']; ?> students)</small>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <!-- Detailed Table -->
                <div class="table-responsive mt-3">
                    <table class="table table-striped table-hover report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Program</th>
                                <th class="text-center">Year</th>
                                <th class="text-center">Students</th>
                                <th class="text-end">Expected Fees</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-center">Avg %</th>
                                <th class="text-center">Fully Paid</th>
                                <th class="text-center">Partial</th>
                                <th class="text-center">Not Paid</th>
                                <th class="text-center">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_students = 0; $grand_expected = 0; $grand_collected = 0; $grand_outstanding = 0;
                            $grand_full = 0; $grand_partial = 0; $grand_none = 0;
                            if ($fee_collection->num_rows > 0):
                                while ($fc = $fee_collection->fetch_assoc()): 
                                    $grand_students += $fc['total_students'];
                                    $grand_expected += $fc['total_expected'];
                                    $grand_collected += $fc['total_collected'];
                                    $grand_outstanding += $fc['total_outstanding'];
                                    $grand_full += $fc['fully_paid'];
                                    $grand_partial += $fc['partial_paid'];
                                    $grand_none += $fc['not_paid'];
                                    $rate = ($fc['total_expected'] > 0) ? round(($fc['total_collected'] / $fc['total_expected']) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fc['program'] ?? 'Unassigned'); ?></td>
                                    <td class="text-center"><?php echo $fc['year_of_study'] ?? '-'; ?></td>
                                    <td class="text-center"><?php echo $fc['total_students']; ?></td>
                                    <td class="text-end"><?php echo format_currency($fc['total_expected']); ?></td>
                                    <td class="text-end positive"><?php echo format_currency($fc['total_collected']); ?></td>
                                    <td class="text-end negative"><?php echo format_currency($fc['total_outstanding']); ?></td>
                                    <td class="text-center"><?php echo $fc['avg_payment_pct']; ?>%</td>
                                    <td class="text-center"><span class="badge bg-success"><?php echo $fc['fully_paid']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?php echo $fc['partial_paid']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?php echo $fc['not_paid']; ?></span></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $rate >= 75 ? 'success' : ($rate >= 50 ? 'warning text-dark' : 'danger'); ?>"><?php echo $rate; ?>%</span>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="11" class="text-center py-4 text-muted">No fee data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr style="border-top: 3px double #000;">
                                <th>GRAND TOTALS</th>
                                <th></th>
                                <th class="text-center"><?php echo $grand_students; ?></th>
                                <th class="text-end"><?php echo format_currency($grand_expected); ?></th>
                                <th class="text-end positive"><?php echo format_currency($grand_collected); ?></th>
                                <th class="text-end negative"><?php echo format_currency($grand_outstanding); ?></th>
                                <th class="text-center"><?php echo $grand_expected > 0 ? round(($grand_collected / $grand_expected) * 100, 1) : 0; ?>%</th>
                                <th class="text-center"><?php echo $grand_full; ?></th>
                                <th class="text-center"><?php echo $grand_partial; ?></th>
                                <th class="text-center"><?php echo $grand_none; ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Report Generated:</strong> <?php echo date('F d, Y \a\t h:i:s A'); ?></small>
                        </div>
                        <div class="col-md-6 text-end">
                            <small class="text-muted"><strong>Generated By:</strong> <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'defaulters'): ?>
        <!-- ===== DEFAULTERS REPORT ===== -->
        <?php
        // Full defaulters: 0% payment
        $full_defaulters_query = "SELECT 
            s.student_id, s.full_name, s.email, s.phone, s.program, s.year_of_study, s.campus,
            sf.expected_total, sf.total_paid, 
            (sf.expected_total - sf.total_paid) as balance,
            sf.payment_percentage,
            sf.last_payment_date
        FROM students s
        JOIN student_finances sf ON s.student_id = sf.student_id
        WHERE sf.payment_percentage < 20
        ORDER BY (sf.expected_total - sf.total_paid) DESC";
        $full_defaulters = $conn->query($full_defaulters_query);

        // Defaulter stats
        $def_stats_query = "SELECT 
            COUNT(*) as total_defaulters,
            SUM(sf.expected_total - sf.total_paid) as total_owed,
            SUM(CASE WHEN sf.payment_percentage = 0 OR sf.payment_percentage IS NULL THEN 1 ELSE 0 END) as zero_payment,
            SUM(CASE WHEN sf.payment_percentage > 0 AND sf.payment_percentage < 20 THEN 1 ELSE 0 END) as under_20,
            AVG(sf.expected_total - sf.total_paid) as avg_debt
        FROM students s
        JOIN student_finances sf ON s.student_id = sf.student_id
        WHERE sf.payment_percentage < 20";
        $def_stats = $conn->query($def_stats_query)->fetch_assoc();

        // Defaulters by program
        $def_by_prog_query = "SELECT 
            s.program,
            COUNT(*) as count,
            SUM(sf.expected_total - sf.total_paid) as outstanding
        FROM students s
        JOIN student_finances sf ON s.student_id = sf.student_id
        WHERE sf.payment_percentage < 20
        GROUP BY s.program
        ORDER BY outstanding DESC";
        $def_by_prog = $conn->query($def_by_prog_query);
        ?>
        <div class="card report-card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Defaulters Report</h5>
                <small>Students with less than 20% fee payment — requires immediate follow-up</small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="10" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - DEFAULTERS REPORT
                            </th>
                        </tr>
                        <tr>
                            <th colspan="10" class="text-center"><small class="text-muted">Students below 20% payment threshold</small></th>
                        </tr>
                    </thead>
                </table>

                <!-- Summary Stats -->
                <div class="row mb-4 mt-3">
                    <div class="col-md-3">
                        <div class="alert alert-danger mb-0">
                            <strong>Total Defaulters</strong><br>
                            <span style="font-size: 24px;"><?php echo number_format($def_stats['total_defaulters'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-warning mb-0">
                            <strong>Total Amount Owed</strong><br>
                            <span style="font-size: 20px;"><?php echo format_currency($def_stats['total_owed'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-dark mb-0">
                            <strong>Zero Payment</strong><br>
                            <span style="font-size: 24px;"><?php echo number_format($def_stats['zero_payment'] ?? 0); ?></span> students
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-secondary mb-0">
                            <strong>Average Debt</strong><br>
                            <span style="font-size: 20px;"><?php echo format_currency($def_stats['avg_debt'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Defaulters by Program -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-pie-chart"></i> Defaulters by Program</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered">
                        <thead class="table-warning">
                            <tr>
                                <th>Program</th>
                                <th class="text-center">No. of Defaulters</th>
                                <th class="text-end">Total Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($def_by_prog && $def_by_prog->num_rows > 0): ?>
                                <?php while ($dbp = $def_by_prog->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dbp['program'] ?? 'Unassigned'); ?></td>
                                    <td class="text-center"><?php echo $dbp['count']; ?></td>
                                    <td class="text-end negative"><?php echo format_currency($dbp['outstanding']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Full Defaulters List -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-list-check"></i> Detailed Defaulters List</h6>
                <div class="table-responsive">
                    <table class="table table-striped table-hover report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Program</th>
                                <th class="text-center">Year</th>
                                <th>Campus</th>
                                <th class="text-end">Expected</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">%</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $def_counter = 0;
                            $def_total_expected = 0; $def_total_paid = 0; $def_total_balance = 0;
                            if ($full_defaulters && $full_defaulters->num_rows > 0):
                                while ($def = $full_defaulters->fetch_assoc()): 
                                    $def_counter++;
                                    $def_total_expected += $def['expected_total'];
                                    $def_total_paid += $def['total_paid'];
                                    $def_total_balance += $def['balance'];
                            ?>
                                <tr>
                                    <td><?php echo $def_counter; ?></td>
                                    <td><strong><?php echo htmlspecialchars($def['student_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($def['full_name']); ?></td>
                                    <td><small><?php echo htmlspecialchars($def['program'] ?? '-'); ?></small></td>
                                    <td class="text-center"><?php echo $def['year_of_study'] ?? '-'; ?></td>
                                    <td><small><?php echo htmlspecialchars($def['campus'] ?? '-'); ?></small></td>
                                    <td class="text-end"><?php echo format_currency($def['expected_total']); ?></td>
                                    <td class="text-end positive"><?php echo format_currency($def['total_paid']); ?></td>
                                    <td class="text-end negative"><strong><?php echo format_currency($def['balance']); ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $def['payment_percentage'] == 0 ? 'danger' : 'warning text-dark'; ?>">
                                            <?php echo $def['payment_percentage']; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo htmlspecialchars($def['email'] ?? ''); ?><br>
                                            <?php echo htmlspecialchars($def['phone'] ?? ''); ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="11" class="text-center py-4 text-muted">No defaulters found</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr style="border-top: 3px double #000;">
                                <th colspan="6" class="text-end">TOTALS (<?php echo $def_counter; ?> students):</th>
                                <th class="text-end"><?php echo format_currency($def_total_expected); ?></th>
                                <th class="text-end positive"><?php echo format_currency($def_total_paid); ?></th>
                                <th class="text-end negative"><?php echo format_currency($def_total_balance); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Report Generated:</strong> <?php echo date('F d, Y \a\t h:i:s A'); ?></small>
                        </div>
                        <div class="col-md-6 text-end">
                            <small class="text-muted"><strong>Generated By:</strong> <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'installment_tracking'): ?>
        <!-- ===== INSTALLMENT TRACKING REPORT ===== -->
        <?php
        $installment_query = "SELECT 
            s.student_id, s.full_name, s.program, s.year_of_study,
            sf.expected_total, sf.total_paid, sf.payment_percentage,
            sf.registration_fee, sf.registration_paid, sf.registration_paid_date,
            sf.installment_1, sf.installment_1_date,
            sf.installment_2, sf.installment_2_date,
            sf.installment_3, sf.installment_3_date,
            sf.installment_4, sf.installment_4_date,
            sf.balance, sf.last_payment_date
        FROM student_finances sf
        LEFT JOIN students s ON sf.student_id = s.student_id
        WHERE sf.expected_total > 0
        ORDER BY s.program, s.full_name";
        $installment_data = $conn->query($installment_query);

        // Installment summary stats
        $inst_stats_query = "SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN installment_1 > 0 THEN 1 ELSE 0 END) as paid_inst1,
            SUM(CASE WHEN installment_2 > 0 THEN 1 ELSE 0 END) as paid_inst2,
            SUM(CASE WHEN installment_3 > 0 THEN 1 ELSE 0 END) as paid_inst3,
            SUM(CASE WHEN installment_4 > 0 THEN 1 ELSE 0 END) as paid_inst4,
            SUM(installment_1) as total_inst1,
            SUM(installment_2) as total_inst2,
            SUM(installment_3) as total_inst3,
            SUM(installment_4) as total_inst4,
            SUM(registration_paid) as total_reg
        FROM student_finances
        WHERE expected_total > 0";
        $inst_stats = $conn->query($inst_stats_query)->fetch_assoc();
        ?>
        <div class="card report-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Installment Tracking Report</h5>
                <small>Tracking payment installment compliance across all students</small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="13" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - INSTALLMENT TRACKING REPORT
                            </th>
                        </tr>
                    </thead>
                </table>

                <!-- Installment Summary -->
                <div class="row mb-4 mt-3">
                    <div class="col-md-2">
                        <div class="card border-primary">
                            <div class="card-body text-center py-2">
                                <strong class="text-primary">Registration</strong><br>
                                <span style="font-size: 16px;"><?php echo format_currency($inst_stats['total_reg'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-success">
                            <div class="card-body text-center py-2">
                                <strong class="text-success">Inst. 1</strong><br>
                                <span style="font-size: 16px;"><?php echo format_currency($inst_stats['total_inst1'] ?? 0); ?></span><br>
                                <small class="text-muted"><?php echo $inst_stats['paid_inst1'] ?? 0; ?>/<?php echo $inst_stats['total_students'] ?? 0; ?> paid</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-info">
                            <div class="card-body text-center py-2">
                                <strong class="text-info">Inst. 2</strong><br>
                                <span style="font-size: 16px;"><?php echo format_currency($inst_stats['total_inst2'] ?? 0); ?></span><br>
                                <small class="text-muted"><?php echo $inst_stats['paid_inst2'] ?? 0; ?>/<?php echo $inst_stats['total_students'] ?? 0; ?> paid</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-warning">
                            <div class="card-body text-center py-2">
                                <strong class="text-warning">Inst. 3</strong><br>
                                <span style="font-size: 16px;"><?php echo format_currency($inst_stats['total_inst3'] ?? 0); ?></span><br>
                                <small class="text-muted"><?php echo $inst_stats['paid_inst3'] ?? 0; ?>/<?php echo $inst_stats['total_students'] ?? 0; ?> paid</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-secondary">
                            <div class="card-body text-center py-2">
                                <strong class="text-secondary">Inst. 4</strong><br>
                                <span style="font-size: 16px;"><?php echo format_currency($inst_stats['total_inst4'] ?? 0); ?></span><br>
                                <small class="text-muted"><?php echo $inst_stats['paid_inst4'] ?? 0; ?>/<?php echo $inst_stats['total_students'] ?? 0; ?> paid</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card border-dark">
                            <div class="card-body text-center py-2">
                                <strong>Total Students</strong><br>
                                <span style="font-size: 20px;"><?php echo $inst_stats['total_students'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Installment Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover report-table mb-0" style="font-size: 12px;">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th class="text-center">Year</th>
                                <th class="text-end">Expected</th>
                                <th class="text-end">Reg. Fee</th>
                                <th class="text-end">Inst. 1</th>
                                <th class="text-end">Inst. 2</th>
                                <th class="text-end">Inst. 3</th>
                                <th class="text-end">Inst. 4</th>
                                <th class="text-end">Total Paid</th>
                                <th class="text-end">Balance</th>
                                <th class="text-center">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $inst_counter = 0;
                            if ($installment_data && $installment_data->num_rows > 0):
                                while ($inst = $installment_data->fetch_assoc()): 
                                    $inst_counter++;
                            ?>
                                <tr>
                                    <td><?php echo $inst_counter; ?></td>
                                    <td><strong><?php echo htmlspecialchars($inst['student_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($inst['full_name'] ?? 'N/A'); ?></td>
                                    <td><small><?php echo htmlspecialchars($inst['program'] ?? '-'); ?></small></td>
                                    <td class="text-center"><?php echo $inst['year_of_study'] ?? '-'; ?></td>
                                    <td class="text-end"><?php echo format_currency($inst['expected_total']); ?></td>
                                    <td class="text-end">
                                        <?php if ($inst['registration_paid'] > 0): ?>
                                            <span class="text-success"><?php echo format_currency($inst['registration_paid']); ?></span>
                                            <?php if ($inst['registration_paid_date']): ?><br><small class="text-muted"><?php echo date('d/m/y', strtotime($inst['registration_paid_date'])); ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inst['installment_1'] > 0): ?>
                                            <span class="text-success"><?php echo format_currency($inst['installment_1']); ?></span>
                                            <?php if ($inst['installment_1_date']): ?><br><small class="text-muted"><?php echo date('d/m/y', strtotime($inst['installment_1_date'])); ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inst['installment_2'] > 0): ?>
                                            <span class="text-success"><?php echo format_currency($inst['installment_2']); ?></span>
                                            <?php if ($inst['installment_2_date']): ?><br><small class="text-muted"><?php echo date('d/m/y', strtotime($inst['installment_2_date'])); ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inst['installment_3'] > 0): ?>
                                            <span class="text-success"><?php echo format_currency($inst['installment_3']); ?></span>
                                            <?php if ($inst['installment_3_date']): ?><br><small class="text-muted"><?php echo date('d/m/y', strtotime($inst['installment_3_date'])); ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inst['installment_4'] > 0): ?>
                                            <span class="text-success"><?php echo format_currency($inst['installment_4']); ?></span>
                                            <?php if ($inst['installment_4_date']): ?><br><small class="text-muted"><?php echo date('d/m/y', strtotime($inst['installment_4_date'])); ?></small><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-danger">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><strong><?php echo format_currency($inst['total_paid']); ?></strong></td>
                                    <td class="text-end negative"><?php echo format_currency($inst['balance']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $inst['payment_percentage'] >= 100 ? 'success' : ($inst['payment_percentage'] >= 50 ? 'warning text-dark' : 'danger'); ?>">
                                            <?php echo $inst['payment_percentage']; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="14" class="text-center py-4 text-muted">No installment data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted"><strong>Report Generated:</strong> <?php echo date('F d, Y \a\t h:i:s A'); ?></small>
                        </div>
                        <div class="col-md-4 text-center">
                            <small class="text-muted"><strong>Total Students:</strong> <?php echo $inst_counter; ?></small>
                        </div>
                        <div class="col-md-4 text-end">
                            <small class="text-muted"><strong>Generated By:</strong> <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'revenue_by_program'): ?>
        <!-- ===== REVENUE BY PROGRAM REPORT ===== -->
        <?php
        // Revenue per program
        $rev_prog_query = "SELECT 
            s.program,
            COUNT(DISTINCT pt.student_id) as paying_students,
            COUNT(pt.transaction_id) as transaction_count,
            SUM(pt.amount) as total_revenue,
            AVG(pt.amount) as avg_transaction,
            MIN(pt.amount) as min_payment,
            MAX(pt.amount) as max_payment
        FROM payment_transactions pt
        LEFT JOIN students s ON pt.student_id = s.student_id
        WHERE pt.payment_date BETWEEN ? AND ?
        GROUP BY s.program
        ORDER BY total_revenue DESC";
        $stmt = $conn->prepare($rev_prog_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $rev_by_program = $stmt->get_result();

        // Revenue by department
        $rev_dept_query = "SELECT 
            s.department,
            COUNT(DISTINCT pt.student_id) as paying_students,
            SUM(pt.amount) as total_revenue
        FROM payment_transactions pt
        LEFT JOIN students s ON pt.student_id = s.student_id
        WHERE pt.payment_date BETWEEN ? AND ?
        GROUP BY s.department
        ORDER BY total_revenue DESC";
        $stmt = $conn->prepare($rev_dept_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $rev_by_dept = $stmt->get_result();

        // Revenue by year of study
        $rev_year_query = "SELECT 
            s.year_of_study,
            COUNT(DISTINCT pt.student_id) as paying_students,
            SUM(pt.amount) as total_revenue
        FROM payment_transactions pt
        LEFT JOIN students s ON pt.student_id = s.student_id
        WHERE pt.payment_date BETWEEN ? AND ?
        GROUP BY s.year_of_study
        ORDER BY s.year_of_study";
        $stmt = $conn->prepare($rev_year_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $rev_by_year = $stmt->get_result();

        // Revenue by campus
        $rev_campus_query = "SELECT 
            COALESCE(s.campus, 'Unknown') as campus,
            COUNT(DISTINCT pt.student_id) as paying_students,
            SUM(pt.amount) as total_revenue
        FROM payment_transactions pt
        LEFT JOIN students s ON pt.student_id = s.student_id
        WHERE pt.payment_date BETWEEN ? AND ?
        GROUP BY s.campus
        ORDER BY total_revenue DESC";
        $stmt = $conn->prepare($rev_campus_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $rev_by_campus = $stmt->get_result();

        // Monthly trend
        $monthly_rev_query = "SELECT 
            DATE_FORMAT(pt.payment_date, '%Y-%m') as month,
            DATE_FORMAT(pt.payment_date, '%b %Y') as month_label,
            SUM(pt.amount) as total,
            COUNT(*) as transactions
        FROM payment_transactions pt
        WHERE pt.payment_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(pt.payment_date, '%Y-%m')
        ORDER BY month";
        $stmt = $conn->prepare($monthly_rev_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $monthly_revenue = $stmt->get_result();
        ?>
        <div class="card report-card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-building"></i> Revenue by Program & Department Report</h5>
                <small>For the period <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="8" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - REVENUE BY PROGRAM
                            </th>
                        </tr>
                        <tr>
                            <th colspan="8" class="text-center"><small class="text-muted">Revenue analysis broken down by program, department, year and campus</small></th>
                        </tr>
                    </thead>
                </table>

                <!-- Revenue by Program -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-mortarboard"></i> Revenue by Program</h6>
                <div class="table-responsive">
                    <table class="table table-striped table-hover report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Program</th>
                                <th class="text-center">Students</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Total Revenue</th>
                                <th class="text-end">Avg Transaction</th>
                                <th class="text-end">Min Payment</th>
                                <th class="text-end">Max Payment</th>
                                <th class="text-center">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rp_counter = 0; $rp_total = 0;
                            $rp_rows = [];
                            if ($rev_by_program && $rev_by_program->num_rows > 0):
                                while ($rp = $rev_by_program->fetch_assoc()) { $rp_rows[] = $rp; $rp_total += $rp['total_revenue']; }
                                foreach ($rp_rows as $rp): $rp_counter++;
                            ?>
                                <tr>
                                    <td><?php echo $rp_counter; ?></td>
                                    <td><?php echo htmlspecialchars($rp['program'] ?? 'Unassigned'); ?></td>
                                    <td class="text-center"><?php echo $rp['paying_students']; ?></td>
                                    <td class="text-center"><?php echo $rp['transaction_count']; ?></td>
                                    <td class="text-end positive"><strong><?php echo format_currency($rp['total_revenue']); ?></strong></td>
                                    <td class="text-end"><?php echo format_currency($rp['avg_transaction']); ?></td>
                                    <td class="text-end"><?php echo format_currency($rp['min_payment']); ?></td>
                                    <td class="text-end"><?php echo format_currency($rp['max_payment']); ?></td>
                                    <td class="text-center">
                                        <div class="progress" style="height: 20px; min-width: 60px;">
                                            <?php $share = $rp_total > 0 ? round(($rp['total_revenue'] / $rp_total) * 100, 1) : 0; ?>
                                            <div class="progress-bar bg-success" style="width: <?php echo $share; ?>%"><?php echo $share; ?>%</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No revenue data for selected period</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr style="border-top: 3px double #000;">
                                <th colspan="4" class="text-end">TOTAL REVENUE:</th>
                                <th class="text-end positive"><?php echo format_currency($rp_total); ?></th>
                                <th colspan="4"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Revenue by Department -->
                <h6 class="fw-bold mt-5 mb-3"><i class="bi bi-diagram-3"></i> Revenue by Department</h6>
                <div class="table-responsive">
                    <table class="table table-striped report-table mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>Department</th>
                                <th class="text-center">Paying Students</th>
                                <th class="text-end">Total Revenue</th>
                                <th class="text-center">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rev_by_dept && $rev_by_dept->num_rows > 0): ?>
                                <?php while ($rd = $rev_by_dept->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($rd['department'] ?? 'Unknown'); ?></td>
                                    <td class="text-center"><?php echo $rd['paying_students']; ?></td>
                                    <td class="text-end positive"><?php echo format_currency($rd['total_revenue']); ?></td>
                                    <td class="text-center">
                                        <?php $rd_pct = $rp_total > 0 ? round(($rd['total_revenue'] / $rp_total) * 100, 1) : 0; ?>
                                        <span class="badge bg-primary"><?php echo $rd_pct; ?>%</span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted">No data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Side by Side: Revenue by Year + Revenue by Campus -->
                <div class="row mt-5">
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-3"><i class="bi bi-calendar3"></i> Revenue by Year of Study</h6>
                        <table class="table table-sm table-bordered report-table">
                            <thead class="table-info">
                                <tr><th>Year</th><th class="text-center">Students</th><th class="text-end">Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($rev_by_year && $rev_by_year->num_rows > 0): ?>
                                    <?php while ($ry = $rev_by_year->fetch_assoc()): ?>
                                    <tr>
                                        <td>Year <?php echo $ry['year_of_study'] ?? '-'; ?></td>
                                        <td class="text-center"><?php echo $ry['paying_students']; ?></td>
                                        <td class="text-end positive"><?php echo format_currency($ry['total_revenue']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt"></i> Revenue by Campus</h6>
                        <table class="table table-sm table-bordered report-table">
                            <thead class="table-warning">
                                <tr><th>Campus</th><th class="text-center">Students</th><th class="text-end">Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($rev_by_campus && $rev_by_campus->num_rows > 0): ?>
                                    <?php while ($rc = $rev_by_campus->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rc['campus']); ?></td>
                                        <td class="text-center"><?php echo $rc['paying_students']; ?></td>
                                        <td class="text-end positive"><?php echo format_currency($rc['total_revenue']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Monthly Trend -->
                <h6 class="fw-bold mt-5 mb-3"><i class="bi bi-graph-up-arrow"></i> Monthly Revenue Trend</h6>
                <div class="table-responsive">
                    <table class="table table-striped report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Month</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Revenue</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $max_monthly = 0;
                            $monthly_rows = [];
                            if ($monthly_revenue && $monthly_revenue->num_rows > 0):
                                while ($mr = $monthly_revenue->fetch_assoc()) { $monthly_rows[] = $mr; if ($mr['total'] > $max_monthly) $max_monthly = $mr['total']; }
                                foreach ($monthly_rows as $mr):
                            ?>
                                <tr>
                                    <td><strong><?php echo $mr['month_label']; ?></strong></td>
                                    <td class="text-center"><?php echo $mr['transactions']; ?></td>
                                    <td class="text-end positive"><?php echo format_currency($mr['total']); ?></td>
                                    <td style="width: 40%;">
                                        <div class="progress" style="height: 20px;">
                                            <?php $bar_pct = $max_monthly > 0 ? round(($mr['total'] / $max_monthly) * 100) : 0; ?>
                                            <div class="progress-bar bg-success" style="width: <?php echo $bar_pct; ?>%"><?php echo format_currency($mr['total']); ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No data for selected period</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Report Period:</strong> <?php echo date('d M Y', strtotime($start_date)); ?> — <?php echo date('d M Y', strtotime($end_date)); ?></small>
                        </div>
                        <div class="col-md-6 text-end">
                            <small class="text-muted"><strong>Generated:</strong> <?php echo date('F d, Y \a\t h:i:s A'); ?> by <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'payment_method_analysis'): ?>
        <!-- ===== PAYMENT METHOD ANALYSIS ===== -->
        <?php
        // Payment method breakdown
        $method_query = "SELECT 
            payment_method,
            COUNT(*) as transaction_count,
            COUNT(DISTINCT student_id) as unique_students,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount
        FROM payment_transactions
        WHERE payment_date BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total_amount DESC";
        $stmt = $conn->prepare($method_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $method_data = $stmt->get_result();

        // Bank usage from payment submissions
        $bank_query = "SELECT 
            COALESCE(bank_name, 'Not Specified') as bank,
            COUNT(*) as submissions,
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM payment_submissions
        WHERE submission_date BETWEEN ? AND ?
        GROUP BY bank_name
        ORDER BY total_amount DESC";
        $stmt = $conn->prepare($bank_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $bank_data = $stmt->get_result();

        // Daily transaction volume
        $daily_vol_query = "SELECT 
            payment_date,
            COUNT(*) as transactions,
            SUM(amount) as daily_total
        FROM payment_transactions
        WHERE payment_date BETWEEN ? AND ?
        GROUP BY payment_date
        ORDER BY payment_date DESC
        LIMIT 30";
        $stmt = $conn->prepare($daily_vol_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $daily_volume = $stmt->get_result();

        // Payment type breakdown
        $type_query = "SELECT 
            payment_type,
            payment_method,
            COUNT(*) as count,
            SUM(amount) as total
        FROM payment_transactions
        WHERE payment_date BETWEEN ? AND ?
        GROUP BY payment_type, payment_method
        ORDER BY payment_type, total DESC";
        $stmt = $conn->prepare($type_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $type_method_data = $stmt->get_result();

        // Submission status overview
        $sub_status_query = "SELECT 
            status,
            COUNT(*) as count,
            SUM(amount) as total_amount
        FROM payment_submissions
        WHERE submission_date BETWEEN ? AND ?
        GROUP BY status";
        $stmt = $conn->prepare($sub_status_query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $sub_status_data = $stmt->get_result();
        ?>
        <div class="card report-card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-credit-card-2-back"></i> Payment Method Analysis Report</h5>
                <small>For the period <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></small>
            </div>
            <div class="card-body">
                <table class="table report-table" style="width: 100%; margin-left: auto; margin-right: auto;">
                    <thead>
                        <tr>
                            <th colspan="8" class="text-center" style="font-weight: bold; font-size: 18px; padding-bottom: 10px;">
                                <img src="../assets/img/Logo.png" alt="University Logo" style="max-height: 60px; margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto;">
                                EXPLOITS UNIVERSITY - PAYMENT METHOD ANALYSIS
                            </th>
                        </tr>
                        <tr>
                            <th colspan="8" class="text-center"><small class="text-muted">Analysis of payment channels, banks, and transaction patterns</small></th>
                        </tr>
                    </thead>
                </table>

                <!-- Payment Method Breakdown -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-wallet2"></i> Payment Method Breakdown</h6>
                <div class="table-responsive">
                    <table class="table table-striped table-hover report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Payment Method</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-center">Unique Students</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Avg Amount</th>
                                <th class="text-end">Min</th>
                                <th class="text-end">Max</th>
                                <th class="text-center">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $pm_total = 0; $pm_rows = [];
                            if ($method_data && $method_data->num_rows > 0):
                                while ($pm = $method_data->fetch_assoc()) { $pm_rows[] = $pm; $pm_total += $pm['total_amount']; }
                                foreach ($pm_rows as $pm):
                            ?>
                                <tr>
                                    <td><strong><?php echo ucwords(str_replace('_', ' ', $pm['payment_method'] ?? 'Unknown')); ?></strong></td>
                                    <td class="text-center"><?php echo $pm['transaction_count']; ?></td>
                                    <td class="text-center"><?php echo $pm['unique_students']; ?></td>
                                    <td class="text-end positive"><strong><?php echo format_currency($pm['total_amount']); ?></strong></td>
                                    <td class="text-end"><?php echo format_currency($pm['avg_amount']); ?></td>
                                    <td class="text-end"><?php echo format_currency($pm['min_amount']); ?></td>
                                    <td class="text-end"><?php echo format_currency($pm['max_amount']); ?></td>
                                    <td class="text-center">
                                        <?php $pm_pct = $pm_total > 0 ? round(($pm['total_amount'] / $pm_total) * 100, 1) : 0; ?>
                                        <div class="progress" style="height: 20px; min-width: 60px;">
                                            <div class="progress-bar bg-info" style="width: <?php echo $pm_pct; ?>%"><?php echo $pm_pct; ?>%</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No transactions in selected period</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr style="border-top: 3px double #000;">
                                <th>TOTAL</th>
                                <th class="text-center"><?php echo array_sum(array_column($pm_rows, 'transaction_count')); ?></th>
                                <th></th>
                                <th class="text-end positive"><strong><?php echo format_currency($pm_total); ?></strong></th>
                                <th colspan="4"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Bank Usage -->
                <h6 class="fw-bold mt-5 mb-3"><i class="bi bi-bank"></i> Bank Usage (from Student Submissions)</h6>
                <div class="table-responsive">
                    <table class="table table-striped report-table mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>Bank Name</th>
                                <th class="text-center">Submissions</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Approved</th>
                                <th class="text-center">Rejected</th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">Approval Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bank_data && $bank_data->num_rows > 0): ?>
                                <?php while ($bd = $bank_data->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($bd['bank']); ?></strong></td>
                                    <td class="text-center"><?php echo $bd['submissions']; ?></td>
                                    <td class="text-end"><?php echo format_currency($bd['total_amount']); ?></td>
                                    <td class="text-center"><span class="badge bg-success"><?php echo $bd['approved']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?php echo $bd['rejected']; ?></span></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?php echo $bd['pending']; ?></span></td>
                                    <td class="text-center">
                                        <?php $appr_rate = $bd['submissions'] > 0 ? round(($bd['approved'] / $bd['submissions']) * 100, 1) : 0; ?>
                                        <span class="badge bg-<?php echo $appr_rate >= 80 ? 'success' : ($appr_rate >= 50 ? 'warning text-dark' : 'danger'); ?>">
                                            <?php echo $appr_rate; ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted">No submission data for selected period</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Submission Status -->
                <h6 class="fw-bold mt-5 mb-3"><i class="bi bi-check2-all"></i> Submission Status Overview</h6>
                <div class="row mb-4">
                    <?php if ($sub_status_data && $sub_status_data->num_rows > 0): ?>
                        <?php while ($ss = $sub_status_data->fetch_assoc()): 
                            $ss_color = $ss['status'] === 'approved' ? 'success' : ($ss['status'] === 'rejected' ? 'danger' : 'warning');
                        ?>
                        <div class="col-md-4 mb-2">
                            <div class="alert alert-<?php echo $ss_color; ?> mb-0">
                                <strong><?php echo ucfirst($ss['status']); ?></strong><br>
                                <span style="font-size: 20px;"><?php echo $ss['count']; ?></span> submissions<br>
                                <small><?php echo format_currency($ss['total_amount']); ?></small>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </div>

                <!-- Payment Type × Method Cross-Tab -->
                <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-grid-3x3"></i> Payment Type by Method</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered report-table mb-0">
                        <thead class="table-info">
                            <tr>
                                <th>Payment Type</th>
                                <th>Payment Method</th>
                                <th class="text-center">Count</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($type_method_data && $type_method_data->num_rows > 0): ?>
                                <?php $prev_type = ''; while ($tm = $type_method_data->fetch_assoc()): ?>
                                <tr>
                                    <td><?php 
                                        $curr_type = ucwords(str_replace('_', ' ', $tm['payment_type'] ?? '')); 
                                        echo $curr_type !== $prev_type ? '<strong>' . $curr_type . '</strong>' : '';
                                        $prev_type = $curr_type;
                                    ?></td>
                                    <td><?php echo ucwords(str_replace('_', ' ', $tm['payment_method'] ?? 'Unknown')); ?></td>
                                    <td class="text-center"><?php echo $tm['count']; ?></td>
                                    <td class="text-end positive"><?php echo format_currency($tm['total']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted">No data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Daily Transaction Volume -->
                <h6 class="fw-bold mt-5 mb-3"><i class="bi bi-calendar-day"></i> Daily Transaction Volume (Last 30 Days)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped report-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Daily Total</th>
                                <th>Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $dv_rows = []; $dv_max = 0;
                            if ($daily_volume && $daily_volume->num_rows > 0):
                                while ($dv = $daily_volume->fetch_assoc()) { $dv_rows[] = $dv; if ($dv['daily_total'] > $dv_max) $dv_max = $dv['daily_total']; }
                                foreach ($dv_rows as $dv):
                            ?>
                                <tr>
                                    <td><?php echo date('D, d M Y', strtotime($dv['payment_date'])); ?></td>
                                    <td class="text-center"><?php echo $dv['transactions']; ?></td>
                                    <td class="text-end positive"><?php echo format_currency($dv['daily_total']); ?></td>
                                    <td style="width: 40%;">
                                        <div class="progress" style="height: 16px;">
                                            <?php $dv_pct = $dv_max > 0 ? round(($dv['daily_total'] / $dv_max) * 100) : 0; ?>
                                            <div class="progress-bar bg-warning" style="width: <?php echo $dv_pct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No transactions in selected period</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Report Period:</strong> <?php echo date('d M Y', strtotime($start_date)); ?> — <?php echo date('d M Y', strtotime($end_date)); ?></small>
                        </div>
                        <div class="col-md-6 text-end">
                            <small class="text-muted"><strong>Generated:</strong> <?php echo date('F d, Y \a\t h:i:s A'); ?> by <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print & Export Buttons -->
        <div class="card report-card no-print mt-4">
            <div class="card-body text-end">
                <button type="button" onclick="window.print()" class="btn btn-success me-2"><i class="bi bi-printer"></i> Print Report</button>
                <button type="button" onclick="exportToCSV()" class="btn btn-info"><i class="bi bi-download"></i> Export CSV</button>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportToCSV() {
            // Find the main data table (last .report-table with tbody)
            const tables = document.querySelectorAll('.report-table');
            let table = null;
            for (let t of tables) {
                if (t.querySelector('tbody')) table = t;
            }
            if (!table) {
                alert('No table found to export');
                return;
            }
            let csv = [];
            for (let row of table.querySelectorAll("tr")) {
                let csvRow = [];
                for (let cell of row.querySelectorAll("td, th")) {
                    let text = cell.innerText.replace(/\n/g, ' ').replace(/"/g, '""').trim();
                    csvRow.push('"' + text + '"');
                }
                if (csvRow.length > 0) csv.push(csvRow.join(","));
            }
            const csvFile = new Blob(["\uFEFF" + csv.join("\n")], {type: "text/csv;charset=utf-8"});
            const csvUrl = URL.createObjectURL(csvFile);
            const link = document.createElement("a");
            link.href = csvUrl;
            const reportType = new URLSearchParams(window.location.search).get('report_type') || 'financial';
            link.download = reportType + "_report_" + new Date().toISOString().split('T')[0] + ".csv";
            link.click();
        }
    </script>
</body>
</html>
