<?php
/**
 * Dean Header Navigation
 * Shared navigation component for all dean pages
 */

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get dean's faculty information
$dean_name = $user['display_name'] ?? 'Dean';
$dean_faculty = '';
$dean_profile_pic = null;
$conn = getDbConnection();

// Try to get dean info from users table linked to faculties
if (!empty($user['related_dean_id'])) {
    $stmt = $conn->prepare("SELECT f.faculty_name, f.faculty_id FROM faculties f WHERE f.faculty_id = ?");
    $stmt->bind_param("i", $user['related_dean_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $dean_faculty = $row['faculty_name'];
    }
}

// Try to get profile picture from lecturers if dean is also a lecturer
if (!empty($user['related_lecturer_id'])) {
    $stmt = $conn->prepare("SELECT profile_picture FROM lecturers WHERE lecturer_id = ?");
    $stmt->bind_param("i", $user['related_lecturer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $dean_profile_pic = $row['profile_picture'];
    }
}

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'claims_approval.php', 'icon' => 'bi-clipboard-check', 'title' => 'Claims'],
    ['url' => 'lecturers.php', 'icon' => 'bi-person-badge', 'title' => 'Lecturers'],
    ['url' => 'courses.php', 'icon' => 'bi-book', 'title' => 'Courses'],
    ['url' => 'students.php', 'icon' => 'bi-people', 'title' => 'Students'],
    ['url' => 'exams.php', 'icon' => 'bi-journal-text', 'title' => 'Exams'],
    ['url' => 'reports.php', 'icon' => 'bi-graph-up', 'title' => 'Reports'],
];

$page_title = $page_title ?? 'Dean Portal';
?>
<!-- Dean Header Navigation -->
<!-- Bootstrap Icons 1.10.0 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar dean-nav" style="background: linear-gradient(135deg, #1a472a 0%, #2d5a3e 100%);">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE Dean</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#deanNavbar" aria-controls="deanNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="deanNavbar">
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
                        <li><a class="dropdown-item" href="performance.php"><i class="bi bi-bar-chart me-2"></i>Performance</a></li>
                        <li><a class="dropdown-item" href="departments.php"><i class="bi bi-building me-2"></i>Departments</a></li>
                        <li><a class="dropdown-item" href="programs.php"><i class="bi bi-mortarboard me-2"></i>Programs</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="announcements.php"><i class="bi bi-megaphone me-2"></i>Announcements</a></li>
                        <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-clock-history me-2"></i>Activity Logs</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="academic-calendar.php"><i class="bi bi-calendar-event me-2"></i>Academic Calendar</a></li>
                        <li><a class="dropdown-item" href="manage_timetable.php"><i class="bi bi-calendar-week me-2"></i>Timetable</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    </ul>
                </li>
            </ul>
            
            <!-- Right Side - User Menu -->
            <div class="navbar-nav">
                <!-- Faculty Badge -->
                <?php if ($dean_faculty): ?>
                <span class="navbar-text me-3 d-none d-lg-inline">
                    <span class="badge bg-light text-dark"><?= htmlspecialchars($dean_faculty) ?></span>
                </span>
                <?php endif; ?>
                
                <!-- Back to Dashboard Button -->
                <?php if ($current_page !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3 d-none d-lg-inline-flex align-items-center">
                    <i class="bi bi-house me-1"></i> Dashboard
                </a>
                <?php endif; ?>
                
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($dean_profile_pic) && file_exists('../uploads/profiles/' . $dean_profile_pic)): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($dean_profile_pic) ?>" class="rounded-circle me-2" style="width: 35px; height: 35px; object-fit: cover; border: 2px solid white;" alt="Profile">
                        <?php else: ?>
                            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: #1a472a; font-weight: 700;">
                                <?= strtoupper(substr($dean_name ?? 'D', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($dean_name) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li>
                            <div class="dropdown-item-text">
                                <strong><?= htmlspecialchars($dean_name) ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                                <?php if ($dean_faculty): ?>
                                <div class="small text-muted">Dean of <?= htmlspecialchars($dean_faculty) ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i>Help & Guide</a></li>
                        <li><a class="dropdown-item" href="../theme_settings.php"><i class="bi bi-palette me-2"></i>Theme</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </div>
</nav>

<!-- Page Header / Breadcrumb -->
<?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
<div class="vle-page-header" style="background-color: #f0f7f2;">
    <div class="container-fluid">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (isset($crumb['url']) && !empty($crumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>"><?= $crumb['title'] ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= $crumb['title'] ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
<?php endif; ?>

<!-- Theme Switcher Script -->
<script src="../assets/js/theme-switcher.js"></script>
<script src="../assets/js/auto-logout.js"></script>
