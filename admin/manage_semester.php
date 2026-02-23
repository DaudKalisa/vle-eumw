<?php
// manage_semester.php - View and filter modules by semester
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

// Handle semester update
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_semester'])) {
    $module_id = $_POST['module_id'];
    $new_semester = $_POST['new_semester'];
    $new_year = $_POST['new_year'];
    
    $stmt = $conn->prepare("UPDATE modules SET semester = ?, year_of_study = ? WHERE module_id = ?");
    $stmt->bind_param("sii", $new_semester, $new_year, $module_id);
    
    if ($stmt->execute()) {
        $success_message = "Module semester assignment updated successfully!";
    } else {
        $error_message = "Error updating module: " . $conn->error;
    }
    $stmt->close();
}

// Get all modules
$modules = [];
$result = $conn->query("SELECT module_id, module_name, module_code, program_of_study, year_of_study, semester, credits, description FROM modules ORDER BY program_of_study, year_of_study, semester, module_code");
while ($row = $result->fetch_assoc()) {
    $modules[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Semester - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sticky-top-custom {
            position: sticky;
            top: 0;
            z-index: 10;
            background: white;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-speedometer2"></i> Admin Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="bi bi-calendar-check text-success"></i> Manage Semester - Module Overview</h2>
                <p class="text-muted">View, filter, and assign modules to semesters</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label fw-bold"><i class="bi bi-funnel"></i> Filter by Semester</label>
                <select class="form-select form-select-lg" id="semesterFilter">
                    <option value="">All Semesters</option>
                    <option value="One">Semester One</option>
                    <option value="Two">Semester Two</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold"><i class="bi bi-123"></i> Filter by Year of Study</label>
                <select class="form-select form-select-lg" id="yearFilter">
                    <option value="">All Years</option>
                    <option value="1">Year 1</option>
                    <option value="2">Year 2</option>
                    <option value="3">Year 3</option>
                    <option value="4">Year 4</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="bi bi-search"></i> Search by Program</label>
                <input type="text" class="form-control form-control-lg" id="programFilter" placeholder="Type program name...">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold"><i class="bi bi-code-square"></i> Search Module Code</label>
                <input type="text" class="form-control form-control-lg" id="codeFilter" placeholder="e.g., CS101">
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-journal-text text-primary" style="font-size: 2rem;"></i>
                        <h4 class="mt-2" id="totalModules"><?php echo count($modules); ?></h4>
                        <p class="text-muted mb-0">Modules Displayed</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar text-info" style="font-size: 2rem;"></i>
                        <h4 class="mt-2" id="semOneCount">0</h4>
                        <p class="text-muted mb-0">Semester One</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar-check text-success" style="font-size: 2rem;"></i>
                        <h4 class="mt-2" id="semTwoCount">0</h4>
                        <p class="text-muted mb-0">Semester Two</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-folder text-warning" style="font-size: 2rem;"></i>
                        <h4 class="mt-2" id="programCount">0</h4>
                        <p class="text-muted mb-0">Programs</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modules Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white sticky-top-custom">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Modules</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-hover table-striped mb-0" id="modulesTable">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>Module Code</th>
                                <th>Module Name</th>
                                <th>Program of Study</th>
                                <th>Year</th>
                                <th>Semester</th>
                                <th>Credits</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="modulesTableBody">
                            <?php if (empty($modules)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                        <p class="mb-0">No modules found. Add modules in Manage Modules.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($modules as $module): ?>
                                    <tr data-semester="<?php echo htmlspecialchars($module['semester']); ?>" 
                                        data-year="<?php echo $module['year_of_study']; ?>"
                                        data-program="<?php echo htmlspecialchars(strtolower($module['program_of_study'])); ?>"
                                        data-code="<?php echo htmlspecialchars(strtolower($module['module_code'])); ?>">
                                        <td><strong class="text-primary"><?php echo htmlspecialchars($module['module_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($module['module_name']); ?></td>
                                        <td><small><?php echo htmlspecialchars($module['program_of_study']); ?></small></td>
                                        <td><span class="badge bg-info">Year <?php echo $module['year_of_study']; ?></span></td>
                                        <td>
                                            <?php if ($module['semester'] === 'One'): ?>
                                                <span class="badge bg-primary">Semester One</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Semester Two</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo isset($module['credits']) ? $module['credits'] : 'N/A'; ?></span></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars(substr($module['description'], 0, 100)); ?><?php echo strlen($module['description']) > 100 ? '...' : ''; ?></small></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" 
                                                    onclick="editModule(<?php echo $module['module_id']; ?>, '<?php echo htmlspecialchars($module['module_code']); ?>', '<?php echo htmlspecialchars($module['module_name']); ?>', '<?php echo $module['semester']; ?>', <?php echo $module['year_of_study']; ?>)">
                                                <i class="bi bi-pencil"></i> Edit
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

    <!-- Edit Module Semester Modal -->
    <div class="modal fade" id="editModuleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Assign Module to Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="update_semester" value="1">
                        <input type="hidden" name="module_id" id="edit_module_id">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Module Code</label>
                            <input type="text" class="form-control" id="edit_module_code" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Module Name</label>
                            <input type="text" class="form-control" id="edit_module_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-123"></i> Year of Study</label>
                            <select class="form-select" name="new_year" id="edit_year" required>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-calendar-check"></i> Semester</label>
                            <select class="form-select" name="new_semester" id="edit_semester" required>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Note:</strong> This will reassign the module to the selected semester and year of study.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-save"></i> Update Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter Functionality
        document.getElementById('semesterFilter').addEventListener('change', filterModules);
        document.getElementById('yearFilter').addEventListener('change', filterModules);
        document.getElementById('programFilter').addEventListener('input', filterModules);
        document.getElementById('codeFilter').addEventListener('input', filterModules);
        
        // Initial count
        updateCounts();
        
        // Edit Module Function
        function editModule(moduleId, moduleCode, moduleName, semester, year) {
            document.getElementById('edit_module_id').value = moduleId;
            document.getElementById('edit_module_code').value = moduleCode;
            document.getElementById('edit_module_name').value = moduleName;
            document.getElementById('edit_semester').value = semester;
            document.getElementById('edit_year').value = year;
            
            const modal = new bootstrap.Modal(document.getElementById('editModuleModal'));
            modal.show();
        }
        
        function filterModules() {
            const semesterValue = document.getElementById('semesterFilter').value;
            const yearValue = document.getElementById('yearFilter').value;
            const programValue = document.getElementById('programFilter').value.toLowerCase();
            const codeValue = document.getElementById('codeFilter').value.toLowerCase();
            const rows = document.querySelectorAll('#modulesTableBody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (!row.hasAttribute('data-semester')) return; // Skip empty row
                
                const semester = row.getAttribute('data-semester');
                const year = row.getAttribute('data-year');
                const program = row.getAttribute('data-program');
                const code = row.getAttribute('data-code');
                
                const semesterMatch = !semesterValue || semester === semesterValue;
                const yearMatch = !yearValue || year === yearValue;
                const programMatch = !programValue || program.includes(programValue);
                const codeMatch = !codeValue || code.includes(codeValue);
                
                if (semesterMatch && yearMatch && programMatch && codeMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            document.getElementById('totalModules').textContent = visibleCount;
            updateCounts();
        }
        
        function updateCounts() {
            const rows = document.querySelectorAll('#modulesTableBody tr[style=""]');
            let semOneCount = 0;
            let semTwoCount = 0;
            let programs = new Set();
            
            rows.forEach(row => {
                if (!row.hasAttribute('data-semester')) return;
                
                const semester = row.getAttribute('data-semester');
                const program = row.getAttribute('data-program');
                
                if (semester === 'One') semOneCount++;
                if (semester === 'Two') semTwoCount++;
                if (program) programs.add(program);
            });
            
            document.getElementById('semOneCount').textContent = semOneCount;
            document.getElementById('semTwoCount').textContent = semTwoCount;
            document.getElementById('programCount').textContent = programs.size;
        }
    </script>
</body>
</html>
