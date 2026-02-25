<?php
/**
 * Examination Reports Dashboard - NCHE Accreditation Compliant
 * Comprehensive reports for Examination Officers and Managers
 * Reports aligned with National Council for Higher Education (NCHE) standards
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$page_title = 'Examination Reports';
$breadcrumbs = [
    ['title' => 'Reports']
];

// --- Get filters ---
$filter_course = (int)($_GET['course_id'] ?? 0);
$filter_exam = (int)($_GET['exam_id'] ?? 0);
$filter_type = $_GET['exam_type'] ?? '';
$filter_dept = (int)($_GET['department_id'] ?? 0);
$filter_year = $_GET['academic_year'] ?? '';

// --- Statistics for overview cards ---
$stats = [];

// Total exams
$r = $conn->query("SELECT COUNT(*) as cnt FROM exams");
$stats['total_exams'] = $r->fetch_assoc()['cnt'] ?? 0;

// Published results
$r = $conn->query("SELECT COUNT(*) as cnt FROM exams WHERE results_published = 1");
$stats['published'] = $r->fetch_assoc()['cnt'] ?? 0;

// Total results entries
$r = $conn->query("SELECT COUNT(*) as cnt FROM exam_results");
$stats['total_results'] = $r->fetch_assoc()['cnt'] ?? 0;

// Overall pass rate
$r = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) as passed,
    AVG(percentage) as avg_pct
    FROM exam_results");
$pass_data = $r->fetch_assoc();
$stats['pass_rate'] = $pass_data['total'] > 0 ? round(($pass_data['passed'] / $pass_data['total']) * 100, 1) : 0;
$stats['avg_score'] = round($pass_data['avg_pct'] ?? 0, 1);

// Grade distribution (institution-wide)
$r = $conn->query("SELECT grade, COUNT(*) as cnt FROM exam_results GROUP BY grade ORDER BY grade");
$grade_dist = [];
while ($row = $r->fetch_assoc()) {
    $grade_dist[$row['grade']] = $row['cnt'];
}

// Get available exams for filter
$exams_list = [];
$r = $conn->query("SELECT e.exam_id, e.exam_name, e.exam_code, e.exam_type, c.course_name, c.course_code 
                    FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id 
                    ORDER BY e.start_time DESC");
while ($row = $r->fetch_assoc()) $exams_list[] = $row;

// Get courses for filter
$courses_list = [];
$r = $conn->query("SELECT course_id, course_name, course_code FROM vle_courses ORDER BY course_name");
while ($row = $r->fetch_assoc()) $courses_list[] = $row;

// Get departments for filter
$depts_list = [];
$r = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
while ($row = $r->fetch_assoc()) $depts_list[] = $row;

// --- NCHE Report Data: Exam-level analysis ---
$exam_analysis_query = "
    SELECT e.exam_id, e.exam_name, e.exam_code, e.exam_type, e.total_marks, e.passing_marks,
           e.results_published, e.start_time,
           c.course_name, c.course_code,
           COUNT(er.result_id) as candidates,
           SUM(CASE WHEN er.is_passed = 1 THEN 1 ELSE 0 END) as passed,
           SUM(CASE WHEN er.is_passed = 0 THEN 1 ELSE 0 END) as failed,
           ROUND(AVG(er.percentage), 1) as avg_pct,
           ROUND(MAX(er.percentage), 1) as highest_pct,
           ROUND(MIN(er.percentage), 1) as lowest_pct,
           ROUND(STDDEV(er.percentage), 1) as std_dev,
           SUM(CASE WHEN er.grade = 'A' THEN 1 ELSE 0 END) as grade_a,
           SUM(CASE WHEN er.grade = 'B' THEN 1 ELSE 0 END) as grade_b,
           SUM(CASE WHEN er.grade = 'C' THEN 1 ELSE 0 END) as grade_c,
           SUM(CASE WHEN er.grade = 'D' THEN 1 ELSE 0 END) as grade_d,
           SUM(CASE WHEN er.grade = 'F' THEN 1 ELSE 0 END) as grade_f
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    LEFT JOIN exam_results er ON e.exam_id = er.exam_id
    WHERE e.results_published = 1
    GROUP BY e.exam_id
    ORDER BY e.start_time DESC
";
$exam_analysis = [];
$r = $conn->query($exam_analysis_query);
if ($r) while ($row = $r->fetch_assoc()) $exam_analysis[] = $row;

// --- Department-level analysis ---
$dept_analysis_query = "
    SELECT d.department_id, d.department_name,
           COUNT(DISTINCT e.exam_id) as total_exams,
           COUNT(er.result_id) as total_candidates,
           SUM(CASE WHEN er.is_passed = 1 THEN 1 ELSE 0 END) as passed,
           ROUND(AVG(er.percentage), 1) as avg_pct,
           ROUND(
               CASE WHEN COUNT(er.result_id) > 0 
               THEN (SUM(CASE WHEN er.is_passed = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(er.result_id))
               ELSE 0 END, 1) as pass_rate
    FROM departments d
    LEFT JOIN vle_courses c ON (d.department_id = c.department_id OR d.department_name = c.department)
    LEFT JOIN exams e ON c.course_id = e.course_id AND e.results_published = 1
    LEFT JOIN exam_results er ON e.exam_id = er.exam_id
    GROUP BY d.department_id
    HAVING total_candidates > 0
    ORDER BY pass_rate DESC
";
$dept_analysis = [];
$r = $conn->query($dept_analysis_query);
if ($r) while ($row = $r->fetch_assoc()) $dept_analysis[] = $row;
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Examinations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .report-card { border-left: 4px solid; transition: transform 0.2s; }
        .report-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
        .report-card.primary { border-color: #0d6efd; }
        .report-card.success { border-color: #198754; }
        .report-card.warning { border-color: #fd7e14; }
        .report-card.danger { border-color: #dc3545; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .grade-bar { display: inline-block; height: 20px; border-radius: 3px; min-width: 4px; }
        .analysis-table th { background: #f0f4f8; font-size: 0.85rem; white-space: nowrap; }
        .analysis-table td { font-size: 0.85rem; vertical-align: middle; }
        .nche-badge { background: linear-gradient(135deg, #1a3a6e, #2563eb); color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .report-type-card { cursor: pointer; transition: all 0.3s; border: 2px solid transparent; }
        .report-type-card:hover { border-color: #0d6efd; background: #f0f7ff; }
        .report-type-card.selected { border-color: #0d6efd; background: #e7f1ff; }
        .performance-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 5px; }
        .pi-excellent { background: #198754; }
        .pi-good { background: #0d6efd; }
        .pi-average { background: #fd7e14; }
        .pi-poor { background: #dc3545; }
    </style>
</head>
<body>

<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <!-- Page Title -->
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-bar-graph me-2"></i>Examination Reports</h4>
            <p class="text-muted mb-0"><span class="nche-badge"><i class="bi bi-award me-1"></i>NCHE Compliant</span> Comprehensive examination analysis and accreditation reports</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print Page
            </button>
        </div>
    </div>

    <!-- Overview Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card report-card primary">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-journal-check"></i></div>
                    <div>
                        <div class="text-muted small">Total Examinations</div>
                        <div class="fw-bold fs-4"><?= $stats['total_exams'] ?></div>
                        <small class="text-muted"><?= $stats['published'] ?> results published</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card report-card success">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="text-muted small">Total Candidates</div>
                        <div class="fw-bold fs-4"><?= number_format($stats['total_results']) ?></div>
                        <small class="text-muted">exam entries processed</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card report-card warning">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-graph-up-arrow"></i></div>
                    <div>
                        <div class="text-muted small">Institution Pass Rate</div>
                        <div class="fw-bold fs-4"><?= $stats['pass_rate'] ?>%</div>
                        <small class="text-muted">
                            <?php if ($stats['pass_rate'] >= 70): ?>
                                <span class="text-success"><i class="bi bi-check-circle"></i> Above NCHE threshold</span>
                            <?php else: ?>
                                <span class="text-danger"><i class="bi bi-exclamation-circle"></i> Below NCHE threshold</span>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card report-card danger">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-bar-chart"></i></div>
                    <div>
                        <div class="text-muted small">Average Score</div>
                        <div class="fw-bold fs-4"><?= $stats['avg_score'] ?>%</div>
                        <small class="text-muted">across all exams</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Generation Section -->
    <div class="row g-4 mb-4">
        <!-- Generate PDF Reports -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white border-bottom-0 pb-0">
                    <h5 class="card-title fw-bold"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Generate NCHE Reports</h5>
                    <p class="text-muted small mb-0">Select a report type and filters to generate official PDF reports</p>
                </div>
                <div class="card-body">
                    <form id="reportForm" method="GET" action="generate_exam_report.php" target="_blank">
                        <div class="row g-3 mb-3">
                            <!-- Report Type Selection -->
                            <div class="col-12">
                                <label class="form-label fw-bold">Report Type</label>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="report-type-card card p-3 h-100" data-type="exam_results_summary">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="exam_results_summary" class="form-check-input me-2" checked>
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-clipboard-data text-primary me-1"></i>Results Summary</div>
                                                    <small class="text-muted">Per-exam results with grade distribution</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="report-type-card card p-3 h-100" data-type="course_analysis">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="course_analysis" class="form-check-input me-2">
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-book text-success me-1"></i>Course Analysis</div>
                                                    <small class="text-muted">Course-level performance analysis</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="report-type-card card p-3 h-100" data-type="department_performance">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="department_performance" class="form-check-input me-2">
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-building text-info me-1"></i>Department Performance</div>
                                                    <small class="text-muted">Department-level NCHE compliance</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mt-2">
                                        <div class="report-type-card card p-3 h-100" data-type="grade_distribution">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="grade_distribution" class="form-check-input me-2">
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-bar-chart-steps text-warning me-1"></i>Grade Distribution</div>
                                                    <small class="text-muted">Detailed grade analysis &amp; statistics</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mt-2">
                                        <div class="report-type-card card p-3 h-100" data-type="consolidated_semester">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="consolidated_semester" class="form-check-input me-2">
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-calendar3 text-danger me-1"></i>Consolidated Report</div>
                                                    <small class="text-muted">Full semester NCHE accreditation report</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mt-2">
                                        <div class="report-type-card card p-3 h-100" data-type="student_list">
                                            <div class="d-flex align-items-center">
                                                <input type="radio" name="report_type" value="student_list" class="form-check-input me-2">
                                                <div>
                                                    <div class="fw-bold small"><i class="bi bi-person-lines-fill text-secondary me-1"></i>Student Results List</div>
                                                    <small class="text-muted">Per-exam student-level results</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Filters -->
                            <div class="col-md-4">
                                <label class="form-label">Examination</label>
                                <select name="exam_id" class="form-select form-select-sm">
                                    <option value="">-- All Examinations --</option>
                                    <?php foreach ($exams_list as $ex): ?>
                                    <option value="<?= $ex['exam_id'] ?>"><?= htmlspecialchars($ex['exam_code'] . ' - ' . $ex['exam_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Course</label>
                                <select name="course_id" class="form-select form-select-sm">
                                    <option value="">-- All Courses --</option>
                                    <?php foreach ($courses_list as $c): ?>
                                    <option value="<?= $c['course_id'] ?>"><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Department</label>
                                <select name="department_id" class="form-select form-select-sm">
                                    <option value="">-- All Departments --</option>
                                    <?php foreach ($depts_list as $d): ?>
                                    <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Exam Type</label>
                                <select name="exam_type" class="form-select form-select-sm">
                                    <option value="">-- All Types --</option>
                                    <option value="quiz">Quiz</option>
                                    <option value="mid_term">Mid-Term</option>
                                    <option value="final">Final Exam</option>
                                    <option value="assignment">Continuous Assessment</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Format</label>
                                <select name="format" class="form-select form-select-sm">
                                    <option value="pdf">PDF Report</option>
                                    <option value="csv">CSV Export</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-download me-1"></i>Generate Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Institution-Wide Grade Distribution -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h6 class="card-title fw-bold mb-0"><i class="bi bi-pie-chart me-2"></i>Institution Grade Distribution</h6>
                </div>
                <div class="card-body">
                    <?php
                    $all_grades = ['A', 'B', 'C', 'D', 'F'];
                    $grade_colors = ['A' => '#198754', 'B' => '#0d6efd', 'C' => '#6f42c1', 'D' => '#fd7e14', 'F' => '#dc3545'];
                    $grade_labels = ['A' => 'Distinction (75-100%)', 'B' => 'Credit (65-74%)', 'C' => 'Pass (50-64%)', 'D' => 'Supplementary (40-49%)', 'F' => 'Fail (0-39%)'];
                    $total_graded = array_sum($grade_dist);
                    foreach ($all_grades as $g):
                        $count = $grade_dist[$g] ?? 0;
                        $pct = $total_graded > 0 ? round(($count / $total_graded) * 100, 1) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-bold" style="color: <?= $grade_colors[$g] ?>">Grade <?= $g ?></span>
                            <span class="small text-muted"><?= $count ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background: <?= $grade_colors[$g] ?>;"></div>
                        </div>
                        <small class="text-muted"><?= $grade_labels[$g] ?></small>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center pt-2 border-top mt-3">
                        <small class="text-muted">Total Graded Entries: <strong><?= number_format($total_graded) ?></strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Examination Analysis Table (NCHE Format) -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title fw-bold mb-0"><i class="bi bi-table me-2"></i>Examination Performance Analysis</h5>
                <small class="text-muted">NCHE Standard - Per-examination statistics for all published results</small>
            </div>
            <a href="generate_exam_report.php?report_type=exam_results_summary&format=csv" class="btn btn-outline-success btn-sm">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover analysis-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Exam Code</th>
                            <th>Examination / Course</th>
                            <th>Type</th>
                            <th class="text-center">Candidates</th>
                            <th class="text-center">Passed</th>
                            <th class="text-center">Failed</th>
                            <th class="text-center">Pass Rate</th>
                            <th class="text-center">Mean %</th>
                            <th class="text-center">Highest</th>
                            <th class="text-center">Lowest</th>
                            <th class="text-center">Std Dev</th>
                            <th class="text-center">Grade Distribution</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($exam_analysis)): ?>
                        <tr><td colspan="14" class="text-center py-4 text-muted">No published exam results yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($exam_analysis as $i => $ea):
                            $pass_rate = $ea['candidates'] > 0 ? round(($ea['passed'] / $ea['candidates']) * 100, 1) : 0;
                            $pr_class = $pass_rate >= 70 ? 'text-success' : ($pass_rate >= 50 ? 'text-warning' : 'text-danger');
                            $type_labels = ['quiz' => 'Quiz', 'mid_term' => 'Mid-Term', 'final' => 'Final', 'assignment' => 'CA'];
                            $type_label = $type_labels[$ea['exam_type']] ?? ucfirst($ea['exam_type']);
                            
                            // Mini grade distribution bar
                            $grades_total = $ea['grade_a'] + $ea['grade_b'] + $ea['grade_c'] + $ea['grade_d'] + $ea['grade_f'];
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($ea['exam_code']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($ea['exam_name']) ?>
                                <?php if ($ea['course_name']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($ea['course_code'] . ' - ' . $ea['course_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= $type_label ?></span></td>
                            <td class="text-center"><?= $ea['candidates'] ?></td>
                            <td class="text-center text-success fw-bold"><?= $ea['passed'] ?></td>
                            <td class="text-center text-danger fw-bold"><?= $ea['failed'] ?></td>
                            <td class="text-center <?= $pr_class ?> fw-bold"><?= $pass_rate ?>%</td>
                            <td class="text-center"><?= $ea['avg_pct'] ?>%</td>
                            <td class="text-center text-success"><?= $ea['highest_pct'] ?>%</td>
                            <td class="text-center text-danger"><?= $ea['lowest_pct'] ?>%</td>
                            <td class="text-center"><?= $ea['std_dev'] ?? 'N/A' ?></td>
                            <td class="text-center" style="min-width: 120px;">
                                <?php if ($grades_total > 0): ?>
                                <div style="display:flex; height:20px; border-radius:3px; overflow:hidden; width:100px;" title="A:<?= $ea['grade_a'] ?> B:<?= $ea['grade_b'] ?> C:<?= $ea['grade_c'] ?> D:<?= $ea['grade_d'] ?> F:<?= $ea['grade_f'] ?>">
                                    <?php if ($ea['grade_a']): ?><div style="width:<?= ($ea['grade_a']/$grades_total)*100 ?>%; background:#198754;"></div><?php endif; ?>
                                    <?php if ($ea['grade_b']): ?><div style="width:<?= ($ea['grade_b']/$grades_total)*100 ?>%; background:#0d6efd;"></div><?php endif; ?>
                                    <?php if ($ea['grade_c']): ?><div style="width:<?= ($ea['grade_c']/$grades_total)*100 ?>%; background:#6f42c1;"></div><?php endif; ?>
                                    <?php if ($ea['grade_d']): ?><div style="width:<?= ($ea['grade_d']/$grades_total)*100 ?>%; background:#fd7e14;"></div><?php endif; ?>
                                    <?php if ($ea['grade_f']): ?><div style="width:<?= ($ea['grade_f']/$grades_total)*100 ?>%; background:#dc3545;"></div><?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="generate_exam_report.php?report_type=student_list&exam_id=<?= $ea['exam_id'] ?>" target="_blank" class="btn btn-outline-primary" title="Student List PDF">
                                        <i class="bi bi-file-pdf"></i>
                                    </a>
                                    <a href="generate_exam_report.php?report_type=student_list&exam_id=<?= $ea['exam_id'] ?>&format=csv" class="btn btn-outline-success" title="Export CSV">
                                        <i class="bi bi-filetype-csv"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Department Performance (NCHE) -->
    <?php if (!empty($dept_analysis)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title fw-bold mb-0"><i class="bi bi-building me-2"></i>Department Performance Summary</h5>
                <small class="text-muted">NCHE Standard - Aggregated departmental examination statistics</small>
            </div>
            <a href="generate_exam_report.php?report_type=department_performance&format=pdf" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-pdf me-1"></i>Download PDF
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover analysis-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Department / Programme</th>
                            <th class="text-center">Examinations</th>
                            <th class="text-center">Candidates</th>
                            <th class="text-center">Passed</th>
                            <th class="text-center">Failed</th>
                            <th class="text-center">Pass Rate</th>
                            <th class="text-center">Mean Score</th>
                            <th class="text-center">NCHE Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dept_analysis as $i => $da):
                            $failed = $da['total_candidates'] - $da['passed'];
                            $nche_ok = $da['pass_rate'] >= 70;
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($da['department_name']) ?></strong></td>
                            <td class="text-center"><?= $da['total_exams'] ?></td>
                            <td class="text-center"><?= $da['total_candidates'] ?></td>
                            <td class="text-center text-success fw-bold"><?= $da['passed'] ?></td>
                            <td class="text-center text-danger fw-bold"><?= $failed ?></td>
                            <td class="text-center">
                                <span class="<?= $nche_ok ? 'text-success' : 'text-danger' ?> fw-bold"><?= $da['pass_rate'] ?>%</span>
                            </td>
                            <td class="text-center"><?= $da['avg_pct'] ?>%</td>
                            <td class="text-center">
                                <?php if ($nche_ok): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Compliant</span>
                                <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Needs Review</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- NCHE Compliance Legend -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h6 class="fw-bold"><i class="bi bi-info-circle me-2 text-primary"></i>NCHE Compliance Standards Reference</h6>
            <div class="row mt-3">
                <div class="col-md-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="performance-indicator pi-excellent"></span>
                        <small><strong>Excellent</strong> (Pass Rate ≥ 80%)</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="performance-indicator pi-good"></span>
                        <small><strong>Good</strong> (Pass Rate 70-79%)</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="performance-indicator pi-average"></span>
                        <small><strong>Needs Improvement</strong> (Pass Rate 50-69%)</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="performance-indicator pi-poor"></span>
                        <small><strong>Critical</strong> (Pass Rate &lt; 50%)</small>
                    </div>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted"><strong>Grading Scale:</strong> A (75-100%) Distinction | B (65-74%) Credit | C (50-64%) Pass | D (40-49%) Supplementary | F (0-39%) Fail</small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted"><strong>NCHE Minimum Pass Rate Threshold:</strong> 70% institutional pass rate recommended</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Report type card selection
document.querySelectorAll('.report-type-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.report-type-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type=radio]').checked = true;
    });
});

// Initialize selected state
const checkedRadio = document.querySelector('.report-type-card input:checked');
if (checkedRadio) checkedRadio.closest('.report-type-card').classList.add('selected');
</script>
</body>
</html>
