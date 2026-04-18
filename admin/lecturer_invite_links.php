<?php
/**
 * Admin - Lecturer Invite Links Manager
 * Create and manage invite links for lecturer registration.
 * Lecturers register via the link, select modules, and wait for admin approval.
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Auto-create tables if they don't exist
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_registration_invites (
    invite_id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    max_uses INT DEFAULT 1,
    times_used INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    INDEX idx_token (token),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS lecturer_invite_registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    invite_id INT NOT NULL DEFAULT 0,
    lecturer_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(20) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    qualification VARCHAR(200) DEFAULT NULL,
    specialization VARCHAR(200) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    selected_modules TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    INDEX idx_invite (invite_id),
    INDEX idx_status (status),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
if ($dept_result) {
    while ($d = $dept_result->fetch_assoc()) $departments[] = $d['department'];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create new invite link
    if (isset($_POST['create_invite'])) {
        $email = trim($_POST['invite_email'] ?? '');
        $full_name = trim($_POST['invite_name'] ?? '');
        $department = trim($_POST['invite_department'] ?? '');
        $position = trim($_POST['invite_position'] ?? '');
        $max_uses = (int)($_POST['invite_max_uses'] ?? 1);
        $expires_days = (int)($_POST['invite_expires_days'] ?? 30);
        $notes = trim($_POST['invite_notes'] ?? '');
        $send_email = isset($_POST['send_email']);
        
        $token = bin2hex(random_bytes(32));
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;
        
        $bind_email = $email ?: null;
        $bind_name = $full_name ?: null;
        $bind_dept = $department ?: null;
        $bind_pos = $position ?: null;
        $bind_notes = $notes ?: null;
        $created_by = $user['user_id'];
        
        $stmt = $conn->prepare("INSERT INTO lecturer_registration_invites 
            (token, email, full_name, department, position, max_uses, expires_at, created_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssisis", 
            $token, $bind_email, $bind_name, $bind_dept, $bind_pos, 
            $max_uses, $expires_at, $created_by, $bind_notes);
        
        if ($stmt->execute()) {
            $invite_url = getLecturerInviteUrl($token);
            $success = "Invite link created successfully!";
            
            // Send email if requested
            if ($send_email && !empty($email) && function_exists('isEmailEnabled') && isEmailEnabled()) {
                $email_body = getLecturerInviteEmailBody($full_name ?: 'Lecturer', $invite_url, $expires_days, $department, $position);
                $email_sent = sendEmail($email, $full_name ?: 'Lecturer', 'Lecturer Registration Invitation - EUMW VLE', $email_body);
                $success .= $email_sent ? ' Email sent to ' . htmlspecialchars($email) . '.' : ' (Email sending failed)';
            }
        } else {
            $error = 'Failed to create invite: ' . $conn->error;
        }
    }
    
    // Deactivate invite
    if (isset($_POST['deactivate_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("UPDATE lecturer_registration_invites SET is_active = 0 WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) $success = "Invite link deactivated.";
    }
    
    // Reactivate invite
    if (isset($_POST['activate_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("UPDATE lecturer_registration_invites SET is_active = 1 WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) $success = "Invite link reactivated.";
    }
    
    // Delete invite
    if (isset($_POST['delete_invite'])) {
        $invite_id = (int)$_POST['invite_id'];
        $stmt = $conn->prepare("DELETE FROM lecturer_registration_invites WHERE invite_id = ?");
        $stmt->bind_param("i", $invite_id);
        if ($stmt->execute()) $success = "Invite link deleted.";
    }

    // Approve lecturer registration
    if (isset($_POST['approve_registration'])) {
        $reg_id = (int)$_POST['registration_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        // Get registration details
        $stmt = $conn->prepare("SELECT * FROM lecturer_invite_registrations WHERE registration_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $reg_id);
        $stmt->execute();
        $reg = $stmt->get_result()->fetch_assoc();
        
        if ($reg) {
            $full_name = trim($reg['first_name'] . ' ' . ($reg['middle_name'] ? $reg['middle_name'] . ' ' : '') . $reg['last_name']);
            $temp_password = 'Lec@' . date('Y') . rand(100, 999);
            $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
            
            $conn->begin_transaction();
            try {
                // 1. Insert into lecturers table
                $stmt = $conn->prepare("INSERT INTO lecturers (full_name, email, phone, department, position, gender, is_active, hire_date) VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE())");
                $stmt->bind_param("ssssss", $full_name, $reg['email'], $reg['phone'], $reg['department'], $reg['position'], $reg['gender']);
                $stmt->execute();
                $new_lecturer_id = $conn->insert_id;
                
                // 2. Insert into users table
                $username = strtolower($reg['first_name'] . '.' . $reg['last_name']);
                // Make username unique
                $ucheck = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                $ucheck->bind_param("s", $username);
                $ucheck->execute();
                if ($ucheck->get_result()->num_rows > 0) {
                    $username .= $new_lecturer_id;
                }
                
                $role = 'lecturer';
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_lecturer_id, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, 1, 1)");
                $stmt->bind_param("ssssi", $username, $reg['email'], $password_hash, $role, $new_lecturer_id);
                $stmt->execute();
                $new_user_id = $conn->insert_id;
                
                // 3. Allocate selected modules to the lecturer
                $selected = json_decode($reg['selected_modules'], true);
                if (is_array($selected) && count($selected) > 0) {
                    $alloc_stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ? AND (lecturer_id IS NULL OR lecturer_id = 0)");
                    foreach ($selected as $course_id) {
                        $cid = (int)$course_id;
                        $alloc_stmt->bind_param("ii", $new_lecturer_id, $cid);
                        $alloc_stmt->execute();
                    }
                }
                
                // 4. Update registration record
                $stmt = $conn->prepare("UPDATE lecturer_invite_registrations SET status = 'approved', lecturer_id = ?, user_id = ?, reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE registration_id = ?");
                $stmt->bind_param("iiisi", $new_lecturer_id, $new_user_id, $user['user_id'], $admin_notes, $reg_id);
                $stmt->execute();
                
                $conn->commit();
                
                // Send approval email with login link
                if (function_exists('isEmailEnabled') && isEmailEnabled()) {
                    // Build login URL
                    if (defined('SYSTEM_URL')) {
                        $login_url = SYSTEM_URL . '/login.php';
                    } else {
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $login_url = $protocol . '://' . $host . '/vle-eumw/login.php';
                    }
                    
                    $email_body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                        <div style='background:linear-gradient(135deg,#059669,#10b981);padding:30px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
                            <h2 style='margin:0;'>✅ Account Approved!</h2>
                            <p style='margin:8px 0 0;opacity:0.9;'>Welcome to Exploits University VLE</p>
                        </div>
                        <div style='background:#fff;padding:30px;border:1px solid #e2e8f0;'>
                            <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                            <p>Congratulations! Your lecturer account has been approved. You can now access the VLE Lecturer Portal using the credentials below.</p>
                            <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;margin:16px 0;'>
                                <p style='margin:4px 0;'><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                <p style='margin:4px 0;'><strong>Temporary Password:</strong> " . htmlspecialchars($temp_password) . "</p>
                            </div>
                            <p style='color:#dc2626;font-size:0.9em;'>⚠️ For security purposes, please change your password immediately after your first login.</p>
                            <div style='text-align:center;margin:24px 0;'>
                                <a href='" . htmlspecialchars($login_url) . "' style='display:inline-block;background:linear-gradient(135deg,#059669,#10b981);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;'>🔑 Login to Lecturer Portal</a>
                            </div>
                            <p style='font-size:0.85em;color:#666;'>Or copy this link into your browser:<br><a href='" . htmlspecialchars($login_url) . "' style='color:#059669;'>" . htmlspecialchars($login_url) . "</a></p>
                        </div>
                        <div style='background:#f8fafc;padding:16px 30px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;font-size:0.85em;color:#64748b;'>
                            <p style='margin:0;'>As a Lecturer, you can manage courses, upload materials, create assignments, grade submissions, and communicate with students.</p>
                        </div>
                    </div>";
                    sendEmail($reg['email'], $full_name, 'Your Lecturer Account Has Been Approved - EUMW VLE', $email_body);
                }
                
                $success = "Lecturer approved! Username: <strong>" . htmlspecialchars($username) . "</strong>, Temp Password: <strong>" . htmlspecialchars($temp_password) . "</strong>";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to approve: " . $e->getMessage();
            }
        } else {
            $error = "Registration not found or already processed.";
        }
    }
    
    // Reject registration
    if (isset($_POST['reject_registration'])) {
        $reg_id = (int)$_POST['registration_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $stmt = $conn->prepare("UPDATE lecturer_invite_registrations SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE registration_id = ?");
        $stmt->bind_param("isi", $user['user_id'], $admin_notes, $reg_id);
        if ($stmt->execute()) $success = "Registration rejected.";
    }

    // Create bulk invites
    if (isset($_POST['create_bulk'])) {
        $bulk_count = min((int)($_POST['bulk_count'] ?? 5), 100);
        $department = trim($_POST['bulk_department'] ?? '');
        $position = trim($_POST['bulk_position'] ?? '');
        $max_uses = (int)($_POST['bulk_max_uses'] ?? 1);
        $expires_days = (int)($_POST['bulk_expires_days'] ?? 30);
        $notes = trim($_POST['bulk_notes'] ?? '');
        $expires_at = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;

        $created = 0;
        $bind_dept = $department ?: null;
        $bind_pos = $position ?: null;
        $bind_notes = $notes ?: null;
        $created_by = $user['user_id'];
        for ($i = 0; $i < $bulk_count; $i++) {
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare("INSERT INTO lecturer_registration_invites 
                (token, department, position, max_uses, expires_at, created_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssisis", $token, $bind_dept, $bind_pos, $max_uses, $expires_at, $created_by, $bind_notes);
            if ($stmt->execute()) $created++;
        }
        $success = "$created bulk invite links created successfully.";
    }
}

// Stats
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'used' => 0, 'total_uses' => 0];
$stat_q = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses = 0 OR times_used < max_uses)) as active,
    SUM(is_active = 0) as inactive,
    SUM(times_used > 0) as used,
    SUM(times_used) as total_uses
    FROM lecturer_registration_invites");
if ($stat_q) $stats = $stat_q->fetch_assoc();

// Pending registrations
$pending_q = $conn->query("SELECT COUNT(*) as cnt FROM lecturer_invite_registrations WHERE status = 'pending'");
$pending_count = $pending_q ? $pending_q->fetch_assoc()['cnt'] : 0;

// Get invites with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$tab = $_GET['tab'] ?? 'links';
$filter = $_GET['filter'] ?? 'all';

$where = "1=1";
if ($filter === 'active') $where = "i.is_active = 1 AND (i.expires_at IS NULL OR i.expires_at > NOW()) AND (i.max_uses = 0 OR i.times_used < i.max_uses)";
elseif ($filter === 'inactive') $where = "i.is_active = 0";
elseif ($filter === 'expired') $where = "i.expires_at IS NOT NULL AND i.expires_at <= NOW()";
elseif ($filter === 'used') $where = "i.times_used > 0";

$total_q = $conn->query("SELECT COUNT(*) as cnt FROM lecturer_registration_invites i WHERE $where");
$total_invites = $total_q ? $total_q->fetch_assoc()['cnt'] : 0;
$total_pages = max(1, ceil($total_invites / $per_page));

$invites = [];
$q = $conn->query("SELECT i.*, u.username as creator_name,
    (SELECT COUNT(*) FROM lecturer_invite_registrations r WHERE r.invite_id = i.invite_id) as reg_count,
    (SELECT COUNT(*) FROM lecturer_invite_registrations r WHERE r.invite_id = i.invite_id AND r.status = 'pending') as pending_count
    FROM lecturer_registration_invites i
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE $where
    ORDER BY i.created_at DESC
    LIMIT $per_page OFFSET $offset");
if ($q) { while ($row = $q->fetch_assoc()) $invites[] = $row; }

// Get pending registrations
$pending_regs = [];
$reg_filter = $_GET['reg_filter'] ?? 'pending';
$reg_where = $reg_filter === 'all' ? '1=1' : "r.status = '$reg_filter'";
$reg_q = $conn->query("SELECT r.*, 
    (SELECT COUNT(*) FROM lecturer_invite_registrations r2 WHERE r2.invite_id = r.invite_id) as from_same_invite 
    FROM lecturer_invite_registrations r 
    WHERE $reg_where 
    ORDER BY r.registered_at DESC LIMIT 50");
if ($reg_q) { while ($row = $reg_q->fetch_assoc()) $pending_regs[] = $row; }

// Helper: build invite URL
function getLecturerInviteUrl($token) {
    if (isset($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = preg_replace('#/admin/.*$#', '', $script);
        $root_url = $protocol . '://' . $host . $base;
    } else {
        $root_url = 'http://localhost/vle-eumw';
    }
    return $root_url . '/register_lecturer.php?token=' . $token;
}

// Email body builder
function getLecturerInviteEmailBody($name, $url, $expires_days, $department, $position) {
    $expires_text = $expires_days > 0 ? "This link expires in {$expires_days} days." : "This link does not expire.";
    return "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
        <div style='background:linear-gradient(135deg,#059669,#10b981);padding:30px;text-align:center;color:#fff;border-radius:12px 12px 0 0;'>
            <h2 style='margin:0;'>Lecturer Registration Invitation</h2>
            <p style='margin:8px 0 0;opacity:0.9;'>Exploits University of Malawi - VLE</p>
        </div>
        <div style='background:#fff;padding:30px;border:1px solid #e2e8f0;'>
            <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>You have been invited to register as a lecturer on the Exploits University of Malawi Virtual Learning Environment.</p>
            " . ($department ? "<p><strong>Department:</strong> " . htmlspecialchars($department) . "</p>" : "") . "
            " . ($position ? "<p><strong>Position:</strong> " . htmlspecialchars($position) . "</p>" : "") . "
            <p>You will be able to select modules/courses you would like to teach during the registration process.</p>
            <div style='text-align:center;margin:25px 0;'>
                <a href='" . htmlspecialchars($url) . "' style='background:linear-gradient(135deg,#059669,#10b981);color:#fff;padding:14px 40px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                    Register Now
                </a>
            </div>
            <p style='color:#64748b;font-size:0.9em;'>{$expires_text}</p>
            <p style='color:#64748b;font-size:0.85em;'>If the button doesn't work, copy and paste this URL:<br>
            <a href='" . htmlspecialchars($url) . "' style='color:#059669;word-break:break-all;'>" . htmlspecialchars($url) . "</a></p>
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
    <title>Lecturer Invite Links - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card { background:#fff; border-radius:14px; padding:20px; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,0.06); border:1px solid #e2e8f0; transition:transform 0.2s; }
        .stat-card:hover { transform:translateY(-3px); }
        .stat-card .stat-icon { font-size:28px; margin-bottom:8px; }
        .stat-card .stat-value { font-size:28px; font-weight:700; }
        .stat-card .stat-label { font-size:0.8rem; color:#64748b; }
        .invite-row { background:#fff; border-radius:12px; padding:16px; margin-bottom:10px; box-shadow:0 1px 6px rgba(0,0,0,0.04); border:1px solid #e2e8f0; }
        .invite-row:hover { border-color:#a7f3d0; }
        .badge-active { background:#dcfce7; color:#16a34a; padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .badge-inactive { background:#fee2e2; color:#dc2626; padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .badge-expired { background:#fef3c7; color:#d97706; padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .link-display { background:#f0fdf4; border:1px solid #a7f3d0; border-radius:8px; padding:8px 12px; font-size:0.8rem; word-break:break-all; color:#059669; cursor:pointer; }
        .link-display:hover { background:#dcfce7; }
        .reg-card { background:#fff; border-radius:12px; padding:20px; margin-bottom:12px; box-shadow:0 1px 6px rgba(0,0,0,0.04); border:1px solid #e2e8f0; }
        .module-badge { display:inline-block; background:#f0f4ff; color:#4f46e5; padding:3px 10px; border-radius:6px; font-size:0.75rem; margin:2px; }
        .copy-toast { position:fixed; bottom:20px; right:20px; z-index:9999; background:#16a34a; color:#fff; padding:12px 24px; border-radius:10px; font-size:0.9rem; display:none; box-shadow:0 4px 12px rgba(0,0,0,0.2); }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Lecturer Invite Links']];
    include 'header_nav.php'; 
    ?>
    <div class="vle-content">
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 col-lg">
                <div class="stat-card">
                    <div class="stat-icon text-success"><i class="bi bi-link-45deg"></i></div>
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
                <div class="stat-card" style="border-color:<?= $pending_count > 0 ? '#fbbf24' : '#e2e8f0' ?>;">
                    <div class="stat-icon text-warning"><i class="bi bi-clock-history"></i></div>
                    <div class="stat-value text-warning"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'links' ? 'active' : '' ?>" href="?tab=links"><i class="bi bi-link-45deg me-1"></i> Invite Links</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'registrations' ? 'active' : '' ?>" href="?tab=registrations">
                    <i class="bi bi-person-plus me-1"></i> Registrations
                    <?php if ($pending_count > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $pending_count ?></span><?php endif; ?>
                </a>
            </li>
        </ul>

        <?php if ($tab === 'links'): ?>
        <!-- Invite Links Tab -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createInviteModal">
                <i class="bi bi-plus-circle me-1"></i> Create Invite Link
            </button>
            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkModal">
                <i class="bi bi-files me-1"></i> Bulk Create
            </button>
        </div>

        <!-- Filter pills -->
        <ul class="nav nav-pills mb-3">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'used' => 'Used', 'inactive' => 'Inactive', 'expired' => 'Expired'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="?tab=links&filter=<?= $key ?>"><?= $label ?></a>
            </li>
            <?php endforeach; ?>
        </ul>

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
            $url = getLecturerInviteUrl($inv['token']);
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
                        <?php if ($inv['department']): ?><div><i class="bi bi-building me-1"></i><?= htmlspecialchars($inv['department']) ?></div><?php endif; ?>
                        <?php if ($inv['position']): ?><div><i class="bi bi-briefcase me-1"></i><?= htmlspecialchars($inv['position']) ?></div><?php endif; ?>
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
                        <button class="btn btn-sm btn-outline-success" onclick="copyLink('<?= htmlspecialchars($url) ?>')" title="Copy"><i class="bi bi-clipboard"></i></button>
                        <?php if ($inv['is_active']): ?>
                        <form method="POST" class="d-inline"><input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="deactivate_invite" class="btn btn-sm btn-outline-warning" title="Deactivate"><i class="bi bi-pause-circle"></i></button>
                        </form>
                        <?php else: ?>
                        <form method="POST" class="d-inline"><input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="activate_invite" class="btn btn-sm btn-outline-success" title="Reactivate"><i class="bi bi-play-circle"></i></button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this invite link?')"><input type="hidden" name="invite_id" value="<?= $inv['invite_id'] ?>">
                            <button type="submit" name="delete_invite" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php elseif ($tab === 'registrations'): ?>
        <!-- Registrations Tab -->
        <ul class="nav nav-pills mb-3">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $reg_filter === $key ? 'active' : '' ?>" href="?tab=registrations&reg_filter=<?= $key ?>"><?= $label ?></a>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if (empty($pending_regs)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size:3rem;color:#cbd5e1;"></i>
            <p class="text-muted mt-2">No registrations found.</p>
        </div>
        <?php else: ?>
        <?php foreach ($pending_regs as $reg): ?>
        <div class="reg-card">
            <div class="row g-3">
                <div class="col-md-4">
                    <h6 class="fw-bold mb-1"><?= htmlspecialchars($reg['first_name'] . ' ' . ($reg['middle_name'] ? $reg['middle_name'] . ' ' : '') . $reg['last_name']) ?></h6>
                    <div style="font-size:0.85rem;color:#64748b;">
                        <div><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($reg['email']) ?></div>
                        <?php if ($reg['phone']): ?><div><i class="bi bi-phone me-1"></i><?= htmlspecialchars($reg['phone']) ?></div><?php endif; ?>
                        <?php if ($reg['gender']): ?><div><i class="bi bi-person me-1"></i><?= htmlspecialchars($reg['gender']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div style="font-size:0.85rem;">
                        <?php if ($reg['department']): ?><div><i class="bi bi-building me-1 text-success"></i><strong>Dept:</strong> <?= htmlspecialchars($reg['department']) ?></div><?php endif; ?>
                        <?php if ($reg['position']): ?><div><i class="bi bi-briefcase me-1 text-success"></i><strong>Position:</strong> <?= htmlspecialchars($reg['position']) ?></div><?php endif; ?>
                        <?php if ($reg['qualification']): ?><div><i class="bi bi-award me-1 text-success"></i><strong>Qualification:</strong> <?= htmlspecialchars($reg['qualification']) ?></div><?php endif; ?>
                        <?php if ($reg['specialization']): ?><div><i class="bi bi-star me-1 text-success"></i><strong>Specialization:</strong> <?= htmlspecialchars($reg['specialization']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <?php if ($reg['selected_modules']): 
                        $modules = json_decode($reg['selected_modules'], true);
                        if (is_array($modules) && count($modules) > 0):
                            $mod_ids = implode(',', array_map('intval', $modules));
                            $mods = $conn->query("SELECT course_code, course_name FROM vle_courses WHERE course_id IN ($mod_ids)");
                    ?>
                    <div style="font-size:0.8rem;"><strong><i class="bi bi-book me-1"></i>Selected Modules (<?= count($modules) ?>):</strong></div>
                    <div class="mt-1">
                        <?php if ($mods): while ($m = $mods->fetch_assoc()): ?>
                        <span class="module-badge"><?= htmlspecialchars($m['course_code']) ?> - <?= htmlspecialchars($m['course_name']) ?></span>
                        <?php endwhile; endif; ?>
                    </div>
                    <?php endif; endif; ?>
                    <div class="mt-2" style="font-size:0.75rem;color:#94a3b8;">
                        Registered: <?= date('M j, Y g:i A', strtotime($reg['registered_at'])) ?>
                        <?php if ($reg['status'] === 'pending'): ?>
                        <span class="badge bg-warning text-dark ms-1">Pending</span>
                        <?php elseif ($reg['status'] === 'approved'): ?>
                        <span class="badge bg-success ms-1">Approved</span>
                        <?php else: ?>
                        <span class="badge bg-danger ms-1">Rejected</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($reg['status'] === 'pending'): ?>
            <hr class="my-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <form method="POST" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1">
                    <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                    <input type="text" name="admin_notes" class="form-control form-control-sm" placeholder="Admin notes (optional)" style="max-width:300px;">
                    <button type="submit" name="approve_registration" class="btn btn-sm btn-success" onclick="return confirm('Approve this lecturer? Their account will be created and modules allocated.')">
                        <i class="bi bi-check-circle me-1"></i>Approve
                    </button>
                    <button type="submit" name="reject_registration" class="btn btn-sm btn-danger" onclick="return confirm('Reject this registration?')">
                        <i class="bi bi-x-circle me-1"></i>Reject
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Create Invite Modal -->
    <div class="modal fade" id="createInviteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Create Lecturer Invite Link</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p class="text-muted mb-3" style="font-size:0.85rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Create a link to send to a lecturer. They will fill out a registration form, select modules to teach (max 7), and an admin will review before activating.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Lecturer Name <small class="text-muted">(optional)</small></label>
                                <input type="text" name="invite_name" class="form-control" placeholder="Pre-fill name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Lecturer Email <small class="text-muted">(optional)</small></label>
                                <input type="email" name="invite_email" class="form-control" placeholder="lecturer@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Department</label>
                                <input type="text" name="invite_department" class="form-control" list="deptList" placeholder="e.g. Computer Science">
                                <datalist id="deptList">
                                    <?php foreach ($departments as $d): ?>
                                    <option value="<?= htmlspecialchars($d) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Position</label>
                                <select name="invite_position" class="form-select">
                                    <option value="">Any Position</option>
                                    <option value="Lecturer">Lecturer</option>
                                    <option value="Senior Lecturer">Senior Lecturer</option>
                                    <option value="Associate Professor">Associate Professor</option>
                                    <option value="Professor">Professor</option>
                                    <option value="Part-time Lecturer">Part-time Lecturer</option>
                                    <option value="Teaching Assistant">Teaching Assistant</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Max Uses</label>
                                <input type="number" name="invite_max_uses" class="form-control" value="1" min="0" max="1000">
                                <small class="text-muted">0 = unlimited</small>
                            </div>
                            <div class="col-md-4">
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
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="send_email" id="sendEmail" class="form-check-input" checked>
                                    <label class="form-check-label" for="sendEmail">
                                        <i class="bi bi-envelope me-1"></i>Send via email
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea name="invite_notes" class="form-control" rows="2" placeholder="Internal notes (not shown to lecturer)"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_invite" class="btn btn-success">
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
                <div class="modal-header" style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:16px 16px 0 0;">
                    <h5 class="modal-title"><i class="bi bi-files me-2"></i>Bulk Create Lecturer Invites</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Number of Links</label>
                                <input type="number" name="bulk_count" class="form-control" value="5" min="1" max="100">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Max Uses Each</label>
                                <input type="number" name="bulk_max_uses" class="form-control" value="1" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Department</label>
                                <input type="text" name="bulk_department" class="form-control" list="deptList" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Position</label>
                                <select name="bulk_position" class="form-select">
                                    <option value="">Any</option>
                                    <option value="Lecturer">Lecturer</option>
                                    <option value="Senior Lecturer">Senior Lecturer</option>
                                    <option value="Part-time Lecturer">Part-time Lecturer</option>
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
                        <button type="submit" name="create_bulk" class="btn btn-success"><i class="bi bi-files me-1"></i> Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="copy-toast" id="copyToast"><i class="bi bi-check-circle me-2"></i>Link copied!</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function copyLink(url) {
        navigator.clipboard.writeText(url).then(function() {
            const t = document.getElementById('copyToast');
            t.style.display = 'block';
            setTimeout(() => t.style.display = 'none', 2500);
        }).catch(function() {
            const ta = document.createElement('textarea');
            ta.value = url; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            const t = document.getElementById('copyToast');
            t.style.display = 'block';
            setTimeout(() => t.style.display = 'none', 2500);
        });
    }
    </script>
</body>
</html>
