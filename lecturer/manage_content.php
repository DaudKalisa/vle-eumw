<?php
// manage_content.php - Independent page for managing course content (lecturer)
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);
require_once '../includes/notifications.php';

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

// Get course_id from URL
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if (!$course_id) {
    header('Location: dashboard.php');
    exit();
}

// Verify lecturer owns this course
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("is", $course_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}
$course = $result->fetch_assoc();


// Fetch weekly content for this course
$weekly_content = [];
$res = $conn->query("SELECT * FROM vle_weekly_content WHERE course_id = $course_id ORDER BY week_number, sort_order");
while ($row = $res->fetch_assoc()) {
    $weekly_content[$row['week_number']][] = $row;
}

// Fetch assignments for this course
$assignments = [];
$res2 = $conn->query("SELECT * FROM vle_assignments WHERE course_id = $course_id ORDER BY week_number, assignment_type");
while ($row = $res2->fetch_assoc()) {
    $assignments[$row['week_number']][] = $row;
}

// Fetch enrolled students and progress for this course
$enrollments = [];
$student_progress = [];
$result = $conn->query("SELECT ve.*, s.full_name, s.student_id, ve.current_week, ve.is_completed FROM vle_enrollments ve JOIN students s ON ve.student_id = s.student_id WHERE ve.course_id = $course_id ORDER BY s.full_name");
while ($row = $result->fetch_assoc()) {
    $row['progress_percentage'] = ($row['current_week'] / 16) * 100;
    $student_progress[] = $row;
    $enrollments[] = $row;
}
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Content - <?php echo htmlspecialchars($course['course_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .navbar.sticky-top, .navbar.fixed-top {
            position: sticky;
            top: 0;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: #198754 !important;
        }
        .navbar-brand img {
            height: 48px;
            width: auto;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <!-- Modern Header -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #002147; position: sticky; top: 0; z-index: 1050; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div class="container-fluid">
            <!-- LEFT SIDE: Logo & Main Nav -->
            <a class="navbar-brand d-flex align-items-center fw-bold text-white me-4" href="dashboard.php">
                <img src="../assets/img/Logo.png" alt="Logo" style="height:38px;width:auto;margin-right:10px;">
                <span>VLE-EUMW</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="messages.php">Messages</a></li>
                    <li class="nav-item"><a class="nav-link" href="request_finance.php">Finance</a></li>
                    <li class="nav-item"><a class="nav-link" href="announcements.php">Announcements</a></li>
                    <li class="nav-item"><a class="nav-link" href="forum.php">Forums</a></li>
                    <li class="nav-item"><a class="nav-link" href="gradebook.php">Gradebook</a></li>
                </ul>
                <!-- RIGHT SIDE: Search, Notifications, Messages, Help, User Dropdown -->
                <form class="d-flex me-3" role="search" style="max-width:220px;">
                    <input class="form-control form-control-sm me-2" type="search" placeholder="Search..." aria-label="Search">
                </form>
                <?php
                    $mc_user_id = $_SESSION['vle_user_id'] ?? 0;
                    $mc_lecturer_id = $_SESSION['vle_related_id'] ?? '';
                    if ($mc_user_id && $mc_lecturer_id) {
                        generateLecturerNotifications($mc_user_id, $mc_lecturer_id);
                    }
                    $mc_unread = $mc_user_id ? getUnreadNotificationCount($mc_user_id) : 0;
                    $mc_notifs = $mc_user_id ? getNotifications($mc_user_id, 10) : [];
                ?>
                <ul class="navbar-nav align-items-center mb-2 mb-lg-0">
                    <!-- Notification Bell with Dropdown -->
                    <li class="nav-item me-2 position-relative">
                        <a class="nav-link position-relative" href="#" id="vle-notif-bell" title="Notifications">
                            <i class="bi bi-bell" style="font-size:1.3rem;"></i>
                            <span class="vle-notif-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  style="<?= $mc_unread ? '' : 'display:none;' ?>">
                                <?= $mc_unread ?: '' ?>
                            </span>
                        </a>
                        <!-- Notification Dropdown -->
                        <div id="vle-notif-dropdown" class="dropdown-menu dropdown-menu-end shadow-lg p-0"
                             style="width:370px;max-height:480px;overflow-y:auto;position:absolute;right:0;top:100%;display:none;">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-primary text-white" style="border-radius:0.375rem 0.375rem 0 0;">
                                <strong><i class="bi bi-bell me-1"></i> Notifications</strong>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="vle-notif-email-toggle" title="Also send clicked notifications to my email">
                                    <label class="form-check-label small text-white-50" for="vle-notif-email-toggle" style="font-size:0.75rem;">Email me</label>
                                </div>
                            </div>
                            <div id="vle-notif-list">
                                <?php if (empty($mc_notifs)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-bell-slash" style="font-size:2rem;"></i>
                                        <p class="mb-0 mt-2">No notifications yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php if ($mc_unread > 0): ?>
                                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                        <small class="text-muted"><?= $mc_unread ?> unread</small>
                                        <a href="#" class="small text-primary" onclick="VLENotif.markAllRead(event)">Mark all read</a>
                                    </div>
                                    <?php endif; ?>
                                    <?php foreach ($mc_notifs as $n):
                                        $unreadCls = $n['is_read'] == 0 ? 'bg-light border-start border-primary border-3' : '';
                                        $eIcon = $n['is_emailed'] == 1 ? 'bi-envelope-check-fill' : 'bi-envelope';
                                        $eBtnCls = $n['is_emailed'] == 1 ? 'text-success' : 'text-muted';
                                    ?>
                                    <div class="notif-item d-flex align-items-start px-3 py-2 border-bottom <?= $unreadCls ?>"
                                         data-id="<?= $n['notification_id'] ?>" style="cursor:pointer;">
                                        <div class="me-2 mt-1">
                                            <span class="badge bg-<?= getNotificationBadgeColor($n['type']) ?> rounded-circle p-2">
                                                <i class="<?= getNotificationBsIcon($n['type']) ?>"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1" onclick="VLENotif.clickNotification(<?= $n['notification_id'] ?>, event)">
                                            <div class="fw-semibold small"><?= htmlspecialchars($n['title']) ?></div>
                                            <div class="text-muted small text-truncate" style="max-width:250px;"><?= htmlspecialchars($n['message']) ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;"><?= notificationTimeAgo($n['created_at']) ?></div>
                                        </div>
                                        <div class="ms-2">
                                            <button class="btn btn-sm p-0 <?= $eBtnCls ?>"
                                                    onclick="VLENotif.emailNotification(<?= $n['notification_id'] ?>, event)"
                                                    title="<?= $n['is_emailed'] ? 'Already emailed' : 'Send to my email' ?>" style="font-size:1rem;">
                                                <i class="<?= $eIcon ?>"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item me-2">
                        <a class="nav-link position-relative" href="messages.php" title="Messages">
                            <i class="bi bi-envelope" style="font-size:1.3rem;"></i>
                        </a>
                    </li>
                    <li class="nav-item me-2">
                        <a class="nav-link" href="#" title="Help/Support"><i class="bi bi-question-circle" style="font-size:1.3rem;"></i></a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
<div class="container mt-4">
    <h2>Manage Content for: <?php echo htmlspecialchars($course['course_name']); ?></h2>
    <hr>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Student Progress Overview</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Current Week</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($student_progress)): ?>
                                    <?php foreach ($student_progress as $progress): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($progress['full_name']); ?></td>
                                            <td><?php echo $progress['current_week']; ?>/16</td>
                                            <td>
                                                <div class="progress" style="width: 100px; height: 20px;">
                                                    <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $progress['progress_percentage']; ?>%">
                                                        <?php echo round($progress['progress_percentage']); ?>%
                                                    </div>
                                                </div>
                                            </td>
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
                                    <tr><td colspan="4" class="text-center">No students enrolled yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php for ($week = 1; $week <= 16; $week++): 
        $contents = isset($weekly_content[$week]) ? $weekly_content[$week] : [];
        $week_assignments = isset($assignments[$week]) ? $assignments[$week] : [];
        // Determine week title
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
            if (count($contents) > 0) {
                $week_title = 'TOPIC OVERVIEW: ' . htmlspecialchars($contents[0]['title']);
            } else {
                $week_title = 'TOPIC OVERVIEW';
            }
        }
    ?>
        <div class="card mb-3">
            <div class="card-header bg-light">
                <strong>Week <?php echo $week; ?> - <?php echo $week_title; ?></strong>
            </div>
            <div class="card-body">
                <?php if (count($contents) > 0): ?>
                    <ul class="list-group mb-2">
                        <?php foreach ($contents as $content): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <?php echo htmlspecialchars($content['title']); ?>
                                    <span class="badge bg-secondary"><?php echo ucfirst($content['content_type']); ?></span>
                                    <?php if (in_array($content['content_type'], ['video', 'audio', 'link']) && !empty($content['description'])): ?>
                                        <?php if ($content['content_type'] === 'audio'):
                                            // If it's a direct audio file, play inline. If it's a URL, still try to play inline.
                                        ?>
                                            <br><audio controls style="max-width:300px;">
                                                <source src="<?php echo htmlspecialchars($content['description']); ?>">
                                                Your browser does not support the audio element.
                                            </audio>
                                            <br><a href="<?php echo htmlspecialchars($content['description']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info mt-1">Open Audio Source</a>
                                        <?php elseif ($content['content_type'] === 'video'): ?>
                                            <br><a href="<?php echo htmlspecialchars($content['description']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info mt-1">Open Video Presentation</a>
                                        <?php else: ?>
                                            <br><a href="<?php echo htmlspecialchars($content['description']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info mt-1">Open Link</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                                <span class="btn-group">
                                    <a href="edit_content.php?content_id=<?php echo $content['content_id']; ?>" class="btn btn-sm btn-outline-warning ms-2">Edit</a>
                                    <a href="delete_content.php?content_id=<?php echo $content['content_id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-outline-danger ms-2" onclick="return confirm('Are you sure you want to delete this content?');">Delete</a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (count($week_assignments) > 0): ?>
                    <div class="ms-3">
                        <strong>WEEKLY ASSESSMENTS:</strong>
                        <ul class="list-group">
                            <?php foreach ($week_assignments as $assignment): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        <?php echo htmlspecialchars($assignment['title']); ?>
                                        <span class="badge bg-warning"><?php echo ucfirst(str_replace('_', ' ', $assignment['assignment_type'])); ?></span>
                                    </span>
                                    <span class="btn-group">
                                        <a href="edit_assignment.php?assignment_id=<?php echo $assignment['assignment_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Assignment"><i class="bi bi-pencil"></i> Edit</a>
                                        <a href="delete_assignment.php?assignment_id=<?php echo $assignment['assignment_id']; ?>&course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-outline-danger" title="Delete Assignment" onclick="return confirm('Are you sure you want to delete this assignment?');"><i class="bi bi-trash"></i> Delete</a>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <a href="add_content.php?course_id=<?php echo $course_id; ?>&week=<?php echo $week; ?>" class="btn btn-sm btn-outline-primary">Add Content</a>
                <a href="add_assignment.php?course_id=<?php echo $course_id; ?>&week=<?php echo $week; ?>" class="btn btn-sm btn-outline-success">Add Assignment</a>
            </div>
        </div>
    <?php endfor; ?>
</div>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
<script src="../assets/js/notifications.js"></script>
<style>
    #vle-notif-dropdown.show { display: block !important; }
    .notif-item:hover { background-color: #f8f9fa; }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
