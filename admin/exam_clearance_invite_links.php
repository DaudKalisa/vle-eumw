<?php
/**
 * Admin – Exam Clearance Invite Links
 * Create & manage invite links for external students needing exam clearance
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin', 'finance']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// ── Actions ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_invite'])) {
        $description  = trim($_POST['description'] ?? '');
        $max_uses     = (int)($_POST['max_uses'] ?? 0);
        $expires_days = (int)($_POST['expires_days'] ?? 60);

        $program_type = 'general';

        $token      = bin2hex(random_bytes(32));
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;
        $created_by = (int)$user['user_id'];
        $bind_desc  = $description ?: null;

        $stmt = $conn->prepare("INSERT INTO exam_clearance_invites (invite_token, program_type, description, max_uses, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssisi", $token, $program_type, $bind_desc, $max_uses, $expires_at, $created_by);

        if ($stmt->execute()) {
            $invite_url = getExamClearanceInviteUrl($token);
            $success = "Exam clearance invite link created!<br><code class='text-break'>$invite_url</code>";
        } else {
            $error = 'Failed to create invite: ' . $conn->error;
        }
    }

    // Deactivate
    if (isset($_POST['deactivate_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("UPDATE exam_clearance_invites SET is_active = 0 WHERE invite_id = $id");
        $success = 'Invite link deactivated.';
    }
    // Activate
    if (isset($_POST['activate_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("UPDATE exam_clearance_invites SET is_active = 1 WHERE invite_id = $id");
        $success = 'Invite link re-activated.';
    }
    // Delete
    if (isset($_POST['delete_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("DELETE FROM exam_clearance_invites WHERE invite_id = $id");
        $success = 'Invite link deleted.';
    }
}

function getExamClearanceInviteUrl($token) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script   = $_SERVER['SCRIPT_NAME'] ?? '';
    $base     = preg_replace('#/admin/.*$#', '', $script);
    return $protocol . '://' . $host . $base . '/exam_clearance_join.php?token=' . $token;
}

// Fetch existing links
$links = [];
$lr = $conn->query("SELECT eci.*, 
        (SELECT username FROM users WHERE user_id = eci.created_by LIMIT 1) as creator_name
    FROM exam_clearance_invites eci
    ORDER BY eci.created_at DESC");
if ($lr) while ($l = $lr->fetch_assoc()) $links[] = $l;

// Count external students registered
$ext_count = 0;
$ec_rs = $conn->query("SELECT COUNT(*) as cnt FROM exam_clearance_students WHERE is_system_student = 0");
if ($ec_rs) $ext_count = $ec_rs->fetch_assoc()['cnt'];

$page_title = 'Exam Clearance Invite Links';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> – VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .page-header{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .card-custom{background:#fff;border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.06);}
        .link-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:.75rem;transition:box-shadow .2s;}
        .link-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.10);}
        .link-card.active{border-left:4px solid #10b981;}
        .link-card.inactive{border-left:4px solid #ef4444;opacity:.7;}
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1200px;">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i><?= $page_title ?></h4>
            <p class="mb-0 opacity-75">Create invite links for external students to register and apply for exam clearance</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-white text-success fs-6"><?= count($links) ?> links</span>
            <span class="badge bg-white text-primary fs-6"><?= $ext_count ?> external students</span>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Create Invite Link -->
    <div class="card-custom mb-4">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create Exam Clearance Invite Link</h5></div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. 2024/2025 Semester 1 Exam Clearance">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Uses</label>
                        <input type="number" name="max_uses" class="form-control" value="0" min="0">
                        <small class="text-muted">0 = unlimited</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Expires (days)</label>
                        <input type="number" name="expires_days" class="form-control" value="60" min="1">
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" name="create_invite" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Create Invite Link</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Links -->
    <div class="card-custom">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Existing Invite Links (<?= count($links) ?>)</h5></div>
        <div class="card-body p-2">
            <?php if (empty($links)): ?>
            <div class="text-center py-4 text-muted"><i class="bi bi-link-45deg fs-1 d-block mb-2"></i>No exam clearance invite links yet.</div>
            <?php else: ?>
            <?php foreach ($links as $lnk): 
                $active = $lnk['is_active'] && (!$lnk['expires_at'] || strtotime($lnk['expires_at']) > time());
                $url = getExamClearanceInviteUrl($lnk['invite_token']);
            ?>
            <div class="link-card <?= $active ? 'active' : 'inactive' ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div style="flex:1;min-width:200px;">
                        <div class="fw-bold mb-1">
                            <?= $lnk['description'] ? htmlspecialchars($lnk['description']) : '<em>Exam Clearance Link</em>' ?>
                            <?php if ($active): ?>
                                <span class="badge bg-success ms-1">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-1">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mb-1">
                            Uses: <strong><?= $lnk['times_used'] ?>/<?= $lnk['max_uses'] ?: '&infin;' ?></strong> &nbsp;|&nbsp;
                            Created: <?= date('M d, Y', strtotime($lnk['created_at'])) ?>
                            <?php if ($lnk['creator_name']): ?> by <?= htmlspecialchars($lnk['creator_name']) ?><?php endif; ?>
                            <?php if ($lnk['expires_at']): ?> &nbsp;|&nbsp; Expires: <?= date('M d, Y', strtotime($lnk['expires_at'])) ?><?php endif; ?>
                        </div>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($url) ?>" readonly onclick="this.select();document.execCommand('copy');">
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($active): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="deactivate_invite" class="btn btn-sm btn-outline-warning" title="Deactivate"><i class="bi bi-pause-fill"></i></button></form>
                        <?php else: ?>
                        <form method="post" class="d-inline"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="activate_invite" class="btn btn-sm btn-outline-success" title="Activate"><i class="bi bi-play-fill"></i></button></form>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this invite link?')"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="delete_invite" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
