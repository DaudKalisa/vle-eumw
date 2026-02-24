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
?>

<body>
    <?php 
    $currentPage = 'manage_faculties';
    $pageTitle = 'Manage Faculties';
    $breadcrumbs = [['title' => 'Faculties']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-building-fill me-2"></i>Manage Faculties</h1>
            <p class="text-muted mb-0">Manage university faculties and their departments</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert vle-alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert vle-alert-error alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Upload from Template Section -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-file-earmark-arrow-up"></i> Bulk Upload Faculties</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <form method="POST" enctype="multipart/form-data" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label for="template_file" class="form-label">Upload CSV File</label>
                                <input type="file" class="form-control" id="template_file" name="template_file" 
                                       accept=".csv" required>
                                <small class="text-muted">Upload a CSV file with columns: Faculty Code, Faculty Name, Head of Faculty</small>
                            </div>
                            <div>
                                <button type="submit" name="upload_template" class="btn btn-success">
                                    <i class="bi bi-upload"></i> Upload
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4 text-end">
                        <label class="form-label">Need a template?</label>
                        <div>
                            <a href="?download_template=1" class="btn btn-outline-success">
                                <i class="bi bi-download"></i> Download CSV Template
                            </a>
                        </div>
                        <small class="text-muted">Download a sample CSV file with example data</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Faculty Form -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Faculty</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label for="faculty_code" class="form-label">Faculty Code *</label>
                            <input type="text" class="form-control" id="faculty_code" name="faculty_code" 
                                   placeholder="e.g., FICT" maxlength="20" style="text-transform: uppercase;" required>
                            <small class="text-muted">Max 20 characters</small>
                        </div>
                        <div class="col-md-5">
                            <label for="faculty_name" class="form-label">Faculty Name *</label>
                            <input type="text" class="form-control" id="faculty_name" name="faculty_name" 
                                   placeholder="e.g., Faculty of ICT" required>
                        </div>
                        <div class="col-md-3">
                            <label for="head_of_faculty" class="form-label">Head of Faculty</label>
                            <input type="text" class="form-control" id="head_of_faculty" name="head_of_faculty" 
                                   placeholder="e.g., Dr. John Doe">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="add_faculty" class="btn btn-info w-100">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Faculties List -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Faculties (<?php echo count($faculties); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($faculties)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No faculties found. Add your first faculty above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Faculty Code</th>
                                    <th>Faculty Name</th>
                                    <th>Head of Faculty</th>
                                    <th>Programs/Departments</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faculties as $faculty): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($faculty['faculty_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($faculty['faculty_name']); ?></td>
                                        <td>
                                            <?php if (!empty($faculty['head_of_faculty'])): ?>
                                                <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($faculty['head_of_faculty']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-building"></i> <?php echo $faculty['department_count']; ?> department(s)
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?php echo date('M d, Y', strtotime($faculty['created_at'])); ?></small></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" 
                                                    data-bs-target="#departmentsModal<?php echo $faculty['faculty_id']; ?>"
                                                    title="Manage Departments">
                                                <i class="bi bi-building"></i> Departments
                                            </button>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#editModal<?php echo $faculty['faculty_id']; ?>"
                                                    title="Edit Faculty">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal<?php echo $faculty['faculty_id']; ?>"
                                                    title="Delete Faculty">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Manage Departments Modal -->
                                    <div class="modal fade" id="departmentsModal<?php echo $faculty['faculty_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header bg-success text-white">
                                                    <h5 class="modal-title">
                                                        <i class="bi bi-building"></i> Manage Departments - 
                                                        <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                                    </h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <!-- Add New Department Form -->
                                                    <div class="card mb-3">
                                                        <div class="card-header bg-info text-white">
                                                            <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Department</h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <form method="POST">
                                                                <input type="hidden" name="faculty_id" value="<?php echo $faculty['faculty_id']; ?>">
                                                                <div class="row g-2">
                                                                    <div class="col-md-3">
                                                                        <input type="text" class="form-control" name="department_code" 
                                                                               placeholder="Code (e.g., CS)" maxlength="10" 
                                                                               style="text-transform: uppercase;" required>
                                                                    </div>
                                                                    <div class="col-md-7">
                                                                        <input type="text" class="form-control" name="department_name" 
                                                                               placeholder="Department/Program Name" required>
                                                                    </div>
                                                                    <div class="col-md-2">
                                                                        <button type="submit" name="add_department_to_faculty" class="btn btn-info w-100">
                                                                            <i class="bi bi-plus-lg"></i> Add
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>

                                                    <!-- Current Departments -->
                                                    <div class="card mb-3">
                                                        <div class="card-header bg-primary text-white">
                                                            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Departments in this Faculty</h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <?php 
                                                            $current_depts = $faculty_departments[$faculty['faculty_id']] ?? [];
                                                            if (empty($current_depts)): 
                                                            ?>
                                                                <div class="alert alert-info mb-0">
                                                                    <i class="bi bi-info-circle"></i> No departments assigned to this faculty yet.
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="list-group">
                                                                    <?php foreach ($current_depts as $dept): ?>
                                                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                                                            <div>
                                                                                <span class="badge bg-info me-2"><?php echo htmlspecialchars($dept['department_code']); ?></span>
                                                                                <strong><?php echo htmlspecialchars($dept['department_name']); ?></strong>
                                                                            </div>
                                                                            <form method="POST" style="display:inline;">
                                                                                <input type="hidden" name="department_id" value="<?php echo $dept['department_id']; ?>">
                                                                                <button type="submit" name="remove_department_from_faculty" 
                                                                                        class="btn btn-sm btn-outline-danger"
                                                                                        onclick="return confirm('Remove this department from the faculty?');">
                                                                                    <i class="bi bi-x-circle"></i> Remove
                                                                                </button>
                                                                            </form>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Assign Existing Departments -->
                                                    <?php if (!empty($unassigned_departments)): ?>
                                                        <div class="card">
                                                            <div class="card-header bg-warning">
                                                                <h6 class="mb-0"><i class="bi bi-arrow-down-circle"></i> Assign Existing Departments</h6>
                                                            </div>
                                                            <div class="card-body">
                                                                <p class="text-muted small mb-2">These departments are not assigned to any faculty:</p>
                                                                <div class="list-group">
                                                                    <?php foreach ($unassigned_departments as $dept): ?>
                                                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                                                            <div>
                                                                                <span class="badge bg-secondary me-2"><?php echo htmlspecialchars($dept['department_code']); ?></span>
                                                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                                                            </div>
                                                                            <form method="POST" style="display:inline;">
                                                                                <input type="hidden" name="department_id" value="<?php echo $dept['department_id']; ?>">
                                                                                <input type="hidden" name="faculty_id" value="<?php echo $faculty['faculty_id']; ?>">
                                                                                <button type="submit" name="assign_department" class="btn btn-sm btn-success">
                                                                                    <i class="bi bi-plus-circle"></i> Assign to Faculty
                                                                                </button>
                                                                            </form>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                        <i class="bi bi-x-circle"></i> Close
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Edit Modal -->
                                    <div class="modal fade" id="editModal<?php echo $faculty['faculty_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title"><i class="bi bi-pencil-fill"></i> Edit Faculty</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="faculty_id" value="<?php echo $faculty['faculty_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">Faculty Code *</label>
                                                            <input type="text" class="form-control" name="faculty_code" 
                                                                   value="<?php echo htmlspecialchars($faculty['faculty_code']); ?>" 
                                                                   maxlength="20" style="text-transform: uppercase;" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Faculty Name *</label>
                                                            <input type="text" class="form-control" name="faculty_name" 
                                                                   value="<?php echo htmlspecialchars($faculty['faculty_name']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Head of Faculty</label>
                                                            <input type="text" class="form-control" name="head_of_faculty" 
                                                                   value="<?php echo htmlspecialchars($faculty['head_of_faculty'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                        <button type="submit" name="update_faculty" class="btn btn-primary">
                                                            <i class="bi bi-check-circle"></i> Update Faculty
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Delete Modal -->
                                    <div class="modal fade" id="deleteModal<?php echo $faculty['faculty_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title"><i class="bi bi-trash-fill"></i> Confirm Delete</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="faculty_id" value="<?php echo $faculty['faculty_id']; ?>">
                                                        <p>Are you sure you want to delete the faculty:</p>
                                                        <p class="fw-bold"><?php echo htmlspecialchars($faculty['faculty_code']); ?> - <?php echo htmlspecialchars($faculty['faculty_name']); ?></p>
                                                        <?php if ($faculty['department_count'] > 0): ?>
                                                            <div class="alert alert-warning">
                                                                <i class="bi bi-exclamation-triangle"></i> This faculty has <?php echo $faculty['department_count']; ?> department(s). 
                                                                They will be unassigned if you delete this faculty.
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                        <button type="submit" name="delete_faculty" class="btn btn-danger">
                                                            <i class="bi bi-trash-fill"></i> Delete Faculty
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
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
