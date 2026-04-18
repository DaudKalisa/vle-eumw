<?php
/**
 * Examination Officer - Semester Reports
 * Generate comprehensive student academic reports with weighted grades:
 * - Assignment 1: 10%
 * - Mid Semester Exam: 20%
 * - Assignment 2: 10%
 * - End Semester Exam: 60%
 * Uses browser-based PDF printing
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get search/filter parameters
$search_term = $_GET['search'] ?? '';
$student_id = $_GET['student_id'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$department_filter = (int)($_GET['department_id'] ?? 0);
$generate_report = isset($_GET['generate']);

// Grade weights configuration
$grade_weights = [
    4 => ['name' => 'Assignment 1', 'weight' => 10, 'type' => 'assignment'],
    8 => ['name' => 'Mid Semester Exam', 'weight' => 20, 'type' => 'exam'],
    12 => ['name' => 'Assignment 2', 'weight' => 10, 'type' => 'assignment'],
    16 => ['name' => 'End Semester Exam', 'weight' => 60, 'type' => 'exam']
];

// Get departments for filter
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// If generating report for a specific student
if ($generate_report && $student_id) {
    // Get student details
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if (!$student) {
        die('Student not found.');
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
    
    // Get enrolled courses for the student
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
    
    $courses_sql .= " ORDER BY c.course_name";
    $stmt = $conn->prepare($courses_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Build report data for each course
    $report_data = [];
    $overall_stats = ['total_weighted' => 0, 'courses_complete' => 0, 'total_courses' => count($courses)];
    
    foreach ($courses as $course) {
        $course_id = $course['course_id'];
        $course_data = [
            'course' => $course,
            'components' => [],
            'final_grade' => 0,
            'weighted_total' => 0,
            'components_graded' => 0
        ];
        
        // Get assignment submissions for this course (weeks 4 and 12)
        $stmt = $conn->prepare("
            SELECT vs.score, va.week_number, va.title, va.max_score, vs.graded_date
            FROM vle_submissions vs
            JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
            WHERE vs.student_id = ? AND va.course_id = ? AND vs.score IS NOT NULL
            AND va.week_number IN (4, 12)
            ORDER BY va.week_number
        ");
        $stmt->bind_param("si", $student_id, $course_id);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Map assignments to grade weights
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
        
        // Get exam results for this course (mid-term and final)
        $stmt = $conn->prepare("
            SELECT er.percentage, er.score, er.grade, er.is_passed, er.submitted_at,
                   e.exam_type, e.total_marks, e.exam_name
            FROM exam_results er
            JOIN exams e ON er.exam_id = e.exam_id
            WHERE er.student_id = ? AND e.course_id = ? AND e.results_published = 1
            ORDER BY e.exam_type
        ");
        $stmt->bind_param("si", $student_id, $course_id);
        $stmt->execute();
        $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($exams as $exam) {
            $week = $exam['exam_type'] === 'mid_term' ? 8 : ($exam['exam_type'] === 'final' ? 16 : null);
            if ($week && isset($grade_weights[$week])) {
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
        
        // Sort components by week
        ksort($course_data['components']);
        
        // Calculate final grade
        $course_data['final_grade'] = round($course_data['weighted_total'], 1);
        
        // Determine letter grade
        if ($course_data['final_grade'] >= 75) $course_data['letter_grade'] = 'A';
        elseif ($course_data['final_grade'] >= 65) $course_data['letter_grade'] = 'B';
        elseif ($course_data['final_grade'] >= 50) $course_data['letter_grade'] = 'C';
        elseif ($course_data['final_grade'] >= 40) $course_data['letter_grade'] = 'D';
        else $course_data['letter_grade'] = 'F';
        
        // GPA points
        $gpa_map = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, 'F' => 0.0];
        $course_data['gpa_points'] = $gpa_map[$course_data['letter_grade']];
        
        // Status
        $course_data['status'] = $course_data['final_grade'] >= 50 ? 'Passed' : 'Failed';
        if ($course_data['components_graded'] < 4) {
            $course_data['status'] = 'In Progress';
        }
        
        $report_data[] = $course_data;
        
        // Update overall stats
        if ($course_data['components_graded'] >= 4) {
            $overall_stats['total_weighted'] += $course_data['final_grade'];
            $overall_stats['courses_complete']++;
        }
    }
    
    // Calculate overall GPA
    $overall_gpa = $overall_stats['courses_complete'] > 0 
        ? array_sum(array_column(array_filter($report_data, fn($c) => $c['components_graded'] >= 4), 'gpa_points')) / $overall_stats['courses_complete']
        : 0;
    $overall_avg = $overall_stats['courses_complete'] > 0 
        ? $overall_stats['total_weighted'] / $overall_stats['courses_complete']
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
    $report_ref = 'SR-' . strtoupper(substr($student_id, -4)) . '-' . date('Ymd-His');
    $generated_by = $user['display_name'] ?? 'Examination Officer';
    
    // Grade legend
    $grade_legend = [
        'A' => ['range' => '75-100%', 'desc' => 'Distinction', 'gpa' => '4.0'],
        'B' => ['range' => '65-74%', 'desc' => 'Credit', 'gpa' => '3.0'],
        'C' => ['range' => '50-64%', 'desc' => 'Pass', 'gpa' => '2.0'],
        'D' => ['range' => '40-49%', 'desc' => 'Supplementary', 'gpa' => '1.0'],
        'F' => ['range' => '0-39%', 'desc' => 'Fail', 'gpa' => '0.0'],
    ];
    
    // Output the report
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Report - <?= htmlspecialchars($student['full_name'] ?? $student_id) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); }
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
            color: #1e3c72;
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
        .print-controls button:hover, .print-controls a:hover { background: #e0e7ff; transform: translateY(-1px); }
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
        .student-info {
            background: #f8f9fa;
            padding: 20px 30px;
            border-bottom: 2px solid #e9ecef;
        }
        .student-info .row { margin: 0; }
        .student-info .info-item { margin-bottom: 8px; }
        .student-info .label { color: #6c757d; font-size: 0.85rem; }
        .student-info .value { font-weight: 600; color: #1e3c72; }
        .grading-info {
            background: #fff3cd;
            padding: 15px 30px;
            border-bottom: 2px solid #ffc107;
        }
        .grading-info h5 { color: #856404; margin-bottom: 10px; font-size: 0.95rem; }
        .grading-info .weight-item {
            display: inline-block;
            background: white;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 3px 5px 3px 0;
            font-size: 0.85rem;
            border: 1px solid #ffc107;
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
            border-bottom: 2px solid #1e3c72;
        }
        .course-header h3 { margin: 0; font-size: 1.1rem; color: #1e3c72; }
        .course-header .course-code { 
            background: #1e3c72;
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
        .course-summary .final-grade { font-size: 1.5rem; font-weight: 700; }
        .course-summary .grade-A { color: #28a745; }
        .course-summary .grade-B { color: #17a2b8; }
        .course-summary .grade-C { color: #ffc107; }
        .course-summary .grade-D { color: #fd7e14; }
        .course-summary .grade-F { color: #dc3545; }
        .course-summary .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .course-summary .status-passed { background: #d4edda; color: #155724; }
        .course-summary .status-failed { background: #f8d7da; color: #721c24; }
        .course-summary .status-inprogress { background: #fff3cd; color: #856404; }
        .overall-summary {
            background: var(--primary-gradient);
            color: white;
            padding: 25px 30px;
        }
        .overall-summary h4 { margin: 0 0 15px; font-size: 1.1rem; }
        .overall-summary .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
        .grade-legend {
            padding: 20px 30px;
            background: #f8f9fa;
        }
        .grade-legend h5 { margin-bottom: 10px; font-size: 0.95rem; color: #495057; }
        .grade-legend table { width: 100%; font-size: 0.85rem; }
        .grade-legend th, .grade-legend td { padding: 6px 10px; border: 1px solid #dee2e6; }
        .grade-legend th { background: #e9ecef; }
        .report-footer {
            padding: 20px 30px;
            text-align: center;
            border-top: 2px solid #e9ecef;
            font-size: 0.85rem;
            color: #6c757d;
        }
        .report-footer .ref { font-family: monospace; color: #1e3c72; }
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
    <div class="print-controls">
        <h4><i class="bi bi-file-earmark-text me-2"></i>Student Semester Report</h4>
        <div class="btn-group">
            <button onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
            <a href="semester_reports.php"><i class="bi bi-arrow-left"></i> Back to Search</a>
        </div>
    </div>
    
    <div class="report-container">
        <div class="report-header">
            <?php if ($logo_base64): ?>
            <img src="<?= $logo_base64 ?>" alt="University Logo">
            <?php endif; ?>
            <h1>EXPLOITS UNIVERSITY</h1>
            <h2>Malawi Campus</h2>
            <div class="subtitle">SEMESTER ACADEMIC REPORT</div>
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
                        <span class="label">Report Date:</span>
                        <span class="value"><?= $today ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="grading-info">
            <h5><i class="bi bi-info-circle me-1"></i> Assessment Weight Distribution</h5>
            <span class="weight-item"><strong>Assignment 1:</strong> 10%</span>
            <span class="weight-item"><strong>Mid Semester Exam:</strong> 20%</span>
            <span class="weight-item"><strong>Assignment 2:</strong> 10%</span>
            <span class="weight-item"><strong>End Semester Exam:</strong> 60%</span>
        </div>
        
        <?php if (empty($report_data)): ?>
        <div class="course-section">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                This student is not enrolled in any courses<?= $semester_filter ? " for semester {$semester_filter}" : '' ?>.
            </div>
        </div>
        <?php else: ?>
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
            
            <div class="course-summary">
                <div>
                    <span class="text-muted">Final Weighted Score:</span>
                    <span class="final-grade grade-<?= $data['letter_grade'] ?>"><?= $data['final_grade'] ?>%</span>
                    <span class="ms-2 badge bg-secondary"><?= $data['letter_grade'] ?></span>
                    <span class="ms-2 text-muted">(GPA: <?= number_format($data['gpa_points'], 1) ?>)</span>
                </div>
                <span class="status-badge status-<?= strtolower(str_replace(' ', '', $data['status'])) ?>">
                    <?= $data['status'] ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($report_data)): ?>
        <div class="overall-summary">
            <h4><i class="bi bi-bar-chart me-2"></i>Semester Summary</h4>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="value"><?= count($report_data) ?></div>
                    <div class="label">Total Courses</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?= $overall_stats['courses_complete'] ?></div>
                    <div class="label">Completed</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?= number_format($overall_avg, 1) ?>%</div>
                    <div class="label">Average Score</div>
                </div>
                <div class="summary-item">
                    <div class="value"><?= number_format($overall_gpa, 2) ?></div>
                    <div class="label">Semester GPA</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="grade-legend">
            <h5><i class="bi bi-list-ul me-1"></i> Grading Scale</h5>
            <table>
                <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Range</th>
                        <th>Classification</th>
                        <th>GPA Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grade_legend as $grade => $info): ?>
                    <tr>
                        <td><strong><?= $grade ?></strong></td>
                        <td><?= $info['range'] ?></td>
                        <td><?= $info['desc'] ?></td>
                        <td><?= $info['gpa'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="report-footer">
            <p><strong>EXPLOITS UNIVERSITY - Virtual Learning Environment</strong></p>
            <p>This is an official academic report generated by the Examination Office.</p>
            <p>Generated by: <?= htmlspecialchars($generated_by) ?></p>
            <p>Reference: <span class="ref"><?= $report_ref ?></span></p>
            <p style="font-size: 0.8rem;">Generated on <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
    exit;
}

// Otherwise, show the search/selection interface
$students = [];
if ($search_term || $department_filter) {
    $sql = "SELECT s.student_id, s.full_name, s.email, s.program, s.year_of_study,
                   d.department_name,
                   (SELECT COUNT(*) FROM vle_enrollments e WHERE e.student_id = s.student_id) as courses_enrolled
            FROM students s
            LEFT JOIN departments d ON s.department = d.department_id
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($search_term) {
        $sql .= " AND (s.student_id LIKE ? OR s.full_name LIKE ? OR s.email LIKE ?)";
        $search_like = "%{$search_term}%";
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $types .= "sss";
    }
    if ($department_filter) {
        $sql .= " AND s.department = ?";
        $params[] = $department_filter;
        $types .= "i";
    }
    
    $sql .= " ORDER BY s.full_name LIMIT 50";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$page_title = 'Semester Reports';
$breadcrumbs = [['title' => 'Semester Reports']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Reports - Examination Officer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="vle-content">
        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-12">
                    <h1 class="h3 mb-1"><i class="bi bi-file-earmark-bar-graph me-2"></i>Student Semester Reports</h1>
                    <p class="text-muted">Search for students and generate their comprehensive semester academic reports</p>
                </div>
            </div>
            
            <!-- Search Form -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-search me-2"></i>Search Students</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Search by Name, ID, or Email</label>
                            <input type="text" name="search" class="form-control" placeholder="Enter student name, ID, or email..." value="<?= htmlspecialchars($search_term) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Filter by Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>" <?= $department_filter == $dept['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['department_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Results -->
            <?php if ($search_term || $department_filter): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>Search Results</h5>
                    <span class="badge bg-primary"><?= count($students) ?> student(s) found</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($students)): ?>
                    <div class="alert alert-info m-3">
                        <i class="bi bi-info-circle me-2"></i>No students found matching your criteria.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Year</th>
                                    <th>Courses</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $s): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($s['student_id']) ?></code></td>
                                    <td>
                                        <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></td>
                                    <td>Year <?= $s['year_of_study'] ?? 1 ?></td>
                                    <td><span class="badge bg-secondary"><?= $s['courses_enrolled'] ?></span></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="?student_id=<?= urlencode($s['student_id']) ?>&generate=1" class="btn btn-primary" title="Generate Full Report">
                                                <i class="bi bi-file-earmark-pdf"></i> Full Report
                                            </a>
                                            <a href="?student_id=<?= urlencode($s['student_id']) ?>&semester=One&generate=1" class="btn btn-outline-primary" title="Semester One">
                                                Sem 1
                                            </a>
                                            <a href="?student_id=<?= urlencode($s['student_id']) ?>&semester=Two&generate=1" class="btn btn-outline-primary" title="Semester Two">
                                                Sem 2
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-search display-1 text-muted mb-3"></i>
                    <h4>Search for Students</h4>
                    <p class="text-muted">Enter a student name, ID, or email to search and generate semester reports.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Grade Weighting Info -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-warning bg-opacity-25">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Grade Weighting Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Assessment Components:</h6>
                            <ul>
                                <li><strong>Assignment 1 (Week 4):</strong> 10%</li>
                                <li><strong>Mid Semester Exam (Week 8):</strong> 20%</li>
                                <li><strong>Assignment 2 (Week 12):</strong> 10%</li>
                                <li><strong>End Semester Exam (Week 16):</strong> 60%</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Grading Scale:</h6>
                            <ul>
                                <li><strong>A (Distinction):</strong> 75-100%</li>
                                <li><strong>B (Credit):</strong> 65-74%</li>
                                <li><strong>C (Pass):</strong> 50-64%</li>
                                <li><strong>D (Supplementary):</strong> 40-49%</li>
                                <li><strong>F (Fail):</strong> 0-39%</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
