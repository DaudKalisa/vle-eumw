<?php
/**
 * Dean Portal - Departments
 * View departments in the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get dean's faculty
$dean_faculty_id = $user['related_dean_id'] ?? null;

// Check if programs has department_id column
$has_dept_col = $conn->query("SHOW COLUMNS FROM programs LIKE 'department_id'");
$has_program_dept = ($has_dept_col && $has_dept_col->num_rows > 0);

// Get departments
$program_count_sql = $has_program_dept 
    ? "(SELECT COUNT(*) FROM programs p WHERE p.department_id = d.department_id)" 
    : "0";

$sql = "SELECT d.*, f.faculty_name,
        $program_count_sql as program_count,
        (SELECT COUNT(*) FROM lecturers l WHERE l.department = d.department_name) as lecturer_count
        FROM departments d
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id";

if ($dean_faculty_id) {
    $sql .= " WHERE d.faculty_id = $dean_faculty_id";
}

$sql .= " ORDER BY d.department_name";

$result = $conn->query($sql);
$departments = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
}

$page_title = "Departments";
$breadcrumbs = [['title' => 'Departments']];
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
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-building me-2"></i>Departments</h5>
                <span class="badge bg-primary"><?= count($departments) ?> departments</span>
            </div>
            <div class="card-body">
                <?php if (empty($departments)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-building fs-1 d-block mb-3"></i>
                    <p>No departments found</p>
                </div>
                <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($departments as $dept): ?>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-start">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded p-3 me-3">
                                        <i class="bi bi-building fs-4"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?= htmlspecialchars($dept['department_name']) ?></h6>
                                        <small class="text-muted">Code: <?= htmlspecialchars($dept['department_code']) ?></small>
                                    </div>
                                </div>
                                <hr>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="fs-5 fw-bold text-primary"><?= $dept['program_count'] ?></div>
                                        <small class="text-muted">Programs</small>
                                    </div>
                                    <div class="col-6">
                                        <div class="fs-5 fw-bold text-success"><?= $dept['lecturer_count'] ?></div>
                                        <small class="text-muted">Lecturers</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
