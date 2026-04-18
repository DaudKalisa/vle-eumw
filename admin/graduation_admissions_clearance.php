<?php
/**
 * Admissions – Graduation Clearance Final Step
 * File the transcript, mark student as cleared, send notification
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id   = (int)$_POST['app_id'];
    $decision = $_POST['decision'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'admissions'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at Admissions step.';
    } elseif (!in_array($decision, ['approved', 'rejected'])) {
        $error = 'Invalid decision.';
    } else {
        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];

        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='admissions', officer_title='Admissions Officer', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='admissions'");
        $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $officer_name, $notes, $app_id);
        $stmt->execute();

        if ($decision === 'approved') {
            $conn->query("UPDATE graduation_applications SET status='completed', current_step='completed' WHERE application_id=$app_id");
            $success = "Application #$app_id — Student cleared and filed! Clearance certificate is now available.";
        } else {
            $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
            $success = "Application #$app_id rejected.";
        }
    }
}

$pending = [];
$rs = $conn->query("SELECT ga.*, ggs.gpa, ggs.classification FROM graduation_applications ga LEFT JOIN graduation_grade_summary ggs ON ga.application_id = ggs.application_id WHERE ga.current_step = 'admissions' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admissions Filing – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#9333ea,#7c3aed);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#9333ea;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Admin Dashboard</a>
    <span class="badge" style="background:#9333ea;color:#fff;"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1000px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-folder-check me-2"></i>Admissions – Filing & Clearance Issuance</h4>
        <p class="mb-0 opacity-75 mt-1">File the transcript and issue graduation clearance to the student</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending admissions filing.</div>
    <?php else: foreach ($pending as $app): $aid = (int)$app['application_id']; ?>
    <div class="app-card">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?></h5>
                <div class="small text-muted">
                    #<?= $aid ?> | ID: <?= htmlspecialchars($app['student_id_number'] ?? 'N/A') ?>
                    | <?= htmlspecialchars($app['campus'] ?? '') ?> | <?= htmlspecialchars($app['program'] ?? '') ?>
                </div>
            </div>
            <?php if ($app['gpa']): ?>
            <div class="text-end">
                <div class="fw-bold fs-4"><?= number_format($app['gpa'], 2) ?></div>
                <span class="badge bg-success"><?= $app['classification'] ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- All cleared steps -->
        <?php
        $all_steps = [];
        $asr = $conn->query("SELECT * FROM graduation_clearance_steps WHERE application_id = $aid ORDER BY step_id");
        if ($asr) while ($as = $asr->fetch_assoc()) $all_steps[] = $as;
        ?>
        <div class="mb-3">
            <strong class="small">Clearance Trail:</strong>
            <div class="d-flex flex-wrap gap-1 mt-1">
            <?php foreach ($all_steps as $s): ?>
                <span class="badge bg-<?= $s['status']==='approved'?'success':'secondary' ?>" title="<?= htmlspecialchars($s['officer_name'] ?? '') ?> — <?= $s['actioned_at'] ?? '' ?>">
                    <?= ucfirst($s['step_name']) ?>
                    <?= $s['status']==='approved'?'✓':'—' ?>
                </span>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <form method="post" class="d-flex gap-2 align-items-end">
                <input type="hidden" name="app_id" value="<?= $aid ?>">
                <input type="hidden" name="decision" value="approved">
                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Filing notes" style="width:250px;">
                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('File transcript and issue graduation clearance?')"><i class="bi bi-folder-check me-1"></i>File & Clear Student</button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="app_id" value="<?= $aid ?>">
                <input type="hidden" name="decision" value="rejected">
                <input type="hidden" name="notes" value="Filing issue">
                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject?')"><i class="bi bi-x-lg"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
