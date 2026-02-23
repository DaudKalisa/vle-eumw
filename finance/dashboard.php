<?php
// finance/dashboard.php - Complete Finance Dashboard
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

// Recent payments (last 10)
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

// ==================== LECTURER FINANCE STATISTICS ====================
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

// Recent lecturer requests (last 5)
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        }
        
        .navbar-brand img {
            height: 45px;
            width: auto;
            margin-right: 15px;
            filter: brightness(1.2);
        }
        
        .dashboard-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .dashboard-header h2 {
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .dashboard-header p {
            color: #718096;
            margin: 0;
        }
        
        .stat-card {
            border: none;
            border-radius: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            position: relative;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.8) 100%);
        }
        
        .stat-card .card-body {
            padding: 25px;
        }
        
        .stat-card h4 {
            font-size: 2rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-card p {
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
        }
        
        .stat-card i {
            opacity: 0.15;
            position: absolute;
            right: 20px;
            bottom: 20px;
            font-size: 4rem;
        }
        
        .gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .gradient-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .gradient-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .gradient-danger {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }
        
        .gradient-orange {
            background: linear-gradient(135deg, #ffa751 0%, #ffe259 100%);
            color: white;
        }
        
        .gradient-purple {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #2d3748;
        }
        
        .gradient-teal {
            background: linear-gradient(135deg, #74ebd5 0%, #9face6 100%);
            color: white;
        }
        
        .content-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 25px;
        }
        
        .content-card .card-header {
            background: white;
            border-bottom: 2px solid #f7fafc;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
        }
        
        .content-card .card-header h5 {
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .content-card .card-body {
            padding: 25px;
        }
        
        .action-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            height: 100%;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .action-card .card-header {
            border-radius: 12px 12px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .action-card .card-body {
            padding: 20px;
        }
        
        .section-divider {
            border-top: 3px solid #e2e8f0;
            margin: 40px 0;
            position: relative;
        }
        
        .section-divider::after {
            content: attr(data-title);
            position: absolute;
            top: -15px;
            left: 30px;
            background: white;
            padding: 5px 20px;
            font-weight: 600;
            color: #4a5568;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .badge {
            padding: 6px 12px;
            font-weight: 500;
            border-radius: 6px;
        }
        
        .list-group-item {
            border: none;
            border-bottom: 1px solid #f7fafc;
            padding: 15px 0;
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .stat-card h4 {
                font-size: 1.5rem;
            }
            
            .dashboard-header {
                padding: 20px;
            }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-success sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../pictures/logo.bmp" alt="VLE Logo">
                <span>Finance Dashboard</span>
            </a>
            <div class="ms-auto d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-2" style="font-size: 1.5rem;"></i>
                        <span class="fw-semibold"><?php echo htmlspecialchars($user['display_name']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user['display_name']); ?></h6></li>
                        <li><small class="dropdown-header text-muted">Finance Department</small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key"></i> Change Password</a></li>
                        <li><a class="dropdown-item" href="student_finances.php"><i class="bi bi-cash-stack"></i> Student Accounts</a></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>


    <div class="container-fluid px-4 py-4">
        <!-- Executive Summary Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-success">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-cash-stack"></i>
                        <p class="mb-1">Total Revenue</p>
                        <h4>K<?php echo number_format($stats['total_collected']); ?></h4>
                        <small style="opacity: 0.9;">Collected</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-warning">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-exclamation-diamond"></i>
                        <p class="mb-1">Outstanding</p>
                        <h4>K<?php echo number_format($stats['total_outstanding']); ?></h4>
                        <small style="opacity: 0.9;">Pending Collection</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-teal">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-cash-coin"></i>
                        <p class="mb-1">Lecturer Payments Due</p>
                        <h4>K<?php echo number_format($lecturer_stats['total_approved_unpaid']); ?></h4>
                        <small style="opacity: 0.9;">Unpaid Approved</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-info">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-clock-history"></i>
                        <p class="mb-1">Recent Activity</p>
                        <h4><?php echo count($recent_payments) + count($recent_lecturer_requests); ?></h4>
                        <small style="opacity: 0.9;">Payments & Requests</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Navigation Bar -->
        <div class="row g-2 mb-4">
            <div class="col-auto">
                <a href="review_payments.php" class="btn btn-warning btn-lg rounded-pill shadow-sm"><i class="bi bi-check2-square"></i> Review Student Payments</a>
            </div>
            <div class="col-auto">
                <a href="student_finances.php" class="btn btn-info btn-lg rounded-pill shadow-sm"><i class="bi bi-people-fill"></i> Student Accounts</a>
            </div>
            <div class="col-auto">
                <a href="lecturer_finance_requests.php" class="btn btn-purple btn-lg rounded-pill shadow-sm" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #2d3748; border: none;"><i class="bi bi-person-workspace"></i> Lecturer Requests</a>
            </div>
            <div class="col-auto">
                <a href="finance_reports.php" class="btn btn-primary btn-lg rounded-pill shadow-sm"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
            </div>
            <div class="col-auto">
                <a href="process_lecturer_payment.php" class="btn btn-warning btn-lg rounded-pill shadow-sm"><i class="bi bi-cash-coin"></i> Process Lecturer Payment</a>
            </div>
            <div class="col-auto">
                <a href="fee_settings.php" class="btn btn-info btn-lg rounded-pill shadow-sm"><i class="bi bi-gear"></i> Fee Settings</a>
            </div>
            <div class="col-auto">
                <a href="outstanding_balances.php" class="btn btn-danger btn-lg rounded-pill shadow-sm"><i class="bi bi-exclamation-triangle"></i> Outstanding Balances</a>
            </div>
        </div>

        <!-- ==================== STUDENT FINANCE SECTION ==================== -->
        <h4 class="mb-4"><i class="bi bi-mortarboard-fill text-primary"></i> Student Finance Overview</h4>
        
        <!-- Student Key Metrics -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-primary">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-people-fill"></i>
                        <p class="mb-1">Total Students</p>
                        <h4><?php echo number_format($stats['total_students']); ?></h4>
                        <small style="opacity: 0.9;">Active Enrollment</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-info">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-graph-up"></i>
                        <p class="mb-1">Expected Revenue</p>
                        <h4>K<?php echo number_format($stats['total_expected']); ?></h4>
                        <small style="opacity: 0.9;">Total Projected</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-success">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-cash-stack"></i>
                        <p class="mb-1">Revenue Collected</p>
                        <h4>K<?php echo number_format($stats['total_collected']); ?></h4>
                        <?php 
                        $collection_rate = $stats['total_expected'] > 0 ? ($stats['total_collected'] / $stats['total_expected'] * 100) : 0;
                        ?>
                        <small style="opacity: 0.9;"><?php echo number_format($collection_rate, 1); ?>% Collection Rate</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-warning">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-exclamation-diamond"></i>
                        <p class="mb-1">Outstanding</p>
                        <h4>K<?php echo number_format($stats['total_outstanding']); ?></h4>
                        <small style="opacity: 0.9;">Pending Collection</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section Divider -->
        <div class="section-divider" data-title="LECTURER FINANCE MANAGEMENT"></div>

        <!-- ==================== LECTURER FINANCE SECTION ==================== -->
        <h4 class="mb-4"><i class="bi bi-person-workspace text-success"></i> Lecturer Finance Requests</h4>
        
        <!-- Lecturer Key Metrics -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-purple">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-file-earmark-text"></i>
                        <p class="mb-1">Total Requests</p>
                        <h4><?php echo number_format($lecturer_stats['total_requests']); ?></h4>
                        <small style="opacity: 0.9;">All Time</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-orange">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-hourglass-split"></i>
                        <p class="mb-1">Pending Review</p>
                        <h4><?php echo number_format($lecturer_stats['pending_requests']); ?></h4>
                        <small style="opacity: 0.9;">Awaiting Approval</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-teal">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-check-circle"></i>
                        <p class="mb-1">Approved (Unpaid)</p>
                        <h4>K<?php echo number_format($lecturer_stats['total_approved_unpaid']); ?></h4>
                        <small style="opacity: 0.9;"><?php echo $lecturer_stats['approved_requests']; ?> Requests</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card gradient-success">
                    <div class="card-body text-center position-relative">
                        <i class="bi bi-cash-coin"></i>
                        <p class="mb-1">Total Paid</p>
                        <h4>K<?php echo number_format($lecturer_stats['total_paid']); ?></h4>
                        <small style="opacity: 0.9;">Completed Payments</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Lecturer Requests -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history text-warning"></i> Recent Lecturer Requests</h5>
                        <a href="recent_lecturer_requests.php" class="btn btn-sm btn-outline-primary">Manage Recent Requests</a>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">View and manage the most recent lecturer finance requests in a dedicated page for finance staff.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section Divider -->
        <div class="section-divider" data-title="QUICK ACTIONS"></div>

        <!-- Quick Actions -->
        <div class="row g-4">
            <!-- Student Finance Actions -->
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-warning text-white">
                        <i class="bi bi-check2-square"></i> Review Student Payments
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-eye-fill" style="font-size: 3rem; color: #f59e0b; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Review and approve student payment submissions</p>
                        <a href="review_payments.php" class="btn btn-warning w-100">
                            <i class="bi bi-arrow-right-circle"></i> Review Now
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-info text-white">
                        <i class="bi bi-receipt"></i> Student Accounts
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-people-fill" style="font-size: 3rem; color: #06b6d4; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">View and manage all student financial records</p>
                        <a href="student_finances.php" class="btn btn-info w-100">
                            <i class="bi bi-arrow-right-circle"></i> View Accounts
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Lecturer Finance Actions -->
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-purple text-white">
                        <i class="bi bi-person-workspace"></i> Lecturer Requests
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-file-earmark-text-fill" style="font-size: 3rem; color: #a855f7; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Review and process lecturer finance requests</p>
                        <a href="lecturer_finance_requests.php" class="btn btn-purple w-100" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #2d3748; border: none;">
                            <i class="bi bi-arrow-right-circle"></i> Manage Requests
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-primary text-white">
                        <i class="bi bi-file-earmark-bar-graph"></i> Financial Reports
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-graph-up-arrow" style="font-size: 3rem; color: #6366f1; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Generate comprehensive financial analytics</p>
                        <a href="finance_reports.php" class="btn btn-primary w-100">
                            <i class="bi bi-arrow-right-circle"></i> View Reports
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-success text-white">
                        <i class="bi bi-plus-circle"></i> Record Payment
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-cash-coin" style="font-size: 3rem; color: #10b981; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Record new student payment transactions</p>
                        <a href="record_payment.php" class="btn btn-success w-100">
                            <i class="bi bi-arrow-right-circle"></i> Add Payment
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-orange text-white">
                        <i class="bi bi-cash-stack"></i> Process Lecturer Payment
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-credit-card-fill" style="font-size: 3rem; color: #f59e0b; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Process approved lecturer payments</p>
                        <a href="process_lecturer_payment.php" class="btn btn-warning w-100">
                            <i class="bi bi-arrow-right-circle"></i> Process Now
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-teal text-white">
                        <i class="bi bi-gear"></i> Fee Settings
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-sliders" style="font-size: 3rem; color: #14b8a6; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">Configure system fee structures</p>
                        <a href="fee_settings.php" class="btn btn-info w-100">
                            <i class="bi bi-arrow-right-circle"></i> Manage Fees
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="action-card">
                    <div class="card-header gradient-danger text-white">
                        <i class="bi bi-exclamation-triangle"></i> Outstanding Balances
                    </div>
                    <div class="card-body text-center">
                        <i class="bi bi-exclamation-diamond-fill" style="font-size: 3rem; color: #ef4444; opacity: 0.3;"></i>
                        <p class="mt-3 mb-3">View students with pending payments</p>
                        <a href="outstanding_balances.php" class="btn btn-danger w-100">
                            <i class="bi bi-arrow-right-circle"></i> View Outstanding
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Session Timeout Manager -->
    <script src="../assets/js/session-timeout.js"></script>
    <!-- End of dashboard -->

        <!-- Section Divider -->
        <div class="section-divider" data-title="ANALYTICS & ACTIVITY"></div>

        <!-- Analytics & Activity -->
        <div class="row g-4 mb-4">
            <!-- Payment Distribution Line Graph (narrower) -->
            <div class="col-xl-7 col-lg-7">
                <div class="content-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-graph-up text-primary"></i> Student Payment Distribution</h5>
                        <span class="badge bg-primary"><?php echo array_sum($payment_distribution); ?> Students</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 160px; max-width: 100%;">
                            <canvas id="paymentChart" style="max-width:100%; height:160px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Student Transactions (narrower) -->
            <div class="col-xl-4 col-lg-4">
                <div class="content-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history text-success"></i> Recent Payments</h5>
                        <a href="payment_history.php" class="btn btn-sm btn-outline-success">View All</a>
                    </div>
                    <div class="card-body p-0" style="max-height: 350px; overflow-y: auto;">
                        <?php if (empty($recent_payments)): ?>
                            <div class="alert alert-info border-0 m-3">
                                <i class="bi bi-info-circle"></i> No recent transactions found
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php
        foreach ($recent_payments as $payment): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <strong><?php echo htmlspecialchars($payment['student_name'] ?? 'N/A'); ?></strong>
                    <br><small class="text-muted">ID: <?php echo htmlspecialchars($payment['student_id'] ?? ''); ?></small>
                </div>
                <div>
                    <span class="badge bg-success">K<?php echo number_format($payment['amount'] ?? 0, 2); ?></span>
                    <br><small class="text-muted"><?php echo !empty($payment['date']) ? date('M d, Y', strtotime($payment['date'])) : 'N/A'; ?></small>
                </div>
            </div>
<?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</div>
</div>
</div>
<!-- Add any additional dashboard content below -->

<!-- Scripts -->
<script src="../assets/js/chart.min.js"></script>
    <script>
        // Chart.js usage for payment distribution with sample data for visualization
        var ctx = document.getElementById('paymentChart').getContext('2d');
        var paymentChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['0%', '25%', '50%', '75%', '100%'],
                datasets: [{
                    label: 'Students',
                    data: [
                        <?php echo $payment_distribution[0] ?? 10; ?>,
                        <?php echo $payment_distribution[25] ?? 15; ?>,
                        <?php echo $payment_distribution[50] ?? 20; ?>,
                        <?php echo $payment_distribution[75] ?? 12; ?>,
                        <?php echo $payment_distribution[100] ?? 30; ?>
                    ],
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99,102,241,0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    </script>
<!-- Session Timeout Manager -->
<script src="../assets/js/session-timeout.js"></script>
</body>
</html>