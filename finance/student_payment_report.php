<?php
/**
 * Finance Portal - Student Payment Report
 * Report showing: Student Name, Total Amount, Amount Paid, Payment Type, Payment Method, Bank Name
 * With print and export functionality
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'staff']);

$conn = getDbConnection();

// Filters
$filter_search = $_GET['search'] ?? '';
$filter_payment_status = $_GET['payment_status'] ?? '';
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query conditions
$where = ["1=1"];
$params = [];
$types = "";

if ($filter_search) {
    $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
    $search = "%$filter_search%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

if ($filter_payment_status === 'full') {
    $where[] = "sf.payment_percentage >= 100";
} elseif ($filter_payment_status === 'partial') {
    $where[] = "sf.payment_percentage > 0 AND sf.payment_percentage < 100";
} elseif ($filter_payment_status === 'none') {
    $where[] = "(sf.payment_percentage = 0 OR sf.payment_percentage IS NULL)";
}

// Detect program column
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

if ($filter_program) {
    $where[] = "s.$program_col = ?";
    $params[] = $filter_program;
    $types .= "s";
}

if ($filter_year) {
    $where[] = "s.year_of_study = ?";
    $params[] = $filter_year;
    $types .= "i";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Count total
$count_sql = "SELECT COUNT(*) as total FROM students s LEFT JOIN student_finances sf ON s.student_id = sf.student_id $where_sql";
if (!empty($params)) {
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total / $per_page);

// For print-all mode, get ALL students matching filter (no pagination)
$print_all = isset($_GET['print_all']);
$limit_sql = $print_all ? "" : "LIMIT $per_page OFFSET $offset";

// Get students with finance data and latest payment details
$sql = "SELECT s.student_id, s.full_name, s.email, s.phone, s.year_of_study,
               s.$program_col,
               p.program_name, p.program_code,
               sf.expected_total, sf.total_paid, sf.balance, sf.payment_percentage, sf.last_payment_date,
               pt_latest.payment_type AS last_payment_type,
               pt_latest.payment_method AS last_payment_method,
               pt_latest.notes AS last_payment_notes,
               ps_latest.bank_name AS submission_bank_name,
               ps_latest.transaction_type AS submission_type
        FROM students s
        LEFT JOIN student_finances sf ON s.student_id = sf.student_id
        LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
        LEFT JOIN (
            SELECT pt1.student_id, pt1.payment_type, pt1.payment_method, pt1.notes
            FROM payment_transactions pt1
            INNER JOIN (
                SELECT student_id, MAX(transaction_id) AS max_id
                FROM payment_transactions
                GROUP BY student_id
            ) pt2 ON pt1.transaction_id = pt2.max_id AND pt1.student_id = pt2.student_id
        ) pt_latest ON s.student_id = pt_latest.student_id
        LEFT JOIN (
            SELECT ps1.student_id, ps1.bank_name, ps1.transaction_type
            FROM payment_submissions ps1
            INNER JOIN (
                SELECT student_id, MAX(submission_id) AS max_id
                FROM payment_submissions
                WHERE status = 'approved'
                GROUP BY student_id
            ) ps2 ON ps1.submission_id = ps2.max_id AND ps1.student_id = ps2.student_id
        ) ps_latest ON s.student_id = ps_latest.student_id
        $where_sql
        ORDER BY s.full_name
        $limit_sql";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$students = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get programs for filter
$programs = [];
$prog_result = $conn->query("SELECT program_id, program_code, program_name FROM programs ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Summary stats
$summary = $conn->query("SELECT 
    COUNT(*) as total_students,
    SUM(COALESCE(sf.expected_total, 0)) as total_expected,
    SUM(COALESCE(sf.total_paid, 0)) as total_collected,
    SUM(COALESCE(sf.balance, 0)) as total_outstanding,
    COUNT(CASE WHEN sf.payment_percentage >= 100 THEN 1 END) as fully_paid,
    COUNT(CASE WHEN sf.payment_percentage > 0 AND sf.payment_percentage < 100 THEN 1 END) as partial_paid,
    COUNT(CASE WHEN sf.payment_percentage = 0 OR sf.payment_percentage IS NULL THEN 1 END) as not_paid
    FROM students s
    LEFT JOIN student_finances sf ON s.student_id = sf.student_id");
$summary_data = $summary ? $summary->fetch_assoc() : ['total_students' => 0, 'total_expected' => 0, 'total_collected' => 0, 'total_outstanding' => 0, 'fully_paid' => 0, 'partial_paid' => 0, 'not_paid' => 0];

// Get university name for print
$uni_name = 'Eastern University of Management and Wellbeing';
$uni_settings = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($uni_settings && $row = $uni_settings->fetch_assoc()) {
    $uni_name = $row['university_name'] ?? $uni_name;
}

$page_title = "Student Payment Report";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Finance Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        /* Print Styles */
        @media print {
            .no-print, .navbar, .vle-navbar, .finance-nav, .filter-card, .pagination, .btn-toolbar, .card-footer {
                display: none !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            .print-header h2 { font-size: 18px; margin-bottom: 2px; }
            .print-header h3 { font-size: 14px; margin-bottom: 2px; }
            .print-header p { font-size: 11px; margin-bottom: 0; }
            body { font-size: 10px; }
            .card { border: none !important; box-shadow: none !important; }
            .card-header { background: none !important; color: #000 !important; border-bottom: 1px solid #000; padding: 5px 0; }
            .table th, .table td { padding: 3px 5px !important; font-size: 10px; }
            .badge { border: 1px solid #333; background: #fff !important; color: #000 !important; }
            .container-fluid { padding: 0 !important; }
            .stats-row .card { border: 1px solid #ccc !important; }
            a { text-decoration: none; color: #000; }
            .print-footer {
                display: block !important;
                text-align: center;
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #ccc;
                font-size: 10px;
            }
        }
        @media screen {
            .print-header, .print-footer { display: none; }
        }
        .amount-positive { color: #198754; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'student_payment_report';
    include 'header_nav.php'; 
    ?>
    
    <!-- Print Header -->
    <div class="print-header">
        <h2><?= htmlspecialchars($uni_name) ?></h2>
        <h3>Student Payment Report</h3>
        <p>
            Generated on: <?= date('F j, Y \a\t g:i A') ?>
            <?php if ($filter_program): ?> | Program: <?= htmlspecialchars($filter_program) ?><?php endif; ?>
            <?php if ($filter_year): ?> | Year: <?= $filter_year ?><?php endif; ?>
            <?php if ($filter_payment_status): ?> | Status: <?= ucfirst($filter_payment_status) ?><?php endif; ?>
            <?php if ($filter_search): ?> | Search: "<?= htmlspecialchars($filter_search) ?>"<?php endif; ?>
            | Total: <?= number_format($total) ?> student(s)
        </p>
    </div>

    <div class="container-fluid py-4">
        
        <!-- Page Header with Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-cash-stack me-2"></i>Student Payment Report</h1>
                <p class="text-muted mb-0">View student payment details with print and export options</p>
            </div>
            <div class="btn-toolbar gap-2">
                <button class="btn btn-success" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Report
                </button>
                <button class="btn btn-danger" onclick="exportPDF()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                </button>
                <a href="?<?= http_build_query(array_merge($_GET, ['print_all' => '1'])) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-download me-1"></i> Load All for Print
                </a>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row g-3 mb-4 stats-row">
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-primary">
                    <div class="card-body">
                        <div class="fs-4 fw-bold text-primary"><?= number_format($summary_data['total_students']) ?></div>
                        <small class="text-muted">Total Students</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-info">
                    <div class="card-body">
                        <div class="fs-5 fw-bold text-info">K<?= number_format($summary_data['total_expected']) ?></div>
                        <small class="text-muted">Expected Total</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-success">
                    <div class="card-body">
                        <div class="fs-5 fw-bold text-success">K<?= number_format($summary_data['total_collected']) ?></div>
                        <small class="text-muted">Total Collected</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-danger">
                    <div class="card-body">
                        <div class="fs-5 fw-bold text-danger">K<?= number_format($summary_data['total_outstanding']) ?></div>
                        <small class="text-muted">Outstanding</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-success">
                    <div class="card-body">
                        <div class="fs-4 fw-bold text-success"><?= number_format($summary_data['fully_paid']) ?></div>
                        <small class="text-muted">Fully Paid</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100 border-warning">
                    <div class="card-body">
                        <div class="fs-4 fw-bold text-warning"><?= number_format($summary_data['partial_paid']) ?></div>
                        <small class="text-muted">Partial</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4 no-print filter-card">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Name, Student ID, Email...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-select">
                            <option value="">All</option>
                            <option value="full" <?= $filter_payment_status === 'full' ? 'selected' : '' ?>>Fully Paid</option>
                            <option value="partial" <?= $filter_payment_status === 'partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="none" <?= $filter_payment_status === 'none' ? 'selected' : '' ?>>Not Paid</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog['program_code']) ?>" <?= $filter_program === $prog['program_code'] ? 'selected' : '' ?>><?= htmlspecialchars($prog['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <option value="1" <?= $filter_year == '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year == '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year == '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year == '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Report Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-table me-2"></i>Student Payment Details</h5>
                <span class="badge bg-primary"><?= number_format($total) ?> records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="paymentTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Total Amount (K)</th>
                                <th>Amount Paid (K)</th>
                                <th>Balance (K)</th>
                                <th>% Paid</th>
                                <th>Payment Type</th>
                                <th>Payment Method</th>
                                <th>Bank Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">No records found</td>
                            </tr>
                            <?php else: ?>
                            <?php $row_num = $print_all ? 1 : (($page - 1) * $per_page) + 1; ?>
                            <?php foreach ($students as $st): 
                                $expected = $st['expected_total'] ?? 0;
                                $paid = $st['total_paid'] ?? 0;
                                $balance = $st['balance'] ?? ($expected - $paid);
                                $pct = $st['payment_percentage'] ?? 0;
                                
                                // Determine bank name from submission or from payment notes
                                $bank_name = $st['submission_bank_name'] ?? '';
                                if (empty($bank_name) && !empty($st['last_payment_notes'])) {
                                    // Try to extract bank from notes (format: "Bank: XYZ | ...")
                                    if (preg_match('/Bank:\s*([^|]+)/', $st['last_payment_notes'], $matches)) {
                                        $bank_name = trim($matches[1]);
                                    }
                                }
                                
                                // Determine payment type
                                $payment_type = $st['submission_type'] ?? $st['last_payment_type'] ?? 'N/A';
                                
                                // Determine payment method
                                $payment_method = $st['last_payment_method'] ?? 'N/A';
                            ?>
                            <tr>
                                <td><?= $row_num++ ?></td>
                                <td><strong><?= htmlspecialchars($st['full_name']) ?></strong></td>
                                <td><code><?= htmlspecialchars($st['student_id']) ?></code></td>
                                <td><?= htmlspecialchars($st['program_name'] ?? $st[$program_col] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($expected) ?></td>
                                <td class="text-end amount-positive"><?= number_format($paid) ?></td>
                                <td class="text-end <?= $balance > 0 ? 'amount-negative' : 'amount-positive' ?>"><?= number_format($balance) ?></td>
                                <td>
                                    <?php if ($pct >= 100): ?>
                                        <span class="badge bg-success"><?= round($pct) ?>%</span>
                                    <?php elseif ($pct >= 50): ?>
                                        <span class="badge bg-warning text-dark"><?= round($pct) ?>%</span>
                                    <?php elseif ($pct > 0): ?>
                                        <span class="badge bg-info"><?= round($pct) ?>%</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">0%</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $payment_type))) ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $payment_method))) ?></td>
                                <td><?= htmlspecialchars($bank_name ?: 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($students)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="4" class="text-end">Totals:</td>
                                <td class="text-end">K<?= number_format(array_sum(array_column($students, 'expected_total'))) ?></td>
                                <td class="text-end amount-positive">K<?= number_format(array_sum(array_column($students, 'total_paid'))) ?></td>
                                <td class="text-end amount-negative">K<?= number_format(array_sum(array_map(function($s) { return ($s['balance'] ?? (($s['expected_total'] ?? 0) - ($s['total_paid'] ?? 0))); }, $students))) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1 && !$print_all): ?>
            <div class="card-footer bg-white no-print">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($filter_search) ?>&payment_status=<?= $filter_payment_status ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>">Previous</a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($filter_search) ?>&payment_status=<?= $filter_payment_status ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($filter_search) ?>&payment_status=<?= $filter_payment_status ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center small text-muted mt-2">
                    Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total) ?> of <?= number_format($total) ?> records
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print Footer -->
    <div class="print-footer">
        <p><?= htmlspecialchars($uni_name) ?> &mdash; Student Payment Report (Confidential) &mdash; Generated <?= date('d/m/Y H:i') ?></p>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportPDF() {
            var originalTitle = document.title;
            document.title = 'Student_Payment_Report_<?= date('Y-m-d') ?>';
            window.print();
            setTimeout(function() {
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</body>
</html>
