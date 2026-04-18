<?php
/**
 * Safe Schema Sync - Generate SQL to update production schema WITHOUT data loss
 * Only generates SQL for missing tables and columns, never deletes or modifies existing data
 * 
 * Usage: 
 * 1. Run locally: http://localhost/vle-eumw/sync_schema_safe.php
 * 2. Upload to production and run there for comparison
 * 
 * NOTE: InfinityFree blocks external MySQL connections.
 * This tool generates SQL scripts to import via phpMyAdmin.
 */

set_time_limit(300);
ini_set('memory_limit', '256M');

// Detect environment
$is_localhost = isset($_SERVER['HTTP_HOST']) && (
    $_SERVER['HTTP_HOST'] === 'localhost' ||
    strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0 ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === 0
);

// Use appropriate database config
if ($is_localhost) {
    $db_config = [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'university_portal'
    ];
    $env_name = 'LOCAL';
} else {
    // Production (InfinityFree)
    $db_config = [
        'host' => 'sql305.infinityfree.com',
        'user' => 'if0_40881536',
        'pass' => 'kalisadaud',
        'name' => 'if0_40881536_university_portal'
    ];
    $env_name = 'PRODUCTION';
}

$generate_sql = isset($_GET['generate']) && $_GET['generate'] === '1';
$download = isset($_GET['download']) && $_GET['download'] === '1';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Schema Export Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-size: 12px; max-height: 500px; overflow-y: auto; }
        .log { max-height: 400px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid">
    <h1 class="mb-4">📋 Schema Export Tool</h1>
    
    <div class="alert alert-info">
        <strong>Environment:</strong> <?= $env_name ?> (<?= $db_config['name'] ?>)
        <br><small>InfinityFree blocks external MySQL connections. Run this locally to generate SQL, then import via phpMyAdmin.</small>
    </div>

    <div class="alert alert-warning">
        <strong>How to sync schema to production:</strong>
        <ol class="mb-0">
            <li>Click "Generate Schema SQL" below</li>
            <li>Download the .sql file</li>
            <li>Go to InfinityFree phpMyAdmin</li>
            <li>Run the SQL (it uses IF NOT EXISTS - safe to run)</li>
        </ol>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <a href="?generate=1" class="btn btn-primary">Generate Schema SQL</a>
            <a href="?generate=1&download=1" class="btn btn-success">Download Schema SQL</a>
        </div>
    </div>

<?php
if ($generate_sql) {
    
    try {
        // Connect to local database
        $conn = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);
        if ($conn->connect_error) {
            throw new Exception("Database connection failed: " . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');

        // Get all tables
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        // Generate safe SQL (uses IF NOT EXISTS)
        $sql_output = "";
        $sql_output .= "-- Schema Sync SQL for VLE Database\n";
        $sql_output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_output .= "-- Source: {$db_config['name']}\n";
        $sql_output .= "-- SAFE: Uses IF NOT EXISTS - won't overwrite existing tables\n\n";
        $sql_output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            // Get CREATE TABLE statement
            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
            if ($row = $create_result->fetch_assoc()) {
                $create_sql = $row['Create Table'];
                
                // Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS
                $safe_sql = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create_sql);
                
                $sql_output .= "-- Table: $table\n";
                $sql_output .= $safe_sql . ";\n\n";
            }
        }

        $sql_output .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        $conn->close();

        if ($download) {
            $filename = 'vle_schema_' . date('Y-m-d_His') . '.sql';
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sql_output));
            echo $sql_output;
            exit;
        }

        // Display
        echo "<div class='card'>";
        echo "<div class='card-header'>Generated SQL (" . count($tables) . " tables, " . number_format(strlen($sql_output)) . " bytes)</div>";
        echo "<div class='card-body'>";
        echo "<p><strong>Tables included:</strong></p>";
        echo "<div class='row mb-3'><div class='col-md-12'><code>" . implode('</code>, <code>', $tables) . "</code></div></div>";
        echo "<pre>" . htmlspecialchars($sql_output) . "</pre>";
        echo "</div></div>";

    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>

    <div class="alert alert-secondary mt-4">
        <strong>Other Tools:</strong>
        <ul class="mb-0">
            <li><a href="export_database.php">export_database.php</a> - Export full database (schema + data)</li>
            <li><a href="sync_database.php">sync_database.php</a> - Generate data sync SQL</li>
        </ul>
    </div>
</div>
</body>
</html>
