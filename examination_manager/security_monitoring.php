<?php
// examination_manager/security_monitoring.php - Live Security Monitoring
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get active exam sessions with monitoring data
$query = "
    SELECT
        es.session_id,
        es.student_id,
        es.exam_id,
        es.started_at,
        es.ip_address,
        e.exam_name as exam_title,
        e.duration_minutes,
        e.start_time as exam_start,
        e.end_time as exam_end,
        s.full_name as student_name,
        s.student_id as student_number,
        COUNT(em.monitoring_id) as total_events,
        SUM(CASE WHEN em.event_type IN ('tab_visibility_change', 'fullscreen_exited', 'violation', 'tab_change', 'fullscreen_exit') THEN 1 ELSE 0 END) as suspicious_events
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN students s ON es.student_id = s.student_id
    LEFT JOIN exam_monitoring em ON es.session_id = em.session_id
    WHERE es.is_active = 1
    GROUP BY es.session_id, es.student_id, es.exam_id, es.started_at, es.ip_address, e.exam_name, e.duration_minutes, e.start_time, e.end_time, s.full_name, s.student_id
    ORDER BY es.started_at DESC
";
$result = $conn->query($query);
$activeSessions = $result->fetch_all(MYSQLI_ASSOC);

// Summary stats
$totalActive = count($activeSessions);
$totalSuspicious = 0;
$totalEvents = 0;
foreach ($activeSessions as $s) {
    $totalSuspicious += ($s['suspicious_events'] > 0 ? 1 : 0);
    $totalEvents += $s['total_events'];
}

// Get recent monitoring events
$eventsQuery = "
    SELECT
        em.monitoring_id, em.session_id, em.event_type, em.timestamp, em.event_data, em.ip_address,
        s.full_name as student_name,
        s.student_id as student_number,
        e.exam_name as exam_title
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    ORDER BY em.timestamp DESC
    LIMIT 50
";
$eventsResult = $conn->query($eventsQuery);
$recentEvents = $eventsResult->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Security Monitoring - VLE Exam Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <style>
        :root {
            --exam-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --monitor-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }

        /* Page Header */
        .page-header-card {
            background: var(--monitor-gradient);
            border-radius: 24px; padding: 2rem; color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(99, 102, 241, 0.35);
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
        .page-header-card .header-stats { display: flex; gap: 2rem; margin-top: 1rem; flex-wrap: wrap; }
        .page-header-card .header-stat { text-align: center; }
        .page-header-card .header-stat-value { font-size: 1.5rem; font-weight: 700; display: block; }
        .page-header-card .header-stat-label { font-size: 0.8rem; opacity: 0.85; }
        @media (min-width: 992px) { .page-header-card h2 { font-size: 2rem; } }

        /* Pulse */
        .pulse-dot {
            display: inline-block; width: 10px; height: 10px;
            border-radius: 50%; background: #10b981;
            animation: pulse 1.5s infinite; margin-right: 6px;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .pulse-dot-red { background: #ef4444; }
        @keyframes pulseRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .pulse-dot-red { animation: pulseRed 1.5s infinite; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        .stat-card {
            background: white; border-radius: 16px; padding: 1.25rem 1rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border-left: 4px solid var(--accent-color, #6366f1);
        }
        .stat-card:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: white; flex-shrink: 0;
        }
        .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; display: block; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; display: block; font-weight: 500; }

        /* Section Headers */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .section-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }

        /* Session Cards */
        .session-card {
            background: white; border-radius: 16px; overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border-left: 4px solid var(--border-color, #10b981);
        }
        .session-card:hover { transform: translateX(4px); box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .session-card .session-body { padding: 1.25rem; }
        .session-card .session-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
        .session-card .student-info h6 { font-weight: 600; color: #1e293b; margin: 0 0 0.15rem; font-size: 0.95rem; }
        .session-card .student-info small { color: #64748b; }
        .session-card .session-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
        .session-card .meta-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 500;
        }
        .session-card .meta-badge.time { background: #eff6ff; color: #2563eb; }
        .session-card .meta-badge.events { background: #f0fdf4; color: #16a34a; }
        .session-card .meta-badge.warning { background: #fef2f2; color: #dc2626; }
        .session-card .meta-badge.ip { background: #f8fafc; color: #64748b; }
        .session-card .session-actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; }

        /* Progress bar for time remaining */
        .time-progress { height: 4px; border-radius: 2px; background: #e2e8f0; margin-top: 0.75rem; overflow: hidden; }
        .time-progress .bar { height: 100%; border-radius: 2px; transition: width 0.5s ease; }

        /* Activity Cards */
        .activity-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .activity-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .activity-header h5 { font-size: 0.95rem; font-weight: 600; margin: 0; }
        .activity-body { max-height: 500px; overflow-y: auto; }
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

        /* Event badges */
        .event-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 20px; font-size: 0.73rem; font-weight: 600;
        }
        .event-badge.violation { background: #fef2f2; color: #dc2626; }
        .event-badge.warning { background: #fffbeb; color: #d97706; }
        .event-badge.info { background: #eff6ff; color: #2563eb; }
        .event-badge.snapshot { background: #f0fdf4; color: #16a34a; }

        /* Auto-refresh indicator */
        .refresh-indicator {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.78rem; color: #64748b; font-weight: 500;
        }
        .refresh-indicator .spinner { width: 14px; height: 14px; border: 2px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; }
        .refresh-indicator.refreshing .spinner { animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

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
        .exam-bottom-nav .badge-count {
            position: absolute; top: -2px; right: 0;
            background: #ef4444; color: white; font-size: 0.6rem;
            padding: 1px 5px; border-radius: 10px;
        }
        .exam-bottom-nav .nav-item { position: relative; }

        /* Modal */
        .modal-content { border-radius: 16px; overflow: hidden; }
        .modal-header { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-bottom: 1px solid #e2e8f0; }
        .modal-header .modal-title { font-weight: 600; font-size: 1rem; }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../assets/img/Logo.png" alt="VLE Logo">
            <span>Monitoring</span>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="location.href='security/index.php'">
                <i class="bi bi-lock"></i>
            </button>
            <button class="header-btn" onclick="location.href='dashboard.php'">
                <i class="bi bi-speedometer2"></i>
            </button>
        </div>
    </header>

    <!-- Desktop Navigation -->
    <nav class="exam-desktop-nav">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-brand">
                <img src="../assets/img/Logo.png" alt="VLE Logo">
                <span>VLE Exam Manager</span>
            </a>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li><a href="create_exam.php" class="nav-link"><i class="bi bi-plus-circle"></i> Create Exam</a></li>
                <li><a href="generate_tokens.php" class="nav-link"><i class="bi bi-key"></i> Tokens</a></li>
                <li><a href="security_monitoring.php" class="nav-link active"><i class="bi bi-shield-check"></i> Monitoring</a></li>
                <li><a href="semester_reports.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></li>
                <li><a href="security/index.php" class="nav-link"><i class="bi bi-lock"></i> Security</a></li>
            </ul>
            <div class="nav-right">
                <div class="admin-dropdown">
                    <div class="nav-user">
                        <div class="nav-user-avatar"><?= strtoupper(substr($user['display_name'] ?? 'E', 0, 1)) ?></div>
                        <span class="nav-user-name"><?= htmlspecialchars($user['display_name'] ?? 'Manager') ?></span>
                        <i class="bi bi-chevron-down" style="font-size:0.7rem;color:#94a3b8;"></i>
                    </div>
                    <div class="admin-dropdown-menu">
                        <a href="../change_password.php"><i class="bi bi-key"></i> Change Password</a>
                        <hr>
                        <a href="../logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
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
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <h2><?php if ($totalActive > 0): ?><span class="pulse-dot"></span><?php endif; ?>Live Monitoring</h2>
                    <p>Real-time exam session monitoring &bullet; Auto-refreshes every 30 seconds</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="header-stat">
                    <span class="header-stat-value"><?= $totalActive ?></span>
                    <span class="header-stat-label">Active Sessions</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-value"><?= $totalSuspicious ?></span>
                    <span class="header-stat-label">Suspicious</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-value"><?= $totalEvents ?></span>
                    <span class="header-stat-label">Total Events</span>
                </div>
                <div class="header-stat">
                    <span class="header-stat-value"><?= count($recentEvents) ?></span>
                    <span class="header-stat-label">Recent Events</span>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-grid mb-4">
            <div class="stat-card" style="--accent-color: #10b981;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-people"></i></div>
                <div><span class="stat-value"><?= $totalActive ?></span><span class="stat-label">Active Now</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #ef4444;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-exclamation-triangle"></i></div>
                <div><span class="stat-value"><?= $totalSuspicious ?></span><span class="stat-label">Flagged</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #6366f1;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-activity"></i></div>
                <div><span class="stat-value"><?= $totalEvents ?></span><span class="stat-label">Events</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #f59e0b;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-clock-history"></i></div>
                <div><span class="stat-value" id="refreshCountdown">30</span><span class="stat-label">Next Refresh</span></div>
            </div>
        </div>

        <!-- Active Sessions -->
        <div class="section-header">
            <h5 class="section-title">
                <?php if ($totalActive > 0): ?><span class="pulse-dot"></span><?php endif; ?>
                Active Exam Sessions (<?= $totalActive ?>)
            </h5>
            <div class="refresh-indicator" id="refreshIndicator">
                <div class="spinner"></div>
                <span>Auto-refresh active</span>
            </div>
        </div>

        <?php if (empty($activeSessions)): ?>
        <div class="activity-card mb-4">
            <div class="activity-body">
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-check-circle display-3 d-block mb-3 text-success"></i>
                    <h5 class="fw-bold text-muted">No Active Sessions</h5>
                    <p class="mb-0">No students are currently taking exams. Check back during scheduled exam times.</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach ($activeSessions as $session):
                $elapsed = $session['started_at'] ? round((time() - strtotime($session['started_at'])) / 60) : 0;
                $remaining = max(0, ($session['duration_minutes'] ?? 60) - $elapsed);
                $progress = $session['duration_minutes'] > 0 ? min(100, ($elapsed / $session['duration_minutes']) * 100) : 0;
                $progressColor = $remaining <= 5 ? '#ef4444' : ($remaining <= 15 ? '#f59e0b' : '#10b981');
                $borderColor = $session['suspicious_events'] > 0 ? '#ef4444' : '#10b981';
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="session-card" style="--border-color: <?= $borderColor ?>;">
                    <div class="session-body">
                        <div class="session-top">
                            <div class="student-info">
                                <h6>
                                    <?php if ($session['suspicious_events'] > 0): ?>
                                    <span class="pulse-dot pulse-dot-red" style="width:8px;height:8px;"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($session['student_name']) ?>
                                </h6>
                                <small><?= htmlspecialchars($session['student_number']) ?></small>
                            </div>
                            <?php if ($session['suspicious_events'] > 0): ?>
                            <span class="badge bg-danger"><i class="bi bi-flag-fill me-1"></i>Flagged</span>
                            <?php else: ?>
                            <span class="badge bg-success"><span class="pulse-dot" style="width:6px;height:6px;margin-right:4px;"></span>OK</span>
                            <?php endif; ?>
                        </div>

                        <small class="text-muted d-block mb-2"><?= htmlspecialchars($session['exam_title']) ?></small>

                        <div class="session-meta">
                            <span class="meta-badge time">
                                <i class="bi bi-clock"></i> <?= $elapsed ?> min elapsed
                            </span>
                            <span class="meta-badge <?= $remaining <= 5 ? 'warning' : 'time' ?>">
                                <i class="bi bi-hourglass-split"></i> <?= $remaining ?> min left
                            </span>
                            <span class="meta-badge events">
                                <i class="bi bi-lightning"></i> <?= $session['total_events'] ?> events
                            </span>
                            <?php if ($session['suspicious_events'] > 0): ?>
                            <span class="meta-badge warning">
                                <i class="bi bi-exclamation-triangle"></i> <?= $session['suspicious_events'] ?> suspicious
                            </span>
                            <?php endif; ?>
                            <?php if ($session['ip_address']): ?>
                            <span class="meta-badge ip">
                                <i class="bi bi-globe"></i> <?= htmlspecialchars($session['ip_address']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="time-progress">
                            <div class="bar" style="width: <?= $progress ?>%; background: <?= $progressColor ?>;"></div>
                        </div>

                        <div class="session-actions">
                            <button class="btn btn-sm btn-primary" style="border-radius:8px;" onclick="viewSessionDetails(<?= $session['session_id'] ?>)">
                                <i class="bi bi-eye me-1"></i> Details
                            </button>
                            <a href="generate_tokens.php?exam_id=<?= $session['exam_id'] ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                                <i class="bi bi-key me-1"></i> Tokens
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Event Feed -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-activity me-2"></i>Recent Monitoring Events</h5>
            <span class="badge bg-light text-dark"><?= count($recentEvents) ?> events</span>
        </div>
        <div class="activity-card mb-4">
            <div class="activity-body">
                <?php if (!empty($recentEvents)): ?>
                    <?php foreach ($recentEvents as $event):
                        $type = $event['event_type'];
                        if (in_array($type, ['tab_visibility_change', 'fullscreen_exited', 'violation'])) {
                            $badgeClass = 'violation'; $dotColor = '#ef4444';
                        } elseif ($type === 'camera_snapshot') {
                            $badgeClass = 'snapshot'; $dotColor = '#10b981';
                        } elseif (in_array($type, ['tab_change', 'fullscreen_exit'])) {
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
                                <span class="text-muted small ms-1"><?= htmlspecialchars($event['student_number']) ?></span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                <span class="event-badge <?= $badgeClass ?>"><?= ucfirst(str_replace('_', ' ', $type)) ?></span>
                                <span class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($event['exam_title']) ?></span>
                                <span class="text-muted" style="font-size:0.75rem;">&bullet; <?= date('H:i:s', strtotime($event['timestamp'])) ?></span>
                            </div>
                            <?php
                            $eventData = json_decode($event['event_data'] ?? '', true);
                            if ($eventData): ?>
                            <div class="mt-1">
                                <small class="text-muted" style="font-size:0.72rem;">
                                    <?php
                                    $details = [];
                                    foreach ($eventData as $k => $v) {
                                        if (is_string($v) && strlen($v) < 100) $details[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
                                    }
                                    echo htmlspecialchars(implode(' | ', array_slice($details, 0, 3)));
                                    ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                        <p class="mb-0">No monitoring events recorded yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>Module</strong>
                    <span><i class="bi bi-shield-check me-1"></i>Live Monitoring</span>
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
        include '../includes/role_cards.php';
        ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="exam-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="create_exam.php" class="nav-item">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create</span>
        </a>
        <a href="security_monitoring.php" class="nav-item active">
            <i class="bi bi-shield-check-fill"></i>
            <span>Monitor</span>
            <?php if ($totalActive > 0): ?>
            <span class="badge-count"><?= $totalActive ?></span>
            <?php endif; ?>
        </a>
        <a href="generate_tokens.php" class="nav-item">
            <i class="bi bi-key-fill"></i>
            <span>Tokens</span>
        </a>
        <a href="semester_reports.php" class="nav-item">
            <i class="bi bi-file-earmark-bar-graph-fill"></i>
            <span>Reports</span>
        </a>
    </nav>

    <!-- Session Details Modal -->
    <div class="modal fade" id="sessionDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Session Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="sessionDetailsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading session details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/session-timeout.js"></script>
    <script>
    function viewSessionDetails(sessionId) {
        document.getElementById('sessionDetailsContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading...</p></div>';
        var modal = new bootstrap.Modal(document.getElementById('sessionDetailsModal'));
        modal.show();

        fetch('get_session_details.php?session_id=' + sessionId)
            .then(response => response.text())
            .then(data => {
                document.getElementById('sessionDetailsContent').innerHTML = data;
            })
            .catch(error => {
                document.getElementById('sessionDetailsContent').innerHTML = '<div class="alert alert-danger" style="border-radius:12px;"><i class="bi bi-exclamation-circle me-2"></i>Failed to load session details</div>';
            });
    }

    // Countdown timer for auto-refresh
    let countdown = 30;
    const countdownEl = document.getElementById('refreshCountdown');
    const indicator = document.getElementById('refreshIndicator');

    setInterval(function() {
        countdown--;
        if (countdownEl) countdownEl.textContent = countdown;
        if (countdown <= 3 && indicator) indicator.classList.add('refreshing');
        if (countdown <= 0) {
            location.reload();
        }
    }, 1000);
    </script>
    <?php include_once __DIR__ . '/../includes/pwa-footer.php'; ?>
</body>
</html>
