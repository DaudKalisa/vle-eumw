<?php
/**
 * All_unique_students.php
 * Deduplicated master list of every unique student across all data sources:
 *   – students table (main VLE registry)
 *   – vle_enrollments (course-enrolled)
 *   – dissertations (dissertation students)
 *   – exam_clearance_students (exam-cleared)
 *
 * Theme: matches admin/student_list_report.php exactly.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'super_admin', 'staff', 'finance', 'dean']);

$conn = getDbConnection();

// ── Filters ──────────────────────────────────────────────────────────────────
$filter_campus   = trim($_GET['campus']   ?? '');
$filter_program  = trim($_GET['program']  ?? '');
$filter_year     = trim($_GET['year']     ?? '');
$filter_semester = trim($_GET['semester'] ?? '');
$filter_gender   = trim($_GET['gender']   ?? '');
$filter_search   = trim($_GET['search']   ?? '');

$has_filter = ($filter_campus || $filter_program || $filter_year || $filter_semester || $filter_gender || $filter_search);

// ── Dropdown option lists ─────────────────────────────────────────────────────
$campuses = [];
$cr = $conn->query("SELECT DISTINCT campus FROM students WHERE campus IS NOT NULL AND TRIM(campus) != '' AND campus != '0' ORDER BY campus");
if ($cr) while ($r = $cr->fetch_assoc()) $campuses[] = $r['campus'];

$programs_list = ['degree', 'diploma', 'professional', 'masters', 'doctorate', 'postgraduate', 'mba'];

// ── Collect all unique students (UNION across sources, deduped by student_id) –
$conditions = [];
$binds      = [];
$bind_types = '';

// Build WHERE clause from filters
if ($filter_campus !== '') {
    $conditions[] = "campus = ?";
    $binds[]      = $filter_campus;
    $bind_types  .= 's';
}
if ($filter_program !== '') {
    $conditions[] = "program_type = ?";
    $binds[]      = $filter_program;
    $bind_types  .= 's';
}
if ($filter_year !== '') {
    $conditions[] = "year_of_study = ?";
    $binds[]      = $filter_year;
    $bind_types  .= 's';
}
if ($filter_semester !== '') {
    $conditions[] = "semester = ?";
    $binds[]      = $filter_semester;
    $bind_types  .= 's';
}
if ($filter_gender !== '') {
    $conditions[] = "gender = ?";
    $binds[]      = $filter_gender;
    $bind_types  .= 's';
}
if ($filter_search !== '') {
    $conditions[] = "(student_id LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $like         = '%' . $filter_search . '%';
    $binds[]      = $like;
    $binds[]      = $like;
    $binds[]      = $like;
    $bind_types  .= 'sss';
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

// Main students table (source of truth)
$sql = "
    SELECT DISTINCT
        s.student_id,
        s.full_name,
        s.email,
        COALESCE(NULLIF(TRIM(s.campus),''), 'Not Set')         AS campus,
        COALESCE(NULLIF(TRIM(s.gender),''), 'Not Specified')   AS gender,
        COALESCE(NULLIF(TRIM(s.program_type),''), '—')         AS program_type,
        COALESCE(NULLIF(TRIM(s.program),''), '—')              AS program,
        COALESCE(NULLIF(TRIM(s.year_of_study),''), '—')        AS year_of_study,
        COALESCE(NULLIF(TRIM(s.semester),''), '—')             AS semester,
        (
            SELECT GROUP_CONCAT(DISTINCT src ORDER BY src SEPARATOR ', ')
            FROM (
                SELECT 'VLE' AS src
                    FROM vle_enrollments ve
                    WHERE ve.student_id = s.student_id
                UNION ALL
                SELECT 'Dissertation' AS src
                    FROM dissertations d
                    WHERE d.student_id = s.student_id
                UNION ALL
                SELECT 'Exam Clearance' AS src
                    FROM exam_clearance_students ec
                    WHERE ec.student_id = s.student_id
            ) src_list
        ) AS data_sources
    FROM students s
    {$where}
    ORDER BY s.full_name
";

$students = [];
if ($bind_types) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($bind_types, ...$binds);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $students[] = $row;
        $stmt->close();
    }
} else {
    $res = $conn->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $students[] = $row;
}

$total = count($students);

// ── Stats: campus, gender, program breakdown ─────────────────────────────────
$stat_campus  = [];
$stat_gender  = [];
$stat_program = [];
foreach ($students as $s) {
    $stat_campus[$s['campus']]    = ($stat_campus[$s['campus']]  ?? 0) + 1;
    $stat_gender[$s['gender']]    = ($stat_gender[$s['gender']]  ?? 0) + 1;
    $stat_program[$s['program_type']] = ($stat_program[$s['program_type']] ?? 0) + 1;
}
arsort($stat_campus);
arsort($stat_gender);
arsort($stat_program);

// ── Role for nav ──────────────────────────────────────────────────────────────
$nav_role = $_SESSION['vle_role'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Unique Students — VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global-theme.css">
    <style>
        /* ── same base as student_list_report.php ── */
        body { background: #f0f4ff; }

        .slr-hero {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: #fff;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(30,60,114,.25);
        }

        .stat-mini {
            border-radius: 12px;
            padding: .7rem 1rem;
            font-size: .82rem;
        }

        .source-badge {
            display: inline-block;
            padding: .2em .55em;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .source-vle          { background:#dbeafe; color:#1e40af; }
        .source-dissertation { background:#fef3c7; color:#92400e; }
        .source-exam         { background:#d1fae5; color:#065f46; }
        .source-none         { background:#f3f4f6; color:#6b7280; }

        @media print {
            .no-print { display: none !important; }
            body      { background: #fff; }
        }
    </style>
</head>
<body>

<?php include 'header_nav.php'; ?>

<div class="container-xl py-4">

    <!-- Hero header -->
    <div class="slr-hero d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-people-fill me-2"></i>All Unique Students
                <span class="badge bg-white text-dark ms-2 align-middle" style="font-size:.7rem"><?= number_format($total) ?></span>
            </h4>
            <p class="mb-0 opacity-75 small">
                Deduplicated master list from the VLE student registry.
                <?= $has_filter ? '<strong>Filtered view active.</strong>' : 'Showing all students.' ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap no-print">
            <a href="student_list_report.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Full Report
            </a>
            <a href="manage_student_list_report.php" class="btn btn-warning btn-sm">
                <i class="bi bi-tools me-1"></i>Fix Missing Data
            </a>
            <button onclick="window.print()" class="btn btn-light btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Summary row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card border-0 stat-mini shadow-sm" style="background:#e0f2fe">
                <span class="fw-bold text-primary fs-5"><?= number_format($total) ?></span>
                <span class="text-muted">Total Unique Students</span>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 stat-mini shadow-sm" style="background:#f0fdf4">
                <span class="fw-bold text-success fs-5"><?= count($stat_campus) ?></span>
                <span class="text-muted">Campuses Represented</span>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 stat-mini shadow-sm" style="background:#fef3c7">
                <span class="fw-bold text-warning fs-5"><?= count($stat_program) ?></span>
                <span class="text-muted">Program Types</span>
            </div>
        </div>
    </div>

    <!-- ── Filter form ── -->
    <div class="card border-0 shadow-sm mb-4 no-print" style="border-radius:14px">
        <div class="card-header fw-semibold" style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;border-radius:14px 14px 0 0">
            <i class="bi bi-funnel me-1"></i>Filter Students
            <?php if ($has_filter): ?>
            <a href="All_unique_students.php" class="btn btn-sm btn-outline-light ms-2 py-0">
                <i class="bi bi-x me-1"></i>Clear
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-semibold mb-1">Campus</label>
                    <select name="campus" class="form-select form-select-sm">
                        <option value="">All Campuses</option>
                        <?php foreach ($campuses as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $filter_campus === $c ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold mb-1">Program Type</label>
                    <select name="program" class="form-select form-select-sm">
                        <option value="">All Programs</option>
                        <?php foreach ($programs_list as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $filter_program === $p ? 'selected' : '' ?>>
                            <?= ucfirst(htmlspecialchars($p)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-4">
                    <label class="form-label small fw-semibold mb-1">Year</label>
                    <select name="year" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <?php foreach (['1','2','3','4','5'] as $y): ?>
                        <option value="<?= $y ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-4">
                    <label class="form-label small fw-semibold mb-1">Semester</label>
                    <select name="semester" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <?php foreach (['One','Two','Three'] as $sem): ?>
                        <option value="<?= $sem ?>" <?= $filter_semester === $sem ? 'selected' : '' ?>><?= $sem ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-4">
                    <label class="form-label small fw-semibold mb-1">Gender</label>
                    <select name="gender" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <?php foreach (['Male','Female','Other','Not Specified'] as $g): ?>
                        <option value="<?= $g ?>" <?= $filter_gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-8">
                    <label class="form-label small fw-semibold mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Student ID, name or email…"
                           value="<?= htmlspecialchars($filter_search) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Breakdown mini-tables ── -->
    <?php if (!$has_filter): ?>
    <div class="row g-3 mb-4">
        <!-- Campus -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-radius:12px">
                <div class="card-header fw-semibold small" style="background:#1e3c72;color:#fff;border-radius:12px 12px 0 0">
                    <i class="bi bi-geo-alt me-1"></i>By Campus
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                        <tbody>
                        <?php foreach ($stat_campus as $c => $n): ?>
                        <tr>
                            <td><?= htmlspecialchars($c) ?></td>
                            <td class="text-end fw-semibold"><?= number_format($n) ?></td>
                            <td class="text-muted text-end" style="width:50px"><?= $total > 0 ? round($n/$total*100,1) : 0 ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total</th><th class="text-end"><?= number_format($total) ?></th><th></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <!-- Gender -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-radius:12px">
                <div class="card-header fw-semibold small" style="background:#0f766e;color:#fff;border-radius:12px 12px 0 0">
                    <i class="bi bi-person-fill me-1"></i>By Gender
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                        <tbody>
                        <?php foreach ($stat_gender as $g => $n): ?>
                        <tr>
                            <td><?= htmlspecialchars($g) ?></td>
                            <td class="text-end fw-semibold"><?= number_format($n) ?></td>
                            <td class="text-muted text-end" style="width:50px"><?= $total > 0 ? round($n/$total*100,1) : 0 ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total</th><th class="text-end"><?= number_format($total) ?></th><th></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <!-- Program type -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border-radius:12px">
                <div class="card-header fw-semibold small" style="background:#7c3aed;color:#fff;border-radius:12px 12px 0 0">
                    <i class="bi bi-mortarboard me-1"></i>By Program Type
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                        <tbody>
                        <?php foreach ($stat_program as $p => $n): ?>
                        <tr>
                            <td><?= ucfirst(htmlspecialchars($p)) ?></td>
                            <td class="text-end fw-semibold"><?= number_format($n) ?></td>
                            <td class="text-muted text-end" style="width:50px"><?= $total > 0 ? round($n/$total*100,1) : 0 ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total</th><th class="text-end"><?= number_format($total) ?></th><th></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Main table ── -->
    <div class="card shadow-sm" style="border:none;border-radius:14px">
        <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;border-radius:14px 14px 0 0">
            <span class="fw-semibold">
                <i class="bi bi-table me-1"></i>
                <?= number_format($total) ?> Unique Student<?= $total !== 1 ? 's' : '' ?>
            </span>
            <?php if ($has_filter): ?>
            <small class="opacity-75 no-print">Filtered view</small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($students)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-search fs-1 d-block mb-2"></i>
                No students match the selected filters.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="uniqueStudentTable">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center" style="width:45px">#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Campus</th>
                            <th>Gender</th>
                            <th>Program Type</th>
                            <th>Program / Study</th>
                            <th class="text-center">Year</th>
                            <th class="text-center">Sem</th>
                            <th class="no-print">Sources</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $i => $s): ?>
                    <?php
                        $missing = ($s['campus'] === 'Not Set' || $s['gender'] === 'Not Specified' || $s['program_type'] === '—');
                        $row_class = $missing ? 'table-warning' : '';
                        // Build source badges
                        $src_html = '';
                        if ($s['data_sources']) {
                            foreach (explode(', ', $s['data_sources']) as $src) {
                                $sc = str_contains($src, 'VLE') ? 'source-vle'
                                   : (str_contains($src, 'Dissertation') ? 'source-dissertation'
                                   : (str_contains($src, 'Exam') ? 'source-exam' : 'source-none'));
                                $src_html .= '<span class="source-badge ' . $sc . '">' . htmlspecialchars($src) . '</span> ';
                            }
                        } else {
                            $src_html = '<span class="source-badge source-none">Registry only</span>';
                        }
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="text-center text-muted small"><?= $i + 1 ?></td>
                        <td><code class="small"><?= htmlspecialchars($s['student_id']) ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['email']) ?></td>
                        <td class="small">
                            <?php if ($s['campus'] === 'Not Set'): ?>
                                <span class="badge bg-danger">Not Set</span>
                            <?php else: ?>
                                <?= htmlspecialchars($s['campus']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($s['gender'] === 'Not Specified'): ?>
                                <span class="badge bg-secondary">Not Set</span>
                            <?php else: ?>
                                <?= htmlspecialchars($s['gender']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['program_type'] === '—'): ?>
                                <span class="badge bg-warning text-dark">Not Set</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= ucfirst(htmlspecialchars($s['program_type'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($s['program']) ?></td>
                        <td class="text-center small"><?= htmlspecialchars($s['year_of_study']) ?></td>
                        <td class="text-center small"><?= htmlspecialchars($s['semester']) ?></td>
                        <td class="no-print"><?= $src_html ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($students)): ?>
        <div class="card-footer small text-muted d-flex justify-content-between no-print" style="border-radius:0 0 14px 14px">
            <span>
                <?php if ($has_filter): ?>
                <span class="badge bg-warning text-dark me-1">Filtered</span>
                <?php endif; ?>
                Showing <strong><?= count($students) ?></strong> student(s)
            </span>
            <span>Generated: <?= date('d M Y H:i') ?></span>
        </div>
        <?php endif; ?>
    </div>

    <p class="text-muted text-end small mt-3">
        <i class="bi bi-lock me-1"></i>Confidential — For Management Use Only — <?= date('d M Y H:i') ?>
    </p>
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Live search within rendered table -->
<script>
(function () {
    var inp = document.getElementById('liveSearch');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#uniqueStudentTable tbody tr').forEach(function (tr) {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
})();
</script>
</body>
</html>
