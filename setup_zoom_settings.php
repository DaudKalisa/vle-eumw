<?php
// setup_zoom_settings.php - Create zoom_settings table if it doesn't exist
require_once 'includes/auth.php';

// Check if user is logged in and is staff/admin
if (isset($_SESSION['user_id'])) {
    $is_staff = isset($_SESSION['role']) && in_array($_SESSION['role'], ['staff', 'admin']);
} else {
    $is_staff = false;
}

$conn = getDbConnection();
$message = '';
$error = '';
$table_exists = false;

// Check if table already exists
$check_result = $conn->query("SHOW TABLES LIKE 'zoom_settings'");
if ($check_result && $check_result->num_rows > 0) {
    $table_exists = true;
    $message = "✓ zoom_settings table already exists!";
} else {
    // Create the table
    $sql = "CREATE TABLE IF NOT EXISTS zoom_settings (
        setting_id INT PRIMARY KEY AUTO_INCREMENT,
        zoom_account_email VARCHAR(255) UNIQUE NOT NULL,
        zoom_api_key VARCHAR(255) NOT NULL,
        zoom_api_secret VARCHAR(255) NOT NULL,
        zoom_meeting_password VARCHAR(100),
        zoom_enable_recording BOOLEAN DEFAULT 1,
        zoom_require_authentication BOOLEAN DEFAULT 0,
        zoom_wait_for_host BOOLEAN DEFAULT 0,
        zoom_auto_recording ENUM('none', 'local', 'cloud') DEFAULT 'none',
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY(zoom_account_email),
        KEY(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($conn->query($sql)) {
        $table_exists = true;
        $message = "✓ zoom_settings table created successfully!";
    } else {
        $error = "✗ Error creating table: " . $conn->error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Zoom Settings - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .setup-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 500px;
            width: 100%;
        }
        .setup-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .setup-header h1 {
            color: #333;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .setup-header p {
            color: #666;
            font-size: 14px;
        }
        .status-message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status-message i {
            font-size: 20px;
        }
        .setup-actions {
            text-align: center;
            margin-top: 30px;
        }
        .setup-actions a {
            display: inline-block;
            padding: 10px 30px;
            background-color: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
            margin: 5px;
        }
        .setup-actions a:hover {
            background-color: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1><i class="bi bi-gear"></i> Zoom Settings Setup</h1>
            <p>Initialize Zoom integration table</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="status-message success">
                <i class="bi bi-check-circle-fill"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="status-message error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($table_exists): ?>
            <div class="alert alert-info" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                The zoom_settings table is ready. You can now configure Zoom settings in the admin panel.
            </div>
        <?php endif; ?>

        <div class="setup-actions">
            <a href="admin/zoom_settings.php" class="btn-link">
                <i class="bi bi-gear me-2"></i>Go to Zoom Settings
            </a>
            <br>
            <a href="admin/dashboard.php" class="btn-link">
                <i class="bi bi-speedometer2 me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
