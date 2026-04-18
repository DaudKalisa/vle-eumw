<?php
/**
 * Research Coordinator – Graduation Clearance Step
 * Verify dissertation submission / printing
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id   = (int)$_POST['app_id'];
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'rc'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at Research Coordinator step.';
    } elseif (!in_array($decision, ['approved', 'rejected'])) {
        $error = 'Invalid decision.';
    } else {
        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];

        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='research_coordinator', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='rc'");
        $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $officer_name, $notes, $app_id);
        $stmt->execute();

        if ($decision === 'approved') {
            $conn->query("UPDATE graduation_applications SET status='rc_approved', current_step='librarian' WHERE application_id=$app_id");
            $success = "Research Coordinator clearance approved — forwarded to Library.";
        } else {
            $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
            $success = "Application #$app_id rejected.";
        }
    }
}

$pending = [];
$rs = $conn->query("SELECT ga.* FROM graduation_applications ga WHERE ga.current_step = 'rc' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RC Clearance – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#059669;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>RC Dashboard</a>
    <span class="badge bg-success"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1000px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-journal-bookmark me-2"></i>Research Coordinator – Dissertation Clearance</h4>
        <p class="mb-0 opacity-75 mt-1">Verify that the student has submitted and printed their dissertation</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending RC clearances.</div>
    <?php else: foreach ($pending as $app):
        $aid = (int)$app['application_id'];
        // Check if student has a dissertation in the system
        $diss = null;
        $dr = $conn->query("SELECT d.* FROM dissertations d JOIN users u ON d.student_id = u.related_student_id WHERE u.user_id = " . (int)$app['user_id'] . " ORDER BY d.dissertation_id DESC LIMIT 1");
        if ($dr) $diss = $dr->fetch_assoc();
    ?>
    <div class="app-card">
        <h5 class="mb-1"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?>
            <span class="badge bg-success"><?= ucfirst($app['application_type']) ?></span>
        </h5>
        <div class="small text-muted mb-3">
            #<?= $aid ?> | <?= htmlspecialchars($app['campus']) ?> | <?= htmlspecialchars($app['program']) ?>
        </div>

        <?php if ($diss): ?>
        <div class="alert alert-info small mb-2">
            <i class="bi bi-journal-text me-1"></i>
            <strong>Dissertation found:</strong> <?= htmlspecialchars($diss['title'] ?? 'Untitled') ?>
            — Status: <strong><?= htmlspecialchars($diss['status'] ?? 'N/A') ?></strong>
            <?php if (!empty($diss['file_path'])): ?> | <i class="bi bi-file-earmark-check text-success"></i> File uploaded<?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>No dissertation record found in the system for this student.</div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-8">
                <form method="post" class="d-flex gap-2 align-items-end">
                    <input type="hidden" name="app_id" value="<?= $aid ?>">
                    <input type="hidden" name="decision" value="approved">
                    <div class="flex-grow-1">
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="RC remarks (optional)">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Clear this student\'s dissertation?')"><i class="bi bi-check-lg me-1"></i>Clear</button>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <form method="post" class="d-inline">
                    <input type="hidden" name="app_id" value="<?= $aid ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <input type="hidden" name="notes" value="Dissertation not submitted">
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject?')"><i class="bi bi-x-lg me-1"></i>Reject</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
