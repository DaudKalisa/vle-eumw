<?php
// edit_course.php - Admin edit course details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

$success_message = '';
$error_message = '';

// Get course ID from URL
$course_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($course_id <= 0) {
    header('Location: manage_courses.php');
    exit;
}

// Fetch course details
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ?");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
$course = $result->fetch_assoc();
$stmt->close();

if (!$course) {
    header('Location: manage_courses.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_course'])) {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);
    $description = trim($_POST['description']);
    $program = trim($_POST['program']);
    $year_of_study = (int)$_POST['year_of_study'];
    $semester = trim($_POST['semester'] ?? 'One');
    $semester = in_array($semester, ['One', 'Two']) ? $semester : 'One';
    $lecturer_id = !empty($_POST['lecturer_id']) ? (int)$_POST['lecturer_id'] : NULL;
    $total_weeks = (int)$_POST['total_weeks'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($course_code) || empty($course_name)) {
        $error_message = "Course code and name are required!";
    } else {
        // Check if course code already exists (for different course)
        $check_stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_code = ? AND course_id != ?");
        $check_stmt->bind_param("si", $course_code, $course_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "Course code '$course_code' already exists!";
        } else {
            // Update course
            $stmt = $conn->prepare("UPDATE vle_courses SET course_code = ?, course_name = ?, description = ?, lecturer_id = ?, total_weeks = ?, program_of_study = ?, year_of_study = ?, semester = ?, is_active = ? WHERE course_id = ?");
            $stmt->bind_param("sssiiissii", $course_code, $course_name, $description, $lecturer_id, $total_weeks, $program, $year_of_study, $semester, $is_active, $course_id);
            
            if ($stmt->execute()) {
                $success_message = "Course updated successfully!";
                
                // Refresh course data
                $stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ?");
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $course = $result->fetch_assoc();
            } else {
                $error_message = "Error updating course: " . $conn->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
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

// Get programs from vle_courses as well
$result = $conn->query("SELECT DISTINCT program_of_study FROM vle_courses WHERE program_of_study IS NOT NULL AND program_of_study != '' ORDER BY program_of_study");
while ($row = $result->fetch_assoc()) {
    if (!in_array($row['program_of_study'], $programs)) {
        $programs[] = $row['program_of_study'];
    }
}
sort($programs);

// Get enrollment count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM vle_enrollments WHERE course_id = ?");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$enrollment_result = $stmt->get_result()->fetch_assoc();
$enrollment_count = $enrollment_result['count'];
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Course - <?php echo htmlspecialchars($course['course_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $breadcrumbs = [
        ['title' => 'Manage Courses', 'url' => 'manage_courses.php'],
        ['title' => 'Edit Course']
    ];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-pencil-square me-2"></i>Edit Course</h2>
                <p class="text-muted mb-0">Modify course details and settings</p>
            </div>
            <a href="manage_courses.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Courses
            </a>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert vle-alert-success alert-dismissible fade show" role="alert">
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

        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-book"></i> Course Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="update_course" value="1">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-code"></i> Course Code *</label>
                                    <input type="text" class="form-control" name="course_code" 
                                           value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar-week"></i> Total Weeks *</label>
                                    <input type="number" class="form-control" name="total_weeks" 
                                           value="<?php echo (int)$course['total_weeks']; ?>" required min="1">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-book"></i> Course Name *</label>
                                <input type="text" class="form-control" name="course_name" 
                                       value="<?php echo htmlspecialchars($course['course_name']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-text-paragraph"></i> Description</label>
                                <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($course['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-diagram-3"></i> Program of Study *</label>
                                    <select class="form-select" name="program" required>
                                        <option value="">Select program...</option>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo htmlspecialchars($program); ?>" 
                                                    <?php echo ($course['program_of_study'] == $program) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($program); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-123"></i> Year of Study *</label>
                                    <select class="form-select" name="year_of_study" required>
                                        <option value="">Select year...</option>
                                        <option value="1" <?php echo ($course['year_of_study'] == 1) ? 'selected' : ''; ?>>Year 1</option>
                                        <option value="2" <?php echo ($course['year_of_study'] == 2) ? 'selected' : ''; ?>>Year 2</option>
                                        <option value="3" <?php echo ($course['year_of_study'] == 3) ? 'selected' : ''; ?>>Year 3</option>
                                        <option value="4" <?php echo ($course['year_of_study'] == 4) ? 'selected' : ''; ?>>Year 4</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar"></i> Semester *</label>
                                    <select class="form-select" name="semester" required>
                                        <option value="">Select semester...</option>
                                        <option value="One" <?php echo ($course['semester'] == 'One') ? 'selected' : ''; ?>>Semester One</option>
                                        <option value="Two" <?php echo ($course['semester'] == 'Two') ? 'selected' : ''; ?>>Semester Two</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-person"></i> Assign Lecturer</label>
                                <select class="form-select" name="lecturer_id">
                                    <option value="">No lecturer assigned</option>
                                    <?php foreach ($lecturers as $lecturer): ?>
                                        <option value="<?php echo $lecturer['lecturer_id']; ?>" 
                                                <?php echo ($course['lecturer_id'] == $lecturer['lecturer_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($lecturer['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                           <?php echo $course['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="is_active">
                                        <i class="bi bi-toggle-on"></i> Course is Active
                                    </label>
                                </div>
                                <small class="text-muted">Inactive courses won't be visible to students</small>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Save Changes
                                </button>
                                <a href="manage_courses.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Course Info Card -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Course Information</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <strong>Course ID:</strong> <?php echo $course['course_id']; ?>
                            </li>
                            <li class="mb-2">
                                <strong>Enrolled Students:</strong> 
                                <span class="badge bg-primary"><?php echo $enrollment_count; ?></span>
                            </li>
                            <li class="mb-2">
                                <strong>Status:</strong> 
                                <span class="badge bg-<?php echo $course['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </li>
                            <li class="mb-2">
                                <strong>Created:</strong> 
                                <?php echo date('M d, Y', strtotime($course['created_date'])); ?>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Quick Actions Card -->
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="manage_courses.php" class="btn btn-outline-warning">
                                <i class="bi bi-person-plus-fill"></i> Allocate Students
                            </a>
                            <a href="manage_courses.php" class="btn btn-outline-success">
                                <i class="bi bi-people-fill"></i> Enroll by Program
                            </a>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteCourseModal">
                                <i class="bi bi-trash"></i> Delete Course
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Course Confirmation Modal -->
    <div class="modal fade" id="deleteCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash"></i> Delete Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="manage_courses.php">
                    <div class="modal-body">
                        <input type="hidden" name="delete_course" value="1">
                        <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                        
                        <div class="text-center mb-3">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                        </div>
                        
                        <p class="text-center fs-5">Are you sure you want to delete:</p>
                        <p class="text-center fw-bold fs-4 text-danger"><?php echo htmlspecialchars($course['course_name']); ?></p>
                        
                        <?php if ($enrollment_count > 0): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-people-fill"></i> <strong>Warning:</strong> This course has <?php echo $enrollment_count; ?> enrolled student(s). 
                                All enrollments will be removed.
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle"></i> <strong>This action cannot be undone!</strong> 
                            All course content, materials, and enrollment records will be permanently deleted.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/theme-switcher.js"></script>
</body>
</html>
