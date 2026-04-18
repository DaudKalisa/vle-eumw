<?php
/**
 * Admin – Graduation Report
 * Lists graduated students grouped by classification with filters
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Filters
$filter_program = $_GET['program'] ?? '';
$filter_campus  = $_GET['campus'] ?? '';
$filter_year    = $_GET['year'] ?? '';
$filter_type    = $_GET['type'] ?? '';

// Build query
$where = ["ga.status = 'completed'"];
$params = [];
$types = '';

if ($filter_program) {
    $where[] = "ga.program = ?";
    $params[] = $filter_program;
    $types .= 's';
}
if ($filter_campus) {
    $where[] = "ga.campus = ?";
    $params[] = $filter_campus;
    $types .= 's';
}
if ($filter_year) {
    $where[] = "ga.year_of_completion = ?";
    $params[] = $filter_year;
    $types .= 'i';
}
if ($filter_type) {
    $where[] = "ga.application_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

$where_sql = implode(' AND ', $where);

$sql = "SELECT ga.*, ggs.gpa, ggs.classification, ggs.total_credits
        FROM graduation_applications ga
        LEFT JOIN graduation_grade_summary ggs ON ga.application_id = ggs.application_id
        WHERE $where_sql
        ORDER BY ggs.gpa DESC, ga.last_name ASC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
$grouped = ['Distinction' => [], 'Merit' => [], 'Credit' => [], 'Pass' => [], 'Fail' => [], 'N/A' => []];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
    $cls = $row['classification'] ?? 'N/A';
    if (!isset($grouped[$cls])) $cls = 'N/A';
    $grouped[$cls][] = $row;
}

// Dropdown values
$programs = [];
$pr = $conn->query("SELECT DISTINCT program FROM graduation_applications WHERE program IS NOT NULL ORDER BY program");
if ($pr) while ($p = $pr->fetch_assoc()) $programs[] = $p['program'];

$campuses = ['Blantyre Campus', 'Lilongwe Campus', 'Mzuzu Campus'];

$years = [];
$yr = $conn->query("SELECT DISTINCT year_of_completion FROM graduation_applications WHERE year_of_completion IS NOT NULL ORDER BY year_of_completion DESC");
if ($yr) while ($y = $yr->fetch_assoc()) $years[] = $y['year_of_completion'];

$total = count($students);

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="graduation_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Student ID', 'Full Name', 'Gender', 'Program', 'Campus', 'Year Entry', 'Year Completion', 'Type', 'GPA', 'Classification']);
    $i = 1;
    foreach ($students as $s) {
        fputcsv($out, [
            $i++,
            $s['student_id_number'],
            trim($s['first_name'] . ' ' . ($s['middle_name'] ?? '') . ' ' . $s['last_name']),
            $s['gender'] ?? '',
            $s['program'] ?? '',
            $s['campus'] ?? '',
            $s['year_of_entry'] ?? '',
            $s['year_of_completion'] ?? '',
            ucfirst($s['application_type'] ?? ''),
            $s['gpa'] ? number_format($s['gpa'], 2) : '',
            $s['classification'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

$page_title = 'Graduation Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        .page-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 25px 0; margin-bottom: 25px; }
        .stat-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); transition: .2s; }
        .stat-card:hover { transform: translateY(-2px); }
        .cls-badge { font-size: .75rem; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
        .badge-distinction { background: #d4edda; color: #155724; }
        .badge-merit { background: #cce5ff; color: #004085; }
        .badge-credit { background: #fff3cd; color: #856404; }
        .badge-pass { background: #e2e3e5; color: #383d41; }
        .badge-fail { background: #f8d7da; color: #721c24; }
        .group-header { background: #f8f9fa; padding: 8px 15px; border-left: 4px solid; font-weight: 600; margin: 20px 0 8px; border-radius: 0 6px 6px 0; }
        .group-distinction { border-color: #28a745; }
        .group-merit { border-color: #007bff; }
        .group-credit { border-color: #ffc107; }
        .group-pass { border-color: #6c757d; }
        .group-fail { border-color: #dc3545; }
        .table th { font-size: .8rem; text-transform: uppercase; letter-spacing: .5px; color: #6c757d; }
        .table td { vertical-align: middle; font-size: .875rem; }
        @media print {
            .no-print { display: none !important; }
            .page-header { background: #1a1a2e !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
<div class="page-header no-print">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-1"><i class="fas fa-graduation-cap me-2"></i><?= $page_title ?></h3>
                <p class="mb-0 opacity-75">Completed graduation clearance list</p>
            </div>
            <a href="graduation_students.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Back</a>
        </div>
    </div>
</div>

<div class="container">
    <!-- Filters -->
    <div class="card stat-card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Program</label>
                    <select name="program" class="form-select">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $filter_program === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Campus</label>
                    <select name="campus" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($campuses as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $filter_campus === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All</option>
                        <option value="clearance" <?= $filter_type === 'clearance' ? 'selected' : '' ?>>Clearance</option>
                        <option value="transcript" <?= $filter_type === 'transcript' ? 'selected' : '' ?>>Transcript Only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="graduation_report.php" class="btn btn-outline-secondary me-2">Clear</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success"><i class="fas fa-file-csv me-1"></i> CSV</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card stat-card text-center p-3">
                <div class="fw-bold fs-4"><?= $total ?></div>
                <small class="text-muted">Total Graduated</small>
            </div>
        </div>
        <?php
        $cls_colors = ['Distinction' => 'success', 'Merit' => 'primary', 'Credit' => 'warning', 'Pass' => 'secondary', 'Fail' => 'danger'];
        foreach ($cls_colors as $cls => $color):
            $cnt = count($grouped[$cls]);
        ?>
        <div class="col-md-2">
            <div class="card stat-card text-center p-3">
                <div class="fw-bold fs-4 text-<?= $color ?>"><?= $cnt ?></div>
                <small class="text-muted"><?= $cls ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Print header (only shows in print) -->
    <div class="d-none d-print-block text-center mb-3">
        <h4>EXPLOITS UNIVERSITY OF MALAWI</h4>
        <h5>Graduation Report <?= $filter_year ? '- ' . $filter_year : '' ?></h5>
        <p class="text-muted">
            <?php
            $filters = [];
            if ($filter_program) $filters[] = "Program: $filter_program";
            if ($filter_campus) $filters[] = "Campus: $filter_campus";
            if ($filter_type) $filters[] = "Type: " . ucfirst($filter_type);
            echo $filters ? implode(' | ', $filters) : 'All Students';
            ?>
            &bull; Total: <?= $total ?> students &bull; Printed: <?= date('d/m/Y') ?>
        </p>
    </div>

    <!-- Grouped Tables -->
    <?php
    $class_order = ['Distinction', 'Merit', 'Credit', 'Pass', 'Fail'];
    $i = 1;
    foreach ($class_order as $cls):
        if (empty($grouped[$cls])) continue;
        $cls_lower = strtolower($cls);
    ?>
    <div class="group-header group-<?= $cls_lower ?>">
        <i class="fas fa-award me-1"></i> <?= $cls ?> (<?= count($grouped[$cls]) ?>)
    </div>
    <div class="table-responsive mb-3">
        <table class="table table-hover table-sm bg-white" style="border-radius:8px;overflow:hidden;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Gender</th>
                    <th>Program</th>
                    <th>Campus</th>
                    <th>Entry</th>
                    <th>Completion</th>
                    <th>Type</th>
                    <th>GPA</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grouped[$cls] as $s): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($s['student_id_number'] ?? '') ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars(trim($s['first_name'] . ' ' . ($s['middle_name'] ?? '') . ' ' . $s['last_name'])) ?></td>
                    <td><?= htmlspecialchars($s['gender'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['program'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['campus'] ?? '') ?></td>
                    <td><?= $s['year_of_entry'] ?? '' ?></td>
                    <td><?= $s['year_of_completion'] ?? '' ?></td>
                    <td><span class="badge bg-<?= $s['application_type'] === 'transcript' ? 'info' : 'primary' ?>"><?= ucfirst($s['application_type'] ?? '') ?></span></td>
                    <td><strong><?= $s['gpa'] ? number_format($s['gpa'], 2) : '-' ?></strong></td>
                    <td class="no-print">
                        <a href="../api/generate_graduation_transcript.php?app_id=<?= $s['application_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="View Transcript"><i class="fas fa-file-pdf"></i></a>
                        <?php if ($s['application_type'] === 'clearance'): ?>
                        <a href="../api/generate_clearance_certificate.php?app_id=<?= $s['application_id'] ?>" target="_blank" class="btn btn-sm btn-outline-success" title="View Certificate"><i class="fas fa-certificate"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if ($total === 0): ?>
    <div class="text-center py-5">
        <i class="fas fa-search fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">No graduated students found</h5>
        <p class="text-muted">Adjust filters or check that clearance processes have been completed.</p>
    </div>
    <?php endif; ?>

    <!-- Print button -->
    <div class="text-end mb-4 no-print">
        <button onclick="window.print()" class="btn btn-outline-dark"><i class="fas fa-print me-1"></i> Print Report</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
