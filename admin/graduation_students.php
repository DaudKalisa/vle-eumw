<?php
/**
 * Admin – Graduation Students & Clearance Management
 * View all graduation students, approve registrations, track clearance progress
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Ensure tables
$conn->query("CREATE TABLE IF NOT EXISTS graduation_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, student_id_number VARCHAR(50) DEFAULT NULL,
    first_name VARCHAR(100) NOT NULL, middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL, gender VARCHAR(10) DEFAULT NULL,
    national_id VARCHAR(30) DEFAULT NULL, address TEXT DEFAULT NULL,
    campus VARCHAR(100) DEFAULT 'Blantyre Campus', program VARCHAR(200) DEFAULT NULL,
    department_id INT DEFAULT NULL, year_of_entry YEAR DEFAULT NULL,
    year_of_completion YEAR DEFAULT NULL,
    transcript_processed_before TINYINT(1) DEFAULT 0,
    transcript_processed_date DATE DEFAULT NULL,
    application_type ENUM('clearance','transcript') DEFAULT 'clearance',
    status ENUM('pending','finance_approved','finance_referred','ict_approved',
                'dean_approved','rc_approved','librarian_approved','admin_generated',
                'registrar_approved','admissions_filed','completed','rejected') DEFAULT 'pending',
    current_step VARCHAR(50) DEFAULT 'finance',
    rejection_reason TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS graduation_clearance_steps (
    step_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL, step_name VARCHAR(50) NOT NULL,
    officer_user_id INT DEFAULT NULL, officer_name VARCHAR(200) DEFAULT NULL,
    officer_role VARCHAR(100) DEFAULT NULL, officer_title VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','approved','rejected','referred') DEFAULT 'pending',
    notes TEXT DEFAULT NULL, step_data TEXT DEFAULT NULL,
    signature_text VARCHAR(255) DEFAULT NULL, actioned_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_app_step (application_id, step_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST actions ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Approve graduation registration -> create user + graduation_application
    if ($action === 'approve_registration') {
        $reg_id = (int)$_POST['reg_id'];
        $rs = $conn->prepare("SELECT * FROM student_invite_registrations WHERE registration_id = ? AND student_type = 'graduation_student'");
        $rs->bind_param("i", $reg_id);
        $rs->execute();
        $reg = $rs->get_result()->fetch_assoc();

        if ($reg && $reg['status'] === 'pending') {
            $conn->begin_transaction();
            try {
                $grad_data = json_decode($reg['selected_modules'] ?? '{}', true) ?: [];

                $username = $grad_data['username'] ?? strtolower($reg['first_name'] . '.' . $reg['last_name']);
                $hash     = $grad_data['password_hash'] ?? password_hash('changeme123', PASSWORD_DEFAULT);
                $full_name = trim($reg['first_name'] . ' ' . ($reg['middle_name'] ?? '') . ' ' . $reg['last_name']);
                $role = 'student';
                $additional_roles = 'graduation_student';
                $must_change = 0;

                // Create user
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, email, role, additional_roles, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, 1, ?)");
                $stmt->bind_param("sssssi", $username, $hash, $reg['email'], $role, $additional_roles, $must_change);
                $stmt->execute();
                $new_uid = $conn->insert_id;

                // Create graduation application
                $yr_entry = $grad_data['year_of_entry'] ?? null;
                $yr_comp  = $grad_data['year_of_completion'] ?? null;
                $tran_bef = $grad_data['transcript_processed_before'] ?? 0;
                $tran_dt  = $grad_data['transcript_processed_date'] ?? null;
                $app_type = $grad_data['application_type'] ?? 'clearance';

                $stmt = $conn->prepare("INSERT INTO graduation_applications 
                    (user_id, student_id_number, first_name, middle_name, last_name, email, phone, gender, national_id, address,
                     campus, program, department_id, year_of_entry, year_of_completion,
                     transcript_processed_before, transcript_processed_date, application_type, status, current_step)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'finance')");
                $stmt->bind_param("isssssssssssississ",
                    $new_uid, $reg['student_id_number'], $reg['first_name'], $reg['middle_name'], $reg['last_name'],
                    $reg['email'], $reg['phone'], $reg['gender'], $reg['national_id'], $reg['address'],
                    $reg['campus'], $reg['program'], $reg['department_id'],
                    $yr_entry, $yr_comp, $tran_bef, $tran_dt, $app_type);
                $stmt->execute();
                $app_id = $conn->insert_id;

                // Initialize clearance steps
                $steps = ($app_type === 'transcript')
                    ? ['finance', 'ict', 'dean', 'registrar']
                    : ['finance', 'ict', 'dean', 'rc', 'librarian', 'admin', 'registrar', 'admissions'];
                foreach ($steps as $step) {
                    $conn->query("INSERT INTO graduation_clearance_steps (application_id, step_name, status) VALUES ($app_id, '$step', 'pending')");
                }

                // Mark registration approved
                $admin_id = (int)$user['user_id'];
                $stmt = $conn->prepare("UPDATE student_invite_registrations SET status='approved', reviewed_by=?, reviewed_at=NOW(), user_id=? WHERE registration_id=?");
                $stmt->bind_param("iii", $admin_id, $new_uid, $reg_id);
                $stmt->execute();

                $conn->commit();
                $success = "Approved: <strong>$full_name</strong> — Account created (username: $username). Application #$app_id started.";
            } catch (\Throwable $e) {
                $conn->rollback();
                $error = 'Approval failed: ' . $e->getMessage();
            }
        } else {
            $error = 'Registration not found or already processed.';
        }
    }

    // Reject registration
    if ($action === 'reject_registration') {
        $reg_id = (int)$_POST['reg_id'];
        $notes  = trim($_POST['admin_notes'] ?? 'Rejected.');
        $admin_id = (int)$user['user_id'];
        $stmt = $conn->prepare("UPDATE student_invite_registrations SET status='rejected', reviewed_by=?, reviewed_at=NOW(), admin_notes=? WHERE registration_id=?");
        $stmt->bind_param("isi", $admin_id, $notes, $reg_id);
        $stmt->execute();
        $success = 'Registration rejected.';
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';

// Pending registrations
$pending_regs = [];
$pr = $conn->query("SELECT * FROM student_invite_registrations WHERE student_type='graduation_student' AND status='pending' ORDER BY registered_at DESC");
if ($pr) while ($r = $pr->fetch_assoc()) $pending_regs[] = $r;

// Graduation applications
$where_app = '';
if ($filter === 'clearance') $where_app = "AND ga.application_type = 'clearance'";
elseif ($filter === 'transcript') $where_app = "AND ga.application_type = 'transcript'";
elseif ($filter === 'completed') $where_app = "AND ga.status = 'completed'";
elseif ($filter === 'pending') $where_app = "AND ga.status = 'pending'";
elseif ($filter === 'in_progress') $where_app = "AND ga.status NOT IN ('pending','completed','rejected')";

$campus_filter = '';
if (!empty($_GET['campus'])) {
    $cf = $conn->real_escape_string($_GET['campus']);
    $campus_filter = "AND ga.campus = '$cf'";
}

$applications = [];
$ar = $conn->query("SELECT ga.*, u.username,
        (SELECT GROUP_CONCAT(CONCAT(cs.step_name, ':', cs.status) SEPARATOR ',') 
         FROM graduation_clearance_steps cs WHERE cs.application_id = ga.application_id) as steps_summary
    FROM graduation_applications ga
    LEFT JOIN users u ON ga.user_id = u.user_id
    WHERE 1=1 $where_app $campus_filter
    ORDER BY ga.submitted_at DESC");
if ($ar) while ($a = $ar->fetch_assoc()) $applications[] = $a;

// Stats
$stats = [];
$r = $conn->query("SELECT COUNT(*) as c FROM graduation_applications");
$stats['total'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
$r = $conn->query("SELECT COUNT(*) as c FROM graduation_applications WHERE status='completed'");
$stats['completed'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
$r = $conn->query("SELECT COUNT(*) as c FROM graduation_applications WHERE status='pending'");
$stats['pending'] = $r ? (int)$r->fetch_assoc()['c'] : 0;
$r = $conn->query("SELECT COUNT(*) as c FROM graduation_applications WHERE status NOT IN ('pending','completed','rejected')");
$stats['in_progress'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

$step_labels = [
    'finance' => ['Finance Check', 'bi-cash-coin', '#f59e0b'],
    'ict'     => ['ICT / Transcript', 'bi-pc-display', '#3b82f6'],
    'dean'    => ['Dean Review', 'bi-award', '#8b5cf6'],
    'rc'      => ['Research Coord.', 'bi-journal-bookmark', '#059669'],
    'librarian' => ['Library Clear', 'bi-book', '#0891b2'],
    'admin'   => ['Admin Transcript', 'bi-file-earmark-text', '#6366f1'],
    'registrar' => ['Registrar Approval', 'bi-check2-circle', '#e11d48'],
    'admissions' => ['Admissions Filing', 'bi-folder-check', '#9333ea'],
];

$page_title = 'Graduation Students';
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
        .stat-card{background:#fff;border-radius:12px;padding:1.25rem;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05);}
        .stat-card .stat-num{font-size:2rem;font-weight:700;}
        .stat-card .stat-label{font-size:.8rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
        .app-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.25rem;margin-bottom:.75rem;transition:box-shadow .2s;}
        .app-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.10);}
        .step-badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;padding:3px 8px;border-radius:50px;font-weight:600;margin:2px;}
        .step-pending{background:#fef3c7;color:#92400e;}
        .step-approved{background:#d1fae5;color:#065f46;}
        .step-rejected{background:#fee2e2;color:#991b1b;}
        .step-referred{background:#dbeafe;color:#1e40af;}
        .pending-reg{background:#fff;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:12px;padding:1rem;margin-bottom:.75rem;}
        .filter-tabs .nav-link{font-weight:500;color:#64748b;}
        .filter-tabs .nav-link.active{color:#059669;border-bottom:3px solid #059669;font-weight:600;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" class="brand"><i class="bi bi-arrow-left"></i> Admin Dashboard</a>
    <div>
        <a href="graduation_invite_links.php" class="btn btn-sm btn-outline-success me-2"><i class="bi bi-link-45deg me-1"></i>Invite Links</a>
        <a href="graduation_report.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-bar-graph me-1"></i>Graduation Report</a>
    </div>
</div>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1300px;">
    <div class="page-header">
        <h4 class="mb-1"><i class="bi bi-mortarboard-fill me-2"></i><?= $page_title ?></h4>
        <p class="mb-0 opacity-75">Manage graduation clearance applications and student accounts</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num text-primary"><?= $stats['total'] ?></div><div class="stat-label">Total Applications</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num text-warning"><?= $stats['pending'] ?></div><div class="stat-label">Pending</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num text-info"><?= $stats['in_progress'] ?></div><div class="stat-label">In Progress</div></div></div>
        <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num text-success"><?= $stats['completed'] ?></div><div class="stat-label">Completed</div></div></div>
    </div>

    <!-- Pending Registrations -->
    <?php if (!empty($pending_regs)): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning bg-opacity-10"><h5 class="mb-0"><i class="bi bi-clock-fill me-2 text-warning"></i>Pending Graduation Registrations (<?= count($pending_regs) ?>)</h5></div>
        <div class="card-body">
            <?php foreach ($pending_regs as $reg): 
                $gd = json_decode($reg['selected_modules'] ?? '{}', true) ?: [];
            ?>
            <div class="pending-reg">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1"><?= htmlspecialchars($reg['first_name'] . ' ' . ($reg['middle_name'] ?? '') . ' ' . $reg['last_name']) ?></h6>
                        <div class="small text-muted">
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($reg['email']) ?>
                            <?php if($reg['phone']): ?> &nbsp;|&nbsp; <i class="bi bi-phone me-1"></i><?= htmlspecialchars($reg['phone']) ?><?php endif; ?>
                            <?php if($reg['campus']): ?> &nbsp;|&nbsp; <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($reg['campus']) ?><?php endif; ?>
                            <?php if($reg['program']): ?> &nbsp;|&nbsp; <i class="bi bi-mortarboard me-1"></i><?= htmlspecialchars($reg['program']) ?><?php endif; ?>
                        </div>
                        <div class="small text-muted mt-1">
                            Entry: <?= $gd['year_of_entry'] ?? 'N/A' ?> | Completion: <?= $gd['year_of_completion'] ?? 'N/A' ?>
                            | Type: <strong><?= ucfirst($gd['application_type'] ?? 'clearance') ?></strong>
                            | Registered: <?= date('M d, Y H:i', strtotime($reg['registered_at'])) ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="post"><input type="hidden" name="action" value="approve_registration"><input type="hidden" name="reg_id" value="<?= $reg['registration_id'] ?>"><button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve</button></form>
                        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $reg['registration_id'] ?>"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
            </div>
            <!-- Reject Modal -->
            <div class="modal fade" id="rejectModal<?= $reg['registration_id'] ?>"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Reject Registration</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="action" value="reject_registration"><input type="hidden" name="reg_id" value="<?= $reg['registration_id'] ?>"><label class="form-label">Reason</label><textarea name="admin_notes" class="form-control" rows="3" required></textarea></div><div class="modal-footer"><button type="submit" class="btn btn-danger">Reject</button></div></form></div></div></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <ul class="nav filter-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
        <li class="nav-item"><a class="nav-link <?= $filter==='pending'?'active':'' ?>" href="?filter=pending">Pending</a></li>
        <li class="nav-item"><a class="nav-link <?= $filter==='in_progress'?'active':'' ?>" href="?filter=in_progress">In Progress</a></li>
        <li class="nav-item"><a class="nav-link <?= $filter==='clearance'?'active':'' ?>" href="?filter=clearance">Clearance</a></li>
        <li class="nav-item"><a class="nav-link <?= $filter==='transcript'?'active':'' ?>" href="?filter=transcript">Transcript</a></li>
        <li class="nav-item"><a class="nav-link <?= $filter==='completed'?'active':'' ?>" href="?filter=completed">Completed</a></li>
        <li class="nav-item ms-auto">
            <select class="form-select form-select-sm" onchange="location.href='?filter=<?= $filter ?>&campus='+this.value" style="width:180px;">
                <option value="">All Campuses</option>
                <option value="Blantyre Campus" <?= ($_GET['campus']??'')==='Blantyre Campus'?'selected':'' ?>>Blantyre</option>
                <option value="Lilongwe Campus" <?= ($_GET['campus']??'')==='Lilongwe Campus'?'selected':'' ?>>Lilongwe</option>
                <option value="Mzuzu Campus" <?= ($_GET['campus']??'')==='Mzuzu Campus'?'selected':'' ?>>Mzuzu</option>
            </select>
        </li>
    </ul>

    <!-- Applications -->
    <?php if (empty($applications)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-mortarboard fs-1 d-block mb-2"></i>No graduation applications yet.</div>
    <?php else: ?>
    <?php foreach ($applications as $app):
        // Parse steps
        $steps_map = [];
        if ($app['steps_summary']) {
            foreach (explode(',', $app['steps_summary']) as $s) {
                [$sn, $ss] = explode(':', $s) + [null, null];
                if ($sn) $steps_map[$sn] = $ss;
            }
        }
        $status_colors = ['pending'=>'warning','completed'=>'success','rejected'=>'danger',
            'finance_approved'=>'info','finance_referred'=>'warning','ict_approved'=>'info',
            'dean_approved'=>'info','rc_approved'=>'info','librarian_approved'=>'info',
            'admin_generated'=>'info','registrar_approved'=>'info','admissions_filed'=>'info'];
    ?>
    <div class="app-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div style="flex:1;min-width:250px;">
                <h6 class="mb-1">
                    <?= htmlspecialchars($app['first_name'] . ' ' . ($app['middle_name'] ?? '') . ' ' . $app['last_name']) ?>
                    <span class="badge bg-<?= $status_colors[$app['status']] ?? 'secondary' ?> ms-1"><?= ucfirst(str_replace('_',' ',$app['status'])) ?></span>
                    <span class="badge bg-<?= $app['application_type']==='clearance'?'success':'primary' ?> ms-1"><?= ucfirst($app['application_type']) ?></span>
                </h6>
                <div class="small text-muted">
                    #<?= $app['application_id'] ?> | <?= htmlspecialchars($app['email']) ?>
                    | <?= htmlspecialchars($app['campus'] ?? 'N/A') ?>
                    | <?= htmlspecialchars($app['program'] ?? 'N/A') ?>
                    | Entry: <?= $app['year_of_entry'] ?? 'N/A' ?> → <?= $app['year_of_completion'] ?? 'N/A' ?>
                </div>
                <div class="mt-2">
                    <?php foreach ($step_labels as $sn => $sl):
                        if (!isset($steps_map[$sn])) continue;
                        $ss = $steps_map[$sn];
                    ?>
                    <span class="step-badge step-<?= $ss ?>">
                        <i class="bi <?= $sl[1] ?>"></i><?= $sl[0] ?>
                        <?php if($ss==='approved'): ?><i class="bi bi-check-circle-fill ms-1"></i><?php elseif($ss==='rejected'): ?><i class="bi bi-x-circle-fill ms-1"></i><?php elseif($ss==='referred'): ?><i class="bi bi-arrow-return-right ms-1"></i><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="text-end small text-muted">
                <div><?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
                <div class="mt-1">Current: <strong class="text-primary"><?= ucfirst($app['current_step']) ?></strong></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
