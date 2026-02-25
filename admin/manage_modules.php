<?php
// manage_modules.php - Admin manage modules
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Handle template download
if (isset($_GET['download_template'])) {
    // Get programs from database for the template
    $template_programs = [];
    $prog_result = $conn->query("SELECT department_name FROM departments ORDER BY department_name");
    while ($row = $prog_result->fetch_assoc()) {
        $template_programs[] = $row['department_name'];
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modules_upload_template.csv"');
    // UTF-8 BOM for Excel compatibility
    echo chr(0xEF) . chr(0xBB) . chr(0xBF);
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Module Code', 'Module Name', 'Program of Study', 'Year of Study', 'Semester', 'Credits', 'Description']);
    
    // Instructions row (will be skipped during upload)
    fputcsv($output, ['--- INSTRUCTIONS ---', '--- FILL IN YOUR MODULES BELOW (delete these instruction rows first) ---', '', '', '', '', '']);
    fputcsv($output, ['Required (or leave blank to auto-generate)', 'Required', 'Required - Must match a program in the system', 'Required: 1, 2, 3, or 4', 'Required: One or Two', 'Required: number', 'Optional']);
    fputcsv($output, ['', '', '', '', '', '', '']);
    
    // Sample rows showing proper format
    $sample_program = !empty($template_programs) ? $template_programs[0] : 'Information Technology';
    fputcsv($output, ['IT101', 'Introduction to Computing', $sample_program, '1', 'One', '3', 'Fundamentals of computer systems']);
    fputcsv($output, ['IT102', 'Programming Fundamentals', $sample_program, '1', 'Two', '4', 'Basic programming concepts']);
    if (count($template_programs) > 1) {
        fputcsv($output, ['BA201', 'Organizational Behavior', $template_programs[1], '2', 'One', '3', 'Study of organizational behaviour']);
    }
    
    // Add a programs reference sheet
    fputcsv($output, ['', '', '', '', '', '', '']);
    fputcsv($output, ['--- AVAILABLE PROGRAMS ---', '', '', '', '', '', '']);
    foreach ($template_programs as $prog) {
        fputcsv($output, ['', '', $prog, '', '', '', '']);
    }
    
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
                    // Strip UTF-8 BOM if present
                    $bom = fread($handle, 3);
                    if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
                        rewind($handle);
                    }
                    
                    // Detect delimiter
                    $first_line = fgets($handle);
                    rewind($handle);
                    // Re-skip BOM
                    $bom = fread($handle, 3);
                    if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) rewind($handle);
                    
                    $delimiter = ',';
                    if (substr_count($first_line, "\t") > substr_count($first_line, ",")) {
                        $delimiter = "\t";
                    }
                    
                    // Read header row
                    $header = fgetcsv($handle, 0, $delimiter);
                    if (!$header) {
                        $error = "Could not read CSV header row.";
                    } else {
                        // Clean header values
                        $header_clean = array_map(function($h) { 
                            return strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $h))); 
                        }, $header);
                        
                        // Smart column mapping by header name
                        $col_map = ['code' => -1, 'name' => -1, 'program' => -1, 'year' => -1, 'semester' => -1, 'credits' => -1, 'description' => -1];
                        
                        foreach ($header_clean as $i => $h) {
                            if ((strpos($h, 'code') !== false && strpos($h, 'module') !== false) || $h === 'module code' || $h === 'code') {
                                $col_map['code'] = $i;
                            } elseif (strpos($h, 'name') !== false || (strpos($h, 'module') !== false && $col_map['name'] === -1)) {
                                if ($col_map['name'] === -1) $col_map['name'] = $i;
                            } elseif (strpos($h, 'program') !== false || strpos($h, 'programme') !== false || strpos($h, 'course') !== false) {
                                $col_map['program'] = $i;
                            } elseif (strpos($h, 'year') !== false) {
                                $col_map['year'] = $i;
                            } elseif (strpos($h, 'sem') !== false) {
                                $col_map['semester'] = $i;
                            } elseif (strpos($h, 'credit') !== false) {
                                $col_map['credits'] = $i;
                            } elseif (strpos($h, 'desc') !== false) {
                                $col_map['description'] = $i;
                            }
                        }
                        
                        // Fallback: positional mapping if headers not recognized
                        if ($col_map['name'] === -1 && count($header_clean) >= 5) {
                            $offset = ($col_map['code'] !== -1) ? 1 : 0;
                            $col_map['name'] = $offset;
                            $col_map['program'] = $offset + 1;
                            $col_map['year'] = $offset + 2;
                            $col_map['semester'] = $offset + 3;
                            $col_map['credits'] = $offset + 4;
                            if (isset($header_clean[$offset + 5])) $col_map['description'] = $offset + 5;
                        }
                        
                        $row_num = 1;
                        $duplicate_count = 0;
                        
                        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $row_num++;
                            
                            // Skip empty rows
                            $non_empty = array_filter($data, function($v) { return trim($v) !== ''; });
                            if (empty($non_empty)) continue;
                            
                            // Skip instruction/reference rows
                            $first_val = strtolower(trim($data[0] ?? ''));
                            if (strpos($first_val, '---') !== false || strpos($first_val, 'instruction') !== false || 
                                strpos($first_val, 'required') !== false || strpos($first_val, 'available program') !== false) {
                                continue;
                            }
                            
                            // Extract values using column map
                            $clean = function($idx) use ($data) {
                                if ($idx < 0 || !isset($data[$idx])) return '';
                                return rtrim(trim($data[$idx]), ',');
                            };
                            
                            $module_code = strtoupper($clean($col_map['code']));
                            $module_name = $clean($col_map['name']);
                            $program_of_study = $clean($col_map['program']);
                            $year_raw = $clean($col_map['year']);
                            $semester_raw = $clean($col_map['semester']);
                            $credits = (int)$clean($col_map['credits']);
                            $description = $clean($col_map['description']);
                            
                            // Skip rows with no module name (reference rows, etc.)
                            if (empty($module_name) || strtolower($module_name) === 'required') continue;
                            
                            // Skip rows where module name looks like instructions
                            if (strpos(strtolower($module_name), 'fill in') !== false || strpos(strtolower($module_name), 'instruction') !== false) continue;
                            
                            // Auto-generate module code if empty
                            if (empty($module_code)) {
                                $prog_words = preg_split('/\s+/', preg_replace('/[^a-zA-Z\s]/', '', $program_of_study));
                                $acr = '';
                                foreach ($prog_words as $w) {
                                    if (strlen($w) > 2) $acr .= strtoupper($w[0]);
                                }
                                if (empty($acr)) $acr = 'MOD';
                                // Use year + 3-digit sequence for uniqueness
                                $module_code = $acr . $year_raw . sprintf('%03d', $row_num);
                            }
                            
                            // Parse year - flexible
                            $year_of_study = 0;
                            $year_clean = strtolower(trim($year_raw));
                            if (is_numeric($year_clean)) {
                                $year_of_study = (int)$year_clean;
                            } else {
                                $year_table = [
                                    'first' => 1, '1st' => 1, 'one' => 1, 'year 1' => 1, 'year1' => 1,
                                    'second' => 2, '2nd' => 2, 'two' => 2, 'year 2' => 2, 'year2' => 2,
                                    'third' => 3, '3rd' => 3, 'three' => 3, 'year 3' => 3, 'year3' => 3,
                                    'fourth' => 4, '4th' => 4, 'four' => 4, 'year 4' => 4, 'year4' => 4,
                                ];
                                if (isset($year_table[$year_clean])) {
                                    $year_of_study = $year_table[$year_clean];
                                } elseif (preg_match('/(\d)/', $year_raw, $m)) {
                                    $year_of_study = (int)$m[1];
                                }
                            }
                            
                            // Parse semester - flexible
                            $semester = '';
                            $sem_clean = strtolower(trim($semester_raw));
                            if (strpos($sem_clean, 'one') !== false || strpos($sem_clean, '1') !== false || 
                                $sem_clean === 'first' || $sem_clean === '1st' || $sem_clean === 'i') {
                                $semester = 'One';
                            } elseif (strpos($sem_clean, 'two') !== false || strpos($sem_clean, '2') !== false || 
                                     $sem_clean === 'second' || $sem_clean === '2nd' || $sem_clean === 'ii') {
                                $semester = 'Two';
                            }
                            
                            // Validate required fields
                            if (empty($module_name) || empty($program_of_study)) {
                                $errors[] = "Row $row_num: Missing module name or program";
                                $skipped++;
                                continue;
                            }
                            
                            if (!in_array($year_of_study, [1, 2, 3, 4])) {
                                $errors[] = "Row $row_num: Invalid year '$year_raw' for '$module_name' (use 1, 2, 3, or 4)";
                                $skipped++;
                                continue;
                            }
                            
                            if (!in_array($semester, ['One', 'Two'])) {
                                $errors[] = "Row $row_num: Invalid semester '$semester_raw' for '$module_name' (use One or Two)";
                                $skipped++;
                                continue;
                            }
                            
                            if ($credits < 1) $credits = 3; // Default credits
                            
                            // Check for duplicate before insert
                            $check = $conn->prepare("SELECT module_id FROM modules WHERE module_code = ? OR (module_name = ? AND program_of_study = ? AND year_of_study = ? AND semester = ?)");
                            $check->bind_param("sssis", $module_code, $module_name, $program_of_study, $year_of_study, $semester);
                            $check->execute();
                            if ($check->get_result()->num_rows > 0) {
                                $duplicate_count++;
                                $skipped++;
                                continue;
                            }
                            
                            // Insert
                            $stmt = $conn->prepare("INSERT INTO modules (module_code, module_name, program_of_study, year_of_study, semester, credits, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssisss", $module_code, $module_name, $program_of_study, $year_of_study, $semester, $credits, $description);
                            
                            if ($stmt->execute()) {
                                $uploaded++;
                            } else {
                                $skipped++;
                                $errors[] = "Row $row_num: Failed to add '$module_name'";
                            }
                        }
                        
                        fclose($handle);
                        
                        // Build result message
                        $success = "<strong>Upload complete!</strong> $uploaded module(s) added successfully.";
                        if ($skipped > 0) {
                            $skip_detail = [];
                            if ($duplicate_count > 0) $skip_detail[] = "$duplicate_count duplicate(s)";
                            $other_skipped = $skipped - $duplicate_count;
                            if ($other_skipped > 0) $skip_detail[] = "$other_skipped with errors";
                            $success .= " $skipped skipped (" . implode(', ', $skip_detail) . ").";
                        }
                        if (!empty($errors)) {
                            $error = '<strong>Details:</strong><br>' . implode('<br>', array_slice($errors, 0, 10));
                            if (count($errors) > 10) {
                                $error .= '<br>... and ' . (count($errors) - 10) . ' more errors.';
                            }
                        }
                    } // end header check
                } else {
                    $error = "Failed to read CSV file.";
                }
            }
        } else {
            $error = "Please select a CSV file to upload.";
        }
    } elseif (isset($_POST['add_module'])) {
        $module_code = strtoupper(trim($_POST['module_code']));
        $module_name = trim($_POST['module_name']);
        $program_of_study = trim($_POST['program_of_study']);
        $year_of_study = (int)$_POST['year_of_study'];
        $semester = trim($_POST['semester']);
        $credits = (int)$_POST['credits'];
        $description = trim($_POST['description'] ?? '');

        try {
            $stmt = $conn->prepare("INSERT INTO modules (module_code, module_name, program_of_study, year_of_study, semester, credits, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssisss", $module_code, $module_name, $program_of_study, $year_of_study, $semester, $credits, $description);
            $stmt->execute();
            $success = "Module added successfully!";
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "Module code '$module_code' already exists. Please use a different code.";
            } else {
                $error = "Failed to add module: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_module'])) {
        $module_id = (int)$_POST['module_id'];
        $module_code = strtoupper(trim($_POST['module_code']));
        $module_name = trim($_POST['module_name']);
        $program_of_study = trim($_POST['program_of_study']);
        $year_of_study = (int)$_POST['year_of_study'];
        $semester = trim($_POST['semester']);
        $credits = (int)$_POST['credits'];
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("UPDATE modules SET module_code = ?, module_name = ?, program_of_study = ?, year_of_study = ?, semester = ?, credits = ?, description = ? WHERE module_id = ?");
        $stmt->bind_param("sssisssi", $module_code, $module_name, $program_of_study, $year_of_study, $semester, $credits, $description, $module_id);
        
        if ($stmt->execute()) {
            $success = "Module updated successfully!";
        } else {
            $error = "Failed to update module.";
        }
    } elseif (isset($_POST['delete_module'])) {
        $module_id = (int)$_POST['module_id'];

        $stmt = $conn->prepare("DELETE FROM modules WHERE module_id = ?");
        $stmt->bind_param("i", $module_id);
        
        if ($stmt->execute()) {
            $success = "Module deleted successfully!";
        } else {
            $error = "Failed to delete module.";
        }
    }
}

// Get filter parameters
$filter_program = $_GET['program'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';

// Build query with filters
$query = "SELECT * FROM modules WHERE 1=1";
$params = [];
$types = "";

if ($filter_program) {
    $query .= " AND program_of_study = ?";
    $params[] = $filter_program;
    $types .= "s";
}
if ($filter_year) {
    $query .= " AND year_of_study = ?";
    $params[] = (int)$filter_year;
    $types .= "i";
}
if ($filter_semester) {
    $query .= " AND semester = ?";
    $params[] = $filter_semester;
    $types .= "s";
}

$query .= " ORDER BY program_of_study, year_of_study, semester, module_code";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$modules = [];
while ($row = $result->fetch_assoc()) {
    $modules[] = $row;
}

// Get programs from departments table
$programs = [];
$result = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
while ($row = $result->fetch_assoc()) {
    $programs[] = $row;
}

// Get stats for dashboard
$total_modules = count($modules);
$total_credits = array_sum(array_column($modules, 'credits'));
$unique_programs = count(array_unique(array_column($modules, 'program_of_study')));

// Note: Don't close $conn here - header_nav.php needs it for getCurrentUser()
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Modules - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.3;
            position: absolute;
            right: 15px;
            top: 15px;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'manage_modules';
    $pageTitle = 'Manage Modules';
    $breadcrumbs = [['title' => 'Modules']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-journal-code me-2"></i>Manage Modules</h1>
            <p class="text-muted mb-0">Add, edit and manage course modules</p>
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

        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card vle-card border-info position-relative">
                    <div class="card-body">
                        <i class="bi bi-journal-code stat-icon text-info"></i>
                        <h6 class="text-muted text-uppercase">Total Modules</h6>
                        <h3 class="mb-0 text-info"><?php echo $total_modules; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card vle-card border-success position-relative">
                    <div class="card-body">
                        <i class="bi bi-award stat-icon text-success"></i>
                        <h6 class="text-muted text-uppercase">Total Credits</h6>
                        <h3 class="mb-0 text-success"><?php echo $total_credits; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card vle-card border-warning position-relative">
                    <div class="card-body">
                        <i class="bi bi-building stat-icon text-warning"></i>
                        <h6 class="text-muted text-uppercase">Programs Covered</h6>
                        <h3 class="mb-0 text-warning"><?php echo $unique_programs; ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Upload Section - Redesigned -->
        <div class="card vle-card mb-4 border-0 shadow">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cloud-arrow-up me-2"></i>Bulk Upload Modules</h5>
                <span class="badge bg-light text-success">CSV Import</span>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- Step 1: Download Template -->
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 h-100 bg-light">
                            <div class="d-flex align-items-center mb-3">
                                <span class="badge bg-success rounded-circle me-2" style="width:28px;height:28px;line-height:18px;font-size:14px;">1</span>
                                <h6 class="mb-0 fw-bold">Download Template</h6>
                            </div>
                            <p class="text-muted small mb-3">Get the CSV template with all available programs pre-filled. Fill in your modules and upload.</p>
                            <a href="?download_template=1" class="btn btn-success w-100">
                                <i class="bi bi-download me-1"></i> Download Template
                            </a>
                        </div>
                    </div>
                    
                    <!-- Step 2: Fill Template -->
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex align-items-center mb-3">
                                <span class="badge bg-primary rounded-circle me-2" style="width:28px;height:28px;line-height:18px;font-size:14px;">2</span>
                                <h6 class="mb-0 fw-bold">Fill Your Modules</h6>
                            </div>
                            <div class="small">
                                <table class="table table-sm table-bordered mb-2" style="font-size:11px;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Column</th>
                                            <th>Format</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>Module Code</td><td class="text-muted">Optional (auto-generated)</td></tr>
                                        <tr><td>Module Name</td><td><strong>Required</strong></td></tr>
                                        <tr><td>Program</td><td><strong>Required</strong> - exact name</td></tr>
                                        <tr><td>Year</td><td><code>1</code>, <code>2</code>, <code>3</code>, or <code>4</code></td></tr>
                                        <tr><td>Semester</td><td><code>One</code> or <code>Two</code></td></tr>
                                        <tr><td>Credits</td><td>Number (default: 3)</td></tr>
                                        <tr><td>Description</td><td class="text-muted">Optional</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Upload -->
                    <div class="col-lg-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex align-items-center mb-3">
                                <span class="badge bg-warning text-dark rounded-circle me-2" style="width:28px;height:28px;line-height:18px;font-size:14px;">3</span>
                                <h6 class="mb-0 fw-bold">Upload CSV File</h6>
                            </div>
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="template_file" class="form-label small text-muted">Select your filled CSV file</label>
                                    <input type="file" class="form-control" id="template_file" name="template_file" accept=".csv" required>
                                </div>
                                <button type="submit" name="upload_template" class="btn btn-warning w-100 fw-bold">
                                    <i class="bi bi-cloud-arrow-up me-1"></i> Upload & Import
                                </button>
                                <div class="mt-2">
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> Duplicates are auto-skipped. Flexible formats accepted.</small>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Format Reference (collapsible) -->
                <div class="mt-3">
                    <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#formatHelp" role="button">
                        <i class="bi bi-question-circle me-1"></i> Accepted Format Examples
                    </a>
                    <div class="collapse mt-2" id="formatHelp">
                        <div class="card card-body bg-light small">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Year of Study:</strong>
                                    <ul class="mb-1">
                                        <li><code>1</code>, <code>2</code>, <code>3</code>, <code>4</code></li>
                                        <li><code>Year 1</code>, <code>Year 2</code>, etc.</li>
                                        <li><code>First</code>, <code>Second</code>, etc.</li>
                                        <li><code>1st</code>, <code>2nd</code>, etc.</li>
                                    </ul>
                                </div>
                                <div class="col-md-4">
                                    <strong>Semester:</strong>
                                    <ul class="mb-1">
                                        <li><code>One</code> or <code>Two</code></li>
                                        <li><code>1</code> or <code>2</code></li>
                                        <li><code>Sem One</code>, <code>Sem Two</code></li>
                                        <li><code>Semester 1</code>, <code>Semester 2</code></li>
                                    </ul>
                                </div>
                                <div class="col-md-4">
                                    <strong>Module Code:</strong>
                                    <ul class="mb-1">
                                        <li>Enter your own: <code>BBA4102</code></li>
                                        <li>Or leave blank to auto-generate</li>
                                        <li>Generated from program acronym + numbers</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Module Form -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Module</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="module_code" class="form-label">Module Code *</label>
                            <input type="text" class="form-control" id="module_code" name="module_code" 
                                   placeholder="e.g., CS101" style="text-transform: uppercase;" required>
                        </div>
                        <div class="col-md-5">
                            <label for="module_name" class="form-label">Module Name *</label>
                            <input type="text" class="form-control" id="module_name" name="module_name" 
                                   placeholder="e.g., Introduction to Programming" required>
                        </div>
                        <div class="col-md-2">
                            <label for="credits" class="form-label">Credits *</label>
                            <input type="number" class="form-control" id="credits" name="credits" 
                                   value="3" min="1" max="10" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="program_of_study" class="form-label">Program of Study *</label>
                            <select class="form-select" id="program_of_study" name="program_of_study" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo htmlspecialchars($prog['department_name']); ?>">
                                        <?php echo htmlspecialchars($prog['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="year_of_study" class="form-label">Year of Study *</label>
                            <select class="form-select" id="year_of_study" name="year_of_study" required>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="semester" class="form-label">Semester *</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2" 
                                      placeholder="Module description (optional)"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" name="add_module" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Add Module
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filter Modules</h6>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <label for="filter_program" class="form-label">Program of Study</label>
                        <select class="form-select" id="filter_program" name="program">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo htmlspecialchars($prog['department_name']); ?>" 
                                        <?php echo $filter_program == $prog['department_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prog['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_year" class="form-label">Year of Study</label>
                        <select class="form-select" id="filter_year" name="year">
                            <option value="">All Years</option>
                            <option value="1" <?php echo $filter_year == '1' ? 'selected' : ''; ?>>Year 1</option>
                            <option value="2" <?php echo $filter_year == '2' ? 'selected' : ''; ?>>Year 2</option>
                            <option value="3" <?php echo $filter_year == '3' ? 'selected' : ''; ?>>Year 3</option>
                            <option value="4" <?php echo $filter_year == '4' ? 'selected' : ''; ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_semester" class="form-label">Semester</label>
                        <select class="form-select" id="filter_semester" name="semester">
                            <option value="">All Semesters</option>
                            <option value="One" <?php echo $filter_semester == 'One' ? 'selected' : ''; ?>>Semester One</option>
                            <option value="Two" <?php echo $filter_semester == 'Two' ? 'selected' : ''; ?>>Semester Two</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-secondary w-100">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modules List -->
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Modules (<?php echo count($modules); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($modules)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No modules found. Add your first module above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="8%">Code</th>
                                    <th width="25%">Module Name</th>
                                    <th width="25%">Program</th>
                                    <th width="8%">Year</th>
                                    <th width="10%">Semester</th>
                                    <th width="8%">Credits</th>
                                    <th width="16%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modules as $module): ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($module['module_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($module['module_name']); ?></td>
                                        <td><small><?php echo htmlspecialchars($module['program_of_study'] ?? 'N/A'); ?></small></td>
                                        <td>Year <?php echo $module['year_of_study']; ?></td>
                                        <td>Sem <?php echo htmlspecialchars($module['semester']); ?></td>
                                        <td><?php echo $module['credits']; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" 
                                                    onclick='editModule(<?php echo json_encode($module); ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this module?');">
                                                <input type="hidden" name="module_id" value="<?php echo $module['module_id']; ?>">
                                                <button type="submit" name="delete_module" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
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

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Module</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="module_id" id="edit_module_id">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="edit_module_code" class="form-label">Module Code *</label>
                                <input type="text" class="form-control" id="edit_module_code" 
                                       name="module_code" style="text-transform: uppercase;" required>
                            </div>
                            <div class="col-md-8">
                                <label for="edit_module_name" class="form-label">Module Name *</label>
                                <input type="text" class="form-control" id="edit_module_name" 
                                       name="module_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_program_of_study" class="form-label">Program of Study *</label>
                                <select class="form-select" id="edit_program_of_study" name="program_of_study" required>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $prog): ?>
                                        <option value="<?php echo htmlspecialchars($prog['department_name']); ?>">
                                            <?php echo htmlspecialchars($prog['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="edit_year_of_study" class="form-label">Year *</label>
                                <select class="form-select" id="edit_year_of_study" name="year_of_study" required>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="edit_semester" class="form-label">Semester *</label>
                                <select class="form-select" id="edit_semester" name="semester" required>
                                    <option value="One">Semester One</option>
                                    <option value="Two">Semester Two</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="edit_credits" class="form-label">Credits *</label>
                                <input type="number" class="form-control" id="edit_credits" 
                                       name="credits" min="1" max="10" required>
                            </div>
                            <div class="col-12">
                                <label for="edit_description" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_description" 
                                          name="description" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_module" class="btn btn-warning">
                            <i class="bi bi-save"></i> Update Module
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editModule(module) {
            document.getElementById('edit_module_id').value = module.module_id;
            document.getElementById('edit_module_code').value = module.module_code;
            document.getElementById('edit_module_name').value = module.module_name;
            document.getElementById('edit_program_of_study').value = module.program_of_study || '';
            document.getElementById('edit_year_of_study').value = module.year_of_study;
            document.getElementById('edit_semester').value = module.semester;
            document.getElementById('edit_credits').value = module.credits;
            document.getElementById('edit_description').value = module.description || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    </script>
</body>
</html>
