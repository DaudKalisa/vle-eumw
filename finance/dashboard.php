<?php
// finance/dashboard.php - Complete Finance Dashboard (Interactive Redesign)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get fee settings
$fee_result = $conn->query("SELECT * FROM fee_settings LIMIT 1");
$fee_settings = $fee_result ? $fee_result->fetch_assoc() : null;
if (!$fee_settings) {
    $fee_settings = [
        'application_fee' => 5500,
        'registration_fee' => 39500,
        'tuition_degree' => 500000
    ];
}

// ==================== STUDENT FINANCE STATISTICS ====================
function getStudentStats($conn, $fee_settings) {
    $stats = [];

    // Total students
    $result = $conn->query("SELECT COUNT(*) as total FROM students WHERE is_active = TRUE");
    $stats['total_students'] = $result->fetch_assoc()['total'];

    // Application Fee Statistics
    $result = $conn->query("SELECT 
        SUM(sf.application_fee_paid) as total_app_paid,
        COUNT(*) as total_records
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE");
    $app_data = $result ? $result->fetch_assoc() : null;
    $stats['total_application_paid'] = $app_data['total_app_paid'] ?? 0;
    $stats['expected_application'] = $stats['total_students'] * $fee_settings['application_fee'];

    // Registration Fee Statistics
    $result = $conn->query("SELECT 
        SUM(sf.registration_paid) as total_reg_paid 
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE");
    $reg_data = $result ? $result->fetch_assoc() : null;
    $stats['total_registration_paid'] = $reg_data['total_reg_paid'] ?? 0;
    $stats['expected_registration'] = $stats['total_students'] * $fee_settings['registration_fee'];

    // Total Revenue Collected
    $result = $conn->query("SELECT SUM(sf.total_paid) as total 
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE");
    $total_data = $result ? $result->fetch_assoc() : null;
    $stats['total_collected'] = $total_data['total'] ?? 0;

    // Expected Total Revenue
    $result = $conn->query("SELECT 
        SUM(sf.expected_total) as total_expected 
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE");
    $expected_data = $result ? $result->fetch_assoc() : null;
    $stats['total_expected'] = $expected_data['total_expected'] ?? 0;

    // Outstanding Balance
    $stats['total_outstanding'] = $stats['total_expected'] - $stats['total_collected'];

    // Tuition collected
    $stats['total_tuition_paid'] = $stats['total_collected'] - $stats['total_application_paid'] - $stats['total_registration_paid'];
    
    // Students with outstanding balance
    $result = $conn->query("SELECT COUNT(*) as count 
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE AND sf.total_paid < sf.expected_total");
    $stats['students_with_balance'] = $result ? $result->fetch_assoc()['count'] : 0;

    // Students by payment status
    $result = $conn->query("SELECT sf.payment_percentage, COUNT(*) as count 
        FROM student_finances sf
        JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci
        WHERE s.is_active = TRUE
        GROUP BY sf.payment_percentage 
        ORDER BY sf.payment_percentage");
    $payment_distribution = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $payment_distribution[$row['payment_percentage']] = $row['count'];
        }
    }

    return $stats;
}
$stats = getStudentStats($conn, $fee_settings);

// ==================== LECTURER FINANCE STATISTICS ====================
function getLecturerStats($conn) {
    $lecturer_stats = [];

    // Total lecturer finance requests
    $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests");
    $lecturer_stats['total_requests'] = $result ? $result->fetch_assoc()['total'] : 0;

    // Pending requests
    $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE status = 'pending'");
    $lecturer_stats['pending_requests'] = $result ? $result->fetch_assoc()['total'] : 0;

    // Approved requests
    $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE status = 'approved'");
    $lecturer_stats['approved_requests'] = $result ? $result->fetch_assoc()['total'] : 0;

    // Total amount requested (pending + approved)
    $result = $conn->query("SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE status IN ('pending', 'approved')");
    $lecturer_stats['total_requested'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Total amount paid
    $result = $conn->query("SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE status = 'paid'");
    $lecturer_stats['total_paid'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    // Total amount approved (not yet paid)
    $result = $conn->query("SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE status = 'approved'");
    $lecturer_stats['total_approved_unpaid'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;

    return $lecturer_stats;
}
$lecturer_stats = getLecturerStats($conn);

// ==================== PENDING PAYMENT SUBMISSIONS ====================
$pending_submissions = 0;
$result = $conn->query("SELECT COUNT(*) as count FROM payment_submissions WHERE status = 'pending'");
if ($result) {
    $pending_submissions = $result->fetch_assoc()['count'];
}

// ==================== RECENT PAYMENTS/REQUESTS ====================
function getRecentPayments($conn) {
    $recent_payments = [];
    $result = $conn->query("SELECT pt.*, s.full_name 
        FROM payment_transactions pt 
        JOIN students s ON pt.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci 
        ORDER BY pt.created_at DESC LIMIT 10");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_payments[] = $row;
        }
    }
    return $recent_payments;
}

function getRecentLecturerRequests($conn) {
    $recent_lecturer_requests = [];
    $result = $conn->query("
        SELECT lfr.*, l.full_name as lecturer_name, l.department
        FROM lecturer_finance_requests lfr
        LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
        ORDER BY lfr.submission_date DESC
        LIMIT 5
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_lecturer_requests[] = $row;
        }
    }
    return $recent_lecturer_requests;
}

$recent_payments = getRecentPayments($conn);
$recent_lecturer_requests = getRecentLecturerRequests($conn);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#1e3c72">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Finance Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/finance-dashboard.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        /* Interactive Finance Dashboard Styles */
        :root {
            --finance-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --card-hover-transform: translateY(-4px);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4f8;
        }
        
        /* Welcome Card */
        .welcome-card {
            background: var(--finance-gradient);
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 40px rgba(30, 60, 114, 0.3);
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
        
        /* Stats Grid */
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
            border-left: 4px solid var(--accent-color, #1e3c72);
            text-decoration: none;
            color: inherit;
        }
        .stat-card:hover {
            transform: var(--card-hover-transform);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            color: inherit;
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
            font-size: 1.1rem;
            font-weight: 700;
            display: block;
        }
        .stat-card .stat-label {
            font-size: 0.7rem;
            color: #64748b;
            display: block;
        }
        
        /* Quick Actions */
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
            color: inherit;
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
        
        /* List View */
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
            color: inherit;
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
        
        /* Activity Card */
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
            max-height: 320px;
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
        
        /* Pending Alert */
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
        
        /* Chart Container */
        .chart-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .chart-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .chart-header h5 {
            font-size: 0.95rem;
            font-weight: 600;
            margin: 0;
        }
        .chart-body {
            padding: 1rem 1.25rem;
        }
        
        /* Wrapper padding */
        .finance-wrapper {
            padding: 1rem;
            padding-bottom: 100px;
        }
        @media (min-width: 768px) {
            .finance-wrapper {
                padding: 2rem;
                padding-bottom: 2rem;
            }
        }
        
        /* Hide mobile/desktop elements */
        @media (min-width: 768px) {
            .finance-mobile-header,
            .finance-bottom-nav {
                display: none !important;
            }
        }
        @media (max-width: 767.98px) {
            .desktop-navbar {
                display: none !important;
            }
        }
    </style>
</head>
<body class="finance-dashboard">
    
    <!-- Mobile Header -->
    <header class="finance-mobile-header">
        <div class="header-content">
            <div class="logo-section">
                <img src="../assets/img/Logo.png" alt="VLE">
                <span>Finance Portal</span>
            </div>
            <div class="header-actions">
                <a href="review_payments.php" class="header-btn" title="Review Payments">
                    <i class="bi bi-check2-square"></i>
                    <?php if($pending_submissions > 0): ?>
                    <span class="badge-dot" style="position:absolute;top:2px;right:2px;width:8px;height:8px;background:#ef4444;border-radius:50%;"></span>
                    <?php endif; ?>
                </a>
                <a href="profile.php" class="header-btn" title="Profile">
                    <i class="bi bi-person-circle"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Desktop Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark desktop-navbar sticky-top" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center fw-bold" href="dashboard.php">
                <img src="../assets/img/Logo.png" alt="Logo" style="height:38px;width:auto;margin-right:10px;">
                <span>VLE Finance</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="student_finances.php"><i class="bi bi-people me-1"></i>Students</a></li>
                    <li class="nav-item"><a class="nav-link" href="lecturer_finance_requests.php"><i class="bi bi-person-workspace me-1"></i>Lecturers</a></li>
                    <li class="nav-item"><a class="nav-link" href="review_payments.php"><i class="bi bi-check2-square me-1"></i>Review</a></li>
                    <li class="nav-item"><a class="nav-link" href="finance_reports.php"><i class="bi bi-graph-up me-1"></i>Reports</a></li>
                </ul>
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" data-bs-toggle="dropdown">
                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;font-weight:bold;">
                                <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                            </div>
                            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                            <li><a class="dropdown-item" href="fee_settings.php"><i class="bi bi-gear me-2"></i>Fee Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <div class="finance-wrapper">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="profile-section">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2 class="welcome-name"><?php echo htmlspecialchars($user['display_name']); ?></h2>
                    <p class="welcome-role mb-0"><i class="bi bi-cash-coin"></i> Finance Officer</p>
                </div>
            </div>
            <div class="welcome-date">
                <i class="bi bi-calendar3"></i> <?php echo date('l, F j, Y'); ?>
            </div>
        </div>
        
        <!-- Pending Payments Alert -->
        <?php if($pending_submissions > 0 || $lecturer_stats['pending_requests'] > 0): ?>
        <div class="pending-alert">
            <div class="alert-icon">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
            <div class="alert-content">
                <div class="alert-title">Action Required</div>
                <div class="alert-text">
                    <?php if($pending_submissions > 0): ?>
                    <?php echo $pending_submissions; ?> payment submission<?php echo $pending_submissions > 1 ? 's' : ''; ?> pending review
                    <?php endif; ?>
                    <?php if($pending_submissions > 0 && $lecturer_stats['pending_requests'] > 0): ?> | <?php endif; ?>
                    <?php if($lecturer_stats['pending_requests'] > 0): ?>
                    <?php echo $lecturer_stats['pending_requests']; ?> lecturer request<?php echo $lecturer_stats['pending_requests'] > 1 ? 's' : ''; ?> pending
                    <?php endif; ?>
                </div>
            </div>
            <a href="review_payments.php" class="alert-btn">Review Now</a>
        </div>
        <?php endif; ?>
        
        <!-- Key Metrics -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-graph-up-arrow me-2"></i>Financial Overview</h5>
        </div>
        <div class="stats-grid mb-4">
            <a href="student_finances.php" class="stat-card" style="--accent-color: #10b981;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value text-success">K<?php echo number_format($stats['total_collected']); ?></span>
                    <span class="stat-label">Collected</span>
                </div>
            </a>
            <a href="student_finances.php?filter=outstanding" class="stat-card" style="--accent-color: #ef4444;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="bi bi-exclamation-circle"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value text-danger">K<?php echo number_format($stats['total_outstanding']); ?></span>
                    <span class="stat-label">Outstanding</span>
                </div>
            </a>
            <a href="#" class="stat-card" style="--accent-color: #3b82f6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value">K<?php echo number_format($stats['total_expected']); ?></span>
                    <span class="stat-label">Expected</span>
                </div>
            </a>
            <a href="#" class="stat-card" style="--accent-color: #f59e0b;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="bi bi-percent"></i>
                </div>
                <div class="stat-content">
                    <?php $collection_rate = $stats['total_expected'] > 0 ? ($stats['total_collected'] / $stats['total_expected'] * 100) : 0; ?>
                    <span class="stat-value"><?php echo number_format($collection_rate, 1); ?>%</span>
                    <span class="stat-label">Collection</span>
                </div>
            </a>
            <a href="student_finances.php" class="stat-card" style="--accent-color: #8b5cf6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value"><?php echo number_format($stats['total_students']); ?></span>
                    <span class="stat-label">Students</span>
                </div>
            </a>
            <a href="lecturer_finance_requests.php" class="stat-card" style="--accent-color: #06b6d4;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <i class="bi bi-person-workspace"></i>
                </div>
                <div class="stat-content">
                    <span class="stat-value">K<?php echo number_format($lecturer_stats['total_approved_unpaid']); ?></span>
                    <span class="stat-label">Lect. Due</span>
                </div>
            </a>
        </div>
        
        <!-- Quick Actions -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-lightning-fill me-2"></i>Quick Actions</h5>
        </div>
        <div class="quick-actions mb-4">
            <a href="review_payments.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="bi bi-check2-square"></i>
                </div>
                <span>Review</span>
            </a>
            <a href="record_payment.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-plus-circle"></i>
                </div>
                <span>Record Pay</span>
            </a>
            <a href="student_finances.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="bi bi-people"></i>
                </div>
                <span>Students</span>
            </a>
            <a href="lecturer_finance_requests.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="bi bi-person-workspace"></i>
                </div>
                <span>Lecturers</span>
            </a>
            <a href="charge_exam_fees.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                    <i class="bi bi-journal-x"></i>
                </div>
                <span>Exam Fees</span>
            </a>
            <a href="student_finances.php?filter=outstanding" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <span>Outstanding</span>
            </a>
            <a href="finance_reports.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <i class="bi bi-graph-up"></i>
                </div>
                <span>Reports</span>
            </a>
            <a href="fee_settings.php" class="action-btn">
                <div class="action-icon" style="background: linear-gradient(135deg, #64748b, #475569);">
                    <i class="bi bi-gear"></i>
                </div>
                <span>Settings</span>
            </a>
        </div>
        
        <!-- Student Finances Section -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-mortarboard me-2"></i>Student Finances</h5>
            <div class="view-toggle" id="studentViewToggle">
                <button class="active" onclick="toggleView('student', 'gallery')"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                <button onclick="toggleView('student', 'list')"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>
        <div class="management-grid mb-4" id="studentGallery">
            <a href="student_finances.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                <div class="card-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-file-earmark-text"></i></div>
                <div class="card-title">Application Fees</div>
                <div class="card-subtitle">K<?php echo number_format($stats['total_application_paid']); ?></div>
            </a>
            <a href="student_finances.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="card-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-journal-check"></i></div>
                <div class="card-title">Registration Fees</div>
                <div class="card-subtitle">K<?php echo number_format($stats['total_registration_paid']); ?></div>
            </a>
            <a href="student_finances.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="card-title">Tuition Fees</div>
                <div class="card-subtitle">K<?php echo number_format($stats['total_tuition_paid']); ?></div>
            </a>
            <a href="student_finances.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #06b6d4, #0891b2);">
                <div class="card-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="bi bi-arrow-right"></i></div>
                <div class="card-title">View All</div>
                <div class="card-subtitle">Student accounts</div>
            </a>
        </div>
        <div class="management-list mb-4" id="studentList">
            <a href="student_finances.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi bi-file-earmark-text"></i></div>
                <div class="list-content">
                    <div class="list-title">Application Fees</div>
                    <div class="list-subtitle">K<?php echo number_format($stats['total_application_paid']); ?> collected</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="student_finances.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-journal-check"></i></div>
                <div class="list-content">
                    <div class="list-title">Registration Fees</div>
                    <div class="list-subtitle">K<?php echo number_format($stats['total_registration_paid']); ?> collected</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="student_finances.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="list-content">
                    <div class="list-title">Tuition Fees</div>
                    <div class="list-subtitle">K<?php echo number_format($stats['total_tuition_paid']); ?> collected</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="student_finances.php?filter=outstanding" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="list-content">
                    <div class="list-title">Outstanding Balances</div>
                    <div class="list-subtitle"><?php echo number_format($stats['students_with_balance']); ?> students with balance</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
        </div>
        
        <!-- Lecturer Finances Section -->
        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-person-workspace me-2"></i>Lecturer Finances</h5>
            <div class="view-toggle" id="lecturerViewToggle">
                <button class="active" onclick="toggleView('lecturer', 'gallery')"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                <button onclick="toggleView('lecturer', 'list')"><i class="bi bi-list-ul"></i></button>
            </div>
        </div>
        <div class="management-grid mb-4" id="lecturerGallery">
            <a href="lecturer_finance_requests.php?status=pending" class="management-card" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-hourglass-split"></i></div>
                <div class="card-title">Pending</div>
                <div class="card-subtitle"><?php echo number_format($lecturer_stats['pending_requests']); ?> requests</div>
            </a>
            <a href="lecturer_finance_requests.php?status=approved" class="management-card" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
                <div class="card-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-check-circle"></i></div>
                <div class="card-title">Approved</div>
                <div class="card-subtitle"><?php echo number_format($lecturer_stats['approved_requests']); ?> requests</div>
            </a>
            <a href="lecturer_finance_requests.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-cash-coin"></i></div>
                <div class="card-title">Total Paid</div>
                <div class="card-subtitle">K<?php echo number_format($lecturer_stats['total_paid']); ?></div>
            </a>
            <a href="lecturer_finance_requests.php" class="management-card" style="--card-gradient: linear-gradient(135deg, #06b6d4, #0891b2);">
                <div class="card-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"><i class="bi bi-arrow-right"></i></div>
                <div class="card-title">View All</div>
                <div class="card-subtitle">All requests</div>
            </a>
        </div>
        <div class="management-list mb-4" id="lecturerList">
            <a href="lecturer_finance_requests.php?status=pending" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="bi bi-hourglass-split"></i></div>
                <div class="list-content">
                    <div class="list-title">Pending Requests</div>
                    <div class="list-subtitle"><?php echo number_format($lecturer_stats['pending_requests']); ?> awaiting review</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="lecturer_finance_requests.php?status=approved" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-check-circle"></i></div>
                <div class="list-content">
                    <div class="list-title">Approved Requests</div>
                    <div class="list-subtitle"><?php echo number_format($lecturer_stats['approved_requests']); ?> approved</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
            <a href="lecturer_finance_requests.php" class="list-item">
                <div class="list-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"><i class="bi bi-cash-coin"></i></div>
                <div class="list-content">
                    <div class="list-title">Total Paid Out</div>
                    <div class="list-subtitle">K<?php echo number_format($lecturer_stats['total_paid']); ?> disbursed</div>
                </div>
                <i class="bi bi-chevron-right list-arrow"></i>
            </a>
        </div>
        
        <!-- Charts and Recent Activity -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-bar-chart-line text-info me-2"></i>Revenue Overview</h5>
                    </div>
                    <div class="chart-body">
                        <div style="height: 280px;">
                            <canvas id="revenueBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="chart-card">
                    <div class="chart-header">
                        <h5><i class="bi bi-pie-chart text-primary me-2"></i>Collection Rate</h5>
                    </div>
                    <div class="chart-body">
                        <div style="height: 280px;">
                            <canvas id="collectionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="activity-card">
                    <div class="activity-header">
                        <h5><i class="bi bi-receipt me-2"></i>Recent Payments</h5>
                        <a href="payment_history.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="activity-body">
                        <?php if (empty($recent_payments)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mb-0 mt-2">No recent payments</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($recent_payments, 0, 5) as $payment): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: #10b981;"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($payment['full_name'] ?? 'Unknown'); ?></strong>
                                        <span class="text-success fw-bold ms-2">K<?php echo number_format($payment['amount'] ?? 0); ?></span>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo ucfirst($payment['payment_type'] ?? 'Payment'); ?> • 
                                        <?php echo date('M j, Y', strtotime($payment['created_at'] ?? 'now')); ?>
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
                        <h5><i class="bi bi-person-workspace me-2"></i>Lecturer Requests</h5>
                        <a href="lecturer_finance_requests.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="activity-body">
                        <?php if (empty($recent_lecturer_requests)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mb-0 mt-2">No recent requests</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_lecturer_requests as $req): ?>
                            <div class="activity-item">
                                <div class="activity-dot" style="background: <?php 
                                    echo $req['status'] == 'pending' ? '#f59e0b' : 
                                        ($req['status'] == 'approved' ? '#10b981' : 
                                        ($req['status'] == 'paid' ? '#3b82f6' : '#ef4444')); 
                                ?>;"></div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($req['lecturer_name'] ?? 'Unknown'); ?></strong>
                                        <span class="ms-2">K<?php echo number_format($req['total_amount'] ?? 0); ?></span>
                                    </div>
                                    <div class="activity-time">
                                        <span class="badge <?php 
                                            echo $req['status'] == 'pending' ? 'bg-warning text-dark' : 
                                                ($req['status'] == 'approved' ? 'bg-success' : 
                                                ($req['status'] == 'paid' ? 'bg-primary' : 'bg-danger')); 
                                        ?>"><?php echo ucfirst($req['status']); ?></span>
                                        <span class="ms-2"><?php echo date('M j, Y', strtotime($req['submission_date'])); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- End finance-wrapper -->
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-md-3">
                    <a href="student_finances.php" class="card text-decoration-none h-100 border-0 shadow-sm hover-shadow" style="transition: all 0.3s; background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%); border-top: 3px solid #06b6d4;">
                        <div class="card-body text-center py-3">
                            <div style="font-size: 1.5rem; color: #0e7490;" class="mb-2">
                                <i class="bi bi-arrow-right"></i>
                            </div>
                            <div class="small fw-bold" style="color: #0e7490;">View All</div>
                            <div class="small" style="color: #06b6d4;">Student accounts</div>
                        </div>
                    </a>
                </div>


    <!-- Mobile Bottom Navigation -->
    <nav class="finance-bottom-nav">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-item active">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a href="student_finances.php" class="nav-item">
                <i class="bi bi-people"></i>
                <span>Students</span>
            </a>
            <a href="review_payments.php" class="nav-item">
                <i class="bi bi-check2-square"></i>
                <span>Review</span>
            </a>
            <a href="lecturer_finance_requests.php" class="nav-item">
                <i class="bi bi-person-workspace"></i>
                <span>Lecturers</span>
            </a>
            <a href="finance_reports.php" class="nav-item">
                <i class="bi bi-graph-up"></i>
                <span>Reports</span>
            </a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/session-timeout.js"></script>
    
    <script>
        // Revenue Bar Chart
        new Chart(document.getElementById('revenueBarChart'), {
            type: 'bar',
            data: {
                labels: ['Expected', 'Collected', 'Outstanding', 'Lecturer Due'],
                datasets: [{
                    label: 'Amount (K)',
                    data: [
                        <?php echo $stats['total_expected']; ?>,
                        <?php echo $stats['total_collected']; ?>,
                        <?php echo $stats['total_outstanding']; ?>,
                        <?php echo $lecturer_stats['total_approved_unpaid']; ?>
                    ],
                    backgroundColor: ['#3182ce', '#38a169', '#e53e3e', '#805ad5'],
                    borderRadius: 8,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });

        // Collection Rate Doughnut Chart
        new Chart(document.getElementById('collectionChart'), {
            type: 'doughnut',
            data: {
                labels: ['Collected', 'Outstanding'],
                datasets: [{
                    data: [<?php echo $stats['total_collected']; ?>, <?php echo $stats['total_outstanding']; ?>],
                    backgroundColor: ['#38a169', '#e53e3e'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '65%'
            }
        });

        // Mobile bottom nav active state
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.finance-bottom-nav .nav-item').forEach(item => {
                if (item.getAttribute('href') === currentPage) {
                    item.classList.add('active');
                } else if (currentPage === '' && item.getAttribute('href') === 'dashboard.php') {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>