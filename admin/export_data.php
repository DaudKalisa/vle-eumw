<?php
// admin/export_data.php - Data Export Tool
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Define exportable categories with their tables, columns, and descriptions
$export_categories = [
    'students' => [
        'label' => 'Students',
        'icon' => 'bi-people-fill',
        'color' => '#4f46e5',
        'description' => 'Student records, profiles and demographics',
        'table' => 'students',
        'columns' => [
            'student_id' => 'Student ID',
            'full_name' => 'Full Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'gender' => 'Gender',
            'national_id' => 'National ID',
            'department' => 'Department',
            'program' => 'Program',
            'program_type' => 'Program Type',
            'year_of_study' => 'Year of Study',
            'semester' => 'Semester',
            'campus' => 'Campus',
            'entry_type' => 'Entry Type',
            'enrollment_date' => 'Enrollment Date',
            'year_of_registration' => 'Year of Registration',
            'is_active' => 'Active Status',
            'address' => 'Address',
        ],
        'default_columns' => ['student_id','full_name','email','phone','gender','department','program','program_type','year_of_study','semester','campus','is_active'],
    ],
    'lecturers' => [
        'label' => 'Lecturers',
        'icon' => 'bi-person-badge-fill',
        'color' => '#0891b2',
        'description' => 'Lecturer records, departments and roles',
        'table' => 'lecturers',
        'columns' => [
            'lecturer_id' => 'Lecturer ID',
            'full_name' => 'Full Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'gender' => 'Gender',
            'department' => 'Department',
            'program' => 'Program',
            'position' => 'Position',
            'office' => 'Office',
            'role' => 'Role',
            'hire_date' => 'Hire Date',
            'is_active' => 'Active Status',
            'bio' => 'Biography',
        ],
        'default_columns' => ['lecturer_id','full_name','email','phone','gender','department','program','position','role','is_active'],
    ],
    'departments' => [
        'label' => 'Departments',
        'icon' => 'bi-building',
        'color' => '#059669',
        'description' => 'Academic departments and their faculty affiliations',
        'table' => 'departments',
        'columns' => [
            'department_id' => 'Department ID',
            'department_code' => 'Code',
            'department_name' => 'Department Name',
            'faculty_id' => 'Faculty ID',
            'created_at' => 'Created At',
        ],
        'default_columns' => ['department_id','department_code','department_name','faculty_id'],
    ],
    'faculties' => [
        'label' => 'Faculties',
        'icon' => 'bi-diagram-3-fill',
        'color' => '#7c3aed',
        'description' => 'Faculty organizational units',
        'table' => 'faculties',
        'columns' => [
            'faculty_id' => 'Faculty ID',
            'faculty_code' => 'Code',
            'faculty_name' => 'Faculty Name',
            'head_of_faculty' => 'Head of Faculty',
            'created_at' => 'Created At',
        ],
        'default_columns' => ['faculty_id','faculty_code','faculty_name','head_of_faculty'],
    ],
    'programs' => [
        'label' => 'Programs',
        'icon' => 'bi-mortarboard-fill',
        'color' => '#d97706',
        'description' => 'Academic programs with department links',
        'table' => 'programs',
        'columns' => [
            'program_id' => 'Program ID',
            'program_code' => 'Code',
            'program_name' => 'Program Name',
            'department_id' => 'Department ID',
            'program_type' => 'Program Type',
            'duration_years' => 'Duration (Years)',
            'description' => 'Description',
            'is_active' => 'Active Status',
            'created_at' => 'Created At',
        ],
        'default_columns' => ['program_id','program_code','program_name','department_id','program_type','duration_years','is_active'],
    ],
    'courses' => [
        'label' => 'Courses / Modules',
        'icon' => 'bi-book-fill',
        'color' => '#0284c7',
        'description' => 'Course catalogue with lecturer assignments',
        'table' => 'vle_courses',
        'columns' => [
            'course_id' => 'Course ID',
            'course_code' => 'Course Code',
            'course_name' => 'Course Name',
            'description' => 'Description',
            'lecturer_id' => 'Lecturer ID',
            'program_of_study' => 'Program of Study',
            'year_of_study' => 'Year of Study',
            'total_weeks' => 'Total Weeks',
            'is_active' => 'Active Status',
            'created_date' => 'Created Date',
        ],
        'default_columns' => ['course_id','course_code','course_name','lecturer_id','program_of_study','year_of_study','total_weeks','is_active'],
    ],
    'enrollments' => [
        'label' => 'Enrollments',
        'icon' => 'bi-journal-plus',
        'color' => '#16a34a',
        'description' => 'Student-course enrollment records',
        'table' => 'vle_enrollments',
        'columns' => [
            'enrollment_id' => 'Enrollment ID',
            'student_id' => 'Student ID',
            'course_id' => 'Course ID',
            'enrollment_date' => 'Enrollment Date',
            'current_week' => 'Current Week',
            'is_completed' => 'Completed',
            'completion_date' => 'Completion Date',
        ],
        'default_columns' => ['enrollment_id','student_id','course_id','enrollment_date','is_completed'],
    ],
    'grades' => [
        'label' => 'Grades & Submissions',
        'icon' => 'bi-award-fill',
        'color' => '#dc2626',
        'description' => 'Assignment submissions with scores and feedback',
        'table' => 'vle_submissions',
        'columns' => [
            'submission_id' => 'Submission ID',
            'assignment_id' => 'Assignment ID',
            'student_id' => 'Student ID',
            'submission_date' => 'Submission Date',
            'file_name' => 'File Name',
            'score' => 'Score',
            'feedback' => 'Feedback',
            'graded_by' => 'Graded By',
            'graded_date' => 'Graded Date',
            'status' => 'Status',
        ],
        'default_columns' => ['submission_id','assignment_id','student_id','submission_date','score','feedback','status'],
    ],
    'assignments' => [
        'label' => 'Assignments',
        'icon' => 'bi-file-earmark-check-fill',
        'color' => '#ea580c',
        'description' => 'Assignment definitions and due dates',
        'table' => 'vle_assignments',
        'columns' => [
            'assignment_id' => 'Assignment ID',
            'course_id' => 'Course ID',
            'week_number' => 'Week Number',
            'title' => 'Title',
            'description' => 'Description',
            'assignment_type' => 'Type',
            'max_score' => 'Max Score',
            'passing_score' => 'Passing Score',
            'due_date' => 'Due Date',
            'is_active' => 'Active Status',
            'created_date' => 'Created Date',
        ],
        'default_columns' => ['assignment_id','course_id','title','assignment_type','max_score','due_date','is_active'],
    ],
    'exam_results' => [
        'label' => 'Exam Results',
        'icon' => 'bi-clipboard2-data-fill',
        'color' => '#be185d',
        'description' => 'Examination scores and grades',
        'table' => 'exam_results',
        'columns' => [
            'result_id' => 'Result ID',
            'exam_id' => 'Exam ID',
            'student_id' => 'Student ID',
            'score' => 'Score',
            'percentage' => 'Percentage',
            'is_passed' => 'Passed',
            'grade' => 'Grade',
            'submitted_at' => 'Submitted At',
            'remarks' => 'Remarks',
        ],
        'default_columns' => ['result_id','exam_id','student_id','score','percentage','grade','is_passed'],
    ],
    'payments' => [
        'label' => 'Payment Transactions',
        'icon' => 'bi-cash-coin',
        'color' => '#15803d',
        'description' => 'Payment records with methods and references',
        'table' => 'payment_transactions',
        'columns' => [
            'transaction_id' => 'Transaction ID',
            'student_id' => 'Student ID',
            'payment_type' => 'Payment Type',
            'amount' => 'Amount',
            'payment_method' => 'Payment Method',
            'reference_number' => 'Reference No.',
            'payment_date' => 'Payment Date',
            'recorded_by' => 'Recorded By',
            'notes' => 'Notes',
            'approval_status' => 'Status',
            'created_at' => 'Created At',
        ],
        'default_columns' => ['transaction_id','student_id','payment_type','amount','payment_method','reference_number','payment_date','approval_status'],
    ],
    'student_finances' => [
        'label' => 'Student Financial Records',
        'icon' => 'bi-wallet2',
        'color' => '#0f766e',
        'description' => 'Student fee balances and payment summaries',
        'table' => 'student_finances',
        'columns' => [
            'student_id' => 'Student ID',
            'full_name' => 'Full Name',
            'total_fees' => 'Total Fees',
            'total_paid' => 'Total Paid',
            'balance' => 'Balance',
            'payment_percentage' => 'Payment %',
            'payment_status' => 'Payment Status',
            'program_type' => 'Program Type',
        ],
        'default_columns' => ['student_id','full_name','total_fees','total_paid','balance','payment_percentage','payment_status'],
    ],
    'fee_settings' => [
        'label' => 'Fee Settings',
        'icon' => 'bi-currency-dollar',
        'color' => '#a16207',
        'description' => 'Fee structure and tuition configuration',
        'table' => 'fee_settings',
        'columns' => [
            'id' => 'ID',
            'application_fee' => 'Application Fee',
            'registration_fee' => 'Registration Fee',
            'tuition_degree' => 'Tuition (Degree)',
            'tuition_professional' => 'Tuition (Professional)',
            'tuition_masters' => 'Tuition (Masters)',
            'tuition_doctorate' => 'Tuition (Doctorate)',
            'supplementary_exam_fee' => 'Supplementary Exam Fee',
            'deferred_exam_fee' => 'Deferred Exam Fee',
            'updated_at' => 'Updated At',
        ],
        'default_columns' => ['application_fee','registration_fee','tuition_degree','tuition_professional','tuition_masters','tuition_doctorate'],
    ],
    'modules' => [
        'label' => 'Module Catalogue',
        'icon' => 'bi-grid-3x3-gap-fill',
        'color' => '#6d28d9',
        'description' => 'Module definitions with credits and semesters',
        'table' => 'modules',
        'columns' => [
            'module_id' => 'Module ID',
            'module_code' => 'Module Code',
            'module_name' => 'Module Name',
            'program_of_study' => 'Program of Study',
            'year_of_study' => 'Year of Study',
            'semester' => 'Semester',
            'credits' => 'Credits',
            'description' => 'Description',
            'created_at' => 'Created At',
        ],
        'default_columns' => ['module_id','module_code','module_name','program_of_study','year_of_study','semester','credits'],
    ],
    'users' => [
        'label' => 'User Accounts',
        'icon' => 'bi-person-gear',
        'color' => '#b91c1c',
        'description' => 'System login accounts (passwords excluded)',
        'table' => 'users',
        'columns' => [
            'user_id' => 'User ID',
            'username' => 'Username',
            'email' => 'Email',
            'role' => 'Role',
            'related_student_id' => 'Student ID',
            'related_lecturer_id' => 'Lecturer ID',
            'is_active' => 'Active Status',
            'created_at' => 'Created At',
            'last_login' => 'Last Login',
        ],
        'default_columns' => ['user_id','username','email','role','is_active','created_at','last_login'],
    ],
    'university_settings' => [
        'label' => 'University Settings',
        'icon' => 'bi-gear-fill',
        'color' => '#475569',
        'description' => 'University branding and configuration',
        'table' => 'university_settings',
        'columns' => [
            'university_name' => 'University Name',
            'address_po_box' => 'P.O. Box',
            'address_area' => 'Area',
            'address_city' => 'City',
            'address_country' => 'Country',
            'phone' => 'Phone',
            'email' => 'Email',
            'website' => 'Website',
            'receipt_footer_text' => 'Receipt Footer',
        ],
        'default_columns' => ['university_name','address_po_box','address_area','address_city','address_country','phone','email','website'],
    ],
];

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {
    $category = $_POST['category'] ?? '';
    $format = $_POST['format'] ?? 'csv';
    $selected_columns = $_POST['columns'] ?? [];
    
    if (!isset($export_categories[$category])) {
        $error = "Invalid export category.";
    } elseif (empty($selected_columns)) {
        $error = "Please select at least one column to export.";
    } else {
        $cat = $export_categories[$category];
        $table = $cat['table'];
        
        // Validate column names against whitelist
        $valid_columns = array_keys($cat['columns']);
        $selected_columns = array_intersect($selected_columns, $valid_columns);
        
        if (empty($selected_columns)) {
            $error = "No valid columns selected.";
        } else {
            // Check if table exists
            $table_check = $conn->query("SHOW TABLES LIKE '$table'");
            if ($table_check->num_rows === 0) {
                $error = "Table '$table' does not exist in the database.";
            } else {
                // Verify columns exist in actual table
                $actual_columns = [];
                $col_result = $conn->query("SHOW COLUMNS FROM `$table`");
                while ($col = $col_result->fetch_assoc()) {
                    $actual_columns[] = $col['Field'];
                }
                $selected_columns = array_intersect($selected_columns, $actual_columns);
                
                if (empty($selected_columns)) {
                    $error = "Selected columns do not exist in the table.";
                } else {
                    $escaped_cols = array_map(function($c) { return "`$c`"; }, $selected_columns);
                    $sql = "SELECT " . implode(', ', $escaped_cols) . " FROM `$table`";
                    
                    // Apply filters if provided
                    $where_clauses = [];
                    if (!empty($_POST['filter_active']) && in_array('is_active', $actual_columns)) {
                        $where_clauses[] = "`is_active` = 1";
                    }
                    if (!empty($_POST['filter_department']) && in_array('department', $actual_columns)) {
                        $dept = $conn->real_escape_string($_POST['filter_department']);
                        $where_clauses[] = "`department` = '$dept'";
                    }
                    if (!empty($_POST['filter_program_type']) && in_array('program_type', $actual_columns)) {
                        $pt = $conn->real_escape_string($_POST['filter_program_type']);
                        $where_clauses[] = "`program_type` = '$pt'";
                    }
                    
                    if (!empty($where_clauses)) {
                        $sql .= " WHERE " . implode(' AND ', $where_clauses);
                    }
                    
                    $result = $conn->query($sql);
                    
                    if (!$result) {
                        $error = "Query failed: " . $conn->error;
                    } else {
                        $rows = [];
                        while ($row = $result->fetch_assoc()) {
                            $rows[] = $row;
                        }
                        
                        $filename = 'export_' . $category . '_' . date('Y-m-d_H-i-s');
                        $col_labels = [];
                        foreach ($selected_columns as $c) {
                            $col_labels[$c] = $cat['columns'][$c] ?? $c;
                        }
                        
                        if ($format === 'csv') {
                            header('Content-Type: text/csv; charset=utf-8');
                            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                            $output = fopen('php://output', 'w');
                            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
                            fputcsv($output, array_values($col_labels));
                            foreach ($rows as $row) {
                                $line = [];
                                foreach ($selected_columns as $c) {
                                    $line[] = $row[$c] ?? '';
                                }
                                fputcsv($output, $line);
                            }
                            fclose($output);
                            exit;
                        } elseif ($format === 'json') {
                            header('Content-Type: application/json; charset=utf-8');
                            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                            $json_rows = [];
                            foreach ($rows as $row) {
                                $item = [];
                                foreach ($selected_columns as $c) {
                                    $key = $col_labels[$c];
                                    $item[$key] = $row[$c] ?? null;
                                }
                                $json_rows[] = $item;
                            }
                            echo json_encode([
                                'export_info' => [
                                    'category' => $cat['label'],
                                    'table' => $table,
                                    'exported_at' => date('Y-m-d H:i:s'),
                                    'total_records' => count($json_rows),
                                    'columns' => array_values($col_labels),
                                ],
                                'data' => $json_rows,
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            exit;
                        } elseif ($format === 'sql') {
                            header('Content-Type: application/sql; charset=utf-8');
                            header('Content-Disposition: attachment; filename="' . $filename . '.sql"');
                            $output = '';
                            $output .= "-- VLE Data Export: {$cat['label']}\n";
                            $output .= "-- Table: $table\n";
                            $output .= "-- Exported: " . date('Y-m-d H:i:s') . "\n";
                            $output .= "-- Records: " . count($rows) . "\n\n";
                            $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                            
                            // Get CREATE TABLE for structure
                            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                            if ($cr = $create_result->fetch_assoc()) {
                                $output .= "-- Table structure\n";
                                $output .= "DROP TABLE IF EXISTS `$table`;\n";
                                $output .= $cr['Create Table'] . ";\n\n";
                            }
                            
                            // Insert data
                            foreach ($rows as $row) {
                                $vals = [];
                                foreach ($selected_columns as $c) {
                                    $v = $row[$c] ?? null;
                                    if ($v === null) {
                                        $vals[] = 'NULL';
                                    } else {
                                        $vals[] = "'" . $conn->real_escape_string($v) . "'";
                                    }
                                }
                                $output .= "INSERT INTO `$table` (" . implode(', ', $escaped_cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                            }
                            $output .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
                            echo $output;
                            exit;
                        } elseif ($format === 'xml') {
                            header('Content-Type: application/xml; charset=utf-8');
                            header('Content-Disposition: attachment; filename="' . $filename . '.xml"');
                            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><export></export>');
                            $info = $xml->addChild('export_info');
                            $info->addChild('category', htmlspecialchars($cat['label']));
                            $info->addChild('table', $table);
                            $info->addChild('exported_at', date('Y-m-d H:i:s'));
                            $info->addChild('total_records', count($rows));
                            $data = $xml->addChild('data');
                            foreach ($rows as $row) {
                                $record = $data->addChild('record');
                                foreach ($selected_columns as $c) {
                                    $val = $row[$c] ?? '';
                                    $record->addChild(htmlspecialchars($c), htmlspecialchars($val));
                                }
                            }
                            echo $xml->asXML();
                            exit;
                        }
                    }
                }
            }
        }
    }
}

// Get row counts for each category
$category_counts = [];
foreach ($export_categories as $key => $cat) {
    $tbl = $cat['table'];
    $check = $conn->query("SHOW TABLES LIKE '$tbl'");
    if ($check && $check->num_rows > 0) {
        $cnt = $conn->query("SELECT COUNT(*) as c FROM `$tbl`");
        $category_counts[$key] = $cnt ? $cnt->fetch_assoc()['c'] : 0;
    } else {
        $category_counts[$key] = -1; // table missing
    }
}

// Get departments list for filters
$departments = [];
$dept_result = $conn->query("SHOW TABLES LIKE 'departments'");
if ($dept_result && $dept_result->num_rows > 0) {
    $d = $conn->query("SELECT department_name FROM departments ORDER BY department_name");
    if ($d) {
        while ($r = $d->fetch_assoc()) $departments[] = $r['department_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Data - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .export-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.25s ease;
            background: white;
            height: 100%;
        }
        .export-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .export-card.selected {
            border-color: var(--vle-primary);
            box-shadow: 0 0 0 3px rgba(30,60,114,0.15);
            background: #f0f4ff;
        }
        .export-card .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            margin-bottom: 12px;
        }
        .export-card .card-count {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1;
        }
        .export-card .card-label {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        .export-card .card-desc {
            font-size: 0.78rem;
            color: var(--vle-text-muted);
            margin: 0;
        }
        .config-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 28px;
        }
        .column-checkbox {
            display: inline-flex;
            align-items: center;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 12px;
            margin: 3px;
            cursor: pointer;
            transition: all 0.15s;
            font-size: 0.85rem;
        }
        .column-checkbox:hover {
            border-color: var(--vle-primary);
            background: #f0f4ff;
        }
        .column-checkbox input:checked + span {
            color: var(--vle-primary);
            font-weight: 600;
        }
        .column-checkbox input {
            margin-right: 6px;
        }
        .format-option {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 18px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            background: white;
        }
        .format-option:hover {
            border-color: var(--vle-primary);
        }
        .format-option.selected {
            border-color: var(--vle-primary);
            background: #f0f4ff;
        }
        .format-option i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 4px;
        }
        .format-option .format-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        .format-option .format-desc {
            font-size: 0.72rem;
            color: var(--vle-text-muted);
        }
        .stat-card {
            background: var(--vle-gradient-primary);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-card.green { background: var(--vle-gradient-success); }
        .stat-card.orange { background: var(--vle-gradient-pink); }
        .stat-card.purple { background: var(--vle-gradient-purple); }
        .step-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .step-indicator .step {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            background: #e2e8f0;
            color: #64748b;
        }
        .step-indicator .step.active {
            background: var(--vle-primary);
            color: white;
        }
        .step-indicator .step.done {
            background: var(--vle-success);
            color: white;
        }
        .step-indicator .step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
        }
        .step-indicator .step-line.done {
            background: var(--vle-success);
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2><i class="bi bi-box-arrow-up-right me-2"></i>Export Data</h2>
                    <div>
                        <a href="database_manager.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-database-gear me-1"></i>Database Manager
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Dashboard
                        </a>
                    </div>
                </div>
                <p class="text-muted mt-1 mb-0">Export system data in CSV, JSON, SQL, or XML format for use in other systems.</p>
            </div>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Summary Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="bi bi-grid-3x3-gap fs-2"></i>
                    <h3 class="mt-2"><?= count($export_categories) ?></h3>
                    <p class="mb-0">Export Categories</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card green">
                    <i class="bi bi-table fs-2"></i>
                    <h3 class="mt-2"><?= count(array_filter($category_counts, fn($c) => $c >= 0)) ?></h3>
                    <p class="mb-0">Available Tables</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card orange">
                    <i class="bi bi-list-ol fs-2"></i>
                    <h3 class="mt-2"><?= number_format(array_sum(array_filter($category_counts, fn($c) => $c > 0))) ?></h3>
                    <p class="mb-0">Total Records</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card purple">
                    <i class="bi bi-filetype-csv fs-2"></i>
                    <h3 class="mt-2">4</h3>
                    <p class="mb-0">Export Formats</p>
                </div>
            </div>
        </div>
        
        <!-- Step Indicator -->
        <div class="step-indicator mb-2">
            <div class="step active" id="step1Indicator">1</div>
            <span class="text-muted" style="font-size:0.85rem;">Select Data</span>
            <div class="step-line" id="stepLine1"></div>
            <div class="step" id="step2Indicator">2</div>
            <span class="text-muted" style="font-size:0.85rem;">Configure</span>
            <div class="step-line" id="stepLine2"></div>
            <div class="step" id="step3Indicator">3</div>
            <span class="text-muted" style="font-size:0.85rem;">Export</span>
        </div>
        
        <!-- Step 1: Select Category -->
        <div class="card mb-4" id="step1">
            <div class="card-header text-white" style="background:var(--vle-gradient-primary);">
                <h5 class="mb-0"><i class="bi bi-1-circle me-2"></i>Select Data to Export</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($export_categories as $key => $cat): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="export-card" data-category="<?= $key ?>" onclick="selectCategory('<?= $key ?>')">
                            <div class="card-icon" style="background:<?= $cat['color'] ?>;">
                                <i class="bi <?= $cat['icon'] ?>"></i>
                            </div>
                            <div class="card-label"><?= $cat['label'] ?></div>
                            <div class="card-count">
                                <?php if ($category_counts[$key] >= 0): ?>
                                    <?= number_format($category_counts[$key]) ?>
                                <?php else: ?>
                                    <span class="text-danger" style="font-size:0.8rem;">Table missing</span>
                                <?php endif; ?>
                            </div>
                            <p class="card-desc"><?= $cat['description'] ?></p>
                            <?php if ($category_counts[$key] === 0): ?>
                                <span class="badge bg-warning text-dark mt-1" style="font-size:0.7rem;">No records</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Step 2: Configure Export (hidden until category selected) -->
        <div class="card mb-4 d-none" id="step2">
            <div class="card-header text-white" style="background:var(--vle-gradient-primary);">
                <h5 class="mb-0"><i class="bi bi-2-circle me-2"></i>Configure Export: <span id="selectedCategoryLabel"></span></h5>
            </div>
            <div class="card-body">
                <form method="POST" id="exportForm">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="category" id="exportCategory">
                    
                    <!-- Column Selection -->
                    <div class="config-panel mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0"><i class="bi bi-layout-three-columns me-2"></i>Select Columns</h6>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="selectAllColumns()">
                                    <i class="bi bi-check-all me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="selectDefaultColumns()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Default
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAllColumns()">
                                    <i class="bi bi-x-lg me-1"></i>Clear
                                </button>
                            </div>
                        </div>
                        <div id="columnList" class="d-flex flex-wrap"></div>
                        <small class="text-muted d-block mt-2"><span id="selectedCount">0</span> columns selected</small>
                    </div>
                    
                    <!-- Filters -->
                    <div class="config-panel mb-4" id="filtersPanel">
                        <h6 class="fw-bold mb-3"><i class="bi bi-funnel me-2"></i>Filters (Optional)</h6>
                        <div class="row g-3">
                            <div class="col-md-4" id="filterActiveWrap" style="display:none;">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="filterActive" name="filter_active" value="1">
                                    <label class="form-check-label" for="filterActive">Active records only</label>
                                </div>
                            </div>
                            <div class="col-md-4" id="filterDeptWrap" style="display:none;">
                                <label class="form-label fw-semibold">Department</label>
                                <select name="filter_department" class="form-select form-select-sm" id="filterDept">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4" id="filterProgramTypeWrap" style="display:none;">
                                <label class="form-label fw-semibold">Program Type</label>
                                <select name="filter_program_type" class="form-select form-select-sm" id="filterProgType">
                                    <option value="">All Types</option>
                                    <option value="degree">Degree</option>
                                    <option value="professional">Professional</option>
                                    <option value="masters">Masters</option>
                                    <option value="doctorate">Doctorate</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Export Format -->
                    <div class="config-panel mb-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-arrow-down me-2"></i>Export Format</h6>
                        <div class="row g-3">
                            <div class="col-md-3 col-6">
                                <div class="format-option selected" onclick="selectFormat('csv', this)">
                                    <i class="bi bi-filetype-csv text-success"></i>
                                    <div class="format-name">CSV</div>
                                    <div class="format-desc">Excel, Sheets, imports</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="format-option" onclick="selectFormat('json', this)">
                                    <i class="bi bi-filetype-json text-warning"></i>
                                    <div class="format-name">JSON</div>
                                    <div class="format-desc">APIs, web systems</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="format-option" onclick="selectFormat('sql', this)">
                                    <i class="bi bi-filetype-sql text-primary"></i>
                                    <div class="format-name">SQL</div>
                                    <div class="format-desc">Database import</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="format-option" onclick="selectFormat('xml', this)">
                                    <i class="bi bi-filetype-xml text-danger"></i>
                                    <div class="format-name">XML</div>
                                    <div class="format-desc">Legacy systems</div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="format" id="exportFormat" value="csv">
                    </div>
                    
                    <!-- Export Button -->
                    <div class="d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-lg text-white" id="exportBtn" style="background:var(--vle-gradient-primary);" disabled>
                            <i class="bi bi-download me-2"></i>Export Data
                        </button>
                        <button type="button" class="btn btn-lg btn-outline-secondary" onclick="resetSelection()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </button>
                        <span class="text-muted ms-3" id="exportSummary"></span>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Quick Export All -->
        <div class="card mb-4">
            <div class="card-header text-white" style="background:var(--vle-gradient-primary);">
                <h5 class="mb-0"><i class="bi bi-lightning-fill me-2"></i>Quick Export</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Export all records from a category with default columns in one click.</p>
                <div class="row g-2">
                    <?php foreach ($export_categories as $key => $cat): 
                        if ($category_counts[$key] <= 0) continue;
                    ?>
                    <div class="col-auto">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="export">
                            <input type="hidden" name="category" value="<?= $key ?>">
                            <input type="hidden" name="format" value="csv">
                            <?php foreach ($cat['default_columns'] as $dc): ?>
                                <input type="hidden" name="columns[]" value="<?= $dc ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi <?= $cat['icon'] ?> me-1" style="color:<?= $cat['color'] ?>;"></i><?= $cat['label'] ?>
                                <span class="badge bg-light text-dark ms-1"><?= $category_counts[$key] ?></span>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Export categories data from PHP
    const categories = <?= json_encode($export_categories) ?>;
    const categoryCounts = <?= json_encode($category_counts) ?>;
    let currentCategory = null;
    
    function selectCategory(key) {
        if (categoryCounts[key] < 0) {
            alert('This table does not exist in the database.');
            return;
        }
        
        currentCategory = key;
        const cat = categories[key];
        
        // Highlight selected card
        document.querySelectorAll('.export-card').forEach(c => c.classList.remove('selected'));
        document.querySelector(`.export-card[data-category="${key}"]`).classList.add('selected');
        
        // Update step indicators
        document.getElementById('step1Indicator').className = 'step done';
        document.getElementById('stepLine1').className = 'step-line done';
        document.getElementById('step2Indicator').className = 'step active';
        
        // Show config panel
        document.getElementById('step2').classList.remove('d-none');
        document.getElementById('selectedCategoryLabel').textContent = cat.label;
        document.getElementById('exportCategory').value = key;
        
        // Build column checkboxes
        const colList = document.getElementById('columnList');
        colList.innerHTML = '';
        const defaults = cat.default_columns || [];
        for (const [col, label] of Object.entries(cat.columns)) {
            const isDefault = defaults.includes(col);
            const el = document.createElement('label');
            el.className = 'column-checkbox';
            el.innerHTML = `<input type="checkbox" name="columns[]" value="${col}" ${isDefault ? 'checked' : ''} onchange="updateColumnCount()"><span>${label}</span>`;
            colList.appendChild(el);
        }
        updateColumnCount();
        
        // Show relevant filters
        const colKeys = Object.keys(cat.columns);
        document.getElementById('filterActiveWrap').style.display = colKeys.includes('is_active') ? '' : 'none';
        document.getElementById('filterDeptWrap').style.display = colKeys.includes('department') ? '' : 'none';
        document.getElementById('filterProgramTypeWrap').style.display = colKeys.includes('program_type') ? '' : 'none';
        
        // Reset filters
        document.getElementById('filterActive').checked = false;
        document.getElementById('filterDept').value = '';
        document.getElementById('filterProgType').value = '';
        
        // Scroll to config
        document.getElementById('step2').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    
    function updateColumnCount() {
        const checked = document.querySelectorAll('#columnList input[type="checkbox"]:checked');
        document.getElementById('selectedCount').textContent = checked.length;
        document.getElementById('exportBtn').disabled = checked.length === 0;
        
        // Update step 3 indicator
        if (checked.length > 0) {
            document.getElementById('stepLine2').className = 'step-line done';
            document.getElementById('step3Indicator').className = 'step active';
        } else {
            document.getElementById('stepLine2').className = 'step-line';
            document.getElementById('step3Indicator').className = 'step';
        }
        
        // Update summary
        const format = document.getElementById('exportFormat').value.toUpperCase();
        const count = categoryCounts[currentCategory] || 0;
        document.getElementById('exportSummary').textContent = checked.length > 0 
            ? `${checked.length} columns × ${count.toLocaleString()} records → ${format}` 
            : '';
    }
    
    function selectAllColumns() {
        document.querySelectorAll('#columnList input[type="checkbox"]').forEach(cb => cb.checked = true);
        updateColumnCount();
    }
    
    function selectDefaultColumns() {
        if (!currentCategory) return;
        const defaults = categories[currentCategory].default_columns || [];
        document.querySelectorAll('#columnList input[type="checkbox"]').forEach(cb => {
            cb.checked = defaults.includes(cb.value);
        });
        updateColumnCount();
    }
    
    function clearAllColumns() {
        document.querySelectorAll('#columnList input[type="checkbox"]').forEach(cb => cb.checked = false);
        updateColumnCount();
    }
    
    function selectFormat(fmt, el) {
        document.querySelectorAll('.format-option').forEach(f => f.classList.remove('selected'));
        el.classList.add('selected');
        document.getElementById('exportFormat').value = fmt;
        updateColumnCount();
    }
    
    function resetSelection() {
        currentCategory = null;
        document.querySelectorAll('.export-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('step2').classList.add('d-none');
        document.getElementById('step1Indicator').className = 'step active';
        document.getElementById('stepLine1').className = 'step-line';
        document.getElementById('step2Indicator').className = 'step';
        document.getElementById('stepLine2').className = 'step-line';
        document.getElementById('step3Indicator').className = 'step';
        document.getElementById('step1').scrollIntoView({ behavior: 'smooth' });
    }
    
    // Export form submit handler - show spinner
    document.getElementById('exportForm').addEventListener('submit', function() {
        const btn = document.getElementById('exportBtn');
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Exporting...';
        btn.disabled = true;
        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-download me-2"></i>Export Data';
            btn.disabled = false;
        }, 3000);
    });
    </script>
</body>
</html>
