<?php
/**
 * Reset Admin Password
 * Temporary script - delete after use
 * URL: https://vle-exploitsonline.ct.ws/reset_admin_pw.php
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$new_password = '3xp10!ts';
$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE username = 'admin'");
$stmt->bind_param("s", $hash);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo "✅ Admin password reset successfully. You can now login with username: admin / password: 3xp10!ts";
} else {
    // Check if user exists
    $check = $conn->query("SELECT user_id, username, role, is_active FROM users WHERE username = 'admin'");
    if ($check && $check->num_rows > 0) {
        $user = $check->fetch_assoc();
        echo "User found (ID: {$user['user_id']}, role: {$user['role']}, active: {$user['is_active']}) but update had no effect. Trying direct update...<br>";
        $conn->query("UPDATE users SET password_hash = '$hash' WHERE username = 'admin'");
        echo "Done. Try logging in now.";
    } else {
        echo "❌ No user with username 'admin' found. Creating one...<br>";
        $stmt2 = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES ('admin', 'admin@exploits.edu', ?, 'admin', 1)");
        $stmt2->bind_param("s", $hash);
        if ($stmt2->execute()) {
            echo "✅ Admin user created. Login with username: admin / password: 3xp10!ts";
        } else {
            echo "❌ Failed to create admin: " . $conn->error;
        }
    }
}

echo "<br><br><a href='login.php'>Go to Login</a>";
echo "<br><br><strong style='color:red;'>⚠️ DELETE THIS FILE AFTER USE!</strong>";
?>
