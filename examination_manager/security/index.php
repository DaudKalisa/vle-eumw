<?php
// examination_manager/security/index.php - Security Center
require_once '../../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get security statistics
$statsQuery = "
    SELECT
        COUNT(DISTINCT es.session_id) as total_sessions,
        COUNT(DISTINCT CASE WHEN em.event_type IN ('tab_visibility_change', 'fullscreen_exited') THEN es.session_id END) as suspicious_sessions,
        COUNT(em.monitoring_id) as total_events,
        COUNT(CASE WHEN em.event_type = 'camera_snapshot' THEN 1 END) as camera_snapshots
    FROM exam_sessions es
    LEFT JOIN exam_monitoring em ON es.session_id = em.session_id
";
$stats = $conn->query($statsQuery)->fetch_assoc();

// Get recent security incidents
$incidentsQuery = "
    SELECT
        es.session_id,
        s.full_name as student_name,
        s.student_id as student_number,
        e.exam_name as exam_title,
        COUNT(em.monitoring_id) as event_count,
        MAX(em.timestamp) as last_incident
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN exam_monitoring em ON es.session_id = em.session_id
    WHERE em.event_type IN ('tab_visibility_change', 'fullscreen_exited')
    GROUP BY es.session_id, s.full_name, s.student_id, e.exam_name
    ORDER BY last_incident DESC
    LIMIT 10
";
$incidents = $conn->query($incidentsQuery)->fetch_all(MYSQLI_ASSOC);

// Get recent monitoring events (all types)
$eventsQuery = "
    SELECT
        em.monitoring_id, em.event_type, em.timestamp, em.event_data,
        s.full_name as student_name,
        s.student_id as student_number,
        e.exam_name as exam_title
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    ORDER BY em.timestamp DESC
    LIMIT 20
";
$eventsResult = $conn->query($eventsQuery);
$recentEvents = $eventsResult ? $eventsResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Security Center - VLE Exam Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../../includes/pwa-head.php'; ?>
    <style>
        :root {
            --exam-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --security-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }

        /* Page Header Card */
        .page-header-card {
            background: var(--security-gradient);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(239, 68, 68, 0.3);
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
        .page-header-card .header-date { margin-top: 1rem; opacity: 0.85; font-size: 0.9rem; }
        @media (min-width: 992px) { .page-header-card h2 { font-size: 2rem; } }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        .stat-card {
            background: white; border-radius: 16px; padding: 1.25rem 1rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border-left: 4px solid var(--accent-color, #ef4444);
        }
        .stat-card:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: white; flex-shrink: 0;
        }
        .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; display: block; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; display: block; font-weight: 500; }

        /* Quick Actions */
        .quick-actions {
            display: flex; gap: 0.75rem; overflow-x: auto;
            padding: 0.5rem 0 1rem;
            -webkit-overflow-scrolling: touch; scrollbar-width: none;
        }
        .quick-actions::-webkit-scrollbar { display: none; }
        .action-btn {
            display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
            min-width: 100px; text-decoration: none; color: #334155;
            padding: 1rem 0.75rem; background: white; border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease; flex-shrink: 0;
        }
        .action-btn:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); color: #92400e; }
        .action-btn .action-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; color: white;
        }
        .action-btn span { font-size: 0.78rem; font-weight: 500; text-align: center; }

        /* Section Headers */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .section-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }

        /* Activity Cards */
        .activity-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .activity-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .activity-header h5 { font-size: 0.95rem; font-weight: 600; margin: 0; }
        .activity-body { max-height: 400px; overflow-y: auto; }
        .activity-item {
            display: flex; align-items: flex-start; gap: 0.75rem;
            padding: 0.875rem 1.25rem; border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { background: #f8fafc; }
        .activity-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 6px; flex-shrink: 0; }
        .activity-content { flex: 1; }
        .activity-text { font-size: 0.85rem; color: #334155; }
        .activity-time { font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem; }

        /* Settings Card */
        .settings-card {
            background: white; border-radius: 16px; padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .settings-card .card-title-bar {
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1.5rem; padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .settings-card .card-title-bar .title-icon {
            width: 42px; height: 42px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: white; background: linear-gradient(135deg, #6366f1, #4f46e5);
        }
        .settings-card .card-title-bar h5 { font-weight: 600; margin: 0; font-size: 1rem; }
        .settings-card label { font-weight: 500; color: #334155; font-size: 0.9rem; }
        .settings-card .form-control { border-radius: 10px; border-color: #e2e8f0; }
        .settings-card .form-control:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }

        /* Footer Info */
        .admin-footer-info {
            background: white; border-radius: 16px; padding: 1rem 1.25rem;
            margin-top: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .info-grid { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center; }
        .info-item { text-align: center; min-width: 120px; }
        .info-item strong { display: block; font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .info-item span { font-size: 0.85rem; color: #475569; }

        /* Wrapper */
        .page-wrapper { padding: 1rem; padding-bottom: 100px; }
        @media (min-width: 768px) { .page-wrapper { padding: 2rem; padding-bottom: 2rem; } }
        @media (min-width: 768px) { .exam-mobile-header, .exam-bottom-nav { display: none !important; } }
        @media (max-width: 767.98px) { .exam-desktop-nav { display: none !important; } }

        /* Mobile Header */
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

        /* Desktop Nav */
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

        /* Mobile Bottom Nav */
        .exam-bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: white; display: flex; justify-content: space-around;
            padding: 0.5rem 0; box-shadow: 0 -2px 15px rgba(0,0,0,0.08);
            z-index: 1000; border-top: 1px solid #e2e8f0;
        }
        .exam-bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center;
            text-decoration: none; color: #94a3b8; font-size: 0.65rem;
            font-weight: 500; padding: 0.25rem 0.5rem; border-radius: 8px;
            transition: all 0.2s;
        }
        .exam-bottom-nav .nav-item i { font-size: 1.25rem; margin-bottom: 0.15rem; }
        .exam-bottom-nav .nav-item.active { color: #d97706; }

        /* Event type badges */
        .event-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
        }
        .event-badge.violation { background: #fef2f2; color: #dc2626; }
        .event-badge.warning { background: #fffbeb; color: #d97706; }
        .event-badge.info { background: #eff6ff; color: #2563eb; }
        .event-badge.snapshot { background: #f0fdf4; color: #16a34a; }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../../assets/img/Logo.png" alt="VLE Logo">
            <span>Security Center</span>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="location.href='../security_monitoring.php'">
                <i class="bi bi-shield-check"></i>
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
        <!-- Page Header Card -->
        <div class="page-header-card">
            <div class="header-content">
                <div class="header-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <div>
                    <h2>Security Center</h2>
                    <p>Monitor security incidents, manage settings, and generate reports</p>
                </div>
            </div>
            <div class="header-date">
                <i class="bi bi-calendar3 me-1"></i> <?= date('l, F j, Y') ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-graph-up-arrow me-2"></i>Security Overview</h5>
        </div>
        <div class="stats-grid mb-4">
            <div class="stat-card" style="--accent-color: #3b82f6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-people"></i></div>
                <div><span class="stat-value"><?= number_format($stats['total_sessions']) ?></span><span class="stat-label">Total Sessions</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #f59e0b;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-exclamation-triangle"></i></div>
                <div><span class="stat-value"><?= number_format($stats['suspicious_sessions']) ?></span><span class="stat-label">Suspicious</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #8b5cf6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-activity"></i></div>
                <div><span class="stat-value"><?= number_format($stats['total_events']) ?></span><span class="stat-label">Total Events</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #10b981;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-camera-video"></i></div>
                <div><span class="stat-value"><?= number_format($stats['camera_snapshots']) ?></span><span class="stat-label">Snapshots</span></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
        </div>
        <div class="quick-actions mb-4">
            <a href="generate_report.php?type=incidents" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-exclamation-triangle"></i></div>
                <span>Incident Report</span>
            </a>
            <a href="generate_report.php?type=sessions" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-list-ul"></i></div>
                <span>Session Report</span>
            </a>
            <a href="view_snapshots.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-camera"></i></div>
                <span>Snapshots</span>
            </a>
            <a href="../security_monitoring.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-shield-check"></i></div>
                <span>Live Monitor</span>
            </a>
            <a href="update_security_settings.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-gear"></i></div>
                <span>Settings</span>
            </a>
        </div>

        <!-- Two-column: Incidents + Event Feed -->
        <div class="row g-3 mb-4">
            <!-- Recent Security Incidents -->
            <div class="col-lg-7">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-shield-exclamation me-2"></i>Recent Security Incidents</h5>
                        <a href="generate_report.php?type=incidents" class="btn btn-sm btn-outline-danger">Full Report</a>
                    </div>
                    <div class="activity-body">
                        <?php if (!empty($incidents)): ?>
                            <?php foreach ($incidents as $incident): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: #ef4444;"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?= htmlspecialchars($incident['student_name']) ?></strong>
                                        <span class="text-muted small ms-1"><?= htmlspecialchars($incident['student_number']) ?></span>
                                    </div>
                                    <div class="activity-time">
                                        <?= htmlspecialchars($incident['exam_title']) ?> &bullet;
                                        <span class="badge bg-danger" style="font-size:0.7rem;"><?= $incident['event_count'] ?> events</span> &bullet;
                                        <?= date('M d, H:i', strtotime($incident['last_incident'])) ?>
                                    </div>
                                </div>
                                <a href="../security_monitoring.php" class="btn btn-sm btn-outline-warning" style="flex-shrink:0;">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle display-4 d-block mb-2 text-success"></i>
                                <p class="mb-0">No security incidents recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Event Feed -->
            <div class="col-lg-5">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-activity me-2"></i>Event Feed</h5>
                        <span class="badge bg-light text-dark"><?= count($recentEvents) ?> recent</span>
                    </div>
                    <div class="activity-body">
                        <?php if (!empty($recentEvents)): ?>
                            <?php foreach ($recentEvents as $event):
                                $type = $event['event_type'];
                                if (in_array($type, ['tab_visibility_change', 'fullscreen_exited'])) {
                                    $badgeClass = 'violation'; $dotColor = '#ef4444';
                                } elseif ($type === 'camera_snapshot') {
                                    $badgeClass = 'snapshot'; $dotColor = '#10b981';
                                } elseif (in_array($type, ['violation', 'tab_change', 'fullscreen_exit'])) {
                                    $badgeClass = 'warning'; $dotColor = '#f59e0b';
                                } else {
                                    $badgeClass = 'info'; $dotColor = '#3b82f6';
                                }
                            ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: <?= $dotColor ?>"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?= htmlspecialchars($event['student_name']) ?></strong>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="event-badge <?= $badgeClass ?>"><?= ucfirst(str_replace('_', ' ', $type)) ?></span>
                                        <span class="activity-time mb-0"><?= date('H:i:s', strtotime($event['timestamp'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                <p class="mb-0">No events recorded yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Settings -->
        <div class="settings-card mb-4">
            <div class="card-title-bar">
                <div class="title-icon"><i class="bi bi-gear-fill"></i></div>
                <div>
                    <h5>Security Settings</h5>
                    <small class="text-muted">Configure monitoring parameters and alert thresholds</small>
                </div>
            </div>
            <form method="post" action="update_security_settings.php">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="snapshot_interval" class="form-label">Camera Snapshot Interval (sec)</label>
                        <input type="number" class="form-control" id="snapshot_interval" name="snapshot_interval"
                               value="30" min="10" max="300" step="10" placeholder="30">
                    </div>
                    <div class="col-md-4">
                        <label for="max_tab_changes" class="form-label">Max Tab Changes Before Alert</label>
                        <input type="number" class="form-control" id="max_tab_changes" name="max_tab_changes"
                               value="3" min="1" max="10" placeholder="3">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="enable_fullscreen_alert" name="enable_fullscreen_alert" checked>
                            <label class="form-check-label" for="enable_fullscreen_alert">Alert on Fullscreen Exit</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;padding:0.6rem 1.5rem;">
                        <i class="bi bi-check-circle me-2"></i>Update Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>Module</strong>
                    <span><i class="bi bi-shield-lock me-1"></i>Security Center</span>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/session-timeout.js"></script>
    <?php include_once __DIR__ . '/../../includes/pwa-footer.php'; ?>
</body>
</html>
