<?php
// dashboard.php - Lecturer Dashboard for VLE System
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

if (!$lecturer_id) {
    die("Error: Lecturer ID not found. Please contact administrator.");
}

// Get lecturer's VLE courses
$courses = [];

$result = $conn->query("
    SELECT vc.*, COUNT(ve.enrollment_id) as enrolled_students
    FROM vle_courses vc
    LEFT JOIN vle_enrollments ve ON vc.course_id = ve.course_id
    WHERE vc.lecturer_id = '$lecturer_id'
    GROUP BY vc.course_id
    ORDER BY vc.course_name
");

while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

// Get current course if specified
$current_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$current_course = null;
$weekly_content = [];
$assignments = [];
$enrollments = [];

if ($current_course_id) {
    // Verify lecturer owns this course
    $stmt = $conn->prepare("
        SELECT * FROM vle_courses
        WHERE course_id = ? AND lecturer_id = ?
    ");
    $stmt->bind_param("is", $current_course_id, $lecturer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $current_course = $result->fetch_assoc();

        // Get student progress for this course
        $student_progress = [];
        $result = $conn->query("
            SELECT s.student_id, s.full_name, ve.current_week, ve.is_completed,
                   COUNT(DISTINCT va.assignment_id) as total_assignments,
                   COUNT(DISTINCT vs.submission_id) as submitted_assignments,
                   SUM(CASE WHEN vs.score IS NOT NULL THEN 1 ELSE 0 END) as graded_assignments,
                   AVG(vs.score) as avg_score
            FROM vle_enrollments ve
            JOIN students s ON ve.student_id = s.student_id
            LEFT JOIN vle_assignments va ON ve.course_id = va.course_id
            LEFT JOIN vle_submissions vs ON va.assignment_id = vs.assignment_id AND vs.student_id = s.student_id
            WHERE ve.course_id = $current_course_id
            GROUP BY s.student_id, s.full_name, ve.current_week, ve.is_completed
            ORDER BY s.full_name
        ");
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = ($row['current_week'] / $current_course['total_weeks']) * 100;
            $student_progress[] = $row;
        }

        // Get weekly content
        $result = $conn->query("
            SELECT * FROM vle_weekly_content
            WHERE course_id = $current_course_id
            ORDER BY week_number, sort_order
        ");

        while ($row = $result->fetch_assoc()) {
            $weekly_content[$row['week_number']][] = $row;
        }

        // Get assignments
        $result = $conn->query("
            SELECT * FROM vle_assignments
            WHERE course_id = $current_course_id
            ORDER BY week_number, assignment_type
        ");

        while ($row = $result->fetch_assoc()) {
            $assignments[$row['week_number']][] = $row;
        }

        // Get enrollments and progress
        $result = $conn->query("
            SELECT ve.*, s.full_name, s.student_id,
                   COUNT(vp.progress_id) as completed_activities
            FROM vle_enrollments ve
            JOIN students s ON ve.student_id = s.student_id
            LEFT JOIN vle_progress vp ON ve.enrollment_id = vp.enrollment_id
            WHERE ve.course_id = $current_course_id
            GROUP BY ve.enrollment_id
            ORDER BY s.full_name
        ");

        while ($row = $result->fetch_assoc()) {
            $enrollments[] = $row;
        }
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE - Lecturer Dashboard</title>
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
        .card-header-progress {
            background: var(--vle-gradient-success) !important;
            border: none;
            color: white;
        }
    </style>
</head>
<body>
    <?php 
    $breadcrumbs = [];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <h2 class="vle-page-title"><i class="bi bi-collection me-2"></i>My Assigned Modules</h2>
            <div>
                <a href="live_classroom.php" class="btn btn-danger me-2"><i class="bi bi-camera-video me-1"></i> Live Classroom</a>
                <a href="request_finance.php" class="btn btn-vle-accent me-2"><i class="bi bi-cash-coin me-1"></i> Finance</a>
                <a href="class_session.php" class="btn btn-vle-primary"><i class="bi bi-clipboard-check me-1"></i> Attendance Sessions</a>
            </div>
        </div>

        <?php if (empty($courses)): ?>
            <div class="alert vle-alert-info">
                <i class="bi bi-info-circle me-2"></i>You haven't been assigned any VLE courses yet.
                Please contact the administrator for module assignment.
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($courses as $course): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card course-card vle-card">
                            <div class="card-body">
                                <h5 class="card-title" style="color: var(--vle-primary); font-weight: 700;">
                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                </h5>
                                <p class="card-text text-muted small"><?php echo htmlspecialchars($course['description']); ?></p>
                                <p class="card-text"><small class="text-muted"><strong>Code:</strong> <?php echo htmlspecialchars($course['course_code']); ?></small></p>
                                <p class="card-text">
                                    <span class="vle-badge-primary">
                                        <i class="bi bi-people me-1"></i><?php echo $course['enrolled_students']; ?> students
                                    </span>
                                </p>
                                <div class="d-grid gap-2">
                                    <div class="btn-group">
                                        <a href="manage_content.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-vle-primary">
                                            <i class="bi bi-folder me-1"></i>Content
                                        </a>
                                        <a href="forum.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-chat me-1"></i>Forums
                                        </a>
                                    </div>
                                    <div class="btn-group">
                                        <a href="gradebook.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-success">
                                            <i class="bi bi-journal-check me-1"></i>Grades
                                        </a>
                                        <a href="announcements.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-warning">
                                            <i class="bi bi-megaphone me-1"></i>Announce
                                        </a>
                                    </div>
                                    <a href="edit_course.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-pencil me-1"></i>Edit Course
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($current_course): ?>
            <hr class="my-4">
            <h3 class="mb-4" style="color: var(--vle-primary);">
                <i class="bi bi-gear me-2"></i>Manage: <?php echo htmlspecialchars($current_course['course_name']); ?>
            </h3>

            <!-- Student Progress Overview -->
            <div class="card vle-card mb-4">
                <div class="card-header card-header-progress">
                    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Student Progress Overview</h5>
                </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Current Week</th>
                                            <th>Progress</th>
                                            <th>Assignments</th>
                                            <th>Graded</th>
                                            <th>Avg Score</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_progress)): ?>
                                            <?php foreach ($student_progress as $progress): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($progress['full_name']); ?></td>
                                                    <td><?php echo $progress['current_week']; ?>/<?php echo $current_course['total_weeks']; ?></td>
                                                    <td>
                                                        <div class="progress" style="width: 100px; height: 20px;">
                                                            <div class="progress-bar bg-info" role="progressbar" 
                                                                 style="width: <?php echo $progress['progress_percentage']; ?>%">
                                                                <?php echo round($progress['progress_percentage']); ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo $progress['submitted_assignments']; ?>/<?php echo $progress['total_assignments']; ?></td>
                                                    <td><?php echo $progress['graded_assignments']; ?></td>
                                                    <td><?php echo $progress['avg_score'] ? round($progress['avg_score']) . '%' : 'N/A'; ?></td>
                                                    <td>
                                                        <?php if ($progress['is_completed']): ?>
                                                            <span class="badge bg-success">Completed</span>
                                                        <?php elseif ($progress['progress_percentage'] > 50): ?>
                                                            <span class="badge bg-primary">In Progress</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">Starting</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="7" class="text-center">No students enrolled yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link active" href="?course_id=<?php echo $current_course_id; ?>">Content</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="forum.php?course_id=<?php echo $current_course_id; ?>">Forums</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="gradebook.php?course_id=<?php echo $current_course_id; ?>">Gradebook</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="announcements.php?course_id=<?php echo $current_course_id; ?>">Announcements</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="approve_downloads.php">Download Requests</a>
                        </li>
                    </ul>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5>Course Content</h5>
                                </div>
                                <div class="card-body">
                                    <?php for ($week = 1; $week <= $current_course['total_weeks']; $week++): 
                                        // Determine week title based on week number
                                        $week_title = '';
                                        if ($week == 4) {
                                            $week_title = 'SUMMATIVE ASSIGNMENT';
                                        } elseif ($week == 8) {
                                            $week_title = 'MID SEMESTER EXAMS GRADE';
                                        } elseif ($week == 12) {
                                            $week_title = 'SUMMATIVE ASSIGNMENT 2';
                                        } elseif ($week == 16) {
                                            $week_title = 'END SEMISTER GRADE';
                                        } else {
                                            // Get the first content title for this week as topic overview
                                            if (isset($weekly_content[$week]) && count($weekly_content[$week]) > 0) {
                                                $week_title = 'TOPIC OVERVIEW: ' . htmlspecialchars($weekly_content[$week][0]['title']);
                                            } else {
                                                $week_title = 'TOPIC OVERVIEW';
                                            }
                                        }
                                    ?>
                                        <div class="mb-3">
                                            <h6>Week <?php echo $week; ?> - <?php echo $week_title; ?></h6>
                                            <?php if (isset($weekly_content[$week])): ?>
                                                <ul class="list-group mb-2">
                                                    <?php foreach ($weekly_content[$week] as $content): ?>
                                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <?php echo htmlspecialchars($content['title']); ?>
                                                                <span class="badge bg-secondary"><?php echo ucfirst($content['content_type']); ?></span>
                                                            </span>
                                                            <span class="btn-group">
                                                                <a href="edit_content.php?content_id=<?php echo $content['content_id']; ?>" class="btn btn-sm btn-outline-warning ms-2">Edit</a>
                                                                <a href="delete_content.php?content_id=<?php echo $content['content_id']; ?>&course_id=<?php echo $current_course_id; ?>" class="btn btn-sm btn-outline-danger ms-2" onclick="return confirm('Are you sure you want to delete this content?');">Delete</a>
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>

                                            <?php if (isset($assignments[$week])): ?>
                                                <div class="ms-3">
                                                    <strong>WEEKLY ASSESSMENTS:</strong>
                                                    <ul class="list-group">
                                                        <?php foreach ($assignments[$week] as $assignment): ?>
                                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                <span>
                                                                    <?php echo htmlspecialchars($assignment['title']); ?>
                                                                    <span class="badge bg-warning"><?php echo ucfirst(str_replace('_', ' ', $assignment['assignment_type'])); ?></span>
                                                                </span>
                                                                <span class="btn-group">
                                                                    <a href="edit_assignment.php?assignment_id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Assignment"><i class="bi bi-pencil"></i> Edit</a>
                                                                    <a href="delete_assignment.php?assignment_id=<?php echo $assignment['assignment_id']; ?>&course_id=<?php echo $current_course_id; ?>" class="btn btn-sm btn-outline-danger" title="Delete Assignment" onclick="return confirm('Are you sure you want to delete this assignment?');"><i class="bi bi-trash"></i> Delete</a>
                                                                </span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <a href="add_content.php?course_id=<?php echo $current_course_id; ?>&week=<?php echo $week; ?>" class="btn btn-sm btn-outline-primary">Add Content</a>
                                            <a href="add_assignment.php?course_id=<?php echo $current_course_id; ?>&week=<?php echo $week; ?>" class="btn btn-sm btn-outline-success">Add Assignment</a>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Enrolled Students (<?php echo count($enrollments); ?>)</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($enrollments)): ?>
                                        <p class="text-muted">No students enrolled yet.</p>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($enrollments as $enrollment): ?>
                                                <div class="list-group-item">
                                                    <strong><?php echo htmlspecialchars($enrollment['full_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($enrollment['student_id']); ?></small><br>
                                                    <small>Week: <?php echo $enrollment['current_week']; ?> | Completed: <?php echo $enrollment['completed_activities']; ?> activities</small>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Session Timeout Manager -->
    <script src="../assets/js/session-timeout.js"></script>
</body>
</html>