<?php
// manage_departments.php - Admin manage departments
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="programs_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Program Code', 'Program Name']);
    
    // Sample rows
    fputcsv($output, ['CS', 'Bachelors of Computer Science']);
    fputcsv($output, ['IT', 'Bachelors of Information Technology']);
    fputcsv($output, ['BBA', 'Bachelors of Business Administration']);
    
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
                        
                        $department_code = strtoupper(trim($data[0]));
                        $department_name = trim($data[1]);
                        
                        // Validate
                        if (empty($department_code) || empty($department_name)) {
                            $skipped++;
                            continue;
                        }
                        
                        if (strlen($department_code) > 10) {
                            $errors[] = "Code '$department_code' exceeds 10 characters";
                            $skipped++;
                            continue;
                        }
                        
                        // Insert
                        $stmt = $conn->prepare("INSERT INTO departments (department_code, department_name) VALUES (?, ?)");
                        $stmt->bind_param("ss", $department_code, $department_name);
                        
                        if ($stmt->execute()) {
                            $uploaded++;
                        } else {
                            $skipped++;
                            $errors[] = "Failed to add program '$department_code' (might already exist)";
                        }
                    }
                    
                    fclose($handle);
                    
                    $success = "Upload complete! $uploaded program(s) added, $skipped skipped.";
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
    } elseif (isset($_POST['add_department'])) {
        $department_code = strtoupper(trim($_POST['department_code']));
        $department_name = trim($_POST['department_name']);
        $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;

        $stmt = $conn->prepare("INSERT INTO departments (department_code, department_name, faculty_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $department_code, $department_name, $faculty_id);
        
        if ($stmt->execute()) {
            $success = "Department added successfully!";
        } else {
            $error = "Failed to add department. Code might already exist.";
        }
    } elseif (isset($_POST['update_department'])) {
        $department_id = (int)$_POST['department_id'];
        $department_code = strtoupper(trim($_POST['department_code']));
        $department_name = trim($_POST['department_name']);
        $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;

        $stmt = $conn->prepare("UPDATE departments SET department_code = ?, department_name = ?, faculty_id = ? WHERE department_id = ?");
        $stmt->bind_param("ssii", $department_code, $department_name, $faculty_id, $department_id);
        
        if ($stmt->execute()) {
            $success = "Department updated successfully!";
        } else {
            $error = "Failed to update department.";
        }
    } elseif (isset($_POST['delete_department'])) {
        $department_id = (int)$_POST['department_id'];

        $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
        $stmt->bind_param("i", $department_id);
        
        if ($stmt->execute()) {
            $success = "Department deleted successfully!";
        } else {
            $error = "Failed to delete department.";
        }
    }
}

// Get all faculties for dropdown
$faculties = [];
$result = $conn->query("SELECT faculty_id, faculty_code, faculty_name FROM faculties ORDER BY faculty_name");
while ($row = $result->fetch_assoc()) {
    $faculties[] = $row;
}

// Get all departments with faculty info
$departments = [];
$result = $conn->query("
    SELECT d.*, f.faculty_name, f.faculty_code
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    ORDER BY d.department_name
");
while ($row = $result->fetch_assoc()) {
    $departments[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Departments - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand img {
            height: 40px;
            width: auto;
            margin-right: 10px;
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.3;
            position: absolute;
            right: 15px;
            top: 15px;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../pictures/logo.bmp" alt="VLE Logo">
                <span>VLE Admin - Departments</span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link" href="manage_faculties.php"><i class="bi bi-building-fill"></i> Faculties</a>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="bi bi-building text-info"></i> Manage Departments</h2>
                <p class="text-muted mb-0">Programs of Study - Academic Structure Management</p>
            </div>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-info position-relative">
                    <div class="card-body">
                        <i class="bi bi-building stat-icon text-info"></i>
                        <h6 class="text-muted text-uppercase">Total Departments</h6>
                        <h3 class="mb-0 text-info"><?php echo count($departments); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success position-relative">
                    <div class="card-body">
                        <i class="bi bi-check-circle stat-icon text-success"></i>
                        <h6 class="text-muted text-uppercase">With Faculty</h6>
                        <h3 class="mb-0 text-success">
                            <?php echo count(array_filter($departments, function($d) { return !empty($d['faculty_id']); })); ?>
                        </h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-warning position-relative">
                    <div class="card-body">
                        <i class="bi bi-exclamation-circle stat-icon text-warning"></i>
                        <h6 class="text-muted text-uppercase">Unassigned</h6>
                        <h3 class="mb-0 text-warning">
                            <?php echo count(array_filter($departments, function($d) { return empty($d['faculty_id']); })); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload from Template Section -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-file-earmark-arrow-up"></i> Bulk Upload Departments</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <form method="POST" enctype="multipart/form-data" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label for="template_file" class="form-label">Upload CSV File</label>
                                <input type="file" class="form-control" id="template_file" name="template_file" 
                                       accept=".csv" required>
                                <small class="text-muted">Upload a CSV file with columns: Program Code, Program Name</small>
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

        <!-- Add Department Form -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Department</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label for="department_code" class="form-label">
                                <i class="bi bi-tag-fill text-info"></i> Department Code *
                            </label>
                            <input type="text" class="form-control" id="department_code" name="department_code" 
                                   placeholder="e.g., CS" maxlength="10" style="text-transform: uppercase;" required>
                            <small class="text-muted">Max 10 characters</small>
                        </div>
                        <div class="col-md-4">
                            <label for="department_name" class="form-label">
                                <i class="bi bi-bookmark-fill text-info"></i> Department Name *
                            </label>
                            <input type="text" class="form-control" id="department_name" name="department_name" 
                                   placeholder="e.g., Computer Science" required>
                        </div>
                        <div class="col-md-4">
                            <label for="faculty_id" class="form-label">
                                <i class="bi bi-building-fill text-info"></i> Faculty
                            </label>
                            <select class="form-select" id="faculty_id" name="faculty_id">
                                <option value="">-- Select Faculty (Optional) --</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?php echo $faculty['faculty_id']; ?>">
                                        <?php echo htmlspecialchars($faculty['faculty_code']); ?> - <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="add_department" class="btn btn-info w-100">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Departments List -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Departments (<?php echo count($departments); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($departments)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No departments found. Add your first department above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="6%"><i class="bi bi-hash"></i> ID</th>
                                    <th width="12%"><i class="bi bi-tag-fill"></i> Code</th>
                                    <th width="30%"><i class="bi bi-bookmark-fill"></i> Department Name</th>
                                    <th width="28%"><i class="bi bi-building-fill"></i> Faculty</th>
                                    <th width="12%"><i class="bi bi-calendar-fill"></i> Created</th>
                                    <th width="12%"><i class="bi bi-tools"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $dept): ?>
                                    <tr id="dept-row-<?php echo $dept['department_id']; ?>">
                                        <td><?php echo $dept['department_id']; ?></td>
                                        <td>
                                            <span class="badge bg-info fs-6"><?php echo htmlspecialchars($dept['department_code']); ?></span>
                                        </td>
                                        <td id="name-<?php echo $dept['department_id']; ?>">
                                            <a href="manage_courses.php?program=<?php echo urlencode($dept['department_code']); ?>" 
                                               class="text-decoration-none text-primary" 
                                               title="View courses for <?php echo htmlspecialchars($dept['department_name']); ?>">
                                                <i class="bi bi-book"></i> <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($dept['faculty_name'])): ?>
                                                <i class="bi bi-building-fill text-primary"></i>
                                                <strong><?php echo htmlspecialchars($dept['faculty_code']); ?></strong> - 
                                                <?php echo htmlspecialchars($dept['faculty_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="bi bi-dash-circle"></i> No faculty assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('M d, Y', strtotime($dept['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#editModal<?php echo $dept['department_id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal<?php echo $dept['department_id']; ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Edit Modal -->
                                    <div class="modal fade" id="editModal<?php echo $dept['department_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-warning">
                                                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Program of Study</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="department_id" value="<?php echo $dept['department_id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">Program Code *</label>
                                                            <input type="text" class="form-control" name="department_code" 
                                                                   value="<?php echo htmlspecialchars($dept['department_code']); ?>" 
                                                                   maxlength="10" style="text-transform: uppercase;" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Program Name *</label>
                                                            <input type="text" class="form-control" name="department_name" 
                                                                   value="<?php echo htmlspecialchars($dept['department_name']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Faculty</label>
                                                            <select class="form-select" name="faculty_id">
                                                                <option value="">-- No Faculty --</option>
                                                                <?php foreach ($faculties as $faculty): ?>
                                                                    <option value="<?php echo $faculty['faculty_id']; ?>" 
                                                                            <?php echo ($dept['faculty_id'] == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($faculty['faculty_code']); ?> - <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                        <button type="submit" name="update_department" class="btn btn-warning">
                                                            <i class="bi bi-save"></i> Update Program
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Delete Modal -->
                                    <div class="modal fade" id="deleteModal<?php echo $dept['department_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title"><i class="bi bi-trash-fill"></i> Confirm Delete</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="department_id" value="<?php echo $dept['department_id']; ?>">
                                                        <p>Are you sure you want to delete this program of study?</p>
                                                        <p class="fw-bold"><?php echo htmlspecialchars($dept['department_code']); ?> - <?php echo htmlspecialchars($dept['department_name']); ?></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                        <button type="submit" name="delete_department" class="btn btn-danger">
                                                            <i class="bi bi-trash-fill"></i> Delete
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
