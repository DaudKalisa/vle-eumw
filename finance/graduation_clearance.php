<?php
/**
 * Finance – Graduation Clearance Step
 * Check tuition fee balance, dissertation fee, graduation fee
 * Approve or refer student to campus finance
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// POST – approve / reject / refer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id   = (int)$_POST['app_id'];
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');
    $tuition_balance  = (float)($_POST['tuition_fee_balance'] ?? 0);
    $dissertation_paid = isset($_POST['dissertation_fee_paid']) ? 1 : 0;
    $graduation_paid   = isset($_POST['graduation_fee_paid']) ? 1 : 0;
    $referral_campus   = trim($_POST['referral_campus'] ?? '');

    if (!in_array($decision, ['approved', 'rejected', 'referred'])) {
        $error = 'Invalid decision.';
    } else {
        // Verify app exists and current step is finance
        $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'finance'");
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();

        if (!$app) {
            $error = 'Application not found or not at finance step.';
        } else {
            $conn->begin_transaction();
            try {
                // Save finance details
                $conn->query("DELETE FROM graduation_finance_details WHERE application_id = $app_id");
                $stmt = $conn->prepare("INSERT INTO graduation_finance_details (application_id, tuition_fee_balance, dissertation_fee_paid, graduation_fee_paid, decision, referral_campus) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("idiiss", $app_id, $tuition_balance, $dissertation_paid, $graduation_paid, $decision, $referral_campus);
                $stmt->execute();

                // Update clearance step
                $officer_name = htmlspecialchars($user['display_name'] ?? $user['username']);
                $sig = $officer_name;
                $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='finance', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='finance'");
                $uid = (int)$user['user_id'];
                $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $sig, $notes, $app_id);
                $stmt->execute();

                // Advance or update application status
                if ($decision === 'approved') {
                    $conn->query("UPDATE graduation_applications SET status='finance_approved', current_step='ict' WHERE application_id=$app_id");
                } elseif ($decision === 'referred') {
                    $conn->query("UPDATE graduation_applications SET status='finance_referred' WHERE application_id=$app_id");
                } else {
                    $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
                }

                $conn->commit();
                $success = 'Finance clearance decision recorded for Application #' . $app_id;
            } catch (\Throwable $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch pending finance clearance apps
$pending = [];
$rs = $conn->query("SELECT ga.*, u.username FROM graduation_applications ga LEFT JOIN users u ON ga.user_id = u.user_id WHERE ga.current_step = 'finance' AND ga.status IN ('pending','finance_referred') ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;

// Fetch recently processed
$processed = [];
$rs = $conn->query("SELECT ga.*, gcs.status as step_status, gcs.notes as step_notes, gcs.actioned_at, gfd.* FROM graduation_applications ga JOIN graduation_clearance_steps gcs ON ga.application_id = gcs.application_id AND gcs.step_name = 'finance' LEFT JOIN graduation_finance_details gfd ON ga.application_id = gfd.application_id WHERE gcs.status != 'pending' ORDER BY gcs.actioned_at DESC LIMIT 20");
if ($rs) while ($r = $rs->fetch_assoc()) $processed[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Clearance – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-form{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#f59e0b;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Finance Dashboard</a>
    <span class="badge bg-warning text-dark"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1100px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Finance – Graduation Clearance</h4>
        <p class="mb-0 opacity-75 mt-1">Verify tuition balance, dissertation and graduation fees</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending finance clearances.</div>
    <?php endif; ?>

    <?php foreach ($pending as $app): ?>
    <div class="app-form">
        <h5><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?>
            <span class="badge bg-<?= $app['application_type']==='clearance'?'success':'primary' ?>"><?= ucfirst($app['application_type']) ?></span>
            <?php if ($app['status'] === 'finance_referred'): ?><span class="badge bg-info">Referred – Re-check</span><?php endif; ?>
        </h5>
        <div class="row small text-muted mb-3">
            <div class="col-auto">ID: <?= htmlspecialchars($app['student_id_number'] ?? 'N/A') ?></div>
            <div class="col-auto">Campus: <?= htmlspecialchars($app['campus']) ?></div>
            <div class="col-auto">Program: <?= htmlspecialchars($app['program']) ?></div>
            <div class="col-auto">Entry: <?= $app['year_of_entry'] ?> → <?= $app['year_of_completion'] ?></div>
        </div>

        <form method="post">
            <input type="hidden" name="app_id" value="<?= $app['application_id'] ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-500">Tuition Fee Balance (MWK)</label>
                    <input type="number" name="tuition_fee_balance" class="form-control" step="0.01" min="0" value="0" required>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="dissertation_fee_paid" class="form-check-input" id="diss_<?= $app['application_id'] ?>">
                        <label class="form-check-label" for="diss_<?= $app['application_id'] ?>">Dissertation Fee Paid</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="graduation_fee_paid" class="form-check-input" id="grad_<?= $app['application_id'] ?>">
                        <label class="form-check-label" for="grad_<?= $app['application_id'] ?>">Graduation Fee Paid</label>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Decision</label>
                    <select name="decision" class="form-select" required onchange="document.getElementById('ref_<?= $app['application_id'] ?>').style.display=this.value==='referred'?'block':'none'">
                        <option value="">Select...</option>
                        <option value="approved">Approved – Clear</option>
                        <option value="referred">Refer to Campus Finance</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-md-4" id="ref_<?= $app['application_id'] ?>" style="display:none">
                    <label class="form-label">Referral Campus</label>
                    <select name="referral_campus" class="form-select">
                        <option value="Blantyre Campus">Blantyre</option>
                        <option value="Lilongwe Campus">Lilongwe</option>
                        <option value="Mzuzu Campus">Mzuzu</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional remarks">
                </div>
            </div>
            <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>Submit Decision</button>
        </form>
    </div>
    <?php endforeach; ?>

    <!-- Recently Processed -->
    <?php if (!empty($processed)): ?>
    <h5 class="mt-4 mb-3"><i class="bi bi-clock-history me-2"></i>Recently Processed</h5>
    <div class="table-responsive">
        <table class="table table-striped bg-white rounded">
            <thead><tr><th>Student</th><th>Balance</th><th>Diss Fee</th><th>Grad Fee</th><th>Decision</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($processed as $p): ?>
            <tr>
                <td><?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?></td>
                <td>MWK <?= number_format($p['tuition_fee_balance'] ?? 0, 2) ?></td>
                <td><?= ($p['dissertation_fee_paid'] ?? 0) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                <td><?= ($p['graduation_fee_paid'] ?? 0) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                <td><span class="badge bg-<?= ($p['step_status']??'')==='approved'?'success':(($p['step_status']??'')==='referred'?'info':'danger') ?>"><?= ucfirst($p['step_status'] ?? '') ?></span></td>
                <td class="small"><?= $p['actioned_at'] ? date('M d, Y', strtotime($p['actioned_at'])) : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
