<?php
/**
 * Exam Clearance Students List
 * Finance officers review exam clearance applications from system students
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['clearance_id'])) {
        $cid = (int)$_POST['clearance_id'];
        $conn->query("DELETE FROM exam_clearance_payments WHERE clearance_id = $cid");
        $conn->query("DELETE FROM exam_clearance_students WHERE clearance_id = $cid");
        $success = 'Student record deleted.';
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_program = $_GET['program_type'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = "1=1";
$params = [];
$types = '';

if ($filter_status && in_array($filter_status, ['registered', 'invoiced', 'proof_submitted', 'cleared', 'rejected'])) {
    $where .= " AND ecs.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_program && in_array($filter_program, ['degree', 'professional', 'masters', 'doctorate'])) {
    $where .= " AND ecs.program_type = ?";
    $params[] = $filter_program;
    $types .= 's';
}
if ($search) {
    $where .= " AND (ecs.student_id LIKE ? OR ecs.full_name LIKE ? OR ecs.email LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'sss';
}

$query = "SELECT ecs.*, 
          (SELECT COUNT(*) FROM exam_clearance_payments ecp WHERE ecp.clearance_id = ecs.clearance_id) as payment_count,
          (SELECT SUM(ecp.amount) FROM exam_clearance_payments ecp WHERE ecp.clearance_id = ecs.clearance_id AND ecp.status = 'approved') as total_approved
          FROM exam_clearance_students ecs 
          WHERE $where 
          ORDER BY ecs.registered_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$total = count($students);
$stats_rs = $conn->query("SELECT status, COUNT(*) as cnt FROM exam_clearance_students GROUP BY status");
$stats = [];
if ($stats_rs) while ($r = $stats_rs->fetch_assoc()) $stats[$r['status']] = $r['cnt'];

// Check for active clearance windows
$active_windows = [];
$aw_rs = $conn->query("SELECT * FROM exam_clearance_invites WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses = 0 OR times_used < max_uses) ORDER BY created_at DESC");
if ($aw_rs) while ($r = $aw_rs->fetch_assoc()) $active_windows[] = $r;

$page_title = 'Exam Clearance Students';
$breadcrumbs = [['title' => 'Exam Clearance Students']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/finance-dashboard.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .stat-card { border-radius: 12px; padding: 1rem 1.25rem; color: #fff; }
        .stat-card h3 { font-size: 1.75rem; font-weight: 700; margin: 0; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2 text-success"></i>Exam Clearance Students</h4>
            <p class="text-muted mb-0">System students who applied for examination clearance</p>
        </div>
        <div>
            <a href="exam_clearance_invites.php" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Manage Windows</a>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col-md-2 col-sm-4 mb-2">
            <div class="stat-card bg-primary">
                <small class="opacity-75">Total</small>
                <h3><?= array_sum($stats) ?></h3>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 mb-2">
            <div class="stat-card bg-info">
                <small class="opacity-75">Invoiced</small>
                <h3><?= ($stats['invoiced'] ?? 0) + ($stats['registered'] ?? 0) ?></h3>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 mb-2">
            <div class="stat-card bg-warning">
                <small class="opacity-75">Proof Submitted</small>
                <h3><?= $stats['proof_submitted'] ?? 0 ?></h3>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 mb-2">
            <div class="stat-card bg-success">
                <small class="opacity-75">Cleared</small>
                <h3><?= $stats['cleared'] ?? 0 ?></h3>
            </div>
        </div>
        <div class="col-md-2 col-sm-4 mb-2">
            <div class="stat-card bg-danger">
                <small class="opacity-75">Rejected</small>
                <h3><?= $stats['rejected'] ?? 0 ?></h3>
            </div>
        </div>
    </div>
    
    <!-- Clearance Period Status Card -->
    <?php if (empty($active_windows)): ?>
    <div class="card shadow-sm border-0 mb-4 border-start border-warning border-4">
        <div class="card-body d-flex align-items-center justify-content-between py-3">
            <div class="d-flex align-items-center">
                <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:50px;height:50px;">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size:1.5rem"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold text-warning">No Open Examination Clearance Period</h6>
                    <small class="text-muted">Students cannot apply for exam clearance until a clearance window is opened.</small>
                </div>
            </div>
            <a href="exam_clearance_invites.php" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Open Clearance Window</a>
        </div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm border-0 mb-4 border-start border-success border-4">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:50px;height:50px;">
                        <i class="bi bi-shield-check text-success" style="font-size:1.5rem"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-success">Examination Clearance Period Open</h6>
                        <small class="text-muted"><?= count($active_windows) ?> active window<?= count($active_windows) > 1 ? 's' : '' ?> — students can apply from their dashboard.</small>
                    </div>
                </div>
                <a href="exam_clearance_invites.php" class="btn btn-outline-success btn-sm"><i class="bi bi-gear me-1"></i>Manage Windows</a>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2 ms-5 ps-3">
                <?php foreach ($active_windows as $aw): ?>
                <span class="badge bg-success bg-opacity-75 fw-normal py-2 px-3">
                    <i class="bi bi-mortarboard me-1"></i><?= ucfirst($aw['program_type']) ?>
                    <?php if ($aw['description']): ?> — <?= htmlspecialchars($aw['description']) ?><?php endif; ?>
                    <?php if ($aw['expires_at']): ?>
                        <span class="ms-2 opacity-75"><i class="bi bi-clock me-1"></i>Expires <?= date('M j', strtotime($aw['expires_at'])) ?></span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="Name, ID, Email...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="registered" <?= $filter_status === 'registered' ? 'selected' : '' ?>>Registered</option>
                        <option value="invoiced" <?= $filter_status === 'invoiced' ? 'selected' : '' ?>>Invoiced</option>
                        <option value="proof_submitted" <?= $filter_status === 'proof_submitted' ? 'selected' : '' ?>>Proof Submitted</option>
                        <option value="cleared" <?= $filter_status === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                        <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Program</label>
                    <select name="program_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="degree" <?= $filter_program === 'degree' ? 'selected' : '' ?>>Degree</option>
                        <option value="professional" <?= $filter_program === 'professional' ? 'selected' : '' ?>>Professional</option>
                        <option value="masters" <?= $filter_program === 'masters' ? 'selected' : '' ?>>Masters</option>
                        <option value="doctorate" <?= $filter_program === 'doctorate' ? 'selected' : '' ?>>Doctorate</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="exam_clearance_students.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Students Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Program Type</th>
                            <th>Invoiced</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Applied</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No exam clearance students found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($s['student_id']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?php if ($s['email']): ?><br><small class="text-muted"><?= htmlspecialchars($s['email']) ?></small><?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $s['program_type'] === 'masters' ? 'info' : ($s['program_type'] === 'doctorate' ? 'danger' : 'primary') ?>"><?= ucfirst($s['program_type']) ?></span></td>
                            <td>MWK <?= number_format($s['invoiced_amount'], 2) ?></td>
                            <td>MWK <?= number_format($s['total_approved'] ?? 0, 2) ?></td>
                            <td>
                                <?php $bal = $s['invoiced_amount'] - ($s['total_approved'] ?? 0); ?>
                                <span class="<?= $bal > 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                                    MWK <?= number_format($bal, 2) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $status_colors = ['registered' => 'secondary', 'invoiced' => 'info', 'proof_submitted' => 'warning', 'proof_requested' => 'info', 'cleared' => 'success', 'rejected' => 'danger'];
                                $status_labels = ['registered' => 'Registered', 'invoiced' => 'Invoiced', 'proof_submitted' => 'Proof Submitted', 'cleared' => 'Cleared', 'rejected' => 'Rejected'];
                                ?>
                                <span class="badge bg-<?= $status_colors[$s['status']] ?? 'secondary' ?>"><?= $status_labels[$s['status']] ?? $s['status'] ?></span>
                            </td>
                            <td><small><?= date('M j, Y', strtotime($s['registered_at'])) ?></small></td>
                            <td>
                                <a href="exam_clearance_review.php?id=<?= $s['clearance_id'] ?>" class="btn btn-sm btn-outline-primary" title="Review"><i class="bi bi-eye"></i></a>
                                <?php if ($s['status'] === 'cleared'): ?>
                                    <a href="exam_clearance_certificate.php?id=<?= $s['clearance_id'] ?>" class="btn btn-sm btn-outline-success" title="Certificate"><i class="bi bi-award"></i></a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student record?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="clearance_id" value="<?= $s['clearance_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Showing <?= count($students) ?> students
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
