<?php
// finance/process_lecturer_payment.php - Start payment process for approved lecturer requests
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
// Get all approved and unpaid requests
$sql = "SELECT r.*, l.full_name, l.email, l.position, l.department FROM lecturer_finance_requests r JOIN lecturers l ON r.lecturer_id = l.lecturer_id WHERE r.status = 'approved' ORDER BY r.request_date DESC";
$result = $conn->query($sql);
$requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Process Lecturer Payments</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css'>
</head>
<body>
    <nav class='navbar navbar-expand-lg navbar-dark bg-success'>
        <div class='container-fluid'>
            <a class='navbar-brand' href='dashboard.php'>VLE Finance</a>
            <div class='navbar-nav ms-auto'>
                <a class='nav-link' href='finance_manage_requests.php'><i class='bi bi-arrow-left'></i> Back to Requests</a>
            </div>
        </div>
    </nav>
    <div class='container mt-4'>
        <h3>Approved Lecturer Requests - Ready for Payment</h3>
        <div class='card'>
            <div class='card-body'>
                <div class='table-responsive'>
                    <table class='table table-hover'>
                        <thead class='table-success'>
                            <tr>
                                <th>Date</th>
                                <th>Lecturer</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Modules</th>
                                <th>Hours</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($requests) > 0): ?>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($req['request_date']); ?></td>
                                    <td><?php echo htmlspecialchars($req['full_name']); ?><br><small><?php echo htmlspecialchars($req['email']); ?></small></td>
                                    <td><?php echo htmlspecialchars($req['position']); ?></td>
                                    <td><?php echo htmlspecialchars($req['department']); ?></td>
                                    <td><?php echo htmlspecialchars($req['month'] . '/' . $req['year']); ?></td>
                                    <td><?php echo htmlspecialchars($req['total_modules']); ?></td>
                                    <td><?php echo htmlspecialchars($req['total_hours']); ?>h</td>
                                    <td><strong>K<?php echo number_format((float)($req['total_amount'] ?? 0)); ?></strong></td>
                                    <td>
                                        <form method="post" action="pay_lecturer.php" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Mark as paid and print confirmation?');"><i class="bi bi-cash-coin"></i> Pay & Print</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted">No approved requests found.</td></tr>
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
