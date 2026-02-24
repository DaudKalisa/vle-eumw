<?php
// dashboard.php - Admin dashboard (Interactive Redesign)
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as total_students FROM students WHERE is_active = TRUE");
$stats['students'] = $result->fetch_assoc()['total_students'];

$result = $conn->query("SELECT COUNT(DISTINCT l.lecturer_id) as total_lecturers FROM lecturers l LEFT JOIN users u ON l.lecturer_id = u.related_lecturer_id WHERE l.is_active = TRUE AND (u.role = 'lecturer' OR u.role IS NULL)");
$stats['lecturers'] = $result->fetch_assoc()['total_lecturers'];

$result = $conn->query("SELECT COUNT(*) as total_courses FROM vle_courses WHERE is_active = TRUE");
$stats['courses'] = $result->fetch_assoc()['total_courses'];

$result = $conn->query("SELECT COUNT(*) as total_enrollments FROM vle_enrollments");
$stats['enrollments'] = $result->fetch_assoc()['total_enrollments'];

$result = $conn->query("SELECT COUNT(*) as total_departments FROM departments");
$stats['departments'] = $result->fetch_assoc()['total_departments'];

$result = $conn->query("SELECT COUNT(*) as total_faculties FROM faculties");
$stats['faculties'] = $result->fetch_assoc()['total_faculties'];

// Registration requests statistics
$result = $conn->query("SELECT COUNT(*) as count FROM course_registration_requests WHERE status = 'pending'");
$stats['pending_requests'] = $result ? $result->fetch_assoc()['count'] : 0;

$result = $conn->query("SELECT COUNT(*) as count FROM course_registration_requests WHERE status = 'approved'");
$stats['approved_requests'] = $result ? $result->fetch_assoc()['count'] : 0;

$result = $conn->query("SELECT COUNT(*) as count FROM course_registration_requests WHERE status = 'rejected'");
$stats['rejected_requests'] = $result ? $result->fetch_assoc()['count'] : 0;

// User role distribution - check if role column exists
$columns = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'role'");
if ($columns->num_rows > 0) {
    // Role column exists
    $result = $conn->query("SELECT COALESCE(role, 'lecturer') as role, COUNT(*) as count FROM lecturers WHERE is_active = TRUE GROUP BY role");
    $user_roles = [];
    while ($row = $result->fetch_assoc()) {
        $user_roles[$row['role']] = $row['count'];
    }
    $stats['admin_users'] = $user_roles['staff'] ?? 0;
    $stats['lecturer_users'] = $user_roles['lecturer'] ?? 0;
} else {
    // Role column doesn't exist, count all as lecturers
    $stats['admin_users'] = 0;
    $stats['lecturer_users'] = $stats['lecturers'];
}

// Count finance users from finance_users table
$table_check = $conn->query("SHOW TABLES LIKE 'finance_users'");
if ($table_check->num_rows > 0) {
    $result = $conn->query("SELECT COUNT(*) as count FROM finance_users WHERE is_active = 1");
    $stats['finance_users'] = $result ? $result->fetch_assoc()['count'] : 0;
} else {
    $stats['finance_users'] = 0;
}

// Recent student registrations
$recent_students = [];
$result = $conn->query("SELECT student_id, full_name, email, enrollment_date FROM students WHERE is_active = TRUE ORDER BY enrollment_date DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_students[] = $row;
    }
}

// Recent course registrations
$recent_registrations = [];
$result = $conn->query("SELECT crr.*, s.full_name, c.course_name 
    FROM course_registration_requests crr 
    LEFT JOIN students s ON crr.student_id = s.student_id 
    LEFT JOIN vle_courses c ON crr.course_id = c.course_id 
    ORDER BY crr.request_date DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_registrations[] = $row;
    }
}

// Active modules count
$result = $conn->query("SELECT COUNT(*) as count FROM modules");
$stats['modules'] = $result ? $result->fetch_assoc()['count'] : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1e3c72">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Admin Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <style>
        /* Interactive Dashboard Styles */
        :root {
            --admin-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-hover-transform: translateY(-4px);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4f8;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: var(--admin-gradient);
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }
        .welcome-card .profile-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .welcome-card .profile-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            border: 3px solid rgba(255,255,255,0.4);
        }
        .welcome-card .welcome-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        .welcome-card .welcome-role {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .welcome-card .welcome-date {
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            margin-top: 1rem;
            display: inline-block;
        }
        
        /* Stats Grid - Mobile 2x2 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (min-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            cursor: pointer;
            border-left: 4px solid var(--accent-color, #667eea);
        }
        .stat-card:hover {
            transform: var(--card-hover-transform);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
        }
        .stat-card .stat-content {
            flex: 1;
            min-width: 0;
        }
        .stat-card .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            display: block;
        }
        .stat-card .stat-label {
            font-size: 0.75rem;
            color: #64748b;
            display: block;
        }
        
        /* Quick Actions - Horizontal Scroll on Mobile */
        .quick-actions {
            display: flex;
            gap: 0.75rem;
            overflow-x: auto;
            padding: 0.5rem 0 1rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .quick-actions::-webkit-scrollbar {
            display: none;
        }
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            min-width: 85px;
            text-decoration: none;
            color: #334155;
            padding: 0.75rem 0.5rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        .action-btn:hover {
            transform: var(--card-hover-transform);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            color: #1e40af;
        }
        .action-btn .action-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        .action-btn span {
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
        }
        
        /* Section Headers with View Toggle */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .view-toggle {
            display: flex;
            gap: 0.25rem;
            background: #e2e8f0;
            padding: 3px;
            border-radius: 8px;
        }
        .view-toggle button {
            border: none;
            background: transparent;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }
        .view-toggle button.active {
            background: white;
            color: #1e40af;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Management Cards - Gallery View */
        .management-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        @media (min-width: 768px) {
            .management-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (min-width: 1200px) {
            .management-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }
        .management-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem 1rem;
            text-align: center;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .management-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--card-gradient);
        }
        .management-card:hover {
            transform: var(--card-hover-transform);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .management-card .card-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 0.75rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        .management-card .card-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .management-card .card-subtitle {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        /* Management Cards - List View */
        .management-list {
            display: none;
            flex-direction: column;
            gap: 0.5rem;
        }
        .management-list.active {
            display: flex;
        }
        .management-grid.hidden {
            display: none;
        }
        .list-item {
            background: white;
            border-radius: 12px;
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }
        .list-item:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }
        .list-item .list-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
            flex-shrink: 0;
        }
        .list-item .list-content {
            flex: 1;
        }
        .list-item .list-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1e293b;
        }
        .list-item .list-subtitle {
            font-size: 0.75rem;
            color: #64748b;
        }
        .list-item .list-arrow {
            color: #94a3b8;
            font-size: 1.1rem;
        }
        
        /* Recent Activity Section */
        .activity-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .activity-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .activity-header h5 {
            font-size: 0.95rem;
            font-weight: 600;
            margin: 0;
        }
        .activity-body {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-item:hover {
            background: #f8fafc;
        }
        .activity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }
        .activity-content {
            flex: 1;
        }
        .activity-text {
            font-size: 0.85rem;
            color: #334155;
        }
        .activity-time {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.25rem;
        }
        
        /* Footer Info */
        .admin-footer-info {
            background: white;
            border-radius: 16px;
            padding: 1rem 1.25rem;
            margin-top: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .info-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }
        .info-item {
            text-align: center;
            min-width: 120px;
        }
        .info-item strong {
            display: block;
            font-size: 0.7rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        .info-item span {
            font-size: 0.85rem;
            color: #475569;
        }
        
        /* Desktop Wrapper padding */
        .admin-wrapper {
            padding: 1rem;
            padding-bottom: 100px;
        }
        @media (min-width: 768px) {
            .admin-wrapper {
                padding: 2rem;
                padding-bottom: 2rem;
            }
        }
        
        /* Hide mobile elements on desktop and vice versa */
        @media (min-width: 768px) {
            .admin-mobile-header,
            .admin-bottom-nav {
                display: none !important;
            }
        }
        @media (max-width: 767.98px) {
            .admin-desktop-nav {
                display: none !important;
            }
        }
        
        /* Pending Alert Enhancement */
        .pending-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: none;
            border-radius: 16px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .pending-alert .alert-icon {
            width: 48px;
            height: 48px;
            background: #f59e0b;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .pending-alert .alert-content {
            flex: 1;
        }
        .pending-alert .alert-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 0.25rem;
        }
        .pending-alert .alert-text {
            font-size: 0.85rem;
            color: #a16207;
        }
        .pending-alert .alert-btn {
            background: #f59e0b;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .pending-alert .alert-btn:hover {
            background: #d97706;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="admin-mobile-header">
        <div class="logo-section">
            <img src="../assets/img/Logo.png" alt="VLE Logo">
            <span>VLE Admin</span>
        </div>
        <div class="header-actions">
            <button class="header-btn has-badge" onclick="location.href='approve_registrations.php'">
                <i class="bi bi-bell-fill"></i>
                <?php if($stats['pending_requests'] > 0): ?>
                <span class="badge-dot"></span>
                <?php endif; ?>
            </button>
            <button class="header-btn" onclick="location.href='messages.php'">
                <i class="bi bi-envelope-fill"></i>
            </button>
            <button class="header-btn" onclick="location.href='profile.php'">
                <i class="bi bi-person-fill"></i>
            </button>
        </div>
    </header>

    <!-- Desktop Navigation -->
    <nav class="admin-desktop-nav">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-brand">
                <img src="../assets/img/Logo.png" alt="VLE Logo">
                <span>VLE-EUMW</span>
            </a>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li><a href="approve_registrations.php" class="nav-link"><i class="bi bi-clipboard-check"></i> Registrations</a></li>
                <li><a href="manage_courses.php" class="nav-link"><i class="bi bi-book"></i> Courses</a></li>
                <li><a href="manage_lecturers.php" class="nav-link"><i class="bi bi-person-badge"></i> Lecturers</a></li>
                <li><a href="manage_students.php" class="nav-link"><i class="bi bi-people"></i> Students</a></li>
            </ul>
            <div class="nav-right">
                <div class="nav-icons">
                    <a href="approve_registrations.php" class="nav-icon-btn position-relative" title="Pending Approvals">
                        <i class="bi bi-bell-fill"></i>
                        <?php if($stats['pending_requests'] > 0): ?>
                        <span class="badge-dot" style="position:absolute;top:4px;right:4px;width:8px;height:8px;background:#ef4444;border-radius:50%;"></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="admin-dropdown">
                    <div class="nav-user">
                        <div class="nav-user-avatar"><?php echo strtoupper(substr($user['display_name'], 0, 1)); ?></div>
                        <span class="nav-user-name"><?php echo htmlspecialchars($user['display_name']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="admin-dropdown-menu">
                        <a href="profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                        <a href="../change_password.php"><i class="bi bi-key"></i> Change Password</a>
                        <a href="university_settings.php"><i class="bi bi-gear"></i> Settings</a>
                        <hr>
                        <a href="../logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="admin-wrapper">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="profile-section">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2 class="welcome-name"><?php echo htmlspecialchars($user['display_name']); ?></h2>
                    <p class="welcome-role mb-0"><i class="bi bi-shield-check"></i> System Administrator</p>
                </div>
            </div>
            <div class="welcome-date">
                <i class="bi bi-calendar3"></i> <?php echo date('l, F j, Y'); ?>
            </div>
        </div>
        
        <!-- Pending Registrations Alert -->
        <?php if($stats['pending_requests'] > 0): ?>
        <div class="pending-alert">
            <div class="alert-icon">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
            <div class="alert-content">
                <div class="alert-title">Action Required</div>
                <div class="alert-text"><?php echo $stats['pending_requests']; ?> pending registration<?php echo $stats['pending_requests'] > 1 ? 's' : ''; ?> awaiting approval</div>
            </div>
            <a href="approve_registrations.php" class="alert-btn">Review Now</a>
        </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-graph-up-arrow me-2"></i>System Overview</h5>
        </div>
        <div class="stats-grid mb-4">
            <a href="manage_students.php" class="stat-card" style="--accent-color: #3b82f6; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['students']); ?></span>
                    <span class="stat-label">Students</span>
                </div>
            </a>
            <a href="manage_lecturers.php" class="stat-card" style="--accent-color: #10b981; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['lecturers']); ?></span>
                    <span class="stat-label">Lecturers</span>
                </div>
            </a>
            <a href="manage_courses.php" class="stat-card" style="--accent-color: #f59e0b; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="bi bi-book-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['courses']); ?></span>
                    <span class="stat-label">Courses</span>
                </div>
            </a>
            <a href="manage_modules.php" class="stat-card" style="--accent-color: #8b5cf6; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="bi bi-collection-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['modules'] ?? 0); ?></span>
                    <span class="stat-label">Modules</span>
                </div>
            </a>
            <a href="#" class="stat-card" style="--accent-color: #06b6d4; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <i class="bi bi-diagram-3-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['enrollments']); ?></span>
                    <span class="stat-label">Enrollments</span>
                </div>
            </a>
            <a href="approve_registrations.php" class="stat-card" style="--accent-color: #ef4444; text-decoration: none;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['pending_requests']); ?></span>
                    <span class="stat-label">Pending</span>
                </div>
            </a>
        </div>
        
        <!-- Quick Actions - Horizontal Scroll -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-lightning-fill me-2"></i>Quick Actions</h5>
        </div>
        <div class="quick-actions mb-4">
            <a href="approve_registrations.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="bi bi-clipboard-check-fill"></i>
                </div>
                <span>Approvals</span>
            </a>
            <a href="manage_students.php?action=add" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="bi bi-person-plus-fill"></i>
                </div>
                <span>Add Student</span>
            </a>
            <a href="manage_lecturers.php?action=add" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-person-badge"></i>
                </div>
                <span>Add Lecturer</span>
            </a>
            <a href="manage_courses.php?action=add" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="bi bi-book-half"></i>
                </div>
                <span>Add Course</span>
            </a>
            <a href="course_reports.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="bi bi-graph-up"></i>
                </div>
                <span>Reports</span>
            </a>
            <a href="module_allocation.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <i class="bi bi-diagram-2-fill"></i>
                </div>
                <span>Allocations</span>
            </a>
            <a href="announcements.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                    <i class="bi bi-megaphone-fill"></i>
                </div>
                <span>Announce</span>
            </a>
            <a href="messages.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);">
                    <i class="bi bi-chat-dots-fill"></i>
                </div>
                <span>Messages</span>
            </a>
            <a href="manage_users.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                    <i class="bi bi-people-fill"></i>
                </div>
                <span>All Users</span>
            </a>
            <a href="manage_administrators.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #dc2626, #991b1b);">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <span>Admins</span>
            </a>
        </div>
        
        <!-- Core Management Section -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-gear-wide-connected me-2"></i>Core Management</h5>
            <div class="view-toggle" id="coreViewToggle">
                <button class="active" onclick="toggleView('core', 'gallery')"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                <button onclick="toggleView('core', 'list')"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>
        <div class="management-grid mb-4" id="coreGallery">
            <a href="manage_students.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                <div class="card-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-people-fill"></i></div>
                <div class="card-title">Students</div>
                <div class="card-subtitle"><?php echo $stats['students']; ?> active</div>
            </a>
            <a href="manage_lecturers.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="card-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-person-badge-fill"></i></div>
                <div class="card-title">Lecturers</div>
                <div class="card-subtitle"><?php echo $stats['lecturers']; ?> active</div>
            </a>
            <a href="manage_courses.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-book-fill"></i></div>
                <div class="card-title">Courses</div>
                <div class="card-subtitle"><?php echo $stats['courses']; ?> courses</div>
            </a>
            <a href="approve_registrations.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626);">
                <div class="card-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-clipboard-check-fill"></i></div>
                <div class="card-title">Approvals</div>
                <div class="card-subtitle"><?php echo $stats['pending_requests']; ?> pending</div>
            </a>
            <a href="manage_finance.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-cash-coin"></i></div>
                <div class="card-title">Finance</div>
                <div class="card-subtitle">Manage users</div>
            </a>
            <a href="course_reports.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #06b6d4, #0891b2);">
                <div class="card-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="card-title">Reports</div>
                <div class="card-subtitle">View analytics</div>
            </a>
            <a href="manage_users.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #6366f1, #4f46e5);">
                <div class="card-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-person-lines-fill"></i></div>
                <div class="card-title">All Users</div>
                <div class="card-subtitle">Manage accounts</div>
            </a>
            <a href="manage_examination_officers.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #db2777);">
                <div class="card-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="bi bi-shield-check"></i></div>
                <div class="card-title">Exam Officers</div>
                <div class="card-subtitle">Manage officers</div>
            </a>
        </div>
        <div class="management-list mb-4" id="coreList">
            <a href="manage_students.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-people-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Students</div>
                    <div class="list-subtitle"><?php echo $stats['students']; ?> active students</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_lecturers.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-person-badge-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Lecturers</div>
                    <div class="list-subtitle"><?php echo $stats['lecturers']; ?> active lecturers</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_courses.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-book-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Courses</div>
                    <div class="list-subtitle"><?php echo $stats['courses']; ?> courses available</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="approve_registrations.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-clipboard-check-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Approvals</div>
                    <div class="list-subtitle"><?php echo $stats['pending_requests']; ?> pending requests</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_finance.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-cash-coin"></i></div>
                <div class="list-content">
                    <div class="list-title">Finance Users</div>
                    <div class="list-subtitle">Manage finance staff</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="course_reports.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Reports</div>
                    <div class="list-subtitle">View system analytics</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_users.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-person-lines-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">All Users</div>
                    <div class="list-subtitle">Manage all system accounts</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_examination_officers.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="bi bi-shield-check"></i></div>
                <div class="list-content">
                    <div class="list-title">Examination Officers</div>
                    <div class="list-subtitle">Manage exam officers</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
        </div>
        
        <!-- Academic Structure Section -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-building me-2"></i>Academic Structure</h5>
            <div class="view-toggle" id="academicViewToggle">
                <button class="active" onclick="toggleView('academic', 'gallery')"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                <button onclick="toggleView('academic', 'list')"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>
        <div class="management-grid mb-4" id="academicGallery">
            <a href="manage_faculties.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #6366f1, #4f46e5);">
                <div class="card-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-building-fill"></i></div>
                <div class="card-title">Faculties</div>
                <div class="card-subtitle"><?php echo $stats['faculties']; ?> total</div>
            </a>
            <a href="manage_departments.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #14b8a6, #0d9488);">
                <div class="card-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"><i class="bi bi-diagram-3-fill"></i></div>
                <div class="card-title">Departments</div>
                <div class="card-subtitle"><?php echo $stats['departments']; ?> total</div>
            </a>
            <a href="manage_programs.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #f97316, #ea580c);">
                <div class="card-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="card-title">Programs</div>
                <div class="card-subtitle">Academic programs</div>
            </a>
            <a href="manage_modules.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #ec4899, #db2777);">
                <div class="card-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="bi bi-collection-fill"></i></div>
                <div class="card-title">Modules</div>
                <div class="card-subtitle"><?php echo $stats['modules'] ?? 0; ?> modules</div>
            </a>
        </div>
        <div class="management-list mb-4" id="academicList">
            <a href="manage_faculties.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"><i class="bi bi-building-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Faculties</div>
                    <div class="list-subtitle"><?php echo $stats['faculties']; ?> faculties</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_departments.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"><i class="bi bi-diagram-3-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Departments</div>
                    <div class="list-subtitle"><?php echo $stats['departments']; ?> departments</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_programs.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Programs</div>
                    <div class="list-subtitle">Academic programs</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="manage_modules.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="bi bi-collection-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Modules</div>
                    <div class="list-subtitle"><?php echo $stats['modules'] ?? 0; ?> course modules</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
        </div>
        
        <!-- Settings Section -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-sliders me-2"></i>Settings & Configuration</h5>
            <div class="view-toggle" id="settingsViewToggle">
                <button class="active" onclick="toggleView('settings', 'gallery')"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                <button onclick="toggleView('settings', 'list')"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>
        <div class="management-grid mb-4" id="settingsGallery">
            <a href="university_settings.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #64748b, #475569);">
                <div class="card-icon" style="background: linear-gradient(135deg, #64748b, #475569);"><i class="bi bi-gear-fill"></i></div>
                <div class="card-title">Settings</div>
                <div class="card-subtitle">University config</div>
            </a>
            <a href="fee_settings.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #22c55e, #16a34a);">
                <div class="card-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a);"><i class="bi bi-cash-stack"></i></div>
                <div class="card-title">Fee Settings</div>
                <div class="card-subtitle">Fee structure</div>
            </a>
            <a href="zoom_settings.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #2563eb, #1d4ed8);">
                <div class="card-icon" style="background: linear-gradient(135deg, #2563eb, #1d4ed8);"><i class="bi bi-camera-video-fill"></i></div>
                <div class="card-title">Zoom</div>
                <div class="card-subtitle">Video settings</div>
            </a>
            <a href="../change_password.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #f43f5e, #e11d48);">
                <div class="card-icon" style="background: linear-gradient(135deg, #f43f5e, #e11d48);"><i class="bi bi-key-fill"></i></div>
                <div class="card-title">Password</div>
                <div class="card-subtitle">Change credentials</div>
            </a>
        </div>
        <div class="management-list mb-4" id="settingsList">
            <a href="university_settings.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #64748b, #475569);"><i class="bi bi-gear-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">University Settings</div>
                    <div class="list-subtitle">Configure university information</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="fee_settings.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a);"><i class="bi bi-cash-stack"></i></div>
                <div class="list-content">
                    <div class="list-title">Fee Settings</div>
                    <div class="list-subtitle">Manage fee structure</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="zoom_settings.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #2563eb, #1d4ed8);"><i class="bi bi-camera-video-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Zoom Settings</div>
                    <div class="list-subtitle">Video conferencing config</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="../change_password.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #f43f5e, #e11d48);"><i class="bi bi-key-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Change Password</div>
                    <div class="list-subtitle">Update your credentials</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
        </div>
        
        <!-- Recent Activity Section -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-person-plus me-2"></i>Recent Students</h5>
                        <a href="manage_students.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="activity-body">
                        <?php if (empty($recent_students)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mb-0 mt-2">No recent students</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_students as $student): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: #3b82f6;"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                                        <span class="text-muted"> - <?php echo htmlspecialchars($student['student_id']); ?></span>
                                    </div>
                                    <div class="activity-time">
                                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($student['email'] ?? 'No email'); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-clipboard-check me-2"></i>Recent Registrations</h5>
                        <a href="approve_registrations.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="activity-body">
                        <?php if (empty($recent_registrations)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mb-0 mt-2">No recent registrations</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_registrations as $reg): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: <?php 
                                    echo $reg['status'] == 'pending' ? '#f59e0b' : 
                                        ($reg['status'] == 'approved' ? '#10b981' : '#ef4444'); 
                                ?>;"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($reg['full_name'] ?? 'Unknown'); ?></strong>
                                        <span class="text-muted"> → <?php echo htmlspecialchars($reg['course_name'] ?? 'Unknown Course'); ?></span>
                                    </div>
                                    <div class="activity-time">
                                        <span class="badge <?php 
                                            echo $reg['status'] == 'pending' ? 'bg-warning text-dark' : 
                                                ($reg['status'] == 'approved' ? 'bg-success' : 'bg-danger'); 
                                        ?>"><?php echo ucfirst($reg['status']); ?></span>
                                        <span class="ms-2"><?php echo date('M j, Y', strtotime($reg['request_date'])); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>System Version</strong>
                    <span><i class="bi bi-info-circle"></i> VLE <?php echo defined('VLE_VERSION') ? VLE_VERSION : '5.0'; ?></span>
                </div>
                <div class="info-item">
                    <strong>Today</strong>
                    <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y'); ?></span>
                </div>
                <div class="info-item">
                    <strong>Role</strong>
                    <span><i class="bi bi-shield-check"></i> Administrator</span>
                </div>
            </div>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="admin-bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="manage_students.php" class="nav-item">
            <i class="bi bi-people-fill"></i>
            <span>Students</span>
        </a>
        <a href="approve_registrations.php" class="nav-item has-badge">
            <i class="bi bi-clipboard-check-fill"></i>
            <span>Approvals</span>
            <?php if($stats['pending_requests'] > 0): ?>
            <span class="badge-count"><?php echo $stats['pending_requests']; ?></span>
            <?php endif; ?>
        </a>
        <a href="manage_courses.php" class="nav-item">
            <i class="bi bi-book-fill"></i>
            <span>Courses</span>
        </a>
        <a href="profile.php" class="nav-item">
            <i class="bi bi-person-fill"></i>
            <span>Profile</span>
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/session-timeout.js"></script>
    <script>
        // View Toggle Function
        function toggleView(section, view) {
            const gallery = document.getElementById(section + 'Gallery');
            const list = document.getElementById(section + 'List');
            const toggle = document.getElementById(section + 'ViewToggle');
            
            if (view === 'gallery') {
                gallery.classList.remove('hidden');
                list.classList.remove('active');
                toggle.querySelectorAll('button')[0].classList.add('active');
                toggle.querySelectorAll('button')[1].classList.remove('active');
            } else {
                gallery.classList.add('hidden');
                list.classList.add('active');
                toggle.querySelectorAll('button')[0].classList.remove('active');
                toggle.querySelectorAll('button')[1].classList.add('active');
            }
            
            // Save preference
            localStorage.setItem('adminView_' + section, view);
        }
        
        // Load saved view preferences
        document.addEventListener('DOMContentLoaded', function() {
            ['core', 'academic', 'settings'].forEach(function(section) {
                const savedView = localStorage.getItem('adminView_' + section);
                if (savedView) {
                    toggleView(section, savedView);
                }
            });
            
            // Highlight current page in bottom nav
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.admin-bottom-nav .nav-item').forEach(item => {
                if (item.getAttribute('href') === currentPage) {
                    item.classList.add('active');
                } else if (currentPage === '' && item.getAttribute('href') === 'dashboard.php') {
                    item.classList.add('active');
                } else if (item.getAttribute('href') !== 'dashboard.php') {
                    item.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>