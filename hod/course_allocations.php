<?php
/**
 * HOD - Course Allocations
 * Assign lecturers to department courses
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

$success_msg = '';
$error_msg = '';

// Handle allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_course'])) {
    $course_id = intval($_POST['course_id'] ?? 0);
    $lecturer_id = intval($_POST['lecturer_id'] ?? 0);

    if ($course_id && $lecturer_id) {
        // Verify course is in department
        $check = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_id = ? AND program_of_study LIKE ?");
        $dept_like = '%' . $hod_department . '%';
        $check->bind_param("is", $course_id, $dept_like);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $upd = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
            $upd->bind_param("ii", $lecturer_id, $course_id);
            if ($upd->execute()) {
                $success_msg = "Lecturer assigned to course successfully.";
            } else {
                $error_msg = "Failed to assign lecturer.";
            }
        } else {
            $error_msg = "Course not found or not in your department.";
        }
    } else {
        $error_msg = "Please select both a course and a lecturer.";
    }
}

// Handle deallocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deallocate_course'])) {
    $course_id = intval($_POST['course_id'] ?? 0);
    if ($course_id) {
        $upd = $conn->prepare("UPDATE vle_courses SET lecturer_id = NULL WHERE course_id = ? AND program_of_study LIKE ?");
        $dept_like = '%' . $hod_department . '%';
        $upd->bind_param("is", $course_id, $dept_like);
        if ($upd->execute() && $upd->affected_rows > 0) {
            $success_msg = "Lecturer removed from course.";
        } else {
            $error_msg = "Failed to remove allocation.";
        }
    }
}

// Get department courses
$courses = [];
if ($hod_department) {
    $stmt = $conn->prepare("SELECT c.*, l.full_name as lecturer_name 
                            FROM vle_courses c 
                            LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                            WHERE c.program_of_study LIKE ? 
                            ORDER BY c.course_code");
    $dept_like = '%' . $hod_department . '%';
    $stmt->bind_param("s", $dept_like);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get department lecturers
$lecturers = [];
if ($hod_department) {
    $stmt = $conn->prepare("SELECT lecturer_id, full_name FROM lecturers WHERE department LIKE ? AND is_active = 1 ORDER BY full_name");
    $dept_like = '%' . $hod_department . '%';
    $stmt->bind_param("s", $dept_like);
    $stmt->execute();
    $lecturers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$unassigned = array_filter($courses, fn($c) => empty($c['lecturer_id']));
$assigned = array_filter($courses, fn($c) => !empty($c['lecturer_id']));

$page_title = "Course Allocations";
$breadcrumbs = [['title' => 'Allocations']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Allocations - HOD Portal</title>
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
                <h2 class="vle-page-title"><i class="bi bi-diagram-3 me-2 text-warning"></i>Course Allocations</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($hod_department ?: 'All Departments') ?> — Assign lecturers to courses</p>
            </div>
        </div>

        <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Summary -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-primary"><?= count($courses) ?></span>
                    <small class="text-muted">Total Courses</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-success"><?= count($assigned) ?></span>
                    <small class="text-muted">Assigned</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <span class="display-6 fw-bold text-danger"><?= count($unassigned) ?></span>
                    <small class="text-muted">Unassigned</small>
                </div>
            </div>
        </div>

        <!-- Allocate Form -->
        <?php if (!empty($unassigned) && !empty($lecturers)): ?>
        <div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
            <div class="card-header bg-warning bg-opacity-10">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Assign Lecturer to Course</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="allocate_course" value="1">
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Unassigned Course</label>
                        <select name="course_id" class="form-select" required>
                            <option value="">Select a course...</option>
                            <?php foreach ($unassigned as $c): ?>
                            <option value="<?= $c['course_id'] ?>"><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Lecturer</label>
                        <select name="lecturer_id" class="form-select" required>
                            <option value="">Select a lecturer...</option>
                            <?php foreach ($lecturers as $l): ?>
                            <option value="<?= $l['lecturer_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-warning w-100"><i class="bi bi-link-45deg me-1"></i>Assign</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current Allocations -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Current Allocations</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-diagram-3 display-4 d-block mb-3"></i>
                    <p>No courses found.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Year / Semester</th>
                                <th>Assigned Lecturer</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $c): ?>
                            <tr class="<?= empty($c['lecturer_id']) ? 'table-warning' : '' ?>">
                                <td><code class="fw-bold"><?= htmlspecialchars($c['course_code']) ?></code></td>
                                <td><?= htmlspecialchars($c['course_name']) ?></td>
                                <td>
                                    <span class="badge bg-secondary">Y<?= $c['year_of_study'] ?></span>
                                    <span class="badge bg-info"><?= $c['semester'] === 'Both' ? 'Sem 1 & 2' : 'Sem ' . ($c['semester'] === 'Two' ? '2' : '1') ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($c['lecturer_name'])): ?>
                                    <span class="text-success fw-bold"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($c['lecturer_name']) ?></span>
                                    <?php else: ?>
                                    <span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c['lecturer_id'])): ?>
                                    <!-- Change Lecturer -->
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#changeModal<?= $c['course_id'] ?>">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </button>
                                    <!-- Remove -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove lecturer from this course?')">
                                        <input type="hidden" name="deallocate_course" value="1">
                                        <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
                                    </form>

                                    <!-- Change Modal -->
                                    <div class="modal fade" id="changeModal<?= $c['course_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="allocate_course" value="1">
                                                    <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Change Lecturer - <?= htmlspecialchars($c['course_code']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-muted">Currently: <strong><?= htmlspecialchars($c['lecturer_name']) ?></strong></p>
                                                        <label class="form-label fw-bold">New Lecturer</label>
                                                        <select name="lecturer_id" class="form-select" required>
                                                            <?php foreach ($lecturers as $l): ?>
                                                            <option value="<?= $l['lecturer_id'] ?>" <?= $l['lecturer_id'] == $c['lecturer_id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($l['full_name']) ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Update</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <!-- Quick assign for unassigned -->
                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#assignModal<?= $c['course_id'] ?>">
                                        <i class="bi bi-link-45deg"></i> Assign
                                    </button>
                                    <div class="modal fade" id="assignModal<?= $c['course_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="allocate_course" value="1">
                                                    <input type="hidden" name="course_id" value="<?= $c['course_id'] ?>">
                                                    <div class="modal-header bg-warning bg-opacity-10">
                                                        <h5 class="modal-title">Assign Lecturer - <?= htmlspecialchars($c['course_code']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label class="form-label fw-bold">Select Lecturer</label>
                                                        <select name="lecturer_id" class="form-select" required>
                                                            <option value="">Choose...</option>
                                                            <?php foreach ($lecturers as $l): ?>
                                                            <option value="<?= $l['lecturer_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-warning">Assign</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
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

        <!-- Lecturer Workload -->
        <?php if (!empty($lecturers)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Lecturer Workload</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($lecturers as $l):
                        $lec_courses = array_filter($courses, fn($c) => $c['lecturer_id'] == $l['lecturer_id']);
                        $count = count($lec_courses);
                        $bar_pct = min(100, $count * 20); // 5 courses = 100%
                        $bar_color = $count == 0 ? 'secondary' : ($count <= 2 ? 'success' : ($count <= 4 ? 'warning' : 'danger'));
                    ?>
                    <div class="col-md-6 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong><?= htmlspecialchars($l['full_name']) ?></strong>
                            <span class="badge bg-<?= $bar_color ?>"><?= $count ?> course(s)</span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-<?= $bar_color ?>" style="width:<?= $bar_pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
