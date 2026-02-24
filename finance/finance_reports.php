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
    return 'K' . number_format($amount, 2);
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
                            lfr.created_at,
                            lfr.lecturer_id as student_id,
                            l.full_name as party_name,
                            CONCAT('Lecturer Finance Request - ', lfr.request_type) as description,
                            lfr.total_amount as amount,
                            'Debit' as entry_type,
                            lfr.responded_by as user_action,
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

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportToCSV() {
            const table = document.querySelector('.report-table');
            if (!table) {
                alert('No table found to export');
                return;
            }
            let csv = [];
            for (let row of table.querySelectorAll("tr")) {
                let csvRow = [];
                for (let cell of row.querySelectorAll("td, th")) {
                    csvRow.push('"' + cell.innerText.replace(/"/g, '""') + '"');
                }
                csv.push(csvRow.join(","));
            }
            const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
            const csvUrl = URL.createObjectURL(csvFile);
            const link = document.createElement("a");
            link.href = csvUrl;
            link.download = "financial_report_" + new Date().toISOString().split('T')[0] + ".csv";
            link.click();
        }
    </script>
</body>
</html>
