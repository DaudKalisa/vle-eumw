<?php
/**
 * Admin Portal - Student Reports
 * Printable / Export to PDF student report
 * Based on dean/students.php template with print & export features
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);

$conn = getDbConnection();

// Detect program column name in students table
$program_col = 'program';
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
if ($col_check && $col_check->num_rows > 0) {
    $program_col = 'program_of_study';
}

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_campus = $_GET['campus'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query
$where = ["1=1"];
$params = [];
$types = "";

if ($filter_program) {
    $where[] = "s.$program_col = ?";
    $params[] = $filter_program;
    $types .= "s";
}

if ($filter_year) {
    $where[] = "s.year_of_study = ?";
    $params[] = $filter_year;
    $types .= "i";
}

if ($filter_status === 'active') {
    $where[] = "s.is_active = 1";
} elseif ($filter_status === 'inactive') {
    $where[] = "s.is_active = 0";
}

if ($filter_campus) {
    $where[] = "s.campus = ?";
    $params[] = $filter_campus;
    $types .= "s";
}

if ($filter_search) {
    $where[] = "(s.full_name LIKE ? OR s.email LIKE ? OR s.student_id LIKE ?)";
    $search = "%$filter_search%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Count total
$count_sql = "SELECT COUNT(*) as total FROM students s $where_sql";
if (!empty($params)) {
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total / $per_page);

// Get students
$sql = "SELECT s.*, p.program_name, p.program_code, d.department_name
        FROM students s
        LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
        LEFT JOIN departments d ON s.department = d.department_id
        $where_sql
        ORDER BY s.full_name
        LIMIT $per_page OFFSET $offset";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$students = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// For print-all mode, get ALL students matching filter (no pagination)
$print_all = isset($_GET['print_all']);
if ($print_all) {
    $sql_all = "SELECT s.*, p.program_name, p.program_code, d.department_name
                FROM students s
                LEFT JOIN programs p ON s.$program_col = p.program_id OR s.$program_col = p.program_code OR s.$program_col = p.program_name
                LEFT JOIN departments d ON s.department = d.department_id
                $where_sql
                ORDER BY s.full_name";
    if (!empty($params)) {
        $stmt = $conn->prepare($sql_all);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result_all = $stmt->get_result();
    } else {
        $result_all = $conn->query($sql_all);
    }
    $students = [];
    while ($row = $result_all->fetch_assoc()) {
        $students[] = $row;
    }
}

// Get programs for filter
$programs = [];
$prog_result = $conn->query("SELECT program_id, program_code, program_name FROM programs ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Get campuses for filter
$campuses = [];
$campus_result = $conn->query("SELECT DISTINCT campus FROM students WHERE campus IS NOT NULL AND campus != '' ORDER BY campus");
if ($campus_result) {
    while ($row = $campus_result->fetch_assoc()) {
        $campuses[] = $row['campus'];
    }
}

// Stats
$stats_result = $conn->query("SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN year_of_study = 1 THEN 1 END) as year1,
    COUNT(CASE WHEN year_of_study = 2 THEN 1 END) as year2,
    COUNT(CASE WHEN year_of_study = 3 THEN 1 END) as year3,
    COUNT(CASE WHEN year_of_study = 4 THEN 1 END) as year4,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count,
    COUNT(CASE WHEN gender = 'Male' THEN 1 END) as male_count,
    COUNT(CASE WHEN gender = 'Female' THEN 1 END) as female_count
    FROM students");
$stats = $stats_result ? $stats_result->fetch_assoc() : ['total' => 0, 'year1' => 0, 'year2' => 0, 'year3' => 0, 'year4' => 0, 'active_count' => 0, 'male_count' => 0, 'female_count' => 0];

// Get university settings for print header
$uni_name = 'Eastern University of Management and Wellbeing';
$uni_settings = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($uni_settings && $row = $uni_settings->fetch_assoc()) {
    $uni_name = $row['university_name'] ?? $uni_name;
}

$page_title = "Student Reports";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        /* Print Styles */
        @media print {
            .no-print, .navbar, .vle-navbar, .filter-card, .pagination, .btn-toolbar, .card-footer {
                display: none !important;
            }
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            .print-header h2 { font-size: 18px; margin-bottom: 2px; }
            .print-header h3 { font-size: 14px; margin-bottom: 2px; }
            .print-header p { font-size: 11px; margin-bottom: 0; }
            body { font-size: 11px; }
            .card { border: none !important; box-shadow: none !important; }
            .card-header { background: none !important; color: #000 !important; border-bottom: 1px solid #000; padding: 5px 0; }
            .table th, .table td { padding: 4px 6px !important; font-size: 11px; }
            .badge { border: 1px solid #333; background: #fff !important; color: #000 !important; }
            .avatar-circle { display: none; }
            .stats-row { display: flex !important; }
            .stats-row .card { border: 1px solid #ccc !important; }
            .container-fluid { padding: 0 !important; }
            a { text-decoration: none; color: #000; }
            .print-footer {
                display: block !important;
                text-align: center;
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #ccc;
                font-size: 10px;
            }
        }
        @media screen {
            .print-header, .print-footer { display: none; }
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'student_reports';
    include 'header_nav.php'; 
    ?>
    
    <!-- Print Header (visible only when printing) -->
    <div class="print-header">
        <h2><?= htmlspecialchars($uni_name) ?></h2>
        <h3>Student Report</h3>
        <p>
            Generated on: <?= date('F j, Y \a\t g:i A') ?>
            <?php if ($filter_program): ?> | Program: <?= htmlspecialchars($filter_program) ?><?php endif; ?>
            <?php if ($filter_year): ?> | Year: <?= $filter_year ?><?php endif; ?>
            <?php if ($filter_campus): ?> | Campus: <?= htmlspecialchars($filter_campus) ?><?php endif; ?>
            <?php if ($filter_search): ?> | Search: "<?= htmlspecialchars($filter_search) ?>"<?php endif; ?>
            | Total: <?= number_format($total) ?> student(s)
        </p>
    </div>

    <div class="container-fluid py-4">
        
        <!-- Page Header with Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-file-earmark-bar-graph me-2"></i>Student Reports</h1>
                <p class="text-muted mb-0">Generate, view, print, or export student reports</p>
            </div>
            <div class="btn-toolbar gap-2">
                <button class="btn btn-success" onclick="printReport()">
                    <i class="bi bi-printer me-1"></i> Print Report
                </button>
                <button class="btn btn-danger" onclick="exportPDF()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                </button>
                <a href="?<?= http_build_query(array_merge($_GET, ['print_all' => '1'])) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-download me-1"></i> Load All for Print
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4 stats-row">
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-primary"><?= number_format($stats['total']) ?></div>
                        <small class="text-muted">Total Students</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-success"><?= number_format($stats['year1']) ?></div>
                        <small class="text-muted">Year 1</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-info"><?= number_format($stats['year2']) ?></div>
                        <small class="text-muted">Year 2</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-warning"><?= number_format($stats['year3']) ?></div>
                        <small class="text-muted">Year 3</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-danger"><?= number_format($stats['year4']) ?></div>
                        <small class="text-muted">Year 4</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="fs-3 fw-bold text-secondary"><?= count($programs) ?></div>
                        <small class="text-muted">Programs</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4 no-print filter-card">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?= htmlspecialchars($prog['program_code']) ?>" <?= $filter_program === $prog['program_code'] ? 'selected' : '' ?>><?= htmlspecialchars($prog['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <option value="1" <?= $filter_year == '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year == '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year == '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year == '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <?php if (!empty($campuses)): ?>
                    <div class="col-md-2">
                        <label class="form-label">Campus</label>
                        <select name="campus" class="form-select">
                            <option value="">All Campuses</option>
                            <?php foreach ($campuses as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $filter_campus === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Name, email, ID...">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Students</h5>
                <span class="badge bg-primary"><?= number_format($total) ?> students found</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="studentsTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Gender</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">No students found</td>
                            </tr>
                            <?php else: ?>
                            <?php $row_num = $print_all ? 1 : (($page - 1) * $per_page) + 1; ?>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?= $row_num++ ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; font-weight: 700; min-width: 35px;">
                                            <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                                        </div>
                                        <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($student['student_id']) ?></code></td>
                                <td><?= htmlspecialchars($student['program_name'] ?? $student[$program_col] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($student['department_name'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-info">Year <?= $student['year_of_study'] ?? 'N/A' ?></span></td>
                                <td><?= htmlspecialchars($student['gender'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($student['email']) ?></td>
                                <td><?= htmlspecialchars($student['phone'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($student['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1 && !$print_all): ?>
            <div class="card-footer bg-white no-print">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&status=<?= $filter_status ?>&campus=<?= urlencode($filter_campus) ?>&search=<?= urlencode($filter_search) ?>">Previous</a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&status=<?= $filter_status ?>&campus=<?= urlencode($filter_campus) ?>&search=<?= urlencode($filter_search) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&program=<?= urlencode($filter_program) ?>&year=<?= $filter_year ?>&status=<?= $filter_status ?>&campus=<?= urlencode($filter_campus) ?>&search=<?= urlencode($filter_search) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center small text-muted mt-2">
                    Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total) ?> of <?= number_format($total) ?> students
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print Footer (visible only when printing) -->
    <div class="print-footer">
        <p><?= htmlspecialchars($uni_name) ?> &mdash; Confidential Student Report &mdash; Generated <?= date('d/m/Y H:i') ?> &mdash; Page <span class="pageNumber"></span></p>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printReport() {
            window.print();
        }

        function exportPDF() {
            // Use browser's print-to-PDF functionality
            // Set a temporary title for the PDF filename
            var originalTitle = document.title;
            document.title = 'Student_Report_<?= date('Y-m-d') ?>';
            window.print();
            setTimeout(function() {
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</body>
</html>
