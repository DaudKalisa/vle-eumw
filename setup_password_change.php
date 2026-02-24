<?php
/**
 * Setup script to add must_change_password column to users table
 * This flag forces new users to change their password on first login
 */

// Handle CLI execution
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/vle-eumw/setup_password_change.php';
}

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting up Must Change Password Feature</h2>";
echo "<pre>";

// Check if column already exists
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");

if ($result->num_rows == 0) {
    // Add the column
    $sql = "ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 1 AFTER password_hash";
    
    if ($conn->query($sql)) {
        echo "✓ Added 'must_change_password' column to users table\n";
        
        // Set existing users to NOT require password change (they already have passwords set)
        $conn->query("UPDATE users SET must_change_password = 0");
        echo "✓ Set existing users to not require password change\n";
    } else {
        echo "✗ Error adding column: " . $conn->error . "\n";
    }
} else {
    echo "✓ Column 'must_change_password' already exists\n";
}

// Verify column
$result = $conn->query("DESCRIBE users must_change_password");
if ($result && $row = $result->fetch_assoc()) {
    echo "\nColumn details:\n";
    echo "  Field: " . $row['Field'] . "\n";
    echo "  Type: " . $row['Type'] . "\n";
    echo "  Default: " . $row['Default'] . "\n";
}

echo "\n==========================================\n";
echo "Setup Complete!\n";
echo "==========================================\n";
echo "\nNew users will now be required to change their password on first login.\n";
echo "</pre>";

echo "<br><a href='admin/dashboard.php' style='color: #007bff;'>← Back to Dashboard</a>";

?>
