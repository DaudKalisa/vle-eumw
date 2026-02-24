<?php
// dashboard.php - Student Dashboard for VLE System
require_once '../includes/auth.php';
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
        header("Location: dashboard.php?course_id=" . $course_id);
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE vle_enrollments SET current_week = current_week + 1 WHERE enrollment_id = ? AND student_id = ?");
    $stmt->bind_param("is", $enrollment_id, $student_id);
    $stmt->execute();
    $_SESSION['week_progress_success'] = "Successfully progressed to Week " . ($current_week + 1) . "!";
    header("Location: dashboard.php?course_id=" . $course_id);
    exit();
}

// Get active live sessions from enrolled courses
$active_live_sessions = [];
$live_result = $conn->query("
    SELECT vls.*, vcs.course_name, vcs.course_code, l.full_name as lecturer_name,
           COUNT(DISTINCT CASE WHEN vsp.status = 'joined' THEN vsp.participant_id END) as active_participants,
           vls.started_at,
           TIMESTAMPDIFF(MINUTE, vls.started_at, NOW()) as minutes_active
    FROM vle_live_sessions vls
    JOIN vle_courses vcs ON vls.course_id = vcs.course_id
    JOIN users u ON vls.lecturer_id = u.user_id
    JOIN lecturers l ON u.related_lecturer_id = l.lecturer_id
    LEFT JOIN vle_session_participants vsp ON vls.session_id = vsp.session_id
    WHERE vls.status = 'active'
      AND vls.course_id IN (
          SELECT course_id FROM vle_enrollments WHERE student_id = '$student_id'
      )
    GROUP BY vls.session_id
    ORDER BY vls.started_at DESC
");
if ($live_result) {
    while ($row = $live_result->fetch_assoc()) {
        $active_live_sessions[] = $row;
    }
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

if ($current_course_id) {}
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


$user = getCurrentUser();
// Do not close $conn yet if notifications need updating

// Now update notification flags before closing $conn
if (!empty($marked_notification_ids)) {
    $ids_str = implode(',', $marked_notification_ids);
    $conn->query("UPDATE vle_submissions SET marked_file_notified = 1 WHERE submission_id IN ($ids_str)");
}

// Announcements are now handled in separate announcements.php page
// No need to fetch them on dashboard anymore

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>VLE - Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/dashboard-responsive.css" rel="stylesheet">
    <style>
        /* Desktop navbar overrides */
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
        
        /* Hide desktop navbar on mobile */
        @media (max-width: 767.98px) {
            .desktop-navbar {
                display: none !important;
            }
        }
        
        /* Hide mobile elements on desktop */
        @media (min-width: 768px) {
            .mobile-header,
            .bottom-nav {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Header (visible only on mobile) -->
    <header class="mobile-header">
        <div class="mobile-header-content">
            <div class="mobile-logo">
                <img src="../pictures/Logo.png" alt="VLE" style="height: 32px;">
                <span>VLE Portal</span>
            </div>
            <div class="mobile-header-actions">
                <a href="messages.php" class="mobile-header-btn" title="Messages">
                    <i class="bi bi-chat-dots"></i>
                </a>
                <a href="profile.php" class="mobile-header-btn" title="Profile">
                    <i class="bi bi-person-circle"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Desktop Navbar (hidden on mobile) -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top desktop-navbar">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../pictures/Logo.png" alt="VLE Logo">
                <span>VLE System</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo (!$current_course_id ? 'active' : ''); ?>" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='courses.php')echo' active'; ?>" href="courses.php">Course Access</a>
                    </li>
                    <?php if ($current_course_id): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="#participants" onclick="document.getElementById('participants-tab').click(); return false;">Participants</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#grades" onclick="document.getElementById('grades-tab').click(); return false;">Grades</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">Messages</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../examination/exams.php">
                            <i class="bi bi-mortarboard"></i> Examinations
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="register_courses.php">
                            <i class="bi bi-journal-plus"></i> Register Courses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="payment_history.php">
                            <i class="bi bi-receipt"></i> Payment History
                        </a>
                    </li>
                </ul>
                <div class="navbar-nav">
                    <?php if ($current_course && isset($final_grade)): ?>
                        <span class="navbar-text me-3">
                            <strong>Overall Grade: <?php echo number_format($final_grade, 2); ?>%</strong>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Profile Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; font-weight: bold;">
                                <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                            </div>
                            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                            <li><h6 class="dropdown-header"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($user['display_name']); ?></h6></li>
                            <li><small class="dropdown-header text-muted"><?php echo htmlspecialchars($user['email'] ?? $student_id); ?></small></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key"></i> Change Password</a></li>
                            <li><a class="dropdown-item" href="payment_history.php"><i class="bi bi-receipt"></i> Payment History</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content Wrapper with responsive padding for mobile bottom nav -->
    <div class="dashboard-wrapper">
        <div class="container-fluid container-lg mt-3 mt-md-4 px-3 px-md-4">
        <?php if (!$current_course): ?>
            
            <!-- Welcome Card - Mobile Optimized -->
            <div class="welcome-card mb-4">
                <div class="welcome-content">
                    <div class="profile-section">
                        <div class="profile-avatar">
                            <?php if (!empty($student_info['profile_picture'])): ?>
                                <img src="../uploads/profiles/<?php echo htmlspecialchars($student_info['profile_picture']); ?>" 
                                     alt="Profile">
                            <?php else: ?>
                                <i class="bi bi-person-fill"></i>
                            <?php endif; ?>
                        </div>
                        <div class="profile-info">
                            <h2 class="welcome-name"><?php echo htmlspecialchars($student_info['full_name'] ?? 'Student'); ?></h2>
                            <p class="student-id"><?php echo htmlspecialchars($student_id); ?></p>
                            <p class="program-info"><?php echo htmlspecialchars($student_info['department_name'] ?? 'Program'); ?></p>
                        </div>
                    </div>
                    <div class="welcome-details d-none d-md-block">
                        <div class="detail-item">
                            <i class="bi bi-envelope"></i>
                            <span><?php echo htmlspecialchars($student_info['email'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="bi bi-telephone"></i>
                            <span><?php echo htmlspecialchars($student_info['phone'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="bi bi-building"></i>
                            <span><?php echo htmlspecialchars($student_info['campus'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="bi bi-calendar3"></i>
                            <span>Year <?php echo ($student_info['year_of_study'] ?? 'N/A'); ?> - Semester <?php echo htmlspecialchars($student_info['semester'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Grid - Responsive 2x2 on mobile, 4 columns on desktop -->
            <?php if ($finance_data): ?>
            <?php
            // Ensure $content_access_weeks and $payment_percentage are always defined
            if (!isset($content_access_weeks)) $content_access_weeks = 0;
            if (!isset($payment_percentage)) $payment_percentage = 0;
            $expected_total = $finance_data['expected_total'] ?? 0;
            $total_paid = $finance_data['total_paid'] ?? 0;
            $outstanding = max(0, $expected_total - $total_paid);
            $max_weeks = 16;
            $content_access_percent = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
            $content_access_percent_display = round($content_access_percent * 100);
            $content_access_weeks = min($max_weeks, (int)round($content_access_percent * $max_weeks));
            ?>
            <div class="stats-grid mb-4">
                <div class="stat-card" style="--accent-color: var(--primary-color);">
                    <div class="stat-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Total Fees</span>
                        <span class="stat-value">K<?php echo number_format($expected_total); ?></span>
                    </div>
                </div>
                <div class="stat-card" style="--accent-color: #11998e;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Paid</span>
                        <span class="stat-value text-success">K<?php echo number_format($total_paid); ?></span>
                    </div>
                </div>
                <div class="stat-card" style="--accent-color: #f5576c;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                        <i class="bi bi-exclamation-circle-fill"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Outstanding</span>
                        <span class="stat-value text-danger">K<?php echo number_format($outstanding); ?></span>
                    </div>
                </div>
                <div class="stat-card" style="--accent-color: #fee140;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                        <i class="bi bi-unlock-fill"></i>
                    </div>
                    <div class="stat-content">
                        <span class="stat-label">Content Access</span>
                        <span class="stat-value"><?php echo $content_access_weeks; ?>/<?php echo $max_weeks; ?> weeks</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions - Horizontal scroll on mobile, grid on desktop -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 d-flex align-items-center">
                        <i class="bi bi-grid-3x3-gap text-primary me-2"></i> Quick Access
                    </h5>
                </div>
                <div class="card-body p-3">
                    <div class="quick-actions">
                        <a href="courses.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                                <i class="bi bi-book"></i>
                            </div>
                            <span>My Courses</span>
                        </a>
                        <a href="profile.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                                <i class="bi bi-person-circle"></i>
                            </div>
                            <span>My Profile</span>
                        </a>
                        <a href="messages.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #0dcaf0, #17a2b8);">
                                <i class="bi bi-envelope"></i>
                            </div>
                            <span>Messages</span>
                        </a>
                        <a href="../examination/exams.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <span>Examinations</span>
                        </a>
                        <a href="submit_payment.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #ffc107, #fd7e14);">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <span>Payments</span>
                        </a>
                        <a href="announcements.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #fd7e14, #dc3545);">
                                <i class="bi bi-megaphone"></i>
                            </div>
                            <span>Announcements</span>
                        </a>
                        <a href="live_invites.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #dc3545, #c82333); animation: pulse 2s infinite;">
                                <i class="bi bi-camera-video"></i>
                            </div>
                            <span>Live Classes</span>
                        </a>
                        <a href="exploits_resources.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #dc3545, #e83e8c);">
                                <i class="bi bi-globe2"></i>
                            </div>
                            <span>Resources</span>
                        </a>
                        <a href="register_courses.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #6c757d, #495057);">
                                <i class="bi bi-journal-plus"></i>
                            </div>
                            <span>Register</span>
                        </a>
                        <a href="payment_history.php" class="action-btn">
                            <div class="action-icon" style="background: linear-gradient(135deg, #667eea, #764ba2); border: 2px solid #667eea;">
                                <i class="bi bi-receipt-cutoff"></i>
                            </div>
                            <span>Pay History</span>
                        </a>
                    </div>
                </div>
            </div>

        <!-- Live Session Now Card -->
        <?php if (!empty($active_live_sessions)): ?>
        <div class="mb-4">
            <?php foreach ($active_live_sessions as $live_session): ?>
            <div class="card border-0 shadow-sm overflow-hidden live-session-card">
                <div class="card-body p-0">
                    <div class="d-flex flex-column flex-md-row align-items-stretch">
                        <!-- Left: Animated icon section -->
                        <div class="live-session-icon-section d-flex align-items-center justify-content-center">
                            <div class="live-pulse-wrapper">
                                <div class="live-pulse-ring"></div>
                                <div class="live-pulse-ring delay-1"></div>
                                <div class="live-pulse-ring delay-2"></div>
                                <i class="bi bi-camera-video-fill live-camera-icon"></i>
                            </div>
                        </div>
                        <!-- Right: Session info + button -->
                        <div class="flex-grow-1 p-3 p-md-4 d-flex flex-column justify-content-center">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-danger me-2" style="animation: liveBlink 1s infinite;"><i class="bi bi-broadcast me-1"></i>LIVE NOW</span>
                                <span class="text-muted small">
                                    <?php 
                                        $mins = (int)($live_session['minutes_active'] ?? 0);
                                        echo $mins > 0 ? "Started {$mins} min ago" : 'Just started';
                                    ?>
                                </span>
                            </div>
                            <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($live_session['session_name']); ?></h5>
                            <p class="text-muted mb-2 small">
                                <i class="bi bi-book me-1"></i><?php echo htmlspecialchars($live_session['course_name']); ?>
                                <span class="mx-1">|</span>
                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($live_session['lecturer_name']); ?>
                                <span class="mx-1">|</span>
                                <i class="bi bi-people me-1"></i><?php echo (int)$live_session['active_participants']; ?> in session
                            </p>
                            <div>
                                <a href="live_room.php?session_id=<?php echo $live_session['session_id']; ?>" class="btn btn-danger btn-lg px-4 fw-bold shadow-sm join-live-btn">
                                    <i class="bi bi-play-circle-fill me-2"></i>&#9654; Join Live Session Now
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <style>
            .live-session-card {
                border-left: 5px solid #dc3545 !important;
                background: linear-gradient(135deg, #fff 0%, #fff5f5 100%);
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .live-session-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(220,53,69,0.18) !important;
            }
            .live-session-icon-section {
                background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                min-width: 120px;
                min-height: 100px;
                position: relative;
            }
            @media (max-width: 767.98px) {
                .live-session-icon-section {
                    min-height: 70px;
                    min-width: 100%;
                }
            }
            .live-pulse-wrapper {
                position: relative;
                width: 60px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .live-pulse-ring {
                position: absolute;
                width: 100%;
                height: 100%;
                border-radius: 50%;
                border: 2px solid rgba(255,255,255,0.5);
                animation: livePulseRing 2s ease-out infinite;
            }
            .live-pulse-ring.delay-1 { animation-delay: 0.5s; }
            .live-pulse-ring.delay-2 { animation-delay: 1s; }
            @keyframes livePulseRing {
                0% { transform: scale(0.5); opacity: 1; }
                100% { transform: scale(1.8); opacity: 0; }
            }
            .live-camera-icon {
                font-size: 28px;
                color: #fff;
                z-index: 1;
                filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
            }
            @keyframes liveBlink {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            .join-live-btn {
                background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                border: none;
                border-radius: 10px;
                transition: transform 0.15s, box-shadow 0.15s;
                font-size: 1rem;
            }
            .join-live-btn:hover {
                transform: scale(1.04);
                box-shadow: 0 4px 15px rgba(220,53,69,0.4);
                background: linear-gradient(135deg, #c82333 0%, #a71d2a 100%);
            }
        </style>
        <?php endif; ?>

        <!-- Announcements moved to separate page - accessible via Quick Access button above -->

        <?php if ($finance_data): ?>
            <?php
            // Ensure $content_access_weeks and $payment_percentage are always defined
            if (!isset($content_access_weeks)) {
                $content_access_weeks = 0;
            }
            if (!isset($payment_percentage)) {
                $payment_percentage = 0;
            }
            ?>
            
            <!-- Financial Summary Section - Hidden on mobile (shown in stats grid above) -->
            <div class="d-none d-lg-block">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-lg border-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="card-body text-white">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h4 class="mb-3">
                                        <i class="bi bi-wallet2"></i> Financial Summary
                                    </h4>
                                    <h5 class="mb-2"><?php echo htmlspecialchars($student_info['full_name']); ?></h5>
                                    <p class="mb-0"><small><i class="bi bi-mortarboard"></i> Student ID: <?php echo htmlspecialchars($student_id); ?></small></p>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <div class="badge bg-light text-dark fs-6 px-3 py-2">
                                        <?php 
                                        $program_type = ucfirst($student_info['program_type'] ?? 'degree');
                                        echo $program_type . ' Program';
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Details Cards - Desktop View -->
            <div class="row mb-4">
                <!-- Total Fees Card -->
                <div class="col-md-3 mb-3">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-cash-stack text-primary" style="font-size: 2.5rem;"></i>
                            </div>
                            <h6 class="text-muted text-uppercase mb-2" style="font-size: 0.75rem;">Total Fees</h6>
                            <h3 class="mb-0 text-dark">
                                K<?php echo number_format($finance_data['expected_total'] ?? 0); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Total Paid Card -->
                <div class="col-md-3 mb-3">
                    <div class="card shadow-sm border-0 h-100" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="card-body text-center text-white">
                            <div class="mb-2">
                                <i class="bi bi-check-circle-fill" style="font-size: 2.5rem;"></i>
                            </div>
                            <h6 class="text-uppercase mb-2" style="font-size: 0.75rem; opacity: 0.9;">Total Paid</h6>
                            <h3 class="mb-0 fw-bold">
                                K<?php echo number_format($finance_data['total_paid'] ?? 0); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Card -->
                <div class="col-md-3 mb-3">
                    <div class="card shadow-sm border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="card-body text-center text-white">
                            <div class="mb-2">
                                <i class="bi bi-exclamation-circle-fill" style="font-size: 2.5rem;"></i>
                            </div>
                            <h6 class="text-uppercase mb-2" style="font-size: 0.75rem; opacity: 0.9;">Outstanding</h6>
                            <h3 class="mb-0 fw-bold">
                                K<?php 
                                    $expected_total = $finance_data['expected_total'] ?? 0;
                                    $total_paid = $finance_data['total_paid'] ?? 0;
                                    $outstanding = $expected_total - $total_paid;
                                    if ($outstanding < 0) $outstanding = 0;
                                    echo number_format($outstanding);
                                ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Content Access Card -->
                <div class="col-md-3 mb-3">
                    <div class="card shadow-sm border-0 h-100" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                        <div class="card-body text-center text-white">
                            <div class="mb-2">
                                <i class="bi bi-unlock-fill" style="font-size: 2.5rem;"></i>
                            </div>
                            <h6 class="text-uppercase mb-2" style="font-size: 0.75rem; opacity: 0.9;">Content Access</h6>
                            <?php
                                $max_weeks = 16;
                                $content_access_percent = ($finance_data['expected_total'] ?? 0) > 0 ? (($finance_data['total_paid'] ?? 0) / ($finance_data['expected_total'] ?? 0)) : 0;
                                $content_access_percent_display = round($content_access_percent * 100);
                                $content_access_weeks = (int)round($content_access_percent * $max_weeks);
                                if ($content_access_weeks > $max_weeks) $content_access_weeks = $max_weeks;
                            ?>
                            <h3 class="mb-0 fw-bold">
                                <?php echo $content_access_percent_display; ?>% <small class="text-white-50">(<?php echo $content_access_weeks; ?> of <?php echo $max_weeks; ?> weeks)</small>
                            </h3>
                            <small style="opacity: 0.9;">
                                <?php 
                                if ($content_access_weeks == 0) {
                                    echo 'No access';
                                } elseif ($content_access_weeks >= $max_weeks) {
                                    echo 'Full access';
                                } else {
                                    echo 'Weeks 1-' . $content_access_weeks;
                                }
                                ?>
                                <br>
                                <span class="text-white-50" style="font-size:0.9em;">
                                    (Access is proportional to fees paid)
                                </span>
                            </small>
                            <div class="progress mt-2" style="height: 8px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $content_access_percent_display; ?>%;" aria-valuenow="<?php echo $content_access_percent_display; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Breakdown Card -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white border-bottom">
                            <h5 class="mb-0"><i class="bi bi-list-check"></i> Payment Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
                                // Get student type and program type for fee calculations
                                $student_type = $student_info['student_type'] ?? 'new_student';
                                $program_type = $student_info['program_type'] ?? 'degree';
                                $is_continuing = ($student_type === 'continuing');
                                $is_professional = ($program_type === 'professional');
                                
                                // Set fees based on student type and program type
                                // Professional courses: K10,000 registration fee, continuing students exempt from app fee
                                // Other programs: Use fee_settings rates
                                if ($is_professional) {
                                    $application_fee = $is_continuing ? 0 : 5500;
                                    $registration_fee = 10000; // Professional course flat rate
                                } else {
                                    $application_fee = $is_continuing ? 0 : 5500;
                                    $new_student_reg_fee = $fee_settings['new_student_reg_fee'] ?? 39500;
                                    $continuing_reg_fee = $fee_settings['continuing_reg_fee'] ?? 35000;
                                    $registration_fee = $is_continuing ? $continuing_reg_fee : $new_student_reg_fee;
                                }
                                
                                // Payment allocation logic: Application Fee -> Registration Fee -> Tuition
                                $total_paid = $finance_data['total_paid'] ?? 0;
                                $student_tuition = $finance_data['expected_tuition'] ?? 0;
                                $installment_amount = $student_tuition / 4;

                                // Distribute payments
                                $remaining_to_distribute = $total_paid;
                                
                                // Application fee (only for new students)
                                $application_fee_paid = 0;
                                if (!$is_continuing) {
                                    $application_fee_paid = min($remaining_to_distribute, $application_fee);
                                    $remaining_to_distribute -= $application_fee_paid;
                                }
                                
                                // Registration fee
                                $registration_paid = min($remaining_to_distribute, $registration_fee);
                                $remaining_to_distribute -= $registration_paid;
                                
                                // Tuition installments
                                $installments_paid = [0, 0, 0, 0];
                                for ($i = 0; $i < 4; $i++) {
                                    $installments_paid[$i] = min($remaining_to_distribute, $installment_amount);
                                    $remaining_to_distribute = max(0, $remaining_to_distribute - $installment_amount);
                                }
                                ?>
                                
                                <?php if (!$is_continuing): // Only show Application Fee for new students ?>
                                <!-- Application Fee -->
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                        <div>
                                            <h6 class="mb-1">Application Fee</h6>
                                            <small class="text-muted">Required before admission</small>
                                        </div>
                                        <div class="text-end">
                                            <h5 class="mb-0 <?php echo $application_fee_paid >= $application_fee ? 'text-success' : 'text-danger'; ?>">
                                                K<?php echo number_format($application_fee_paid); ?>
                                            </h5>
                                            <small class="text-muted">/ K<?php echo number_format($application_fee); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Registration Fee -->
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                        <div>
                                            <h6 class="mb-1">Registration Fee</h6>
                                            <small class="text-muted">
                                                <?php 
                                                if ($is_professional) {
                                                    echo 'Professional Course';
                                                } elseif ($is_continuing) {
                                                    echo 'Continuing Student';
                                                } else {
                                                    echo 'Semester registration';
                                                }
                                                ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <h5 class="mb-0 <?php echo $registration_paid >= $registration_fee ? 'text-success' : 'text-danger'; ?>">
                                                K<?php echo number_format($registration_paid); ?>
                                            </h5>
                                            <small class="text-muted">/ K<?php echo number_format($registration_fee); ?></small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tuition Installments -->
                                <?php
                                $labels = ['1st Installment', '2nd Installment', '3rd Installment', '4th Installment'];
                                for ($i = 0; $i < 4; $i++):
                                ?>
                                <div class="col-md-3 mb-3">
                                    <div class="p-3 border rounded h-100">
                                        <h6 class="mb-2"><?php echo $labels[$i]; ?></h6>
                                        <div class="progress mb-2" style="height: 10px;">
                                            <div class="progress-bar <?php echo $installments_paid[$i] >= $installment_amount ? 'bg-success' : 'bg-warning'; ?>"
                                                 role="progressbar"
                                                 style="width: <?php echo $installment_amount > 0 ? min(100, ($installments_paid[$i] / $installment_amount) * 100) : 0; ?>%">
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">K<?php echo number_format($installments_paid[$i]); ?></small>
                                            <small class="text-muted">K<?php echo number_format($installment_amount); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Payment History Link -->
                            <div class="text-center mt-4">
                                <a href="payment_history.php" class="btn btn-primary">
                                    <i class="bi bi-receipt"></i> View Payment History
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- End d-none d-lg-block wrapper for desktop finance -->
            
            <!-- Mobile Finance Card -->
            <div class="d-lg-none mb-4">
                <div class="finance-card">
                    <div class="finance-header">
                        <i class="bi bi-wallet2"></i>
                        <span>Financial Status</span>
                    </div>
                    <div class="finance-body">
                        <div class="finance-row">
                            <span class="label">Total Fees:</span>
                            <span class="value">K<?php echo number_format($finance_data['expected_total'] ?? 0); ?></span>
                        </div>
                        <div class="finance-row">
                            <span class="label">Paid:</span>
                            <span class="value text-success">K<?php echo number_format($finance_data['total_paid'] ?? 0); ?></span>
                        </div>
                        <div class="finance-row">
                            <span class="label">Balance:</span>
                            <span class="value text-danger">K<?php 
                                $exp_total = $finance_data['expected_total'] ?? 0;
                                $tot_paid = $finance_data['total_paid'] ?? 0;
                                echo number_format(max(0, $exp_total - $tot_paid)); 
                            ?></span>
                        </div>
                        <div class="finance-progress">
                            <div class="progress-bar" style="width: <?php echo min(100, $content_access_percent_display); ?>%;"></div>
                        </div>
                        <div class="finance-footer">
                            <small><?php echo $content_access_percent_display; ?>% paid - <?php echo $content_access_weeks; ?> of 16 weeks access</small>
                        </div>
                        <a href="payment_history.php" class="btn btn-sm btn-outline-primary w-100 mt-2">
                            <i class="bi bi-receipt"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Announcements removed - now accessible via dedicated announcements.php page -->

        <?php endif; ?>
        
        <!-- ...existing code... (removed inline My Courses view, now handled by courses.php) -->

                <?php if ($current_course): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="mb-0"><?php echo htmlspecialchars($current_course['course_name']); ?></h3>
                        <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to My Courses</a>
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
                                <i class="bi bi-envelope-fill"></i> Email: <a href="mailto:finance@exploitsonline.com" class="alert-link">finance@exploitsonline.com</a><br>
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
                                <i class="bi bi-envelope-fill"></i> <a href="mailto:finance@exploitsonline.com" class="alert-link">finance@exploitsonline.com</a>
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

                        <h5 class="mb-3">Grading Scale Reference</h5>
                        <div class="table-responsive mb-4">
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
        </div>
    </div><!-- End container -->
    </div><!-- End dashboard-wrapper -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Session Timeout Manager -->
    <script src="../assets/js/session-timeout.js"></script>
    
    <!-- Mobile Bottom Navigation (visible only on mobile) -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <i class="bi bi-house-fill"></i>
            <span>Home</span>
        </a>
        <a href="courses.php" class="nav-item">
            <i class="bi bi-book"></i>
            <span>Courses</span>
        </a>
        <a href="messages.php" class="nav-item">
            <i class="bi bi-chat-dots"></i>
            <span>Messages</span>
        </a>
        <a href="submit_payment.php" class="nav-item">
            <i class="bi bi-wallet2"></i>
            <span>Payments</span>
        </a>
        <a href="profile.php" class="nav-item">
            <i class="bi bi-person"></i>
            <span>Profile</span>
        </a>
    </nav>
    
    <script>
    // Mobile bottom nav active state
    document.addEventListener('DOMContentLoaded', function() {
        const currentPage = window.location.pathname.split('/').pop();
        const navItems = document.querySelectorAll('.bottom-nav .nav-item');
        
        navItems.forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) {
                item.classList.add('active');
            } else if (currentPage === 'dashboard.php' && href === 'dashboard.php') {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    });
    </script>
</body>
</html>