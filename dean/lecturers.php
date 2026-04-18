<?php
/**
 * Dean Portal - Lecturers Management
 * View and monitor lecturers in the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Filters
$filter_department = $_GET['department'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query
$where = [];
$params = [];
$types = "";

if ($filter_department) {
    $where[] = "l.department = ?";
    $params[] = $filter_department;
    $types .= "s";
}

if ($filter_search) {
    $where[] = "(l.full_name LIKE ? OR l.email LIKE ? OR l.lecturer_id LIKE ?)";
    $search = "%$filter_search%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get lecturers with stats
$sql = "SELECT l.*, 
        (SELECT COUNT(*) FROM vle_courses c WHERE c.lecturer_id = l.lecturer_id) as course_count,
        (SELECT COUNT(*) FROM lecturer_finance_requests r WHERE r.lecturer_id = l.lecturer_id) as claim_count,
        (SELECT SUM(total_amount) FROM lecturer_finance_requests r WHERE r.lecturer_id = l.lecturer_id AND r.status = 'paid') as total_paid
        FROM lecturers l
        $where_sql
        ORDER BY l.full_name";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$lecturers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Get departments for filter
$departments = [];
$dept_result = $conn->query("SELECT DISTINCT department FROM lecturers WHERE department IS NOT NULL AND department != '' ORDER BY department");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['department'];
    }
}

// Stats
$total_lecturers = count($lecturers);
$active_lecturers = 0;
foreach ($lecturers as $l) {
    if (($l['is_active'] ?? 1) == 1) $active_lecturers++;
}

$page_title = "Lecturers";
$breadcrumbs = [['title' => 'Lecturers']];
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
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-primary"><?= $total_lecturers ?></div>
                        <small class="text-muted">Total Lecturers</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success"><?= $active_lecturers ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= count($departments) ?></div>
                        <small class="text-muted">Departments</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>" <?= $filter_department === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Search by name, email, or ID...">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lecturers Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Faculty Lecturers</h5>
                <span class="badge bg-primary"><?= $total_lecturers ?> lecturers</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Lecturer</th>
                                <th>Department</th>
                                <th>Phone</th>
                                <th>Courses</th>
                                <th>Claims</th>
                                <th>Total Paid</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lecturers)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No lecturers found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($lecturers as $lecturer): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($lecturer['profile_picture']) && file_exists('../uploads/profiles/' . $lecturer['profile_picture'])): ?>
                                            <img src="../uploads/profiles/<?= htmlspecialchars($lecturer['profile_picture']) ?>" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-weight: 700;">
                                                <?= strtoupper(substr($lecturer['full_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($lecturer['full_name']) ?></strong>
                                            <div class="small text-muted"><?= htmlspecialchars($lecturer['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($lecturer['department'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($lecturer['phone'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-primary"><?= $lecturer['course_count'] ?></span></td>
                                <td><span class="badge bg-info"><?= $lecturer['claim_count'] ?></span></td>
                                <td><strong>MKW <?= number_format($lecturer['total_paid'] ?? 0) ?></strong></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewLecturer(<?= $lecturer['lecturer_id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Lecturer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="lecturerDetails">
                    Loading...
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        
        function viewLecturer(id) {
            document.getElementById('lecturerDetails').innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
            viewModal.show();
            
            fetch('get_lecturer_details.php?id=' + id)
                .then(r => r.text())
                .then(html => document.getElementById('lecturerDetails').innerHTML = html)
                .catch(() => document.getElementById('lecturerDetails').innerHTML = '<div class="alert alert-danger">Failed to load</div>');
        }
    </script>
</body>
</html>
