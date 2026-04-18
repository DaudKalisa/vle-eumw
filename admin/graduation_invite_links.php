<?php
/**
 * Admin – Graduation Clearance Invite Links
 * Create & manage invite links for graduating students
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Ensure column exists
$chk = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'is_graduation_student'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN is_graduation_student TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
}

// Departments
$departments = [];
$dr = $conn->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name");
if ($dr) while ($d = $dr->fetch_assoc()) $departments[] = $d;

// Programs
$programs = [];
$pr = $conn->query("SELECT program_id, program_name FROM programs WHERE is_active=1 ORDER BY program_name");
if ($pr) while ($p = $pr->fetch_assoc()) $programs[] = $p;

// ── Actions ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_invite'])) {
        $email        = trim($_POST['invite_email'] ?? '');
        $full_name    = trim($_POST['invite_name'] ?? '');
        $department_id= !empty($_POST['invite_department']) ? (int)$_POST['invite_department'] : null;
        $program      = trim($_POST['invite_program'] ?? '');
        $campus       = trim($_POST['invite_campus'] ?? 'Blantyre Campus');
        $max_uses     = (int)($_POST['invite_max_uses'] ?? 1);
        $expires_days = (int)($_POST['invite_expires_days'] ?? 60);
        $notes        = trim($_POST['invite_notes'] ?? '');
        $send_email   = isset($_POST['send_email']);

        $token      = bin2hex(random_bytes(32));
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;
        $created_by = $user['user_id'];

        $bind_email   = $email ?: null;
        $bind_name    = $full_name ?: null;
        $bind_program = $program ?: null;
        $bind_notes   = $notes ?: null;

        $stmt = $conn->prepare("INSERT INTO student_registration_invites 
            (token, email, full_name, department_id, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes, is_graduation_student)
            VALUES (?, ?, ?, ?, ?, ?, 'degree', 4, 'One', 'NE', ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssississs",
            $token, $bind_email, $bind_name, $department_id,
            $bind_program, $campus, $max_uses, $expires_at, $created_by, $bind_notes);

        if ($stmt->execute()) {
            $invite_url = getGradInviteUrl($token);
            $success    = "Graduation invite link created!<br><code class='text-break'>$invite_url</code>";

            if ($send_email && !empty($email) && function_exists('isEmailEnabled') && isEmailEnabled()) {
                $body = "<p>Dear " . htmlspecialchars($full_name ?: 'Student') . ",</p>
                    <p>You have been invited to apply for <strong>Graduation Clearance</strong> at Exploits University of Malawi.</p>
                    <p><a href=\"$invite_url\">Click here to register and apply</a></p>
                    <p>This link expires in $expires_days days.</p>";
                sendEmail($email, $full_name ?: 'Student', 'Graduation Clearance Invitation – EUMW VLE', $body);
                $success .= '<br>Email sent.';
            }
        } else {
            $error = 'Failed: ' . $conn->error;
        }
    }

    // Bulk create
    if (isset($_POST['create_bulk'])) {
        $campus   = trim($_POST['bulk_campus'] ?? 'Blantyre Campus');
        $program  = trim($_POST['bulk_program'] ?? '');
        $max_uses = max(1, (int)($_POST['bulk_max_uses'] ?? 50));
        $expires  = (int)($_POST['bulk_expires_days'] ?? 60);
        $notes    = trim($_POST['bulk_notes'] ?? '');

        $token      = bin2hex(random_bytes(32));
        $expires_at = $expires > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires} days")) : null;
        $created_by = $user['user_id'];
        $bind_prog  = $program ?: null;
        $bind_notes = $notes ?: null;

        $stmt = $conn->prepare("INSERT INTO student_registration_invites 
            (token, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes, is_graduation_student)
            VALUES (?, ?, ?, 'degree', 4, 'One', 'NE', ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssisis",
            $token, $bind_prog, $campus, $max_uses, $expires_at, $created_by, $bind_notes);
        if ($stmt->execute()) {
            $url = getGradInviteUrl($token);
            $success = "Bulk graduation link created (max $max_uses uses):<br><code class='text-break'>$url</code>";
        } else {
            $error = 'Failed: ' . $conn->error;
        }
    }

    // Deactivate
    if (isset($_POST['deactivate_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("UPDATE student_registration_invites SET is_active=0 WHERE invite_id=$id");
        $success = 'Link deactivated.';
    }
    // Activate
    if (isset($_POST['activate_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("UPDATE student_registration_invites SET is_active=1 WHERE invite_id=$id");
        $success = 'Link re-activated.';
    }
    // Delete
    if (isset($_POST['delete_invite'])) {
        $id = (int)$_POST['invite_id'];
        $conn->query("DELETE FROM student_registration_invites WHERE invite_id=$id AND is_graduation_student=1");
        $success = 'Link deleted.';
    }
}

function getGradInviteUrl($token) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script   = $_SERVER['SCRIPT_NAME'] ?? '';
    $base     = preg_replace('#/admin/.*$#', '', $script);
    return $protocol . '://' . $host . $base . '/register_graduation.php?token=' . $token;
}

// Fetch graduation links
$links = [];
$lr = $conn->query("SELECT i.*, 
        (SELECT full_name FROM users WHERE user_id = i.created_by LIMIT 1) as creator_name
    FROM student_registration_invites i 
    WHERE i.is_graduation_student = 1
    ORDER BY i.created_at DESC");
if ($lr) while ($l = $lr->fetch_assoc()) $links[] = $l;

$page_title = 'Graduation Invite Links';
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
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .top-bar .brand{font-weight:700;font-size:1.1rem;color:#4f46e5;text-decoration:none;display:flex;align-items:center;gap:.5rem;}
        .page-header{background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .card-custom{background:#fff;border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.06);}
        .link-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:.75rem;transition:box-shadow .2s;}
        .link-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.10);}
        .link-card.active{border-left:4px solid #10b981;}
        .link-card.inactive{border-left:4px solid #ef4444;opacity:.7;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" class="brand"><i class="bi bi-arrow-left"></i> Admin Dashboard</a>
    <div><a href="graduation_students.php" class="btn btn-sm btn-outline-primary me-2"><i class="bi bi-mortarboard me-1"></i>Graduation Students</a></div>
</div>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1200px;">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h4 class="mb-1"><i class="bi bi-link-45deg me-2"></i><?= $page_title ?></h4>
            <p class="mb-0 opacity-75">Create invite links for graduating students to register and apply for clearance</p>
        </div>
        <span class="badge bg-white text-success fs-6"><?= count($links) ?> links</span>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Create Individual Link -->
    <div class="card-custom mb-4">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create Individual Link</h5></div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Student Email <small class="text-muted">(optional)</small></label>
                        <input type="email" name="invite_email" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Full Name <small class="text-muted">(optional)</small></label>
                        <input type="text" name="invite_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Campus</label>
                        <select name="invite_campus" class="form-select">
                            <option value="Blantyre Campus">Blantyre Campus</option>
                            <option value="Lilongwe Campus">Lilongwe Campus</option>
                            <option value="Mzuzu Campus">Mzuzu Campus</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Program</label>
                        <select name="invite_program" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach ($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p['program_name']) ?>"><?= htmlspecialchars($p['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="invite_department" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max Uses</label>
                        <input type="number" name="invite_max_uses" class="form-control" value="1" min="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Expires (days)</label>
                        <input type="number" name="invite_expires_days" class="form-control" value="60" min="1">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <input type="text" name="invite_notes" class="form-control">
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <div class="form-check">
                            <input type="checkbox" name="send_email" class="form-check-input" id="sendEmail">
                            <label class="form-check-label" for="sendEmail">Send email</label>
                        </div>
                        <button type="submit" name="create_invite" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Create</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Link -->
    <div class="card-custom mb-4">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-collection me-2"></i>Create Bulk Link (Multiple Uses)</h5></div>
        <div class="card-body">
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Campus</label>
                        <select name="bulk_campus" class="form-select">
                            <option value="Blantyre Campus">Blantyre Campus</option>
                            <option value="Lilongwe Campus">Lilongwe Campus</option>
                            <option value="Mzuzu Campus">Mzuzu Campus</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="bulk_program" class="form-select">
                            <option value="">-- All --</option>
                            <?php foreach ($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p['program_name']) ?>"><?= htmlspecialchars($p['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label">Max Uses</label><input type="number" name="bulk_max_uses" class="form-control" value="100" min="1"></div>
                    <div class="col-md-2"><label class="form-label">Expires (days)</label><input type="number" name="bulk_expires_days" class="form-control" value="60" min="1"></div>
                    <div class="col-md-2 d-flex align-items-end"><button type="submit" name="create_bulk" class="btn btn-primary w-100"><i class="bi bi-link-45deg me-1"></i>Create Bulk</button></div>
                    <div class="col-12"><input type="text" name="bulk_notes" class="form-control" placeholder="Notes (optional)"></div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Links -->
    <div class="card-custom">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Existing Graduation Links (<?= count($links) ?>)</h5></div>
        <div class="card-body p-2">
            <?php if (empty($links)): ?>
            <div class="text-center py-4 text-muted"><i class="bi bi-link-45deg fs-1 d-block mb-2"></i>No graduation links yet.</div>
            <?php else: ?>
            <?php foreach ($links as $lnk): 
                $active = $lnk['is_active'] && (!$lnk['expires_at'] || strtotime($lnk['expires_at']) > time());
                $url = getGradInviteUrl($lnk['token']);
            ?>
            <div class="link-card <?= $active ? 'active' : 'inactive' ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div style="flex:1;min-width:200px;">
                        <div class="fw-bold mb-1">
                            <?= $lnk['full_name'] ? htmlspecialchars($lnk['full_name']) : '<em>Open link</em>' ?>
                            <?php if ($lnk['campus']): ?><span class="badge bg-info text-dark ms-1"><?= htmlspecialchars($lnk['campus']) ?></span><?php endif; ?>
                            <?php if ($lnk['program']): ?><span class="badge bg-secondary ms-1"><?= htmlspecialchars($lnk['program']) ?></span><?php endif; ?>
                        </div>
                        <div class="small text-muted mb-1">
                            Uses: <strong><?= $lnk['times_used'] ?>/<?= $lnk['max_uses'] ?></strong> &nbsp;|&nbsp;
                            Created: <?= date('M d, Y', strtotime($lnk['created_at'])) ?>
                            <?php if($lnk['expires_at']): ?> &nbsp;|&nbsp; Expires: <?= date('M d, Y', strtotime($lnk['expires_at'])) ?><?php endif; ?>
                        </div>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($url) ?>" readonly onclick="this.select();document.execCommand('copy');">
                    </div>
                    <div class="d-flex gap-1">
                        <?php if($active): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="deactivate_invite" class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-fill"></i></button></form>
                        <?php else: ?>
                        <form method="post" class="d-inline"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="activate_invite" class="btn btn-sm btn-outline-success"><i class="bi bi-play-fill"></i></button></form>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this link?')"><input type="hidden" name="invite_id" value="<?= $lnk['invite_id'] ?>"><button name="delete_invite" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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
