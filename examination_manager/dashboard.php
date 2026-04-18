<?php
// examination_manager/dashboard.php - Examination Manager Dashboard
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get statistics
$stats = [];

// Total exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE is_active = 1");
$stats['total_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Active exam sessions (currently running)
$result = $conn->query("
    SELECT COUNT(*) as total FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    WHERE es.is_active = 1 AND NOW() BETWEEN e.start_time AND e.end_time
");
$stats['active_sessions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Pending reviews/flagged submissions
$result = $conn->query("SELECT COUNT(*) as total FROM exam_results WHERE status = 'flagged'");
$stats['pending_reviews'] = $result ? $result->fetch_assoc()['total'] : 0;

// Today's exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE DATE(start_time) = CURDATE() AND is_active = 1");
$stats['today_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Total questions across all exams
$result = $conn->query("SELECT COUNT(*) as total FROM exam_questions");
$stats['total_questions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Total results graded
$result = $conn->query("SELECT COUNT(*) as total FROM exam_results");
$stats['total_results'] = $result ? $result->fetch_assoc()['total'] : 0;

// Average pass rate
$result = $conn->query("SELECT AVG(is_passed) * 100 as pass_rate FROM exam_results");
$stats['pass_rate'] = $result ? round($result->fetch_assoc()['pass_rate'] ?? 0, 1) : 0;

// Total tokens generated
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens");
$stats['total_tokens'] = $result ? $result->fetch_assoc()['total'] : 0;

// Monitoring violations
$result = $conn->query("SELECT COUNT(*) as total FROM exam_monitoring WHERE event_type IN ('violation', 'tab_change', 'fullscreen_exit', 'tab_visibility_change', 'fullscreen_exited')");
$stats['violations'] = $result ? $result->fetch_assoc()['total'] : 0;

// Get active exams (happening now or scheduled for today)
$active_exams = [];
$exam_query = "
    SELECT e.*, c.course_name, c.course_code, l.full_name as lecturer_name,
           COUNT(es.session_id) as active_session_count,
           CASE
               WHEN NOW() BETWEEN e.start_time AND e.end_time THEN 'active'
               WHEN NOW() < e.start_time THEN 'upcoming'
               ELSE 'completed'
           END as exam_status
    FROM exams e
    JOIN vle_courses c ON e.course_id = c.course_id
    LEFT JOIN lecturers l ON e.lecturer_id = l.lecturer_id
    LEFT JOIN exam_sessions es ON e.exam_id = es.exam_id AND es.is_active = 1
    WHERE e.is_active = 1 AND DATE(e.start_time) = CURDATE()
    GROUP BY e.exam_id
    ORDER BY e.start_time ASC
";
$result = $conn->query($exam_query);
if ($result) {
    while ($exam = $result->fetch_assoc()) {
        $active_exams[$exam['exam_status']][] = $exam;
    }
}

// Get recent exam sessions for monitoring
$recent_sessions = [];
$sessions_query = "
    SELECT es.*, e.exam_name, e.exam_id, s.full_name as student_name,
           TIMESTAMPDIFF(MINUTE, es.started_at, NOW()) as elapsed_minutes,
           e.duration_minutes
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN students s ON es.student_id = s.student_id
    WHERE es.is_active = 1
    ORDER BY es.started_at DESC
    LIMIT 10
";
$result = $conn->query($sessions_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_sessions[] = $row;
    }
}

// Recent exams
$recent_exams = [];
$result = $conn->query("
    SELECT e.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.exam_id) as question_count,
           (SELECT COUNT(*) FROM exam_sessions WHERE exam_id = e.exam_id) as attempt_count
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    ORDER BY e.created_at DESC LIMIT 5
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_exams[] = $row;
    }
}

// Recent monitoring events
$recent_violations = [];
$result = $conn->query("
    SELECT em.*, es.student_id, s.full_name as student_name, ex.exam_name
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams ex ON es.exam_id = ex.exam_id
    WHERE em.event_type IN ('violation', 'tab_change', 'fullscreen_exit', 'tab_visibility_change', 'fullscreen_exited')
    ORDER BY em.timestamp DESC LIMIT 8
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_violations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Examination Manager Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <style>
        :root {
            --exam-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }

        /* Welcome Card */
        .welcome-card {
            background: var(--exam-gradient);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(245, 158, 11, 0.35);
        }
        .welcome-card .profile-section { display: flex; align-items: center; gap: 1.25rem; }
        .welcome-card .profile-avatar {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.75rem; font-weight: 700;
            border: 4px solid rgba(255,255,255,0.4);
        }
        .welcome-card .welcome-name { font-size: 1.75rem; font-weight: 700; margin: 0; }
        @media (min-width: 992px) {
            .welcome-card .welcome-name { font-size: 2rem; }
            .welcome-card .profile-avatar { width: 80px; height: 80px; }
        }
        .welcome-card .welcome-role { opacity: 0.9; font-size: 1rem; }
        .welcome-card .welcome-date { margin-top: 1rem; opacity: 0.85; font-size: 0.9rem; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1200px) { .stats-grid { grid-template-columns: repeat(5, 1fr); } }
        .stat-card {
            background: white; border-radius: 16px; padding: 1.25rem 1rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease; text-decoration: none; color: inherit;
            border-left: 4px solid var(--accent-color, #f59e0b);
        }
        .stat-card:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); color: inherit; }
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
            min-width: 85px; text-decoration: none; color: #334155;
            padding: 0.75rem 0.5rem; background: white; border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease; flex-shrink: 0;
        }
        .action-btn:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); color: #92400e; }
        .action-btn .action-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: white;
        }
        .action-btn span { font-size: 0.75rem; font-weight: 500; text-align: center; }

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
        .activity-body { max-height: 350px; overflow-y: auto; }
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

        /* Live pulse animation */
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

        /* Session cards */
        .session-card {
            background: white; border-radius: 12px; padding: 1rem 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
            border-left: 4px solid var(--border-color, #10b981);
        }
        .session-card:hover { background: #f8fafc; transform: translateX(4px); }

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
        .exam-wrapper { padding: 1rem; padding-bottom: 100px; }
        @media (min-width: 768px) { .exam-wrapper { padding: 2rem; padding-bottom: 2rem; } }
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
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; position: relative;
        }
        .badge-dot { position: absolute; top: 6px; right: 6px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; }

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
            transition: all 0.2s; position: relative;
        }
        .exam-bottom-nav .nav-item i { font-size: 1.25rem; margin-bottom: 0.15rem; }
        .exam-bottom-nav .nav-item.active { color: #d97706; }
        .exam-bottom-nav .badge-count {
            position: absolute; top: -2px; right: 0;
            background: #ef4444; color: white; font-size: 0.6rem;
            padding: 1px 5px; border-radius: 10px;
        }

        /* Pending Alert */
        .pending-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: none; border-radius: 16px; padding: 1rem 1.25rem;
            display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;
        }
        .pending-alert .alert-icon {
            width: 48px; height: 48px; background: #f59e0b;
            border-radius: 12px; display: flex; align-items: center;
            justify-content: center; color: white; font-size: 1.25rem; flex-shrink: 0;
        }
        .pending-alert .alert-content { flex: 1; }
        .pending-alert .alert-title { font-weight: 600; color: #92400e; margin-bottom: 0.25rem; }
        .pending-alert .alert-text { font-size: 0.85rem; color: #a16207; }
        .pending-alert .alert-btn {
            background: #f59e0b; color: white; border: none;
            padding: 0.5rem 1rem; border-radius: 8px; font-weight: 500;
            font-size: 0.85rem; text-decoration: none; transition: all 0.2s;
        }
        .pending-alert .alert-btn:hover { background: #d97706; color: white; }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../assets/img/Logo.png" alt="VLE Logo">
            <span>VLE Exam Manager</span>
        </div>
        <div class="header-actions">
            <?php if ($stats['active_sessions'] > 0): ?>
            <button class="header-btn has-badge" onclick="location.href='security_monitoring.php'">
                <i class="bi bi-shield-exclamation"></i>
                <span class="badge-dot"></span>
            </button>
            <?php endif; ?>
            <button class="header-btn" onclick="location.href='create_exam.php'">
                <i class="bi bi-plus-circle-fill"></i>
            </button>
            <button class="header-btn" onclick="location.href='../change_password.php'">
                <i class="bi bi-person-fill"></i>
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
                <li><a href="dashboard.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li><a href="create_exam.php" class="nav-link"><i class="bi bi-plus-circle"></i> Create Exam</a></li>
                <li><a href="generate_tokens.php" class="nav-link"><i class="bi bi-key"></i> Tokens</a></li>
                <li><a href="security_monitoring.php" class="nav-link"><i class="bi bi-shield-check"></i> Monitoring</a></li>
                <li><a href="semester_reports.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></li>
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
    <main class="exam-wrapper">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="profile-section">
                <div class="profile-avatar">
                    <?= strtoupper(substr($user['display_name'] ?? 'E', 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <h2 class="welcome-name"><?= htmlspecialchars($user['display_name'] ?? 'Examination Manager') ?></h2>
                    <p class="welcome-role mb-0"><i class="bi bi-shield-check"></i> Examination Manager</p>
                </div>
            </div>
            <div class="welcome-date">
                <i class="bi bi-calendar3"></i> <?= date('l, F j, Y') ?>
            </div>
        </div>

        <!-- Active Sessions Alert -->
        <?php if ($stats['active_sessions'] > 0): ?>
        <div class="pending-alert">
            <div class="alert-icon">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
            <div class="alert-content">
                <div class="alert-title"><span class="pulse-dot"></span> Live Exams in Progress</div>
                <div class="alert-text"><?= $stats['active_sessions'] ?> student<?= $stats['active_sessions'] > 1 ? 's' : '' ?> currently writing exam<?= $stats['active_sessions'] > 1 ? 's' : '' ?></div>
            </div>
            <a href="security_monitoring.php" class="alert-btn">Monitor Now</a>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
        </div>
        <div class="quick-actions mb-4">
            <a href="create_exam.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-plus-circle"></i></div>
                <span>Create Exam</span>
            </a>
            <a href="generate_tokens.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-key"></i></div>
                <span>Gen. Tokens</span>
            </a>
            <a href="security_monitoring.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-shield-check"></i></div>
                <span>Monitoring</span>
            </a>
            <a href="semester_reports.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-file-earmark-bar-graph"></i></div>
                <span>Reports</span>
            </a>
            <a href="security/index.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-camera-video"></i></div>
                <span>Security</span>
            </a>
            <a href="../examination_officer/manage_exams.php?status=inactive" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a);"><i class="bi bi-play-circle"></i></div>
                <span>Activate Exams</span>
            </a>
        </div>

        <!-- Stats Grid -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-graph-up-arrow me-2"></i>Examination Overview</h5>
        </div>
        <div class="stats-grid mb-4">
            <a href="create_exam.php" class="stat-card" style="--accent-color: #f59e0b; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-file-earmark-text"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['total_exams']) ?></span><span class="stat-label">Total Exams</span></div>
            </a>
            <a href="security_monitoring.php" class="stat-card" style="--accent-color: #10b981; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-play-circle"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['active_sessions']) ?></span><span class="stat-label">Active Sessions</span></div>
            </a>
            <a href="create_exam.php" class="stat-card" style="--accent-color: #3b82f6; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-calendar-event"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['today_exams']) ?></span><span class="stat-label">Today's Exams</span></div>
            </a>
            <a href="create_exam.php" class="stat-card" style="--accent-color: #8b5cf6; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-collection"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['total_questions']) ?></span><span class="stat-label">Questions</span></div>
            </a>
            <a href="semester_reports.php" class="stat-card" style="--accent-color: #06b6d4; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="bi bi-graph-up"></i></div>
                <div class="stat-content"><span class="stat-value"><?= $stats['pass_rate'] ?>%</span><span class="stat-label">Pass Rate</span></div>
            </a>
            <a href="security_monitoring.php" class="stat-card" style="--accent-color: #ef4444; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-flag"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['pending_reviews']) ?></span><span class="stat-label">Flagged</span></div>
            </a>
            <a href="generate_tokens.php" class="stat-card" style="--accent-color: #f97316; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="bi bi-key"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['total_tokens']) ?></span><span class="stat-label">Tokens</span></div>
            </a>
            <a href="semester_reports.php" class="stat-card" style="--accent-color: #ec4899; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="bi bi-clipboard-check"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['total_results']) ?></span><span class="stat-label">Results</span></div>
            </a>
            <a href="security/index.php" class="stat-card" style="--accent-color: #14b8a6; text-decoration:none; color:inherit;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="stat-content"><span class="stat-value"><?= number_format($stats['violations']) ?></span><span class="stat-label">Violations</span></div>
            </a>
        </div>

        <!-- Active Exams Section -->
        <?php if (!empty($active_exams['active'])): ?>
        <div class="section-header">
            <h5 class="section-title"><span class="pulse-dot"></span> Live Exams Now</h5>
            <a href="security_monitoring.php" class="btn btn-sm btn-outline-success">View All</a>
        </div>
        <div class="row g-3 mb-4">
            <?php foreach ($active_exams['active'] as $exam): ?>
            <div class="col-md-6 col-lg-4">
                <div class="session-card" style="--border-color: #10b981;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($exam['exam_name']) ?></h6>
                        <span class="badge bg-success"><span class="pulse-dot" style="width:6px;height:6px;margin-right:4px;"></span>LIVE</span>
                    </div>
                    <p class="mb-1 small text-muted"><?= htmlspecialchars($exam['course_code'] . ' — ' . $exam['course_name']) ?></p>
                    <p class="mb-2 small"><strong>Active:</strong> <?= $exam['active_session_count'] ?> students &bullet; <strong>Duration:</strong> <?= $exam['duration_minutes'] ?> min</p>
                    <div class="d-flex gap-2">
                        <a href="security_monitoring.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-eye me-1"></i>Monitor</a>
                        <a href="generate_tokens.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-key me-1"></i>Tokens</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Upcoming Exams Section -->
        <?php if (!empty($active_exams['upcoming'])): ?>
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-clock me-2"></i>Upcoming Exams Today</h5>
        </div>
        <div class="row g-3 mb-4">
            <?php foreach ($active_exams['upcoming'] as $exam): ?>
            <div class="col-md-6 col-lg-4">
                <div class="session-card" style="--border-color: #f59e0b;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($exam['exam_name']) ?></h6>
                        <span class="badge bg-warning text-dark">Upcoming</span>
                    </div>
                    <p class="mb-1 small text-muted"><?= htmlspecialchars($exam['course_code'] . ' — ' . $exam['course_name']) ?></p>
                    <p class="mb-2 small">
                        <i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($exam['start_time'])) ?> - <?= date('H:i', strtotime($exam['end_time'])) ?>
                        &bullet; <?= $exam['duration_minutes'] ?> min
                    </p>
                    <div class="d-flex gap-2">
                        <a href="generate_tokens.php?exam_id=<?= $exam['exam_id'] ?>" class="btn btn-warning btn-sm text-dark"><i class="bi bi-key me-1"></i>Generate Tokens</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Live Session Monitor + Recent Violations -->
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-people me-2"></i>Live Sessions (<?= count($recent_sessions) ?>)</h5>
                        <a href="security_monitoring.php" class="btn btn-sm btn-outline-primary">Full Monitor</a>
                    </div>
                    <div class="activity-body">
                        <?php if (!empty($recent_sessions)): ?>
                            <?php foreach ($recent_sessions as $session): ?>
                            <div class="activity-item">
                                <?php
                                $remaining = ($session['duration_minutes'] ?? 60) - ($session['elapsed_minutes'] ?? 0);
                                $remaining = max(0, $remaining);
                                $dotColor = $remaining <= 5 ? '#ef4444' : ($remaining <= 15 ? '#f59e0b' : '#10b981');
                                ?>
                                <div class="activity-dot" style="background: <?= $dotColor ?>"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?= htmlspecialchars($session['student_name']) ?></strong>
                                        — <?= htmlspecialchars($session['exam_name']) ?>
                                    </div>
                                    <div class="activity-time">
                                        <?= $session['elapsed_minutes'] ?> min elapsed &bullet;
                                        <span class="<?= $remaining <= 5 ? 'text-danger fw-bold' : '' ?>"><?= $remaining ?> min remaining</span>
                                        &bullet; Started <?= date('H:i', strtotime($session['started_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                <p class="mb-0">No active sessions right now</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-exclamation-triangle me-2"></i>Recent Violations</h5>
                        <a href="security/index.php" class="btn btn-sm btn-outline-danger">View All</a>
                    </div>
                    <div class="activity-body">
                        <?php if (!empty($recent_violations)): ?>
                            <?php foreach ($recent_violations as $v): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: #ef4444"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?= htmlspecialchars($v['student_name']) ?></strong>
                                        — <?= ucfirst(str_replace('_', ' ', $v['event_type'])) ?>
                                    </div>
                                    <div class="activity-time">
                                        <?= htmlspecialchars($v['exam_name']) ?> &bullet;
                                        <?= date('M d, H:i', strtotime($v['timestamp'])) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-check-circle display-4 d-block mb-2 text-success"></i>
                                <p class="mb-0">No violations recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Exams Table -->
        <div class="activity-card mb-4">
            <div class="activity-header">
                <h5><i class="bi bi-journal-text me-2"></i>Recent Exams</h5>
                <a href="create_exam.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>New Exam</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Exam</th>
                            <th>Course</th>
                            <th>Questions</th>
                            <th>Attempts</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_exams)): ?>
                            <?php foreach ($recent_exams as $exam): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($exam['exam_name']) ?></strong></td>
                                <td><small class="text-muted"><?= htmlspecialchars(($exam['course_code'] ?? '') . ' ' . ($exam['course_name'] ?? '')) ?></small></td>
                                <td><span class="badge bg-light text-dark"><?= $exam['question_count'] ?></span></td>
                                <td><span class="badge bg-light text-dark"><?= $exam['attempt_count'] ?></span></td>
                                <td>
                                    <?php if ($exam['is_active'] && strtotime($exam['end_time'] ?? 'now') > time()): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php elseif ($exam['is_active']): ?>
                                        <span class="badge bg-secondary">Ended</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= date('M d, Y', strtotime($exam['created_at'])) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No exams yet. <a href="create_exam.php">Create one</a></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>System</strong>
                    <span><i class="bi bi-mortarboard me-1"></i>VLE Exam Manager</span>
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
        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="create_exam.php" class="nav-item">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create</span>
        </a>
        <a href="security_monitoring.php" class="nav-item <?= $stats['active_sessions'] > 0 ? 'has-badge' : '' ?>">
            <i class="bi bi-shield-check-fill"></i>
            <span>Monitor</span>
            <?php if ($stats['active_sessions'] > 0): ?>
            <span class="badge-count"><?= $stats['active_sessions'] ?></span>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/session-timeout.js"></script>
    <script>
    function confirmTerminate(sessionId) {
        if (confirm('Are you sure you want to terminate this exam session?')) {
            fetch('terminate_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'session_id=' + sessionId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) { alert('Session terminated'); location.reload(); }
                else { alert('Failed: ' + data.message); }
            })
            .catch(() => alert('Error terminating session'));
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        const currentPage = window.location.pathname.split('/').pop();
        document.querySelectorAll('.exam-bottom-nav .nav-item').forEach(item => {
            if (item.getAttribute('href') === currentPage) item.classList.add('active');
            else if (currentPage !== '' && item.getAttribute('href') !== currentPage) item.classList.remove('active');
        });
    });
    </script>
    <?php include_once __DIR__ . '/../includes/pwa-footer.php'; ?>
</body>
</html>
