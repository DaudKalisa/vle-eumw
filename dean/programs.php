<?php
/**
 * Dean Portal - Programs
 * View academic programs in the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$dean_faculty_id = $user['related_dean_id'] ?? null;
$success_message = '';
$error_message = '';

// Handle delete program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_program'])) {
    $program_id = (int)$_POST['program_id'];
    
    // Check if any students are enrolled in this program
    $check_stmt = $conn->prepare("SELECT p.program_code, p.program_name FROM programs p WHERE p.program_id = ?");
    $check_stmt->bind_param("i", $program_id);
    $check_stmt->execute();
    $program_info = $check_stmt->get_result()->fetch_assoc();
    
    if (!$program_info) {
        $error_message = "Program not found.";
    } else {
        // Check for students using this program
        $student_check = $conn->prepare("SELECT COUNT(*) as cnt FROM students WHERE program = ? OR program = ?");
        $student_check->bind_param("ss", $program_info['program_code'], $program_info['program_name']);
        $student_check->execute();
        $student_count = $student_check->get_result()->fetch_assoc()['cnt'];
        
        if ($student_count > 0) {
            $error_message = "Cannot delete program '" . htmlspecialchars($program_info['program_name']) . "' — $student_count student(s) are still enrolled in it. Reassign or remove them first.";
        } else {
            $del_stmt = $conn->prepare("DELETE FROM programs WHERE program_id = ?");
            $del_stmt->bind_param("i", $program_id);
            if ($del_stmt->execute()) {
                $success_message = "Program '" . htmlspecialchars($program_info['program_name']) . "' has been permanently deleted.";
            } else {
                $error_message = "Failed to delete program: " . $conn->error;
            }
        }
    }
}

// Check if programs has department_id column
$has_dept_col = $conn->query("SHOW COLUMNS FROM programs LIKE 'department_id'");
$has_program_dept = ($has_dept_col && $has_dept_col->num_rows > 0);

// Get programs
if ($has_program_dept) {
    $sql = "SELECT p.*, d.department_name, d.department_code
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.department_id";
    if ($dean_faculty_id) {
        $sql .= " WHERE d.faculty_id = $dean_faculty_id";
    }
} else {
    $sql = "SELECT p.* FROM programs p";
}

$sql .= " ORDER BY p.program_name";

$result = $conn->query($sql);
$programs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Stats by type
$type_stats = [];
foreach ($programs as $p) {
    $type = $p['program_type'] ?? 'other';
    $type_stats[$type] = ($type_stats[$type] ?? 0) + 1;
}

$page_title = "Programs";
$breadcrumbs = [['title' => 'Programs']];
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
        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-primary"><?= count($programs) ?></div>
                        <small class="text-muted">Total Programs</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success"><?= $type_stats['degree'] ?? 0 ?></div>
                        <small class="text-muted">Degree Programs</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= $type_stats['masters'] ?? 0 ?></div>
                        <small class="text-muted">Masters Programs</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-warning"><?= ($type_stats['professional'] ?? 0) + ($type_stats['doctorate'] ?? 0) ?></div>
                        <small class="text-muted">Professional/Doctorate</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Academic Programs</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Program Name</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th width="80">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($programs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No programs found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($programs as $program): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($program['program_code']) ?></code></td>
                                <td><strong><?= htmlspecialchars($program['program_name']) ?></strong></td>
                                <td><?= htmlspecialchars($program['department_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php
                                    $type_badges = [
                                        'degree' => 'primary',
                                        'professional' => 'info',
                                        'masters' => 'success',
                                        'doctorate' => 'warning'
                                    ];
                                    $badge = $type_badges[$program['program_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $badge ?>"><?= ucfirst($program['program_type'] ?? 'N/A') ?></span>
                                </td>
                                <td><?= $program['duration_years'] ?? 'N/A' ?> years</td>
                                <td>
                                    <?php if (($program['is_active'] ?? 1) == 1): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="confirmDelete(<?= $program['program_id'] ?>, '<?= htmlspecialchars(addslashes($program['program_name']), ENT_QUOTES) ?>')" 
                                            title="Delete permanently">
                                        <i class="bi bi-trash"></i>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to <strong>permanently delete</strong> the program:</p>
                    <p class="fw-bold text-danger" id="deleteProgramName"></p>
                    <p class="text-muted small">This action cannot be undone. The program will be removed from the database entirely.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="program_id" id="deleteProgramId">
                        <button type="submit" name="delete_program" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Delete Permanently
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function confirmDelete(programId, programName) {
        document.getElementById('deleteProgramId').value = programId;
        document.getElementById('deleteProgramName').textContent = programName;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
    </script>
</body>
</html>
