<?php
// VLE Student Navigation Header with Breadcrumb System
if (!isset($user)) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once '../includes/auth.php';
    requireLogin();
    $user = getCurrentUser();
    $student_id = $_SESSION['vle_related_id'] ?? '';
}

// Determine base path for student links — works from /student/ and /examination/
$_nav_in_student_dir = (strpos($_SERVER['PHP_SELF'], '/student/') !== false);
$_student_base = $_nav_in_student_dir ? '' : '../student/';
$_root_base = '../';

// Breadcrumb configuration
$breadcrumbs = isset($breadcrumbs) ? $breadcrumbs : [];
$page_title = isset($page_title) ? $page_title : 'Student Portal';
?>
<!-- Global Theme CSS -->
<link href="../assets/css/global-theme.css" rel="stylesheet">

<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $_student_base ?>dashboard.php">
            <img src="../assets/img/Logo.png" alt="Logo">
            <span>VLE-EUMW</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="studentNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='dashboard.php' && $_nav_in_student_dir)echo' active'; ?>" href="<?= $_student_base ?>dashboard.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='courses.php')echo' active'; ?>" href="<?= $_student_base ?>courses.php"><i class="bi bi-book me-1"></i>Course Access</a></li>
                <li class="nav-item"><a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='messages.php' && $_nav_in_student_dir)echo' active'; ?>" href="<?= $_student_base ?>messages.php"><i class="bi bi-chat-dots me-1"></i>Messages</a></li>
                <li class="nav-item"><a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='register_courses.php')echo' active'; ?>" href="<?= $_student_base ?>register_courses.php"><i class="bi bi-journal-plus me-1"></i>Register Courses</a></li>
                <li class="nav-item"><a class="nav-link<?php if(strpos($_SERVER['PHP_SELF'],'examination/')!==false)echo' active'; ?>" href="<?= $_root_base ?>examination/exams.php"><i class="bi bi-file-earmark-text me-1"></i>Examinations</a></li>
                <li class="nav-item"><a class="nav-link<?php if(basename($_SERVER['PHP_SELF'])=='payment_history.php')echo' active'; ?>" href="<?= $_student_base ?>payment_history.php"><i class="bi bi-credit-card me-1"></i>Payment History</a></li>
            </ul>
            <ul class="navbar-nav align-items-center mb-2 mb-lg-0 ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="me-2" style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:0.85rem;">
                            <?php echo strtoupper(substr($user['display_name'] ?? $student_id, 0, 1)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($user['display_name'] ?? $student_id); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="<?= $_student_base ?>profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="<?= $_student_base ?>change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="<?= $_root_base ?>theme_settings.php"><i class="bi bi-palette me-2"></i>Theme</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= $_root_base ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Breadcrumb Navigation -->
<?php if (!empty($breadcrumbs)): ?>
<div class="vle-page-header">
    <div class="container-fluid">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= $_student_base ?>dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <?php foreach ($breadcrumbs as $breadcrumb): ?>
                    <?php if (isset($breadcrumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?php echo $breadcrumb['url']; ?>"><?php echo htmlspecialchars($breadcrumb['title']); ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($breadcrumb['title']); ?></li>
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
