<?php
// manage_shared_courses.php - Manage and visualize shared courses across programs
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Ensure course_programs table exists
$table_check = $conn->query("SHOW TABLES LIKE 'course_programs'");
if (!$table_check || $table_check->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS course_programs (
        course_program_id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        program_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_course_program (course_id, program_id),
        FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
        FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
        INDEX idx_course (course_id),
        INDEX idx_program (program_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Ensure is_shared column exists in vle_courses
$col_check = $conn->query("SHOW COLUMNS FROM vle_courses LIKE 'is_shared'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE vle_courses ADD COLUMN is_shared BOOLEAN DEFAULT FALSE AFTER semester");
}

$success_message = '';
$error_message = '';

function resolveProgramIdByNameOrCodeShared($conn, $program_name_or_code) {
    $name_or_code = trim((string)$program_name_or_code);
    if ($name_or_code === '') {
        return null;
    }

    $stmt = $conn->prepare(" 
        SELECT program_id
        FROM programs
        WHERE is_active = 1
          AND (program_name = ? OR program_code = ?)
        LIMIT 1
    ");
    $stmt->bind_param("ss", $name_or_code, $name_or_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['program_id'] ?? null;
}

// Handle course sharing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Mark course as shared and add programs
    if (isset($_POST['share_course'])) {
        $course_id = (int)$_POST['course_id'];
        $program_ids = $_POST['share_programs'] ?? [];
        $program_ids = array_map('intval', array_filter($program_ids));

        // Always preserve the course primary program as associated.
        $primary_program_name = '';
        $primary_lookup = $conn->prepare("SELECT program_of_study FROM vle_courses WHERE course_id = ? LIMIT 1");
        $primary_lookup->bind_param("i", $course_id);
        $primary_lookup->execute();
        $primary_row = $primary_lookup->get_result()->fetch_assoc();
        $primary_lookup->close();
        if ($primary_row) {
            $primary_program_name = (string)($primary_row['program_of_study'] ?? '');
            $primary_program_id = resolveProgramIdByNameOrCodeShared($conn, $primary_program_name);
            if ($primary_program_id) {
                $program_ids[] = (int)$primary_program_id;
            }
        }

        $program_ids = array_values(array_unique(array_map('intval', $program_ids)));
        
        if (!empty($program_ids)) {
            // Mark course as shared
            $conn->query("UPDATE vle_courses SET is_shared = 1 WHERE course_id = $course_id");
            
            // Clear existing program associations and add new ones
            $conn->query("DELETE FROM course_programs WHERE course_id = $course_id");
            
            $insert_stmt = $conn->prepare("INSERT INTO course_programs (course_id, program_id) VALUES (?, ?)");
            $success_count = 0;
            
            foreach ($program_ids as $prog_id) {
                $insert_stmt->bind_param("ii", $course_id, $prog_id);
                if ($insert_stmt->execute()) {
                    $success_count++;
                }
            }
            $insert_stmt->close();
            
            $success_message = "Course shared with $success_count program(s)! Students from those programs can now access this course.";
        } else {
            $error_message = "Please select at least one program to share the course with.";
        }
    }
    
    // Remove course from shared programs
    elseif (isset($_POST['unshare_course'])) {
        $course_id = (int)$_POST['course_id'];
        
        $conn->query("DELETE FROM course_programs WHERE course_id = $course_id");
        $conn->query("UPDATE vle_courses SET is_shared = 0 WHERE course_id = $course_id");
        
        $success_message = "Course removed from shared programs. Only the primary program can access it now.";
    }
    
    // Toggle shared status
    elseif (isset($_POST['toggle_shared'])) {
        $course_id = (int)$_POST['course_id'];
        $new_status = isset($_POST['new_status']) ? (int)$_POST['new_status'] : 0;
        
        $conn->query("UPDATE vle_courses SET is_shared = $new_status WHERE course_id = $course_id");
        
        $msg = $new_status ? "Course marked as shared." : "Course unmarked as shared.";
        $success_message = $msg;
    }
}

// Get all programs
$all_programs = [];
$prog_result = $conn->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $all_programs[] = $row;
    }
}

// Get all courses with program associations
$courses_with_sharing = [];
$courses_result = $conn->query("
    SELECT c.course_id, c.course_code, c.course_name, c.program_of_study, c.year_of_study, 
           c.semester, c.is_shared, c.lecturer_id, l.full_name as lecturer_name,
           COUNT(DISTINCT ve.student_id) as enrolled_students
    FROM vle_courses c
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    LEFT JOIN vle_enrollments ve ON c.course_id = ve.course_id
    GROUP BY c.course_id
    ORDER BY c.is_shared DESC, c.course_code ASC
");

if ($courses_result) {
    while ($course = $courses_result->fetch_assoc()) {
        // Get associated programs for this course
        $prog_assoc = [];
        $pa_result = $conn->query("
            SELECT p.program_id, p.program_code, p.program_name 
            FROM course_programs cp
            INNER JOIN programs p ON cp.program_id = p.program_id
            WHERE cp.course_id = " . (int)$course['course_id'] . "
            ORDER BY p.program_name
        ");
        
        if ($pa_result) {
            while ($pa = $pa_result->fetch_assoc()) {
                $prog_assoc[] = $pa;
            }
        }
        
        $course['associated_programs'] = $prog_assoc;
        $courses_with_sharing[] = $course;
    }
}

// Calculate statistics
$total_courses = count($courses_with_sharing);
$shared_courses = array_filter($courses_with_sharing, fn($c) => $c['is_shared']);
$shared_count = count($shared_courses);

// Build program sharing matrix
$program_sharing_matrix = [];
foreach ($courses_with_sharing as $course) {
    if ($course['is_shared'] && !empty($course['associated_programs'])) {
        foreach ($course['associated_programs'] as $prog) {
            $prog_id = $prog['program_id'];
            if (!isset($program_sharing_matrix[$prog_id])) {
                $program_sharing_matrix[$prog_id] = [
                    'program' => $prog,
                    'courses' => []
                ];
            }
            $program_sharing_matrix[$prog_id]['courses'][] = $course;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Shared Courses - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .shared-course-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .program-connection-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        
        .program-connection-card:hover {
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }
        
        .program-course-connection {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px dotted #dee2e6;
        }
        
        .program-course-connection:last-child {
            border-bottom: none;
        }
        
        .connection-icon {
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 0.8rem;
        }
        
        .shared-course-table {
            background: white;
        }
        
        .share-button {
            transition: all 0.2s ease;
        }
        
        .share-button:hover {
            transform: scale(1.05);
        }
        
        .program-matrix {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .matrix-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
        }
        
        .matrix-content {
            background: white;
            border: 1px solid #dee2e6;
            border-top: none;
            padding: 1rem;
            border-radius: 0 0 8px 8px;
        }
        
        .course-sharing-stats {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .tab-pane {
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .tooltip-hover {
            cursor: help;
            text-decoration: underline dotted;
        }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Shared Courses']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="vle-page-title"><i class="bi bi-share2 me-2"></i>Manage Shared Courses</h2>
                    <p class="text-muted mb-0">Share courses across programs and manage student access</p>
                </div>
                <a href="manage_courses.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Courses
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted mb-2">Total Courses</h5>
                        <div class="stat-number text-primary"><?php echo $total_courses; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted mb-2">Shared Courses</h5>
                        <div class="stat-number text-success"><?php echo $shared_count; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted mb-2">Programs</h5>
                        <div class="stat-number text-info"><?php echo count($all_programs); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted mb-2">Sharing Rate</h5>
                        <div class="stat-number text-warning"><?php echo $total_courses > 0 ? round(($shared_count / $total_courses) * 100) : 0; ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
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

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="shared-tab" data-bs-toggle="tab" data-bs-target="#sharedCourses" type="button" role="tab">
                    <i class="bi bi-share-fill me-2"></i>Shared Courses
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="matrix-tab" data-bs-toggle="tab" data-bs-target="#sharingMatrix" type="button" role="tab">
                    <i class="bi bi-diagram-2 me-2"></i>Program Connections
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="all-tab" data-bs-toggle="tab" data-bs-target="#allCourses" type="button" role="tab">
                    <i class="bi bi-list-check me-2"></i>All Courses
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Shared Courses Tab -->
            <div class="tab-pane fade show active" id="sharedCourses" role="tabpanel">
                <?php if (!empty($shared_courses)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover shared-course-table">
                            <thead class="table-dark">
                                <tr>
                                    <th><i class="bi bi-code"></i> Course Code</th>
                                    <th><i class="bi bi-book"></i> Course Name</th>
                                    <th><i class="bi bi-diagram-3"></i> Shared With</th>
                                    <th><i class="bi bi-people"></i> Students</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shared_courses as $course): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($course['program_of_study']); ?></span>
                                                <?php foreach ($course['associated_programs'] as $prog): ?>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($prog['program_name']); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $course['enrolled_students']; ?> students</span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editShareModal" 
                                                    onclick="editSharing(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_code']); ?>', '<?php echo addslashes($course['course_name']); ?>')">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                                                <input type="hidden" name="unshare_course" value="1">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove this course from shared programs?')">
                                                    <i class="bi bi-x-circle"></i> Unshare
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center py-5">
                        <i class="bi bi-info-circle" style="font-size: 2rem;"></i>
                        <p class="mt-3 mb-0">No shared courses yet. Go to the "All Courses" tab to share a course.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sharing Matrix Tab -->
            <div class="tab-pane fade" id="sharingMatrix" role="tabpanel">
                <h5 class="mb-4">
                    <i class="bi bi-diagram-2 me-2"></i>Course Sharing Across Programs
                </h5>
                
                <?php if (!empty($program_sharing_matrix)): ?>
                    <div class="program-matrix">
                        <?php foreach ($program_sharing_matrix as $prog_id => $data): ?>
                            <div class="card program-connection-card">
                                <div class="matrix-header">
                                    <i class="bi bi-mortarboard-fill me-2"></i>
                                    <?php echo htmlspecialchars($data['program']['program_name']); ?>
                                    <small class="d-block mt-1">
                                        <i class="bi bi-code-square me-1"></i><?php echo htmlspecialchars($data['program']['program_code']); ?>
                                    </small>
                                </div>
                                <div class="matrix-content">
                                    <div class="mb-2">
                                        <strong class="text-muted" style="font-size: 0.85rem;">
                                            <i class="bi bi-book me-1"></i><?php echo count($data['courses']); ?> shared courses
                                        </strong>
                                    </div>
                                    <?php foreach ($data['courses'] as $course): ?>
                                        <div class="program-course-connection">
                                            <div class="connection-icon">
                                                <i class="bi bi-book"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <strong><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($course['course_name']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center py-5">
                        <i class="bi bi-diagram-2" style="font-size: 2rem;"></i>
                        <p class="mt-3 mb-0">No program connections yet. Share courses to see program relationships.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- All Courses Tab -->
            <div class="tab-pane fade" id="allCourses" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover shared-course-table">
                        <thead class="table-dark">
                            <tr>
                                <th><i class="bi bi-code"></i> Code</th>
                                <th><i class="bi bi-book"></i> Course Name</th>
                                <th><i class="bi bi-diagram-3"></i> Primary Program</th>
                                <th>Shared</th>
                                <th><i class="bi bi-people"></i> Enrolled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses_with_sharing as $course): ?>
                                <tr>
                                    <td>
                                        <strong class="text-primary"><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                    <td><?php echo htmlspecialchars($course['program_of_study']); ?></td>
                                    <td>
                                        <?php if ($course['is_shared']): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle me-1"></i>Yes
                                                <?php if (!empty($course['associated_programs'])): ?>
                                                    (<?php echo count($course['associated_programs']); ?> programs)
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $course['enrolled_students']; ?></span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editShareModal"
                                                onclick="editSharing(<?php echo $course['course_id']; ?>, '<?php echo addslashes($course['course_code']); ?>', '<?php echo addslashes($course['course_name']); ?>')">
                                            <i class="bi bi-share"></i> Share
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Share Modal -->
    <div class="modal fade" id="editShareModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-share2 me-2"></i><span id="shareModalTitle">Share Course</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="shareForm">
                    <div class="modal-body">
                        <input type="hidden" name="course_id" id="shareCourseiId">
                        <input type="hidden" name="share_course" value="1">
                        
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Share Course with Programs:</strong> Select the programs whose students should have access to this course.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-mortarboard me-2"></i>Select Programs to Share With
                            </label>
                            <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($all_programs as $prog): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input program-checkbox" type="checkbox" 
                                               name="share_programs[]" value="<?php echo $prog['program_id']; ?>"
                                               id="prog_<?php echo $prog['program_id']; ?>">
                                        <label class="form-check-label" for="prog_<?php echo $prog['program_id']; ?>">
                                            <strong><?php echo htmlspecialchars($prog['program_name']); ?></strong>
                                            <small class="text-muted d-block ms-4"><?php echo htmlspecialchars($prog['program_code']); ?></small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <small class="text-muted d-block">
                                <i class="bi bi-lightbulb me-1"></i>
                                <strong>Tip:</strong> When you share a course, students from the selected programs will automatically see it in their available courses, even if their year/semester doesn't match.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Share Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editSharing(courseId, courseCode, courseName) {
            document.getElementById('shareCourseiId').value = courseId;
            document.getElementById('shareModalTitle').textContent = 
                `Share Course: ${courseCode} - ${courseName}`;
            
            // Reset all checkboxes
            document.querySelectorAll('.program-checkbox').forEach(cb => {
                cb.checked = false;
            });
        }
    </script>
</body>
</html>
