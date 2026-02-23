<?php
// finance/payment_history.php - View payment history for a student
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : '';

if (empty($student_id)) {
    header('Location: student_finances.php');
    exit;
}

// Get student info
$student_query = "SELECT s.*, d.department_code as program_code FROM students s LEFT JOIN departments d ON s.department = d.department_id WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header('Location: student_finances.php');
    exit;
}

// Get payment history
$history_query = "SELECT * FROM payment_transactions WHERE student_id = ? ORDER BY payment_date DESC, created_at DESC";
$stmt = $conn->prepare($history_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$transactions = $stmt->get_result();

// Calculate totals
$total_query = "SELECT SUM(amount) as total, COUNT(*) as count FROM payment_transactions WHERE student_id = ?";
$stmt = $conn->prepare($total_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none; }
            .card { border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-success no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="student_finances.php">
                <i class="bi bi-arrow-left-circle"></i> Back to Student Accounts
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3 text-white">Welcome, <?php echo htmlspecialchars($user['display_name']); ?></span>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Header -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3 class="mb-1"><i class="bi bi-clock-history text-primary"></i> Payment History</h3>
                        <p class="text-muted mb-0">Student: <strong><?php echo htmlspecialchars($student['full_name']); ?></strong> (<?php echo htmlspecialchars($student['student_id']); ?>)</p>
                        <p class="text-muted mb-0">Program: <strong><?php echo htmlspecialchars($student['program_code'] ?? 'N/A'); ?></strong></p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button onclick="window.print()" class="btn btn-primary no-print">
                            <i class="bi bi-printer"></i> Print History
                        </button>
                        <a href="view_student_finance.php?id=<?php echo urlencode($student_id); ?>" class="btn btn-info no-print">
                            <i class="bi bi-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Total Transactions</h6>
                        <h2 class="text-primary"><?php echo $totals['count']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Total Amount Paid</h6>
                        <h2 class="text-success">K<?php echo number_format($totals['total'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Complete Transaction History</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($transactions->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Payment Type</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th class="no-print">Recorded By</th>
                                    <th class="no-print">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                while ($trans = $transactions->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($trans['payment_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?php echo ucwords(str_replace('_', ' ', $trans['payment_type'])); ?>
                                            </span>
                                        </td>
                                        <td><strong class="text-success">K<?php echo number_format($trans['amount']); ?></strong></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $trans['payment_method'])); ?></td>
                                        <td><?php echo htmlspecialchars($trans['reference_number'] ?? '-'); ?></td>
                                        <td class="no-print"><?php echo htmlspecialchars($trans['recorded_by']); ?></td>
                                        <td class="no-print">
                                            <?php if (!empty($trans['notes'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($trans['notes']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="text-end"><strong>Total:</strong></td>
                                    <td colspan="5"><strong class="text-success">K<?php echo number_format($totals['total'] ?? 0); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info m-3">
                        <i class="bi bi-info-circle"></i> No payment transactions found for this student.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Print Footer -->
        <div class="mt-4 mb-4 text-center d-none d-print-block">
            <hr>
            <p class="text-muted">
                <small>Generated on <?php echo date('F d, Y \a\t h:i A'); ?> | University VLE System</small>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php $conn->close(); ?>
