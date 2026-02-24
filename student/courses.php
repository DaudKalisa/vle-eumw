<?php
// courses.php - Student's My Courses Page
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get student's enrolled VLE courses
$enrolled_courses = [];
$result = $conn->query("
    SELECT vc.*, ve.enrollment_id, ve.current_week, ve.is_completed,
           l.full_name as lecturer_name
    FROM vle_enrollments ve
    JOIN vle_courses vc ON ve.course_id = vc.course_id
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    WHERE ve.student_id = '$student_id' AND vc.is_active = TRUE
    ORDER BY vc.course_name
");
while ($row = $result->fetch_assoc()) {
    $progress_percentage = ($row['current_week'] / $row['total_weeks']) * 100;
    $row['progress_percentage'] = min(100, $progress_percentage);
    $enrolled_courses[] = $row;
}
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - VLE Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .course-card {
            border: none;
            border-radius: var(--vle-radius-lg);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(30, 60, 114, 0.15);
        }
        .course-icon {
            font-size: 3rem;
            background: var(--vle-gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .progress {
            height: 18px;
            border-radius: 10px;
            background: var(--vle-gray-100);
        }
        .progress-bar {
            background: var(--vle-gradient-accent);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php 
    // Set up breadcrumb navigation
    $page_title = "My Courses";
    $breadcrumbs = [
        ['title' => 'Course Access']
    ];
    include 'header_nav.php'; 
    ?>
    <div class="vle-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-book me-2"></i>My Courses</h2>
        </div>
        <?php if (empty($enrolled_courses)): ?>
            <div class="alert vle-alert-info">
                <i class="bi bi-info-circle me-2"></i>You are not enrolled in any courses yet.
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($enrolled_courses as $course): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card course-card h-100 vle-card">
                            <div class="card-body text-center">
                                <i class="bi bi-book course-icon mb-3"></i>
                                <h5 class="card-title mb-2" style="font-weight:700; color: var(--vle-primary);">
                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                </h5>
                                <div class="mt-2 mb-3">
                                    <span class="d-block text-muted"><strong>Course Code:</strong> <?php echo htmlspecialchars($course['course_code'] ?? ''); ?></span>
                                    <span class="d-block text-muted"><strong>Year:</strong> <?php echo htmlspecialchars($course['year_of_study'] ?? ''); ?></span>
                                    <span class="d-block text-muted"><strong>Lecturer:</strong> <?php echo htmlspecialchars($course['lecturer_name'] ?? ''); ?></span>
                                    <?php 
                                        $total_modules = count($enrolled_courses);
                                        $module_position = array_search($course['course_id'], array_column($enrolled_courses, 'course_id')) + 1;
                                    ?>
                                    <span class="d-block text-muted"><strong>Module:</strong> <?php echo $module_position . '/' . $total_modules; ?></span>
                                </div>
                                <div class="mb-3">
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: <?php echo $course['progress_percentage']; ?>%;" aria-valuenow="<?php echo $course['progress_percentage']; ?>" aria-valuemin="0" aria-valuemax="100">
                                            <?php echo number_format($course['progress_percentage'], 1); ?>%
                                        </div>
                                    </div>
                                </div>
                                <a href="course_content.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-vle-primary w-100 mt-2">
                                    <i class="bi bi-folder2-open me-1"></i> View Content
                                </a>
                                <a href="forum.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-secondary w-100 mt-2">
                                    <i class="bi bi-chat-dots me-1"></i> Forum
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
