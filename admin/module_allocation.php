<?php
// module_allocation.php - Admin module allocation to students
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();

$success = '';
$error = '';

// Handle module allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['allocate_course'])) {
        $student_id = (int)$_POST['student_id'];
        $course_id = (int)$_POST['course_id'];
        
        // Verify student exists
        $student_check = $conn->prepare("SELECT student_id, full_name, program, year_of_study, semester FROM students WHERE student_id = ?");
        $student_check->bind_param("i", $student_id);
        $student_check->execute();
        $student_result = $student_check->get_result();
        
        if ($student_result->num_rows === 0) {
            $error = "Invalid student selected!";
        } else {
            $student = $student_result->fetch_assoc();
            
            // Get course details with lecturer info
            $course_check = $conn->prepare("SELECT c.course_id, c.course_name, c.course_code, c.program_of_study, c.year_of_study, l.full_name as lecturer_name 
                                           FROM vle_courses c 
                                           LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                                           WHERE c.course_id = ?");
            $course_check->bind_param("i", $course_id);
            $course_check->execute();
            $course_result = $course_check->get_result();
            
            if ($course_result->num_rows === 0) {
                $error = "Invalid course selected!";
            } else {
                $course = $course_result->fetch_assoc();
                
                // Check 7-course limit
                $count_check = $conn->prepare("SELECT COUNT(*) as course_count FROM vle_enrollments e 
                                               JOIN students s ON e.student_id = s.student_id 
                                               WHERE e.student_id = ? AND s.semester = ?");
                $count_check->bind_param("is", $student_id, $student['semester']);
                $count_check->execute();
                $count_result = $count_check->get_result();
                $count_row = $count_result->fetch_assoc();
                
                if ($count_row['course_count'] >= 7) {
                    $error = "Student has reached maximum of 7 courses for this semester!";
                } else {
                    // Check if already allocated
                    $check = $conn->prepare("SELECT * FROM vle_enrollments WHERE student_id = ? AND course_id = ?");
                    $check->bind_param("ii", $student_id, $course_id);
                    $check->execute();
                    $result = $check->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error = "This course is already allocated to the student!";
                    } else {
                        $stmt = $conn->prepare("INSERT INTO vle_enrollments (student_id, course_id, enrollment_date) VALUES (?, ?, NOW())");
                        $stmt->bind_param("ii", $student_id, $course_id);
                        
                        if ($stmt->execute()) {
                            $lecturer_info = $course['lecturer_name'] ? " (Lecturer: " . htmlspecialchars($course['lecturer_name']) . ")" : "";
                            $success = "Course '" . htmlspecialchars($course['course_code']) . "' successfully allocated to " . htmlspecialchars($student['full_name']) . "!" . $lecturer_info . " (" . ($count_row['course_count'] + 1) . "/7 courses)";
                        } else {
                            $error = "Failed to allocate course: " . $stmt->error;
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['remove_allocation'])) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        
        $stmt = $conn->prepare("DELETE FROM vle_enrollments WHERE enrollment_id = ?");
        $stmt->bind_param("i", $enrollment_id);
        
        if ($stmt->execute()) {
            $success = "Course allocation removed successfully!";
        } else {
            $error = "Failed to remove allocation.";
        }
    }
}

// Get all students with their year and semester
$students = [];
$result = $conn->query("SELECT student_id, full_name, program, department, year_of_study, semester FROM students WHERE is_active = TRUE ORDER BY full_name");
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Get only courses that are assigned to semesters with lecturer information
$courses = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'semester_courses'");
if ($tableCheck->num_rows > 0) {
    // Only get courses that are in semester_courses table with is_active = TRUE and have lecturer assigned
    $result = $conn->query("SELECT DISTINCT c.course_id, c.course_name, c.course_code, c.program_of_study, c.year_of_study, c.total_weeks,
                                   sc.semester, sc.academic_year, c.lecturer_id,
                                   l.full_name as lecturer_name
                           FROM vle_courses c
                           INNER JOIN semester_courses sc ON c.course_id = sc.course_id
                           LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
                           WHERE c.is_active = TRUE AND sc.is_active = TRUE
                           ORDER BY c.program_of_study, c.year_of_study, c.course_code");
} else {
    // Fallback if semester_courses table doesn't exist
    $result = $conn->query("SELECT c.course_id, c.course_name, c.course_code, c.program_of_study, c.year_of_study, c.total_weeks, 
                                   NULL as semester, NULL as academic_year, c.lecturer_id, l.full_name as lecturer_name 
                           FROM vle_courses c 
                           LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                           WHERE c.is_active = TRUE ORDER BY c.course_code");
}
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
// Get current allocations
$allocations = [];
$query = "SELECT e.enrollment_id, e.enrollment_date,
                 s.student_id, s.full_name as student_name, s.program, s.year_of_study, s.semester,
                 c.course_id, c.course_name, c.course_code, c.program_of_study, c.year_of_study as course_year
          FROM vle_enrollments e
          JOIN students s ON e.student_id = s.student_id
          JOIN vle_courses c ON e.course_id = c.course_id
          ORDER BY e.enrollment_date DESC, s.full_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $allocations[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Allocation - Admin</title>
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

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="bi bi-person-lines-fill text-primary"></i> Course Allocation to Students</h2>
                <p class="text-muted mb-0">Assign courses to students for their academic programs</p>
            </div>
            <div>
                <a href="semester_course_assignment.php" class="btn btn-outline-dark me-2">
                    <i class="bi bi-calendar-plus"></i> Manage Semester Courses
                </a>
                <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#allocateModal">
                    <i class="bi bi-plus-circle"></i> Allocate Course to Student
                </button>
            </div>
        </div>

        <?php if (empty($courses)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> 
                <strong>No courses available for allocation!</strong> 
                You need to assign courses to semesters first. 
                <a href="semester_course_assignment.php" class="alert-link">Go to Semester Course Assignment</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-people-fill text-primary" style="font-size: 2.5rem;"></i>
                        <h4 class="mt-2"><?php echo count($students); ?></h4>
                        <p class="text-muted mb-0">Active Students</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-journal-code text-success" style="font-size: 2.5rem;"></i>
                        <h4 class="mt-2"><?php echo count($courses); ?></h4>
                        <p class="text-muted mb-0">Available Courses</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-bookmark-check-fill text-info" style="font-size: 2.5rem;"></i>
                        <h4 class="mt-2"><?php echo count($allocations); ?></h4>
                        <p class="text-muted mb-0">Total Allocations</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Allocations Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> Current Course Allocations</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Program</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Year</th>
                                <th>Semester</th>
                                <th>Enrollment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allocations)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                        <p class="mb-0">No course allocations found. Click "Allocate to Student" to assign courses.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allocations as $allocation): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($allocation['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($allocation['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($allocation['program']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($allocation['course_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($allocation['course_name']); ?></td>
                                        <td><span class="badge bg-info">Year <?php echo $allocation['course_year']; ?></span></td>
                                        <td><span class="badge bg-success">Sem <?php echo htmlspecialchars($allocation['semester']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($allocation['enrollment_date'])); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this course allocation?');">
                                                <input type="hidden" name="enrollment_id" value="<?php echo $allocation['enrollment_id']; ?>">
                                                <button type="submit" name="remove_allocation" class="btn btn-sm btn-danger" title="Remove Allocation">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
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

    <!-- Allocate Course Modal -->
    <div class="modal fade" id="allocateModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Allocate Course to Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="allocateForm">
                    <div class="modal-body">
                        <input type="hidden" name="allocate_course" value="1">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Select Student <span class="text-danger">*</span></label>
                                <select class="form-select" name="student_id" required id="studentSelect">
                                    <option value="">Choose a student...</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['student_id']; ?>" 
                                                data-program="<?php echo htmlspecialchars($student['program']); ?>"
                                                data-year="<?php echo $student['year_of_study']; ?>"
                                                data-semester="<?php echo htmlspecialchars($student['semester']); ?>">
                                            <?php echo htmlspecialchars($student['full_name']); ?>
                                            (<?php echo htmlspecialchars($student['program']); ?>, Year <?php echo $student['year_of_study']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Search Courses</label>
                                <input type="text" class="form-control" id="courseSearchInput" placeholder="Search by code, name, or lecturer...">
                                <small class="text-muted">Filter courses by code, name, lecturer, or semester</small>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12" id="studentInfo" style="display:none;">
                                <div class="alert alert-info">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <strong>Program:</strong> <span id="studentProgram"></span> |
                                            <strong>Year:</strong> <span id="studentYear"></span> |
                                            <strong>Semester:</strong> <span id="studentSemester"></span>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <span class="badge bg-warning text-dark" id="courseCount">0/7 courses</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-2">
                            <div class="col-12">
                                <label class="form-label">Select Course <span class="text-danger">*</span></label>
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover table-sm">
                                        <thead class="table-primary sticky-top">
                                            <tr>
                                                <th width="40"></th>
                                                <th>Code</th>
                                                <th>Course Name</th>
                                                <th>Lecturer</th>
                                                <th>Program</th>
                                                <th>Year</th>
                                                <th>Semester</th>
                                            </tr>
                                        </thead>
                                        <tbody id="courseTableBody">
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">
                                                    <i class="bi bi-arrow-up-circle fs-3"></i><br>
                                                    Please select a student first
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <input type="hidden" name="course_id" id="selectedCourseId" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Allocate Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const allCourses = <?php echo json_encode($courses); ?>;
        let filteredCourses = [];
        let selectedCourseId = null;
        
        // Student selection handler
        document.getElementById('studentSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const studentInfo = document.getElementById('studentInfo');
            const courseTableBody = document.getElementById('courseTableBody');
            
            if (this.value) {
                const studentId = this.value;
                const program = selectedOption.getAttribute('data-program');
                const year = selectedOption.getAttribute('data-year');
                const semester = selectedOption.getAttribute('data-semester');
                
                // Display student info
                document.getElementById('studentProgram').textContent = program;
                document.getElementById('studentYear').textContent = 'Year ' + year;
                document.getElementById('studentSemester').textContent = 'Semester ' + semester;
                studentInfo.style.display = 'block';
                
                // Filter courses by program and year
                const suggestedCourses = allCourses;
                
                // Fetch course count
                fetch('get_module_count.php?student_id=' + studentId + '&semester=' + encodeURIComponent(semester))
                    .then(response => response.json())
                    .then(data => {
                        const count = data.count || 0;
                        const badge = document.getElementById('courseCount');
                        badge.textContent = count + '/7 courses';
                        
                        if (count >= 7) {
                            badge.className = 'badge bg-danger';
                            courseTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle"></i> Student has reached maximum 7 courses!</td></tr>';
                        } else {
                            badge.className = count >= 5 ? 'badge bg-warning text-dark' : 'badge bg-success';
                            populateCourseTable(filteredCourses);
                        }
                    });
            } else {
                studentInfo.style.display = 'none';
                courseTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Please select a student first</td></tr>';
            }
        });
        
        // Populate course table
        function populateCourseTable(courses) {
            const tbody = document.getElementById('courseTableBody');
            tbody.innerHTML = '';
            
            if (courses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No courses available for this student</td></tr>';
                return;
            }
            
            courses.forEach(course => {
                const row = document.createElement('tr');
                row.style.cursor = 'pointer';
                row.className = 'course-row';
                row.setAttribute('data-course-id', course.course_id);
                row.setAttribute('data-search', (course.course_code + ' ' + course.course_name + ' ' + (course.lecturer_name || '') + ' ' + (course.semester || '')).toLowerCase());
                
                const lecturerBadge = course.lecturer_name 
                    ? '<span class="badge bg-success"><i class="bi bi-person-check"></i> ' + course.lecturer_name + '</span>' 
                    : '<span class="badge bg-secondary">No lecturer</span>';
                    
                const semesterBadge = course.semester && course.academic_year
                    ? '<span class="badge bg-info">' + course.semester + ' ' + course.academic_year + '</span>'
                    : '<span class="badge bg-light text-dark">Not assigned</span>';
                
                row.innerHTML = `
                    <td class="text-center">
                        <input type="radio" name="courseRadio" value="${course.course_id}" class="form-check-input">
                    </td>
                    <td><strong>${course.course_code}</strong></td>
                    <td>${course.course_name}</td>
                    <td>${lecturerBadge}</td>
                    <td><span class="badge bg-primary">${course.program_of_study}</span></td>
                    <td>Year ${course.year_of_study}</td>
                    <td>${semesterBadge}</td>
                `;
                
                row.addEventListener('click', function() {
                    selectCourse(course.course_id, this);
                });
                
                tbody.appendChild(row);
            });
        }
        
        // Select course
        function selectCourse(courseId, row) {
            document.querySelectorAll('.course-row').forEach(r => r.classList.remove('table-active'));
            row.classList.add('table-active');
            row.querySelector('input[type="radio"]').checked = true;
            document.getElementById('selectedCourseId').value = courseId;
        }
        
        // Search courses
        document.getElementById('courseSearchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.course-row');
            
            rows.forEach(row => {
                const searchText = row.getAttribute('data-search');
                if (searchText.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Reset modal on close
        document.getElementById('allocateModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('allocateForm').reset();
            document.getElementById('studentInfo').style.display = 'none';
            document.getElementById('courseSearchInput').value = '';
            document.getElementById('selectedCourseId').value = '';
            document.getElementById('courseTableBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Please select a student first</td></tr>';
        });
    </script>
</body>
</html>
