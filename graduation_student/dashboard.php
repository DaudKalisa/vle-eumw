<?php
/**
 * Graduation Student Portal / Dashboard
 * Shows clearance application status, step tracker, certificate download
 */
require_once '../includes/auth.php';
requireLogin();

// Must be a graduation_student
if (!hasRole('graduation_student') && !hasRole('admin')) {
    header('Location: ../dashboard.php');
    exit();
}

$conn = getDbConnection();
$user = getCurrentUser();
$uid  = (int)$user['user_id'];

// Fetch graduation application
$stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE user_id = ? ORDER BY application_id DESC LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();

$steps = [];
$grade_summary = null;
$finance_details = null;

if ($app) {
    $aid = (int)$app['application_id'];
    // Clearance steps
    $sr = $conn->query("SELECT * FROM graduation_clearance_steps WHERE application_id = $aid ORDER BY step_id ASC");
    if ($sr) while ($s = $sr->fetch_assoc()) $steps[$s['step_name']] = $s;

    // Grade summary
    $gr = $conn->query("SELECT * FROM graduation_grade_summary WHERE application_id = $aid LIMIT 1");
    if ($gr) $grade_summary = $gr->fetch_assoc();

    // Finance details
    $fr = $conn->query("SELECT * FROM graduation_finance_details WHERE application_id = $aid LIMIT 1");
    if ($fr) $finance_details = $fr->fetch_assoc();
}

$all_steps = [
    'finance'    => ['Finance Check', 'bi-cash-coin', '#f59e0b', 'Tuition and fees verification'],
    'ict'        => ['ICT / Transcript', 'bi-pc-display', '#3b82f6', 'Academic records & module grades'],
    'dean'       => ['Dean Review', 'bi-award', '#8b5cf6', 'Module grades confirmation'],
    'rc'         => ['Research Coordinator', 'bi-journal-bookmark', '#059669', 'Dissertation clearance'],
    'librarian'  => ['Library Clearance', 'bi-book', '#0891b2', 'Outstanding books check'],
    'admin'      => ['Admin / Transcript', 'bi-file-earmark-text', '#6366f1', 'Transcript generation'],
    'registrar'  => ['Registrar Approval', 'bi-check2-circle', '#e11d48', 'Final approval'],
    'admissions' => ['Admissions Filing', 'bi-folder-check', '#9333ea', 'Filing and clearance issuance'],
];

// For transcript-only, only show relevant steps
if ($app && $app['application_type'] === 'transcript') {
    $all_steps = array_intersect_key($all_steps, array_flip(['finance', 'ict', 'dean', 'registrar']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduation Portal – VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;min-height:100vh;}
        .top-nav{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .top-nav .brand{font-weight:700;font-size:1.05rem;color:#059669;text-decoration:none;display:flex;align-items:center;gap:.5rem;}
        .hero{background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:16px;padding:2rem;margin-bottom:1.5rem;}
        .info-card{background:#fff;border-radius:12px;padding:1.25rem;border:1px solid #e2e8f0;margin-bottom:1rem;}
        .step-tracker{position:relative;padding:0;}
        .step-item{display:flex;align-items:flex-start;gap:1rem;padding:.75rem 0;position:relative;}
        .step-item:not(:last-child)::after{content:'';position:absolute;left:19px;top:45px;bottom:0;width:2px;background:#e2e8f0;}
        .step-icon{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;z-index:1;}
        .step-pending .step-icon{background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0;}
        .step-approved .step-icon{background:#d1fae5;color:#059669;border:2px solid #059669;}
        .step-rejected .step-icon{background:#fee2e2;color:#dc2626;border:2px solid #dc2626;}
        .step-referred .step-icon{background:#dbeafe;color:#2563eb;border:2px solid #2563eb;}
        .step-current .step-icon{background:#059669;color:#fff;border:2px solid #059669;animation:pulse 2s infinite;}
        .step-content h6{margin-bottom:2px;font-weight:600;}
        .step-content .step-desc{font-size:.8rem;color:#64748b;}
        .step-content .step-meta{font-size:.75rem;color:#94a3b8;margin-top:2px;}
        @keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(5,150,105,.4)}50%{box-shadow:0 0 0 8px rgba(5,150,105,0)}}
        .classification-badge{font-size:1.5rem;font-weight:700;padding:8px 24px;border-radius:50px;}
    </style>
</head>
<body>
<div class="top-nav">
    <a href="../dashboard.php" class="brand"><i class="bi bi-mortarboard-fill me-1"></i>Graduation Portal</a>
    <div class="d-flex align-items-center gap-3">
        <span class="small text-muted"><?= htmlspecialchars($user['display_name'] ?? $user['username']) ?></span>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</div>

<div class="container py-4" style="max-width:900px;">
    <?php if (!$app): ?>
    <div class="text-center py-5">
        <i class="bi bi-hourglass-split fs-1 text-muted d-block mb-3"></i>
        <h4>No Graduation Application Found</h4>
        <p class="text-muted">Your application may still be pending admin approval. Please check back later.</p>
    </div>
    <?php else: ?>

    <!-- Hero -->
    <div class="hero">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h4 class="mb-1"><i class="bi bi-mortarboard-fill me-2"></i>Graduation Clearance</h4>
                <p class="mb-0 opacity-75">Application #<?= $app['application_id'] ?> — <?= ucfirst($app['application_type']) ?></p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark px-3 py-2 fs-6"><?= ucfirst(str_replace('_', ' ', $app['status'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Student Info -->
    <div class="info-card">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-muted">Full Name</div>
                <div class="fw-600"><?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Student ID</div>
                <div class="fw-600"><?= htmlspecialchars($app['student_id_number'] ?? 'N/A') ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Campus</div>
                <div class="fw-600"><?= htmlspecialchars($app['campus'] ?? 'N/A') ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Program</div>
                <div class="fw-600"><?= htmlspecialchars($app['program'] ?? 'N/A') ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Year of Entry</div>
                <div class="fw-600"><?= $app['year_of_entry'] ?? 'N/A' ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Year of Completion</div>
                <div class="fw-600"><?= $app['year_of_completion'] ?? 'N/A' ?></div>
            </div>
        </div>
    </div>

    <!-- Grade Summary if available -->
    <?php if ($grade_summary): ?>
    <div class="info-card text-center">
        <div class="mb-2 text-muted small text-uppercase">Overall Result</div>
        <?php
        $class_colors = ['Distinction'=>'success','Merit'=>'primary','Credit'=>'info','Pass'=>'warning','Fail'=>'danger'];
        $cls = $grade_summary['classification'] ?? 'N/A';
        $clr = $class_colors[$cls] ?? 'secondary';
        ?>
        <span class="classification-badge badge bg-<?= $clr ?>"><?= htmlspecialchars($cls) ?></span>
        <div class="mt-2 text-muted">GPA: <strong><?= number_format($grade_summary['gpa'] ?? 0, 2) ?></strong> | Credits: <?= $grade_summary['total_credits'] ?? 'N/A' ?></div>
    </div>
    <?php endif; ?>

    <!-- Clearance Step Tracker -->
    <div class="info-card">
        <h5 class="mb-3"><i class="bi bi-list-check me-2 text-success"></i>Clearance Progress</h5>
        <div class="step-tracker">
            <?php foreach ($all_steps as $sn => $info):
                $step = $steps[$sn] ?? null;
                $status = $step ? $step['status'] : 'pending';
                $is_current = ($app['current_step'] === $sn && !in_array($app['status'], ['completed', 'rejected']));
                $css_class = $is_current ? 'step-current' : 'step-' . $status;
            ?>
            <div class="step-item <?= $css_class ?>">
                <div class="step-icon">
                    <?php if ($status === 'approved'): ?><i class="bi bi-check-lg"></i>
                    <?php elseif ($status === 'rejected'): ?><i class="bi bi-x-lg"></i>
                    <?php elseif ($status === 'referred'): ?><i class="bi bi-arrow-return-right"></i>
                    <?php elseif ($is_current): ?><i class="bi <?= $info[1] ?>"></i>
                    <?php else: ?><i class="bi <?= $info[1] ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="step-content">
                    <h6><?= $info[0] ?> <?php if ($status === 'approved'): ?><span class="badge bg-success ms-1">Cleared</span><?php elseif ($status === 'rejected'): ?><span class="badge bg-danger ms-1">Rejected</span><?php elseif ($status === 'referred'): ?><span class="badge bg-warning ms-1">Referred</span><?php elseif ($is_current): ?><span class="badge bg-primary ms-1">In Progress</span><?php endif; ?></h6>
                    <div class="step-desc"><?= $info[3] ?></div>
                    <?php if ($step && $step['actioned_at']): ?>
                    <div class="step-meta">
                        <?= $step['officer_name'] ? 'By: ' . htmlspecialchars($step['officer_name']) . ' — ' : '' ?>
                        <?= date('M d, Y H:i', strtotime($step['actioned_at'])) ?>
                        <?php if ($step['notes']): ?><br><em><?= htmlspecialchars($step['notes']) ?></em><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Certificate Download -->
    <?php if ($app['status'] === 'completed'): ?>
    <div class="info-card text-center bg-success bg-opacity-10 border-success">
        <i class="bi bi-award-fill text-success fs-1 d-block mb-2"></i>
        <h5 class="text-success">Clearance Complete!</h5>
        <p class="text-muted mb-3">Your graduation clearance has been processed successfully.</p>
        <a href="../api/generate_clearance_certificate.php?app_id=<?= $app['application_id'] ?>" class="btn btn-success"><i class="bi bi-download me-1"></i>Download Clearance Certificate</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
