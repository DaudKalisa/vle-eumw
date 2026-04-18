<?php
/**
 * Database Export Tool - Generate SQL file for production import
 * Creates a SQL file that can be uploaded and imported to InfinityFree via phpMyAdmin
 * 
 * Usage: http://localhost/vle-eumw/export_database.php
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

// Only allow from localhost
if (!isset($_SERVER['HTTP_HOST']) || (
    $_SERVER['HTTP_HOST'] !== 'localhost' &&
    strpos($_SERVER['HTTP_HOST'], 'localhost:') !== 0 &&
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== 0
)) {
    die('Access denied. Run from localhost only.');
}

// Local database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'university_portal';

// Production database name (for SET statements)
$prod_db_name = 'if0_40881536_university_portal';

// Export options
$export_schema = isset($_GET['schema']) ? $_GET['schema'] === '1' : true;
$export_data = isset($_GET['data']) ? $_GET['data'] === '1' : true;
$download = isset($_GET['download']) && $_GET['download'] === '1';

// Tables to exclude (if any)
$exclude_tables = [];

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    // Get all tables
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        if (!in_array($row[0], $exclude_tables)) {
            $tables[] = $row[0];
        }
    }

    // Start building SQL
    $sql_output = "";
    $sql_output .= "-- VLE Database Export\n";
    $sql_output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_output .= "-- Source: $db_name (localhost)\n";
    $sql_output .= "-- Target: $prod_db_name\n\n";
    
    $sql_output .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql_output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql_output .= "SET AUTOCOMMIT = 0;\n";
    $sql_output .= "START TRANSACTION;\n\n";

    foreach ($tables as $table) {
        $sql_output .= "-- --------------------------------------------------------\n";
        $sql_output .= "-- Table: `$table`\n";
        $sql_output .= "-- --------------------------------------------------------\n\n";

        if ($export_schema) {
            // Get CREATE TABLE statement
            $create_result = $conn->query("SHOW CREATE TABLE `$table`");
            if ($create_result && $row = $create_result->fetch_assoc()) {
                // Option to drop existing table first
                $sql_output .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_output .= $row['Create Table'] . ";\n\n";
            }
        }

        if ($export_data) {
            // Get data
            $data_result = $conn->query("SELECT * FROM `$table`");
            if ($data_result && $data_result->num_rows > 0) {
                $columns = [];
                $fields = $data_result->fetch_fields();
                foreach ($fields as $field) {
                    $columns[] = "`{$field->name}`";
                }
                
                $sql_output .= "-- Data for table `$table`\n";
                
                // Batch inserts (100 rows per INSERT for efficiency)
                $batch_size = 100;
                $batch = [];
                
                $data_result->data_seek(0);
                while ($row = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    $batch[] = "(" . implode(", ", $values) . ")";
                    
                    if (count($batch) >= $batch_size) {
                        $sql_output .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES\n";
                        $sql_output .= implode(",\n", $batch) . ";\n\n";
                        $batch = [];
                    }
                }
                
                // Remaining rows
                if (!empty($batch)) {
                    $sql_output .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES\n";
                    $sql_output .= implode(",\n", $batch) . ";\n\n";
                }
            }
        }
    }

    $sql_output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $sql_output .= "COMMIT;\n";

    $conn->close();

    // Output or download
    if ($download) {
        $filename = 'vle_database_' . date('Y-m-d_His') . '.sql';
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql_output));
        echo $sql_output;
        exit;
    }

    // Display page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Database Export Tool</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-4">
        <div class="container">
            <h1 class="mb-4">Database Export Tool</h1>
            
            <div class="card mb-4">
                <div class="card-header">Export Options</div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-auto">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="schema" value="1" id="schema" <?= $export_schema ? 'checked' : '' ?>>
                                <label class="form-check-label" for="schema">Include Schema (CREATE TABLE)</label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="data" value="1" id="data" <?= $export_data ? 'checked' : '' ?>>
                                <label class="form-check-label" for="data">Include Data (INSERT)</label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Preview</button>
                            <button type="submit" name="download" value="1" class="btn btn-success">Download SQL File</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    Tables Found: <?= count($tables) ?>
                    <span class="badge bg-secondary"><?= number_format(strlen($sql_output)) ?> bytes</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <strong>Tables:</strong>
                            <ul class="list-unstyled small">
                                <?php foreach ($tables as $t): ?>
                                    <li><code><?= htmlspecialchars($t) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="col-md-9">
                            <strong>SQL Preview (first 5000 chars):</strong>
                            <pre style="max-height: 500px; overflow-y: auto; font-size: 11px;"><?= htmlspecialchars(substr($sql_output, 0, 5000)) ?>...</pre>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <strong>How to import to InfinityFree:</strong>
                <ol class="mb-0">
                    <li>Click "Download SQL File" above</li>
                    <li>Go to InfinityFree Control Panel → MySQL Databases</li>
                    <li>Click "phpMyAdmin" for your database</li>
                    <li>Go to "Import" tab</li>
                    <li>Choose the downloaded .sql file</li>
                    <li>Click "Go" to import</li>
                </ol>
            </div>
        </div>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
