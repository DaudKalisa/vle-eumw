<?php
/**
 * ODL Coordinator - Activity Logs
 * View system activity and audit trail
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

// Filter parameters
$filter_type = $_GET['type'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_user = $_GET['user'] ?? '';

// Get login history
$where = ["1=1"];
$params = [];
$types = "";

if ($filter_date) {
    $where[] = "DATE(lh.login_time) = ?";
    $params[] = $filter_date;
    $types .= "s";
}

if ($filter_user) {
    $where[] = "(u.username LIKE ? OR COALESCE(s.full_name, l.full_name, u.username) LIKE ?)";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $types .= "ss";
}

$where_sql = "WHERE " . implode(" AND ", $where);

$sql = "
    SELECT lh.*, u.username, u.role,
           COALESCE(s.full_name, l.full_name, u.username) as user_full_name
    FROM login_history lh
    JOIN users u ON lh.user_id = u.user_id
    LEFT JOIN students s ON u.related_student_id = s.student_id
    LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    $where_sql
    ORDER BY lh.login_time DESC
    LIMIT 200
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$logs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}

// Statistics
$today_logins = $conn->query("SELECT COUNT(*) as c FROM login_history WHERE DATE(login_time) = CURDATE()")->fetch_assoc()['c'];
$unique_today = $conn->query("SELECT COUNT(DISTINCT user_id) as c FROM login_history WHERE DATE(login_time) = CURDATE()")->fetch_assoc()['c'];
$failed_logins = $conn->query("SELECT COUNT(*) as c FROM login_history WHERE DATE(login_time) = CURDATE() AND is_successful = 0")->fetch_assoc()['c'];

$page_title = 'Activity Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-clock-history me-2"></i>Activity Logs</h1>
                <p class="text-muted mb-0">System login activity and audit trail</p>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($today_logins) ?></div>
                        <small>Total Logins Today</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($unique_today) ?></div>
                        <small>Unique Users Today</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center">
                        <div class="h2 mb-0"><?= number_format($failed_logins) ?></div>
                        <small>Failed Logins Today</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Search User</label>
                        <input type="text" name="user" class="form-control form-control-sm" placeholder="Username or name..." value="<?= htmlspecialchars($filter_user) ?>">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="activity_logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Logs Table -->
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0">Login History (<?= count($logs) ?> records)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Device</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <small><?= date('M j, Y', strtotime($log['login_time'])) ?></small>
                                    <div class="small text-muted"><?= date('g:i:s a', strtotime($log['login_time'])) ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($log['user_full_name']) ?></strong>
                                    <div class="small text-muted">@<?= htmlspecialchars($log['username']) ?></div>
                                </td>
                                <td><span class="badge bg-secondary"><?= ucfirst($log['role']) ?></span></td>
                                <td><code><?= htmlspecialchars($log['ip_address']) ?></code></td>
                                <td><small><?= htmlspecialchars($log['device_type'] ?? 'Unknown') ?></small></td>
                                <td>
                                    <?php if ($log['is_successful']): ?>
                                    <span class="badge bg-success">Success</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Failed</span>
                                    <?php endif; ?>
                                    <?php if (!empty($log['is_suspicious'])): ?>
                                    <span class="badge bg-warning text-dark">Suspicious</span>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
