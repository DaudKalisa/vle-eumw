<?php
/**
 * Admin Header Navigation
 * Shared navigation component for all admin pages
 * Uses unified VLE theme (global-theme.css + admin-dashboard.css)
 */

// Get current user
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get admin profile picture
$admin_profile_pic = null;
if (!empty($user['related_lecturer_id'])) {
    $pic_conn = getDbConnection();
    $pic_stmt = $pic_conn->prepare("SELECT profile_picture FROM lecturers WHERE lecturer_id = ?");
    $pic_stmt->bind_param("i", $user['related_lecturer_id']);
    $pic_stmt->execute();
    $pic_result = $pic_stmt->get_result();
    if ($pic_row = $pic_result->fetch_assoc()) {
        $admin_profile_pic = $pic_row['profile_picture'];
    }
}

// Navigation items - short labels for compact header (Dashboard keeps full name)
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard', 'short' => ''],
    ['url' => 'approve_registrations.php', 'icon' => 'bi-clipboard-check', 'title' => 'Registrations', 'short' => 'Reg'],
    ['url' => 'manage_courses.php', 'icon' => 'bi-book', 'title' => 'Courses', 'short' => 'CR'],
    ['url' => 'manage_lecturers.php', 'icon' => 'bi-person-badge', 'title' => 'Lecturers', 'short' => 'LC'],
    ['url' => 'manage_students.php', 'icon' => 'bi-people', 'title' => 'Students', 'short' => 'ST'],
    ['url' => 'student_invite_links.php', 'icon' => 'bi-link-45deg', 'title' => 'Student Invites', 'short' => 'SI'],
    ['url' => 'lecturer_invite_links.php', 'icon' => 'bi-person-plus', 'title' => 'Lecturer Invites', 'short' => 'LI'],
    ['url' => 'approve_student_accounts.php', 'icon' => 'bi-person-check', 'title' => 'Approve Students', 'short' => 'SA'],
    ['url' => 'register_student_courses.php', 'icon' => 'bi-journal-plus', 'title' => 'Enroll Students', 'short' => 'EN'],
    ['url' => 'semester_shift.php', 'icon' => 'bi-arrow-repeat', 'title' => 'Semester Shift', 'short' => 'SS'],
    ['url' => 'manage_finance.php', 'icon' => 'bi-cash-coin', 'title' => 'Finance', 'short' => 'FN'],
    ['url' => 'student_reports.php', 'icon' => 'bi-file-earmark-bar-graph', 'title' => 'Reports', 'short' => 'RP'],
    ['url' => 'messages.php', 'icon' => 'bi-chat-dots', 'title' => 'Messages', 'short' => 'MS'],
    ['url' => 'manage_research_coordinators.php', 'icon' => 'bi-mortarboard', 'title' => 'Coordinators', 'short' => 'RC'],
];

// Get page title from breadcrumbs or default
$page_title = $page_title ?? 'Admin Portal';
?>
<!-- Bootstrap Icons 1.10.0 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<!-- Admin Header Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE-EUMW</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <?php if ($item['url'] === 'dashboard.php'): ?>
                        <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>" title="<?= $item['title'] ?>">
                            <i class="<?= $item['icon'] ?> me-1"></i> <?= $item['title'] ?>
                        </a>
                    <?php else: ?>
                        <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>" title="<?= $item['title'] ?>">
                            <i class="<?= $item['icon'] ?>"></i> <span class="d-lg-inline" style="font-size:0.75rem;"><?= $item['short'] ?></span>
                        </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
                
                <!-- More Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="moreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-grid me-1"></i> More
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="moreDropdown">
                        <li><a class="dropdown-item" href="manage_departments.php"><i class="bi bi-building me-2"></i>Departments</a></li>
                        <li><a class="dropdown-item" href="manage_faculties.php"><i class="bi bi-diagram-3 me-2"></i>Faculties</a></li>
                        <li><a class="dropdown-item" href="manage_programs.php"><i class="bi bi-mortarboard me-2"></i>Programs</a></li>
                        <li><a class="dropdown-item" href="manage_administrators.php"><i class="bi bi-person-gear me-2"></i>Administrators</a></li>
                        <li><a class="dropdown-item" href="manage_examination_officers.php"><i class="bi bi-shield-check me-2"></i>Exam Officers</a></li>
                        <li><a class="dropdown-item" href="manage_coordinators.php"><i class="bi bi-person-video3 me-2"></i>Coordinators</a></li>
                        <li><a class="dropdown-item" href="manage_research_coordinators.php"><i class="bi bi-mortarboard me-2"></i>Research Coordinators</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../finance/finance_clearance_invites.php"><i class="bi bi-link-45deg me-2"></i>Finance Clearance Invites</a></li>
                        <li><a class="dropdown-item" href="../finance/Finance_clearence_students.php"><i class="bi bi-person-check me-2"></i>Finance Clearance Students</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="exam_clearance_invite_links.php"><i class="bi bi-link-45deg me-2"></i>Exam Clearance Invites</a></li>
                        <li><a class="dropdown-item" href="../finance/exam_clearance_students.php"><i class="bi bi-person-badge me-2"></i>Exam Clearance Students</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="fee_settings.php"><i class="bi bi-currency-dollar me-2"></i>Fee Settings</a></li>
                        <li><a class="dropdown-item" href="smtp_settings.php"><i class="bi bi-envelope-gear me-2"></i>SMTP Settings</a></li>
                        <li><a class="dropdown-item" href="system_notifications.php"><i class="bi bi-megaphone me-2"></i>System Notifications</a></li>
                        <li><a class="dropdown-item" href="zoom_settings.php"><i class="bi bi-camera-video me-2"></i>Zoom Settings</a></li>
                        <li><a class="dropdown-item" href="university_settings.php"><i class="bi bi-gear me-2"></i>University Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#systemToolsLoginModal" data-tool="database_manager.php"><i class="bi bi-database-gear me-2"></i>Database Manager <i class="bi bi-lock-fill text-danger ms-1"></i></a></li>
                        <li><a class="dropdown-item" href="export_data.php"><i class="bi bi-box-arrow-up-right me-2"></i>Export Data</a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#systemToolsLoginModal" data-tool="file_manager.php"><i class="bi bi-folder2-open me-2"></i>File Manager <i class="bi bi-lock-fill text-danger ms-1"></i></a></li>
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
                        <?php if (!empty($admin_profile_pic) && file_exists('../uploads/profiles/' . $admin_profile_pic)): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($admin_profile_pic) ?>" class="rounded-circle me-2" style="width: 35px; height: 35px; object-fit: cover; border: 2px solid white;" alt="Profile">
                        <?php else: ?>
                            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: var(--vle-primary); font-weight: 700;">
                                <?= strtoupper(substr($user['display_name'] ?? 'A', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($user['display_name'] ?? 'Admin') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($user['display_name'] ?? 'Admin') ?></h6></li>
                        <li><small class="dropdown-header text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i>Help & Guide</a></li>
                        <li><a class="dropdown-item" href="university_settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
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
<div class="vle-page-header">
    <div class="container-fluid">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (isset($crumb['url']) && !empty($crumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>"><?= $crumb['title'] ?? '' ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= $crumb['title'] ?? '' ?></li>
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
