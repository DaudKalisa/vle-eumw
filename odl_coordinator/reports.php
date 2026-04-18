<?php
/**
 * ODL Coordinator - Reports Dashboard
 * Generate various reports for ODL program management
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Generate report if requested
$report_data = null;
$report_type = $_GET['report'] ?? '';

if ($report_type) {
    switch ($report_type) {
        case 'student_activity':
            $period = $_GET['period'] ?? '30';
            $report_data = [];
            $result = $conn->query("
                SELECT DATE(lh.login_time) as login_date, COUNT(DISTINCT lh.user_id) as unique_users, COUNT(*) as total_logins
                FROM login_history lh
                JOIN users u ON lh.user_id = u.user_id
                WHERE u.role = 'student' AND lh.login_time >= DATE_SUB(NOW(), INTERVAL $period DAY)
                GROUP BY DATE(lh.login_time)
                ORDER BY login_date DESC
            ");
            while ($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            break;
            
        case 'claims_summary':
            $report_data = [];
            $col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
            $status_col = ($col_check && $col_check->num_rows > 0) ? 'odl_approval_status' : 'status';
            
            $result = $conn->query("
                SELECT l.full_name, l.department, COUNT(*) as total_claims, 
                       SUM(r.total_amount) as total_amount,
                       SUM(CASE WHEN r.$status_col = 'approved' THEN r.total_amount ELSE 0 END) as approved_amount,
                       SUM(CASE WHEN r.$status_col = 'pending' THEN r.total_amount ELSE 0 END) as pending_amount
                FROM lecturer_finance_requests r
                LEFT JOIN lecturers l ON r.lecturer_id = l.lecturer_id
                GROUP BY r.lecturer_id
                ORDER BY total_amount DESC
            ");
            while ($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            break;
            
        case 'course_engagement':
            $report_data = [];
            $result = $conn->query("
                SELECT vc.course_code, vc.course_name,
                       (SELECT COUNT(*) FROM vle_enrollments ve WHERE ve.course_id = vc.course_id) as enrolled,
                       (SELECT COUNT(*) FROM vle_weekly_content vwc WHERE vwc.course_id = vc.course_id) as content_count,
                       (SELECT COUNT(*) FROM vle_assignments va WHERE va.course_id = vc.course_id) as assignment_count,
                       (SELECT COUNT(*) FROM vle_submissions vs WHERE vs.assignment_id IN (SELECT assignment_id FROM vle_assignments WHERE course_id = vc.course_id)) as submission_count
                FROM vle_courses vc
                WHERE vc.is_active = TRUE
                ORDER BY enrolled DESC
                LIMIT 50
            ");
            while ($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            break;
            
        case 'exam_performance':
            $report_data = [];
            $result = $conn->query("
                SELECT e.exam_name as title, c.course_code, e.exam_type,
                       COUNT(DISTINCT er.student_id) as total_attempts,
                       AVG(er.percentage) as avg_score,
                       SUM(CASE WHEN er.is_passed = 1 THEN 1 ELSE 0 END) as passed,
                       MIN(er.percentage) as min_score,
                       MAX(er.percentage) as max_score
                FROM exams e
                LEFT JOIN vle_courses c ON e.course_id = c.course_id
                LEFT JOIN exam_results er ON e.exam_id = er.exam_id
                WHERE e.end_time < NOW()
                GROUP BY e.exam_id
                ORDER BY e.end_time DESC
                LIMIT 50
            ");
            while ($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            break;
            
        case 'enrollment_stats':
            $report_data = [];
            $result = $conn->query("
                SELECT COALESCE(s.program, 'Unknown') as program_name, 
                       COUNT(DISTINCT s.student_id) as total_students,
                       SUM(CASE WHEN s.year_of_study = 1 THEN 1 ELSE 0 END) as year_1,
                       SUM(CASE WHEN s.year_of_study = 2 THEN 1 ELSE 0 END) as year_2,
                       SUM(CASE WHEN s.year_of_study = 3 THEN 1 ELSE 0 END) as year_3,
                       SUM(CASE WHEN s.year_of_study = 4 THEN 1 ELSE 0 END) as year_4
                FROM students s
                WHERE s.is_active = TRUE
                GROUP BY s.program
                ORDER BY total_students DESC
            ");
            while ($row = $result->fetch_assoc()) {
                $report_data[] = $row;
            }
            break;
    }
}

$page_title = 'Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .report-card {
            padding: 25px;
            border-radius: 12px;
            background: white;
            border: 1px solid #eee;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-color: #3498db;
        }
        .report-card i { font-size: 40px; margin-bottom: 15px; }
        .report-card h5 { margin-bottom: 10px; }
        .report-card p { color: #666; font-size: 14px; margin: 0; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-graph-up me-2"></i>Reports Dashboard</h1>
                <p class="text-muted mb-0">Generate and view ODL program reports</p>
            </div>
        </div>
        
        <?php if (!$report_type): ?>
        <!-- Report Selection -->
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <a href="?report=student_activity&period=30" class="report-card">
                    <i class="bi bi-person-check text-primary"></i>
                    <h5>Student Activity Report</h5>
                    <p>Login activity and engagement metrics for students over time</p>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="?report=claims_summary" class="report-card">
                    <i class="bi bi-cash-coin text-warning"></i>
                    <h5>Claims Summary Report</h5>
                    <p>Lecturer claims overview by department and approval status</p>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="?report=course_engagement" class="report-card">
                    <i class="bi bi-book text-success"></i>
                    <h5>Course Engagement Report</h5>
                    <p>Course content, assignments, and student submissions analysis</p>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="?report=exam_performance" class="report-card">
                    <i class="bi bi-journal-text text-purple"></i>
                    <h5>Exam Performance Report</h5>
                    <p>Examination results, pass rates, and score distribution</p>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="?report=enrollment_stats" class="report-card">
                    <i class="bi bi-people text-info"></i>
                    <h5>Enrollment Statistics</h5>
                    <p>Student enrollment by program and year of study</p>
                </a>
            </div>
            <div class="col-lg-4 col-md-6">
                <a href="activity_logs.php" class="report-card">
                    <i class="bi bi-clock-history text-secondary"></i>
                    <h5>Activity Logs</h5>
                    <p>Detailed system activity and audit trail logs</p>
                </a>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Report Display -->
        <div class="mb-4">
            <a href="reports.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Reports
            </a>
            <button onclick="window.print()" class="btn btn-outline-primary ms-2">
                <i class="bi bi-printer me-1"></i>Print Report
            </button>
            <button onclick="exportReport()" class="btn btn-outline-success ms-2">
                <i class="bi bi-download me-1"></i>Export CSV
            </button>
        </div>
        
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <?php
                    $titles = [
                        'student_activity' => 'Student Activity Report',
                        'claims_summary' => 'Claims Summary Report',
                        'course_engagement' => 'Course Engagement Report',
                        'exam_performance' => 'Exam Performance Report',
                        'enrollment_stats' => 'Enrollment Statistics'
                    ];
                    echo $titles[$report_type] ?? 'Report';
                    ?>
                </h5>
                <small class="text-muted">Generated: <?= date('F j, Y g:i a') ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($report_data)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="reportTable">
                        <thead class="table-light">
                            <?php if ($report_type === 'student_activity'): ?>
                            <tr>
                                <th>Date</th>
                                <th class="text-center">Unique Users</th>
                                <th class="text-center">Total Logins</th>
                            </tr>
                            <?php elseif ($report_type === 'claims_summary'): ?>
                            <tr>
                                <th>Lecturer</th>
                                <th>Department</th>
                                <th class="text-center">Claims</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-end">Approved</th>
                                <th class="text-end">Pending</th>
                            </tr>
                            <?php elseif ($report_type === 'course_engagement'): ?>
                            <tr>
                                <th>Course</th>
                                <th class="text-center">Enrolled</th>
                                <th class="text-center">Content</th>
                                <th class="text-center">Assignments</th>
                                <th class="text-center">Submissions</th>
                            </tr>
                            <?php elseif ($report_type === 'exam_performance'): ?>
                            <tr>
                                <th>Exam</th>
                                <th>Course</th>
                                <th>Type</th>
                                <th class="text-center">Attempts</th>
                                <th class="text-center">Avg Score</th>
                                <th class="text-center">Passed</th>
                                <th class="text-center">Range</th>
                            </tr>
                            <?php elseif ($report_type === 'enrollment_stats'): ?>
                            <tr>
                                <th>Program</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Year 1</th>
                                <th class="text-center">Year 2</th>
                                <th class="text-center">Year 3</th>
                                <th class="text-center">Year 4</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <?php if ($report_type === 'student_activity'): ?>
                                <td><?= date('M j, Y', strtotime($row['login_date'])) ?></td>
                                <td class="text-center"><span class="badge bg-primary"><?= $row['unique_users'] ?></span></td>
                                <td class="text-center"><?= $row['total_logins'] ?></td>
                                
                                <?php elseif ($report_type === 'claims_summary'): ?>
                                <td><strong><?= htmlspecialchars($row['full_name'] ?? 'Unknown') ?></strong></td>
                                <td><?= htmlspecialchars($row['department'] ?? 'N/A') ?></td>
                                <td class="text-center"><?= $row['total_claims'] ?></td>
                                <td class="text-end">MKW<?= number_format($row['total_amount']) ?></td>
                                <td class="text-end text-success">MKW<?= number_format($row['approved_amount']) ?></td>
                                <td class="text-end text-warning">MKW<?= number_format($row['pending_amount']) ?></td>
                                
                                <?php elseif ($report_type === 'course_engagement'): ?>
                                <td>
                                    <strong><?= htmlspecialchars($row['course_code']) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($row['course_name']) ?></div>
                                </td>
                                <td class="text-center"><span class="badge bg-primary"><?= $row['enrolled'] ?></span></td>
                                <td class="text-center"><?= $row['content_count'] ?></td>
                                <td class="text-center"><?= $row['assignment_count'] ?></td>
                                <td class="text-center"><span class="badge bg-success"><?= $row['submission_count'] ?></span></td>
                                
                                <?php elseif ($report_type === 'exam_performance'): ?>
                                <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                                <td><?= htmlspecialchars($row['course_code'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-light text-dark"><?= ucfirst($row['exam_type'] ?? 'exam') ?></span></td>
                                <td class="text-center"><?= $row['total_attempts'] ?></td>
                                <td class="text-center">
                                    <?php if ($row['avg_score'] !== null): ?>
                                    <strong class="text-<?= $row['avg_score'] >= 50 ? 'success' : 'danger' ?>"><?= number_format($row['avg_score'], 1) ?>%</strong>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['total_attempts'] > 0): ?>
                                    <?= round(($row['passed'] / $row['total_attempts']) * 100) ?>%
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['min_score'] !== null): ?>
                                    <small><?= number_format($row['min_score'], 0) ?>% - <?= number_format($row['max_score'], 0) ?>%</small>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                
                                <?php elseif ($report_type === 'enrollment_stats'): ?>
                                <td><strong><?= htmlspecialchars($row['program_name'] ?? 'Unassigned') ?></strong></td>
                                <td class="text-center"><span class="badge bg-primary"><?= $row['total_students'] ?></span></td>
                                <td class="text-center"><?= $row['year_1'] ?></td>
                                <td class="text-center"><?= $row['year_2'] ?></td>
                                <td class="text-center"><?= $row['year_3'] ?></td>
                                <td class="text-center"><?= $row['year_4'] ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-x display-1 text-muted"></i>
                    <p class="mt-3 text-muted">No data available for this report</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function exportReport() {
        const table = document.getElementById('reportTable');
        if (!table) return;
        
        let csv = [];
        const rows = table.querySelectorAll('tr');
        
        for (let row of rows) {
            let cols = row.querySelectorAll('th, td');
            let rowData = [];
            for (let col of cols) {
                rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
            }
            csv.push(rowData.join(','));
        }
        
        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'odl_report_<?= date('Y-m-d') ?>.csv';
        a.click();
    }
    </script>
</body>
</html>
