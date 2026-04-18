<?php
/**
 * Dean Portal - Faculty Reports
 * Generate and view academic reports for the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$dean_faculty_id = $user['related_dean_id'] ?? null;

// Get report type
$report_type = $_GET['type'] ?? 'overview';
$export = $_GET['export'] ?? '';

// Date range filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Calendar filters
$cal_year = $_GET['cal_year'] ?? '';
$cal_semester = $_GET['cal_semester'] ?? '';
$cal_program = $_GET['cal_program'] ?? '';

// ==================== REPORT DATA FUNCTIONS ====================

function getOverviewReport($conn, $faculty_id = null) {
    $data = [];
    
    // Departments count
    if ($faculty_id) {
        $result = $conn->query("SELECT COUNT(*) as total FROM departments WHERE faculty_id = $faculty_id");
    } else {
        $result = $conn->query("SELECT COUNT(*) as total FROM departments");
    }
    $data['departments'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Programs count
    $result = $conn->query("SELECT COUNT(*) as total FROM programs");
    $data['programs'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Lecturers count
    $result = $conn->query("SELECT COUNT(*) as total FROM lecturers");
    $data['lecturers'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Students count
    $result = $conn->query("SELECT COUNT(*) as total FROM students");
    $data['students'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    // Courses count
    $table = $conn->query("SHOW TABLES LIKE 'vle_courses'")->num_rows > 0 ? 'vle_courses' : 'courses';
    $result = $conn->query("SELECT COUNT(*) as total FROM $table");
    $data['courses'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    return $data;
}

function getLecturerReport($conn, $faculty_id = null) {
    $lecturers = [];
    
    $sql = "SELECT l.*, 
            (SELECT COUNT(*) FROM vle_courses c WHERE c.lecturer_id = l.lecturer_id) as course_count,
            (SELECT SUM(total_amount) FROM lecturer_finance_requests r WHERE r.lecturer_id = l.lecturer_id AND r.status = 'paid') as total_payments
            FROM lecturers l 
            ORDER BY l.full_name";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $lecturers[] = $row;
        }
    }
    
    return $lecturers;
}

function getStudentReport($conn, $faculty_id = null) {
    $students = [];
    
    // Check if programs has department_id
    $has_dept_col = $conn->query("SHOW COLUMNS FROM programs LIKE 'department_id'");
    if ($has_dept_col && $has_dept_col->num_rows > 0) {
        $sql = "SELECT s.*, p.program_name, p.program_code, d.department_name
                FROM students s
                LEFT JOIN programs p ON s.program = p.program_code OR s.program = p.program_name
                LEFT JOIN departments d ON p.department_id = d.department_id
                ORDER BY s.full_name
                LIMIT 500";
    } else {
        $sql = "SELECT s.*, p.program_name, p.program_code
                FROM students s
                LEFT JOIN programs p ON s.program = p.program_code OR s.program = p.program_name
                ORDER BY s.full_name
                LIMIT 500";
    }
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    
    return $students;
}

function getClaimsReport($conn, $start_date, $end_date) {
    $claims = [];
    
    $sql = "SELECT r.*, l.full_name, l.department
            FROM lecturer_finance_requests r
            JOIN lecturers l ON r.lecturer_id = l.lecturer_id
            WHERE r.request_date BETWEEN ? AND ?
            ORDER BY r.request_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $claims[] = $row;
        }
    }
    
    return $claims;
}

function getExamReport($conn) {
    $exams = [];
    
    $exam_table = $conn->query("SHOW TABLES LIKE 'exams'")->num_rows > 0 ? 'exams' : 'vle_exams';
    
    $result = $conn->query("SELECT * FROM $exam_table ORDER BY created_at DESC LIMIT 100");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $exams[] = $row;
        }
    }
    
    return $exams;
}

function getCalendarReport($conn, $filter_year = '', $filter_semester = '', $filter_program = '') {
    $events = [];
    $where = [];
    $params = [];
    $types = '';

    if ($filter_year) { $where[] = "academic_year = ?"; $params[] = $filter_year; $types .= 's'; }
    if ($filter_semester) { $where[] = "semester = ?"; $params[] = $filter_semester; $types .= 's'; }
    if ($filter_program) { $where[] = "(program_type = ? OR program_type = 'all')"; $params[] = $filter_program; $types .= 's'; }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT * FROM academic_calendar $where_sql ORDER BY semester ASC, start_date ASC";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
    }

    return $events;
}

function getCalendarYears($conn) {
    $years = [];
    $result = $conn->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $years[] = $row['academic_year'];
        }
    }
    return $years;
}

function getCalendarSemesters($conn) {
    $semesters = [];
    $result = $conn->query("SELECT DISTINCT semester FROM academic_calendar ORDER BY semester ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $semesters[] = $row['semester'];
        }
    }
    return $semesters;
}

// Get report data based on type
$report_data = [];
switch ($report_type) {
    case 'lecturers':
        $report_data = getLecturerReport($conn, $dean_faculty_id);
        break;
    case 'students':
        $report_data = getStudentReport($conn, $dean_faculty_id);
        break;
    case 'claims':
        $report_data = getClaimsReport($conn, $start_date, $end_date);
        break;
    case 'exams':
        $report_data = getExamReport($conn);
        break;
    case 'calendar':
        $report_data = getCalendarReport($conn, $cal_year, $cal_semester, $cal_program);
        $calendar_years = getCalendarYears($conn);
        $calendar_semesters = getCalendarSemesters($conn);
        break;
    default:
        $report_data = getOverviewReport($conn, $dean_faculty_id);
}

// Export to CSV
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dean_report_' . $report_type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($report_type === 'calendar' && !empty($report_data)) {
        fputcsv($output, ['Academic Year', 'Semester', 'Event', 'Type', 'Program', 'Start Date', 'End Date', 'Description', 'Status']);
        foreach ($report_data as $row) {
            fputcsv($output, [
                $row['academic_year'],
                $row['semester'],
                $row['event_name'],
                ucfirst(str_replace('_', ' ', $row['event_type'])),
                ucfirst($row['program_type'] ?? 'all'),
                $row['start_date'],
                $row['end_date'] ?? '',
                $row['description'] ?? '',
                $row['is_active'] ? 'Active' : 'Inactive'
            ]);
        }
    } elseif ($report_type === 'lecturers' && !empty($report_data)) {
        fputcsv($output, ['Name', 'Email', 'Department', 'Courses', 'Total Payments']);
        foreach ($report_data as $row) {
            fputcsv($output, [
                $row['full_name'],
                $row['email'],
                $row['department'] ?? '',
                $row['course_count'] ?? 0,
                $row['total_payments'] ?? 0
            ]);
        }
    } elseif ($report_type === 'students' && !empty($report_data)) {
        fputcsv($output, ['Name', 'Student ID', 'Email', 'Program', 'Department']);
        foreach ($report_data as $row) {
            fputcsv($output, [
                $row['full_name'],
                $row['student_id'],
                $row['email'],
                $row['program_name'] ?? '',
                $row['department_name'] ?? ''
            ]);
        }
    } elseif ($report_type === 'claims' && !empty($report_data)) {
        fputcsv($output, ['Lecturer', 'Department', 'Month', 'Year', 'Amount', 'Status', 'Date']);
        foreach ($report_data as $row) {
            fputcsv($output, [
                $row['full_name'],
                $row['department'] ?? '',
                $row['month'],
                $row['year'],
                $row['total_amount'],
                $row['status'],
                $row['request_date']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = "Faculty Reports";
$breadcrumbs = [['title' => 'Reports']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Report Types</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="?type=overview" class="list-group-item list-group-item-action <?= $report_type === 'overview' ? 'active' : '' ?>">
                            <i class="bi bi-pie-chart me-2"></i> Overview
                        </a>
                        <a href="?type=lecturers" class="list-group-item list-group-item-action <?= $report_type === 'lecturers' ? 'active' : '' ?>">
                            <i class="bi bi-person-badge me-2"></i> Lecturers
                        </a>
                        <a href="?type=students" class="list-group-item list-group-item-action <?= $report_type === 'students' ? 'active' : '' ?>">
                            <i class="bi bi-people me-2"></i> Students
                        </a>
                        <a href="?type=claims" class="list-group-item list-group-item-action <?= $report_type === 'claims' ? 'active' : '' ?>">
                            <i class="bi bi-cash me-2"></i> Claims
                        </a>
                        <a href="?type=exams" class="list-group-item list-group-item-action <?= $report_type === 'exams' ? 'active' : '' ?>">
                            <i class="bi bi-journal-text me-2"></i> Examinations
                        </a>
                        <a href="?type=calendar" class="list-group-item list-group-item-action <?= $report_type === 'calendar' ? 'active' : '' ?>">
                            <i class="bi bi-calendar-event me-2"></i> Calendar of Events
                        </a>
                    </div>
                </div>
                
                <?php if (in_array($report_type, ['claims', 'calendar'])): ?>
                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <input type="hidden" name="type" value="<?= $report_type ?>">
                            <?php if ($report_type === 'calendar'): ?>
                            <div class="mb-3">
                                <label class="form-label">Academic Year</label>
                                <select name="cal_year" class="form-select">
                                    <option value="">All Years</option>
                                    <?php foreach ($calendar_years ?? [] as $y): ?>
                                    <option value="<?= htmlspecialchars($y) ?>" <?= $cal_year === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Semester</label>
                                <select name="cal_semester" class="form-select">
                                    <option value="">All Semesters</option>
                                    <?php foreach ($calendar_semesters ?? [] as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $cal_semester === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Program Type</label>
                                <select name="cal_program" class="form-select">
                                    <option value="">All Programs</option>
                                    <option value="weekday" <?= $cal_program === 'weekday' ? 'selected' : '' ?>>Weekday</option>
                                    <option value="weekend" <?= $cal_program === 'weekend' ? 'selected' : '' ?>>Weekend</option>
                                </select>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-graph-up me-2"></i>
                            <?= ucfirst($report_type) ?> Report
                        </h5>
                        <?php if ($report_type !== 'overview'): ?>
                        <a href="?type=<?= $report_type ?>&export=csv&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>&cal_year=<?= htmlspecialchars($cal_year) ?>&cal_semester=<?= htmlspecialchars($cal_semester) ?>&cal_program=<?= htmlspecialchars($cal_program) ?>" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-download me-1"></i> Export CSV
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($report_type === 'overview'): ?>
                        <!-- Overview Report -->
                        <div class="row g-4 mb-4">
                            <div class="col-md-4">
                                <div class="p-4 bg-primary bg-opacity-10 rounded text-center">
                                    <div class="fs-1 fw-bold text-primary"><?= $report_data['departments'] ?></div>
                                    <div class="text-muted">Departments</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-4 bg-success bg-opacity-10 rounded text-center">
                                    <div class="fs-1 fw-bold text-success"><?= $report_data['programs'] ?></div>
                                    <div class="text-muted">Programs</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-4 bg-info bg-opacity-10 rounded text-center">
                                    <div class="fs-1 fw-bold text-info"><?= $report_data['lecturers'] ?></div>
                                    <div class="text-muted">Lecturers</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-4 bg-warning bg-opacity-10 rounded text-center">
                                    <div class="fs-1 fw-bold text-warning"><?= $report_data['students'] ?></div>
                                    <div class="text-muted">Students</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-4 bg-danger bg-opacity-10 rounded text-center">
                                    <div class="fs-1 fw-bold text-danger"><?= $report_data['courses'] ?></div>
                                    <div class="text-muted">Courses</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="overviewChart"></canvas>
                            </div>
                            <div class="col-md-6">
                                <h6>Quick Actions</h6>
                                <div class="list-group">
                                    <a href="?type=lecturers" class="list-group-item list-group-item-action">
                                        <i class="bi bi-person-badge me-2"></i> View Lecturer Report
                                    </a>
                                    <a href="?type=students" class="list-group-item list-group-item-action">
                                        <i class="bi bi-people me-2"></i> View Student Report
                                    </a>
                                    <a href="?type=claims" class="list-group-item list-group-item-action">
                                        <i class="bi bi-cash me-2"></i> View Claims Report
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <script>
                            new Chart(document.getElementById('overviewChart'), {
                                type: 'doughnut',
                                data: {
                                    labels: ['Departments', 'Programs', 'Lecturers', 'Students', 'Courses'],
                                    datasets: [{
                                        data: [<?= $report_data['departments'] ?>, <?= $report_data['programs'] ?>, <?= $report_data['lecturers'] ?>, <?= $report_data['students'] ?>, <?= $report_data['courses'] ?>],
                                        backgroundColor: ['#0d6efd', '#198754', '#0dcaf0', '#ffc107', '#dc3545']
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: { position: 'bottom' }
                                    }
                                }
                            });
                        </script>
                        
                        <?php elseif ($report_type === 'lecturers'): ?>
                        <!-- Lecturers Report -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Courses</th>
                                        <th>Total Payments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $lecturer): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($lecturer['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($lecturer['email']) ?></td>
                                        <td><?= htmlspecialchars($lecturer['department'] ?? 'N/A') ?></td>
                                        <td><span class="badge bg-primary"><?= $lecturer['course_count'] ?? 0 ?></span></td>
                                        <td>MK <?= number_format($lecturer['total_payments'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php elseif ($report_type === 'students'): ?>
                        <!-- Students Report -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Student ID</th>
                                        <th>Email</th>
                                        <th>Program</th>
                                        <th>Department</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $student): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($student['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($student['student_id']) ?></td>
                                        <td><?= htmlspecialchars($student['email']) ?></td>
                                        <td><?= htmlspecialchars($student['program_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($student['department_name'] ?? 'N/A') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted small mt-2">Showing up to 500 students</div>
                        
                        <?php elseif ($report_type === 'claims'): ?>
                        <!-- Claims Report -->
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Showing claims from <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Lecturer</th>
                                        <th>Department</th>
                                        <th>Period</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_amount = 0;
                                    foreach ($report_data as $claim): 
                                        $total_amount += $claim['total_amount'];
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($claim['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></td>
                                        <td><?= date('M Y', mktime(0, 0, 0, $claim['month'], 1, $claim['year'])) ?></td>
                                        <td>MK <?= number_format($claim['total_amount']) ?></td>
                                        <td>
                                            <?php
                                            $badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'paid' => 'info'][$claim['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge ?>"><?= ucfirst($claim['status']) ?></span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($claim['request_date'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="3">Total</th>
                                        <th>MK <?= number_format($total_amount) ?></th>
                                        <th colspan="2"><?= count($report_data) ?> claims</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <?php elseif ($report_type === 'calendar'): ?>
                        <!-- Calendar of Events Report -->
                        <div class="mb-3 text-end">
                            <a href="print_calendar.php<?= $cal_year ? '?year=' . urlencode($cal_year) : '' ?><?= $cal_program ? ($cal_year ? '&' : '?') . 'program=' . urlencode($cal_program) : '' ?>" class="btn btn-outline-dark btn-sm" target="_blank">
                                <i class="bi bi-printer me-1"></i>Printable Version
                            </a>
                        </div>
                        <?php
                        $event_type_colors = [
                            'semester_start' => 'success', 'semester_end' => 'danger',
                            'exam_start' => 'warning', 'exam_end' => 'info',
                            'registration_start' => 'primary', 'registration_end' => 'secondary',
                            'holiday' => 'success', 'break' => 'info',
                            'graduation' => 'warning', 'other' => 'secondary'
                        ];
                        $program_colors = ['all' => 'primary', 'weekday' => 'info', 'weekend' => 'warning'];
                        // Summary stats
                        $total_events = count($report_data);
                        $semesters_found = array_unique(array_column($report_data, 'semester'));
                        sort($semesters_found);
                        ?>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="p-3 bg-primary bg-opacity-10 rounded text-center">
                                    <div class="fs-2 fw-bold text-primary"><?= $total_events ?></div>
                                    <div class="text-muted small">Total Events</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-success bg-opacity-10 rounded text-center">
                                    <div class="fs-2 fw-bold text-success"><?= count($semesters_found) ?></div>
                                    <div class="text-muted small">Semesters</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-info bg-opacity-10 rounded text-center">
                                    <div class="fs-2 fw-bold text-info"><?= count(array_unique(array_column($report_data, 'academic_year'))) ?></div>
                                    <div class="text-muted small">Academic Years</div>
                                </div>
                            </div>
                        </div>

                        <?php
                        $current_semester = '';
                        foreach ($report_data as $event):
                            if ($event['semester'] !== $current_semester):
                                if ($current_semester !== '') echo '</tbody></table></div></div>';
                                $current_semester = $event['semester'];
                        ?>
                        <div class="card mb-4 border">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-bookmark-fill me-2 text-primary"></i>
                                    <?= htmlspecialchars($current_semester) ?>
                                    <span class="text-muted">— <?= htmlspecialchars($event['academic_year']) ?></span>
                                </h6>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:30%">Event</th>
                                            <th>Type</th>
                                            <th>Program</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        <?php endif; ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($event['event_name']) ?></strong>
                                            <?php if (!empty($event['description'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($event['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $event_type_colors[$event['event_type']] ?? 'secondary' ?>">
                                                <?= ucfirst(str_replace('_', ' ', $event['event_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $program_colors[$event['program_type'] ?? 'all'] ?? 'secondary' ?>">
                                                <?= ucfirst($event['program_type'] ?? 'All') ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($event['start_date'])) ?></td>
                                        <td><?= $event['end_date'] ? date('M d, Y', strtotime($event['end_date'])) : '—' ?></td>
                                        <td>
                                            <?php if ($event['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                        <?php endforeach; ?>
                        <?php if ($current_semester !== ''): ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($report_data)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>
                            <p>No calendar events found for the selected filters.</p>
                            <a href="?type=calendar" class="btn btn-outline-primary btn-sm">Clear Filters</a>
                        </div>
                        <?php endif; ?>

                        <?php elseif ($report_type === 'exams'): ?>
                        <!-- Exams Report -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Exam</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($report_data)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No exam data available</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($report_data as $exam): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($exam['exam_name'] ?? $exam['course_code'] ?? 'Exam') ?></strong></td>
                                        <td><?= isset($exam['start_time']) ? date('M d, Y H:i', strtotime($exam['start_time'])) : (isset($exam['created_at']) ? date('M d, Y', strtotime($exam['created_at'])) : 'N/A') ?></td>
                                        <td>
                                            <span class="badge bg-<?= ($exam['status'] ?? '') === 'completed' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($exam['status'] ?? 'scheduled') ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
