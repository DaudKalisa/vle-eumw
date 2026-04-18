<?php
// reset_passwords.php - Admin: Manage & auto-reset password requests
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Ensure the table exists with all columns
$conn->query("CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending',
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    notes TEXT NULL,
    temp_password VARCHAR(100) NULL,
    auto_reset TINYINT(1) DEFAULT 0
)");
$existing_cols = [];
$cr = $conn->query("SHOW COLUMNS FROM password_reset_requests");
if ($cr) while ($c = $cr->fetch_assoc()) $existing_cols[] = $c['Field'];
if (!in_array('resolved_at', $existing_cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN resolved_at DATETIME NULL");
if (!in_array('resolved_by', $existing_cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN resolved_by INT NULL");
if (!in_array('notes', $existing_cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN notes TEXT NULL");
if (!in_array('temp_password', $existing_cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN temp_password VARCHAR(100) NULL");
if (!in_array('auto_reset', $existing_cols)) $conn->query("ALTER TABLE password_reset_requests ADD COLUMN auto_reset TINYINT(1) DEFAULT 0");

$success = '';
$error = '';
$admin_id = (int)$_SESSION['vle_user_id'];

// Helper: generate temp password and reset a user
function autoResetUser($conn, $user_id, $username, $email, $admin_id, $req_id = null) {
    $temp_password = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 8);
    $hash = password_hash($temp_password, PASSWORD_DEFAULT);

    $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
    $upd->bind_param("si", $hash, $user_id);
    if (!$upd->execute()) {
        $upd->close();
        return ['ok' => false, 'error' => 'Failed to update password'];
    }
    $upd->close();

    // Mark request resolved
    if ($req_id) {
        $notes = 'Auto-reset by admin on ' . date('Y-m-d H:i:s');
        $mr = $conn->prepare("UPDATE password_reset_requests SET status = 'resolved', resolved_at = NOW(), resolved_by = ?, notes = ?, temp_password = ?, auto_reset = 1 WHERE id = ?");
        $mr->bind_param("issi", $admin_id, $notes, $temp_password, $req_id);
        $mr->execute();
        $mr->close();
    }

    // Send email
    $change_url = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/change_password.php';
    $email_body = "
        <h2 style='color:#4f46e5;'>Password Reset</h2>
        <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
        <p>Your password has been reset by the administrator. Use the temporary password below to log in:</p>
        <div style='background:#f0f4f8;border-radius:8px;padding:16px;margin:16px 0;text-align:center;'>
            <p style='margin:0 0 4px;color:#64748b;font-size:13px;'>Your temporary password:</p>
            <p style='margin:0;font-size:24px;font-weight:700;color:#1e293b;letter-spacing:2px;font-family:monospace;'>" . htmlspecialchars($temp_password) . "</p>
        </div>
        <p><strong>You must change your password immediately after logging in.</strong></p>
        <p><a href='" . htmlspecialchars($change_url) . "' style='display:inline-block;background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;'>Log In &amp; Change Password</a></p>
        <p style='color:#94a3b8;font-size:12px;margin-top:20px;'>If you did not request this reset, please contact the administrator.</p>
    ";
    $sent = sendEmail($email, $username, 'Password Reset - VLE', $email_body);

    return ['ok' => true, 'temp' => $temp_password, 'emailed' => $sent];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Single auto-reset
    if ($action === 'auto_reset') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        if ($req_id <= 0) {
            $error = 'Invalid request.';
        } else {
            $rs = $conn->prepare("SELECT user_id, username, email FROM password_reset_requests WHERE id = ? AND status = 'pending'");
            $rs->bind_param("i", $req_id);
            $rs->execute();
            $req = $rs->get_result()->fetch_assoc();
            $rs->close();
            if (!$req) {
                $error = 'Request not found or already resolved.';
            } else {
                $result = autoResetUser($conn, $req['user_id'], $req['username'], $req['email'], $admin_id, $req_id);
                if ($result['ok']) {
                    $success = "Password reset for <strong>" . htmlspecialchars($req['username']) . "</strong>. Temp password: <code>" . htmlspecialchars($result['temp']) . "</code>" . ($result['emailed'] ? ' (email sent)' : ' <span class="text-warning">(email failed - share manually)</span>');
                } else {
                    $error = $result['error'];
                }
            }
        }
    }

    // Bulk auto-reset (select all pending)
    if ($action === 'bulk_auto_reset') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $error = 'No requests selected.';
        } else {
            $reset_count = 0;
            $fail_count = 0;
            $results_detail = [];
            foreach ($ids as $rid) {
                $rid = (int)$rid;
                if ($rid <= 0) continue;
                $rs = $conn->prepare("SELECT user_id, username, email FROM password_reset_requests WHERE id = ? AND status = 'pending'");
                $rs->bind_param("i", $rid);
                $rs->execute();
                $req = $rs->get_result()->fetch_assoc();
                $rs->close();
                if (!$req) { $fail_count++; continue; }

                $result = autoResetUser($conn, $req['user_id'], $req['username'], $req['email'], $admin_id, $rid);
                if ($result['ok']) {
                    $reset_count++;
                    $results_detail[] = htmlspecialchars($req['username']) . ': <code>' . htmlspecialchars($result['temp']) . '</code>' . ($result['emailed'] ? ' (emailed)' : ' <span class="text-warning">(email failed)</span>');
                } else {
                    $fail_count++;
                }
            }
            if ($reset_count > 0) {
                $success = "<strong>{$reset_count}</strong> password(s) auto-reset successfully." . ($fail_count > 0 ? " {$fail_count} failed." : '') . '<br><details class="mt-2"><summary class="fw-bold" style="cursor:pointer;">View temporary passwords</summary><div class="mt-2 small">' . implode('<br>', $results_detail) . '</div></details>';
            }
            if ($reset_count === 0 && $fail_count > 0) {
                $error = "All {$fail_count} selected requests failed or were already resolved.";
            }
        }
    }

    // Manual password reset
    if ($action === 'reset_password') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');
        if (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $rs = $conn->prepare("SELECT user_id, username, email FROM password_reset_requests WHERE id = ?");
            $rs->bind_param("i", $req_id);
            $rs->execute();
            $req = $rs->get_result()->fetch_assoc();
            $rs->close();
            if ($req) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
                $up->bind_param("si", $hash, $req['user_id']);
                if ($up->execute()) {
                    $notes = 'Manual reset by admin on ' . date('Y-m-d H:i:s');
                    $mr = $conn->prepare("UPDATE password_reset_requests SET status = 'resolved', resolved_at = NOW(), resolved_by = ?, notes = ? WHERE id = ?");
                    $mr->bind_param("isi", $admin_id, $notes, $req_id);
                    $mr->execute();
                    $success = "Password for <strong>" . htmlspecialchars($req['username']) . "</strong> has been reset.";
                } else {
                    $error = 'Failed to update password.';
                }
            } else {
                $error = 'Request not found.';
            }
        }
    }

    // Reject
    if ($action === 'reject') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? 'Rejected by admin.');
        $mr = $conn->prepare("UPDATE password_reset_requests SET status = 'rejected', resolved_at = NOW(), resolved_by = ?, notes = ? WHERE id = ?");
        $mr->bind_param("isi", $admin_id, $notes, $req_id);
        $mr->execute();
        $success = 'Request rejected.';
    }

    // Delete
    if ($action === 'delete') {
        $req_id = (int)($_POST['req_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM password_reset_requests WHERE id = ?");
        $stmt->bind_param("i", $req_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Request deleted.';
    }
}

// Filter
$filter = $_GET['filter'] ?? 'pending';
$allowed_filters = ['pending', 'resolved', 'rejected', 'all'];
if (!in_array($filter, $allowed_filters)) $filter = 'pending';

$where = $filter === 'all' ? '' : "WHERE r.status = '" . $conn->real_escape_string($filter) . "'";

$requests = [];
$res = $conn->query("
    SELECT r.*,
           u.is_active AS user_active,
           u.last_login,
           CASE r.role
               WHEN 'student' THEN (SELECT full_name FROM students WHERE student_id = u.related_student_id LIMIT 1)
               ELSE NULL
           END AS full_name_lookup
    FROM password_reset_requests r
    LEFT JOIN users u ON r.user_id = u.user_id
    $where
    ORDER BY r.requested_at DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) $requests[] = $row;
}

// Counts for tabs
$counts = [];
foreach (['pending','resolved','rejected','all'] as $s) {
    $w = $s === 'all' ? '' : "WHERE status = '" . $conn->real_escape_string($s) . "'";
    $c = $conn->query("SELECT COUNT(*) as n FROM password_reset_requests $w");
    $counts[$s] = $c ? (int)$c->fetch_assoc()['n'] : 0;
}

$pending_requests = array_filter($requests, fn($r) => ($r['status'] ?? '') === 'pending');
$user_obj = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Requests – VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .req-card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:1.25rem; margin-bottom:.75rem; transition:box-shadow .2s; }
        .req-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.10); }
        .req-card.status-pending { border-left:4px solid #f59e0b; }
        .req-card.status-resolved { border-left:4px solid #10b981; }
        .req-card.status-rejected { border-left:4px solid #ef4444; opacity:.75; }
        .role-badge { font-size:.75rem; padding:.25rem .65rem; border-radius:50px; font-weight:600; }
        .role-student { background:#dbeafe; color:#1d4ed8; }
        .role-lecturer { background:#d1fae5; color:#065f46; }
        .role-staff, .role-admin { background:#ede9fe; color:#5b21b6; }
        .role-other { background:#f3f4f6; color:#374151; }
        .nav-tabs .nav-link { font-weight:500; border-radius:8px 8px 0 0; }
        .nav-tabs .nav-link.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }
        .empty-state { text-align:center; padding:3rem 1rem; color:#94a3b8; }
        .empty-state i { font-size:3rem; display:block; margin-bottom:1rem; }
        .bulk-bar { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 20px; margin-bottom:16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .select-check { width:18px; height:18px; cursor:pointer; accent-color:#4f46e5; }
    </style>
</head>
<body>
<?php
$breadcrumbs = [['title' => 'Password Reset Requests']];
include 'header_nav.php';
?>

<div class="vle-content">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-key-fill me-2" style="color:#ef4444;"></i>Password Reset Requests</h4>
            <p class="text-muted mb-0" style="font-size:.85rem;">Auto-reset passwords and send temporary credentials via email</p>
        </div>
        <?php if ($counts['pending'] > 0): ?>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2"><?= $counts['pending'] ?> Pending</span>
        <?php endif; ?>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
        <i class="bi bi-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
        <i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <ul class="nav nav-tabs mb-4">
        <?php foreach (['pending' => 'warning', 'resolved' => 'success', 'rejected' => 'danger', 'all' => 'secondary'] as $tab => $color): ?>
        <li class="nav-item">
            <a class="nav-link <?= $filter === $tab ? 'active' : '' ?>" href="?filter=<?= $tab ?>">
                <?= ucfirst($tab) ?>
                <span class="badge bg-<?= $color ?> ms-1 <?= $filter === $tab ? 'bg-white text-dark' : '' ?>"><?= $counts[$tab] ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if (empty($requests)): ?>
    <div class="empty-state">
        <i class="bi bi-inbox"></i>
        No <?= $filter === 'all' ? '' : $filter ?> password reset requests.
    </div>
    <?php else: ?>

    <!-- Bulk Action Bar (only for pending) -->
    <?php if ($filter === 'pending' && count($pending_requests) > 0): ?>
        <div class="bulk-bar">
            <input type="checkbox" class="select-check" id="selectAll" title="Select All">
            <label for="selectAll" class="fw-bold mb-0" style="cursor:pointer;">Select All</label>
            <span class="text-muted small">(<span id="selectedCount">0</span> of <?= count($pending_requests) ?> selected)</span>
            <button type="button" class="btn btn-success btn-sm ms-auto" id="bulkResetBtn" disabled>
                <i class="bi bi-lightning-fill me-1"></i>Auto-Reset Selected
            </button>
        </div>
    <?php endif; ?>

    <?php foreach ($requests as $req): ?>
    <?php
        $role_class = match($req['role']) {
            'student' => 'role-student',
            'lecturer' => 'role-lecturer',
            'staff','admin' => 'role-staff',
            default => 'role-other'
        };
        $status_class = 'status-' . ($req['status'] ?? 'pending');
        $display_name = $req['full_name_lookup'] ?? $req['username'];
    ?>
    <div class="req-card <?= $status_class ?>">
        <div class="d-flex flex-wrap align-items-start gap-3">
            <?php if ($req['status'] === 'pending' && $filter === 'pending'): ?>
            <input type="checkbox" name="selected_ids[]" value="<?= $req['id'] ?>" class="select-check bulk-check mt-1">
            <?php endif; ?>
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1rem;flex-shrink:0;">
                <?= strtoupper(substr($display_name, 0, 1)) ?>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <strong><?= htmlspecialchars($display_name) ?></strong>
                    <span class="text-muted small">(<?= htmlspecialchars($req['username']) ?>)</span>
                    <span class="role-badge <?= $role_class ?>"><?= htmlspecialchars(ucfirst($req['role'])) ?></span>
                    <?php if ($req['status'] === 'pending'): ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                    <?php elseif ($req['status'] === 'resolved'): ?>
                        <span class="badge bg-success">Resolved</span>
                        <?php if (!empty($req['auto_reset'])): ?>
                        <span class="badge bg-info text-dark">Auto</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-danger">Rejected</span>
                    <?php endif; ?>
                    <?php if (isset($req['user_active']) && !$req['user_active']): ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($req['email']) ?>
                    &nbsp;|&nbsp;
                    <i class="bi bi-clock me-1"></i><?= date('M j, Y H:i', strtotime($req['requested_at'])) ?>
                    <?php if (!empty($req['last_login'])): ?>
                    &nbsp;|&nbsp;<i class="bi bi-box-arrow-in-right me-1"></i>Last login: <?= date('M j, Y', strtotime($req['last_login'])) ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($req['notes']) && $req['status'] !== 'pending'): ?>
                <div class="mt-1 small text-muted"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($req['notes']) ?></div>
                <?php endif; ?>
                <?php if (!empty($req['temp_password']) && $req['status'] === 'resolved'): ?>
                <div class="mt-1 small"><i class="bi bi-key me-1 text-success"></i>Temp password: <code><?= htmlspecialchars($req['temp_password']) ?></code></div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2 flex-wrap ms-auto align-items-start">
                <?php if ($req['status'] === 'pending'): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Auto-reset password for <?= htmlspecialchars($req['username'], ENT_QUOTES) ?>? A temporary password will be generated and emailed.');">
                    <input type="hidden" name="action" value="auto_reset">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-lightning-fill me-1"></i>Auto Reset</button>
                </form>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manualModal<?= $req['id'] ?>">
                    <i class="bi bi-key me-1"></i>Manual
                </button>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $req['id'] ?>">
                    <i class="bi bi-x-circle me-1"></i>Reject
                </button>
                <?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this request permanently?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($req['status'] === 'pending'): ?>
    <!-- Manual Reset Modal -->
    <div class="modal fade" id="manualModal<?= $req['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-key-fill me-2"></i>Manual Reset – <?= htmlspecialchars($req['username']) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <div class="modal-body">
                        <p class="text-muted small mb-3">Set a custom password for <strong><?= htmlspecialchars($req['username']) ?></strong>.</p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="np<?= $req['id'] ?>" minlength="6" required placeholder="Min 6 characters">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePwd('np<?= $req['id'] ?>')"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" id="cp<?= $req['id'] ?>" minlength="6" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal<?= $req['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject – <?= htmlspecialchars($req['username']) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Reason..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<!-- Hidden Bulk Reset Form (submitted by JS) -->
<form method="POST" id="bulkForm" style="display:none;">
    <input type="hidden" name="action" value="bulk_auto_reset">
    <div id="bulkFormIds"></div>
</form>

<!-- Bulk Reset Confirmation Modal -->
<div class="modal fade" id="bulkConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-lightning-fill me-2"></i>Confirm Bulk Auto-Reset</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">You are about to auto-reset passwords for <strong id="modalCount">0</strong> user(s). Each user will receive a temporary password via email.</p>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>This action cannot be undone. Users will need to log in with temporary passwords.
                </div>
                <h6 class="fw-bold mb-2"><i class="bi bi-people me-1"></i>Selected Users:</h6>
                <div id="bulkUserList" style="max-height:280px; overflow-y:auto;">
                    <!-- populated by JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmBulkReset">
                    <i class="bi bi-lightning-fill me-1"></i>Reset All <span id="confirmCount">0</span> Passwords
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd(id) {
    var el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
}

// Bulk select logic
var selectAll = document.getElementById('selectAll');
var bulkChecks = document.querySelectorAll('.bulk-check');
var bulkBtn = document.getElementById('bulkResetBtn');
var countSpan = document.getElementById('selectedCount');

function updateCount() {
    var checked = document.querySelectorAll('.bulk-check:checked').length;
    if (countSpan) countSpan.textContent = checked;
    if (bulkBtn) bulkBtn.disabled = checked === 0;
    if (selectAll) selectAll.checked = checked === bulkChecks.length && bulkChecks.length > 0;
}

if (selectAll) {
    selectAll.addEventListener('change', function() {
        bulkChecks.forEach(function(cb) { cb.checked = selectAll.checked; });
        updateCount();
    });
}
bulkChecks.forEach(function(cb) {
    cb.addEventListener('change', updateCount);
});

// Bulk reset confirmation modal
if (bulkBtn) {
    bulkBtn.addEventListener('click', function() {
        var checkedBoxes = document.querySelectorAll('.bulk-check:checked');
        var count = checkedBoxes.length;
        if (count === 0) return;

        document.getElementById('modalCount').textContent = count;
        document.getElementById('confirmCount').textContent = count;

        // Build user list from the checked cards
        var listHtml = '<table class="table table-sm table-bordered mb-0" style="font-size:.85rem;"><thead class="table-light"><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th></tr></thead><tbody>';
        var idx = 0;
        checkedBoxes.forEach(function(cb) {
            idx++;
            var card = cb.closest('.req-card');
            if (!card) return;
            var name = card.querySelector('.flex-grow-1 strong') ? card.querySelector('.flex-grow-1 strong').textContent.trim() : '-';
            var usernameEl = card.querySelector('.flex-grow-1 .text-muted.small');
            var username = usernameEl ? usernameEl.textContent.replace(/[()]/g,'').split('|')[0].trim() : '-';
            // Get username from the parentheses next to the name
            var nameRow = card.querySelector('.flex-grow-1 .d-flex');
            if (nameRow) {
                var uSpan = nameRow.querySelectorAll('.text-muted.small');
                if (uSpan.length > 0) username = uSpan[0].textContent.replace(/[()]/g,'').trim();
            }
            var roleBadge = card.querySelector('.role-badge');
            var role = roleBadge ? roleBadge.textContent.trim() : '-';
            // Get email from the detail line
            var detailLine = card.querySelectorAll('.text-muted.small');
            var email = '-';
            detailLine.forEach(function(el) {
                var text = el.textContent;
                if (text.indexOf('@') !== -1) {
                    var match = text.match(/[\w.-]+@[\w.-]+/);
                    if (match) email = match[0];
                }
            });
            listHtml += '<tr><td>' + idx + '</td><td>' + escHtml(name) + '</td><td><code>' + escHtml(username) + '</code></td><td>' + escHtml(email) + '</td><td><span class="badge bg-secondary">' + escHtml(role) + '</span></td></tr>';
        });
        listHtml += '</tbody></table>';
        document.getElementById('bulkUserList').innerHTML = listHtml;

        var modal = new bootstrap.Modal(document.getElementById('bulkConfirmModal'));
        modal.show();
    });
}

// Confirm button collects checked IDs and submits the hidden bulk form
var confirmBtn = document.getElementById('confirmBulkReset');
if (confirmBtn) {
    confirmBtn.addEventListener('click', function() {
        var form = document.getElementById('bulkForm');
        var idsContainer = document.getElementById('bulkFormIds');
        if (!form || !idsContainer) return;
        // Clear previous hidden inputs
        idsContainer.innerHTML = '';
        // Inject a hidden input for each checked box
        var checkedBoxes = document.querySelectorAll('.bulk-check:checked');
        checkedBoxes.forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            idsContainer.appendChild(input);
        });
        if (checkedBoxes.length > 0) {
            form.submit();
        }
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}
</script>
</body>
</html>
