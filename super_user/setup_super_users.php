<?php
/**
 * Setup Super Users Table
 * Creates the super_users table and default account
 * RUN THIS ONCE, then delete or restrict access
 */

require_once '../includes/config.php';

$conn = getDbConnection();
$messages = [];

// Create super_users table
$sql = "CREATE TABLE IF NOT EXISTS super_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    login_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    $messages[] = ['type' => 'success', 'text' => 'Table "super_users" created successfully.'];
} else {
    $messages[] = ['type' => 'danger', 'text' => 'Error creating table: ' . $conn->error];
}

// Check if default super user exists
$check = $conn->query("SELECT id FROM super_users WHERE username = 'superadmin'");
if ($check && $check->num_rows == 0) {
    // Create default super user
    // Default password: SuperAdmin@2024 (CHANGE THIS IMMEDIATELY!)
    $default_password = 'SuperAdmin@2024';
    $password_hash = password_hash($default_password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO super_users (username, password_hash, full_name, email) VALUES (?, ?, ?, ?)");
    $username = 'superadmin';
    $full_name = 'System Administrator';
    $email = 'admin@system.local';
    $stmt->bind_param('ssss', $username, $password_hash, $full_name, $email);
    
    if ($stmt->execute()) {
        $messages[] = ['type' => 'success', 'text' => 'Default super user created.'];
        $messages[] = ['type' => 'warning', 'text' => "Username: <strong>superadmin</strong><br>Password: <strong>$default_password</strong><br><em>CHANGE THIS PASSWORD IMMEDIATELY!</em>"];
    } else {
        $messages[] = ['type' => 'danger', 'text' => 'Error creating default user: ' . $stmt->error];
    }
    $stmt->close();
} else {
    $messages[] = ['type' => 'info', 'text' => 'Default super user already exists.'];
}

// Create login_attempts table for security
$sql2 = "CREATE TABLE IF NOT EXISTS super_user_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    ip_address VARCHAR(45),
    success TINYINT(1),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql2)) {
    $messages[] = ['type' => 'success', 'text' => 'Table "super_user_login_attempts" created successfully.'];
} else {
    $messages[] = ['type' => 'danger', 'text' => 'Error creating login attempts table: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Super Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; color: #eee; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .setup-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); max-width: 600px; width: 100%; }
    </style>
</head>
<body>
    <div class="setup-card rounded-3 p-4">
        <h3 class="text-center mb-4"><i class="bi bi-shield-lock text-danger me-2"></i>Super User Setup</h3>
        
        <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= $msg['text'] ?></div>
        <?php endforeach; ?>
        
        <hr class="border-secondary">
        
        <div class="alert alert-danger">
            <strong><i class="bi bi-exclamation-triangle me-1"></i>Security Notice:</strong><br>
            Delete this file (<code>setup_super_users.php</code>) after setup is complete!
        </div>
        
        <div class="text-center">
            <a href="login.php" class="btn btn-primary">Go to Super User Login</a>
        </div>
    </div>
</body>
</html>
