<?php
/**
 * Lecturer Header Navigation
 * Shared navigation component for all lecturer pages
 * Uses unified VLE theme (global-theme.css)
 */

// Get current user
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Load notification system
require_once __DIR__ . '/../includes/notifications.php';
$notif_user_id = $_SESSION['vle_user_id'] ?? 0;
$notif_lecturer_id = $_SESSION['vle_related_id'] ?? '';
if ($notif_user_id && $notif_lecturer_id) {
    generateLecturerNotifications($notif_user_id, $notif_lecturer_id);
}
$unread_notif_count = $notif_user_id ? getUnreadNotificationCount($notif_user_id) : 0;
$recent_notifications = $notif_user_id ? getNotifications($notif_user_id, 10) : [];

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'messages.php', 'icon' => 'bi-chat-dots', 'title' => 'Messages'],
    ['url' => 'request_finance.php', 'icon' => 'bi-cash-coin', 'title' => 'Finance'],
    ['url' => 'announcements.php', 'icon' => 'bi-megaphone', 'title' => 'Announcements'],
    ['url' => 'forum.php', 'icon' => 'bi-chat-left-text', 'title' => 'Forums'],
    ['url' => 'gradebook.php', 'icon' => 'bi-journal-check', 'title' => 'Gradebook'],
    ['url' => 'exam_marking.php', 'icon' => 'bi-pencil-square', 'title' => 'Exam Marking'],
];

// Get page title from breadcrumbs or default
$page_title = $page_title ?? 'Lecturer Portal';
?>
<!-- Lecturer Header Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE-EUMW</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#lecturerNavbar" aria-controls="lecturerNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="lecturerNavbar">
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
                        <li><a class="dropdown-item" href="class_session.php"><i class="bi bi-clipboard-check me-2"></i>Attendance Sessions</a></li>
                        <li><a class="dropdown-item" href="manage_content.php"><i class="bi bi-folder me-2"></i>Course Content</a></li>
                        <li><a class="dropdown-item" href="approve_downloads.php"><i class="bi bi-download me-2"></i>Download Requests</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                    </ul>
                </li>
            </ul>
            
            <!-- Right Side - User Menu -->
            <div class="navbar-nav align-items-center">
                <!-- Back to Dashboard Button -->
                <?php if ($current_page !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3 d-none d-lg-inline-flex align-items-center">
                    <i class="bi bi-house me-1"></i> Dashboard
                </a>
                <?php endif; ?>
                
                <!-- Notification Bell -->
                <li class="nav-item me-2 position-relative" style="list-style:none;">
                    <a class="nav-link position-relative" href="#" id="vle-notif-bell" title="Notifications">
                        <i class="bi bi-bell" style="font-size:1.3rem;"></i>
                        <span class="vle-notif-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="<?= $unread_notif_count ? '' : 'display:none;' ?>">
                            <?= $unread_notif_count ?: '' ?>
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
                            <?php if (empty($recent_notifications)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-bell-slash" style="font-size:2rem;"></i>
                                    <p class="mb-0 mt-2">No notifications yet</p>
                                </div>
                            <?php else: ?>
                                <?php if ($unread_notif_count > 0): ?>
                                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                    <small class="text-muted"><?= $unread_notif_count ?> unread</small>
                                    <a href="#" class="small text-primary" onclick="VLENotif.markAllRead(event)">Mark all read</a>
                                </div>
                                <?php endif; ?>
                                <?php foreach ($recent_notifications as $n): 
                                    $unreadClass = $n['is_read'] == 0 ? 'bg-light border-start border-primary border-3' : '';
                                    $emailIcon = $n['is_emailed'] == 1 ? 'bi-envelope-check-fill' : 'bi-envelope';
                                    $emailBtnClass = $n['is_emailed'] == 1 ? 'text-success' : 'text-muted';
                                ?>
                                <div class="notif-item d-flex align-items-start px-3 py-2 border-bottom <?= $unreadClass ?>"
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
                                        <button class="btn btn-sm p-0 <?= $emailBtnClass ?>"
                                                onclick="VLENotif.emailNotification(<?= $n['notification_id'] ?>, event)"
                                                title="<?= $n['is_emailed'] ? 'Already emailed' : 'Send to my email' ?>" style="font-size:1rem;">
                                            <i class="<?= $emailIcon ?>"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>

                <!-- Messages Link -->
                <li class="nav-item me-2" style="list-style:none;">
                    <a class="nav-link position-relative" href="messages.php" title="Messages">
                        <i class="bi bi-envelope" style="font-size:1.3rem;"></i>
                    </a>
                </li>
                
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: var(--vle-primary); font-weight: 700;">
                            <?= strtoupper(substr($user['display_name'] ?? 'L', 0, 1)) ?>
                        </div>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($user['display_name'] ?? 'Lecturer') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($user['display_name'] ?? 'Lecturer') ?></h6></li>
                        <li><small class="dropdown-header text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
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
<script src="../assets/js/notifications.js"></script>
<style>
    #vle-notif-dropdown.show { display: block !important; }
    .notif-item:hover { background-color: #f8f9fa; }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
</style>