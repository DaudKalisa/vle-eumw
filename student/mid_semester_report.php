<?php
/**
 * Student Mid-Semester Report
 * Generates academic report with mid-semester components:
 * - Assignment 1 (Week 4): 10%
 * - Mid Semester Exam (Week 8): 20%
 * Total weight: 30% of full semester
 * Access requires: 50% fee payment + mid-term results published
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$user = getCurrentUser();
$student_id = $_SESSION['vle_related_id'] ?? '';

// Get student details
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) die('Student record not found.');

// --- Payment & Results Access Check ---
$access_blocked = false;
$block_reasons = [];

// Check payment: must have at least 50%
$fin_stmt = $conn->prepare("SELECT total_paid, expected_total, balance, payment_percentage FROM student_finances WHERE student_id = ?");
$fin_stmt->bind_param("s", $student_id);
$fin_stmt->execute();
$finance = $fin_stmt->get_result()->fetch_assoc();
$payment_pct = (int)($finance['payment_percentage'] ?? 0);
$balance = (float)($finance['balance'] ?? ($finance['expected_total'] ?? 1));

if (!$finance || $payment_pct < 50) {
    $access_blocked = true;
    $block_reasons[] = 'insufficient_payment';
}

// Check results published: at least one mid_term exam must have results_published = 1
$pub_stmt = $conn->prepare("
    SELECT COUNT(*) as published_count
    FROM exams e
    JOIN vle_enrollments en ON e.course_id = en.course_id
    WHERE en.student_id = ? AND e.exam_type = 'mid_term' AND e.results_published = 1
");
$pub_stmt->bind_param("s", $student_id);
$pub_stmt->execute();
$pub_result = $pub_stmt->get_result()->fetch_assoc();
if (!$pub_result || (int)$pub_result['published_count'] === 0) {
    $access_blocked = true;
    $block_reasons[] = 'results_not_published';
}

if ($access_blocked) {
    $page_title = 'Mid-Semester Report - Access Restricted';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mid-Semester Report - Access Restricted</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    </head>
    <body>
        <?php include 'header_nav.php'; ?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <div class="mb-4">
                                <i class="bi bi-lock-fill text-warning" style="font-size: 4rem;"></i>
                            </div>
                            <h3 class="mb-3">Mid-Semester Report Not Available</h3>
                            <p class="text-muted mb-4">The mid-semester report requires the following conditions to be met:</p>
                            <div class="text-start d-inline-block mb-4">
                                <div class="d-flex align-items-center mb-3">
                                    <?php if (in_array('insufficient_payment', $block_reasons)): ?>
                                        <i class="bi bi-x-circle-fill text-danger me-2 fs-5"></i>
                                        <span><strong>50% fee payment</strong> &mdash; You have paid <?= $payment_pct ?>%. At least 50% payment is required.</span>
                                    <?php else: ?>
                                        <i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>
                                        <span><strong>50% fee payment</strong> &mdash; Completed (<?= $payment_pct ?>%)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center mb-3">
                                    <?php if (in_array('results_not_published', $block_reasons)): ?>
                                        <i class="bi bi-x-circle-fill text-danger me-2 fs-5"></i>
                                        <span><strong>Mid-term results published</strong> &mdash; Results have not yet been published.</span>
                                    <?php else: ?>
                                        <i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>
                                        <span><strong>Mid-term results published</strong> &mdash; Available</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="payment_history.php" class="btn btn-outline-secondary me-2"><i class="bi bi-credit-card me-1"></i>Payment History</a>
                                <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Resolve program/department name
$program_name = $student['program'] ?? '';
if (empty($program_name) || is_numeric($program_name)) {
    $dept_id = !empty($student['department']) ? $student['department'] : $program_name;
    if ($dept_id) {
        $d = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
        $d->bind_param("i", $dept_id);
        $d->execute();
        $dr = $d->get_result()->fetch_assoc();
        $program_name = $dr ? $dr['department_name'] : 'Not Assigned';
    }
}

// Get filter parameters
$semester_filter = $_GET['semester'] ?? '';
$course_filter = (int)($_GET['course_id'] ?? 0);
$year_filter = $_GET['year'] ?? date('Y');

// Mid-semester grade weights (only weeks 4 and 8)
$grade_weights = [
    4 => ['name' => 'Assignment 1', 'weight' => 10, 'type' => 'assignment'],
    8 => ['name' => 'Mid Semester Exam', 'weight' => 20, 'type' => 'exam']
];

// Get enrolled courses
$courses_sql = "
    SELECT DISTINCT c.course_id, c.course_code, c.course_name, c.semester, c.year_of_study,
           l.full_name as lecturer_name
    FROM vle_enrollments e
    JOIN vle_courses c ON e.course_id = c.course_id
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    WHERE e.student_id = ?
";
$params = [$student_id];
$types = "s";

if ($semester_filter) {
    $courses_sql .= " AND c.semester = ?";
    $params[] = $semester_filter;
    $types .= "s";
}
if ($course_filter) {
    $courses_sql .= " AND c.course_id = ?";
    $params[] = $course_filter;
    $types .= "i";
}

$courses_sql .= " ORDER BY c.course_name";
$stmt = $conn->prepare($courses_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($courses)) {
    $page_title = 'Mid-Semester Report';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mid-Semester Report - VLE</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    </head>
    <body>
        <?php include 'header_nav.php'; ?>
        <div class="container py-5">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                You are not enrolled in any courses. Please register for courses first.
            </div>
            <a href="register_courses.php" class="btn btn-primary">Register Courses</a>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Build report data for each course
$report_data = [];
$overall_stats = ['total_weighted' => 0, 'courses_complete' => 0, 'total_courses' => count($courses)];

foreach ($courses as $course) {
    $course_id = $course['course_id'];
    $course_data = [
        'course' => $course,
        'components' => [],
        'mid_total' => 0,
        'weighted_total' => 0,
        'components_graded' => 0
    ];

    // Get assignment submissions for week 4 only
    $stmt = $conn->prepare("
        SELECT vs.score, va.week_number, va.title, va.max_score, vs.graded_date
        FROM vle_submissions vs
        JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
        WHERE vs.student_id = ? AND va.course_id = ? AND vs.score IS NOT NULL
        AND va.week_number = 4
        ORDER BY va.week_number
    ");
    $stmt->bind_param("si", $student_id, $course_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($assignments as $asn) {
        $week = $asn['week_number'];
        if (isset($grade_weights[$week])) {
            $percentage = $asn['max_score'] > 0 ? ($asn['score'] / $asn['max_score']) * 100 : 0;
            $weighted = ($percentage * $grade_weights[$week]['weight']) / 100;
            $course_data['components'][$week] = [
                'name' => $grade_weights[$week]['name'],
                'weight' => $grade_weights[$week]['weight'],
                'raw_score' => $asn['score'],
                'max_score' => $asn['max_score'],
                'percentage' => round($percentage, 1),
                'weighted_score' => round($weighted, 2),
                'date' => $asn['graded_date'],
                'status' => 'graded'
            ];
            $course_data['weighted_total'] += $weighted;
            $course_data['components_graded']++;
        }
    }

    // Get mid-term exam results only
    $stmt = $conn->prepare("
        SELECT er.percentage, er.score, er.grade, er.is_passed, er.submitted_at,
               e.exam_type, e.total_marks, e.exam_name
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        WHERE er.student_id = ? AND e.course_id = ? AND e.results_published = 1
        AND e.exam_type = 'mid_term'
    ");
    $stmt->bind_param("si", $student_id, $course_id);
    $stmt->execute();
    $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($exams as $exam) {
        $week = 8;
        $weighted = ($exam['percentage'] * $grade_weights[$week]['weight']) / 100;
        $course_data['components'][$week] = [
            'name' => $grade_weights[$week]['name'],
            'weight' => $grade_weights[$week]['weight'],
            'raw_score' => $exam['score'],
            'max_score' => $exam['total_marks'],
            'percentage' => round($exam['percentage'], 1),
            'weighted_score' => round($weighted, 2),
            'date' => $exam['submitted_at'],
            'status' => 'graded',
            'grade' => $exam['grade'],
            'passed' => $exam['is_passed']
        ];
        $course_data['weighted_total'] += $weighted;
        $course_data['components_graded']++;
    }

    // Fill in missing components
    foreach ($grade_weights as $week => $info) {
        if (!isset($course_data['components'][$week])) {
            $course_data['components'][$week] = [
                'name' => $info['name'],
                'weight' => $info['weight'],
                'raw_score' => null,
                'max_score' => 100,
                'percentage' => 0,
                'weighted_score' => 0,
                'date' => null,
                'status' => 'pending'
            ];
        }
    }

    ksort($course_data['components']);
    $course_data['mid_total'] = round($course_data['weighted_total'], 1);

    // Mid-semester performance indicator (scaled to 30%)
    $max_possible = 30; // 10% + 20%
    $scaled_pct = $max_possible > 0 ? round(($course_data['weighted_total'] / $max_possible) * 100, 1) : 0;
    $course_data['scaled_percentage'] = $scaled_pct;

    if ($scaled_pct >= 75) $course_data['performance'] = 'Excellent';
    elseif ($scaled_pct >= 65) $course_data['performance'] = 'Good';
    elseif ($scaled_pct >= 50) $course_data['performance'] = 'Satisfactory';
    elseif ($scaled_pct >= 40) $course_data['performance'] = 'Needs Improvement';
    else $course_data['performance'] = 'At Risk';

    $report_data[] = $course_data;

    if ($course_data['components_graded'] >= 2) {
        $overall_stats['total_weighted'] += $course_data['weighted_total'];
        $overall_stats['courses_complete']++;
    }
}

$overall_avg = $overall_stats['courses_complete'] > 0
    ? $overall_stats['total_weighted'] / $overall_stats['courses_complete']
    : 0;
$overall_scaled = $overall_stats['courses_complete'] > 0
    ? round(($overall_avg / 30) * 100, 1)
    : 0;

// Logo as base64
$logo_path = realpath('../assets/img/Logo.png');
$logo_base64 = '';
if ($logo_path && file_exists($logo_path)) {
    $logo_base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$today = date('F d, Y');
$academic_year = date('Y') . '/' . (date('Y') + 1);
$student_year = $student['year_of_study'] ?? 1;
$semester_label = $semester_filter ?: 'All Semesters';
$report_ref = 'MSR-' . strtoupper(substr($student_id, -4)) . '-' . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mid-Semester Report - <?= htmlspecialchars($student['full_name'] ?? $student_id) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
        }
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f5f7fa; }

        .print-controls {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 25px;
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .print-controls h4 { margin: 0; font-size: 1.1rem; }
        .print-controls .btn-group { display: flex; gap: 10px; }
        .print-controls button, .print-controls a {
            background: white;
            color: #0d6efd;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        .print-controls button:hover, .print-controls a:hover {
            background: #e0e7ff;
            transform: translateY(-1px);
        }

        .report-container {
            background: white;
            max-width: 900px;
            margin: 20px auto;
            padding: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .report-header {
            background: var(--primary-gradient);
            color: white;
            padding: 25px 30px;
            text-align: center;
        }
        .report-header img { height: 70px; margin-bottom: 10px; }
        .report-header h1 { font-size: 1.8rem; margin: 5px 0; font-weight: 700; }
        .report-header h2 { font-size: 1.2rem; margin: 5px 0; font-weight: 400; opacity: 0.9; }
        .report-header .subtitle { font-size: 0.9rem; opacity: 0.8; margin-top: 5px; }
        .report-header .badge-mid { background: rgba(255,255,255,0.25); padding: 4px 14px; border-radius: 20px; font-size: 0.85rem; display: inline-block; margin-top: 8px; }

        .student-info {
            background: #f8f9fa;
            padding: 20px 30px;
            border-bottom: 2px solid #e9ecef;
        }
        .student-info .info-item { margin-bottom: 8px; }
        .student-info .label { color: #6c757d; font-size: 0.85rem; }
        .student-info .value { font-weight: 600; color: #0d6efd; }

        .grading-info {
            background: #cfe2ff;
            padding: 15px 30px;
            border-bottom: 2px solid #9ec5fe;
        }
        .grading-info h5 { color: #084298; margin-bottom: 10px; font-size: 0.95rem; }
        .grading-info .weight-item {
            display: inline-block;
            background: white;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 3px 5px 3px 0;
            font-size: 0.85rem;
            border: 1px solid #9ec5fe;
        }

        .course-section {
            padding: 25px 30px;
            border-bottom: 1px solid #e9ecef;
        }
        .course-section:last-of-type { border-bottom: none; }
        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0d6efd;
        }
        .course-header h3 { margin: 0; font-size: 1.1rem; color: #0d6efd; }
        .course-header .course-code {
            background: #0d6efd;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .components-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .components-table th, .components-table td {
            padding: 10px 12px;
            text-align: left;
            border: 1px solid #dee2e6;
        }
        .components-table th {
            background: #e9ecef;
            font-weight: 600;
            font-size: 0.85rem;
            color: #495057;
        }
        .components-table td { font-size: 0.9rem; }
        .components-table .score-cell { text-align: center; }
        .components-table .graded { background: #d4edda; }
        .components-table .pending { background: #fff3cd; color: #856404; }

        .course-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 6px;
        }
        .course-summary .mid-score { font-size: 1.3rem; font-weight: 700; }
        .perf-excellent { color: #28a745; }
        .perf-good { color: #17a2b8; }
        .perf-satisfactory { color: #ffc107; }
        .perf-needs-improvement { color: #fd7e14; }
        .perf-at-risk { color: #dc3545; }
        .performance-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .perf-bg-excellent { background: #d4edda; color: #155724; }
        .perf-bg-good { background: #d1ecf1; color: #0c5460; }
        .perf-bg-satisfactory { background: #fff3cd; color: #856404; }
        .perf-bg-needs-improvement { background: #ffe5d0; color: #854d0e; }
        .perf-bg-at-risk { background: #f8d7da; color: #721c24; }

        .overall-summary {
            background: var(--primary-gradient);
            color: white;
            padding: 25px 30px;
        }
        .overall-summary h4 { margin: 0 0 15px; font-size: 1.1rem; }
        .overall-summary .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        .overall-summary .summary-item {
            background: rgba(255,255,255,0.15);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .overall-summary .summary-item .value { font-size: 1.8rem; font-weight: 700; }
        .overall-summary .summary-item .label { font-size: 0.85rem; opacity: 0.9; }

        .report-footer {
            padding: 20px 30px;
            text-align: center;
            border-top: 2px solid #e9ecef;
            font-size: 0.85rem;
            color: #6c757d;
        }
        .report-footer .ref { font-family: monospace; color: #0d6efd; }

        @media print {
            body { background: white; }
            .print-controls { display: none !important; }
            .report-container { box-shadow: none; margin: 0; max-width: none; border-radius: 0; }
            .course-section { page-break-inside: avoid; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>
    <?php if (!isset($_GET['print'])): ?>
    <div class="print-controls">
        <h4><i class="bi bi-file-earmark-text me-2"></i>Mid-Semester Academic Report</h4>
        <div class="btn-group">
            <button onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
            <a href="semester_report.php"><i class="bi bi-file-earmark-bar-graph"></i> Full Report</a>
            <a href="dashboard.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="report-container">
        <div class="report-header">
            <?php if ($logo_base64): ?>
            <img src="<?= $logo_base64 ?>" alt="University Logo">
            <?php endif; ?>
            <h1>EXPLOITS UNIVERSITY</h1>
            <h2><?= htmlspecialchars($student['campus'] ?? 'Mzuzu Campus') ?></h2>
            <div class="subtitle">MID-SEMESTER ACADEMIC REPORT</div>
            <div class="badge-mid"><i class="bi bi-clock-history me-1"></i>Progress Report &mdash; Weeks 1-8</div>
        </div>

        <div class="student-info">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-item">
                        <span class="label">Student Name:</span>
                        <span class="value"><?= htmlspecialchars($student['full_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Student ID:</span>
                        <span class="value"><?= htmlspecialchars($student_id) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Programme:</span>
                        <span class="value"><?= htmlspecialchars($program_name) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Campus:</span>
                        <span class="value"><?= htmlspecialchars($student['campus'] ?? 'Mzuzu Campus') ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-item">
                        <span class="label">Year of Study:</span>
                        <span class="value">Year <?= $student_year ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Academic Year:</span>
                        <span class="value"><?= $academic_year ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Report Type:</span>
                        <span class="value">Mid-Semester (Weeks 1-8)</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Report Date:</span>
                        <span class="value"><?= $today ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grading-info">
            <h5><i class="bi bi-info-circle me-1"></i> Mid-Semester Assessment Components</h5>
            <span class="weight-item"><strong>Assignment 1 (Week 4):</strong> 10%</span>
            <span class="weight-item"><strong>Mid Semester Exam (Week 8):</strong> 20%</span>
            <span class="weight-item"><strong>Total Mid-Semester Weight:</strong> 30% of final grade</span>
        </div>

        <?php foreach ($report_data as $data): ?>
        <div class="course-section">
            <div class="course-header">
                <h3><?= htmlspecialchars($data['course']['course_name']) ?></h3>
                <span class="course-code"><?= htmlspecialchars($data['course']['course_code']) ?></span>
            </div>

            <?php if ($data['course']['lecturer_name']): ?>
            <p class="text-muted mb-3" style="font-size: 0.9rem;">
                <i class="bi bi-person me-1"></i>Lecturer: <?= htmlspecialchars($data['course']['lecturer_name']) ?>
            </p>
            <?php endif; ?>

            <table class="components-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Assessment Component</th>
                        <th style="width: 12%;" class="score-cell">Weight</th>
                        <th style="width: 15%;" class="score-cell">Score</th>
                        <th style="width: 15%;" class="score-cell">Percentage</th>
                        <th style="width: 15%;" class="score-cell">Weighted Score</th>
                        <th style="width: 13%;" class="score-cell">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['components'] as $week => $comp): ?>
                    <tr class="<?= $comp['status'] ?>">
                        <td><strong><?= $comp['name'] ?></strong></td>
                        <td class="score-cell"><?= $comp['weight'] ?>%</td>
                        <td class="score-cell">
                            <?php if ($comp['status'] === 'graded'): ?>
                                <?= $comp['raw_score'] ?>/<?= $comp['max_score'] ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="score-cell">
                            <?php if ($comp['status'] === 'graded'): ?>
                                <?= $comp['percentage'] ?>%
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="score-cell">
                            <?php if ($comp['status'] === 'graded'): ?>
                                <strong><?= $comp['weighted_score'] ?></strong>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="score-cell">
                            <?php if ($comp['status'] === 'graded'): ?>
                                <span style="color: #28a745;"><i class="bi bi-check-circle"></i> Graded</span>
                            <?php else: ?>
                                <span style="color: #ffc107;"><i class="bi bi-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $perf_class = strtolower(str_replace(' ', '-', $data['performance']));
            ?>
            <div class="course-summary">
                <div>
                    <span class="text-muted">Mid-Semester Score:</span>
                    <span class="mid-score perf-<?= $perf_class ?>"><?= $data['mid_total'] ?>/30</span>
                    <span class="ms-2 text-muted">(<?= $data['scaled_percentage'] ?>%)</span>
                </div>
                <span class="performance-badge perf-bg-<?= $perf_class ?>">
                    <?= $data['performance'] ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="overall-summary">
            <h4><i class="bi bi-bar-chart me-2"></i>Mid-Semester Summary</h4>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="value"><?= count($report_data) ?></div>
                    <div class="label">Total Courses</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?= number_format($overall_avg, 1) ?>/30</div>
                    <div class="label">Avg Mid-Semester Score</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?= $overall_scaled ?>%</div>
                    <div class="label">Overall Performance</div>
                </div>
            </div>
        </div>

        <div class="report-footer">
            <p><strong>EXPLOITS UNIVERSITY - Virtual Learning Environment</strong></p>
            <p>This is an official mid-semester progress report. Final grades will be available in the full semester report after end-of-semester examinations.</p>
            <p>Reference: <span class="ref"><?= $report_ref ?></span></p>
            <p style="font-size: 0.8rem;">Generated on <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
