<?php
/**
 * Examination Manager Header Navigation
 * Shared navigation component for all examination manager pages
 * Uses unified VLE theme (global-theme.css)
 */

// Get current user
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get profile info
$manager_name = $user['display_name'] ?? 'Manager';

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'create_exam.php', 'icon' => 'bi-plus-circle', 'title' => 'Create Exam'],
    ['url' => 'generate_tokens.php', 'icon' => 'bi-key', 'title' => 'Tokens'],
    ['url' => 'security_monitoring.php', 'icon' => 'bi-shield-check', 'title' => 'Security'],
    ['url' => 'semester_reports.php', 'icon' => 'bi-file-earmark-bar-graph', 'title' => 'Semester Reports'],
];

$page_title = $page_title ?? 'Examination Manager Portal';
?>
<!-- Global Theme CSS -->
<link href="../assets/css/global-theme.css" rel="stylesheet">

<!-- Examination Manager Header Navigation -->
<!-- Bootstrap Icons 1.10.0 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <!-- Logo and Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE Exam Manager</span>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#examManagerNav" aria-controls="examManagerNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Links -->
        <div class="collapse navbar-collapse" id="examManagerNav">
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
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="rounded-circle bg-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; color: var(--vle-primary); font-weight: 700;">
                            <?= strtoupper(substr($manager_name, 0, 1)) ?>
                        </div>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($manager_name) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header">Examination Manager</h6></li>
                        <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
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

<!-- Page Header -->
<?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
<div class="vle-page-header">
    <div class="container-fluid">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (isset($crumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>"><?= htmlspecialchars($crumb['title']) ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active"><?= htmlspecialchars($crumb['title']) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
<?php endif; ?>
