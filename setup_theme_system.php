<?php
/**
 * Setup Theme Preference Column
 * Adds theme_preference column to users table
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Setup Theme System</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='container py-4'>
<h1>Setup Theme System</h1>
<hr>";

// Check if column exists
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_preference'");

if ($result->num_rows > 0) {
    echo "<div class='alert alert-info'>✓ theme_preference column already exists in users table.</div>";
} else {
    // Add the column
    $sql = "ALTER TABLE users ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'navy'";
    
    if ($conn->query($sql)) {
        echo "<div class='alert alert-success'>✓ theme_preference column added successfully!</div>";
    } else {
        echo "<div class='alert alert-danger'>✗ Failed to add column: " . $conn->error . "</div>";
    }
}

// Show available themes
echo "<h3>Available Themes</h3>
<div class='row'>
    <div class='col-md-3'>
        <div class='card'>
            <div class='card-body text-center'>
                <div style='width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); margin: 0 auto 10px;'></div>
                <h5>Navy Blue</h5>
                <small class='text-muted'>Default Theme</small>
            </div>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card'>
            <div class='card-body text-center'>
                <div style='width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #047857 0%, #059669 100%); margin: 0 auto 10px;'></div>
                <h5>Emerald Green</h5>
                <small class='text-muted'>Nature Theme</small>
            </div>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card'>
            <div class='card-body text-center'>
                <div style='width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%); margin: 0 auto 10px;'></div>
                <h5>Royal Purple</h5>
                <small class='text-muted'>Elegant Theme</small>
            </div>
        </div>
    </div>
    <div class='col-md-3'>
        <div class='card'>
            <div class='card-body text-center'>
                <div style='width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #c2410c 0%, #ea580c 100%); margin: 0 auto 10px;'></div>
                <h5>Sunset Orange</h5>
                <small class='text-muted'>Warm Theme</small>
            </div>
        </div>
    </div>
</div>

<hr>
<p class='text-success'><strong>Setup Complete!</strong> Users can now change themes from their profile dropdown menu.</p>
<p><a href='admin/dashboard.php' class='btn btn-primary'>Go to Admin Dashboard</a></p>
</body>
</html>";

?>
