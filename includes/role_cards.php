<?php
/**
 * Other Roles Cards Component
 * 
 * Displays cards for the user's additional roles so they can quickly
 * switch to their other dashboards. Include this file in any dashboard
 * after calling requireLogin().
 * 
 * Usage: 
 *   $current_role_context = 'lecturer';  // The role this dashboard serves
 *   include '../includes/role_cards.php'; // or include 'role_cards.php' if in includes/
 * 
 * The variable $current_role_context must be set before including this file.
 * It tells the component which role dashboard we're currently on, so it
 * won't show a card for the current role.
 */

if (!isset($current_role_context)) {
    $current_role_context = $_SESSION['vle_role'] ?? '';
}

// Get the user's PRIMARY (main) role 
$_primary_role = $_SESSION['vle_role'] ?? '';

// Get all user roles
$_all_user_roles = function_exists('getAllUserRoles') ? getAllUserRoles() : [];

// Role definitions: role_key => [label, icon, gradient, dashboard_path, description]
$_role_definitions = [
    'admin' => [
        'label' => 'Admin',
        'icon' => 'bi-shield-lock-fill',
        'gradient' => 'linear-gradient(135deg, #6366f1, #4f46e5)',
        'path' => '/admin/dashboard.php',
        'description' => 'System Administration'
    ],
    'staff' => [
        'label' => 'Admin',
        'icon' => 'bi-shield-lock-fill',
        'gradient' => 'linear-gradient(135deg, #6366f1, #4f46e5)',
        'path' => '/admin/dashboard.php',
        'description' => 'System Administration'
    ],
    'lecturer' => [
        'label' => 'Lecturer',
        'icon' => 'bi-person-video3',
        'gradient' => 'linear-gradient(135deg, #3b82f6, #2563eb)',
        'path' => '/lecturer/dashboard.php',
        'description' => 'Course & Teaching Management'
    ],
    'student' => [
        'label' => 'Student',
        'icon' => 'bi-mortarboard',
        'gradient' => 'linear-gradient(135deg, #667eea, #764ba2)',
        'path' => '/student/dashboard.php',
        'description' => 'Student Dashboard'
    ],
    'examination_manager' => [
        'label' => 'Examination Manager',
        'icon' => 'bi-journal-check',
        'gradient' => 'linear-gradient(135deg, #f59e0b, #d97706)',
        'path' => '/examination_manager/dashboard.php',
        'description' => 'Exam Management & Monitoring'
    ],
    'examination_officer' => [
        'label' => 'Examination Officer',
        'icon' => 'bi-shield-check',
        'gradient' => 'linear-gradient(135deg, #ec4899, #db2777)',
        'path' => '/examination_officer/dashboard.php',
        'description' => 'Exam Operations & Logistics'
    ],
    'dean' => [
        'label' => 'Dean',
        'icon' => 'bi-mortarboard-fill',
        'gradient' => 'linear-gradient(135deg, #8b5cf6, #7c3aed)',
        'path' => '/dean/dashboard.php',
        'description' => 'Faculty Oversight & Approvals'
    ],
    'finance' => [
        'label' => 'Finance',
        'icon' => 'bi-cash-stack',
        'gradient' => 'linear-gradient(135deg, #22c55e, #16a34a)',
        'path' => '/finance/dashboard.php',
        'description' => 'Payments & Financial Records'
    ],
    'odl_coordinator' => [
        'label' => 'ODL Coordinator',
        'icon' => 'bi-globe2',
        'gradient' => 'linear-gradient(135deg, #14b8a6, #0d9488)',
        'path' => '/odl_coordinator/dashboard.php',
        'description' => 'Distance Learning Coordination'
    ],
    'hod' => [
        'label' => 'Head of Department',
        'icon' => 'bi-building',
        'gradient' => 'linear-gradient(135deg, #7c3aed, #6d28d9)',
        'path' => '/hod/dashboard.php',
        'description' => 'Department Academic Oversight'
    ],
    'research_coordinator' => [
        'label' => 'Research Coordinator',
        'icon' => 'bi-journal-bookmark-fill',
        'gradient' => 'linear-gradient(135deg, #059669, #047857)',
        'path' => '/research_coordinator/dashboard.php',
        'description' => 'Dissertation & Research Management'
    ],
];

// Normalize: treat staff/admin as same for "current" check, and examination_manager as exam officer
$_current_normalized = $current_role_context;
if ($_current_normalized === 'staff') $_current_normalized = 'admin';

// Normalize primary role
$_primary_normalized = $_primary_role;
if ($_primary_normalized === 'staff') $_primary_normalized = 'admin';

// Check if user is in a secondary role dashboard (not their main role)
$_is_secondary_dashboard = ($_current_normalized !== $_primary_normalized);

// Build the list of other roles to display
$_other_roles = [];
foreach ($_all_user_roles as $role) {
    $role = trim($role);
    if (empty($role)) continue;
    
    // Normalize for comparison
    $norm = $role;
    if ($norm === 'staff') $norm = 'admin';
    
    // Skip current role context
    if ($norm === $_current_normalized) continue;
    
    // Skip if already added (e.g. admin+staff both point to same)
    $already = false;
    foreach ($_other_roles as $or) {
        if ($or['key'] === $norm) { $already = true; break; }
    }
    if ($already) continue;
    
    // Get definition
    if (isset($_role_definitions[$role])) {
        $_other_roles[] = array_merge($_role_definitions[$role], ['key' => $norm]);
    }
}

// Determine base path relative to current file
$_base_path = '';
if (defined('VLE_BASE_URL')) {
    $_base_path = rtrim(VLE_BASE_URL, '/');
} else {
    // Auto-detect: assume we're one level deep (e.g., /lecturer/, /admin/)
    $_base_path = '..';
}

// Get main dashboard info for "Back to Main Dashboard" button
$_main_dashboard = isset($_role_definitions[$_primary_role]) ? $_role_definitions[$_primary_role] : null;

// Show "Back to Main Dashboard" button if user is in a secondary role dashboard
if ($_is_secondary_dashboard && $_main_dashboard):
?>
<!-- Back to Main Dashboard Button -->
<div class="mb-4">
    <a href="<?php echo $_base_path . $_main_dashboard['path']; ?>" 
       class="btn btn-lg w-100 d-flex align-items-center justify-content-center gap-2"
       style="background: linear-gradient(135deg, #0d1b4a, #1b3a7b); color: white; border: none; border-radius: 12px; padding: 16px 24px; font-weight: 600; box-shadow: 0 4px 15px rgba(13,27,74,0.3); transition: all 0.3s;"
       onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 25px rgba(13,27,74,0.4)';"
       onmouseout="this.style.transform=''; this.style.boxShadow='0 4px 15px rgba(13,27,74,0.3)';">
        <i class="bi bi-arrow-left-circle-fill" style="font-size: 1.5rem;"></i>
        <span>Back to <?php echo htmlspecialchars($_main_dashboard['label']); ?> Dashboard</span>
    </a>
</div>
<?php endif; ?>

<?php
// Only render "Other Roles" section if there are other roles
if (!empty($_other_roles)):
?>
<!-- Other Roles Section -->
<div class="card mt-4 mb-4 border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
    <div class="card-header border-0 py-3" style="background: linear-gradient(135deg, #1e293b, #334155); color: white;">
        <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Your Other Roles</h5>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <?php foreach ($_other_roles as $orole): ?>
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <a href="<?php echo $_base_path . $orole['path']; ?>" 
                       class="text-decoration-none d-block h-100"
                       style="border-radius: 12px; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s;"
                       onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(0,0,0,0.15)';"
                       onmouseout="this.style.transform=''; this.style.boxShadow='';">
                        <div class="text-center p-3 h-100 d-flex flex-column align-items-center justify-content-center" 
                             style="background: <?php echo $orole['gradient']; ?>; border-radius: 12px; min-height: 140px;">
                            <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                                <i class="bi <?php echo $orole['icon']; ?> text-white" style="font-size: 1.4rem;"></i>
                            </div>
                            <div class="text-white fw-semibold" style="font-size: 1rem;"><?php echo $orole['label']; ?></div>
                            <div class="text-white-50" style="font-size: 0.78rem;"><?php echo $orole['description']; ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
