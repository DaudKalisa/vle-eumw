
<?php
// edit_course.php - Edit VLE course details
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);
require_once '../includes/notifications.php';

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_name = trim($_POST['course_name'] ?? '');
    $course_code = trim($_POST['course_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $total_weeks = (int)($_POST['total_weeks'] ?? 12);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($course_name) && !empty($course_code)) {
        $stmt = $conn->prepare("UPDATE vle_courses SET course_name = ?, course_code = ?, description = ?, total_weeks = ?, is_active = ? WHERE course_id = ?");
        $stmt->bind_param("sssiii", $course_name, $course_code, $description, $total_weeks, $is_active, $course_id);

        if ($stmt->execute()) {
            header("Location: dashboard.php?course_id=$course_id&success=1");
            exit();
        } else {
            $error = "Failed to update course: " . $conn->error;
        }
    } else {
        $error = "Course name and code are required.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
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
                    <li class="nav-item"><a class="nav-link" href="announcements.php?course_id=<?php echo $course_id; ?>">Announcements</a></li>
                    <li class="nav-item"><a class="nav-link" href="forum.php?course_id=<?php echo $course_id; ?>">Forums</a></li>
                    <li class="nav-item"><a class="nav-link active" href="edit_course.php?course_id=<?php echo $course_id; ?>">Edit Course</a></li>
                </ul>
                <!-- RIGHT SIDE: Search, Notifications, Messages, Help, User Dropdown -->
                <form class="d-flex me-3" role="search" style="max-width:220px;">
                    <input class="form-control form-control-sm me-2" type="search" placeholder="Search..." aria-label="Search">
                </form>
                <?php
                    $ec_user_id = $_SESSION['vle_user_id'] ?? 0;
                    $ec_lecturer_id = $_SESSION['vle_related_id'] ?? '';
                    if ($ec_user_id && $ec_lecturer_id) {
                        generateLecturerNotifications($ec_user_id, $ec_lecturer_id);
                    }
                    $ec_unread = $ec_user_id ? getUnreadNotificationCount($ec_user_id) : 0;
                    $ec_notifs = $ec_user_id ? getNotifications($ec_user_id, 10) : [];
                ?>
                <ul class="navbar-nav align-items-center mb-2 mb-lg-0">
                    <!-- Notification Bell with Dropdown -->
                    <li class="nav-item me-2 position-relative">
                        <a class="nav-link position-relative" href="#" id="vle-notif-bell" title="Notifications">
                            <i class="bi bi-bell" style="font-size:1.3rem;"></i>
                            <span class="vle-notif-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  style="<?= $ec_unread ? '' : 'display:none;' ?>">
                                <?= $ec_unread ?: '' ?>
                            </span>
                        </a>
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
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-hourglass-split" style="font-size:1.5rem;"></i>
                                    <p class="mb-0 mt-2 small">Loading...</p>
                                </div>
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
    <!-- Centered Back Button in Header -->
    <div class="w-100 d-flex justify-content-center align-items-center" style="position:relative;top:-10px;z-index:1051;">
        <button class="btn btn-outline-secondary mb-2" onclick="window.history.back();">
            <i class="bi bi-arrow-left"></i> Back
        </button>
    </div>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Course - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h4>Edit Course</h4>

                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="course_name" class="form-label">Course Name *</label>
                                <input type="text" class="form-control" id="course_name" name="course_name" value="<?php echo htmlspecialchars($course['course_name']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="course_code" class="form-label">Course Code *</label>
                                <input type="text" class="form-control" id="course_code" name="course_code" value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($course['description']); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="total_weeks" class="form-label">Total Weeks</label>
                                <input type="number" class="form-control" id="total_weeks" name="total_weeks" value="<?php echo $course['total_weeks']; ?>" min="1" max="52">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?php echo $course['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Course is Active</label>
                            </div>

                            <button type="submit" class="btn btn-primary">Update Course</button>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="../assets/js/notifications.js"></script>
    <style>
        #vle-notif-dropdown.show { display: block !important; }
        .notif-item:hover { background-color: #f8f9fa; }
    </style>
</body>
</html>
