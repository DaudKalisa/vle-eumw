<?php
/**
 * ODL Coordinator Header Navigation
 * Shared navigation component for all ODL coordinator pages
 */

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get coordinator profile picture
$coordinator_profile_pic = null;
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT profile_picture, full_name FROM odl_coordinators WHERE user_id = ?");
$stmt->bind_param("i", $user['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $coordinator_profile_pic = $row['profile_picture'];
    $coordinator_name = $row['full_name'];
}

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'claims_approval.php', 'icon' => 'bi-clipboard-check', 'title' => 'Claims'],
    ['url' => 'student_verification.php', 'icon' => 'bi-person-check', 'title' => 'Students'],
    ['url' => 'manage_courses.php', 'icon' => 'bi-book', 'title' => 'Courses'],
    ['url' => 'manage_timetable.php', 'icon' => 'bi-calendar-week', 'title' => 'Timetable'],
    ['url' => 'exam_management.php', 'icon' => 'bi-journal-text', 'title' => 'Exams'],
    ['url' => 'reports.php', 'icon' => 'bi-graph-up', 'title' => 'Reports'],
];

$page_title = $page_title ?? 'ODL Coordinator Portal';
?>
<!-- ODL Coordinator Header Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar odl-nav" style="background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE ODL</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#odlNavbar" aria-controls="odlNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="odlNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                        <i class="<?= $item['icon'] ?> me-1"></i> <?= $item['title'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
                
                <!-- More Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="moreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-grid me-1"></i> More
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="moreDropdown">
                        <li><a class="dropdown-item" href="course_allocation.php"><i class="bi bi-person-gear me-2"></i>Course Allocation</a></li>
                        <li><a class="dropdown-item" href="student_enrollment.php"><i class="bi bi-people me-2"></i>Student Enrollment</a></li>
                        <li><a class="dropdown-item" href="course_monitoring.php"><i class="bi bi-bar-chart me-2"></i>Course Monitoring</a></li>
                        <li><a class="dropdown-item" href="student_progress.php"><i class="bi bi-graph-up me-2"></i>Student Progress</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-clock-history me-2"></i>Activity Logs</a></li>
                    </ul>
                </li>
            </ul>
            
            <!-- Right Side - User Menu -->
            <div class="navbar-nav">
                <!-- Back to Dashboard Button -->
                <?php if ($current_page !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3 d-none d-lg-inline-flex align-items-center">
                    <i class="bi bi-house me-1"></i> Dashboard
                </a>
                <?php endif; ?>
                
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($coordinator_profile_pic) && file_exists('../uploads/profiles/' . $coordinator_profile_pic)): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($coordinator_profile_pic) ?>" class="rounded-circle me-2" style="width: 35px; height: 35px; object-fit: cover; border: 2px solid white;" alt="Profile">
                        <?php else: ?>
                            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: #2c3e50; font-weight: 700;">
                                <?= strtoupper(substr($coordinator_name ?? 'O', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($coordinator_name ?? 'ODL Coordinator') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li>
                            <div class="dropdown-item-text">
                                <strong><?= htmlspecialchars($coordinator_name ?? 'ODL Coordinator') ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i>Help & Guide</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </div>
</nav>

<style>
.odl-nav {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.odl-nav .nav-link {
    color: rgba(255,255,255,0.85) !important;
    transition: all 0.2s;
}
.odl-nav .nav-link:hover,
.odl-nav .nav-link.active {
    color: #fff !important;
    background: rgba(255,255,255,0.1);
    border-radius: 6px;
}
.odl-nav .dropdown-menu {
    border: none;
    border-radius: 10px;
}
</style>
