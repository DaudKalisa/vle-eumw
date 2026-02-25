<?php
/**
 * Examination Officer Header Navigation
 * Shared navigation component for all examination officer pages
 * Uses unified VLE theme (global-theme.css)
 */

// Get current user
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get profile info
$officer_profile_pic = null;
$officer_name = '';
if (!empty($user['related_staff_id'])) {
    $pic_conn = getDbConnection();
    $pic_stmt = $pic_conn->prepare("SELECT full_name FROM examination_managers WHERE manager_id = ?");
    $pic_stmt->bind_param("i", $user['related_staff_id']);
    $pic_stmt->execute();
    $pic_result = $pic_stmt->get_result();
    if ($pic_row = $pic_result->fetch_assoc()) {
        $officer_name = $pic_row['full_name'];
    }
}

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'manage_exams.php', 'icon' => 'bi-journal-text', 'title' => 'Examinations'],
    ['url' => 'question_bank.php', 'icon' => 'bi-collection', 'title' => 'Questions'],
    ['url' => 'exam_tokens.php', 'icon' => 'bi-key', 'title' => 'Tokens'],
    ['url' => 'exam_results.php', 'icon' => 'bi-graph-up', 'title' => 'Results'],
    ['url' => 'exam_reports.php', 'icon' => 'bi-file-earmark-bar-graph', 'title' => 'Reports'],
    ['url' => 'monitoring.php', 'icon' => 'bi-camera-video', 'title' => 'Monitoring'],
];

$page_title = $page_title ?? 'Examination Officer Portal';
?>
<!-- Examination Officer Header Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE Examinations</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#examNavbar" aria-controls="examNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="examNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                        <i class="<?= $item['icon'] ?> me-1"></i> <?= $item['title'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <!-- Right Side - User Menu -->
            <div class="navbar-nav">
                <?php if ($current_page !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-3 d-none d-lg-inline-flex align-items-center">
                    <i class="bi bi-house me-1"></i> Dashboard
                </a>
                <?php endif; ?>
                
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($officer_profile_pic) && file_exists('../uploads/profiles/' . $officer_profile_pic)): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($officer_profile_pic) ?>" class="rounded-circle me-2" style="width: 35px; height: 35px; object-fit: cover; border: 2px solid white;" alt="Profile">
                        <?php else: ?>
                            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: var(--vle-primary); font-weight: 700;">
                                <?= strtoupper(substr($user['display_name'] ?? 'E', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($user['display_name'] ?? 'Exam Officer') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($user['display_name'] ?? 'Exam Officer') ?></h6></li>
                        <li><small class="dropdown-header text-muted"><?= htmlspecialchars($user['email'] ?? '') ?></small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
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
                <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house-door"></i> Exam Dashboard</a></li>
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
