<?php
// admin/zoom_settings.php - Manage Zoom account settings
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$message = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_zoom_settings'])) {
    $zoom_email = trim($_POST['zoom_email']);
    $zoom_api_key = trim($_POST['zoom_api_key']);
    $zoom_api_secret = trim($_POST['zoom_api_secret']);
    $zoom_meeting_password = trim($_POST['zoom_meeting_password'] ?? '');
    $zoom_enable_recording = isset($_POST['zoom_enable_recording']) ? 1 : 0;
    $zoom_require_auth = isset($_POST['zoom_require_authentication']) ? 1 : 0;
    $zoom_wait_host = isset($_POST['zoom_wait_for_host']) ? 1 : 0;
    $zoom_auto_recording = $_POST['zoom_auto_recording'] ?? 'none';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate inputs
    if (empty($zoom_email) || empty($zoom_api_key) || empty($zoom_api_secret)) {
        $error = "Zoom email, API key, and API secret are required!";
    } else if (!filter_var($zoom_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid Zoom email address!";
    } else if (strlen($zoom_api_key) < 10) {
        $error = "API key seems too short. Please verify your Zoom API key!";
    } else if (strlen($zoom_api_secret) < 10) {
        $error = "API secret seems too short. Please verify your Zoom API secret!";
    } else {
        // Check if settings already exist
        $check = $conn->prepare("SELECT setting_id FROM zoom_settings WHERE zoom_account_email = ?");
        if (!$check) {
            $error = "Database table 'zoom_settings' needs to be created. Please run setup_zoom_settings.php first!";
        } else {
            $check->bind_param("s", $zoom_email);
            $check->execute();
            $check_result = $check->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing
            $stmt = $conn->prepare("UPDATE zoom_settings SET zoom_api_key = ?, zoom_api_secret = ?, zoom_meeting_password = ?, zoom_enable_recording = ?, zoom_require_authentication = ?, zoom_wait_for_host = ?, zoom_auto_recording = ?, is_active = ? WHERE zoom_account_email = ?");
            $stmt->bind_param("sssiiiisi", $zoom_api_key, $zoom_api_secret, $zoom_meeting_password, $zoom_enable_recording, $zoom_require_auth, $zoom_wait_host, $zoom_auto_recording, $is_active, $zoom_email);
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO zoom_settings (zoom_account_email, zoom_api_key, zoom_api_secret, zoom_meeting_password, zoom_enable_recording, zoom_require_authentication, zoom_wait_for_host, zoom_auto_recording, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssiiiisi", $zoom_email, $zoom_api_key, $zoom_api_secret, $zoom_meeting_password, $zoom_enable_recording, $zoom_require_auth, $zoom_wait_host, $zoom_auto_recording, $is_active);
        }
        
        if ($stmt->execute()) {
            $message = "Zoom settings saved successfully! ✓";
            $stmt->close();
        } else {
            $error = "Error saving Zoom settings: " . $stmt->error;
            $stmt->close();
        }
        $check->close();
        };
    }
}

// Delete Zoom settings
if (isset($_GET['delete'])) {
    $setting_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM zoom_settings WHERE setting_id = ?");
    $stmt->bind_param("i", $setting_id);
    if ($stmt->execute()) {
        $message = "Zoom settings deleted successfully!";
    }
    $stmt->close();
}

// Get all Zoom settings
$zoom_settings = [];
$result = $conn->query("SELECT * FROM zoom_settings ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $zoom_settings[] = $row;
    }
} else {
    // Table doesn't exist yet - show a setup message instead of error
    $zoom_settings = [];
}

// Get active Zoom setting
$active_zoom = null;
$active_result = $conn->query("SELECT * FROM zoom_settings WHERE is_active = TRUE LIMIT 1");
if ($active_result && $active_result->num_rows > 0) {
    $active_zoom = $active_result->fetch_assoc();
}

// Get first setting for edit form
$form_data = !empty($zoom_settings) ? $zoom_settings[0] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zoom Settings - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $breadcrumbs = [['title' => 'Zoom Settings']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-camera-video"></i> Zoom Integration</h2>
                <p class="text-muted mb-0">Configure your Zoom account for live classroom sessions</p>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <!-- Configuration Form -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Zoom Account Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="update_zoom_settings" value="1">
                            
                            <div class="alert alert-info mb-4">
                                <i class="bi bi-info-circle"></i>
                                <strong>How to get Zoom API credentials:</strong>
                                <ol class="mb-0 mt-2">
                                    <li>Go to <a href="https://marketplace.zoom.us/" target="_blank">Zoom App Marketplace</a></li>
                                    <li>Sign in with your Zoom account</li>
                                    <li>Create a new "Server-to-Server OAuth" app</li>
                                    <li>Copy your <strong>Account ID</strong> (use as API Key) and <strong>Client Secret</strong></li>
                                    <li>Paste them below</li>
                                </ol>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-envelope"></i> Zoom Account Email *</label>
                                <input type="email" class="form-control" name="zoom_email" 
                                       value="<?php echo $form_data ? htmlspecialchars($form_data['zoom_account_email']) : ''; ?>"
                                       placeholder="your-email@zoom.us" required>
                                <small class="text-muted">The email associated with your Zoom account</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-key"></i> Zoom API Key (Account ID) *</label>
                                <input type="password" class="form-control" name="zoom_api_key" 
                                       value="<?php echo $form_data ? htmlspecialchars($form_data['zoom_api_key']) : ''; ?>"
                                       placeholder="Your Zoom Account ID" required>
                                <small class="text-muted">From Zoom App Marketplace → Your App → Account ID</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-shield-lock"></i> Zoom API Secret (Client Secret) *</label>
                                <input type="password" class="form-control" name="zoom_api_secret" 
                                       value="<?php echo $form_data ? htmlspecialchars($form_data['zoom_api_secret']) : ''; ?>"
                                       placeholder="Your Zoom Client Secret" required>
                                <small class="text-muted">From Zoom App Marketplace → Your App → Client Secret</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="bi bi-lock"></i> Default Meeting Password (Optional)</label>
                                <input type="text" class="form-control" name="zoom_meeting_password" 
                                       value="<?php echo $form_data ? htmlspecialchars($form_data['zoom_meeting_password']) : ''; ?>"
                                       placeholder="Leave blank for no password" maxlength="20">
                                <small class="text-muted">Optional password for all Zoom meetings created through this system</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-arrow-clockwise"></i> Auto Recording</label>
                                    <select class="form-select" name="zoom_auto_recording">
                                        <option value="none" <?php echo ($form_data && $form_data['zoom_auto_recording'] == 'none') ? 'selected' : ''; ?>>None</option>
                                        <option value="local" <?php echo ($form_data && $form_data['zoom_auto_recording'] == 'local') ? 'selected' : ''; ?>>Local Recording</option>
                                        <option value="cloud" <?php echo ($form_data && $form_data['zoom_auto_recording'] == 'cloud') ? 'selected' : ''; ?>>Cloud Recording</option>
                                    </select>
                                    <small class="text-muted">Automatically record sessions</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Meeting Settings</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="zoom_enable_recording" id="enableRecording"
                                               <?php echo ($form_data && $form_data['zoom_enable_recording']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enableRecording">
                                            Enable Recording <small class="text-muted">(students can record)</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="zoom_require_authentication" id="requireAuth"
                                               <?php echo ($form_data && $form_data['zoom_require_authentication']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="requireAuth">
                                            Require Authentication <small class="text-muted">(signed-in Zoom users only)</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="zoom_wait_for_host" id="waitForHost"
                                               <?php echo ($form_data && $form_data['zoom_wait_for_host']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="waitForHost">
                                            Wait for Host <small class="text-muted">(students wait until lecturer joins)</small>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                           <?php echo ($form_data && $form_data['is_active']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="isActive">
                                        <strong>Activate this Zoom account</strong> <small class="text-muted">(will be used for all live sessions)</small>
                                    </label>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Zoom Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Status and Info -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Integration Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($active_zoom): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i>
                                <strong>Active Account</strong>
                                <br>
                                <small><?php echo htmlspecialchars($active_zoom['zoom_account_email']); ?></small>
                            </div>
                            <p><strong>Features Enabled:</strong></p>
                            <ul class="mb-0 small">
                                <li><?php echo $active_zoom['zoom_enable_recording'] ? '✓' : '✗'; ?> Recording</li>
                                <li><?php echo $active_zoom['zoom_require_authentication'] ? '✓' : '✗'; ?> Authentication Required</li>
                                <li><?php echo $active_zoom['zoom_wait_for_host'] ? '✓' : '✗'; ?> Wait for Host</li>
                                <li>Auto Recording: <strong><?php echo ucfirst($active_zoom['zoom_auto_recording']); ?></strong></li>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>No Active Account</strong>
                                <br>
                                <small>Please configure and activate a Zoom account above</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Configured Accounts -->
                <?php if (!empty($zoom_settings)): ?>
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-list"></i> All Accounts (<?php echo count($zoom_settings); ?>)</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($zoom_settings as $zoom): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($zoom['zoom_account_email']); ?>
                                        <?php if ($zoom['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted">
                                        Added: <?php echo date('M d, Y', strtotime($zoom['created_at'])); ?>
                                    </small>
                                </div>
                                <a href="?delete=<?php echo $zoom['setting_id']; ?>" class="btn btn-sm btn-danger" 
                                   onclick="return confirm('Delete this Zoom account?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
