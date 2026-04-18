<?php
/**
 * Research Coordinator Header Navigation
 * Shared navigation component for all research coordinator pages
 * Uses unified VLE theme (global-theme.css)
 */

// Get current user
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'manage_dissertations.php', 'icon' => 'bi-journal-bookmark', 'title' => 'Dissertations'],
    ['url' => 'assign_supervisors.php', 'icon' => 'bi-person-lines-fill', 'title' => 'Supervisors'],
    ['url' => 'review_submissions.php', 'icon' => 'bi-file-earmark-check', 'title' => 'Reviews'],
    ['url' => 'deadline_management.php', 'icon' => 'bi-calendar-event', 'title' => 'Deadlines'],
    ['url' => 'ethical_forms.php', 'icon' => 'bi-file-earmark-medical', 'title' => 'Ethics'],
    ['url' => 'reference_letters.php', 'icon' => 'bi-envelope-paper', 'title' => 'Letters'],
    ['url' => 'defense_management.php', 'icon' => 'bi-mortarboard', 'title' => 'Defense'],
    ['url' => 'similarity_reports.php', 'icon' => 'bi-shield-check', 'title' => 'Similarity'],
    ['url' => 'messages.php', 'icon' => 'bi-chat-dots', 'title' => 'Messages'],
];

// Get page title from breadcrumbs or default
$page_title = $page_title ?? 'Research Coordinator Portal';
?>
<!-- Global Theme CSS -->
<link href="../assets/css/global-theme.css" rel="stylesheet">

<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE-EUMW</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#coordinatorNavbar" aria-controls="coordinatorNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="coordinatorNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                        <i class="bi <?= $item['icon'] ?> me-1"></i> <?= $item['title'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <!-- Right Side: User Menu -->
            <ul class="navbar-nav align-items-center mb-2 mb-lg-0 ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="me-2" style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:0.85rem;color:#fff;">
                            <?= strtoupper(substr($user['display_name'] ?? 'RC', 0, 1)) ?>
                        </div>
                        <span class="text-white"><?= htmlspecialchars($user['display_name'] ?? 'Coordinator') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i>Help & Guide</a></li>
                        <li><a class="dropdown-item" href="../theme_settings.php"><i class="bi bi-palette me-2"></i>Theme</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
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
                <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <?php foreach ($breadcrumbs as $bc): ?>
                    <?php if (isset($bc['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?= $bc['url'] ?>"><?= htmlspecialchars($bc['title']) ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($bc['title']) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
<?php endif; ?>
