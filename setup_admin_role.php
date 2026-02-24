<?php
/**
 * Setup Admin Role
 * Adds 'admin' to the users.role enum and creates a default admin user.
 * Run this once via browser: http://localhost/vle-eumw/setup_admin_role.php
 */
require_once 'includes/config.php';
$conn = getDbConnection();

echo "<h2>Setting up Admin Role</h2>";

// Step 1: ALTER the role enum to include 'admin'
echo "<h3>Step 1: Adding 'admin' to users.role enum...</h3>";
$alter_sql = "ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','staff','hod','dean','finance','admin') NOT NULL";
if ($conn->query($alter_sql)) {
    echo "<p style='color:green;'>✅ 'admin' role added to users table enum successfully.</p>";
} else {
    echo "<p style='color:red;'>❌ Error altering table: " . $conn->error . "</p>";
}

// Verify the change
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($row = $result->fetch_assoc()) {
    echo "<p><strong>Current role column type:</strong> " . $row['Type'] . "</p>";
}

// Step 2: Check if an admin user already exists
echo "<h3>Step 2: Creating default admin user...</h3>";
$check = $conn->prepare("SELECT user_id, username, role FROM users WHERE role = 'admin'");
$check->execute();
$existing = $check->get_result()->fetch_assoc();

if ($existing) {
    echo "<p style='color:blue;'>ℹ️ Admin user already exists: <strong>" . htmlspecialchars($existing['username']) . "</strong> (user_id: " . $existing['user_id'] . ")</p>";
} else {
    // Create admin user
    $admin_username = 'admin';
    $admin_email = 'admin@university.edu';
    $admin_password = password_hash('Admin@2026', PASSWORD_DEFAULT);
    
    // Check if 'admin' username already exists with a different role
    $check2 = $conn->prepare("SELECT user_id, username, role FROM users WHERE username = ?");
    $check2->bind_param("s", $admin_username);
    $check2->execute();
    $existing_user = $check2->get_result()->fetch_assoc();
    
    if ($existing_user) {
        // Update existing 'admin' user to admin role
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?");
        $stmt->bind_param("i", $existing_user['user_id']);
        if ($stmt->execute()) {
            echo "<p style='color:green;'>✅ Existing user '<strong>admin</strong>' (user_id: " . $existing_user['user_id'] . ") updated from '" . $existing_user['role'] . "' to 'admin' role.</p>";
        } else {
            echo "<p style='color:red;'>❌ Error updating user: " . $conn->error . "</p>";
        }
    } else {
        // Insert new admin user
        $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, 'admin', 1)");
        $stmt->bind_param("sss", $admin_username, $admin_email, $admin_password);
        if ($stmt->execute()) {
            echo "<p style='color:green;'>✅ Admin user created successfully.</p>";
        } else {
            echo "<p style='color:red;'>❌ Error creating admin user: " . $conn->error . "</p>";
        }
    }
}

// Step 3: Show all admin-capable users
echo "<h3>Step 3: Current admin users</h3>";
$result = $conn->query("SELECT user_id, username, email, role, is_active, last_login FROM users WHERE role = 'admin' ORDER BY user_id");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>Last Login</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['user_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td><strong>" . $row['role'] . "</strong></td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td>" . ($row['last_login'] ?: 'Never') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No admin users found.</p>";
}

echo "<hr>";
echo "<h3>Login Credentials</h3>";
echo "<p><strong>Username:</strong> admin<br><strong>Password:</strong> Admin@2026</p>";
echo "<p><a href='login.php'>Go to Login Page</a></p>";
?>
