<?php
/**
 * HOD - Department Reports
 * Academic reports: enrollment, course performance, lecturer workload
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['hod', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get HOD department
$hod_department = '';
$department_id = 0;
if (!empty($user['related_staff_id'])) {
    $stmt = $conn->prepare("SELECT department FROM administrative_staff WHERE staff_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['related_staff_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $hod_department = $row['department'] ?? '';
            $stmt2 = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? OR department_code = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param("ss", $hod_department, $hod_department);
                $stmt2->execute();
                $drow = $stmt2->get_result()->fetch_assoc();
                if ($drow) $department_id = $drow['department_id'];
            }
        }
    }
}

$dept_like = '%' . $hod_department . '%';

// === Report Data ===

// 1. Enrollment by Year
$enrollment_by_year = [];
if ($department_id) {
    $sql = "SELECT s.year_of_study, COUNT(*) as count 
            FROM students s 
            JOIN programs p ON (s.program = p.program_code OR s.program = p.program_name)
            WHERE p.department_id = ?
            GROUP BY s.year_of_study ORDER BY s.year_of_study";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $enrollment_by_year = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 2. Enrollment by Program
$enrollment_by_program = [];
if ($department_id) {
    $sql = "SELECT p.program_name, p.program_code, COUNT(s.student_id) as count 
            FROM programs p 
            LEFT JOIN students s ON (s.program = p.program_code OR s.program = p.program_name)
            WHERE p.department_id = ?
            GROUP BY p.program_id ORDER BY count DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $enrollment_by_program = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 3. Course Stats
$course_stats = [];
if ($hod_department) {
    $sql = "SELECT c.course_code, c.course_name, l.full_name as lecturer_name,
            c.year_of_study, c.semester,
            (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled,
            (SELECT COUNT(*) FROM vle_weekly_content wc WHERE wc.course_id = c.course_id) as content_count,
            (SELECT COUNT(*) FROM vle_assignments a WHERE a.course_id = c.course_id) as assignment_count
            FROM vle_courses c
            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
            WHERE c.program_of_study LIKE ?
            ORDER BY enrolled DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept_like);
    $stmt->execute();
    $course_stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 4. Lecturer Workload
$lecturer_workload = [];
if ($hod_department) {
    $sql = "SELECT l.full_name, l.lecturer_id, l.email,
            COUNT(c.course_id) as courses,
            COALESCE(SUM((SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id)), 0) as total_students
            FROM lecturers l
            LEFT JOIN vle_courses c ON c.lecturer_id = l.lecturer_id AND c.program_of_study LIKE ?
            WHERE l.department LIKE ? AND l.is_active = 1
            GROUP BY l.lecturer_id
            ORDER BY courses DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $dept_like, $dept_like);
    $stmt->execute();
    $lecturer_workload = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 5. Totals
$total_students = 0;
foreach ($enrollment_by_year as $e) $total_students += $e['count'];
$total_courses = count($course_stats);
$assigned_courses = count(array_filter($course_stats, fn($c) => !empty($c['lecturer_name'])));
$total_lecturers = count($lecturer_workload);

$page_title = "Department Reports";
$breadcrumbs = [['title' => 'Reports']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - HOD Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-graph-up me-2 text-danger"></i>Department Reports</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($hod_department ?: 'All Departments') ?> — Academic Overview</p>
            </div>
            <div class="no-print">
                <button class="btn btn-outline-danger btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print Report
                </button>
            </div>
        </div>

        <!-- Overview Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3">
                <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid #0d6efd !important;">
                    <span class="display-6 fw-bold text-primary"><?= $total_students ?></span>
                    <small class="text-muted">Total Students</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid #198754 !important;">
                    <span class="display-6 fw-bold text-success"><?= $total_courses ?></span>
                    <small class="text-muted">Total Courses</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid #0dcaf0 !important;">
                    <span class="display-6 fw-bold text-info"><?= $total_lecturers ?></span>
                    <small class="text-muted">Active Lecturers</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid <?= ($total_courses > 0 && $assigned_courses < $total_courses) ? '#dc3545' : '#198754' ?> !important;">
                    <span class="display-6 fw-bold <?= ($total_courses > 0 && $assigned_courses < $total_courses) ? 'text-danger' : 'text-success' ?>">
                        <?= $total_courses > 0 ? round(($assigned_courses / $total_courses) * 100) : 0 ?>%
                    </span>
                    <small class="text-muted">Courses Assigned</small>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Enrollment by Year -->
            <div class="col-md-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Enrollment by Year</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($enrollment_by_year)): ?>
                        <p class="text-muted text-center">No data available.</p>
                        <?php else: ?>
                        <?php 
                        $max_enroll = max(array_column($enrollment_by_year, 'count'));
                        foreach ($enrollment_by_year as $e):
                            $pct = $max_enroll > 0 ? round(($e['count'] / $max_enroll) * 100) : 0;
                            $colors = [1 => 'primary', 2 => 'success', 3 => 'warning', 4 => 'info'];
                            $color = $colors[$e['year_of_study']] ?? 'secondary';
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <strong>Year <?= $e['year_of_study'] ?></strong>
                                <span class="badge bg-<?= $color ?>"><?= $e['count'] ?> students</span>
                            </div>
                            <div class="progress" style="height:12px;">
                                <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enrollment by Program -->
            <div class="col-md-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-pie-chart me-2 text-success"></i>Enrollment by Program</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($enrollment_by_program)): ?>
                        <p class="text-muted text-center">No data available.</p>
                        <?php else: ?>
                        <?php 
                        $max_prog = max(array_column($enrollment_by_program, 'count') ?: [1]);
                        $prog_colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
                        $ci = 0;
                        foreach ($enrollment_by_program as $ep):
                            $pct = $max_prog > 0 ? round(($ep['count'] / $max_prog) * 100) : 0;
                            $color = $prog_colors[$ci % count($prog_colors)]; $ci++;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span><strong><?= htmlspecialchars($ep['program_code']) ?></strong> <small class="text-muted"><?= htmlspecialchars($ep['program_name']) ?></small></span>
                                <span class="badge bg-<?= $color ?>"><?= $ep['count'] ?></span>
                            </div>
                            <div class="progress" style="height:10px;">
                                <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Performance -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-table me-2 text-info"></i>Course Overview</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($course_stats)): ?>
                <div class="text-center py-4 text-muted">No course data available.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th>Year/Sem</th>
                                <th>Lecturer</th>
                                <th>Students</th>
                                <th>Content</th>
                                <th>Assignments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_stats as $cs): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($cs['course_code']) ?></code></td>
                                <td><?= htmlspecialchars($cs['course_name']) ?></td>
                                <td>
                                    <span class="badge bg-secondary">Y<?= $cs['year_of_study'] ?></span>
                                    <span class="badge bg-info"><?= $cs['semester'] === 'Both' ? '1&2' : ($cs['semester'] === 'Two' ? '2' : '1') ?></span>
                                </td>
                                <td>
                                    <?php if ($cs['lecturer_name']): ?>
                                    <span class="text-success"><?= htmlspecialchars($cs['lecturer_name']) ?></span>
                                    <?php else: ?>
                                    <span class="text-danger">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-primary"><?= $cs['enrolled'] ?></span></td>
                                <td><span class="badge bg-<?= $cs['content_count'] > 0 ? 'success' : 'secondary' ?>"><?= $cs['content_count'] ?></span></td>
                                <td><span class="badge bg-<?= $cs['assignment_count'] > 0 ? 'warning text-dark' : 'secondary' ?>"><?= $cs['assignment_count'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Lecturer Workload -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-person-workspace me-2 text-warning"></i>Lecturer Workload</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($lecturer_workload)): ?>
                <div class="text-center py-4 text-muted">No lecturer data available.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Lecturer</th>
                                <th>Staff Number</th>
                                <th>Email</th>
                                <th>Courses</th>
                                <th>Total Students</th>
                                <th>Load</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lecturer_workload as $lw):
                                $load_color = $lw['courses'] == 0 ? 'secondary' : ($lw['courses'] <= 2 ? 'success' : ($lw['courses'] <= 4 ? 'warning' : 'danger'));
                                $load_label = $lw['courses'] == 0 ? 'Idle' : ($lw['courses'] <= 2 ? 'Light' : ($lw['courses'] <= 4 ? 'Normal' : 'Heavy'));
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($lw['full_name']) ?></strong></td>
                                <td><code>LEC-<?= $lw['lecturer_id'] ?></code></td>
                                <td><small><?= htmlspecialchars($lw['email'] ?? '') ?></small></td>
                                <td><span class="badge bg-primary"><?= $lw['courses'] ?></span></td>
                                <td><span class="badge bg-info"><?= $lw['total_students'] ?></span></td>
                                <td><span class="badge bg-<?= $load_color ?>"><?= $load_label ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-muted text-center py-3 no-print">
            <small>Report generated on <?= date('F j, Y \a\t g:i A') ?></small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
