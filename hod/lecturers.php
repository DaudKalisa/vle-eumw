<?php
/**
 * HOD - Department Lecturers
 * View lecturers assigned to the HOD's department
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['hod', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get HOD department
$hod_department = '';
if (!empty($user['related_staff_id'])) {
    $stmt = $conn->prepare("SELECT department FROM administrative_staff WHERE staff_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['related_staff_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $hod_department = $row['department'] ?? '';
    }
}

$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

// Build query
$where = ["1=1"];
$params = [];
$types = '';

if ($hod_department) {
    $where[] = "l.department LIKE ?";
    $params[] = '%' . $hod_department . '%';
    $types .= 's';
}

if ($filter_status === '1') {
    $where[] = "l.is_active = 1";
} elseif ($filter_status === '0') {
    $where[] = "l.is_active = 0";
}

if ($search) {
    $where[] = "(l.full_name LIKE ? OR l.email LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';
}

$sql = "SELECT l.*, 
        (SELECT COUNT(*) FROM vle_courses c WHERE c.lecturer_id = l.lecturer_id) as course_count,
        (SELECT GROUP_CONCAT(c2.course_code SEPARATOR ', ') FROM vle_courses c2 WHERE c2.lecturer_id = l.lecturer_id LIMIT 5) as courses_list,
        u.email as user_email, u.is_active as user_active
        FROM lecturers l
        LEFT JOIN users u ON u.related_lecturer_id = l.lecturer_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.full_name";

$stmt = $conn->prepare($sql);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$lecturers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = "Department Lecturers";
$breadcrumbs = [['title' => 'Lecturers']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturers - HOD Portal</title>
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
                <h2 class="vle-page-title"><i class="bi bi-people me-2 text-info"></i>Department Lecturers</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($hod_department ?: 'All Departments') ?> — <?= count($lecturers) ?> lecturer(s)</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Name, email, staff number..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="lecturers.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <?php
            $total = count($lecturers);
            $active = count(array_filter($lecturers, fn($l) => $l['is_active']));
            $with_courses = count(array_filter($lecturers, fn($l) => $l['course_count'] > 0));
            ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-primary"><?= $total ?></span>
                    <small class="text-muted">Total Lecturers</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-success"><?= $active ?></span>
                    <small class="text-muted">Active</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-info"><?= $with_courses ?></span>
                    <small class="text-muted">Teaching Courses</small>
                </div>
            </div>
        </div>

        <!-- Lecturers Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($lecturers)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-people display-4 d-block mb-3"></i>
                    <p>No lecturers found.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Lecturer</th>
                                <th>Staff Number</th>
                                <th>Contact</th>
                                <th>Courses</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lecturers as $l): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;font-weight:600;">
                                            <?= strtoupper(substr($l['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($l['full_name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($l['department'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><code>LEC-<?= $l['lecturer_id'] ?></code></td>
                                <td>
                                    <small>
                                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($l['email'] ?? '') ?><br>
                                        <?php if (!empty($l['phone'])): ?>
                                        <i class="bi bi-phone me-1"></i><?= htmlspecialchars($l['phone']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?= $l['course_count'] ?> course(s)</span>
                                    <?php if (!empty($l['courses_list'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($l['courses_list']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($l['is_active']): ?>
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
