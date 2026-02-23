<?php
// finance/outstanding_balances.php - Outstanding Balances Page
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff', 'admin']);

$conn = getDbConnection();

// Get outstanding balances for all students
$sql = "SELECT s.student_id, s.full_name, s.email, sf.expected_total, sf.total_paid, (sf.expected_total - sf.total_paid) AS outstanding
    FROM students s
    JOIN student_finances sf ON CAST(s.student_id AS CHAR) COLLATE utf8mb4_general_ci = CAST(sf.student_id AS CHAR) COLLATE utf8mb4_general_ci
    WHERE s.is_active = TRUE AND (sf.expected_total - sf.total_paid) > 0
    ORDER BY outstanding DESC";
$result = $conn->query($sql);

$outstandings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-exclamation-triangle"></i> Outstanding Balances
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <h3 class="mb-4">Outstanding Student Balances</h3>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-danger">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Expected Total</th>
                                <th>Total Paid</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($outstandings) > 0): ?>
                            <?php foreach ($outstandings as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td>K<?php echo number_format($row['expected_total']); ?></td>
                                    <td>K<?php echo number_format($row['total_paid']); ?></td>
                                    <td><strong class="text-danger">K<?php echo number_format($row['outstanding']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted">No outstanding balances found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
