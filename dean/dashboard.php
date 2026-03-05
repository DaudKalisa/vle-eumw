<?php
/**
 * Dean Portal - Dashboard
 * Overview of faculty academic performance, pending approvals, and reports
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get dean's faculty - for now, show all faculties if not specifically assigned
$dean_faculty_id = $user['related_dean_id'] ?? null;

// ==================== FACULTY STATISTICS ====================
function getFacultyStats($conn, $faculty_id = null) {
    $stats = [];
    
    // Total departments
    if ($faculty_id) {
        $result = $conn->query("SELECT COUNT(*) as total FROM departments WHERE faculty_id = $faculty_id");
    } else {
        $result = $conn->query("SELECT COUNT(*) as total FROM departments");
    }
    $stats['total_departments'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Total programs
    $table_check = $conn->query("SHOW TABLES LIKE 'programs'");
    if ($table_check && $table_check->num_rows > 0) {
        // Check if programs has department_id column
        $has_dept_col = $conn->query("SHOW COLUMNS FROM programs LIKE 'department_id'");
        if ($has_dept_col && $has_dept_col->num_rows > 0 && $faculty_id) {
            $result = $conn->query("SELECT COUNT(*) as total FROM programs p JOIN departments d ON p.department_id = d.department_id WHERE d.faculty_id = $faculty_id");
        } else {
            $result = $conn->query("SELECT COUNT(*) as total FROM programs");
        }
        $stats['total_programs'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $stats['total_programs'] = 0;
    }
    
    // Total lecturers
    $table_check = $conn->query("SHOW TABLES LIKE 'lecturers'");
    if ($table_check && $table_check->num_rows > 0) {
        $col_check = $conn->query("SHOW COLUMNS FROM lecturers LIKE 'faculty_id'");
        if ($col_check && $col_check->num_rows > 0 && $faculty_id) {
            $result = $conn->query("SELECT COUNT(*) as total FROM lecturers WHERE faculty_id = $faculty_id");
        } else {
            $result = $conn->query("SELECT COUNT(*) as total FROM lecturers");
        }
        $stats['total_lecturers'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $stats['total_lecturers'] = 0;
    }
    
    // Total students  
    $table_check = $conn->query("SHOW TABLES LIKE 'students'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM students");
        $stats['total_students'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $stats['total_students'] = 0;
    }
    
    // Total courses
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_courses'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM vle_courses");
        $stats['total_courses'] = $result ? $result->fetch_assoc()['total'] : 0;
    } else {
        $table_check = $conn->query("SHOW TABLES LIKE 'courses'");
        if ($table_check && $table_check->num_rows > 0) {
            $result = $conn->query("SELECT COUNT(*) as total FROM courses");
            $stats['total_courses'] = $result ? $result->fetch_assoc()['total'] : 0;
        } else {
            $stats['total_courses'] = 0;
        }
    }
    
    return $stats;
}

// ==================== CLAIMS STATISTICS ====================
function getClaimsStats($conn) {
    $stats = [
        'pending_claims' => 0,
        'forwarded_claims' => 0,
        'approved_claims' => 0,
        'total_amount' => 0
    ];
    
    $table_check = $conn->query("SHOW TABLES LIKE 'lecturer_finance_requests'");
    if ($table_check && $table_check->num_rows > 0) {
        // Check if dean columns exist
        $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'dean_approval_status'");
        $has_dean_column = $col_check && $col_check->num_rows > 0;
        
        // Check ODL column for forwarded claims
        $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
        $has_odl_column = $col_check && $col_check->num_rows > 0;
        
        if ($has_dean_column) {
            // Claims pending dean approval
            $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE dean_approval_status = 'pending'");
            $stats['pending_claims'] = $result ? $result->fetch_assoc()['total'] : 0;
            
            // Claims approved by dean
            $result = $conn->query("SELECT COUNT(*) as total, SUM(total_amount) as amount FROM lecturer_finance_requests WHERE dean_approval_status = 'approved'");
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['approved_claims'] = $row['total'] ?? 0;
                $stats['total_amount'] = $row['amount'] ?? 0;
            }
        } elseif ($has_odl_column) {
            // Claims forwarded to dean (from ODL coordinator)
            $result = $conn->query("SELECT COUNT(*) as total FROM lecturer_finance_requests WHERE odl_approval_status = 'forwarded_to_dean'");
            $stats['forwarded_claims'] = $result ? $result->fetch_assoc()['total'] : 0;
            $stats['pending_claims'] = $stats['forwarded_claims'];
        }
    }
    
    return $stats;
}

// ==================== EXAM STATISTICS ====================
function getExamStats($conn) {
    $stats = [
        'upcoming_exams' => 0,
        'pending_results' => 0,
        'published_results' => 0
    ];
    
    // Check for exams table
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
        
        if ($has_exam_date) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE exam_date >= CURDATE()");
            $stats['upcoming_exams'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
        
        if ($has_status) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE status = 'completed'");
            $stats['pending_results'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
        
        if ($has_results_published) {
            $result = $conn->query("SELECT COUNT(*) as total FROM $exam_table WHERE results_published = 1");
            $stats['published_results'] = $result ? $result->fetch_assoc()['total'] : 0;
        }
    }
    
    return $stats;
}

// ==================== RECENT CLAIMS ====================
function getRecentClaims($conn, $limit = 5) {
    $claims = [];
    
    $table_check = $conn->query("SHOW TABLES LIKE 'lecturer_finance_requests'");
    if ($table_check && $table_check->num_rows > 0) {
        $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
        $has_odl = $col_check && $col_check->num_rows > 0;
        
        if ($has_odl) {
            $result = $conn->query("
                SELECT r.*, l.full_name, l.department 
                FROM lecturer_finance_requests r 
                JOIN lecturers l ON r.lecturer_id = l.lecturer_id 
                WHERE r.odl_approval_status = 'forwarded_to_dean' OR r.odl_approval_status = 'approved'
                ORDER BY r.request_date DESC 
                LIMIT $limit
            ");
        } else {
            $result = $conn->query("
                SELECT r.*, l.full_name, l.department 
                FROM lecturer_finance_requests r 
                JOIN lecturers l ON r.lecturer_id = l.lecturer_id 
                WHERE r.status = 'pending'
                ORDER BY r.request_date DESC 
                LIMIT $limit
            ");
        }
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $claims[] = $row;
            }
        }
    }
    
    return $claims;
}

// ==================== RECENT ACTIVITY ====================
function getRecentActivity($conn, $limit = 10) {
    $activities = [];
    
    // Try odl_claims_approval table
    $table_check = $conn->query("SHOW TABLES LIKE 'odl_claims_approval'");
    if ($table_check && $table_check->num_rows > 0) {
        $result = $conn->query("
            SELECT 'claim_approval' as type, status, approved_at as activity_date, remarks
            FROM odl_claims_approval
            ORDER BY approved_at DESC
            LIMIT $limit
        ");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $activities[] = $row;
            }
        }
    }
    
    return $activities;
}

// Get all statistics
$faculty_stats = getFacultyStats($conn, $dean_faculty_id);
$claims_stats = getClaimsStats($conn);
$exam_stats = getExamStats($conn);
$recent_claims = getRecentClaims($conn);
$recent_activity = getRecentActivity($conn);

// Get faculty name
$faculty_name = 'All Faculties';
if ($dean_faculty_id) {
    $result = $conn->query("SELECT faculty_name FROM faculties WHERE faculty_id = $dean_faculty_id");
    if ($result && $row = $result->fetch_assoc()) {
        $faculty_name = $row['faculty_name'];
    }
}

$page_title = "Dean Dashboard";
$breadcrumbs = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            border-radius: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .dean-card {
            background: linear-gradient(135deg, #1a472a 0%, #2d5a3e 100%);
            color: white;
        }
        .dean-card .stat-icon {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        .quick-action {
            padding: 1rem;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
            display: block;
        }
        .quick-action:hover {
            background: #f8f9fa;
            color: inherit;
        }
        .activity-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card dean-card">
                    <div class="card-body py-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h2 class="mb-1">Welcome, <?= htmlspecialchars($user['display_name'] ?? 'Dean') ?></h2>
                                <p class="mb-0 opacity-75">
                                    <i class="bi bi-building me-1"></i>
                                    Dean of <?= htmlspecialchars($faculty_name) ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <span class="badge bg-light text-dark fs-6 px-3 py-2">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= date('l, F j, Y') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <a href="claims_approval.php" class="quick-action text-center border rounded">
                                    <i class="bi bi-clipboard-check text-primary fs-3 d-block mb-2"></i>
                                    <span>Review Claims</span>
                                    <?php if ($claims_stats['pending_claims'] > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $claims_stats['pending_claims'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="exams.php" class="quick-action text-center border rounded">
                                    <i class="bi bi-journal-check text-success fs-3 d-block mb-2"></i>
                                    <span>Exam Results</span>
                                    <?php if ($exam_stats['pending_results'] > 0): ?>
                                    <span class="badge bg-warning ms-1"><?= $exam_stats['pending_results'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="reports.php" class="quick-action text-center border rounded">
                                    <i class="bi bi-graph-up text-info fs-3 d-block mb-2"></i>
                                    <span>View Reports</span>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="announcements.php" class="quick-action text-center border rounded">
                                    <i class="bi bi-megaphone text-warning fs-3 d-block mb-2"></i>
                                    <span>Announcements</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <!-- Departments -->
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted mb-1">Departments</p>
                                <p class="stat-value mb-0"><?= number_format($faculty_stats['total_departments']) ?></p>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-building"></i>
                            </div>
                        </div>
                        <a href="departments.php" class="small text-primary">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Programs -->
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted mb-1">Programs</p>
                                <p class="stat-value mb-0"><?= number_format($faculty_stats['total_programs']) ?></p>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                        </div>
                        <a href="programs.php" class="small text-success">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Lecturers -->
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted mb-1">Lecturers</p>
                                <p class="stat-value mb-0"><?= number_format($faculty_stats['total_lecturers']) ?></p>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-person-badge"></i>
                            </div>
                        </div>
                        <a href="lecturers.php" class="small text-info">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Students -->
            <div class="col-md-6 col-lg-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted mb-1">Students</p>
                                <p class="stat-value mb-0"><?= number_format($faculty_stats['total_students']) ?></p>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <a href="students.php" class="small text-warning">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Claims and Exams Row -->
        <div class="row g-4 mb-4">
            <!-- Claims Stats -->
            <div class="col-md-6 col-lg-4">
                <div class="card stat-card h-100 border-primary">
                    <div class="card-body">
                        <h6 class="text-muted mb-3"><i class="bi bi-clipboard-data me-2"></i>Claims Overview</h6>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-2 bg-warning bg-opacity-10 rounded text-center">
                                    <div class="fs-4 fw-bold text-warning"><?= $claims_stats['pending_claims'] ?></div>
                                    <small class="text-muted">Pending</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-success bg-opacity-10 rounded text-center">
                                    <div class="fs-4 fw-bold text-success"><?= $claims_stats['approved_claims'] ?></div>
                                    <small class="text-muted">Approved</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="p-2 bg-info bg-opacity-10 rounded text-center">
                                    <div class="fs-5 fw-bold text-info">UGX <?= number_format($claims_stats['total_amount']) ?></div>
                                    <small class="text-muted">Total Approved Amount</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Exam Stats -->
            <div class="col-md-6 col-lg-4">
                <div class="card stat-card h-100 border-success">
                    <div class="card-body">
                        <h6 class="text-muted mb-3"><i class="bi bi-journal-text me-2"></i>Examinations</h6>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="p-2 bg-primary bg-opacity-10 rounded text-center">
                                    <div class="fs-4 fw-bold text-primary"><?= $exam_stats['upcoming_exams'] ?></div>
                                    <small class="text-muted">Upcoming</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-warning bg-opacity-10 rounded text-center">
                                    <div class="fs-4 fw-bold text-warning"><?= $exam_stats['pending_results'] ?></div>
                                    <small class="text-muted">Pending Results</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="p-2 bg-success bg-opacity-10 rounded text-center">
                                    <div class="fs-5 fw-bold text-success"><?= $exam_stats['published_results'] ?></div>
                                    <small class="text-muted">Results Published</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Courses Stats -->
            <div class="col-md-6 col-lg-4">
                <div class="card stat-card h-100 border-info">
                    <div class="card-body">
                        <h6 class="text-muted mb-3"><i class="bi bi-book me-2"></i>Courses</h6>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="fs-2 fw-bold text-info"><?= $faculty_stats['total_courses'] ?></div>
                                <small class="text-muted">Total Courses</small>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-book"></i>
                            </div>
                        </div>
                        <a href="courses.php" class="btn btn-outline-info btn-sm w-100">
                            <i class="bi bi-eye me-1"></i> View All Courses
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Claims and Activity -->
        <div class="row g-4">
            <!-- Recent Claims -->
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Claims for Review</h5>
                        <a href="claims_approval.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_claims)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                            <p>No pending claims to review</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lecturer</th>
                                        <th>Department</th>
                                        <th>Period</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_claims as $claim): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($claim['full_name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></td>
                                        <td><?= date('M Y', mktime(0, 0, 0, $claim['month'], 1, $claim['year'])) ?></td>
                                        <td><strong>UGX <?= number_format($claim['total_amount']) ?></strong></td>
                                        <td>
                                            <?php
                                            $status = $claim['odl_approval_status'] ?? $claim['status'];
                                            $badge_class = [
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                'forwarded_to_dean' => 'info'
                                            ][$status] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge_class ?>"><?= ucfirst(str_replace('_', ' ', $status)) ?></span>
                                        </td>
                                        <td>
                                            <a href="claims_approval.php?id=<?= $claim['request_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Review
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Faculty Summary</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><i class="bi bi-building me-2 text-primary"></i>Departments</span>
                                <strong><?= $faculty_stats['total_departments'] ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><i class="bi bi-mortarboard me-2 text-success"></i>Programs</span>
                                <strong><?= $faculty_stats['total_programs'] ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><i class="bi bi-person-badge me-2 text-info"></i>Lecturers</span>
                                <strong><?= $faculty_stats['total_lecturers'] ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><i class="bi bi-people me-2 text-warning"></i>Students</span>
                                <strong><?= $faculty_stats['total_students'] ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2">
                                <span><i class="bi bi-book me-2 text-danger"></i>Courses</span>
                                <strong><?= $faculty_stats['total_courses'] ?></strong>
                            </li>
                        </ul>
                    </div>
                    <div class="card-footer bg-white">
                        <a href="reports.php" class="btn btn-primary w-100">
                            <i class="bi bi-graph-up me-1"></i> Generate Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $current_role_context = 'dean';
        include '../includes/role_cards.php';
        ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
