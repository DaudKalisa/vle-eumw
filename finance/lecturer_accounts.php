<?php
// finance/lecturer_accounts.php - Lecturer account summary (moved from admin)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();

// Fetch all lecturers
$stmt = $conn->query("SELECT * FROM lecturers ORDER BY full_name ASC");
$lecturers = $stmt->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Accounts - Finance</title>
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
        <h2><i class="bi bi-person-lines-fill"></i> Lecturer Accounts</h2>
        <div class="card mt-3">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lecturers as $lecturer): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($lecturer['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($lecturer['email']); ?></td>
                                <td><?php echo htmlspecialchars($lecturer['position']); ?></td>
                                <td><?php echo htmlspecialchars($lecturer['department']); ?></td>
                                <td><?php echo $lecturer['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
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
