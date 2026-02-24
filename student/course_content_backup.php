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
        // Defer notification update until after all $conn queries are done
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
// Do not close $conn yet if notifications need updating

// Now update notification flags before closing $conn
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
    <title><?php echo htmlspecialchars($course['course_name']); ?> - Course Content</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .content-card { margin-bottom: 1.5rem; }
        .content-img { max-width: 100%; height: auto; border-radius: 8px; margin-bottom: 0.5rem; }
        .content-icon { font-size: 1.5rem; margin-right: 0.5rem; }
        .week-header { background: #f8f9fa; border-radius: 8px; padding: 0.5rem 1rem; margin-bottom: 0.5rem; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="container mt-4">
    <h2 class="mb-3">📚 <?php echo htmlspecialchars($course['course_name']); ?> - Course Content</h2>
    <?php
    $current_week = null;
    foreach ($contents as $content) {
        if ($content['week_number'] !== $current_week) {
            if ($current_week !== null) echo '</div>';
            $current_week = $content['week_number'];
            echo '<div class="week-header">Week ' . intval($current_week) . '</div><div class="row">';
        }
        echo '<div class="col-md-6 col-lg-4"><div class="card content-card shadow-sm">';
        echo '<div class="card-body">';
        // Icon by type
        $icon = '📄';
        if ($content['content_type'] === 'video') $icon = '🎬';
        elseif ($content['content_type'] === 'audio') $icon = '🎵';
        elseif ($content['content_type'] === 'link') $icon = '🔗';
        elseif ($content['file_path'] && is_image($content['file_path'])) $icon = '🖼️';
        echo '<span class="content-icon">' . $icon . '</span>';
        // Description as HTML
        echo '<div class="mb-2">' . $content['description'] . '</div>';
        // Uploaded file preview
        if ($content['file_path']) {
            $file_url = '../uploads/' . htmlspecialchars($content['file_path']);
            if (is_image($content['file_path'])) {
                echo '<img src="' . $file_url . '" class="content-img" alt="Content Image">';
            } elseif (is_video($content['file_path'])) {
                echo '<a href="' . $file_url . '" class="btn btn-sm btn-primary" target="_blank">View Video</a> ';
            } elseif (is_audio($content['file_path'])) {
                echo '<audio controls class="w-100"><source src="' . $file_url . '">Your browser does not support the audio element.</audio>';
            } else {
                echo '<a href="' . $file_url . '" class="btn btn-sm btn-outline-secondary" target="_blank">Download Resource</a> ';
            }
        }
        // For links
        if ($content['content_type'] === 'link' && filter_var($content['description'], FILTER_VALIDATE_URL)) {
            echo '<a href="' . htmlspecialchars($content['description']) . '" class="btn btn-sm btn-outline-info mt-1" target="_blank">Open Link</a>';
        }
        // For audio/video links
        if ($content['content_type'] === 'audio' && filter_var($content['description'], FILTER_VALIDATE_URL)) {
            echo '<audio controls class="w-100"><source src="' . htmlspecialchars($content['description']) . '">Your browser does not support the audio element.</audio>';
        }
        if ($content['content_type'] === 'video' && filter_var($content['description'], FILTER_VALIDATE_URL)) {
            echo '<a href="' . htmlspecialchars($content['description']) . '" class="btn btn-sm btn-outline-info mt-1" target="_blank">Open Video</a>';
        }
        echo '</div></div></div>';
    }
    if ($current_week !== null) echo '</div>';
    ?>
    <hr>
    <h4 class="mt-4">📝 Assignments</h4>
    <div class="row">
    <?php foreach ($assignments as $assignment): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card mb-3 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($assignment['title']); ?></h5>
                    <p class="card-text"><?php echo $assignment['description']; ?></p>
                    <p class="card-text"><small class="text-muted">Due: <?php echo htmlspecialchars($assignment['due_date']); ?></small></p>
                    <a href="submit_assignment.php?assignment_id=<?php echo $assignment['id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-success btn-sm">Submit Assignment</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <a href="dashboard.php" class="btn btn-secondary mt-3">&larr; Back to Dashboard</a>
</div>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
