<?php
/**
 * HOD Header Navigation
 * Shared navigation component for all Head of Department pages
 * Uses unified VLE theme (global-theme.css)
 */

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Get HOD info
$hod_name = $user['display_name'] ?? 'HOD';
$hod_department = '';
$hod_department_id = null;

if (!empty($user['related_staff_id'])) {
    $nav_conn = getDbConnection();
    $nav_stmt = $nav_conn->prepare("SELECT full_name, department FROM administrative_staff WHERE staff_id = ?");
    if ($nav_stmt) {
        $nav_stmt->bind_param("i", $user['related_staff_id']);
        $nav_stmt->execute();
        $nav_result = $nav_stmt->get_result();
        if ($nav_row = $nav_result->fetch_assoc()) {
            $hod_name = $nav_row['full_name'];
            $hod_department = $nav_row['department'] ?? '';
        }
    }
    // Try to get department_id
    if ($hod_department) {
        $dept_stmt = $nav_conn->prepare("SELECT department_id FROM departments WHERE department_name = ? OR department_code = ?");
        if ($dept_stmt) {
            $dept_stmt->bind_param("ss", $hod_department, $hod_department);
            $dept_stmt->execute();
            $dept_row = $dept_stmt->get_result()->fetch_assoc();
            if ($dept_row) $hod_department_id = $dept_row['department_id'];
        }
    }
}

// Navigation items
$nav_items = [
    ['url' => 'dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Dashboard'],
    ['url' => 'courses.php', 'icon' => 'bi-book', 'title' => 'Courses'],
    ['url' => 'lecturers.php', 'icon' => 'bi-person-badge', 'title' => 'Lecturers'],
    ['url' => 'students.php', 'icon' => 'bi-people', 'title' => 'Students'],
    ['url' => 'course_allocations.php', 'icon' => 'bi-diagram-3', 'title' => 'Allocations'],
    ['url' => 'reports.php', 'icon' => 'bi-bar-chart', 'title' => 'Reports'],
];

$page_title = $page_title ?? 'HOD Portal';
?>
<!-- HOD Header Navigation -->
<!-- Bootstrap Icons 1.10.0 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<nav class="navbar navbar-expand-lg navbar-dark vle-navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="../assets/img/Logo.png" alt="VLE Logo" style="height: 38px; width: auto; margin-right: 10px;">
            <span class="fw-bold">VLE - HOD Portal</span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hodNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="hodNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($nav_items as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === $item['url']) ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                        <i class="<?= $item['icon'] ?> me-1"></i> <?= $item['title'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;">
                            <i class="bi bi-person-circle"></i>
                        </div>
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($hod_name) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text"><small class="text-muted">Head of Department</small></span></li>
                        <?php if ($hod_department): ?>
                        <li><span class="dropdown-item-text"><small class="text-muted"><?= htmlspecialchars($hod_department) ?></small></span></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i>Help & Guide</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php if (!empty($breadcrumbs)): ?>
<div class="vle-breadcrumb">
    <div class="container-fluid">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">HOD Portal</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                <li class="breadcrumb-item active"><?= $crumb['title'] ?></li>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
<?php endif; ?>
