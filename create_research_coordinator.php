<?php
/**
 * Create Sample Research Coordinator User
 * Run this once to set up a test research coordinator account
 */
require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Creating Sample Research Coordinator</h2>";

// Check if already exists
$check = $conn->query("SELECT user_id FROM users WHERE username = 'research_coord' OR email = 'coordinator@vle.eumw.edu'");
if ($check && $check->num_rows > 0) {
    echo "<p style='color:orange;'>⚠️ Sample research coordinator already exists. Skipping creation.</p>";
    
    // Show existing info
    $existing = $check->fetch_assoc();
    $uid = $existing['user_id'];
    $u = $conn->query("SELECT * FROM users WHERE user_id = $uid")->fetch_assoc();
    echo "<div style='background:#f0fdf4;padding:16px;border:1px solid #bbf7d0;border-radius:8px;margin:16px 0;font-family:monospace;'>";
    echo "<p><strong>User ID:</strong> {$u['user_id']}</p>";
    echo "<p><strong>Username:</strong> {$u['username']}</p>";
    echo "<p><strong>Email:</strong> {$u['email']}</p>";
    echo "<p><strong>Role:</strong> {$u['role']}</p>";
    echo "</div>";
    
    echo "<p><a href='login.php'>Go to Login</a></p>";
    exit;
}

$conn->begin_transaction();
try {
    // 1. Create user account
    $username = 'research_coord';
    $email = 'coordinator@vle.eumw.edu';
    $password = 'Coordinator@2026';
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $full_name = 'Dr. Sarah Mwale';
    $phone = '+265991234567';
    $department = 'Research & Postgraduate Studies';
    
    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, 'research_coordinator', 1)");
    $stmt->bind_param("sss", $username, $email, $hashed);
    $stmt->execute();
    $user_id = $conn->insert_id;
    echo "<p>✅ User account created (ID: $user_id)</p>";
    
    // 2. Create research_coordinators record
    $stmt = $conn->prepare("INSERT INTO research_coordinators (user_id, full_name, email, phone, department, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("issss", $user_id, $full_name, $email, $phone, $department);
    $stmt->execute();
    $coordinator_id = $conn->insert_id;
    echo "<p>✅ Research coordinator record created (ID: $coordinator_id)</p>";
    
    // 3. Link user to coordinator
    $conn->query("UPDATE users SET related_staff_id = $coordinator_id WHERE user_id = $user_id");
    echo "<p>✅ User linked to coordinator (related_staff_id: $coordinator_id)</p>";
    
    $conn->commit();
    
    echo "<hr>";
    echo "<div style='background:#f0fdf4;padding:20px;border:1px solid #bbf7d0;border-radius:8px;margin:16px 0;'>";
    echo "<h3 style='color:#065f46;margin-top:0;'>🎉 Sample Research Coordinator Created!</h3>";
    echo "<table style='border-collapse:collapse;width:100%;'>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Name:</td><td style='padding:6px 12px;'>$full_name</td></tr>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Username:</td><td style='padding:6px 12px;'><code>$username</code></td></tr>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Email:</td><td style='padding:6px 12px;'>$email</td></tr>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Password:</td><td style='padding:6px 12px;'><code>$password</code></td></tr>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Department:</td><td style='padding:6px 12px;'>$department</td></tr>";
    echo "<tr><td style='padding:6px 12px;font-weight:bold;'>Role:</td><td style='padding:6px 12px;'>research_coordinator</td></tr>";
    echo "</table>";
    echo "</div>";
    
    echo "<p><a href='login.php' style='display:inline-block;background:#7c3aed;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold;'>🔑 Login as Research Coordinator</a></p>";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
