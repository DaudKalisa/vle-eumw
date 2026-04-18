<?php
/**
 * ODL Coordinator - Course Allocation
 * Allocate courses to lecturers
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'allocate':
                $course_id = (int)$_POST['course_id'];
                $lecturer_id = !empty($_POST['lecturer_id']) ? (int)$_POST['lecturer_id'] : null;
                
                $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
                $stmt->bind_param("ii", $lecturer_id, $course_id);
                
                if ($stmt->execute()) {
                    $success_message = $lecturer_id ? "Course allocated successfully." : "Lecturer removed from course.";
                } else {
                    $error_message = "Failed to allocate course.";
                }
                break;
                
            case 'bulk_allocate':
                $lecturer_id = (int)$_POST['lecturer_id'];
                $course_ids = $_POST['course_ids'] ?? [];
                
                if (!empty($course_ids)) {
                    $success_count = 0;
                    foreach ($course_ids as $course_id) {
                        $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
                        $stmt->bind_param("ii", $lecturer_id, $course_id);
                        if ($stmt->execute()) {
                            $success_count++;
                        }
                    }
                    $success_message = "Allocated $success_count courses to lecturer.";
                } else {
                    $error_message = "No courses selected.";
                }
                break;
        }
    }
}

// Get all courses with lecturer info
$courses_sql = "
    SELECT c.*, l.full_name as lecturer_name, l.email as lecturer_email,
           (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = c.course_id) as enrolled_count
    FROM vle_courses c
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    ORDER BY c.course_code
";
$courses = [];
$result = $conn->query($courses_sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get all lecturers
$lecturers = [];
$lect_result = $conn->query("
    SELECT l.*, 
           (SELECT COUNT(*) FROM vle_courses c WHERE c.lecturer_id = l.lecturer_id) as course_count
    FROM lecturers l 
    ORDER BY l.full_name
");
if ($lect_result) {
    while ($row = $lect_result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Filter
$filter_lecturer = $_GET['lecturer'] ?? '';
$filter_status = $_GET['status'] ?? '';

$filtered_courses = $courses;
if ($filter_lecturer) {
    $filtered_courses = array_filter($courses, fn($c) => $c['lecturer_id'] == $filter_lecturer);
}
if ($filter_status === 'assigned') {
    $filtered_courses = array_filter($filtered_courses, fn($c) => $c['lecturer_id']);
}
if ($filter_status === 'unassigned') {
    $filtered_courses = array_filter($filtered_courses, fn($c) => !$c['lecturer_id']);
}

$page_title = 'Course Allocation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Allocation - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .lecturer-card { transition: all 0.2s; cursor: pointer; }
        .lecturer-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .lecturer-card.selected { border-color: #0d6efd; background: #f0f7ff; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-person-gear me-2"></i>Course Allocation</h1>
                <p class="text-muted mb-0">Allocate courses to lecturers</p>
            </div>
            <a href="manage_courses.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Courses
            </a>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Lecturers Panel -->
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-people me-2"></i>Lecturers (<?= count($lecturers) ?>)</h6>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php foreach ($lecturers as $l): ?>
                        <div class="card mb-2 lecturer-card" data-lecturer-id="<?= $l['lecturer_id'] ?>">
                            <div class="card-body py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?= htmlspecialchars($l['full_name']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars($l['email'] ?? '') ?></div>
                                    </div>
                                    <span class="badge bg-primary"><?= $l['course_count'] ?> courses</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Courses Panel -->
            <div class="col-md-8">
                <!-- Filters -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <form method="GET" class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <select name="lecturer" class="form-select form-select-sm">
                                    <option value="">All Lecturers</option>
                                    <?php foreach ($lecturers as $l): ?>
                                    <option value="<?= $l['lecturer_id'] ?>" <?= $filter_lecturer == $l['lecturer_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($l['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">All Status</option>
                                    <option value="assigned" <?= $filter_status === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                                    <option value="unassigned" <?= $filter_status === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                                <a href="course_allocation.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Bulk Allocation -->
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="action" value="bulk_allocate">
                    <div class="card mb-3">
                        <div class="card-body py-2 d-flex align-items-center gap-3">
                            <span class="small">Bulk Allocate:</span>
                            <select name="lecturer_id" class="form-select form-select-sm" style="width: 200px;" required>
                                <option value="">Select Lecturer</option>
                                <?php foreach ($lecturers as $l): ?>
                                <option value="<?= $l['lecturer_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Allocate selected courses?')">
                                <i class="bi bi-check-lg me-1"></i>Allocate Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll()">Select All</button>
                        </div>
                    </div>
                    
                    <!-- Courses Table -->
                    <div class="card">
                        <div class="card-header bg-white">
                            <h6 class="mb-0">Courses (<?= count($filtered_courses) ?>)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleAll()"></th>
                                            <th>Code</th>
                                            <th>Course Name</th>
                                            <th>Current Lecturer</th>
                                            <th>Change</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filtered_courses as $c): ?>
                                        <tr>
                                            <td><input type="checkbox" name="course_ids[]" value="<?= $c['course_id'] ?>" class="course-checkbox"></td>
                                            <td><code><?= htmlspecialchars($c['course_code']) ?></code></td>
                                            <td>
                                                <?= htmlspecialchars($c['course_name']) ?>
                                                <div class="small text-muted"><?= $c['enrolled_count'] ?> students</div>
                                            </td>
                                            <td>
                                                <?php if ($c['lecturer_name']): ?>
                                                <span class="text-success"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($c['lecturer_name']) ?></span>
                                                <?php else: ?>
                                                <span class="text-danger"><i class="bi bi-person-x me-1"></i>Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="allocate">
                                                    <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                                                    <select name="lecturer_id" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                                                        <option value="">-- Remove --</option>
                                                        <?php foreach ($lecturers as $l): ?>
                                                        <option value="<?= $l['lecturer_id'] ?>" <?= $c['lecturer_id'] == $l['lecturer_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($l['full_name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAll() {
            const checkboxes = document.querySelectorAll('.course-checkbox');
            const selectAll = document.getElementById('selectAll');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
        
        document.querySelectorAll('.lecturer-card').forEach(card => {
            card.addEventListener('click', function() {
                const lecturerId = this.dataset.lecturerId;
                window.location.href = 'course_allocation.php?lecturer=' + lecturerId;
            });
        });
    </script>
</body>
</html>
