<?php
/**
 * Research Coordinator - Student & Supervisor Invite Links
 * Create single/bulk dissertation invite links, track usage
 */
session_start();
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();

$message = '';
$error = '';
$bulk_invite_url = '';
$bulk_invite_label = '';
$bulk_invite_max_uses = 0;

// Ensure dissertation invite flag exists on invite links
$invite_col = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'dissertation_only'");
if ($invite_col && $invite_col->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN dissertation_only TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
}

// Ensure is_supervisor column exists for bulk invite type
$supervisor_col = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'is_supervisor'");
if ($supervisor_col && $supervisor_col->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER dissertation_only");
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_bulk_dissertation_invite') {
        $bulk_label = trim($_POST['bulk_label'] ?? '');
        $bulk_max_uses = (int)($_POST['bulk_max_uses'] ?? 300);
        $bulk_type = ($_POST['bulk_type'] ?? 'student') === 'supervisor' ? 'supervisor' : 'student';
        if ($bulk_max_uses < 2) $bulk_max_uses = 2;
        if ($bulk_max_uses > 300) $bulk_max_uses = 300;
        if (empty($bulk_label)) {
            $error = 'Please provide a label/description for the bulk invite.';
        } else {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+60 days'));
            $created_by = $_SESSION['vle_user_id'] ?? 0;
            $notes = 'Bulk dissertation invite: ' . $bulk_label . ' | type: ' . $bulk_type;
            $is_supervisor = ($bulk_type === 'supervisor') ? 1 : 0;
            $stmt = $conn->prepare("INSERT INTO student_registration_invites
                (token, email, full_name, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes, dissertation_only, is_supervisor)
                VALUES (?, '', ?, '', 'Mzuzu Campus', 'degree', 3, 'One', 'NE', ?, ?, ?, ?, 1, ?)");
            $stmt->bind_param("ssissis", $token, $bulk_label, $bulk_max_uses, $expires_at, $created_by, $notes, $is_supervisor);
            if ($stmt->execute()) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $script = $_SERVER['SCRIPT_NAME'] ?? '/vle-eumw/research_coordinator/student_link.php';
                $base = preg_replace('#/research_coordinator/.*$#', '', $script);
                $invite_url = $protocol . '://' . $host . $base . '/register_student.php?token=' . $token;
                $message = ucfirst($bulk_type) . ' bulk invite link created successfully (Allowed uses: ' . $bulk_max_uses . ')';
                $bulk_invite_url = $invite_url;
                $bulk_invite_label = $bulk_label;
                $bulk_invite_max_uses = $bulk_max_uses;
            } else {
                $error = 'Failed to create bulk invite link.';
            }
        }
    } elseif ($action === 'create_dissertation_student') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $student_id = trim($_POST['student_id'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $year_of_study = (int)($_POST['year_of_study'] ?? 1);
        $send_email = isset($_POST['send_email']);

        if (empty($full_name) || empty($email)) {
            $error = 'Please provide both full name and email for the dissertation student.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();

            if ($exists) {
                $error = 'A user with that email already exists. Please use a different email.';
            }

            if (empty($error)) {
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
                $notes = 'Dissertation-only invite' . (!empty($student_id) ? ' | preferred student id: ' . $student_id : '');
                $created_by = $_SESSION['vle_user_id'] ?? 0;

                $stmt = $conn->prepare("INSERT INTO student_registration_invites
                    (token, email, full_name, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes, dissertation_only)
                    VALUES (?, ?, ?, ?, 'Mzuzu Campus', 'degree', ?, 'One', 'NE', 1, ?, ?, ?, 1)");
                $stmt->bind_param("ssssisis", $token, $email, $full_name, $program, $year_of_study, $expires_at, $created_by, $notes);

                if ($stmt->execute()) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $script = $_SERVER['SCRIPT_NAME'] ?? '/vle-eumw/research_coordinator/student_link.php';
                    $base = preg_replace('#/research_coordinator/.*$#', '', $script);
                    $invite_url = $protocol . '://' . $host . $base . '/register_student.php?token=' . $token;

                    $message = 'Dissertation invite link created successfully: ' . $invite_url;

                    if ($send_email && function_exists('isEmailEnabled') && isEmailEnabled()) {
                        $subject = 'Dissertation Portal Registration Invitation';
                        $body = "<p>Dear " . htmlspecialchars($full_name) . ",</p>" .
                                "<p>You have been invited to register for dissertation portal access.</p>" .
                                "<p><a href=\"" . htmlspecialchars($invite_url) . "\">Complete Registration</a></p>" .
                                "<p>This invitation expires in 30 days and can be used once.</p>";
                        if (!sendEmail($email, $full_name, $subject, $body)) {
                            $message .= ' (Email sending failed; share the invite link manually.)';
                        }
                    }
                } else {
                    $error = 'Failed to create dissertation invite link.';
                }
            }
        }
    }
}

// Get dissertation-only invites
$dissertation_invites = [];
$invite_stats = ['total' => 0, 'active' => 0, 'used' => 0, 'expired' => 0];

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = $_SERVER['SCRIPT_NAME'] ?? '/vle-eumw/research_coordinator/student_link.php';
$base = preg_replace('#/research_coordinator/.*$#', '', $script);
$invite_base_url = $protocol . '://' . $host . $base . '/register_student.php?token=';

$invite_stats_q = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses = 0 OR times_used < max_uses) THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN times_used > 0 THEN 1 ELSE 0 END) as used,
    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired
    FROM student_registration_invites
    WHERE dissertation_only = 1");
if ($invite_stats_q && $row = $invite_stats_q->fetch_assoc()) {
    $invite_stats = [
        'total' => (int)($row['total'] ?? 0),
        'active' => (int)($row['active'] ?? 0),
        'used' => (int)($row['used'] ?? 0),
        'expired' => (int)($row['expired'] ?? 0),
    ];
}

$invite_q = $conn->query("SELECT i.*, u.username as creator_name,
    (SELECT COUNT(*) FROM student_invite_registrations r WHERE r.invite_id = i.invite_id) as registration_count
    FROM student_registration_invites i
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE i.dissertation_only = 1
    ORDER BY i.created_at DESC
    LIMIT 100");
if ($invite_q) {
    while ($row = $invite_q->fetch_assoc()) {
        $dissertation_invites[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student & Supervisor Invite Links - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="bi bi-link-45deg me-2" style="color: #667eea;"></i>
                Student & Supervisor Invite Links
            </h2>
            <p class="text-muted mb-0">Create single or bulk invite links for dissertation students and supervisors.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
        </a>
    </div>

    <?php if ($message || !empty($bulk_invite_url)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?= htmlspecialchars($message) ?>
            <?php if (!empty($bulk_invite_url)): ?>
                <div class="mt-3 p-3 bg-white rounded" style="border: 1px solid #d4edda;">
                    <small class="d-block mb-2 text-muted"><strong>Invite Link:</strong></small>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <code style="background:#f8f9fa; padding:8px 12px; border-radius:4px; flex:1; min-width:300px; word-break:break-all;"><?= htmlspecialchars($bulk_invite_url) ?></code>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="copyBulkInviteLink('<?= htmlspecialchars($bulk_invite_url, ENT_QUOTES) ?>')" title="Copy to clipboard">
                            <i class="bi bi-clipboard"></i> Copy
                        </button>
                        <a href="<?= htmlspecialchars($bulk_invite_url) ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Open link">
                            <i class="bi bi-box-arrow-up-right"></i> Open
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Invite Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3">
                            <i class="bi bi-link-45deg"></i>
                        </div>
                        <div>
                            <div class="stat-value text-secondary"><?= (int)$invite_stats['total'] ?></div>
                            <small class="text-muted">Total Invites</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value text-success"><?= (int)$invite_stats['active'] ?></div>
                            <small class="text-muted">Active</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="bi bi-person-check"></i>
                        </div>
                        <div>
                            <div class="stat-value text-info"><?= (int)$invite_stats['used'] ?></div>
                            <small class="text-muted">Used</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="stat-value text-warning"><?= (int)$invite_stats['expired'] ?></div>
                            <small class="text-muted">Expired</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Create Single Invite -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Create Student Invite</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="create_dissertation_student">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Student ID (optional)</label>
                            <input type="text" name="student_id" class="form-control" placeholder="e.g. DISS/2026/1234">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Program (optional)</label>
                            <input type="text" name="program" class="form-control" placeholder="e.g. Master of Science">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Year of Study</label>
                            <select name="year_of_study" class="form-select">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3" selected>3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="send_email" id="send_email" checked>
                                <label class="form-check-label" for="send_email">Email invite to student</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="bi bi-link-45deg me-1"></i>Create Dissertation Invite
                            </button>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Creates a one-time invite for registration as a dissertation-only student.</small>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Create Bulk Invite -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>Create Bulk Invite Link</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="create_bulk_dissertation_invite">
                        <div class="col-12">
                            <label class="form-label">Description / Label</label>
                            <input type="text" name="bulk_label" class="form-control" placeholder="e.g. 2026 MSc Cohort" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Allowed Uses (max 300)</label>
                            <input type="number" name="bulk_max_uses" class="form-control" min="2" max="300" value="300" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invite Type</label>
                            <select name="bulk_type" class="form-select" required>
                                <option value="student">Student</option>
                                <option value="supervisor">Supervisor</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success w-100" type="submit">
                                <i class="bi bi-link-45deg me-1"></i>Create Bulk Invite Link
                            </button>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Creates a single invite link usable by up to 300 students <b>or</b> supervisors. Track usage below.</small>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Invites Table -->
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-table me-2"></i>Dissertation Invites</h5>
            <div class="d-flex gap-2">
                <span class="badge bg-secondary">Total: <?= (int)$invite_stats['total'] ?></span>
                <span class="badge bg-success">Active: <?= (int)$invite_stats['active'] ?></span>
                <span class="badge bg-info text-dark">Used: <?= (int)$invite_stats['used'] ?></span>
                <span class="badge bg-warning text-dark">Expired: <?= (int)$invite_stats['expired'] ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Usage</th>
                            <th>Expires</th>
                            <th>Created</th>
                            <th>Invite Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dissertation_invites)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No dissertation invites found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dissertation_invites as $ii => $inv): ?>
                                <?php
                                    $is_expired = !empty($inv['expires_at']) && strtotime($inv['expires_at']) <= time();
                                    $is_used_up = ((int)($inv['max_uses'] ?? 0) > 0) && ((int)($inv['times_used'] ?? 0) >= (int)($inv['max_uses'] ?? 0));
                                    $is_active_inv = (int)($inv['is_active'] ?? 0) === 1;

                                    if (!$is_active_inv) {
                                        $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                    } elseif ($is_expired) {
                                        $status_badge = '<span class="badge bg-warning text-dark">Expired</span>';
                                    } elseif ($is_used_up) {
                                        $status_badge = '<span class="badge bg-info text-dark">Used Up</span>';
                                    } else {
                                        $status_badge = '<span class="badge bg-success">Active</span>';
                                    }

                                    $full_invite_url = $invite_base_url . $inv['token'];
                                    $inv_type = !empty($inv['is_supervisor']) ? 'Supervisor' : 'Student';
                                ?>
                                <tr>
                                    <td><?= $ii + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($inv['full_name'] ?: 'Unnamed') ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($inv['email'] ?: '-') ?></small>
                                    </td>
                                    <td><span class="badge bg-<?= $inv_type === 'Supervisor' ? 'purple' : 'primary' ?> bg-opacity-75"><?= $inv_type ?></span></td>
                                    <td><?= $status_badge ?></td>
                                    <td>
                                        <small><?= (int)($inv['times_used'] ?? 0) ?>/<?= ((int)($inv['max_uses'] ?? 0) === 0 ? 'Unlimited' : (int)$inv['max_uses']) ?></small>
                                        <?php if ((int)($inv['registration_count'] ?? 0) > 0): ?>
                                            <br><small class="text-success">Registrations: <?= (int)$inv['registration_count'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= !empty($inv['expires_at']) ? date('M j, Y', strtotime($inv['expires_at'])) : 'Never' ?></small></td>
                                    <td><small><?= !empty($inv['created_at']) ? date('M j, Y', strtotime($inv['created_at'])) : '-' ?></small></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="<?= htmlspecialchars($full_invite_url) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Open invite link">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Copy invite link" onclick="copyInviteLink('<?= htmlspecialchars($full_invite_url, ENT_QUOTES) ?>')">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyInviteLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            alert('Invite link copied to clipboard.');
        }).catch(function() {
            alert('Failed to copy link. Please copy it manually.');
        });
    } else {
        prompt('Copy invite link:', url);
    }
}

function copyBulkInviteLink(url) {
    const btn = event.currentTarget;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Copied!';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
            setTimeout(function() {
                btn.innerHTML = originalHTML;
                btn.classList.add('btn-outline-success');
                btn.classList.remove('btn-success');
            }, 2000);
        }).catch(function(err) {
            const codeBlock = document.querySelector('code');
            if (codeBlock) {
                const range = document.createRange();
                range.selectNodeContents(codeBlock);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                try {
                    document.execCommand('copy');
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> Copied!';
                    btn.classList.remove('btn-outline-success');
                    btn.classList.add('btn-success');
                    setTimeout(function() {
                        btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                        btn.classList.add('btn-outline-success');
                        btn.classList.remove('btn-success');
                    }, 2000);
                } catch (e) {
                    alert('Failed to copy link. Please copy it manually.');
                }
            }
        });
    } else {
        const codeBlock = document.querySelector('code');
        if (codeBlock) {
            const range = document.createRange();
            range.selectNodeContents(codeBlock);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            try {
                document.execCommand('copy');
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Copied!';
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-success');
                setTimeout(function() {
                    btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                    btn.classList.add('btn-outline-success');
                    btn.classList.remove('btn-success');
                }, 2000);
            } catch (e) {
                prompt('Copy invite link:', url);
            }
        }
    }
}
</script>
</body>
</html>
