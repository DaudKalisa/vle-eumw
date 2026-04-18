<?php
require_once __DIR__ . '/includes/ui.php';

if (dmsIsLoggedIn()) {
    $role = $_SESSION['dms_role'] ?? '';
    header('Location: ' . dmsBaseUrl() . '/' . dmsRoleDashboard($role));
    exit;
}

dmsRenderPageStart('DMS + Finance Login');
$error = $_SESSION['dms_flash_error'] ?? '';
unset($_SESSION['dms_flash_error']);
?>
<div class="card" style="max-width:520px;margin:40px auto;">
    <h2>Standalone Dissertation + Finance System</h2>
    <p class="muted">This is isolated from the main VLE and uses its own database.</p>
    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="login_process.php">
        <label>Username or Email</label>
        <input type="text" name="username_email" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <button class="btn" type="submit">Login</button>
    </form>
    <p class="muted">First-time setup: open <a href="setup.php">setup.php</a></p>
</div>
<?php dmsRenderPageEnd(); ?>
