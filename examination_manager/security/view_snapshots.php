<?php
// examination_manager/security/view_snapshots.php - View camera snapshots
require_once '../../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Filter parameters
$exam_filter = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$student_filter = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';
$date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';

// Build query with filters
$where = "em.event_type = 'camera_snapshot' AND em.snapshot_path IS NOT NULL";
$params = [];
$types = '';

if ($exam_filter > 0) {
    $where .= " AND e.exam_id = ?";
    $params[] = $exam_filter;
    $types .= 'i';
}
if ($student_filter) {
    $where .= " AND s.student_id = ?";
    $params[] = $student_filter;
    $types .= 's';
}
if ($date_filter) {
    $where .= " AND DATE(em.timestamp) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

$query = "
    SELECT
        em.monitoring_id, em.session_id, em.event_type, em.timestamp,
        em.snapshot_path, em.ip_address,
        s.full_name as student_name,
        s.student_id as student_number,
        e.exam_name as exam_title,
        e.exam_id
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    WHERE $where
    ORDER BY em.timestamp DESC
    LIMIT 100
";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}
$snapshots = $result->fetch_all(MYSQLI_ASSOC);

// Get exams for filter dropdown
$exams = [];
$exResult = $conn->query("SELECT exam_id, exam_name FROM exams ORDER BY exam_name ASC");
if ($exResult) { while ($r = $exResult->fetch_assoc()) $exams[] = $r; }

// Group snapshots by student for summary
$studentGroups = [];
foreach ($snapshots as $snap) {
    $sid = $snap['student_number'];
    if (!isset($studentGroups[$sid])) {
        $studentGroups[$sid] = ['name' => $snap['student_name'], 'count' => 0];
    }
    $studentGroups[$sid]['count']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Camera Snapshots - VLE Exam Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../../includes/pwa-head.php'; ?>
    <style>
        :root {
            --exam-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --snapshot-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }

        /* Page Header */
        .page-header-card {
            background: var(--snapshot-gradient);
            border-radius: 24px; padding: 2rem; color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(16, 185, 129, 0.3);
        }
        .page-header-card .header-content { display: flex; align-items: center; gap: 1.25rem; }
        .page-header-card .header-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.75rem; border: 4px solid rgba(255,255,255,0.4); flex-shrink: 0;
        }
        .page-header-card h2 { font-size: 1.75rem; font-weight: 700; margin: 0; }
        .page-header-card p { opacity: 0.9; margin: 0.25rem 0 0; }
        .page-header-card .header-stats {
            display: flex; gap: 2rem; margin-top: 1rem;
            flex-wrap: wrap;
        }
        .page-header-card .header-stat { text-align: center; }
        .page-header-card .header-stat-value { font-size: 1.5rem; font-weight: 700; display: block; }
        .page-header-card .header-stat-label { font-size: 0.8rem; opacity: 0.85; }
        @media (min-width: 992px) { .page-header-card h2 { font-size: 2rem; } }

        /* Filter Bar */
        .filter-bar {
            background: white; border-radius: 16px; padding: 1.25rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 1.5rem;
        }
        .filter-bar label { font-weight: 500; color: #334155; font-size: 0.85rem; }
        .filter-bar .form-control, .filter-bar .form-select {
            border-radius: 10px; border-color: #e2e8f0; font-size: 0.9rem;
        }
        .filter-bar .form-control:focus, .filter-bar .form-select:focus {
            border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
        }

        /* Snapshot Grid */
        .snapshot-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 1rem; }
        @media (min-width: 576px) { .snapshot-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 992px) { .snapshot-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1400px) { .snapshot-grid { grid-template-columns: repeat(4, 1fr); } }

        .snapshot-card {
            background: white; border-radius: 16px; overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
        }
        .snapshot-card:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .snapshot-card .snapshot-image {
            width: 100%; height: 200px; object-fit: cover;
            cursor: pointer; transition: opacity 0.2s;
            background: #f1f5f9;
        }
        .snapshot-card .snapshot-image:hover { opacity: 0.9; }
        .snapshot-card .snapshot-placeholder {
            width: 100%; height: 200px; display: flex;
            align-items: center; justify-content: center;
            background: #f8fafc; color: #94a3b8;
            flex-direction: column; gap: 0.5rem;
        }
        .snapshot-card .snapshot-info { padding: 1rem; }
        .snapshot-card .student-name { font-weight: 600; color: #1e293b; font-size: 0.9rem; margin-bottom: 0.25rem; }
        .snapshot-card .snapshot-meta {
            display: flex; flex-direction: column; gap: 0.25rem;
            font-size: 0.78rem; color: #64748b;
        }
        .snapshot-card .snapshot-meta i { width: 16px; text-align: center; margin-right: 4px; }
        .snapshot-card .snapshot-footer {
            padding: 0.75rem 1rem; background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            display: flex; justify-content: space-between; align-items: center;
        }
        .snapshot-card .time-badge {
            font-size: 0.72rem; padding: 3px 8px;
            background: #ecfdf5; color: #059669;
            border-radius: 20px; font-weight: 500;
        }
        .snapshot-card .ip-badge {
            font-size: 0.72rem; color: #94a3b8;
        }

        /* Section Headers */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .section-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }

        /* Empty State */
        .empty-state {
            background: white; border-radius: 16px; padding: 3rem 2rem;
            text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .empty-state i { font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; display: block; }
        .empty-state h5 { font-weight: 600; color: #64748b; }
        .empty-state p { color: #94a3b8; }

        /* Footer Info */
        .admin-footer-info {
            background: white; border-radius: 16px; padding: 1rem 1.25rem;
            margin-top: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .info-grid { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center; }
        .info-item { text-align: center; min-width: 120px; }
        .info-item strong { display: block; font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .info-item span { font-size: 0.85rem; color: #475569; }

        /* Wrapper & Nav */
        .page-wrapper { padding: 1rem; padding-bottom: 100px; }
        @media (min-width: 768px) { .page-wrapper { padding: 2rem; padding-bottom: 2rem; } }
        @media (min-width: 768px) { .exam-mobile-header, .exam-bottom-nav { display: none !important; } }
        @media (max-width: 767.98px) { .exam-desktop-nav { display: none !important; } }

        .exam-mobile-header {
            background: var(--exam-gradient); padding: 1rem;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 100;
        }
        .exam-mobile-header .logo-section { display: flex; align-items: center; gap: 0.5rem; color: white; font-weight: 700; }
        .exam-mobile-header .logo-section img { height: 30px; width: auto; }
        .exam-mobile-header .header-actions { display: flex; gap: 0.5rem; }
        .exam-mobile-header .header-btn {
            background: rgba(255,255,255,0.15); border: none; color: white;
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
        }

        .exam-desktop-nav {
            background: white; border-bottom: 1px solid #e2e8f0;
            padding: 0.5rem 2rem; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }
        .exam-desktop-nav .nav-container {
            display: flex; align-items: center; justify-content: space-between;
            max-width: 1600px; margin: 0 auto;
        }
        .exam-desktop-nav .nav-brand { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: #1e293b; }
        .exam-desktop-nav .nav-brand img { height: 38px; }
        .exam-desktop-nav .nav-brand span { font-weight: 700; font-size: 1.1rem; }
        .exam-desktop-nav .nav-menu { display: flex; list-style: none; margin: 0; padding: 0; gap: 0.25rem; }
        .exam-desktop-nav .nav-link {
            text-decoration: none; color: #64748b; padding: 0.6rem 1rem;
            border-radius: 10px; font-weight: 500; font-size: 0.9rem;
            transition: all 0.2s; display: flex; align-items: center; gap: 0.4rem;
        }
        .exam-desktop-nav .nav-link:hover, .exam-desktop-nav .nav-link.active { background: #fef3c7; color: #92400e; }
        .exam-desktop-nav .nav-right { display: flex; align-items: center; gap: 1rem; }
        .exam-desktop-nav .nav-user {
            display: flex; align-items: center; gap: 0.5rem; cursor: pointer;
            padding: 0.4rem 0.75rem; border-radius: 10px; transition: background 0.2s;
        }
        .exam-desktop-nav .nav-user:hover { background: #f8fafc; }
        .exam-desktop-nav .nav-user-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--exam-gradient);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 0.95rem;
        }
        .exam-desktop-nav .nav-user-name { font-weight: 500; color: #1e293b; font-size: 0.9rem; }
        .admin-dropdown { position: relative; }
        .admin-dropdown-menu {
            display: none; position: absolute; top: 100%; right: 0;
            background: white; border-radius: 12px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            min-width: 200px; padding: 0.5rem 0; z-index: 1000;
        }
        .admin-dropdown:hover .admin-dropdown-menu { display: block; }
        .admin-dropdown-menu a {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.6rem 1rem; text-decoration: none; color: #475569;
            font-size: 0.9rem; transition: background 0.2s;
        }
        .admin-dropdown-menu a:hover { background: #f8fafc; }
        .admin-dropdown-menu hr { margin: 0.25rem 0; border-color: #e2e8f0; }

        .exam-bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: white; display: flex; justify-content: space-around;
            padding: 0.5rem 0; box-shadow: 0 -2px 15px rgba(0,0,0,0.08);
            z-index: 1000; border-top: 1px solid #e2e8f0;
        }
        .exam-bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center;
            text-decoration: none; color: #94a3b8; font-size: 0.65rem;
            font-weight: 500; padding: 0.25rem 0.5rem; border-radius: 8px; transition: all 0.2s;
        }
        .exam-bottom-nav .nav-item i { font-size: 1.25rem; margin-bottom: 0.15rem; }
        .exam-bottom-nav .nav-item.active { color: #d97706; }

        /* Modal */
        .modal-content { border-radius: 16px; overflow: hidden; }
        .modal-header { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-bottom: 1px solid #e2e8f0; }
        .modal-header .modal-title { font-weight: 600; font-size: 1rem; }
        .modal-body img { border-radius: 8px; }
        .modal-info { padding: 1rem; background: #f8fafc; border-radius: 12px; margin-top: 1rem; }
        .modal-info .info-row { display: flex; justify-content: space-between; padding: 0.4rem 0; font-size: 0.85rem; }
        .modal-info .info-row:not(:last-child) { border-bottom: 1px solid #e2e8f0; }
        .modal-info .info-label { color: #64748b; font-weight: 500; }
        .modal-info .info-value { color: #1e293b; font-weight: 600; }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../../assets/img/Logo.png" alt="VLE Logo">
            <span>Snapshots</span>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="location.href='index.php'">
                <i class="bi bi-shield-lock"></i>
            </button>
            <button class="header-btn" onclick="location.href='../dashboard.php'">
                <i class="bi bi-speedometer2"></i>
            </button>
        </div>
    </header>

    <!-- Desktop Navigation -->
    <nav class="exam-desktop-nav">
        <div class="nav-container">
            <a href="../dashboard.php" class="nav-brand">
                <img src="../../assets/img/Logo.png" alt="VLE Logo">
                <span>VLE Exam Manager</span>
            </a>
            <ul class="nav-menu">
                <li><a href="../dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li><a href="../create_exam.php" class="nav-link"><i class="bi bi-plus-circle"></i> Create Exam</a></li>
                <li><a href="../generate_tokens.php" class="nav-link"><i class="bi bi-key"></i> Tokens</a></li>
                <li><a href="../security_monitoring.php" class="nav-link"><i class="bi bi-shield-check"></i> Monitoring</a></li>
                <li><a href="../semester_reports.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></li>
                <li><a href="index.php" class="nav-link active"><i class="bi bi-lock"></i> Security</a></li>
            </ul>
            <div class="nav-right">
                <div class="admin-dropdown">
                    <div class="nav-user">
                        <div class="nav-user-avatar"><?= strtoupper(substr($user['display_name'] ?? 'E', 0, 1)) ?></div>
                        <span class="nav-user-name"><?= htmlspecialchars($user['display_name'] ?? 'Manager') ?></span>
                        <i class="bi bi-chevron-down" style="font-size:0.7rem;color:#94a3b8;"></i>
                    </div>
                    <div class="admin-dropdown-menu">
                        <a href="../../change_password.php"><i class="bi bi-key"></i> Change Password</a>
                        <hr>
                        <a href="../../logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="page-wrapper">
        <!-- Page Header -->
        <div class="page-header-card">
            <div class="header-content">
                <div class="header-icon">
                    <i class="bi bi-camera-video-fill"></i>
                </div>
                <div>
                    <h2>Camera Snapshots</h2>
                    <p>Review camera captures taken during exam sessions</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="header-stat">
                    <span class="header-stat-value"><?= count($snapshots) ?></span>
                    <span class="header-stat-label">Total Snapshots</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-value"><?= count($studentGroups) ?></span>
                    <span class="header-stat-label">Students</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-value"><?= count(array_unique(array_column($snapshots, 'exam_id'))) ?></span>
                    <span class="header-stat-label">Exams</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="exam_id" class="form-label">Filter by Exam</label>
                    <select name="exam_id" id="exam_id" class="form-select">
                        <option value="">All Exams</option>
                        <?php foreach ($exams as $ex): ?>
                        <option value="<?= $ex['exam_id'] ?>" <?= $exam_filter == $ex['exam_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ex['exam_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="student_id" class="form-label">Student ID</label>
                    <input type="text" name="student_id" id="student_id" class="form-control"
                           placeholder="e.g. STU001" value="<?= htmlspecialchars($student_filter) ?>">
                </div>
                <div class="col-md-3">
                    <label for="date" class="form-label">Date</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?= htmlspecialchars($date_filter) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill" style="border-radius:10px;">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="view_snapshots.php" class="btn btn-outline-secondary" style="border-radius:10px;">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Snapshots Grid -->
        <?php if (empty($snapshots)): ?>
        <div class="empty-state">
            <i class="bi bi-camera-video-off"></i>
            <h5>No Snapshots Found</h5>
            <p>No camera snapshots have been captured yet, or none match your filters.</p>
            <a href="view_snapshots.php" class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Clear Filters
            </a>
        </div>
        <?php else: ?>
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-grid me-2"></i>Snapshots (<?= count($snapshots) ?>)</h5>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                <i class="bi bi-arrow-left me-1"></i> Security Center
            </a>
        </div>
        <div class="snapshot-grid mb-4">
            <?php foreach ($snapshots as $snapshot): ?>
            <div class="snapshot-card">
                <?php if (file_exists($snapshot['snapshot_path'])): ?>
                <img src="<?= htmlspecialchars($snapshot['snapshot_path']) ?>"
                     class="snapshot-image" alt="Snapshot"
                     onclick="showFullImage('<?= htmlspecialchars($snapshot['snapshot_path']) ?>', '<?= htmlspecialchars($snapshot['student_name']) ?>', '<?= htmlspecialchars($snapshot['student_number']) ?>', '<?= htmlspecialchars($snapshot['exam_title']) ?>', '<?= date('Y-m-d H:i:s', strtotime($snapshot['timestamp'])) ?>', '<?= htmlspecialchars($snapshot['ip_address'] ?? '') ?>')">
                <?php else: ?>
                <div class="snapshot-placeholder">
                    <i class="bi bi-image" style="font-size:2rem;"></i>
                    <span style="font-size:0.8rem;">Image not available</span>
                </div>
                <?php endif; ?>

                <div class="snapshot-info">
                    <div class="student-name">
                        <i class="bi bi-person-fill me-1" style="color:#10b981;"></i>
                        <?= htmlspecialchars($snapshot['student_name']) ?>
                    </div>
                    <div class="snapshot-meta">
                        <span><i class="bi bi-hash"></i><?= htmlspecialchars($snapshot['student_number']) ?></span>
                        <span><i class="bi bi-journal-text"></i><?= htmlspecialchars($snapshot['exam_title']) ?></span>
                    </div>
                </div>
                <div class="snapshot-footer">
                    <span class="time-badge">
                        <i class="bi bi-clock me-1"></i><?= date('M d, H:i:s', strtotime($snapshot['timestamp'])) ?>
                    </span>
                    <span class="ip-badge">
                        <i class="bi bi-globe me-1"></i><?= htmlspecialchars($snapshot['ip_address'] ?? 'N/A') ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>Module</strong>
                    <span><i class="bi bi-camera-video me-1"></i>Camera Snapshots</span>
                </div>
                <div class="info-item">
                    <strong>Today</strong>
                    <span><i class="bi bi-calendar3 me-1"></i><?= date('M d, Y') ?></span>
                </div>
                <div class="info-item">
                    <strong>Role</strong>
                    <span><i class="bi bi-shield-check me-1"></i>Examination Manager</span>
                </div>
            </div>
        </div>

        <?php
        $current_role_context = 'examination_manager';
        include '../../includes/role_cards.php';
        ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="exam-bottom-nav">
        <a href="../dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="../create_exam.php" class="nav-item">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create</span>
        </a>
        <a href="../security_monitoring.php" class="nav-item">
            <i class="bi bi-shield-check-fill"></i>
            <span>Monitor</span>
        </a>
        <a href="index.php" class="nav-item active">
            <i class="bi bi-lock-fill"></i>
            <span>Security</span>
        </a>
        <a href="../semester_reports.php" class="nav-item">
            <i class="bi bi-file-earmark-bar-graph-fill"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- Full Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-camera me-2"></i>Snapshot Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <img id="fullImage" src="" class="img-fluid" alt="Full size snapshot" style="max-height:500px;">
                    </div>
                    <div class="modal-info" id="modalInfo">
                        <div class="info-row">
                            <span class="info-label">Student</span>
                            <span class="info-value" id="modalStudent"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Student ID</span>
                            <span class="info-value" id="modalStudentId"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Exam</span>
                            <span class="info-value" id="modalExam"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Captured</span>
                            <span class="info-value" id="modalTime"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">IP Address</span>
                            <span class="info-value" id="modalIp"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/session-timeout.js"></script>
    <script>
    function showFullImage(imagePath, studentName, studentId, examTitle, timestamp, ip) {
        document.getElementById('fullImage').src = imagePath;
        document.getElementById('modalStudent').textContent = studentName;
        document.getElementById('modalStudentId').textContent = studentId;
        document.getElementById('modalExam').textContent = examTitle;
        document.getElementById('modalTime').textContent = timestamp;
        document.getElementById('modalIp').textContent = ip || 'N/A';
        var modal = new bootstrap.Modal(document.getElementById('imageModal'));
        modal.show();
    }
    </script>
    <?php include_once __DIR__ . '/../../includes/pwa-footer.php'; ?>
</body>
</html>
