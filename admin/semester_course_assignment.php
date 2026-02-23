<?php
// semester_course_assignment.php - Assign courses to semesters for student allocation
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff']);

$conn = getDbConnection();
$user = getCurrentUser();

$success = '';
$error = '';

// Handle course-semester assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_courses'])) {
    $semester = $_POST['semester'] ?? '';
    $year = $_POST['academic_year'] ?? '';
    $selected_courses = $_POST['courses'] ?? [];
    
    if (empty($semester) || empty($year) || empty($selected_courses)) {
        $error = "Please select semester, academic year, and at least one course.";
    } else {
        // Check if semester_courses table exists, create if not
        $tableCheck = $conn->query("SHOW TABLES LIKE 'semester_courses'");
        if ($tableCheck->num_rows == 0) {
            $createTable = "CREATE TABLE semester_courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                semester VARCHAR(50) NOT NULL,
                academic_year VARCHAR(50) NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
                UNIQUE KEY unique_course_semester (course_id, semester, academic_year)
            )";
            if (!$conn->query($createTable)) {
                $error = "Error creating semester_courses table: " . $conn->error;
            }
        }
        
        if (empty($error)) {
            $success_count = 0;
            $duplicate_count = 0;
            
            foreach ($selected_courses as $course_id) {
                $stmt = $conn->prepare("INSERT INTO semester_courses (course_id, semester, academic_year) VALUES (?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE is_active = TRUE");
                $stmt->bind_param("iss", $course_id, $semester, $year);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success_count++;
                    } else {
                        $duplicate_count++;
                    }
                }
                $stmt->close();
            }
            
            if ($success_count > 0) {
                $success = "$success_count course(s) assigned to $semester $year successfully!";
                if ($duplicate_count > 0) {
                    $success .= " ($duplicate_count already assigned)";
                }
            } else if ($duplicate_count > 0) {
                $error = "All selected courses are already assigned to this semester.";
            }
        }
    }
}

// Handle deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_assignment'])) {
    $assignment_id = $_POST['assignment_id'] ?? 0;
    
    $stmt = $conn->prepare("UPDATE semester_courses SET is_active = FALSE WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    
    if ($stmt->execute()) {
        $success = "Course assignment deactivated successfully!";
    } else {
        $error = "Error deactivating assignment: " . $conn->error;
    }
    $stmt->close();
}

// Handle reactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_assignment'])) {
    $assignment_id = $_POST['assignment_id'] ?? 0;
    
    $stmt = $conn->prepare("UPDATE semester_courses SET is_active = TRUE WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    
    if ($stmt->execute()) {
        $success = "Course assignment reactivated successfully!";
    } else {
        $error = "Error reactivating assignment: " . $conn->error;
    }
    $stmt->close();
}

// Get all active courses
$courses_result = $conn->query("SELECT c.*, l.full_name as lecturer_name 
                               FROM vle_courses c 
                               LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id 
                               WHERE c.is_active = TRUE 
                               ORDER BY c.program_of_study, c.year_of_study, c.course_code");
$courses = [];
while ($row = $courses_result->fetch_assoc()) {
    $courses[] = $row;
}

// Get current semester assignments
$tableCheck = $conn->query("SHOW TABLES LIKE 'semester_courses'");
$assignments = [];
if ($tableCheck->num_rows > 0) {
    $assignments_result = $conn->query("SELECT sc.*, c.course_code, c.course_name, c.program_of_study, c.year_of_study,
                                       l.full_name as lecturer_name,
                                       (SELECT COUNT(*) FROM vle_enrollments e WHERE e.course_id = sc.course_id) as enrollment_count
                                       FROM semester_courses sc
                                       JOIN vle_courses c ON sc.course_id = c.course_id
                                       LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
                                       ORDER BY sc.academic_year DESC, sc.semester, c.program_of_study, c.year_of_study");
    while ($row = $assignments_result->fetch_assoc()) {
        $assignments[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Course Assignment - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        .course-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .course-card.selected {
            border-color: #0d6efd !important;
            background-color: #e7f1ff;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-mortarboard-fill"></i> VLE Admin
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="module_allocation.php">
                            <i class="bi bi-person-lines-fill"></i> Student Allocation
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="bi bi-calendar-plus"></i> Semester Course Assignment</h2>
                        <p class="text-muted">Assign courses to semesters to make them available for student allocation</p>
                    </div>
                    <div>
                        <a href="module_allocation.php" class="btn btn-success">
                            <i class="bi bi-person-lines-fill"></i> Course Allocation
                        </a>
                        <a href="manage_courses.php" class="btn btn-info">
                            <i class="bi bi-person-check-fill"></i> Assign Courses to Student
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignCoursesModal">
                            <i class="bi bi-plus-circle"></i> Assign Courses to Semester
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Current Assignments -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Current Semester Course Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assignments)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                                <p class="mt-3">No courses assigned to semesters yet. Click "Assign Courses to Semester" to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Semester</th>
                                            <th>Academic Year</th>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Program</th>
                                            <th>Year</th>
                                            <th>Lecturer</th>
                                            <th>Enrollments</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($assignment['semester']); ?></span></td>
                                                <td><?php echo htmlspecialchars($assignment['academic_year']); ?></td>
                                                <td><strong><?php echo htmlspecialchars($assignment['course_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($assignment['course_name']); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($assignment['program_of_study']); ?></span></td>
                                                <td>Year <?php echo htmlspecialchars($assignment['year_of_study']); ?></td>
                                                <td><?php echo htmlspecialchars($assignment['lecturer_name'] ?? 'Not assigned'); ?></td>
                                                <td><span class="badge bg-primary"><?php echo $assignment['enrollment_count']; ?> students</span></td>
                                                <td>
                                                    <?php if ($assignment['is_active']): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($assignment['is_active']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                            <button type="submit" name="deactivate_assignment" class="btn btn-sm btn-warning" 
                                                                    onclick="return confirm('Deactivate this course assignment?')">
                                                                <i class="bi bi-pause-circle"></i> Deactivate
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                            <button type="submit" name="reactivate_assignment" class="btn btn-sm btn-success">
                                                                <i class="bi bi-play-circle"></i> Reactivate
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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
        </div>
    </div>

    <!-- Assign Courses Modal -->
    <div class="modal fade" id="assignCoursesModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-calendar-plus"></i> Assign Courses to Semester</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Semester *</label>
                                <select name="semester" id="semesterSelect" class="form-select" required>
                                    <option value="">Select Semester</option>
                                    <option value="Semester 1">Semester 1</option>
                                    <option value="Semester 2">Semester 2</option>
                                    <option value="Summer Session">Summer Session</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Academic Year *</label>
                                <select name="academic_year" id="academicYearSelect" class="form-select" required>
                                    <option value="">Select Academic Year</option>
                                    <?php for ($year = 2026; $year >= 2020; $year--): ?>
                                        <option value="<?php echo $year; ?>/<?php echo $year + 1; ?>" <?php echo $year == 2026 ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>/<?php echo $year + 1; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="filter-section">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Filter by Program</label>
                                    <select id="programFilter" class="form-select">
                                        <option value="">All Programs</option>
                                        <?php
                                        $programs = array_unique(array_column($courses, 'program_of_study'));
                                        sort($programs);
                                        foreach ($programs as $program):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Filter by Year</label>
                                    <select id="yearFilter" class="form-select">
                                        <option value="">All Years</option>
                                        <option value="1">Year 1</option>
                                        <option value="2">Year 2</option>
                                        <option value="3">Year 3</option>
                                        <option value="4">Year 4</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" id="courseSearchInput" class="form-control" placeholder="Search course code or name...">
                                </div>
                            </div>
                        </div>

                        <!-- Course Selection -->
                        <div class="mb-3">
                            <label class="form-label">Select Courses * <small class="text-muted">(Click to select/deselect)</small></label>
                            <div class="row" id="courseGrid">
                                <?php foreach ($courses as $course): ?>
                                    <div class="col-md-6 mb-3 course-item" 
                                         data-program="<?php echo htmlspecialchars($course['program_of_study']); ?>"
                                         data-year="<?php echo $course['year_of_study']; ?>"
                                         data-search="<?php echo strtolower($course['course_code'] . ' ' . $course['course_name']); ?>">
                                        <div class="card course-card h-100" onclick="toggleCourseSelection(this, <?php echo $course['course_id']; ?>)">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input course-checkbox" type="checkbox" 
                                                           name="courses[]" value="<?php echo $course['course_id']; ?>" 
                                                           id="course<?php echo $course['course_id']; ?>">
                                                    <label class="form-check-label w-100" for="course<?php echo $course['course_id']; ?>">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="mb-1"><?php echo htmlspecialchars($course['course_code']); ?></h6>
                                                                <p class="mb-1 text-muted small"><?php echo htmlspecialchars($course['course_name']); ?></p>
                                                                <div>
                                                                    <span class="badge bg-info"><?php echo htmlspecialchars($course['program_of_study']); ?></span>
                                                                    <span class="badge bg-secondary">Year <?php echo $course['year_of_study']; ?></span>
                                                                    <?php if ($course['total_weeks']): ?>
                                                                        <span class="badge bg-primary"><?php echo $course['total_weeks']; ?> weeks</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($course['lecturer_name']): ?>
                                                                    <small class="text-muted d-block mt-1">
                                                                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($course['lecturer_name']); ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Selected: <span id="selectedCount">0</span> course(s)</strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="assign_courses" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Assign Selected Courses
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle course selection
        function toggleCourseSelection(card, courseId) {
            const checkbox = card.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
            
            updateSelectedCount();
        }

        // Update selected count
        function updateSelectedCount() {
            const count = document.querySelectorAll('.course-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count;
        }

        // Filter courses
        function filterCourses() {
            const programFilter = document.getElementById('programFilter').value;
            const yearFilter = document.getElementById('yearFilter').value;
            const searchTerm = document.getElementById('courseSearchInput').value.toLowerCase();
            
            const courseItems = document.querySelectorAll('.course-item');
            
            courseItems.forEach(item => {
                const program = item.dataset.program;
                const year = item.dataset.year;
                const searchText = item.dataset.search;
                
                const programMatch = !programFilter || program === programFilter;
                const yearMatch = !yearFilter || year === yearFilter;
                const searchMatch = !searchTerm || searchText.includes(searchTerm);
                
                if (programMatch && yearMatch && searchMatch) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Event listeners for filters
        document.getElementById('programFilter').addEventListener('change', filterCourses);
        document.getElementById('yearFilter').addEventListener('change', filterCourses);
        document.getElementById('courseSearchInput').addEventListener('input', filterCourses);

        // Update count on checkbox change
        document.querySelectorAll('.course-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Reset modal on close
        document.getElementById('assignCoursesModal').addEventListener('hidden.bs.modal', function() {
            document.querySelectorAll('.course-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelectorAll('.course-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        });
    </script>
</body>
</html>
