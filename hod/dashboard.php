<?php
/**
 * HOD Dashboard
 * Head of Department main dashboard with department overview and quick actions
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['hod', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get HOD's department info
$hod_department = '';
$hod_department_id = null;
$hod_name = $user['display_name'] ?? 'Head of Department';

if (!empty($user['related_staff_id'])) {
    $stmt = $conn->prepare("SELECT full_name, department FROM administrative_staff WHERE staff_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['related_staff_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $hod_name = $row['full_name'];
            $hod_department = $row['department'] ?? '';
        }
    }
}

// Get department_id from department name
if ($hod_department) {
    $dept_stmt = $conn->prepare("SELECT department_id, department_name, department_code FROM departments WHERE department_name = ? OR department_code = ?");
    if ($dept_stmt) {
        $dept_stmt->bind_param("ss", $hod_department, $hod_department);
        $dept_stmt->execute();
        $dept_row = $dept_stmt->get_result()->fetch_assoc();
        if ($dept_row) {
            $hod_department_id = $dept_row['department_id'];
            $hod_department = $dept_row['department_name'];
        }
    }
}

// ==================== STATS ====================

// Courses in department
$courses_count = 0;
$active_courses = 0;
if ($hod_department) {
    $r = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count FROM vle_courses WHERE program_of_study LIKE '%" . $conn->real_escape_string($hod_department) . "%'");
    if ($r) { $d = $r->fetch_assoc(); $courses_count = $d['total'] ?? 0; $active_courses = $d['active_count'] ?? 0; }
}

// Lecturers in department
$lecturers_count = 0;
if ($hod_department) {
    $r = $conn->query("SELECT COUNT(*) as total FROM lecturers WHERE department LIKE '%" . $conn->real_escape_string($hod_department) . "%'");
    if ($r) $lecturers_count = $r->fetch_assoc()['total'] ?? 0;
}

// Students in department programs
$students_count = 0;
if ($hod_department_id) {
    $r = $conn->query("SELECT COUNT(DISTINCT s.student_id) as total FROM students s 
        INNER JOIN programs p ON (s.program = p.program_code OR s.program = p.program_name)
        WHERE p.department_id = $hod_department_id");
    if ($r) $students_count = $r->fetch_assoc()['total'] ?? 0;
}

// Programs in department
$programs_count = 0;
if ($hod_department_id) {
    $r = $conn->query("SELECT COUNT(*) as total FROM programs WHERE department_id = $hod_department_id");
    if ($r) $programs_count = $r->fetch_assoc()['total'] ?? 0;
}

// Courses without lecturers
$unassigned_courses = 0;
if ($hod_department) {
    $r = $conn->query("SELECT COUNT(*) as total FROM vle_courses WHERE (lecturer_id IS NULL OR lecturer_id = 0) AND program_of_study LIKE '%" . $conn->real_escape_string($hod_department) . "%' AND is_active = 1");
    if ($r) $unassigned_courses = $r->fetch_assoc()['total'] ?? 0;
}

// Recent enrollments
$recent_enrollments = [];
if ($hod_department) {
    $r = $conn->query("SELECT s.full_name, c.course_code, c.course_name, e.enrollment_date 
        FROM vle_enrollments e 
        JOIN students s ON e.student_id = s.student_id 
        JOIN vle_courses c ON e.course_id = c.course_id 
        WHERE c.program_of_study LIKE '%" . $conn->real_escape_string($hod_department) . "%'
        ORDER BY e.enrollment_date DESC LIMIT 10");
    if ($r) while ($row = $r->fetch_assoc()) $recent_enrollments[] = $row;
}

// Enrollment by year
$enrollment_by_year = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
if ($hod_department_id) {
    $r = $conn->query("SELECT s.year_of_study, COUNT(*) as cnt FROM students s 
        INNER JOIN programs p ON (s.program = p.program_code OR s.program = p.program_name)
        WHERE p.department_id = $hod_department_id
        GROUP BY s.year_of_study");
    if ($r) while ($row = $r->fetch_assoc()) $enrollment_by_year[(int)$row['year_of_study']] = $row['cnt'];
}

$page_title = "HOD Dashboard";
$breadcrumbs = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOD Dashboard - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 16px;
            padding: 1.5rem;
            color: #fff;
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); color: #fff; }
        .stat-card .stat-value { font-size: 2.5rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem; }
        .quick-action { 
            border: 2px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; text-decoration: none; 
            color: #374151; transition: all 0.2s; display: flex; align-items: center; gap: 1rem;
        }
        .quick-action:hover { border-color: #7c3aed; background: #f5f3ff; color: #374151; transform: translateY(-2px); }
        .quick-action i { font-size: 1.5rem; color: #7c3aed; }
        .welcome-banner {
            background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 50%, #2563eb 100%);
            border-radius: 16px; padding: 2rem; color: white; margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <!-- Welcome -->
        <div class="welcome-banner">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="fw-bold mb-1">Welcome, <?= htmlspecialchars($hod_name) ?></h2>
                    <p class="mb-0 opacity-75">
                        <i class="bi bi-building me-1"></i>Head of Department
                        <?php if ($hod_department): ?>
                         — <?= htmlspecialchars($hod_department) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <span class="badge bg-white bg-opacity-25 fs-6 px-3 py-2">
                        <i class="bi bi-calendar3 me-1"></i><?= date('l, M d, Y') ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!$hod_department): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Department not configured.</strong> Your staff record does not have a department assigned. Please contact the system administrator.
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <a href="courses.php" class="stat-card d-block" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <div class="stat-value"><?= $courses_count ?></div>
                    <div class="stat-label"><i class="bi bi-book me-1"></i>Courses</div>
                    <small class="d-block mt-1 opacity-75"><?= $active_courses ?> active</small>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="lecturers.php" class="stat-card d-block" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-value"><?= $lecturers_count ?></div>
                    <div class="stat-label"><i class="bi bi-person-badge me-1"></i>Lecturers</div>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="students.php" class="stat-card d-block" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <div class="stat-value"><?= $students_count ?></div>
                    <div class="stat-label"><i class="bi bi-people me-1"></i>Students</div>
                </a>
            </div>
            <div class="col-md-3 col-6">
                <a href="reports.php" class="stat-card d-block" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="stat-value"><?= $programs_count ?></div>
                    <div class="stat-label"><i class="bi bi-mortarboard me-1"></i>Programs</div>
                </a>
            </div>
        </div>

        <!-- Quick Actions & Alerts Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="bi bi-lightning me-2 text-warning"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <a href="courses.php" class="quick-action">
                                    <i class="bi bi-book"></i>
                                    <div>
                                        <strong>View Courses</strong>
                                        <div class="small text-muted">Browse department courses</div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="course_allocations.php" class="quick-action">
                                    <i class="bi bi-diagram-3"></i>
                                    <div>
                                        <strong>Course Allocations</strong>
                                        <div class="small text-muted">Assign lecturers to courses</div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="lecturers.php" class="quick-action">
                                    <i class="bi bi-person-badge"></i>
                                    <div>
                                        <strong>View Lecturers</strong>
                                        <div class="small text-muted">Department teaching staff</div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="students.php" class="quick-action">
                                    <i class="bi bi-people"></i>
                                    <div>
                                        <strong>View Students</strong>
                                        <div class="small text-muted">Students in department programs</div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="reports.php" class="quick-action">
                                    <i class="bi bi-bar-chart"></i>
                                    <div>
                                        <strong>Department Reports</strong>
                                        <div class="small text-muted">Analytics & performance data</div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="../change_password.php" class="quick-action">
                                    <i class="bi bi-key"></i>
                                    <div>
                                        <strong>Change Password</strong>
                                        <div class="small text-muted">Update your credentials</div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="bi bi-bell me-2 text-danger"></i>Alerts</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($unassigned_courses > 0): ?>
                        <div class="alert alert-warning py-2 mb-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong><?= $unassigned_courses ?></strong> course(s) have no lecturer assigned.
                            <a href="course_allocations.php" class="d-block small mt-1">Manage allocations &rarr;</a>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <h6 class="text-muted small text-uppercase">Enrollment by Year</h6>
                            <?php foreach ($enrollment_by_year as $year => $count): ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small>Year <?= $year ?></small>
                                <div class="d-flex align-items-center gap-2" style="width:60%;">
                                    <div class="progress flex-grow-1" style="height:6px;">
                                        <div class="progress-bar" style="width:<?= $students_count > 0 ? round($count/$students_count*100) : 0 ?>%; background: linear-gradient(135deg, #7c3aed, #4f46e5);"></div>
                                    </div>
                                    <small class="text-muted"><?= $count ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$hod_department): ?>
                        <div class="alert alert-info py-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>Configure your department to see full stats.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Enrollments -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-info"></i>Recent Enrollments</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_enrollments)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox display-6 d-block mb-2"></i>
                    <p>No recent enrollments.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Enrolled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_enrollments as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['full_name']) ?></td>
                                <td>
                                    <code><?= htmlspecialchars($e['course_code']) ?></code>
                                    <small class="d-block text-muted"><?= htmlspecialchars($e['course_name']) ?></small>
                                </td>
                                <td><small><?= date('M d, Y H:i', strtotime($e['enrollment_date'])) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php 
        $current_role_context = 'hod';
        include '../includes/role_cards.php'; 
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
