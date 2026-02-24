<?php
// setup_smtp_settings.php - Create smtp_settings table if it doesn't exist
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
$check_result = $conn->query("SHOW TABLES LIKE 'smtp_settings'");
if ($check_result && $check_result->num_rows > 0) {
    $table_exists = true;
    $message = "✓ smtp_settings table already exists!";
} else {
    // Create the table
    $sql = "CREATE TABLE IF NOT EXISTS smtp_settings (
        setting_id INT PRIMARY KEY AUTO_INCREMENT,
        smtp_host VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
        smtp_port INT NOT NULL DEFAULT 587,
        smtp_username VARCHAR(255) NOT NULL,
        smtp_password VARCHAR(255) NOT NULL,
        smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
        smtp_from_email VARCHAR(255) NOT NULL,
        smtp_from_name VARCHAR(255) DEFAULT 'VLE System',
        smtp_reply_to_email VARCHAR(255),
        smtp_reply_to_name VARCHAR(255),
        is_active BOOLEAN DEFAULT 1,
        enable_email_notifications BOOLEAN DEFAULT 1,
        test_email_sent BOOLEAN DEFAULT 0,
        last_test_date TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if ($conn->query($sql)) {
        $table_exists = true;
        $message = "✓ smtp_settings table created successfully!";
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
    <title>Setup SMTP Settings Table</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .icon-large {
            font-size: 48px;
            margin-bottom: 20px;
        }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .info { color: #007bff; }
    </style>
</head>
<body>
    <div class="setup-card text-center">
        <div class="icon-large <?php echo $error ? 'error' : ($message ? 'success' : 'info'); ?>">
            <?php if ($error): ?>
                <i class="bi bi-x-circle-fill"></i>
            <?php elseif ($table_exists): ?>
                <i class="bi bi-check-circle-fill"></i>
            <?php else: ?>
                <i class="bi bi-envelope-gear-fill"></i>
            <?php endif; ?>
        </div>
        
        <h2 class="mb-4">SMTP Settings Setup</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <h5>Table Structure</h5>
            <table class="table table-sm table-bordered text-start mt-3">
                <thead class="table-light">
                    <tr><th>Column</th><th>Type</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td>setting_id</td><td>INT</td><td>Primary key</td></tr>
                    <tr><td>smtp_host</td><td>VARCHAR(255)</td><td>SMTP server hostname</td></tr>
                    <tr><td>smtp_port</td><td>INT</td><td>SMTP port (587, 465, 25)</td></tr>
                    <tr><td>smtp_username</td><td>VARCHAR(255)</td><td>SMTP auth username/email</td></tr>
                    <tr><td>smtp_password</td><td>VARCHAR(255)</td><td>SMTP auth password/app key</td></tr>
                    <tr><td>smtp_encryption</td><td>ENUM</td><td>tls, ssl, or none</td></tr>
                    <tr><td>smtp_from_email</td><td>VARCHAR(255)</td><td>From email address</td></tr>
                    <tr><td>smtp_from_name</td><td>VARCHAR(255)</td><td>From display name</td></tr>
                    <tr><td>smtp_reply_to_email</td><td>VARCHAR(255)</td><td>Reply-to email (optional)</td></tr>
                    <tr><td>smtp_reply_to_name</td><td>VARCHAR(255)</td><td>Reply-to name (optional)</td></tr>
                    <tr><td>is_active</td><td>BOOLEAN</td><td>Whether config is active</td></tr>
                    <tr><td>enable_email_notifications</td><td>BOOLEAN</td><td>Enable/disable all emails</td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="mt-4 d-flex gap-2 justify-content-center flex-wrap">
            <?php if ($table_exists && $is_staff): ?>
                <a href="admin/smtp_settings.php" class="btn btn-primary">
                    <i class="bi bi-gear me-2"></i>Configure SMTP Settings
                </a>
            <?php elseif ($is_staff): ?>
                <a href="setup_smtp_settings.php" class="btn btn-warning">
                    <i class="bi bi-arrow-clockwise me-2"></i>Retry Setup
                </a>
            <?php endif; ?>
            
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house me-2"></i>Back to Home
            </a>
            
            <?php if ($is_staff): ?>
                <a href="admin/dashboard.php" class="btn btn-outline-primary">
                    <i class="bi bi-speedometer2 me-2"></i>Admin Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
