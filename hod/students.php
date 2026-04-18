<?php
/**
 * HOD - Department Students
 * View students enrolled in programs under the HOD's department
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['hod', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get HOD department info
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
            // Get department_id
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

// Get department programs
$dept_programs = [];
if ($department_id) {
    $stmt = $conn->prepare("SELECT program_name, program_code FROM programs WHERE department_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dept_programs[] = $row;
        }
    }
}

// Filters
$filter_year = $_GET['year'] ?? '';
$filter_program = $_GET['program'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query for students in department programs
$where = ["1=1"];
$params = [];
$types = '';

if ($department_id && !empty($dept_programs)) {
    $prog_conditions = [];
    foreach ($dept_programs as $dp) {
        $prog_conditions[] = "s.program = ?";
        $params[] = $dp['program_code'];
        $types .= 's';
        $prog_conditions[] = "s.program = ?";
        $params[] = $dp['program_name'];
        $types .= 's';
    }
    $where[] = "(" . implode(' OR ', $prog_conditions) . ")";
} elseif ($hod_department) {
    // Fallback: match by department name
    $where[] = "s.program LIKE ?";
    $params[] = '%' . $hod_department . '%';
    $types .= 's';
}

if ($filter_year) {
    $where[] = "s.year_of_study = ?";
    $params[] = $filter_year;
    $types .= 'i';
}

if ($filter_program) {
    $where[] = "(s.program = ? OR s.program = ?)";
    $params[] = $filter_program;
    $params[] = $filter_program;
    $types .= 'ss';
}

if ($search) {
    $where[] = "(s.full_name LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'sss';
}

$sql = "SELECT s.*, 
        (SELECT COUNT(*) FROM vle_enrollments e WHERE e.student_id = s.student_id) as course_count
        FROM students s
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.year_of_study, s.full_name";

$stmt = $conn->prepare($sql);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = "Department Students";
$breadcrumbs = [['title' => 'Students']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - HOD Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-mortarboard me-2 text-success"></i>Department Students</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($hod_department ?: 'All Departments') ?> — <?= count($students) ?> student(s)</p>
            </div>
            <div>
                <button class="btn btn-outline-success btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print List
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, ID, email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php for ($y = 1; $y <= 4; $y++): ?>
                            <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($dept_programs as $dp): ?>
                            <option value="<?= htmlspecialchars($dp['program_code']) ?>" <?= $filter_program === $dp['program_code'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dp['program_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="students.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Year Summary -->
        <div class="row mb-4">
            <?php
            $year_counts = [];
            foreach ($students as $s) {
                $yr = $s['year_of_study'] ?? 0;
                $year_counts[$yr] = ($year_counts[$yr] ?? 0) + 1;
            }
            ksort($year_counts);
            $colors = [1 => 'primary', 2 => 'success', 3 => 'warning', 4 => 'info'];
            foreach ($year_counts as $yr => $cnt):
                $color = $colors[$yr] ?? 'secondary';
            ?>
            <div class="col-md-3 col-6 mb-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-<?= $color ?>"><?= $cnt ?></span>
                    <small class="text-muted">Year <?= $yr ?> Students</small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Students Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($students)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-mortarboard display-4 d-block mb-3"></i>
                    <p>No students found in your department.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th>Semester</th>
                                <th>Courses</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center me-3" style="width:38px;height:38px;font-weight:600;font-size:0.85rem;">
                                            <?= strtoupper(substr($s['full_name'] ?? '', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($s['full_name'] ?? '') ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($s['email'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($s['student_id'] ?? '') ?></code></td>
                                <td><small><?= htmlspecialchars($s['program'] ?? 'N/A') ?></small></td>
                                <td><span class="badge bg-<?= $colors[$s['year_of_study'] ?? 0] ?? 'secondary' ?>">Year <?= $s['year_of_study'] ?? '?' ?></span></td>
                                <td><?= htmlspecialchars($s['semester'] ?? '-') ?></td>
                                <td><span class="badge bg-primary"><?= $s['course_count'] ?></span></td>
                                <td>
                                    <?php if (($s['is_active'] ?? 1)): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
