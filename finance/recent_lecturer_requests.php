<?php
// finance/recent_lecturer_requests.php - Recent Lecturer Requests for Finance Management
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$recent_lecturer_requests = [];
$result = $conn->query("
    SELECT lfr.*, l.full_name as lecturer_name, l.department
    FROM lecturer_finance_requests lfr
    LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
    ORDER BY lfr.submission_date DESC
    LIMIT 10
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_lecturer_requests[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Lecturer Requests - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">VLE Finance</a>
            <div class="d-flex align-items-center ms-auto">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4 mb-5">
        <div class="content-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history text-warning"></i> Recent Lecturer Requests</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Lecturer Name</th>
                                <th>Month</th>
                                <th>Hours</th>
                                <th>Amount</th>
                                <th>Date of Request</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_lecturer_requests as $req): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($req['lecturer_name'] ?? ''); ?></td>
                                <td><?php echo isset($req['month'], $req['year']) ? date('F Y', strtotime($req['year'].'-'.$req['month'].'-01')) : ''; ?></td>
                                <td><?php echo htmlspecialchars($req['total_hours'] ?? ''); ?></td>
                                <td><span class="badge bg-success">K<?php echo number_format($req['total_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo !empty($req['submission_date']) ? date('M d, Y', strtotime($req['submission_date'])) : ''; ?></td>
                                <td>
                                    <?php if (($req['status'] ?? '') === 'pending'): ?>
                                        <a href="../admin/finance_request_action.php?id=<?php echo $req['request_id']; ?>&action=approve&ref=recent" class="btn btn-success btn-sm" onclick="return confirm('Approve this request?');">Approve</a>
                                        <a href="../admin/finance_request_action.php?id=<?php echo $req['request_id']; ?>&action=reject&ref=recent" class="btn btn-danger btn-sm ms-1" onclick="return confirm('Reject this request?');">Deny</a>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No Action</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
