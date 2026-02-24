<?php
// manage_programs.php - Admin manage programs of study
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_program'])) {
        $program_code = strtoupper(trim($_POST['program_code']));
        $program_name = trim($_POST['program_name']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $program_type = trim($_POST['program_type']);
        $duration_years = (int)$_POST['duration_years'];
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("INSERT INTO programs (program_code, program_name, department_id, program_type, duration_years, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssis", $program_code, $program_name, $department_id, $program_type, $duration_years, $description);
        
        if ($stmt->execute()) {
            $success = "Program added successfully!";
        } else {
            $error = "Failed to add program. Code might already exist.";
        }
    } elseif (isset($_POST['update_program'])) {
        $program_id = (int)$_POST['program_id'];
        $program_code = strtoupper(trim($_POST['program_code']));
        $program_name = trim($_POST['program_name']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $program_type = trim($_POST['program_type']);
        $duration_years = (int)$_POST['duration_years'];
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("UPDATE programs SET program_code = ?, program_name = ?, department_id = ?, program_type = ?, duration_years = ?, description = ? WHERE program_id = ?");
        $stmt->bind_param("ssssisi", $program_code, $program_name, $department_id, $program_type, $duration_years, $description, $program_id);
        
        if ($stmt->execute()) {
            $success = "Program updated successfully!";
        } else {
            $error = "Failed to update program.";
        }
    } elseif (isset($_POST['delete_program'])) {
        $program_id = (int)$_POST['program_id'];

        $stmt = $conn->prepare("DELETE FROM programs WHERE program_id = ?");
        $stmt->bind_param("i", $program_id);
        
        if ($stmt->execute()) {
            $success = "Program deleted successfully!";
        } else {
            $error = "Failed to delete program. It might be in use.";
        }
    } elseif (isset($_POST['toggle_status'])) {
        $program_id = (int)$_POST['program_id'];
        $is_active = (int)$_POST['is_active'];
        $new_status = $is_active ? 0 : 1;

        $stmt = $conn->prepare("UPDATE programs SET is_active = ? WHERE program_id = ?");
        $stmt->bind_param("ii", $new_status, $program_id);
        
        if ($stmt->execute()) {
            $success = $new_status ? "Program activated successfully!" : "Program deactivated successfully!";
        } else {
            $error = "Failed to update program status.";
        }
    }
}

// Get all departments for dropdown
$departments = [];
$result = $conn->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

// Get all programs with department info
$programs = [];
$result = $conn->query("
    SELECT p.*, d.department_name, d.department_code
    FROM programs p
    LEFT JOIN departments d ON p.department_id = d.department_id
    ORDER BY p.program_name
");
while ($row = $result->fetch_assoc()) {
    $programs[] = $row;
}

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as total FROM programs");
$stats['total'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as active FROM programs WHERE is_active = 1");
$stats['active'] = $result->fetch_assoc()['active'];

$result = $conn->query("SELECT program_type, COUNT(*) as count FROM programs GROUP BY program_type");
$stats['by_type'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['by_type'][$row['program_type']] = $row['count'];
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Programs - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'manage_programs';
    $pageTitle = 'Manage Programs';
    $breadcrumbs = [['title' => 'Programs']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-mortarboard-fill me-2"></i>Manage Programs of Study</h1>
                    <p class="text-muted mb-0">Add and manage academic programs</p>
                </div>
                <button type="button" class="btn btn-vle-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                    <i class="bi bi-plus-circle"></i> Add New Program
                </button>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert vle-alert-error alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card vle-card border-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-mortarboard text-primary" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?php echo $stats['total']; ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle text-success" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?php echo $stats['active']; ?></h3>
                        <p class="text-muted mb-0">Active Programs</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-award text-info" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?php echo $stats['by_type']['degree'] ?? 0; ?></h3>
                        <p class="text-muted mb-0">Degree Programs</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-star text-warning" style="font-size: 2.5rem;"></i>
                        <h3 class="mt-2"><?php echo ($stats['by_type']['masters'] ?? 0) + ($stats['by_type']['doctorate'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Postgraduate</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Programs Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Programs</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Program Name</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($programs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No programs found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($programs as $program): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($program['program_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                        <td>
                                            <?php if ($program['department_name']): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($program['department_code']); ?></span>
                                                <?php echo htmlspecialchars($program['department_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $type_badges = [
                                                'degree' => 'primary',
                                                'professional' => 'info',
                                                'masters' => 'warning',
                                                'doctorate' => 'danger'
                                            ];
                                            $badge_class = $type_badges[$program['program_type']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>">
                                                <?php echo ucfirst($program['program_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $program['duration_years']; ?> years</td>
                                        <td>
                                            <?php if ($program['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editProgramModal<?php echo $program['program_id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                                <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $program['is_active']; ?>">
                                                <button type="submit" name="toggle_status" class="btn btn-sm btn-warning">
                                                    <i class="bi bi-toggle-<?php echo $program['is_active'] ? 'on' : 'off'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this program?');">
                                                <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                                <button type="submit" name="delete_program" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Edit Modal for each program -->
                                    <div class="modal fade" id="editProgramModal<?php echo $program['program_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Program</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="program_id" value="<?php echo $program['program_id']; ?>">
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Program Code</label>
                                                            <input type="text" name="program_code" class="form-control" 
                                                                   value="<?php echo htmlspecialchars($program['program_code']); ?>" 
                                                                   required maxlength="10">
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Program Name</label>
                                                            <input type="text" name="program_name" class="form-control" 
                                                                   value="<?php echo htmlspecialchars($program['program_name']); ?>" 
                                                                   required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Department</label>
                                                            <select name="department_id" class="form-select">
                                                                <option value="">-- No Department --</option>
                                                                <?php foreach ($departments as $dept): ?>
                                                                    <option value="<?php echo $dept['department_id']; ?>" 
                                                                            <?php echo ($program['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($dept['department_code'] . ' - ' . $dept['department_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Program Type</label>
                                                            <select name="program_type" class="form-select" required>
                                                                <option value="degree" <?php echo $program['program_type'] == 'degree' ? 'selected' : ''; ?>>Degree</option>
                                                                <option value="professional" <?php echo $program['program_type'] == 'professional' ? 'selected' : ''; ?>>Professional</option>
                                                                <option value="masters" <?php echo $program['program_type'] == 'masters' ? 'selected' : ''; ?>>Masters</option>
                                                                <option value="doctorate" <?php echo $program['program_type'] == 'doctorate' ? 'selected' : ''; ?>>Doctorate</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Duration (Years)</label>
                                                            <input type="number" name="duration_years" class="form-control" 
                                                                   value="<?php echo $program['duration_years']; ?>" 
                                                                   min="1" max="10" required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Description</label>
                                                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($program['description'] ?? ''); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_program" class="btn btn-primary">Update Program</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Program Code</label>
                            <input type="text" name="program_code" class="form-control" 
                                   placeholder="e.g., BSC-CS" required maxlength="10">
                            <small class="text-muted">Short unique code for the program</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Program Name</label>
                            <input type="text" name="program_name" class="form-control" 
                                   placeholder="e.g., Bachelor of Science in Computer Science" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">-- No Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_code'] . ' - ' . $dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Program Type</label>
                            <select name="program_type" class="form-select" required>
                                <option value="degree">Degree</option>
                                <option value="professional">Professional</option>
                                <option value="masters">Masters</option>
                                <option value="doctorate">Doctorate</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Duration (Years)</label>
                            <input type="number" name="duration_years" class="form-control" 
                                   value="4" min="1" max="10" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description (Optional)</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Brief description of the program"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_program" class="btn btn-primary">Add Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
