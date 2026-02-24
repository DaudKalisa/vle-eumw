<?php
// student/course_content.php
// Student view for all course materials and assignments, integrated with VLE system

require_once '../includes/auth.php';
require_once '../includes/config.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get student's financial information
$finance_data = null;
$student_info = null;
$fee_settings = null;

// Get student complete details
$student_query = "SELECT s.*, d.department_name, d.department_code 
                  FROM students s 
                  LEFT JOIN departments d ON CAST(s.department AS UNSIGNED) = d.department_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $student_info = $result->fetch_assoc();
}
$stmt->close();

$finance_query = "SELECT sf.*, s.program_type FROM student_finances sf 
                  JOIN students s ON sf.student_id COLLATE utf8mb4_general_ci = s.student_id COLLATE utf8mb4_general_ci 
                  WHERE sf.student_id = ?";
$stmt = $conn->prepare($finance_query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $finance_data = $result->fetch_assoc();
}
$stmt->close();

// Get fee settings
$fee_query = "SELECT * FROM fee_settings LIMIT 1";
$fee_result = $conn->query($fee_query);
if ($fee_result && $fee_result->num_rows > 0) {
    $fee_settings = $fee_result->fetch_assoc();
}

// Grading scale function
function getGradeInfo($score) {
    if ($score === null) {
        return ['letter' => 'N/A', 'description' => 'Not Graded', 'gpa' => 0, 'color' => 'secondary'];
    }
    
    if ($score >= 85) {
        return ['letter' => 'A+', 'description' => 'High Distinction', 'gpa' => 4.0, 'color' => 'success'];
    } elseif ($score >= 75) {
        return ['letter' => 'A', 'description' => 'Distinction', 'gpa' => 3.7, 'color' => 'success'];
    } elseif ($score >= 70) {
        return ['letter' => 'B+', 'description' => 'High Credit', 'gpa' => 3.3, 'color' => 'info'];
    } elseif ($score >= 65) {
        return ['letter' => 'B', 'description' => 'Credit', 'gpa' => 3.0, 'color' => 'info'];
    } elseif ($score >= 60) {
        return ['letter' => 'C+', 'description' => 'High Pass', 'gpa' => 2.7, 'color' => 'primary'];
    } elseif ($score >= 55) {
        return ['letter' => 'C', 'description' => 'Satisfactory Pass', 'gpa' => 2.3, 'color' => 'primary'];
    } elseif ($score >= 50) {
        return ['letter' => 'C-', 'description' => 'Bare Pass', 'gpa' => 2.0, 'color' => 'warning'];
    } elseif ($score >= 45) {
        return ['letter' => 'D', 'description' => 'Marginal Failure', 'gpa' => 1.0, 'color' => 'danger'];
    } elseif ($score >= 40) {
        return ['letter' => 'E', 'description' => 'Failure', 'gpa' => 0.5, 'color' => 'danger'];
    } else {
        return ['letter' => 'F', 'description' => 'Undoubted Failure', 'gpa' => 0.0, 'color' => 'danger'];
    }
}

// Handle week progression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['progress_week'])) {
    $enrollment_id = (int)$_POST['enrollment_id'];
    $course_id = (int)$_POST['course_id'];
    $current_week = (int)$_POST['current_week'];
    
    // Check if current week's assignment is submitted
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) as submitted_count
        FROM vle_assignments va
        LEFT JOIN vle_submissions vs ON va.assignment_id = vs.assignment_id AND vs.student_id = ?
        WHERE va.course_id = ? AND va.week_number = ? AND va.is_active = TRUE AND vs.submission_id IS NOT NULL
    ");
    $check_stmt->bind_param("sii", $student_id, $course_id, $current_week);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $check = $result->fetch_assoc();
    
    // Get total assignments for the week
    $total_stmt = $conn->prepare("
        SELECT COUNT(*) as total_count
        FROM vle_assignments
        WHERE course_id = ? AND week_number = ? AND is_active = TRUE
    ");
    $total_stmt->bind_param("ii", $course_id, $current_week);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total = $total_result->fetch_assoc();
    
    // Only allow progression if all assignments are submitted
    if ($total['total_count'] > 0 && $check['submitted_count'] < $total['total_count']) {
        $_SESSION['week_progress_error'] = "You must complete all assignments for Week $current_week before proceeding to the next week.";
        header("Location: course_content.php?course_id=" . $course_id);
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE vle_enrollments SET current_week = current_week + 1 WHERE enrollment_id = ? AND student_id = ?");
    $stmt->bind_param("is", $enrollment_id, $student_id);
    $stmt->execute();
    $_SESSION['week_progress_success'] = "Successfully progressed to Week " . ($current_week + 1) . "!";
    header("Location: course_content.php?course_id=" . $course_id);
    exit();
}

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
    // Calculate progress percentage
    $progress_percentage = ($row['current_week'] / $row['total_weeks']) * 100;
    $row['progress_percentage'] = min(100, $progress_percentage);
    
    // Get assignment completion for this course
    $course_id = $row['course_id'];
    $assignments_query = $conn->query("
        SELECT COUNT(*) as total_assignments,
               SUM(CASE WHEN vs.score IS NOT NULL THEN 1 ELSE 0 END) as graded_assignments,
               AVG(vs.score) as avg_score
        FROM vle_assignments va
        LEFT JOIN vle_submissions vs ON va.assignment_id = vs.assignment_id AND vs.student_id = '$student_id'
        WHERE va.course_id = $course_id
    ");
    $assignment_stats = $assignments_query->fetch_assoc();
    $row['total_assignments'] = $assignment_stats['total_assignments'];
    $row['graded_assignments'] = $assignment_stats['graded_assignments'];
    $row['avg_score'] = $assignment_stats['avg_score'] ?? 0;
    
    $enrolled_courses[] = $row;
}

// Get current course if specified
$current_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$current_course = null;
$weekly_content = [];
$assignments = [];
$progress_data = [];

if ($current_course_id) {
    // Verify student is enrolled in this course
    $stmt = $conn->prepare("
        SELECT vc.*, ve.enrollment_id, ve.current_week, ve.is_completed
        FROM vle_enrollments ve
        JOIN vle_courses vc ON ve.course_id = vc.course_id
        WHERE ve.student_id = ? AND vc.course_id = ? AND vc.is_active = TRUE
    ");
    $stmt->bind_param("si", $student_id, $current_course_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $current_course = $result->fetch_assoc();
        
        // Check if student has paid any fees
        $has_paid_fees = false;
        $content_access_weeks = 0;
        if ($finance_data) {
            $total_paid = $finance_data['total_paid'] ?? 0;
            $expected_total = $finance_data['expected_total'] ?? 1;
            $has_paid_fees = $total_paid > 0;
            $payment_percentage = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
            if ($payment_percentage >= 1) {
                $content_access_weeks = 16;
            } elseif ($payment_percentage >= 0.75) {
                $content_access_weeks = 12;
            } elseif ($payment_percentage >= 0.5) {
                $content_access_weeks = 8;
            } elseif ($payment_percentage >= 0.2) {
                $content_access_weeks = 4;
            } else {
                $content_access_weeks = 0;
            }
        }

        // Get weekly content for current week and previous weeks
        $max_week = $current_course['current_week'];
        $stmt = $conn->prepare("
            SELECT * FROM vle_weekly_content
            WHERE course_id = ? AND week_number <= ?
            ORDER BY week_number, sort_order
        ");
        $stmt->bind_param("ii", $current_course_id, $max_week);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $weekly_content[$row['week_number']][] = $row;
        }

        // Get assignments for accessible weeks
        $stmt = $conn->prepare("
            SELECT * FROM vle_assignments
            WHERE course_id = ? AND week_number <= ? AND is_active = TRUE
            ORDER BY week_number, assignment_type
        ");
        $stmt->bind_param("ii", $current_course_id, $max_week);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $assignments[$row['week_number']][] = $row;
        }

        // Get student's progress
        $stmt = $conn->prepare("
            SELECT * FROM vle_progress
            WHERE enrollment_id = ?
            ORDER BY week_number, completion_date
        ");
        $stmt->bind_param("i", $current_course['enrollment_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $progress_data[$row['week_number']][] = $row;
        }

        // Get student's submissions and grades
        $submissions = [];
        $submission_weeks = []; // Track which weeks have submissions
        $new_marked_notifications = [];
        $stmt = $conn->prepare("
            SELECT vs.*, va.title as assignment_title, va.week_number, vs.marked_file_path, vs.marked_file_name
            FROM vle_submissions vs
            JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
            WHERE vs.student_id = ?
            ORDER BY va.week_number, vs.submission_date
        ");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $submissions[] = $row;
            $submission_weeks[$row['week_number']] = true;
            if (!empty($row['marked_file_path']) && isset($row['marked_file_notified']) && $row['marked_file_notified'] == 0) {
                $new_marked_notifications[] = $row;
            }
        }

        // Mark notifications as seen
        $marked_notification_ids = [];
        if (!empty($new_marked_notifications)) {
            $marked_notification_ids = array_map(function($r) { return (int)$r['submission_id']; }, $new_marked_notifications);
        }
        
        // Check completion status for each week
        $week_completion = [];
        for ($w = 1; $w <= $current_course['total_weeks']; $w++) {
            $completion_stmt = $conn->prepare("
                SELECT COUNT(*) as total_assignments,
                       SUM(CASE WHEN vs.submission_id IS NOT NULL THEN 1 ELSE 0 END) as submitted_count
                FROM vle_assignments va
                LEFT JOIN vle_submissions vs ON va.assignment_id = vs.assignment_id AND vs.student_id = ?
                WHERE va.course_id = ? AND va.week_number = ? AND va.is_active = TRUE
            ");
            $completion_stmt->bind_param("sii", $student_id, $current_course_id, $w);
            $completion_stmt->execute();
            $comp_result = $completion_stmt->get_result();
            $comp = $comp_result->fetch_assoc();
            $week_completion[$w] = ($comp['total_assignments'] > 0 && $comp['submitted_count'] == $comp['total_assignments']);
        }

        // Calculate max accessible week based on grades
        $passed_weeks = [];
        foreach ($submissions as $sub) {
            if ($sub['score'] !== null && $sub['score'] >= 50) {
                $passed_weeks[$sub['week_number']] = true;
            }
        }
        $max_accessible_week = $current_course['current_week'];
        for ($w = 1; $w <= $current_course['total_weeks']; $w++) {
            if (!isset($passed_weeks[$w])) break;
            $max_accessible_week = max($max_accessible_week, $w + 1);
        }

        // Get course participants
        $participants = [];
        $stmt = $conn->prepare("
            SELECT s.student_id, s.full_name, s.email
            FROM vle_enrollments ve
            JOIN students s ON ve.student_id = s.student_id
            WHERE ve.course_id = ?
            ORDER BY s.full_name
        ");
        $stmt->bind_param("i", $current_course_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $participants[] = $row;
        }

        // Get lecturer info
        $lecturer_info = null;
        $stmt = $conn->prepare("
            SELECT l.full_name, l.email
            FROM vle_courses vc
            JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
            WHERE vc.course_id = ?
        ");
        $stmt->bind_param("i", $current_course_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $lecturer_info = $result->fetch_assoc();

        // Calculate weighted final grade
        $grade_weights = [
            4 => ['name' => 'Assignment 1', 'weight' => 10],
            8 => ['name' => 'Midsemester Exams', 'weight' => 20],
            12 => ['name' => 'Assignment 2', 'weight' => 10],
            16 => ['name' => 'Final End Semester Exam', 'weight' => 60]
        ];
        
        $weighted_scores = [];
        $final_grade = 0;
        $total_gpa_points = 0;
        $graded_count = 0;
        
        foreach ($submissions as $sub) {
            if (isset($grade_weights[$sub['week_number']]) && $sub['score'] !== null) {
                $weight = $grade_weights[$sub['week_number']]['weight'];
                $weighted_score = ($sub['score'] * $weight) / 100;
                $weighted_scores[$sub['week_number']] = $weighted_score;
                $final_grade += $weighted_score;
                
                // Calculate GPA based on weighted contribution
                $grade_info = getGradeInfo($sub['score']);
                $total_gpa_points += $grade_info['gpa'] * ($weight / 100);
                $graded_count++;
            }
        }
        
        // Get final grade letter and GPA
        $final_grade_info = getGradeInfo($final_grade);
        $overall_gpa = $total_gpa_points; // Already weighted

        $stmt->close();
    }
}

$user = getCurrentUser();
// Update notification flags before closing connection
if (!empty($marked_notification_ids)) {
    $ids_str = implode(',', $marked_notification_ids);
    $conn->query("UPDATE vle_submissions SET marked_file_notified = 1 WHERE submission_id IN ($ids_str)");
}
function is_image($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','bmp','webp']);
}
function is_video($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4','webm','ogg','mov','avi','mkv']);
}
function is_audio($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ['mp3','wav','aac','flac','ogg']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE - Course Content</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .navbar.sticky-top {
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        }
        .navbar-brand img {
            height: 40px;
            width: auto;
            margin-right: 10px;
        }
    </style>
</head>
<body>
<?php 
// Set up breadcrumb navigation
$page_title = "Course Content";
$breadcrumbs = [];
if ($current_course) {
    $breadcrumbs = [
        ['title' => 'Course Access', 'url' => 'courses.php'],
        ['title' => htmlspecialchars($current_course['course_name'])]
    ];
} else {
    $breadcrumbs = [
        ['title' => 'Course Access']
    ];
}
include 'header_nav.php'; 
?>
<div class="container mt-4">
        <!-- Course List (only shown when view=courses or when accessing a specific course) -->
        <?php if (!$current_course && (isset($_GET['view']) && $_GET['view'] == 'courses')): ?>
            <div class="row">
                <div class="col-md-12">
                    <h2><i class="bi bi-book"></i> My Registered Courses</h2>

                    <?php if (empty($enrolled_courses)): ?>
                        <div class="alert alert-info">
                            You are not enrolled in any VLE courses yet.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($enrolled_courses as $course): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h5 class="card-title"><?php echo htmlspecialchars($course['course_name']); ?></h5>
                                            <p class="card-text"><?php echo htmlspecialchars($course['description']); ?></p>
                                            <p class="card-text"><small class="text-muted">Lecturer: <?php echo htmlspecialchars($course['lecturer_name']); ?></small></p>
                                            <p class="card-text"><small class="text-muted">Week: <?php echo $course['current_week']; ?>/<?php echo $course['total_weeks']; ?></small></p>
                                            
                                            <!-- Progress Bar -->
                                            <div class="mb-2">
                                                <small class="text-muted d-block mb-1">Course Progress: <?php echo round($course['progress_percentage']); ?>%</small>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?php echo $course['progress_percentage']; ?>%"
                                                         aria-valuenow="<?php echo $course['progress_percentage']; ?>" 
                                                         aria-valuemin="0" aria-valuemax="100">
                                                        <?php echo round($course['progress_percentage']); ?>%
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid gap-2">
                                                <a href="?course_id=<?php echo $course['course_id']; ?>" class="btn btn-primary">Access Course</a>
                                                <a href="view_forum.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-outline-info">Forums</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($current_course): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="mb-0"><?php echo htmlspecialchars($current_course['course_name']); ?></h3>
                        <a href="courses.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to My Courses</a>
                    </div>
                    
                    <?php if (!$has_paid_fees): ?>
                        <!-- Payment Warning Alert -->
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Course Access Restricted</h4>
                            <hr>
                            <p class="mb-2"><strong>You cannot access this course because you have not paid any fees.</strong></p>
                            <p class="mb-2">To gain access to course materials and assignments, you must pay your student fees.</p>
                            <p class="mb-3">
                                <i class="bi bi-person-fill"></i> <strong>Please contact the Finance Office:</strong><br>
                                <i class="bi bi-envelope-fill"></i> Email: <a href="mailto:finance@university.edu" class="alert-link">finance@university.edu</a><br>
                                <i class="bi bi-telephone-fill"></i> Contact: Linda Chirwa - Finance Department
                            </p>
                            <hr>
                            <div class="d-grid gap-2 d-md-flex">
                                <a href="submit_payment.php" class="btn btn-warning">
                                    <i class="bi bi-credit-card"></i> Submit Payment
                                </a>
                                <a href="payment_history.php" class="btn btn-outline-light">
                                    <i class="bi bi-receipt"></i> View Payment Status
                                </a>
                            </div>
                        </div>
                    <?php elseif ($content_access_weeks < $current_course['current_week']): ?>
                        <!-- Limited Access Warning -->
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <h5 class="alert-heading"><i class="bi bi-exclamation-circle-fill"></i> Limited Course Access</h5>
                            <p class="mb-2">Your current payment allows access to <strong>Week <?php echo $content_access_weeks; ?></strong> only.</p>
                            <p class="mb-2">You are currently on <strong>Week <?php echo $current_course['current_week']; ?></strong>. Additional payment is required to access all course content.</p>
                            <p class="mb-0">
                                <i class="bi bi-person-fill"></i> <strong>Contact Finance:</strong> Linda Chirwa | 
                                <i class="bi bi-envelope-fill"></i> <a href="mailto:finance@university.edu" class="alert-link">finance@university.edu</a>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Course Navigation Tabs -->
                    <ul class="nav nav-tabs" id="courseTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="content-tab" data-bs-toggle="tab" data-bs-target="#content" type="button" role="tab">Content</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="participants-tab" data-bs-toggle="tab" data-bs-target="#participants" type="button" role="tab">Participants</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="grades-tab" data-bs-toggle="tab" data-bs-target="#grades" type="button" role="tab">Grades</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="courseTabContent">
                        <!-- Content Tab -->
                        <div class="tab-pane fade show active" id="content" role="tabpanel">
                            <div class="mt-3">

                    <?php if (!$has_paid_fees): ?>
                        <!-- No Payment - Block All Access -->
                        <div class="card border-danger">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-lock-fill text-danger" style="font-size: 5rem;"></i>
                                <h3 class="mt-3 text-danger">Course Content Locked</h3>
                                <p class="lead">You must pay your student fees to access course materials.</p>
                                <p class="text-muted">Please contact the Finance Department to arrange payment.</p>
                                <div class="mt-4">
                                    <a href="submit_payment.php" class="btn btn-danger btn-lg me-2">
                                        <i class="bi bi-credit-card"></i> Submit Payment
                                    </a>
                                    <a href="payment_history.php" class="btn btn-outline-secondary btn-lg">
                                        <i class="bi bi-receipt"></i> Check Payment Status
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                    
                    <?php 
                    // Display week progress/completion alerts
                    if (isset($_SESSION['week_progress_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $_SESSION['week_progress_error']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php 
                        unset($_SESSION['week_progress_error']);
                    endif;
                    
                    if (isset($_SESSION['week_progress_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill"></i> <?php echo $_SESSION['week_progress_success']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php 
                        unset($_SESSION['week_progress_success']);
                    endif;
                    ?>
                    
                    <!-- Active Zoom Sessions Display -->
                    <?php
                    // Query for active live sessions in this course
                    $live_sessions_query = "SELECT 
                        ls.session_id, 
                        ls.session_name, 
                        ls.session_code, 
                        ls.lecturer_id, 
                        l.full_name as lecturer_name,
                        COUNT(sp.participant_id) as participant_count,
                        CASE WHEN sp.participant_id IS NOT NULL AND sp.status = 'joined' THEN 1 ELSE 0 END as student_joined
                    FROM vle_live_sessions ls
                    LEFT JOIN lecturers l ON ls.lecturer_id = l.lecturer_id
                    LEFT JOIN vle_session_participants sp ON ls.session_id = sp.session_id AND sp.student_id = ?
                    WHERE ls.course_id = ? AND ls.status = 'active'
                    GROUP BY ls.session_id
                    ORDER BY ls.created_at DESC";
                    
                    $live_stmt = $conn->prepare($live_sessions_query);
                    if ($live_stmt) {
                        $live_stmt->bind_param("si", $_SESSION['student_id'], $current_course['course_id']);
                        $live_stmt->execute();
                        $live_result = $live_stmt->get_result();
                        
                        if ($live_result && $live_result->num_rows > 0): 
                            while ($session = $live_result->fetch_assoc()):
                    ?>
                                <!-- Zoom Meeting Container -->
                                <div class="card mb-4 border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-0">
                                                    <i class="bi bi-camera-video-fill"></i> 
                                                    Live Session: <?php echo htmlspecialchars($session['session_name']); ?>
                                                </h5>
                                                <small>Hosted by: <?php echo htmlspecialchars($session['lecturer_name']); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-danger me-2">
                                                    <i class="bi bi-circle-fill"></i> LIVE
                                                </span>
                                                <span class="badge bg-light text-dark">
                                                    <i class="bi bi-people-fill"></i> <?php echo $session['participant_count']; ?> Participants
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-info mb-3">
                                            <i class="bi bi-info-circle"></i>
                                            <?php if ($session['student_joined']): ?>
                                                <strong>You're already in this Zoom meeting</strong> - Click the button below to join
                                            <?php else: ?>
                                                <strong>Join Zoom Meeting</strong> - Click the button below to participate with your classmates
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-grid">
                                            <a href="<?php echo 'https://zoom.us/wc/join/' . htmlspecialchars($session['session_code']); ?>" 
                                               target="_blank" class="btn btn-lg btn-primary">
                                                <i class="bi bi-camera-video"></i> Join Zoom Meeting Now
                                            </a>
                                        </div>
                                    </div>
                                    <div class="card-footer text-muted">
                                        <small><i class="bi bi-info-circle"></i> Meeting ID: <?php echo htmlspecialchars($session['session_code']); ?></small>
                                    </div>
                                </div>
                    <?php 
                            endwhile;
                        endif;
                        $live_stmt->close();
                    }
                    ?>
                    
                    <?php 
                    // Limit accessible weeks based on payment
                    $payment_limited_weeks = min($max_accessible_week, $content_access_weeks, $current_course['total_weeks']);
                    
                    for ($week = 1; $week <= $payment_limited_weeks; $week++): 
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
                        <div class="card mb-3" id="week-<?php echo $week; ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    Week <?php echo $week; ?> - <?php echo $week_title; ?>
                                    <?php if (isset($week_completion[$week]) && $week_completion[$week]): ?>
                                        <span class="badge bg-success ms-2">✓ Marked as Complete</span>
                                    <?php elseif (isset($assignments[$week]) && count($assignments[$week]) > 0): ?>
                                        <span class="badge bg-warning text-dark ms-2">Pending Assignment</span>
                                    <?php endif; ?>
                                </h5>
                                <div>
                                    <?php if ($week > 1): ?>
                                        <a href="#week-<?php echo $week - 1; ?>" class="btn btn-sm btn-outline-secondary">← Previous</a>
                                    <?php endif; ?>
                                    <?php if ($week < min($max_accessible_week, $current_course['total_weeks'])): ?>
                                        <a href="#week-<?php echo $week + 1; ?>" class="btn btn-sm btn-outline-primary">Next →</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (isset($weekly_content[$week])): ?>
                                    <h6>Content:</h6>
                                    <ul class="list-group mb-3">
                                        <?php foreach ($weekly_content[$week] as $content): ?>
                                            <li class="list-group-item">
                                                <strong><?php echo htmlspecialchars($content['title']); ?></strong>
                                                <div>
                                                    <?php
                                                    $type = $content['content_type'];
                                                    $desc = $content['description'];
                                                    $file_path = $content['file_path'] ?? '';
                                                    // Render HTML as-is for headings, subheadings, and formatting
                                                    $show_desc = true;
                                                    // Hide raw URL/label for video/audio/link content that is just a URL
                                                    if (in_array($type, ['video','audio','link']) && filter_var(trim($desc), FILTER_VALIDATE_URL)) {
                                                        $show_desc = false;
                                                    }
                                                    if ($show_desc && !empty($desc)) {
                                                        echo $desc;
                                                    }
                                                    // File previews and links
                                                    if (!empty($file_path)) {
                                                        $file_url = "../uploads/" . htmlspecialchars($file_path);
                                                        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                                                        $is_video = in_array($ext, ['mp4','webm','ogg','mov','avi','mkv']);
                                                        $is_image = in_array($ext, ['jpg','jpeg','png','gif','bmp','webp']);
                                                        $is_audio = in_array($ext, ['mp3','wav','aac','flac','ogg']);
                                                        if ($is_image) {
                                                            echo '<img src="' . $file_url . '" class="img-fluid my-2" alt="Content Image">';
                                                        } elseif ($is_video) {
                                                            echo '<a href="' . $file_url . '" class="btn btn-sm btn-primary" target="_blank">View Video Lecture</a>';
                                                        } elseif ($is_audio) {
                                                            echo '<a href="' . $file_url . '" class="btn btn-sm btn-info my-2" target="_blank">View Audio Lecture</a>';
                                                        } else {
                                                            echo '<a href="' . $file_url . '" class="btn btn-sm btn-outline-primary" target="_blank">View Resource</a>';
                                                        }
                                                    }
                                                    // For links
                                                    if ($type === 'link' && filter_var($desc, FILTER_VALIDATE_URL)) {
                                                        echo '<a href="' . htmlspecialchars($desc) . '" class="btn btn-sm btn-outline-info mt-1" target="_blank">Open Link</a>';
                                                    }
                                                    // For audio/video links (show only as links)
                                                    if ($type === 'audio' && filter_var($desc, FILTER_VALIDATE_URL)) {
                                                        echo '<a href="' . htmlspecialchars($desc) . '" class="btn btn-sm btn-info mt-1" target="_blank">View Audio Lecture</a>';
                                                    }
                                                    if ($type === 'video' && filter_var($desc, FILTER_VALIDATE_URL)) {
                                                        echo '<a href="' . htmlspecialchars($desc) . '" class="btn btn-sm btn-primary mt-1" target="_blank">View Video Lecture</a>';
                                                    }
                                                    ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <?php if (isset($assignments[$week])): ?>
                                    <h6>WEEKLY ASSESSMENTS:</h6>
                                    <ul class="list-group">
                                        <?php foreach ($assignments[$week] as $assignment): ?>
                                            <li class="list-group-item">
                                                <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                                <p><?php echo htmlspecialchars($assignment['description']); ?></p>
                                                <small>Due: <?php echo $assignment['due_date']; ?></small>
                                                <a href="submit_assignment.php?assignment_id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-outline-success ms-2">Submit</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                            
                            <?php if ($current_course['current_week'] < $current_course['total_weeks']): ?>
                                <?php 
                                // Check if current week is complete
                                $current_week = $current_course['current_week'];
                                $is_current_week_complete = isset($week_completion[$current_week]) && $week_completion[$current_week];
                                ?>
                                <div class="card mt-3 <?php echo $is_current_week_complete ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-body text-center">
                                        <?php if ($is_current_week_complete): ?>
                                            <h5 class="text-success"><i class="bi bi-check-circle-fill"></i> Week <?php echo $current_week; ?> Complete!</h5>
                                            <p class="text-muted">You can now progress to Week <?php echo $current_week + 1; ?></p>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="enrollment_id" value="<?php echo $current_course['enrollment_id']; ?>">
                                                <input type="hidden" name="course_id" value="<?php echo $current_course_id; ?>">
                                                <input type="hidden" name="current_week" value="<?php echo $current_week; ?>">
                                                <button type="submit" name="progress_week" class="btn btn-success btn-lg">
                                                    <i class="bi bi-arrow-right-circle"></i> Proceed to Week <?php echo $current_week + 1; ?> →
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <h5 class="text-warning"><i class="bi bi-exclamation-triangle"></i> Complete Week <?php echo $current_week; ?> First</h5>
                                            <p class="text-muted">You must complete all assignments for Week <?php echo $current_week; ?> before proceeding.</p>
                                            <button type="button" class="btn btn-warning btn-lg" disabled>
                                                <i class="bi bi-lock-fill"></i> Next Week Locked
                                            </button>
                                            <p class="mt-2"><small class="text-muted">Complete the assignment(s) above to unlock Week <?php echo $current_week + 1; ?></small></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($current_course['current_week'] >= $current_course['total_weeks']): ?>
                                <div class="alert alert-success mt-3">
                                    <strong>Congratulations!</strong> You have completed all weeks for this course.
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($content_access_weeks < $current_course['total_weeks']): ?>
                                <!-- Payment Required for More Weeks -->
                                <div class="alert alert-info mt-3">
                                    <h5><i class="bi bi-info-circle-fill"></i> Additional Payment Required</h5>
                                    <p class="mb-2">You currently have access to <strong>Week <?php echo $content_access_weeks; ?></strong> of this course.</p>
                                    <p class="mb-2">To access all <strong><?php echo $current_course['total_weeks']; ?> weeks</strong>, please complete your fee payment.</p>
                                    <p class="mb-0">
                                        <i class="bi bi-person-fill"></i> Contact Finance: <strong>Finance Department</strong> | 
                                        <i class="bi bi-envelope-fill"></i> finance@exploitsonline.com
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <?php endif; // End of has_paid_fees check ?>
                            </div>
                        </div>

                        <!-- Participants Tab -->
                        <div class="tab-pane fade" id="participants" role="tabpanel">
                            <div class="mt-3">
                                <?php if (!$has_paid_fees): ?>
                                    <div class="alert alert-danger">
                                        <i class="bi bi-lock-fill"></i> <strong>Access Restricted:</strong> Please pay your fees to view course participants.
                                    </div>
                                <?php else: ?>
                                <h4>Course Participants</h4>
                                
                                <?php if ($lecturer_info): ?>
                                    <div class="card mb-3">
                                        <div class="card-header bg-success text-white">
                                            <strong>Lecturer</strong>
                                        </div>
                                        <div class="card-body">
                                            <p class="mb-1"><strong><?php echo htmlspecialchars($lecturer_info['full_name']); ?></strong></p>
                                            <p class="mb-0 text-muted"><?php echo htmlspecialchars($lecturer_info['email']); ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="card">
                                    <div class="card-header">
                                        <strong>Students (<?php echo count($participants); ?>)</strong>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Student ID</th>
                                                        <th>Name</th>
                                                        <th>Email</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($participants as $participant): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($participant['student_id']); ?></td>
                                                            <td><?php echo htmlspecialchars($participant['full_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($participant['email']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Grades Tab -->
                        <div class="tab-pane fade" id="grades" role="tabpanel">
                            <div class="mt-3">
                                <?php if (!$has_paid_fees): ?>
                                    <div class="alert alert-danger">
                                        <i class="bi bi-lock-fill"></i> <strong>Access Restricted:</strong> Please pay your fees to view your grades.
                                    </div>
                                <?php else: ?>
                                <h4>Assignment Grades</h4>
                    <?php if (!empty($submissions)): ?>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card border-<?php echo $final_grade_info['color']; ?>">
                                    <div class="card-header bg-<?php echo $final_grade_info['color']; ?> text-white">
                                        <strong>Overall Course Grade</strong>
                                    </div>
                                    <div class="card-body text-center">
                                        <h1 class="display-4"><?php echo $final_grade_info['letter']; ?></h1>
                                        <h3><?php echo number_format($final_grade, 2); ?>%</h3>
                                        <p class="text-muted mb-0"><?php echo $final_grade_info['description']; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <strong>GPA (4.0 Scale)</strong>
                                    </div>
                                    <div class="card-body text-center">
                                        <h1 class="display-4"><?php echo number_format($overall_gpa, 2); ?></h1>
                                        <p class="text-muted mb-0">Grade Point Average</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex align-items-center mb-2">
                                <h5 class="mb-0 me-3">Grading Scale Reference</h5>
                                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#gradingScaleTable" aria-expanded="false" aria-controls="gradingScaleTable">
                                    <i class="bi bi-chevron-down"></i> Show/Hide Scale
                                </button>
                            </div>
                            <div class="collapse" id="gradingScaleTable">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Grade</th>
                                                <th>Scale</th>
                                                <th>GPA</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="table-success"><td>A+</td><td>85.00 - 100.00</td><td>4.0</td><td>High Distinction</td></tr>
                                            <tr class="table-success"><td>A</td><td>75.00 - 84.00</td><td>3.7</td><td>Distinction</td></tr>
                                            <tr class="table-info"><td>B+</td><td>70.00 - 74.00</td><td>3.3</td><td>High Credit</td></tr>
                                            <tr class="table-info"><td>B</td><td>65.00 - 69.00</td><td>3.0</td><td>Credit</td></tr>
                                            <tr class="table-primary"><td>C+</td><td>60.00 - 64.00</td><td>2.7</td><td>High Pass</td></tr>
                                            <tr class="table-primary"><td>C</td><td>55.00 - 59.00</td><td>2.3</td><td>Satisfactory Pass</td></tr>
                                            <tr class="table-warning"><td>C-</td><td>50.00 - 54.00</td><td>2.0</td><td>Bare Pass</td></tr>
                                            <tr class="table-danger"><td>D</td><td>45.00 - 49.00</td><td>1.0</td><td>Marginal Failure</td></tr>
                                            <tr class="table-danger"><td>E</td><td>40.00 - 44.00</td><td>0.5</td><td>Failure</td></tr>
                                            <tr class="table-danger"><td>F</td><td>0.00 - 39.00</td><td>0.0</td><td>Undoubted Failure</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($new_marked_notifications)): ?>
                        <div class="alert alert-info"><i class="bi bi-bell"></i> You have new marked assignments available for review!
                            <ul class="mb-0">
                                <?php foreach ($new_marked_notifications as $notif): ?>
                                    <li>Week <?php echo $notif['week_number']; ?>: <strong><?php echo htmlspecialchars($notif['assignment_title']); ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <h5 class="mb-3">Assignment Details</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Week</th>
                                        <th>Assignment</th>
                                        <th>Score</th>
                                        <th>Grade</th>
                                        <th>Weight</th>
                                        <th>Weighted Score</th>
                                        <th>GPA</th>
                                        <th>Status</th>
                                        <th>Feedback</th>
                                        <th>Marked Assignment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $submission): ?>
                                        <?php
                                        $week = $submission['week_number'];
                                        $assignment_name = $submission['assignment_title'];
                                        $weight = 0;
                                        $weighted = 0;
                                        $grade_info = getGradeInfo($submission['score']);
                                        
                                        if (isset($grade_weights[$week])) {
                                            $assignment_name = $grade_weights[$week]['name'];
                                            $weight = $grade_weights[$week]['weight'];
                                            if ($submission['score'] !== null) {
                                                $weighted = ($submission['score'] * $weight) / 100;
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo $week; ?></td>
                                            <td><strong><?php echo htmlspecialchars($assignment_name); ?></strong></td>
                                            <td><?php echo $submission['score'] !== null ? number_format($submission['score'], 2) . '%' : 'Not graded'; ?></td>
                                            <td>
                                                <?php if ($submission['score'] !== null): ?>
                                                    <span class="badge bg-<?php echo $grade_info['color']; ?>">
                                                        <?php echo $grade_info['letter']; ?>
                                                    </span>
                                                    <small class="text-muted d-block"><?php echo $grade_info['description']; ?></small>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $weight > 0 ? $weight . '%' : '-'; ?></td>
                                            <td><?php echo $weight > 0 && $submission['score'] !== null ? number_format($weighted, 2) . '%' : '-'; ?></td>
                                            <td><?php echo $submission['score'] !== null ? number_format($grade_info['gpa'], 1) : '-'; ?></td>
                                            <td>
                                                <?php if ($submission['score'] !== null): ?>
                                                    <?php if ($submission['score'] >= 50): ?>
                                                        <span class="badge bg-success">Pass</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Fail - Repeat</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($submission['feedback'] ?? ''); ?></td>
                                            <td class="text-center">
                                                <?php if (!empty($submission['marked_file_path'])): ?>
                                                    <a href="../uploads/marked_assignments/<?php echo htmlspecialchars($submission['marked_file_path']); ?>" class="btn btn-sm btn-primary" target="_blank" title="Review: <?php echo htmlspecialchars($submission['marked_file_name']); ?>">
                                                        <i class="bi bi-eye"></i> Review
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p>No assignments submitted yet.</p>
                    <?php endif; ?>
                                <?php endif; // End has_paid_fees check for grades ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Session Timeout Manager -->
<script src="../assets/js/session-timeout.js"></script>
</body>
</html>