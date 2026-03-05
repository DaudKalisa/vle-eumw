<?php
/**
 * ODL Coordinator Dashboard
 * Main dashboard for Open Distance Learning Coordinator
 * 
 * Features:
 * - Overview of pending lecturer claims
 * - Student enrollment & activity statistics
 * - Exam management overview
 * - Quick action buttons
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// ==================== LECTURER CLAIMS STATISTICS ====================
function getClaimsStats($conn) {
    $stats = [];
    
    // Check if odl_approval_status column exists
    $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
    $has_odl_column = $col_check && $col_check->num_rows > 0;
    
    if ($has_odl_column) {
        // Pending ODL approval
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE odl_approval_status = 'pending' AND status = 'pending'");
        $stats['pending_approval'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        // Approved by ODL
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE odl_approval_status = 'approved'");
        $stats['approved'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        // Rejected by ODL
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE odl_approval_status = 'rejected'");
        $stats['rejected'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        // Total claims amount pending
        $result = $conn->query("SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE odl_approval_status = 'pending' AND status = 'pending'");
        $stats['pending_amount'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;
    } else {
        // Fallback - use existing status column
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE status = 'pending'");
        $stats['pending_approval'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE status = 'approved'");
        $stats['approved'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE status = 'rejected'");
        $stats['rejected'] = $result ? $result->fetch_assoc()['total'] : 0;
        
        $result = $conn->query("SELECT SUM(total_amount) as total FROM lecturer_finance_requests WHERE status = 'pending'");
        $stats['pending_amount'] = $result ? ($result->fetch_assoc()['total'] ?? 0) : 0;
    }
    
    return $stats;
}

// ==================== STUDENT STATISTICS ====================
function getStudentStats($conn) {
    $stats = [];
    
    // Total active students
    $result = $conn->query("SELECT COUNT(*) as total FROM students WHERE is_active = TRUE");
    $stats['total_students'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Students with login activity (last 7 days)
    $result = $conn->query("SELECT COUNT(DISTINCT user_id) as total FROM login_history WHERE login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['active_7_days'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Students with login activity (last 30 days)
    $result = $conn->query("SELECT COUNT(DISTINCT user_id) as total FROM login_history WHERE login_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['active_30_days'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Course enrollments
    $result = $conn->query("SELECT COUNT(*) as total FROM vle_enrollments");
    $stats['total_enrollments'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Students without any login (inactive)
    $result = $conn->query("
        SELECT COUNT(*) as total FROM students s 
        LEFT JOIN users u ON s.student_id = u.related_student_id
        WHERE s.is_active = TRUE AND u.user_id NOT IN (SELECT DISTINCT user_id FROM login_history)
    ");
    $stats['never_logged_in'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    return $stats;
}

// ==================== COURSE STATISTICS ====================
function getCourseStats($conn) {
    $stats = [];
    
    // Total courses
    $result = $conn->query("SELECT COUNT(*) as total FROM vle_courses");
    $stats['total_courses'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Currently active courses (check if is_active column exists)
    $col_check = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'is_active'");
    if ($col_check && $col_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_courses WHERE is_active = 1");
    } else {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_courses");
    }
    $stats['current_semester_courses'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Total assignments (check table exists)
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_assignments'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_assignments");
        $stats['total_assignments'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        // Try assignments table
        $table_check = $conn->query("SHOW TABLES LIKE 'assignments'");
        if ($table_check && $table_check->num_rows > 0) {
            $result = $conn->query("SELECT COUNT(*) as total FROM assignments");
            $stats['total_assignments'] = $result ? $result->fetch_assoc()['total'] : 0;
        } else {
            $stats['total_assignments'] = 0;
        }
    }
    
    // Pending submissions (unmarked)
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_submissions'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_submissions WHERE score IS NULL");
        $stats['pending_submissions'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $table_check = $conn->query("SHOW TABLES LIKE 'assignment_submissions'");
        if ($table_check && $table_check->num_rows > 0) {
            $result = $conn->query("SELECT COUNT(*) as total FROM assignment_submissions WHERE marks IS NULL");
            $stats['pending_submissions'] = $result ? $result->fetch_assoc()['total'] : 0;
        } else {
            $stats['pending_submissions'] = 0;
        }
    }
    
    // Weekly content uploaded
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_weekly_content'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_weekly_content");
        $stats['total_content'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $stats['total_content'] = 0;
    }
    
    return $stats;
}

// ==================== EXAM STATISTICS ====================
function getExamStats($conn) {
    $stats = [
        'upcoming_exams' => 0,
        'ongoing_exams' => 0,
        'results_pending' => 0,
        'total_registrations' => 0
    ];
    
    // Check for exams table (could be 'exams' or 'vle_exams')
    $exam_table = null;
    $table_check = $conn->query("SHOW TABLES LIKE 'exams'");
    if ($table_check && $table_check->num_rows > 0) {
        $exam_table = 'exams';
    } else {
        $table_check = $conn->query("SHOW TABLES LIKE 'vle_exams'");
        if ($table_check && $table_check->num_rows > 0) {
            $exam_table = 'vle_exams';
        }
    }
    
    if ($exam_table) {
        // Check which columns exist
        $columns = [];
        $col_result = $conn->query("SHOW COLUMNS FROM $exam_table");
        if ($col_result) {
            while ($col = $col_result->fetch_assoc()) {
                $columns[] = $col['Field'];
            }
        }
        
        $has_exam_date = in_array('exam_date', $columns);
        $has_status = in_array('status', $columns);
        $has_results_published = in_array('results_published', $columns);
        
        // Upcoming exams
        if ($has_exam_date && $has_status) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE exam_date >= CURDATE() AND status = 'scheduled'");
            $stats['upcoming_exams'] = $result ? $result->fetch_assoc()['total'] : 0;
        } elseif ($has_exam_date) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE exam_date >= CURDATE()");
            $stats['upcoming_exams'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
        
        // Ongoing exams
        if ($has_status) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE status = 'ongoing'");
            $stats['ongoing_exams'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
        
        // Completed exams (results pending)
        if ($has_status && $has_results_published) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE status = 'completed' AND results_published = FALSE");
            $stats['results_pending'] = $result ? $result->fetch_assoc()['total'] : 0;
        } elseif ($has_status) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE status = 'completed'");
            $stats['results_pending'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
    }
    
    // Check for exam registrations table
    $reg_table = null;
    $table_check = $conn->query("SHOW TABLES LIKE 'exam_registrations'");
    if ($table_check && $table_check->num_rows > 0) {
        $reg_table = 'exam_registrations';
    } else {
        $table_check = $conn->query("SHOW TABLES LIKE 'vle_exam_registrations'");
        if ($table_check && $table_check->num_rows > 0) {
            $reg_table = 'vle_exam_registrations';
        }
    }
    
    if ($reg_table) {
        $result = $conn->query("SELECT COUNT(*) as total FROM $reg_table");
        $stats['total_registrations'] = $result ? $result->fetch_assoc()['total'] : 0;
    }
    
    return $stats;
}

// ==================== RECENT CLAIMS ====================
function getRecentClaims($conn, $limit = 5) {
    $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
    $has_odl_column = $col_check && $col_check->num_rows > 0;
    
    $status_col = $has_odl_column ? 'odl_approval_status' : 'status';
    
    $result = $conn->query("
        SELECT r.*, l.full_name, l.department 
        FROM lecturer_finance_requests r 
        JOIN lecturers l ON r.lecturer_id = l.lecturer_id 
        WHERE r.$status_col = 'pending'
        ORDER BY r.request_date DESC 
        LIMIT $limit
    ");
    
    $claims = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $claims[] = $row;
        }
    }
    return $claims;
}

// ==================== RECENT LOGINS ====================
function getRecentLogins($conn, $limit = 10) {
    $result = $conn->query("
        SELECT lh.*, u.username, u.role,
               COALESCE(s.full_name, l.full_name, u.username) as user_full_name
        FROM login_history lh
        JOIN users u ON lh.user_id = u.user_id
        LEFT JOIN students s ON u.related_student_id = s.student_id
        LEFT JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
        WHERE u.role = 'student'
        ORDER BY lh.login_time DESC
        LIMIT $limit
    ");
    
    $logins = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $logins[] = $row;
        }
    }
    return $logins;
}

// Get all statistics
$claims_stats = getClaimsStats($conn);
$student_stats = getStudentStats($conn);
$course_stats = getCourseStats($conn);
$exam_stats = getExamStats($conn);
$recent_claims = getRecentClaims($conn);
$recent_logins = getRecentLogins($conn);

// Page title
$page_title = 'ODL Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ODL Coordinator Dashboard - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .stat-card {
            border-radius: 12px;
            padding: 20px;
            color: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .stat-card .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 13px;
            opacity: 0.9;
        }
        .bg-claims { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .bg-students { background: linear-gradient(135deg, #3498db, #2980b9); }
        .bg-courses { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .bg-exams { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        
        .quick-action {
            padding: 20px;
            border-radius: 12px;
            background: white;
            border: 1px solid #eee;
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
            display: block;
            text-align: center;
        }
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-color: #3498db;
            color: #3498db;
        }
        .quick-action i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        
        .activity-item {
            padding: 12px 16px;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        .activity-item:hover {
            background: #f8f9fa;
            border-left-color: #3498db;
        }
        .activity-item .activity-time {
            font-size: 11px;
            color: #999;
        }
        
        .claim-card {
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            border-radius: 8px;
        }
        .claim-card:hover {
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .progress-ring {
            width: 80px;
            height: 80px;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Welcome Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h1 class="h3 mb-1">Welcome, <?= htmlspecialchars($coordinator_name ?? 'ODL Coordinator') ?></h1>
                        <p class="text-muted mb-0">Open Distance Learning Coordinator Dashboard</p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="badge bg-light text-dark"><i class="bi bi-calendar me-1"></i><?= date('l, F j, Y') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Statistics -->
        <div class="row g-4 mb-4">
            <!-- Claims Stats -->
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-claims">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value"><?= number_format($claims_stats['pending_approval']) ?></div>
                            <div class="stat-label">Pending Claims</div>
                            <div class="mt-2 small">K<?= number_format($claims_stats['pending_amount']) ?> total</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-clipboard-check"></i></div>
                    </div>
                </div>
            </div>
            
            <!-- Student Stats -->
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-students">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value"><?= number_format($student_stats['total_students']) ?></div>
                            <div class="stat-label">Total Students</div>
                            <div class="mt-2 small"><?= number_format($student_stats['active_7_days']) ?> active this week</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-people"></i></div>
                    </div>
                </div>
            </div>
            
            <!-- Course Stats -->
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-courses">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value"><?= number_format($course_stats['total_courses']) ?></div>
                            <div class="stat-label">Active Courses</div>
                            <div class="mt-2 small"><?= number_format($course_stats['total_content']) ?> contents uploaded</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-book"></i></div>
                    </div>
                </div>
            </div>
            
            <!-- Exam Stats -->
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-exams">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value"><?= number_format($exam_stats['upcoming_exams']) ?></div>
                            <div class="stat-label">Upcoming Exams</div>
                            <div class="mt-2 small"><?= number_format($exam_stats['total_registrations']) ?> registrations</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-journal-text"></i></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="claims_approval.php" class="quick-action">
                    <i class="bi bi-clipboard-check text-warning"></i>
                    <span>Review Claims</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="student_verification.php" class="quick-action">
                    <i class="bi bi-person-check text-primary"></i>
                    <span>Verify Students</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="exam_management.php" class="quick-action">
                    <i class="bi bi-journal-text text-purple"></i>
                    <span>Manage Exams</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="reports.php" class="quick-action">
                    <i class="bi bi-graph-up text-success"></i>
                    <span>Generate Reports</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="course_monitoring.php" class="quick-action">
                    <i class="bi bi-eye text-info"></i>
                    <span>Monitor Courses</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="activity_logs.php" class="quick-action">
                    <i class="bi bi-clock-history text-secondary"></i>
                    <span>Activity Logs</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="../admin/student_invite_links.php" class="quick-action">
                    <i class="bi bi-link-45deg text-indigo" style="color:#7c3aed!important;"></i>
                    <span>Invite Links</span>
                </a>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-4 col-6 mb-3">
                <a href="../admin/approve_student_accounts.php" class="quick-action">
                    <i class="bi bi-person-check" style="color:#0ea5e9!important;"></i>
                    <span>Approve Students</span>
                </a>
            </div>
        </div>
        
        <!-- Main Content Row -->
        <div class="row g-4">
            <!-- Pending Claims -->
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-clipboard-check me-2 text-warning"></i>Pending Claims for Approval</h6>
                        <a href="claims_approval.php" class="btn btn-sm btn-outline-warning">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recent_claims)): ?>
                            <?php foreach ($recent_claims as $claim): ?>
                            <div class="claim-card m-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($claim['full_name']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></div>
                                    </div>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <small class="text-muted">
                                        <?= date('M Y', mktime(0,0,0,$claim['month'],1,$claim['year'])) ?> &bull; <?= $claim['total_hours'] ?? 0 ?> hours
                                    </small>
                                    <strong class="text-success">K<?= number_format($claim['total_amount']) ?></strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-check-circle display-4"></i>
                                <p class="mt-2">No pending claims to review</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Student Activity -->
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-person-check me-2 text-primary"></i>Recent Student Logins</h6>
                        <a href="student_verification.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recent_logins)): ?>
                            <?php foreach ($recent_logins as $login): ?>
                            <div class="activity-item border-bottom">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?= htmlspecialchars($login['user_full_name']) ?></strong>
                                        <div class="activity-time">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($login['ip_address'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success">Logged In</span>
                                        <div class="activity-time"><?= date('M j, g:i a', strtotime($login['login_time'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-person-x display-4"></i>
                                <p class="mt-2">No recent student logins</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Additional Stats Row -->
        <div class="row g-4 mt-2">
            <!-- Student Activity Overview -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-activity me-2 text-info"></i>Student Activity Overview</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <span>Active (Last 7 days)</span>
                            <strong class="text-success"><?= number_format($student_stats['active_7_days']) ?></strong>
                        </div>
                        <div class="progress mb-3" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?= $student_stats['total_students'] > 0 ? round(($student_stats['active_7_days'] / $student_stats['total_students']) * 100) : 0 ?>%"></div>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Active (Last 30 days)</span>
                            <strong class="text-primary"><?= number_format($student_stats['active_30_days']) ?></strong>
                        </div>
                        <div class="progress mb-3" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?= $student_stats['total_students'] > 0 ? round(($student_stats['active_30_days'] / $student_stats['total_students']) * 100) : 0 ?>%"></div>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Never Logged In</span>
                            <strong class="text-danger"><?= number_format($student_stats['never_logged_in']) ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-danger" style="width: <?= $student_stats['total_students'] > 0 ? round(($student_stats['never_logged_in'] / $student_stats['total_students']) * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Course Statistics -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-book me-2 text-success"></i>Course Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="display-6 text-primary"><?= number_format($course_stats['current_semester_courses']) ?></div>
                                <small class="text-muted">Current Semester</small>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="display-6 text-success"><?= number_format($student_stats['total_enrollments']) ?></div>
                                <small class="text-muted">Enrollments</small>
                            </div>
                            <div class="col-6">
                                <div class="display-6 text-warning"><?= number_format($course_stats['total_assignments']) ?></div>
                                <small class="text-muted">Assignments</small>
                            </div>
                            <div class="col-6">
                                <div class="display-6 text-danger"><?= number_format($course_stats['pending_submissions']) ?></div>
                                <small class="text-muted">Pending Marking</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Claims Summary -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i>Claims Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4 mb-3">
                                <div class="display-6 text-warning"><?= number_format($claims_stats['pending_approval']) ?></div>
                                <small class="text-muted">Pending</small>
                            </div>
                            <div class="col-4 mb-3">
                                <div class="display-6 text-success"><?= number_format($claims_stats['approved']) ?></div>
                                <small class="text-muted">Approved</small>
                            </div>
                            <div class="col-4 mb-3">
                                <div class="display-6 text-danger"><?= number_format($claims_stats['rejected']) ?></div>
                                <small class="text-muted">Rejected</small>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <div class="h4 text-success mb-0">K<?= number_format($claims_stats['pending_amount']) ?></div>
                            <small class="text-muted">Pending Approval Amount</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $current_role_context = 'odl_coordinator';
        include '../includes/role_cards.php';
        ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
