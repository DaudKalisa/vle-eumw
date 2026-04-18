<?php
/**
 * Admin - Student Invite Links Manager
 * Allows admin/coordinator/super_admin to create and manage invite links
 * that students use to register in the system.
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin', 'odl_coordinator']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';
$bulk_dissertation_url = '';
$bulk_dissertation_label = '';

// Ensure dissertation_only and is_supervisor columns exist
$diss_col = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'dissertation_only'");
if ($diss_col && $diss_col->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN dissertation_only TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
}
$sup_col = $conn->query("SHOW COLUMNS FROM student_registration_invites LIKE 'is_supervisor'");
if ($sup_col && $sup_col->num_rows === 0) {
    $conn->query("ALTER TABLE student_registration_invites ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER dissertation_only");
}

// Get departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name");
if ($dept_result) {
    while ($dept = $dept_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create new invite link
    if (isset($_POST['create_invite'])) {
        $email = trim($_POST['invite_email'] ?? '');
        $full_name = trim($_POST['invite_name'] ?? '');
        $department_id = !empty($_POST['invite_department']) ? (int)$_POST['invite_department'] : null;
        $program = trim($_POST['invite_program'] ?? '');
        $campus = trim($_POST['invite_campus'] ?? 'Mzuzu Campus');
        $program_type = trim($_POST['invite_program_type'] ?? 'degree');
        $year_of_study = (int)($_POST['invite_year'] ?? 1);
        $semester = trim($_POST['invite_semester'] ?? 'One');
        $entry_type = trim($_POST['invite_entry_type'] ?? 'NE');
        $max_uses = (int)($_POST['invite_max_uses'] ?? 1);
        $expires_days = (int)($_POST['invite_expires_days'] ?? 30);
        $notes = trim($_POST['invite_notes'] ?? '');
        $send_email = isset($_POST['send_email']);
        
        $token = bin2hex(random_bytes(32));
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;
        
        $bind_email = $email ?: null;
        $bind_name = $full_name ?: null;
        $bind_program = $program ?: null;
        $bind_notes = $notes ?: null;
        $created_by = $user['user_id'];
        
        $stmt = $conn->prepare("INSERT INTO student_registration_invites 
            (token, email, full_name, department_id, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssississsisis", 
            $token, 
            $bind_email, 
            $bind_name, 
            $department_id, 
            $bind_program, 
            $campus, 
            $program_type, 
            $year_of_study, 
            $semester, 
            $entry_type, 
            $max_uses, 
            $expires_at, 
            $created_by, 
            $bind_notes);
        
        if ($stmt->execute()) {
            $invite_url = getInviteUrl($token);
            $success = "Invite link created successfully!";
            
            // Send email if requested and email is provided
            if ($send_email && !empty($email) && isEmailEnabled()) {
                $email_body = getInviteLinkEmailBody($full_name ?: 'Student', $invite_url, $expires_days, $campus, $program);
                $email_sent = sendEmail($email, $full_name ?: 'Student', 'Registration Invitation - EUMW VLE', $email_body);
                $success .= $email_sent ? ' Email sent to ' . htmlspecialchars($email) . '.' : ' (Email sending failed)';
            }
        } else {
            $error = 'Failed to create invite: ' . $conn->error;
        }
    }
    
    // Deactivate invite
    if (isset($_POST['deactivate_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("UPDATE student_registration_invites SET is_active = 0 WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) {
            $success = "Invite link deactivated.";
        }
    }
    
    // Reactivate invite
    if (isset($_POST['activate_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("UPDATE student_registration_invites SET is_active = 1 WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) {
            $success = "Invite link reactivated.";
        }
    }
    
    // Delete invite
    if (isset($_POST['delete_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("DELETE FROM student_registration_invites WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) {
            $success = "Invite link deleted.";
        }
    }

    // Create Bulk Dissertation Invite Link
    if (isset($_POST['create_bulk_dissertation'])) {
        $bulk_label = trim($_POST['diss_label'] ?? '');
        $bulk_max_uses = (int)($_POST['diss_max_uses'] ?? 300);
        $bulk_type = ($_POST['diss_type'] ?? 'student') === 'supervisor' ? 'supervisor' : 'student';
        $diss_campus = trim($_POST['diss_campus'] ?? 'Mzuzu Campus');
        $valid_campuses = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];
        if (!in_array($diss_campus, $valid_campuses)) $diss_campus = 'Mzuzu Campus';
        if ($bulk_max_uses < 2) $bulk_max_uses = 2;
        if ($bulk_max_uses > 300) $bulk_max_uses = 300;
        if (empty($bulk_label)) {
            $error = 'Please provide a label/description for the bulk dissertation invite.';
        } else {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+60 days'));
            $created_by = $user['user_id'];
            $notes = 'Bulk dissertation invite: ' . $bulk_label . ' | type: ' . $bulk_type . ' | campus: ' . $diss_campus;
            $is_supervisor = ($bulk_type === 'supervisor') ? 1 : 0;
            $stmt = $conn->prepare("INSERT INTO student_registration_invites
                (token, email, full_name, program, campus, program_type, year_of_study, semester, entry_type, max_uses, expires_at, created_by, notes, dissertation_only, is_supervisor)
                VALUES (?, '', ?, '', ?, 'degree', 3, 'One', 'NE', ?, ?, ?, ?, 1, ?)");
            $stmt->bind_param("sssisisi", $token, $bulk_label, $diss_campus, $bulk_max_uses, $expires_at, $created_by, $notes, $is_supervisor);
            if ($stmt->execute()) {
                $invite_url = getInviteUrl($token);
                $type_label = ucfirst($bulk_type);
                $success = "{$type_label} bulk dissertation invite link created successfully (Max uses: {$bulk_max_uses}).";
                $bulk_dissertation_url = $invite_url;
                $bulk_dissertation_label = $bulk_label;
            } else {
                $error = 'Failed to create bulk dissertation invite: ' . $conn->error;
            }
        }
    }

    // Create bulk invites
    if (isset($_POST['create_bulk'])) {
        $bulk_count = min((int)($_POST['bulk_count'] ?? 5), 100);
        $department_id = !empty($_POST['bulk_department']) ? (int)$_POST['bulk_department'] : null;
        $program = trim($_POST['bulk_program'] ?? '');
        $campus = trim($_POST['bulk_campus'] ?? 'Mzuzu Campus');
        $program_type = trim($_POST['bulk_program_type'] ?? 'degree');
        $max_uses = (int)($_POST['bulk_max_uses'] ?? 1);
        $expires_days = (int)($_POST['bulk_expires_days'] ?? 30);
        $notes = trim($_POST['bulk_notes'] ?? '');
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;

        $created = 0;
        $bind_program = $program ?: null;
        $bind_notes = $notes ?: null;
        $created_by = $user['user_id'];
        for ($i = 0; $i < $bulk_count; $i++) {
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare("INSERT INTO student_registration_invites 
                (token, department_id, program, campus, program_type, max_uses, expires_at, created_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisssisis", $token, $department_id, $bind_program, $campus, $program_type, $max_uses, $expires_at, $created_by, $bind_notes);
            if ($stmt->execute()) $created++;
        }
        $success = "$created bulk invite links created successfully.";
    }
}

// Get invite statistics
$stats = [];
$stat_q = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses = 0 OR times_used < max_uses)) as active,
    SUM(is_active = 0) as inactive,
    SUM(times_used > 0) as used,
    SUM(times_used) as total_uses,
    SUM(dissertation_only = 1) as dissertation,
    SUM(is_supervisor = 1) as supervisor
    FROM student_registration_invites");
$stats = $stat_q ? $stat_q->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0, 'used' => 0, 'total_uses' => 0, 'dissertation' => 0, 'supervisor' => 0];

// Pending registrations count
$pending_q = $conn->query("SELECT COUNT(*) as cnt FROM student_invite_registrations WHERE status = 'pending'");
$pending_count = $pending_q ? $pending_q->fetch_assoc()['cnt'] : 0;

// Get all invites with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$filter = $_GET['filter'] ?? 'all';
$where = "1=1";
if ($filter === 'active') $where = "i.is_active = 1 AND (i.expires_at IS NULL OR i.expires_at > NOW()) AND (i.max_uses = 0 OR i.times_used < i.max_uses)";
elseif ($filter === 'inactive') $where = "i.is_active = 0";
elseif ($filter === 'expired') $where = "i.expires_at IS NOT NULL AND i.expires_at <= NOW()";
elseif ($filter === 'used') $where = "i.times_used > 0";
elseif ($filter === 'dissertation') $where = "i.dissertation_only = 1";
elseif ($filter === 'supervisor') $where = "i.is_supervisor = 1";

$total_q = $conn->query("SELECT COUNT(*) as cnt FROM student_registration_invites i WHERE $where");
$total_invites = $total_q ? $total_q->fetch_assoc()['cnt'] : 0;
$total_pages = max(1, ceil($total_invites / $per_page));

$invites = [];
$q = $conn->query("SELECT i.*, u.username as creator_name,
    (SELECT COUNT(*) FROM student_invite_registrations r WHERE r.invite_id = i.invite_id) as reg_count,
    (SELECT COUNT(*) FROM student_invite_registrations r WHERE r.invite_id = i.invite_id AND r.status = 'pending') as pending_count,
    i.dissertation_only, i.is_supervisor
    FROM student_registration_invites i
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE $where
    ORDER BY i.created_at DESC
    LIMIT $per_page OFFSET $offset");
if ($q) { while ($row = $q->fetch_assoc()) $invites[] = $row; }

// Helper to build the registration URL
function getInviteUrl($token) {
    // SITE_URL may include /admin when called from this file, so strip subdirectories
    if (isset($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        // Get the base path (e.g. /vle-eumw) by finding the project root
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        // Remove /admin/... or any subdir to get project root
        $base = preg_replace('#/admin/.*$#', '', $script);
        $root_url = $protocol . '://' . $host . $base;
    } else {
        $root_url = 'http://localhost/vle-eumw';
    }
    return $root_url . '/register_student.php?token=' . $token;
}

// Email body builder
function getInviteLinkEmailBody($name, $url, $expires_days, $campus, $program) {
    $expires_text = $expires_days > 0 ? "This link expires in {$expires_days} days." : "This link does not expire.";
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
        <div style='background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:30px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
            <h2 style='margin:0;'>Student Registration Invitation</h2>
            <p style='margin:8px 0 0;opacity:0.9;'>Exploits University of Malawi - VLE</p>
        </div>
        <div style='background:#fff;padding:30px;border:1px solid #e2e8f0;'>
            <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>You have been invited to register as a student on the Exploits University of Malawi Virtual Learning Environment.</p>
            " . ($campus ? "<p><strong>Campus:</strong> " . htmlspecialchars($campus) . "</p>" : "") . "
            " . ($program ? "<p><strong>Program:</strong> " . htmlspecialchars($program) . "</p>" : "") . "
            <div style='text-align:center;margin:25px 0;'>
                <a href='" . htmlspecialchars($url) . "' style='background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:14px 40px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                    Register Now
                </a>
            </div>
            <p style='color:#64748b;font-size:0.9em;'>{$expires_text}</p>
            <p style='color:#64748b;font-size:0.85em;'>If the button doesn't work, copy and paste this URL into your browser:<br>
            <a href='" . htmlspecialchars($url) . "' style='color:#4f46e5;word-break:break-all;'>" . htmlspecialchars($url) . "</a></p>
            <hr style='border:none;border-top:1px solid #e2e8f0;margin:20px 0;'>
            <p style='color:#94a3b8;font-size:0.8em;text-align:center;'>After registration, an administrator will review and approve your account.</p>
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Invite Links - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card .stat-icon { font-size: 28px; margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; }
        .invite-row { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
        .invite-row:hover { border-color: #c7d2fe; }
        .badge-active { background: #dcfce7; color: #16a34a; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-inactive { background: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-expired { background: #fef3c7; color: #d97706; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .link-display {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.8rem;
            word-break: break-all;
            color: #4f46e5;
            cursor: pointer;
            position: relative;
        }
        .link-display:hover { background: #eef2ff; }
        .copy-toast {
            position: fixed; bottom: 20px; right: 20px; z-index: 9999;
            background: #16a34a; color: #fff; padding: 12px 24px; border-radius: 10px;
            font-size: 0.9rem; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Student Invite Links']];
    include 'header_nav.php'; 
    ?>
    <div class="vle-content">
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
            <?php if (!empty($bulk_dissertation_url)): ?>
            <div class="mt-3 p-3 bg-white rounded" style="border: 1px solid #d4edda;">
                <small class="d-block mb-2 text-muted"><strong>Dissertation Invite Link:</strong></small>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <code style="background:#f8f9fa; padding:8px 12px; border-radius:4px; flex:1; min-width:300px; word-break:break-all;"><?= htmlspecialchars($bulk_dissertation_url) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="copyLink('<?= htmlspecialchars($bulk_dissertation_url, ENT_QUOTES) ?>')" title="Copy">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 col-lg">
                <div class="stat-card">
                    <div class="stat-icon text-primary"><i class="bi bi-link-45deg"></i></div>
                    <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
                    <div class="stat-label">Total Links</div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg">
                <div class="stat-card">
                    <div class="stat-icon text-success"><i class="bi bi-check-circle"></i></div>
                    <div class="stat-value"><?= $stats['active'] ?? 0 ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg">
                <div class="stat-card">
                    <div class="stat-icon text-info"><i class="bi bi-people"></i></div>
                    <div class="stat-value"><?= $stats['total_uses'] ?? 0 ?></div>
                    <div class="stat-label">Registrations</div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg">
                <a href="approve_student_accounts.php" class="stat-card d-block text-decoration-none" style="border-color: <?= $pending_count > 0 ? '#fbbf24' : '#e2e8f0' ?>;">
                    <div class="stat-icon text-warning"><i class="bi bi-clock-history"></i></div>
                    <div class="stat-value text-warning"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Approval</div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg">
                <a href="?filter=dissertation" class="stat-card d-block text-decoration-none">
                    <div class="stat-icon" style="color:#8b5cf6;"><i class="bi bi-journal-bookmark"></i></div>
                    <div class="stat-value" style="color:#8b5cf6;"><?= $stats['dissertation'] ?? 0 ?></div>
                    <div class="stat-label">Dissertation Invites</div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg">
                <a href="?filter=supervisor" class="stat-card d-block text-decoration-none">
                    <div class="stat-icon" style="color:#d97706;"><i class="bi bi-person-badge"></i></div>
                    <div class="stat-value" style="color:#d97706;"><?= $stats['supervisor'] ?? 0 ?></div>
                    <div class="stat-label">Supervisor Invites</div>
                </a>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex flex-wrap gap-2 mb-4">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createInviteModal">
                <i class="bi bi-plus-circle me-1"></i> Create Invite Link
            </button>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkModal">
                <i class="bi bi-files me-1"></i> Bulk Create
            </button>
            <button class="btn" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;" data-bs-toggle="modal" data-bs-target="#dissertationInviteModal">
                <i class="bi bi-journal-bookmark me-1"></i> Bulk Dissertation Invite
            </button>
            <a href="approve_student_accounts.php" class="btn btn-outline-warning">
                <i class="bi bi-clipboard-check me-1"></i> Review Registrations
                <?php if ($pending_count > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- Filter tabs -->
        <ul class="nav nav-pills mb-3">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'used' => 'Used', 'dissertation' => 'Dissertation', 'supervisor' => 'Supervisor', 'inactive' => 'Inactive', 'expired' => 'Expired'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="?filter=<?= $key ?>"><?= $label ?>
                    <?php if ($key === 'dissertation' && ($stats['dissertation'] ?? 0) > 0): ?>
                    <span class="badge bg-light text-dark ms-1"><?= $stats['dissertation'] ?></span>
                    <?php elseif ($key === 'supervisor' && ($stats['supervisor'] ?? 0) > 0): ?>
                    <span class="badge bg-light text-dark ms-1"><?= $stats['supervisor'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- Invite Links List -->
        <?php if (empty($invites)): ?>
        <div class="text-center py-5">
            <i class="bi bi-link-45deg" style="font-size:3rem;color:#cbd5e1;"></i>
            <p class="text-muted mt-2">No invite links found. Create one to get started.</p>
        </div>
        <?php else: ?>
        <?php foreach ($invites as $inv): 
            $is_expired = $inv['expires_at'] && strtotime($inv['expires_at']) < time();
            $is_maxed = $inv['max_uses'] > 0 && $inv['times_used'] >= $inv['max_uses'];
            $is_usable = $inv['is_active'] && !$is_expired && !$is_maxed;
            $url = getInviteUrl($inv['token']);
        ?>
        <div class="invite-row">
            <div class="row align-items-center g-2">
                <div class="col-md-5">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <?php if ($is_usable): ?>
                            <span class="badge-active"><i class="bi bi-check-circle me-1"></i>Active</span>
                        <?php elseif ($is_expired): ?>
                            <span class="badge-expired"><i class="bi bi-clock me-1"></i>Expired</span>
                        <?php else: ?>
                            <span class="badge-inactive"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                        <?php endif; ?>
                        <?php if ($inv['pending_count'] > 0): ?>
                            <span class="badge bg-warning text-dark" style="font-size:0.7rem;"><?= $inv['pending_count'] ?> pending</span>
                        <?php endif; ?>
                        <?php if (!empty($inv['dissertation_only'])): ?>
                            <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.7rem;"><i class="bi bi-journal-bookmark me-1"></i>Dissertation</span>
                        <?php endif; ?>
                        <?php if (!empty($inv['is_supervisor'])): ?>
                            <span class="badge" style="background:#fef3c7;color:#d97706;font-size:0.7rem;"><i class="bi bi-person-badge me-1"></i>Supervisor</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($inv['full_name'] || $inv['email']): ?>
                    <div style="font-size:0.85rem;font-weight:500;">
                        <?= htmlspecialchars($inv['full_name'] ?: '') ?>
                        <?php if ($inv['email']): ?><span class="text-muted">&lt;<?= htmlspecialchars($inv['email']) ?>&gt;</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="link-display mt-1" onclick="copyLink('<?= htmlspecialchars($url) ?>')" title="Click to copy">
                        <i class="bi bi-clipboard me-1"></i><?= htmlspecialchars($url) ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div style="font-size:0.8rem;color:#64748b;">
                        <?php if ($inv['campus']): ?><div><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inv['campus']) ?></div><?php endif; ?>
                        <?php if ($inv['program']): ?><div><i class="bi bi-book me-1"></i><?= htmlspecialchars($inv['program']) ?></div><?php endif; ?>
                        <?php if (!empty($inv['notes'])): ?><div><i class="bi bi-sticky me-1"></i><?= htmlspecialchars(mb_strimwidth($inv['notes'], 0, 60, '...')) ?></div><?php endif; ?>
                        <div><i class="bi bi-people me-1"></i>Used: <?= $inv['times_used'] ?>/<?= $inv['max_uses'] ?: '&infin;' ?></div>
                        <?php if ($inv['expires_at']): ?>
                        <div><i class="bi bi-clock me-1"></i>Expires: <?= date('M j, Y', strtotime($inv['expires_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2">
                    <div style="font-size:0.75rem;color:#94a3b8;">
                        Created <?= date('M j, Y', strtotime($inv['created_at'])) ?><br>
                        by <?= htmlspecialchars($inv['creator_name'] ?? 'Unknown') ?>
                    </div>
                </div>
                <div class="col-md-2 text-end">
                    <div class="d-flex gap-1 justify-content-end flex-wrap">
                        <button class="btn btn-sm btn-outline-primary" onclick="copyLink('<?= htmlspecialchars($url) ?>')" title="Copy link">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <?php if ($inv['is_active']): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="deactivate_invite" class="btn btn-sm btn-outline-warning" title="Deactivate">
                                <i class="bi bi-pause-circle"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="activate_invite" class="btn btn-sm btn-outline-success" title="Reactivate">
                                <i class="bi bi-play-circle"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this invite link?')">
                            <input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="delete_invite" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&filter=<?= $filter ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Create Invite Modal -->
    <div class="modal fade" id="createInviteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Create Student Invite Link</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p class="text-muted mb-3" style="font-size:0.85rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Create a link to send to a student. They will fill out a registration form and an admin will review before activating the account.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Student Name <small class="text-muted">(optional)</small></label>
                                <input type="text" name="invite_name" class="form-control" placeholder="Pre-fill name for the student">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Student Email <small class="text-muted">(optional)</small></label>
                                <input type="email" name="invite_email" class="form-control" placeholder="student@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Department</label>
                                <select name="invite_department" class="form-select">
                                    <option value="">Any Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Program</label>
                                <input type="text" name="invite_program" class="form-control" placeholder="e.g. Bachelor of Business Administration">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Campus</label>
                                <select name="invite_campus" class="form-select">
                                    <option value="Mzuzu Campus">Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                    <option value="ODel Campus">ODel Campus</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Program Type</label>
                                <select name="invite_program_type" class="form-select">
                                    <option value="degree">Degree</option>
                                    <option value="diploma">Diploma</option>
                                    <option value="certificate">Certificate</option>
                                    <option value="professional">Professional</option>
                                    <option value="masters">Masters</option>
                                    <option value="doctorate">Doctorate</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Entry Type</label>
                                <select name="invite_entry_type" class="form-select">
                                    <option value="NE">Normal Entry (NE)</option>
                                    <option value="ME">Mature Entry (ME)</option>
                                    <option value="CE">Continuing Entry (CE)</option>
                                    <option value="ODL">Open Distance Learning (ODL)</option>
                                    <option value="PC">Professional Course (PC)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Year</label>
                                <select name="invite_year" class="form-select">
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                    <option value="<?= $i ?>">Year <?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Semester</label>
                                <select name="invite_semester" class="form-select">
                                    <option value="One">Semester One</option>
                                    <option value="Two">Semester Two</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Max Uses</label>
                                <input type="number" name="invite_max_uses" class="form-control" value="1" min="0" max="1000">
                                <small class="text-muted">0 = unlimited</small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Expires In</label>
                                <select name="invite_expires_days" class="form-select">
                                    <option value="7">7 days</option>
                                    <option value="14">14 days</option>
                                    <option value="30" selected>30 days</option>
                                    <option value="60">60 days</option>
                                    <option value="90">90 days</option>
                                    <option value="0">Never</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea name="invite_notes" class="form-control" rows="2" placeholder="Internal notes (not shown to student)"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" name="send_email" id="sendEmail" class="form-check-input" checked>
                                    <label class="form-check-label" for="sendEmail">
                                        <i class="bi bi-envelope me-1"></i>Send invite link via email (requires email to be filled)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_invite" class="btn btn-primary">
                            <i class="bi bi-link-45deg me-1"></i> Create Invite Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Create Modal -->
    <div class="modal fade" id="bulkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-files me-2"></i>Bulk Create Invite Links</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p class="text-muted" style="font-size:0.85rem;">Create multiple invite links at once. These are generic links (no name/email pre-filled).</p>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Number of Links</label>
                                <input type="number" name="bulk_count" class="form-control" value="10" min="1" max="100">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Max Uses Each</label>
                                <input type="number" name="bulk_max_uses" class="form-control" value="1" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Department</label>
                                <select name="bulk_department" class="form-select">
                                    <option value="">Any</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Campus</label>
                                <select name="bulk_campus" class="form-select">
                                    <option value="Mzuzu Campus">Mzuzu Campus</option>
                                    <option value="Lilongwe Campus">Lilongwe Campus</option>
                                    <option value="Blantyre Campus">Blantyre Campus</option>
                                    <option value="ODel Campus">ODel Campus</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Program</label>
                                <input type="text" name="bulk_program" class="form-control" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Program Type</label>
                                <select name="bulk_program_type" class="form-select">
                                    <option value="degree">Degree</option>
                                    <option value="diploma">Diploma</option>
                                    <option value="certificate">Certificate</option>
                                    <option value="professional">Professional</option>
                                    <option value="masters">Masters</option>
                                    <option value="doctorate">Doctorate</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Expires In</label>
                                <select name="bulk_expires_days" class="form-select">
                                    <option value="30" selected>30 days</option>
                                    <option value="60">60 days</option>
                                    <option value="90">90 days</option>
                                    <option value="0">Never</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea name="bulk_notes" class="form-control" rows="2" placeholder="Batch notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_bulk" class="btn btn-primary">
                            <i class="bi bi-files me-1"></i> Create Bulk Links
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Dissertation Invite Modal -->
    <div class="modal fade" id="dissertationInviteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-journal-bookmark me-2"></i>Create Bulk Dissertation Invite Link</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Create a shared invite link for dissertation students or supervisors. The link can be used by multiple people (up to the max uses limit). 
                            These invites are tracked separately and visible in the Research Coordinator's portal.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Invite Type <span class="text-danger">*</span></label>
                            <select name="diss_type" class="form-select" id="dissTypeSelect">
                                <option value="student">Dissertation Student</option>
                                <option value="supervisor">Dissertation Supervisor</option>
                            </select>
                            <small class="text-muted" id="dissTypeHint">Students will register and be linked to the dissertation module.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Label / Description <span class="text-danger">*</span></label>
                            <input type="text" name="diss_label" class="form-control" required placeholder="e.g. 2026 Semester 1 Dissertation Students">
                            <small class="text-muted">Helps identify this invite link in reports.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Campus <span class="text-danger">*</span></label>
                            <select name="diss_campus" class="form-select" required>
                                <option value="Mzuzu Campus">Mzuzu Campus</option>
                                <option value="Lilongwe Campus">Lilongwe Campus</option>
                                <option value="Blantyre Campus">Blantyre Campus</option>
                                <option value="ODel Campus">ODel Campus</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Maximum Uses</label>
                            <input type="number" name="diss_max_uses" class="form-control" value="300" min="2" max="300">
                            <small class="text-muted">How many people can register using this link (max 300).</small>
                        </div>
                        <div class="mb-3">
                            <div class="card bg-light border-0">
                                <div class="card-body py-2 small text-muted">
                                    <i class="bi bi-clock me-1"></i>Link expires in <strong>60 days</strong> from creation.<br>
                                    <i class="bi bi-tag me-1"></i>Tracked as: <strong>dissertation_only</strong> invite
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_bulk_dissertation" class="btn" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:#fff;">
                            <i class="bi bi-journal-bookmark me-1"></i> Create Dissertation Invite
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Copy Toast -->
    <div class="copy-toast" id="copyToast"><i class="bi bi-check-circle me-2"></i>Link copied to clipboard!</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function copyLink(url) {
        navigator.clipboard.writeText(url).then(function() {
            const toast = document.getElementById('copyToast');
            toast.style.display = 'block';
            setTimeout(function() { toast.style.display = 'none'; }, 2500);
        }).catch(function() {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = url;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            const toast = document.getElementById('copyToast');
            toast.style.display = 'block';
            setTimeout(function() { toast.style.display = 'none'; }, 2500);
        });
    }

    // Dissertation type hint toggle
    const dissTypeSelect = document.getElementById('dissTypeSelect');
    const dissTypeHint = document.getElementById('dissTypeHint');
    if (dissTypeSelect) {
        dissTypeSelect.addEventListener('change', function() {
            if (this.value === 'supervisor') {
                dissTypeHint.textContent = 'Supervisors will register as lecturers linked to the dissertation module.';
            } else {
                dissTypeHint.textContent = 'Students will register and be linked to the dissertation module.';
            }
        });
    }
    </script>
</body>
</html>
