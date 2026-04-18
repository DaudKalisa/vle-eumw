<?php
/**
 * Admin – Transcript Generation (Graduation Clearance Step)
 * Generate academic transcript for the student
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

    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'admin'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at Admin step.';
    } elseif (!in_array($decision, ['approved', 'rejected'])) {
        $error = 'Invalid decision.';
    } else {
        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];

        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status=?, officer_user_id=?, officer_name=?, officer_role='admin', officer_title='Administrator', signature_text=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='admin'");
        $stmt->bind_param("sisssi", $decision, $uid, $officer_name, $officer_name, $notes, $app_id);
        $stmt->execute();

        if ($decision === 'approved') {
            $conn->query("UPDATE graduation_applications SET status='admin_generated', current_step='registrar' WHERE application_id=$app_id");
            $success = "Transcript generated — forwarded to Registrar for approval.";
        } else {
            $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
            $success = "Application #$app_id rejected.";
        }
    }
}

$pending = [];
$rs = $conn->query("SELECT ga.*, ggs.gpa, ggs.classification, ggs.total_credits FROM graduation_applications ga LEFT JOIN graduation_grade_summary ggs ON ga.application_id = ggs.application_id WHERE ga.current_step = 'admin' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Transcript – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#6366f1,#4338ca);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:1rem;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#6366f1;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Admin Dashboard</a>
    <span class="badge" style="background:#6366f1;color:#fff;"><?= count($pending) ?> Pending</span>
</div>
<div class="container-fluid py-4" style="max-width:1000px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Admin – Transcript Generation</h4>
        <p class="mb-0 opacity-75 mt-1">Generate and confirm academic transcripts for cleared students</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending transcript generation.</div>
    <?php else: foreach ($pending as $app):
        $aid = (int)$app['application_id'];
        $modules = [];
        $mr = $conn->query("SELECT * FROM graduation_ict_modules WHERE application_id = $aid ORDER BY year_of_study, module_code");
        if ($mr) while ($m = $mr->fetch_assoc()) $modules[] = $m;
    ?>
    <div class="app-card">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?></h5>
                <div class="small text-muted">
                    #<?= $aid ?> | ID: <?= htmlspecialchars($app['student_id_number'] ?? 'N/A') ?>
                    | <?= htmlspecialchars($app['campus']) ?> | <?= htmlspecialchars($app['program']) ?>
                    | <?= $app['year_of_entry'] ?> → <?= $app['year_of_completion'] ?>
                </div>
            </div>
            <div class="text-end">
                <?php if ($app['gpa']): ?>
                <div class="fw-bold fs-5"><?= number_format($app['gpa'], 2) ?></div>
                <span class="badge bg-<?= ($app['classification']??'')==='Distinction'?'success':(($app['classification']??'')==='Merit'?'primary':'info') ?>"><?= $app['classification'] ?? '' ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($modules)): ?>
        <details class="mb-3">
            <summary class="small fw-500 text-primary" style="cursor:pointer;">View Module Grades (<?= count($modules) ?> modules)</summary>
            <table class="table table-sm table-bordered mt-2" style="font-size:.8rem;">
                <thead><tr><th>Year</th><th>Code</th><th>Module</th><th>Marks</th><th>Grade</th><th>GP</th></tr></thead>
                <tbody>
                <?php foreach ($modules as $m): ?>
                <tr><td>Year <?= (int)($m['year_of_study'] ?? 0) ?></td><td><?= htmlspecialchars($m['module_code']) ?></td><td><?= htmlspecialchars($m['module_name']) ?></td><td><?= $m['marks_obtained'] ?></td><td><?= htmlspecialchars($m['grade'] ?? '') ?></td><td><?= $m['grade_point'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
            <a href="../api/generate_graduation_transcript.php?app_id=<?= $aid ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-pdf me-1"></i>Preview Transcript PDF</a>
            <form method="post" class="d-flex gap-2 align-items-end">
                <input type="hidden" name="app_id" value="<?= $aid ?>">
                <input type="hidden" name="decision" value="approved">
                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Admin notes" style="width:200px;">
                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Confirm transcript generation?')"><i class="bi bi-check-lg me-1"></i>Confirm & Forward</button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="app_id" value="<?= $aid ?>">
                <input type="hidden" name="decision" value="rejected">
                <input type="hidden" name="notes" value="Transcript generation issue">
                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject?')"><i class="bi bi-x-lg"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
