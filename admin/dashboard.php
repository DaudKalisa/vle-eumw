<?php
// dashboard.php - Admin dashboard
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as total_students FROM students WHERE is_active = TRUE");
$stats['students'] = $result->fetch_assoc()['total_students'];

$result = $conn->query("SELECT COUNT(*) as total_lecturers FROM lecturers WHERE is_active = TRUE");
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
    $stats['finance_users'] = $user_roles['finance'] ?? 0;
    $stats['admin_users'] = $user_roles['staff'] ?? 0;
    $stats['lecturer_users'] = $user_roles['lecturer'] ?? 0;
} else {
    // Role column doesn't exist, count all as lecturers
    $stats['finance_users'] = 0;
    $stats['admin_users'] = 0;
    $stats['lecturer_users'] = $stats['lecturers'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            min-height: 100vh;
        }
        
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%) !important;
        }
        
        .navbar-brand img {
            height: 40px;
            width: auto;
            margin-right: 10px;
        }
        
        .dashboard-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .dashboard-header h2 {
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }
        
        .dashboard-header .subtitle {
            color: #6c757d;
            font-size: 0.95rem;
        }
        
        .stat-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card .card-body {
            padding: 25px;
        }
        
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.9;
        }
        
        .stat-card h3 {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 15px 0 5px;
        }
        
        .stat-card p {
            font-size: 0.9rem;
            font-weight: 500;
            opacity: 0.95;
            margin: 0;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            margin: 40px 0 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #e8ecf1;
        }
        
        .section-header i {
            font-size: 1.8rem;
            margin-right: 12px;
            color: #1e3c72;
        }
        
        .section-header h4 {
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
            font-size: 1.5rem;
        }
        
        .action-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            height: 100%;
            overflow: hidden;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .action-card .card-header {
            padding: 20px;
            font-weight: 600;
            border: none;
            font-size: 1.1rem;
        }
        
        .action-card .card-body {
            padding: 20px;
            background: white;
        }
        
        .action-card .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 12px 15px;
            transition: all 0.2s ease;
            border: none;
        }
        
        .action-card .btn:hover {
            transform: scale(1.02);
        }
        
        .priority-card {
            border-left: 5px solid #ffc107 !important;
            animation: pulse-border 2s infinite;
        }
        
        @keyframes pulse-border {
            0%, 100% { border-left-color: #ffc107; }
            50% { border-left-color: #ffdb4d; }
        }
        
        .alert-stat {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe8a1 100%);
            border: none;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        
        .alert-stat h4 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #856404;
            margin-bottom: 5px;
        }
        
        .alert-stat small {
            color: #856404;
            font-weight: 500;
        }
        
        .mini-stat {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }
        
        .mini-stat:last-child {
            margin-bottom: 0;
        }
        
        .badge-custom {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .stat-card h3 {
                font-size: 1.8rem;
            }
            
            .section-header h4 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../pictures/logo.bmp" alt="VLE Logo">
                <span>VLE Admin</span>
            </a>
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <a class="nav-link" href="approve_registrations.php">
                    <i class="bi bi-clipboard-check"></i> Registration Approvals
                </a>
                
                <!-- Profile Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="rounded-circle bg-white text-dark d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; font-weight: bold;">
                            <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($user['display_name']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                        <li><h6 class="dropdown-header"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user['display_name']); ?></h6></li>
                        <li><small class="dropdown-header text-muted">Administrator</small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key"></i> Change Password</a></li>
                        <li><a class="dropdown-item" href="university_settings.php"><i class="bi bi-gear"></i> System Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-speedometer2"></i> Administration Dashboard</h2>
                    <p class="subtitle mb-0">
                        <i class="bi bi-calendar-event"></i> <?php echo date('l, F j, Y'); ?> 
                        <span class="mx-2">•</span>
                        <i class="bi bi-clock"></i> <?php echo date('h:i A'); ?>
                    </p>
                </div>
                <div class="text-end">
                    <small class="text-muted d-block">Welcome back,</small>
                    <h5 class="mb-0 text-primary"><?php echo htmlspecialchars($user['display_name']); ?></h5>
                </div>
            </div>
        </div>

        <!-- Key Performance Indicators -->
        <div class="quick-stats-grid">
            <div class="stat-card card text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="card-body text-center">
                    <i class="bi bi-people-fill"></i>
                    <h3><?php echo $stats['students']; ?></h3>
                    <p>TOTAL STUDENTS</p>
                </div>
            </div>
            
            <div class="stat-card card text-white" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="card-body text-center">
                    <i class="bi bi-person-badge-fill"></i>
                    <h3><?php echo $stats['lecturers']; ?></h3>
                    <p>TOTAL LECTURERS</p>
                </div>
            </div>
            
            <div class="stat-card card text-white" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="card-body text-center">
                    <i class="bi bi-book-fill"></i>
                    <h3><?php echo $stats['courses']; ?></h3>
                    <p>ACTIVE COURSES</p>
                </div>
            </div>
            
            <div class="stat-card card text-white" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <div class="card-body text-center">
                    <i class="bi bi-diagram-3-fill"></i>
                    <h3><?php echo $stats['enrollments']; ?></h3>
                    <p>TOTAL ENROLLMENTS</p>
                </div>
            </div>
        </div>

        <!-- Priority: Registration Approvals Section -->
        <div class="section-header">
            <i class="bi bi-clipboard-check-fill"></i>
            <h4>Course Registration Approvals</h4>
            <?php if($stats['pending_requests'] > 0): ?>
            <span class="badge bg-warning text-dark ms-3 badge-custom">
                <?php echo $stats['pending_requests']; ?> Pending
            </span>
            <?php endif; ?>
        </div>
        
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="action-card card priority-card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="bi bi-clipboard-check-fill"></i> Registration Request Management
                    </div>
                    <div class="card-body" style="padding: 15px;">
                        <div class="text-center mb-2">
                            <h2 class="mb-0" style="color: #856404;"><?php echo $stats['pending_requests']; ?></h2>
                            <small class="text-muted">Pending Requests</small>
                        </div>
                        
                        <a href="approve_registrations.php" class="btn btn-warning w-100">
                            <i class="bi bi-clipboard-check-fill"></i> Review Requests
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="action-card card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-graph-up-arrow"></i> Registration Statistics
                    </div>
                    <div class="card-body" style="padding: 15px;">
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="text-center p-2 rounded" style="background: linear-gradient(135deg, #fff3cd 0%, #ffe8a1 100%);">
                                    <i class="bi bi-hourglass-split" style="font-size: 1.5rem; color: #856404;"></i>
                                    <h5 class="mb-0 mt-1" style="color: #856404;"><?php echo $stats['pending_requests']; ?></h5>
                                    <small class="text-muted" style="font-size: 0.7rem;">PENDING</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="text-center p-2 rounded" style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);">
                                    <i class="bi bi-check-circle-fill" style="font-size: 1.5rem; color: #155724;"></i>
                                    <h5 class="mb-0 mt-1" style="color: #155724;"><?php echo $stats['approved_requests']; ?></h5>
                                    <small class="text-muted" style="font-size: 0.7rem;">APPROVED</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="text-center p-2 rounded" style="background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);">
                                    <i class="bi bi-x-circle-fill" style="font-size: 1.5rem; color: #721c24;"></i>
                                    <h5 class="mb-0 mt-1" style="color: #721c24;"><?php echo $stats['rejected_requests']; ?></h5>
                                    <small class="text-muted" style="font-size: 0.7rem;">REJECTED</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Management Actions -->
        <div class="section-header">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <h4>Management & Administration</h4>
        </div>

        <div class="row">
            <!-- User Management Card -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="action-card card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="bi bi-people-fill"></i> User Management
                    </div>
                    <div class="card-body" style="padding: 12px;">
                        <a href="manage_students.php" class="btn btn-outline-primary btn-sm mb-1 w-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-person-fill"></i> Students</span>
                                <span class="badge bg-primary rounded-pill"><?php echo $stats['students']; ?></span>
                            </div>
                        </a>
                        <a href="manage_lecturers.php" class="btn btn-outline-success btn-sm mb-1 w-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-person-badge-fill"></i> Lecturers</span>
                                <span class="badge bg-success rounded-pill"><?php echo $stats['lecturer_users']; ?></span>
                            </div>
                        </a>
                        <a href="manage_administrators.php" class="btn btn-outline-warning btn-sm mb-1 w-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-shield-fill-check"></i> Admins</span>
                                <span class="badge bg-warning text-dark rounded-pill"><?php echo $stats['admin_users']; ?></span>
                            </div>
                        </a>
                        <a href="manage_finance.php" class="btn btn-outline-info btn-sm w-100">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-cash-coin"></i> Finance</span>
                                <span class="badge bg-info rounded-pill"><?php echo $stats['finance_users']; ?></span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Course Management Card -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="action-card card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="bi bi-book-half"></i> Course Management
                    </div>
                    <div class="card-body" style="padding: 12px;">
                        <a href="manage_courses.php" class="btn btn-warning btn-sm mb-1 w-100">
                            <i class="bi bi-book-fill"></i> Manage Courses
                        </a>
                        <a href="semester_course_assignment.php" class="btn btn-dark btn-sm mb-1 w-100">
                            <i class="bi bi-calendar-plus-fill"></i> Semester Assignments
                        </a>
                        <a href="module_allocation.php" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-person-lines-fill"></i> Module Allocation
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Academic Structure Card -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="action-card card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <i class="bi bi-diagram-3-fill"></i> Academic Structure
                    </div>
                    <div class="card-body" style="padding: 12px;">
                        <a href="manage_faculties.php" class="btn btn-dark btn-sm mb-1 w-100">
                            <i class="bi bi-building-fill"></i> Faculties
                        </a>
                        <a href="manage_departments.php" class="btn btn-info btn-sm mb-1 w-100">
                            <i class="bi bi-building"></i> Departments
                        </a>
                        <a href="manage_programs.php" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-mortarboard-fill"></i> Programs
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- System Settings Card -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="action-card card">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <i class="bi bi-gear-fill"></i> System Settings
                    </div>
                    <div class="card-body" style="padding: 12px;">
                        <a href="university_settings.php" class="btn btn-primary btn-sm mb-1 w-100">
                            <i class="bi bi-building-fill"></i> University Settings
                        </a>
                        <a href="fee_settings.php" class="btn btn-success btn-sm mb-1 w-100">
                            <i class="bi bi-cash-stack"></i> Fee Configuration
                        </a>
                        <a href="../change_password.php" class="btn btn-warning btn-sm w-100">
                            <i class="bi bi-key-fill"></i> Change Password
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer Info -->
        <div class="row mt-4 mb-4">
            <div class="col-12">
                <div class="card action-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="card-body text-white text-center py-3">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <i class="bi bi-info-circle-fill"></i>
                                <strong> System Version:</strong> VLE 1.0
                            </div>
                            <div class="col-md-4">
                                <i class="bi bi-calendar-event"></i>
                                <strong> Last Login:</strong> <?php echo date('M d, Y h:i A'); ?>
                            </div>
                            <div class="col-md-4">
                                <i class="bi bi-shield-check"></i>
                                <strong> Role:</strong> Administrator
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Session Timeout Manager -->
    <script src="../assets/js/session-timeout.js"></script>
</body>
</html>