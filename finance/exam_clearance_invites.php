<?php
/**
 * Examination Clearance - Invite Link Management
 * Finance officers generate invite links for system students to apply for exam clearance
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Generate new invite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate') {
        $program_type = $_POST['program_type'] ?? 'degree';
        $description = trim($_POST['description'] ?? '');
        $max_uses = (int)($_POST['max_uses'] ?? 0);
        $expires_days = (int)($_POST['expires_days'] ?? 30);
        
        if (!in_array($program_type, ['degree', 'professional', 'masters', 'doctorate'])) {
            $error = 'Invalid program type.';
        } else {
            $token = bin2hex(random_bytes(32));
            $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;
            
            $stmt = $conn->prepare("INSERT INTO exam_clearance_invites (invite_token, program_type, description, max_uses, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
            $uid = (int)$user['user_id'];
            $stmt->bind_param("sssiss", $token, $program_type, $description, $max_uses, $uid, $expires_at);
            
            if ($stmt->execute()) {
                $success = 'Exam clearance invite created! Students can now apply from their dashboard when this window is active.';
            } else {
                $error = 'Failed to generate invite: ' . $conn->error;
            }
        }
    }
    
    if ($_POST['action'] === 'toggle') {
        $invite_id = (int)$_POST['invite_id'];
        $conn->query("UPDATE exam_clearance_invites SET is_active = NOT is_active WHERE invite_id = $invite_id");
        $success = 'Invite status updated.';
    }
    
    if ($_POST['action'] === 'delete') {
        $invite_id = (int)$_POST['invite_id'];
        $conn->query("DELETE FROM exam_clearance_invites WHERE invite_id = $invite_id");
        $success = 'Invite deleted.';
    }
}

// Fetch all invites
$invites = [];
$rs = $conn->query("SELECT eci.*, u.username as created_by_name FROM exam_clearance_invites eci LEFT JOIN users u ON eci.created_by = u.user_id ORDER BY eci.created_at DESC");
if ($rs) while ($r = $rs->fetch_assoc()) $invites[] = $r;

$page_title = 'Exam Clearance Invites';
$breadcrumbs = [['title' => 'Exam Clearance Invites']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/finance-dashboard.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Generate New Invite -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create Exam Clearance Window</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Create an exam clearance window. Students in the system will see active windows on their dashboard and can apply directly.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="generate">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Program Type</label>
                            <select name="program_type" class="form-select" required>
                                <option value="degree">Degree (Undergraduate)</option>
                                <option value="professional">Professional</option>
                                <option value="masters">Masters</option>
                                <option value="doctorate">Doctorate</option>
                            </select>
                            <small class="text-muted">Students will be invoiced based on this level.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. 2026 Semester 1 Exam Clearance">
                        </div>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">Max Uses</label>
                                <input type="number" name="max_uses" class="form-control" value="0" min="0">
                                <small class="text-muted">0 = unlimited</small>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">Expires In (days)</label>
                                <input type="number" name="expires_days" class="form-control" value="30" min="0">
                                <small class="text-muted">0 = never expires</small>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-circle me-2"></i>Create Clearance Window</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Existing Invites -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Exam Clearance Windows</h5>
                    <span class="badge bg-primary"><?= count($invites) ?> total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Program</th>
                                    <th>Description</th>
                                    <th>Uses</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invites)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No exam clearance windows created yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($invites as $inv): 
                                    $expired = $inv['expires_at'] && strtotime($inv['expires_at']) < time();
                                    $maxed = $inv['max_uses'] > 0 && $inv['times_used'] >= $inv['max_uses'];
                                    $active = $inv['is_active'] && !$expired && !$maxed;
                                ?>
                                <tr class="<?= !$active ? 'table-secondary' : '' ?>">
                                    <td><span class="badge bg-<?= $inv['program_type'] === 'masters' ? 'info' : ($inv['program_type'] === 'doctorate' ? 'danger' : 'primary') ?>"><?= ucfirst($inv['program_type']) ?></span></td>
                                    <td><?= htmlspecialchars($inv['description'] ?: '—') ?></td>
                                    <td><?= $inv['times_used'] ?><?= $inv['max_uses'] > 0 ? '/' . $inv['max_uses'] : '' ?></td>
                                    <td>
                                        <?php if ($expired): ?>
                                            <span class="badge bg-secondary">Expired</span>
                                        <?php elseif ($maxed): ?>
                                            <span class="badge bg-secondary">Max Reached</span>
                                        <?php elseif ($active): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= date('M j, Y', strtotime($inv['created_at'])) ?></small></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                                            <button class="btn btn-sm btn-outline-<?= $inv['is_active'] ? 'warning' : 'success' ?>" title="<?= $inv['is_active'] ? 'Disable' : 'Enable' ?>">
                                                <i class="bi bi-<?= $inv['is_active'] ? 'pause' : 'play' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this clearance window?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
