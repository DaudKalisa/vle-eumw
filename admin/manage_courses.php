<?php
// manage_courses.php - Admin manage courses and student access
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

$success_message = '';
$error_message = '';

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="courses_template.csv"');
    
    echo "Course Code,Course Name,Description,Program of Study,Year of Study,Total Weeks,Lecturer ID\n";
    echo "CS101,Introduction to Programming,Basic programming concepts,Computer Science,1,16,\n";
    echo "CS201,Data Structures,Advanced data structures,Computer Science,2,16,\n";
    echo "BUS101,Business Fundamentals,Introduction to business,Business Administration,1,16,\n";
    exit;
}

// Handle template upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['course_template'])) {
    $file = $_FILES['course_template'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_path = $file['tmp_name'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_extension === 'csv') {
            $handle = fopen($file_path, 'r');
            
            if ($handle !== false) {
                // Skip header row
                $header = fgetcsv($handle);
                
                $imported_count = 0;
                $error_rows = [];
                $row_number = 1;
                
                while (($data = fgetcsv($handle)) !== false) {
                    $row_number++;
                    
                    if (count($data) < 6) {
                        $error_rows[] = "Row $row_number: Insufficient columns";
                        continue;
                    }
                    
                    $course_code = trim($data[0]);
                    $course_name = trim($data[1]);
                    $description = trim($data[2]);
                    $program = trim($data[3]);
                    $year_of_study = isset($data[4]) ? (int)trim($data[4]) : 1;
                    $total_weeks = isset($data[5]) ? (int)trim($data[5]) : 16;
                    $lecturer_id = isset($data[6]) && !empty(trim($data[6])) ? (int)trim($data[6]) : NULL;
                    
                    if (empty($course_code) || empty($course_name)) {
                        $error_rows[] = "Row $row_number: Course code and name are required";
                        continue;
                    }
                    
                    // Check if course code already exists
                    $check_stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_code = ?");
                    $check_stmt->bind_param("s", $course_code);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error_rows[] = "Row $row_number: Course code '$course_code' already exists";
                        $check_stmt->close();
                        continue;
                    }
                    $check_stmt->close();
                    
                    // Insert course
                    $stmt = $conn->prepare("INSERT INTO vle_courses (course_code, course_name, description, lecturer_id, total_weeks, program_of_study, year_of_study) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssiisi", $course_code, $course_name, $description, $lecturer_id, $total_weeks, $program, $year_of_study);
                    
                    if ($stmt->execute()) {
                        $imported_count++;
                    } else {
                        $error_rows[] = "Row $row_number: Database error - " . $stmt->error;
                    }
                    $stmt->close();
                }
                
                fclose($handle);
                
                if ($imported_count > 0) {
                    $success_message = "Successfully imported $imported_count course(s)!";
                }
                
                if (!empty($error_rows)) {
                    $error_message = "Import completed with errors:\n" . implode("\n", $error_rows);
                }
                
                if ($imported_count === 0 && empty($error_rows)) {
                    $error_message = "No valid courses found in the template.";
                }
            } else {
                $error_message = "Error opening uploaded file.";
            }
        } else {
            $error_message = "Invalid file format. Please upload a CSV file.";
        }
    } else {
        $error_message = "Error uploading file. Please try again.";
    }
}

// Handle course creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_course'])) {
    $course_code = $_POST['course_code'];
    $course_name = $_POST['course_name'];
    $description = $_POST['description'];
    $program = $_POST['program'];
    $year_of_study = $_POST['year_of_study'];
    $lecturer_id = !empty($_POST['lecturer_id']) ? $_POST['lecturer_id'] : NULL;
    $total_weeks = $_POST['total_weeks'];
    
    $stmt = $conn->prepare("INSERT INTO vle_courses (course_code, course_name, description, lecturer_id, total_weeks, program_of_study, year_of_study) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiisi", $course_code, $course_name, $description, $lecturer_id, $total_weeks, $program, $year_of_study);
    
    if ($stmt->execute()) {
        $success_message = "Course created successfully!";
    } else {
        $error_message = "Error creating course: " . $conn->error;
    }
    $stmt->close();
}

// Handle bulk student enrollment by program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_by_program'])) {
    $course_id = (int)$_POST['course_id'];
    $program = $_POST['program_filter'];
    $year = $_POST['year_filter'];
    $semester = $_POST['semester_filter'];
    
    // Get students by program, year, and semester
    $where_conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($program)) {
        $where_conditions[] = "program = ?";
        $params[] = $program;
        $types .= "s";
    }
    if (!empty($year)) {
        $where_conditions[] = "year_of_study = ?";
        $params[] = $year;
        $types .= "i";
    }
    if (!empty($semester)) {
        $where_conditions[] = "semester = ?";
        $params[] = $semester;
        $types .= "s";
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    $stmt = $conn->prepare("SELECT student_id FROM students $where_clause");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrolled_count = 0;
    while ($row = $result->fetch_assoc()) {
        $student_id = $row['student_id'];
        
        // Check if already enrolled
        $check_stmt = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
        $check_stmt->bind_param("is", $course_id, $student_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            $enroll_stmt = $conn->prepare("INSERT INTO vle_enrollments (course_id, student_id) VALUES (?, ?)");
            $enroll_stmt->bind_param("is", $course_id, $student_id);
            if ($enroll_stmt->execute()) {
                $enrolled_count++;
            }
            $enroll_stmt->close();
        }
        $check_stmt->close();
    }
    $stmt->close();
    
    $success_message = "$enrolled_count student(s) enrolled successfully!";
}

// Handle individual student enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_student'])) {
    $course_id = (int)$_POST['course_id'];
    $student_id = $_POST['student_id'];

    // Check if already enrolled
    $stmt = $conn->prepare("SELECT * FROM vle_enrollments WHERE course_id = ? AND student_id = ?");
    $stmt->bind_param("is", $course_id, $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO vle_enrollments (course_id, student_id) VALUES (?, ?)");
        $stmt->bind_param("is", $course_id, $student_id);
        if ($stmt->execute()) {
            $success_message = "Student enrolled successfully!";
        } else {
            $error_message = "Error enrolling student: " . $conn->error;
        }
    } else {
        $error_message = "Student is already enrolled in this course!";
    }
    $stmt->close();
}

// Handle bulk student allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_allocate_students'])) {
    $course_id = (int)$_POST['allocate_course_id'];
    $student_ids = $_POST['student_ids'] ?? [];
    
    if (empty($student_ids)) {
        $error_message = "Please select at least one student to allocate.";
    } else {
        $success_count = 0;
        $already_enrolled = 0;
        
        foreach ($student_ids as $student_id) {
            $student_id = trim($student_id);
            
            // Check if already enrolled
            $check = $conn->prepare("SELECT enrollment_id FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
            $check->bind_param("si", $student_id, $course_id);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $already_enrolled++;
            } else {
                // Allocate student to course
                $stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                $stmt->bind_param("si", $student_id, $course_id);
                if ($stmt->execute()) {
                    $success_count++;
                }
                $stmt->close();
            }
            $check->close();
        }
        
        if ($success_count > 0) {
            $success_message = "$success_count student(s) allocated successfully!";
            if ($already_enrolled > 0) {
                $success_message .= " ($already_enrolled already enrolled)";
            }
        } else if ($already_enrolled > 0) {
            $error_message = "All selected students are already enrolled in this course.";
        }
    }
}

// Handle student unenrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unenroll_student'])) {
    $enrollment_id = (int)$_POST['enrollment_id'];
    
    $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE enrollment_id = ?");
    $stmt->bind_param("i", $enrollment_id);
    if ($stmt->execute()) {
        $success_message = "Student unenrolled successfully!";
    } else {
        $error_message = "Error unenrolling student: " . $conn->error;
    }
    $stmt->close();
}

// Get all students with program info
$students = [];
$result = $conn->query("SELECT student_id, full_name, program, year_of_study FROM students ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get all lecturers
$lecturers = [];
$result = $conn->query("SELECT lecturer_id, full_name FROM lecturers ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $lecturers[] = $row;
}

// Get distinct programs
$programs = [];
$result = $conn->query("SELECT DISTINCT program FROM students WHERE program IS NOT NULL AND program != '' ORDER BY program");
while ($row = $result->fetch_assoc()) {
    $programs[] = $row['program'];
}

// Check if program_of_study and year_of_study columns exist in vle_courses
$columns_exist = false;
$result = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'program_of_study'");
if ($result->num_rows === 0) {
    // Add missing columns
    $conn->query("ALTER TABLE vle_courses ADD COLUMN program_of_study VARCHAR(100) AFTER lecturer_id");
    $conn->query("ALTER TABLE vle_courses ADD COLUMN year_of_study INT AFTER program_of_study");
}

// Get all courses with lecturer info and enrolled students
$courses = [];
$result = $conn->query("
    SELECT vc.*, l.full_name as lecturer_name,
           COUNT(ve.enrollment_id) as enrolled_students
    FROM vle_courses vc
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    LEFT JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    GROUP BY vc.course_id
    ORDER BY vc.created_date DESC
");
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
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
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="bi bi-book-half text-warning"></i> Manage Courses</h2>
                        <p class="text-muted">Create courses, manage enrollments, and assign students by program</p>
                    </div>
                    <div class="btn-group" role="group">
                        <a href="?download_template" class="btn btn-success btn-lg">
                            <i class="bi bi-download"></i> Download Template
                        </a>
                        <button class="btn btn-info btn-lg" data-bs-toggle="modal" data-bs-target="#uploadTemplateModal">
                            <i class="bi bi-upload"></i> Upload Template
                        </button>
                        <button class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                            <i class="bi bi-plus-circle"></i> Add New Course
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Courses List -->
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Courses (<?php echo count($courses); ?>)</h5>
            </div>
            <div class="card-body">
                <!-- Filter Section -->
                <div class="row g-3 mb-3 p-3 bg-light rounded">
                    <div class="col-md-4">
                        <label class="form-label fw-bold"><i class="bi bi-funnel"></i> Filter by Program</label>
                        <select class="form-select" id="courseFilterProgram" onchange="filterCourses()">
                            <option value="">All Programs</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold"><i class="bi bi-search"></i> Search</label>
                        <input type="text" class="form-control" id="courseSearch" placeholder="Search by code or name..." oninput="filterCourses()">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button class="btn btn-secondary" onclick="clearCourseFilters()">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <p class="mt-3">No courses found. Click "Add New Course" to create one.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Lecturer</th>
                                    <th>Enrolled</th>
                                    <th>Weeks</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="coursesTableBody">
                                <?php foreach ($courses as $course): ?>
                                    <tr data-program="<?php echo htmlspecialchars($course['program_of_study'] ?? ''); ?>" data-code="<?php echo htmlspecialchars($course['course_code']); ?>" data-name="<?php echo htmlspecialchars($course['course_name']); ?>">
                                        <td><strong class="text-primary"><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                        <td><small><?php echo htmlspecialchars($course['program_of_study'] ?? 'N/A'); ?></small></td>
                                        <td>
                                            <?php if (isset($course['year_of_study'])): ?>
                                                <span class="badge bg-info">Year <?php echo $course['year_of_study']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'Not assigned'); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $course['enrolled_students']; ?> students</span>
                                        </td>
                                        <td><?php echo $course['total_weeks']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $course['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="openAllocateStudentsModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_code']); ?>', '<?php echo addslashes($course['course_name']); ?>', '<?php echo addslashes($course['program_of_study'] ?? ''); ?>', <?php echo $course['year_of_study'] ?? 0; ?>)"
                                                        title="Allocate students to access course content">
                                                    <i class="bi bi-person-plus-fill"></i> Allocate
                                                </button>
                                                <button class="btn btn-sm btn-success" 
                                                        onclick="openEnrollByProgramModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-people-fill"></i> By Program
                                                </button>
                                                <button class="btn btn-sm btn-primary" 
                                                        onclick="openEnrollModal(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-person-plus"></i> Individual
                                                </button>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewEnrolledStudents(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_name']); ?>')">
                                                    <i class="bi bi-eye"></i> View
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

    <!-- Upload Template Modal -->
    <div class="modal fade" id="uploadTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Course Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Instructions:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Click "Download Template" to get the CSV template</li>
                                <li>Fill in the course details in the template</li>
                                <li>Upload the completed template below</li>
                            </ol>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-file-earmark-spreadsheet"></i> Select CSV File *</label>
                            <input type="file" class="form-control" name="course_template" accept=".csv" required>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Template Format:</strong><br>
                            <small>Course Code, Course Name, Description, Program of Study, Year of Study, Total Weeks, Lecturer ID (optional)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-upload"></i> Upload & Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Course Modal -->
    <div class="modal fade" id="addCourseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="create_course" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-code"></i> Course Code *</label>
                                <input type="text" class="form-control" name="course_code" required placeholder="e.g., CS101">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-calendar-week"></i> Total Weeks *</label>
                                <input type="number" class="form-control" name="total_weeks" value="16" required min="1">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-book"></i> Course Name *</label>
                            <input type="text" class="form-control" name="course_name" required placeholder="e.g., Introduction to Programming">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-text-paragraph"></i> Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Course description..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-diagram-3"></i> Program of Study *</label>
                                <select class="form-select" name="program" required>
                                    <option value="">Select program...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-123"></i> Year of Study *</label>
                                <select class="form-select" name="year_of_study" required>
                                    <option value="">Select year...</option>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person"></i> Assign Lecturer (Optional - Can teach any course)</label>
                            <select class="form-select" name="lecturer_id">
                                <option value="">No lecturer assigned</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?php echo $lecturer['lecturer_id']; ?>"><?php echo htmlspecialchars($lecturer['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Lecturers can be assigned to courses from any department or program</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Create Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enroll by Program Modal -->
    <div class="modal fade" id="enrollByProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-people-fill"></i> Enroll Students by Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="enroll_by_program" value="1">
                        <input type="hidden" name="course_id" id="programModalCourseId">
                        
                        <div class="alert alert-info">
                            <strong>Course:</strong> <span id="programCourseName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-diagram-3"></i> Filter by Program</label>
                            <select class="form-select" name="program_filter">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-123"></i> Filter by Year of Study</label>
                            <select class="form-select" name="year_filter">
                                <option value="">All Years</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-calendar-check"></i> Filter by Semester</label>
                            <select class="form-select" name="semester_filter">
                                <option value="">All Semesters</option>
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle"></i> All students matching the selected filters will be enrolled in this course.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-people-fill"></i> Enroll Students</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Individual Student Enrollment Modal -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Enroll Individual Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="enroll_student" value="1">
                        <input type="hidden" name="course_id" id="modalCourseId">
                        
                        <div class="alert alert-info">
                            <strong>Course:</strong> <span id="courseName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="bi bi-person"></i> Select Student *</label>
                            <select class="form-select" name="student_id" id="student_id" required>
                                <option value="">Choose a student...</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['student_id']; ?>">
                                        <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['student_id'] . ') - ' . ($student['program'] ?? 'No Program') . ' - Year ' . ($student['year_of_study'] ?? 'N/A')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus"></i> Enroll Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Enrolled Students Modal -->
    <div class="modal fade" id="viewStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye"></i> Enrolled Students in <span id="viewCourseName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="enrolledStudentsList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Allocate Students Modal -->
    <div class="modal fade" id="allocateStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill"></i> Allocate Students to Course: <span id="allocateCourseTitle"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="allocateStudentsForm">
                    <div class="modal-body">
                        <input type="hidden" name="bulk_allocate_students" value="1">
                        <input type="hidden" name="allocate_course_id" id="allocateCourseId">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Select students to give them access to course content uploaded by the lecturer
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Filter by Program</label>
                                <select class="form-select" id="filterProgram">
                                    <option value="">All Programs</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Filter by Year</label>
                                <select class="form-select" id="filterYear">
                                    <option value="">All Years</option>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" id="searchStudent" placeholder="Search by ID or name...">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllStudents()">
                                <i class="bi bi-check-all"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">
                                <i class="bi bi-x-circle"></i> Deselect All
                            </button>
                            <span class="ms-3 badge bg-primary" id="selectedCount">0 selected</span>
                        </div>
                        
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover table-sm">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAllCheckbox" onchange="toggleAllStudents(this)">
                                        </th>
                                        <th>Student ID</th>
                                        <th>Full Name</th>
                                        <th>Program</th>
                                        <th>Year</th>
                                        <th>Semester</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="studentsTableBody">
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <div class="spinner-border spinner-border-sm" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div> Loading students...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-circle"></i> Allocate Selected Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let allStudents = [];
        let currentCourseId = null;
        let currentCourseProgram = '';
        let currentCourseYear = 0;
        
        function openAllocateStudentsModal(courseId, courseCode, courseName, program, year) {
            currentCourseId = courseId;
            currentCourseProgram = program;
            currentCourseYear = year;
            
            document.getElementById('allocateCourseTitle').textContent = courseCode + ' - ' + courseName;
            document.getElementById('allocateCourseId').value = courseId;
            
            const modal = new bootstrap.Modal(document.getElementById('allocateStudentsModal'));
            modal.show();
            
            // Load students
            loadStudentsForAllocation(program, year);
        }
        
        function loadStudentsForAllocation(suggestedProgram, suggestedYear) {
            fetch('get_students_for_allocation.php?course_id=' + currentCourseId)
                .then(response => response.json())
                .then(data => {
                    allStudents = data.students || [];
                    
                    // Populate program filter
                    const programFilter = document.getElementById('filterProgram');
                    const programs = [...new Set(allStudents.map(s => s.program))];
                    programFilter.innerHTML = '<option value="">All Programs</option>';
                    programs.forEach(prog => {
                        const option = document.createElement('option');
                        option.value = prog;
                        option.textContent = prog;
                        if (prog === suggestedProgram) option.selected = true;
                        programFilter.appendChild(option);
                    });
                    
                    // Set suggested year
                    if (suggestedYear) {
                        document.getElementById('filterYear').value = suggestedYear;
                    }
                    
                    displayStudents();
                })
                .catch(error => {
                    document.getElementById('studentsTableBody').innerHTML = 
                        '<tr><td colspan="7" class="text-center text-danger">Error loading students</td></tr>';
                });
        }
        
        function displayStudents() {
            const filterProgram = document.getElementById('filterProgram').value;
            const filterYear = document.getElementById('filterYear').value;
            const searchTerm = document.getElementById('searchStudent').value.toLowerCase();
            
            const filtered = allStudents.filter(student => {
                const matchProgram = !filterProgram || student.program === filterProgram;
                const matchYear = !filterYear || student.year_of_study == filterYear;
                const matchSearch = !searchTerm || 
                    student.student_id.toLowerCase().includes(searchTerm) ||
                    student.full_name.toLowerCase().includes(searchTerm);
                
                return matchProgram && matchYear && matchSearch;
            });
            
            const tbody = document.getElementById('studentsTableBody');
            tbody.innerHTML = '';
            
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No students found</td></tr>';
                return;
            }
            
            filtered.forEach(student => {
                const isEnrolled = student.is_enrolled;
                const matchesCourse = student.program === currentCourseProgram && student.year_of_study == currentCourseYear;
                
                const row = document.createElement('tr');
                if (matchesCourse) row.classList.add('table-success', 'table-success-subtle');
                if (isEnrolled) row.classList.add('table-warning');
                
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input student-checkbox" 
                               name="student_ids[]" value="${student.student_id}"
                               ${isEnrolled ? 'disabled' : ''}
                               onchange="updateSelectedCount()">
                    </td>
                    <td>${student.student_id}</td>
                    <td>${student.full_name}</td>
                    <td><span class="badge ${matchesCourse ? 'bg-success' : 'bg-secondary'}">${student.program}</span></td>
                    <td>Year ${student.year_of_study}</td>
                    <td>Sem ${student.semester}</td>
                    <td>
                        ${isEnrolled ? '<span class="badge bg-warning text-dark">Already Enrolled</span>' : 
                          matchesCourse ? '<span class="badge bg-success">Matching</span>' : 
                          '<span class="badge bg-light text-dark">Available</span>'}
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            updateSelectedCount();
        }
        
        function toggleAllStudents(checkbox) {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateSelectedCount();
        }
        
        function selectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }
        
        function deselectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const count = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count + ' selected';
        }
        
        // Event listeners for filters
        document.getElementById('filterProgram')?.addEventListener('change', displayStudents);
        document.getElementById('filterYear')?.addEventListener('change', displayStudents);
        document.getElementById('searchStudent')?.addEventListener('input', displayStudents);
        
        // Course filtering functionality
        function filterCourses() {
            const programFilter = document.getElementById('courseFilterProgram').value.toLowerCase();
            const searchTerm = document.getElementById('courseSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#coursesTableBody tr');
            
            let visibleCount = 0;
            rows.forEach(row => {
                const program = (row.dataset.program || '').toLowerCase();
                const code = (row.dataset.code || '').toLowerCase();
                const name = (row.dataset.name || '').toLowerCase();
                
                const matchesProgram = !programFilter || program === programFilter;
                const matchesSearch = !searchTerm || code.includes(searchTerm) || name.includes(searchTerm);
                
                if (matchesProgram && matchesSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function clearCourseFilters() {
            document.getElementById('courseFilterProgram').value = '';
            document.getElementById('courseSearch').value = '';
            filterCourses();
            // Remove URL parameter
            window.history.pushState({}, '', 'manage_courses.php');
        }
        
        // Initialize course program filter on page load
        document.addEventListener('DOMContentLoaded', function() {
            const programFilter = document.getElementById('courseFilterProgram');
            
            // Get all unique programs from table rows
            const programs = new Set();
            document.querySelectorAll('#coursesTableBody tr').forEach(row => {
                const program = row.dataset.program;
                if (program && program !== 'N/A') {
                    programs.add(program);
                }
            });
            
            // Populate filter dropdown
            Array.from(programs).sort().forEach(program => {
                const option = document.createElement('option');
                option.value = program.toLowerCase();
                option.textContent = program;
                programFilter.appendChild(option);
            });
            
            // Check for URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const programParam = urlParams.get('program');
            
            if (programParam) {
                // Set the filter and apply
                programFilter.value = programParam.toLowerCase();
                filterCourses();
                
                // Show notification
                const alert = document.createElement('div');
                alert.className = 'alert alert-info alert-dismissible fade show';
                alert.innerHTML = `
                    <i class="bi bi-filter-circle"></i> Showing courses for program: <strong>${programParam}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                const container = document.querySelector('.container-fluid');
                container.insertBefore(alert, container.firstChild.nextSibling.nextSibling);
            }
        });
        
        function openEnrollByProgramModal(courseId, courseName) {
            document.getElementById('programModalCourseId').value = courseId;
            document.getElementById('programCourseName').textContent = courseName;
            new bootstrap.Modal(document.getElementById('enrollByProgramModal')).show();
        }
        
        function openEnrollModal(courseId, courseName) {
            document.getElementById('modalCourseId').value = courseId;
            document.getElementById('courseName').textContent = courseName;
            new bootstrap.Modal(document.getElementById('enrollModal')).show();
        }
        
        function viewEnrolledStudents(courseId, courseName) {
            document.getElementById('viewCourseName').textContent = courseName;
            const modal = new bootstrap.Modal(document.getElementById('viewStudentsModal'));
            modal.show();
            
            // Fetch enrolled students via AJAX
            fetch('get_enrolled_students.php?course_id=' + courseId)
                .then(response => response.json())
                .then(data => {
                    let html = '';
                    if (data.students && data.students.length > 0) {
                        html = '<div class="table-responsive"><table class="table table-striped"><thead class="table-dark"><tr><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Enrolled Date</th><th>Action</th></tr></thead><tbody>';
                        data.students.forEach(student => {
                            html += `<tr>
                                <td>${student.student_id}</td>
                                <td>${student.full_name}</td>
                                <td>${student.program || 'N/A'}</td>
                                <td>${student.year_of_study ? 'Year ' + student.year_of_study : 'N/A'}</td>
                                <td>${student.enrollment_date}</td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to unenroll this student?');">
                                        <input type="hidden" name="unenroll_student" value="1">
                                        <input type="hidden" name="enrollment_id" value="${student.enrollment_id}">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Unenroll</button>
                                    </form>
                                </td>
                            </tr>`;
                        });
                        html += '</tbody></table></div>';
                    } else {
                        html = '<div class="alert alert-warning"><i class="bi bi-exclamation-circle"></i> No students enrolled in this course yet.</div>';
                    }
                    document.getElementById('enrolledStudentsList').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('enrolledStudentsList').innerHTML = '<div class="alert alert-danger">Error loading students</div>';
                });
        }
    </script>
</body>
</html>