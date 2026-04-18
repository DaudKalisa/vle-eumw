<?php
/**
 * Dean – Graduation Clearance Step
 * Review module grades entered by ICT, confirm or reject
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id   = (int)$_POST['app_id'];
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'dean'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at Dean step.';
    } elseif (!in_array($decision, ['approved', 'rejected'])) {
        $error = 'Invalid decision.';
    } else {
        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];

        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='dean', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='dean'");
        $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $officer_name, $notes, $app_id);
        $stmt->execute();

        if ($decision === 'approved') {
            // For transcript-only apps, skip RC/librarian/admin and go to registrar
            $next = ($app['application_type'] === 'transcript') ? 'registrar' : 'rc';
            $conn->query("UPDATE graduation_applications SET status='dean_approved', current_step='$next' WHERE application_id=$app_id");
            $success = "Dean clearance approved — forwarded to " . ucfirst($next) . ".";
        } else {
            $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
            $success = "Application #$app_id rejected.";
        }
    }
}

// Fetch pending
$pending = [];
$rs = $conn->query("SELECT ga.* FROM graduation_applications ga WHERE ga.current_step = 'dean' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Clearance – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
        .grade-table{font-size:.85rem;}
        .grade-table th{background:#f8fafc;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#8b5cf6;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Dean Dashboard</a>
    <span class="badge bg-purple" style="background:#8b5cf6;"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1100px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-award me-2"></i>Dean – Module Grades Review</h4>
        <p class="mb-0 opacity-75 mt-1">Review module grades entered by ICT and confirm clearance</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending Dean clearances.</div>
    <?php else: ?>
    <?php foreach ($pending as $app):
        $aid = (int)$app['application_id'];
        // Load modules
        $modules = [];
        $mr = $conn->query("SELECT * FROM graduation_ict_modules WHERE application_id = $aid ORDER BY year_of_study, module_code");
        if ($mr) while ($m = $mr->fetch_assoc()) $modules[] = $m;
        // Load grade summary
        $gs = null;
        $gr = $conn->query("SELECT * FROM graduation_grade_summary WHERE application_id = $aid LIMIT 1");
        if ($gr) $gs = $gr->fetch_assoc();
    ?>
    <div class="app-card">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?>
                    <span class="badge bg-<?= $app['application_type']==='clearance'?'success':'primary' ?>"><?= ucfirst($app['application_type']) ?></span>
                </h5>
                <div class="small text-muted">
                    #<?= $aid ?> | <?= htmlspecialchars($app['campus']) ?> | <?= htmlspecialchars($app['program']) ?>
                    | <?= $app['year_of_entry'] ?> → <?= $app['year_of_completion'] ?>
                </div>
            </div>
            <?php if ($gs): ?>
            <div class="text-end">
                <div class="small text-muted">Computed GPA</div>
                <div class="fw-bold fs-5"><?= number_format($gs['gpa'], 2) ?> <span class="badge bg-<?= $gs['classification']==='Distinction'?'success':($gs['classification']==='Merit'?'primary':($gs['classification']==='Credit'?'info':($gs['classification']==='Pass'?'warning':'danger'))) ?>"><?= $gs['classification'] ?></span></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($modules)): ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered grade-table">
                <thead><tr><th>Year</th><th>Code</th><th>Module Name</th><th>Marks</th><th>Grade</th><th>GP</th></tr></thead>
                <tbody>
                <?php
                $cur_year = '';
                foreach ($modules as $m):
                    if ($m['year_of_study'] !== $cur_year) {
                        $cur_year = $m['year_of_study'];
                        echo '<tr><td colspan="6" class="fw-bold bg-light">Year ' . (int)$cur_year . '</td></tr>';
                    }
                ?>
                <tr>
                    <td></td>
                    <td><?= htmlspecialchars($m['module_code']) ?></td>
                    <td><?= htmlspecialchars($m['module_name']) ?></td>
                    <td><?= $m['marks_obtained'] !== null ? number_format($m['marks_obtained'], 1) : 'N/A' ?></td>
                    <td><strong><?= htmlspecialchars($m['grade'] ?? '') ?></strong></td>
                    <td><?= $m['grade_point'] !== null ? number_format($m['grade_point'], 2) : '' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-warning small">No modules entered by ICT yet.</div>
        <?php endif; ?>

        <div class="row g-3 mt-2">
            <div class="col-md-8">
                <form method="post" class="d-flex gap-2 align-items-end">
                    <input type="hidden" name="app_id" value="<?= $aid ?>">
                    <input type="hidden" name="decision" value="approved">
                    <div class="flex-grow-1">
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Dean's remarks (optional)">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this student\'s grades?')"><i class="bi bi-check-lg me-1"></i>Approve Grades</button>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <form method="post" class="d-inline">
                    <input type="hidden" name="app_id" value="<?= $aid ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <input type="hidden" name="notes" value="Grade discrepancy found">
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject?')"><i class="bi bi-x-lg me-1"></i>Reject</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
