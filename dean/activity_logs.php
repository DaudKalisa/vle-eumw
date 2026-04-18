<?php
/**
 * Dean Portal - Activity Logs
 * View activity logs for the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Get dean approval logs
$dean_logs = [];
$result = $conn->query("
    SELECT dca.*, lfr.total_amount, l.full_name
    FROM dean_claims_approval dca
    JOIN lecturer_finance_requests lfr ON dca.request_id = lfr.request_id
    JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
    ORDER BY dca.approved_at DESC
    LIMIT 100
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dean_logs[] = $row;
    }
}

// Get recent announcements
$announcements = [];
$result = $conn->query("SELECT * FROM dean_announcements ORDER BY created_at DESC LIMIT 20");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$page_title = "Activity Logs";
$breadcrumbs = [['title' => 'Activity Logs']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Claims Approval Log -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Claims Approval History</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($dean_logs)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-clock-history fs-1 d-block mb-3"></i>
                            <p>No approval history yet</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lecturer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dean_logs as $log): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($log['full_name']) ?></strong></td>
                                        <td>MKW <?= number_format($log['total_amount']) ?></td>
                                        <td>
                                            <?php
                                            $badge = ['approved' => 'success', 'rejected' => 'danger', 'returned' => 'warning'][$log['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge ?>"><?= ucfirst($log['status']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars(substr($log['remarks'] ?? '', 0, 50)) ?><?= strlen($log['remarks'] ?? '') > 50 ? '...' : '' ?></td>
                                        <td><?= date('M d, Y H:i', strtotime($log['approved_at'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Announcements Log -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-megaphone me-2"></i>Announcements</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($announcements)): ?>
                        <div class="text-center py-5 text-muted">
                            <p>No announcements</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($announcements as $ann): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted"><?= date('M d', strtotime($ann['created_at'])) ?></small>
                                    <span class="badge bg-<?= $ann['target_audience'] === 'all' ? 'primary' : 'info' ?>"><?= ucfirst($ann['target_audience']) ?></span>
                                </div>
                                <strong><?= htmlspecialchars($ann['title']) ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
