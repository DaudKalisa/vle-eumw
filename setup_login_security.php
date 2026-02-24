<?php
// setup_login_security.php - Create tables for login security and tracking

require_once 'includes/config.php';

$conn = getDbConnection();
$messages = [];

// Create login_attempts table for tracking failed logins and account locking
$sql1 = "CREATE TABLE IF NOT EXISTS login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username_email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) DEFAULT 0,
    INDEX idx_username_email (username_email),
    INDEX idx_ip_address (ip_address),
    INDEX idx_attempt_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql1)) {
    $messages[] = ['success', 'Login attempts table created successfully'];
} else {
    $messages[] = ['error', 'Failed to create login_attempts table: ' . $conn->error];
}

// Create login_history table for tracking successful logins
$sql2 = "CREATE TABLE IF NOT EXISTS login_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    device_info VARCHAR(255),
    location VARCHAR(255),
    login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    logout_time DATETIME NULL,
    session_duration INT DEFAULT 0,
    is_suspicious TINYINT(1) DEFAULT 0,
    INDEX idx_user_id (user_id),
    INDEX idx_login_time (login_time),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql2)) {
    $messages[] = ['success', 'Login history table created successfully'];
} else {
    $messages[] = ['error', 'Failed to create login_history table: ' . $conn->error];
}

// Create account_locks table
$sql3 = "CREATE TABLE IF NOT EXISTS account_locks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    username_email VARCHAR(255) NOT NULL,
    lock_reason VARCHAR(255),
    locked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    unlocked_at DATETIME NULL,
    unlock_scheduled DATETIME NULL,
    locked_by_admin TINYINT(1) DEFAULT 0,
    INDEX idx_username_email (username_email),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql3)) {
    $messages[] = ['success', 'Account locks table created successfully'];
} else {
    $messages[] = ['error', 'Failed to create account_locks table: ' . $conn->error];
}

// Add columns to users table for lock tracking
$columns_to_add = [
    ['failed_login_attempts', 'INT DEFAULT 0'],
    ['last_failed_login', 'DATETIME NULL'],
    ['account_locked_until', 'DATETIME NULL'],
    ['last_login_at', 'DATETIME NULL'],
    ['last_login_ip', 'VARCHAR(45) NULL']
];

foreach ($columns_to_add as $column) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE '{$column[0]}'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE users ADD COLUMN {$column[0]} {$column[1]}";
        if ($conn->query($sql)) {
            $messages[] = ['success', "Added column {$column[0]} to users table"];
        } else {
            $messages[] = ['error', "Failed to add {$column[0]} column: " . $conn->error];
        }
    } else {
        $messages[] = ['info', "Column {$column[0]} already exists"];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Login Security</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Login Security Setup</h4>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-3">Setup Results:</h5>
                        <?php foreach ($messages as $msg): ?>
                            <div class="alert alert-<?= $msg[0] === 'success' ? 'success' : ($msg[0] === 'error' ? 'danger' : 'info') ?> py-2">
                                <?php if ($msg[0] === 'success'): ?>
                                    <i class="bi bi-check-circle me-2"></i>
                                <?php elseif ($msg[0] === 'error'): ?>
                                    <i class="bi bi-x-circle me-2"></i>
                                <?php else: ?>
                                    <i class="bi bi-info-circle me-2"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($msg[1]) ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="mt-4">
                            <h5>Security Features Enabled:</h5>
                            <ul class="list-group">
                                <li class="list-group-item">
                                    <i class="bi bi-shield-check text-success me-2"></i>
                                    <strong>Login Attempt Tracking</strong> - Records all login attempts
                                </li>
                                <li class="list-group-item">
                                    <i class="bi bi-shield-check text-success me-2"></i>
                                    <strong>Account Locking</strong> - Locks account after 5 failed attempts
                                </li>
                                <li class="list-group-item">
                                    <i class="bi bi-shield-check text-success me-2"></i>
                                    <strong>Login History</strong> - Keeps track of login sessions
                                </li>
                                <li class="list-group-item">
                                    <i class="bi bi-shield-check text-success me-2"></i>
                                    <strong>Email Alerts</strong> - Sends security notifications
                                </li>
                            </ul>
                        </div>
                        
                        <div class="mt-4">
                            <a href="admin/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                            <a href="login.php" class="btn btn-outline-secondary">Test Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
