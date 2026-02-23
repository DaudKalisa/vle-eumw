<?php
// finance/finance_reports.php - Financial Reports and Analytics
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get date range for reports
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Revenue by Payment Type
$revenue_query = "SELECT payment_type, SUM(amount) as total FROM payment_transactions WHERE payment_date BETWEEN ? AND ? GROUP BY payment_type";
$stmt = $conn->prepare($revenue_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$revenue_result = $stmt->get_result();

// Recent transactions
$transactions_query = "SELECT pt.*, s.full_name, s.student_id FROM payment_transactions pt JOIN students s ON pt.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci WHERE pt.payment_date BETWEEN ? AND ? ORDER BY pt.payment_date DESC LIMIT 50";
$stmt = $conn->prepare($transactions_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$transactions = $stmt->get_result();

// Total revenue in date range
$total_query = "SELECT SUM(amount) as total FROM payment_transactions WHERE payment_date BETWEEN ? AND ?";
$stmt = $conn->prepare($total_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$total_revenue = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Defaulters list (students with 0% payment)
$defaulters_query = "SELECT s.student_id, s.full_name, s.email, d.department_code as program_code, sf.balance FROM students s JOIN student_finances sf ON s.student_id COLLATE utf8mb4_unicode_ci = sf.student_id COLLATE utf8mb4_unicode_ci LEFT JOIN departments d ON s.department = d.department_id WHERE sf.payment_percentage = 0 AND s.is_active = TRUE ORDER BY s.student_id";
$defaulters = $conn->query($defaulters_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Reports - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3 text-white">Welcome, <?php echo htmlspecialchars($user['display_name']); ?></span>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-graph-up text-success"></i> Financial Reports & Analytics</h2>
        <p class="text-muted">Generate and view financial reports</p>

        <!-- Date Range Filter -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" onclick="window.print()" class="btn btn-success w-100"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <!-- Revenue Summary -->
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Revenue Summary (<?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <h3 class="text-success">K<?php echo number_format($total_revenue); ?></h3>
                        <p class="text-muted">Total Revenue Collected</p>
                        
                        <hr>
                        
                        <h6>Revenue by Payment Type</h6>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Payment Type</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $revenue_result->data_seek(0);
                                while ($row = $revenue_result->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><?php echo ucwords(str_replace('_', ' ', $row['payment_type'])); ?></td>
                                        <td class="text-end"><strong>K<?php echo number_format($row['total']); ?></strong></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Defaulters List with Payment Breakdown -->
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Defaulters (<?php echo $defaulters->num_rows; ?>)</h5>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if ($defaulters->num_rows > 0): ?>
                            <table class="table table-sm table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Program</th>
                                        <th>Application Fee</th>
                                        <th>Registration Fee</th>
                                        <th>Tuition Paid</th>
                                        <th>Balance</th>
                                        <th>Access Weeks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($defaulter = $defaulters->fetch_assoc()): ?>
                                        <?php
                                            // Fetch full finance data for this student
                                            $student_id = $defaulter['student_id'];
                                            $finance = $conn->query("SELECT * FROM student_finances WHERE student_id = '" . $conn->real_escape_string($student_id) . "'")->fetch_assoc();
                                            $total_paid = $finance['total_paid'] ?? 0;
                                            $application_fee = 5500;
                                            $registration_fee = 39500;
                                            $student_tuition = $finance['expected_tuition'] ?? 0;
                                            $installment_amount = $student_tuition / 4;
                                            $application_fee_paid = min($total_paid, $application_fee);
                                            $remaining = max(0, $total_paid - $application_fee);
                                            $registration_paid = min($remaining, $registration_fee);
                                            $remaining = max(0, $remaining - $registration_fee);
                                            $tuition_paid = 0;
                                            for ($i = 0; $i < 4; $i++) {
                                                $tuition_paid += min($remaining, $installment_amount);
                                                $remaining = max(0, $remaining - $installment_amount);
                                            }
                                        ?>
                                        <?php
                                            $expected_total = $finance['expected_total'] ?? 1;
                                            $payment_percentage = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
                                            if ($payment_percentage >= 1) {
                                                $access_weeks = 16;
                                            } elseif ($payment_percentage >= 0.75) {
                                                $access_weeks = 12;
                                            } elseif ($payment_percentage >= 0.5) {
                                                $access_weeks = 8;
                                            } elseif ($payment_percentage >= 0.2) {
                                                $access_weeks = 4;
                                            } else {
                                                $access_weeks = 0;
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($defaulter['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($defaulter['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($defaulter['program_code'] ?? 'N/A'); ?></td>
                                            <td class="<?php echo $application_fee_paid >= $application_fee ? 'text-success' : 'text-danger'; ?>">K<?php echo number_format($application_fee_paid); ?> / K<?php echo number_format($application_fee); ?></td>
                                            <td class="<?php echo $registration_paid >= $registration_fee ? 'text-success' : 'text-danger'; ?>">K<?php echo number_format($registration_paid); ?> / K<?php echo number_format($registration_fee); ?></td>
                                            <td>K<?php echo number_format($tuition_paid); ?> / K<?php echo number_format($student_tuition); ?></td>
                                            <td class="text-danger"><strong>K<?php echo number_format($defaulter['balance']); ?></strong></td>
                                            <td><?php echo $access_weeks; ?> weeks<br><span style="font-size:0.9em;color:#888;">(100% fees = 16w, 75% = 12w, 50% = 8w, 20% = 4w)</span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-success">No defaulters found! All students have made payments.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Transaction History (Last 50)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Payment Type</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while ($trans = $transactions->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($trans['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($trans['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($trans['full_name']); ?></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $trans['payment_type'])); ?></td>
                                        <td><strong>K<?php echo number_format($trans['amount']); ?></strong></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $trans['payment_method'])); ?></td>
                                        <td><?php echo htmlspecialchars($trans['reference_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($trans['recorded_by']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No transactions in selected date range</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php $conn->close(); ?>
