<?php
// manage_faculties.php - Admin manage faculties
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="faculties_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Faculty Code', 'Faculty Name', 'Head of Faculty']);
    
    // Sample rows
    fputcsv($output, ['FICT', 'Faculty of Information and Communication Technology', 'Dr. John Smith']);
    fputcsv($output, ['FBM', 'Faculty of Business Management', 'Prof. Sarah Johnson']);
    fputcsv($output, ['FED', 'Faculty of Education', 'Dr. Michael Brown']);
    
    fclose($output);
    exit();
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_template'])) {
        if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['template_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['template_file']['name'], PATHINFO_EXTENSION));
            
            if ($file_ext !== 'csv') {
                $error = "Only CSV files are allowed.";
            } else {
                $uploaded = 0;
                $skipped = 0;
                $errors = [];
                
                if (($handle = fopen($file_tmp, 'r')) !== false) {
                    // Skip header row
                    $header = fgetcsv($handle);
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) < 2) {
                            $skipped++;
                            continue;
                        }
                        
                        $faculty_code = strtoupper(trim($data[0]));
                        $faculty_name = trim($data[1]);
                        $head_of_faculty = isset($data[2]) ? trim($data[2]) : null;
                        
                        // Validate
                        if (empty($faculty_code) || empty($faculty_name)) {
                            $skipped++;
                            continue;
                        }
                        
                        if (strlen($faculty_code) > 20) {
                            $errors[] = "Code '$faculty_code' exceeds 20 characters";
                            $skipped++;
                            continue;
                        }
                        
                        // Insert
                        $stmt = $conn->prepare("INSERT INTO faculties (faculty_code, faculty_name, head_of_faculty) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $faculty_code, $faculty_name, $head_of_faculty);
                        
                        if ($stmt->execute()) {
                            $uploaded++;
                        } else {
                            $skipped++;
                            $errors[] = "Failed to add faculty '$faculty_code' (might already exist)";
                        }
                    }
                    
                    fclose($handle);
                    
                    $success = "Upload complete! $uploaded faculty/faculties added, $skipped skipped.";
                    if (!empty($errors)) {
                        $error = implode('<br>', array_slice($errors, 0, 5));
                        if (count($errors) > 5) {
                            $error .= '<br>... and ' . (count($errors) - 5) . ' more errors.';
                        }
                    }
                } else {
                    $error = "Failed to read CSV file.";
                }
            }
        } else {
            $error = "Please select a CSV file to upload.";
        }
    } elseif (isset($_POST['add_faculty'])) {
        $faculty_code = strtoupper(trim($_POST['faculty_code']));
        $faculty_name = trim($_POST['faculty_name']);
        $head_of_faculty = trim($_POST['head_of_faculty']);

        $stmt = $conn->prepare("INSERT INTO faculties (faculty_code, faculty_name, head_of_faculty) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $faculty_code, $faculty_name, $head_of_faculty);
        
        if ($stmt->execute()) {
            $success = "Faculty added successfully!";
        } else {
            $error = "Failed to add faculty. Code might already exist.";
        }
    } elseif (isset($_POST['update_faculty'])) {
        $faculty_id = (int)$_POST['faculty_id'];
        $faculty_code = strtoupper(trim($_POST['faculty_code']));
        $faculty_name = trim($_POST['faculty_name']);
        $head_of_faculty = trim($_POST['head_of_faculty']);

        $stmt = $conn->prepare("UPDATE faculties SET faculty_code = ?, faculty_name = ?, head_of_faculty = ? WHERE faculty_id = ?");
        $stmt->bind_param("sssi", $faculty_code, $faculty_name, $head_of_faculty, $faculty_id);
        
        if ($stmt->execute()) {
            $success = "Faculty updated successfully!";
        } else {
            $error = "Failed to update faculty.";
        }
    } elseif (isset($_POST['delete_faculty'])) {
        $faculty_id = (int)$_POST['faculty_id'];

        $stmt = $conn->prepare("DELETE FROM faculties WHERE faculty_id = ?");
        $stmt->bind_param("i", $faculty_id);
        
        if ($stmt->execute()) {
            $success = "Faculty deleted successfully!";
        } else {
            $error = "Failed to delete faculty. It may be associated with departments.";
        }
    } elseif (isset($_POST['add_department_to_faculty'])) {
        $faculty_id = (int)$_POST['faculty_id'];
        $department_code = strtoupper(trim($_POST['department_code']));
        $department_name = trim($_POST['department_name']);
        
        // Check if department code already exists
        $check_stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_code = ?");
        $check_stmt->bind_param("s", $department_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Department code '$department_code' already exists. Please use a different code.";
        } else {
            $stmt = $conn->prepare("INSERT INTO departments (department_code, department_name, faculty_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $department_code, $department_name, $faculty_id);
            
            if ($stmt->execute()) {
                $success = "Department added to faculty successfully!";
            } else {
                $error = "Failed to add department: " . $conn->error;
            }
        }
    } elseif (isset($_POST['assign_department'])) {
        $department_id = (int)$_POST['department_id'];
        $faculty_id = (int)$_POST['faculty_id'];

        $stmt = $conn->prepare("UPDATE departments SET faculty_id = ? WHERE department_id = ?");
        $stmt->bind_param("ii", $faculty_id, $department_id);
        
        if ($stmt->execute()) {
            $success = "Department assigned to faculty successfully!";
        } else {
            $error = "Failed to assign department.";
        }
    } elseif (isset($_POST['remove_department_from_faculty'])) {
        $department_id = (int)$_POST['department_id'];

        $stmt = $conn->prepare("UPDATE departments SET faculty_id = NULL WHERE department_id = ?");
        $stmt->bind_param("i", $department_id);
        
        if ($stmt->execute()) {
            $success = "Department removed from faculty successfully!";
        } else {
            $error = "Failed to remove department.";
        }
    }
}

// Get all faculties with department count
$faculties = [];
$result = $conn->query("
    SELECT f.*, 
           COUNT(d.department_id) as department_count
    FROM faculties f
    LEFT JOIN departments d ON f.faculty_id = d.faculty_id
    GROUP BY f.faculty_id
    ORDER BY f.faculty_name
");
while ($row = $result->fetch_assoc()) {
    $faculties[] = $row;
}

// Get all departments for each faculty
$faculty_departments = [];
$result = $conn->query("
    SELECT d.*, f.faculty_id
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    ORDER BY d.department_name
");
while ($row = $result->fetch_assoc()) {
    $fid = $row['faculty_id'] ?? 'unassigned';
    if (!isset($faculty_departments[$fid])) {
        $faculty_departments[$fid] = [];
    }
    $faculty_departments[$fid][] = $row;
}

// Get unassigned departments
$unassigned_departments = [];
$result = $conn->query("SELECT * FROM departments WHERE faculty_id IS NULL ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $unassigned_departments[] = $row;
}

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()

// Calculate statistics
$total_faculties = count($faculties);
$total_departments_assigned = 0;
foreach ($faculties as $f) {
    $total_departments_assigned += $f['department_count'];
}
$total_unassigned = count($unassigned_departments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Faculties - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .faculty-card {
            transition: all 0.3s ease;
            border-left: 4px solid #6366f1;
        }
        .faculty-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .dept-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: #e0e7ff;
            color: #4338ca;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .head-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: #d1fae5;
            color: #065f46;
            border-radius: 6px;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'manage_faculties';
    $pageTitle = 'Manage Faculties';
    $breadcrumbs = [['title' => 'Faculties']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-building me-2"></i>Manage Faculties</h2>
                <p class="text-muted mb-0">Organize university faculties and their departments</p>
            </div>
            <div class="d-flex gap-2">
                <a href="?download_template=1" class="btn btn-outline-success">
                    <i class="bi bi-download me-1"></i>CSV Template
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                    <i class="bi bi-plus-circle me-1"></i>Add Faculty
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-primary"><?= $total_faculties ?></div>
                        <small class="text-muted">Total Faculties</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-success"><?= $total_departments_assigned ?></div>
                        <small class="text-muted">Assigned Departments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="display-5 fw-bold text-warning"><?= $total_unassigned ?></div>
                        <small class="text-muted">Unassigned Departments</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Template Section -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-success bg-opacity-10 border-0">
                <h5 class="mb-0 text-success"><i class="bi bi-file-earmark-arrow-up me-2"></i>Bulk Upload Faculties</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="template_file" class="form-label">Upload CSV File</label>
                        <input type="file" class="form-control" id="template_file" name="template_file" accept=".csv" required>
                        <small class="text-muted">Columns: Faculty Code, Faculty Name, Head of Faculty</small>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="upload_template" class="btn btn-success w-100">
                            <i class="bi bi-upload me-1"></i>Upload CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Faculties Directory -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-building me-2"></i>Faculty Directory</h5>
                <span class="badge bg-light text-dark"><?= count($faculties) ?> faculties</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($faculties)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-building display-4 d-block mb-3"></i>
                        <p>No faculties registered yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                            <i class="bi bi-plus-circle me-1"></i>Add First Faculty
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Faculty Name</th>
                                    <th>Head of Faculty</th>
                                    <th>Departments</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faculties as $faculty): ?>
                                <tr>
                                    <td><strong class="text-primary"><?= htmlspecialchars($faculty['faculty_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($faculty['faculty_name']) ?></td>
                                    <td>
                                        <?php if (!empty($faculty['head_of_faculty'])): ?>
                                            <span class="head-badge"><i class="bi bi-person-badge"></i><?= htmlspecialchars($faculty['head_of_faculty']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="dept-badge">
                                            <i class="bi bi-diagram-3"></i><?= $faculty['department_count'] ?> dept(s)
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?= date('M d, Y', strtotime($faculty['created_at'])) ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#departmentsModal<?= $faculty['faculty_id'] ?>" title="Manage Departments">
                                                <i class="bi bi-diagram-3"></i>
                                            </button>
                                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $faculty['faculty_id'] ?>" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $faculty['faculty_id'] ?>" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
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

    <!-- Add Faculty Modal -->
    <div class="modal fade" id="addFacultyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add New Faculty</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Faculty Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_code" placeholder="e.g., FICT" maxlength="20" style="text-transform: uppercase;" required>
                            <small class="text-muted">Max 20 characters</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Faculty Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_name" placeholder="e.g., Faculty of ICT" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Head of Faculty</label>
                            <input type="text" class="form-control" name="head_of_faculty" placeholder="e.g., Dr. John Doe">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_faculty" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i>Add Faculty
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Faculty Modals (Edit, Delete, Departments) -->
    <?php foreach ($faculties as $faculty): ?>
        <!-- Departments Modal -->
        <div class="modal fade" id="departmentsModal<?= $faculty['faculty_id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Manage Departments - <?= htmlspecialchars($faculty['faculty_name']) ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Add New Department -->
                        <div class="card mb-3 border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="bi bi-plus-circle me-2"></i>Add New Department</h6>
                                <form method="POST">
                                    <input type="hidden" name="faculty_id" value="<?= $faculty['faculty_id'] ?>">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="department_code" placeholder="Code (e.g., CS)" maxlength="10" style="text-transform: uppercase;" required>
                                        </div>
                                        <div class="col-md-7">
                                            <input type="text" class="form-control" name="department_name" placeholder="Department Name" required>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" name="add_department_to_faculty" class="btn btn-success w-100">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Current Departments -->
                        <h6 class="mb-3"><i class="bi bi-list-ul me-2"></i>Departments in Faculty</h6>
                        <?php $current_depts = $faculty_departments[$faculty['faculty_id']] ?? []; ?>
                        <?php if (empty($current_depts)): ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>No departments assigned yet.
                            </div>
                        <?php else: ?>
                            <div class="list-group mb-3">
                                <?php foreach ($current_depts as $dept): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-primary me-2"><?= htmlspecialchars($dept['department_code']) ?></span>
                                            <?= htmlspecialchars($dept['department_name']) ?>
                                        </div>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="department_id" value="<?= $dept['department_id'] ?>">
                                            <button type="submit" name="remove_department_from_faculty" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this department?');">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Unassigned Departments -->
                        <?php if (!empty($unassigned_departments)): ?>
                            <h6 class="mb-3"><i class="bi bi-arrow-down-circle me-2"></i>Assign Existing Departments</h6>
                            <div class="list-group">
                                <?php foreach ($unassigned_departments as $dept): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-secondary me-2"><?= htmlspecialchars($dept['department_code']) ?></span>
                                            <?= htmlspecialchars($dept['department_name']) ?>
                                        </div>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="department_id" value="<?= $dept['department_id'] ?>">
                                            <input type="hidden" name="faculty_id" value="<?= $faculty['faculty_id'] ?>">
                                            <button type="submit" name="assign_department" class="btn btn-sm btn-success">
                                                <i class="bi bi-plus-circle me-1"></i>Assign
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal<?= $faculty['faculty_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Faculty</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="faculty_id" value="<?= $faculty['faculty_id'] ?>">
                            <div class="mb-3">
                                <label class="form-label">Faculty Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="faculty_code" value="<?= htmlspecialchars($faculty['faculty_code']) ?>" maxlength="20" style="text-transform: uppercase;" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Faculty Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="faculty_name" value="<?= htmlspecialchars($faculty['faculty_name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Head of Faculty</label>
                                <input type="text" class="form-control" name="head_of_faculty" value="<?= htmlspecialchars($faculty['head_of_faculty'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_faculty" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div class="modal fade" id="deleteModal<?= $faculty['faculty_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Faculty</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="faculty_id" value="<?= $faculty['faculty_id'] ?>">
                            <p>Are you sure you want to delete:</p>
                            <p class="fw-bold text-danger"><?= htmlspecialchars($faculty['faculty_code']) ?> - <?= htmlspecialchars($faculty['faculty_name']) ?></p>
                            <?php if ($faculty['department_count'] > 0): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    This faculty has <strong><?= $faculty['department_count'] ?></strong> department(s) that will be unassigned.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_faculty" class="btn btn-danger">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
