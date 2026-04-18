<?php
/**
 * Database Sync Tool - Generate SQL to sync local database to production
 * Generates INSERT IGNORE statements that won't overwrite existing data
 * 
 * Usage: http://localhost/vle-eumw/sync_database.php
 * 
 * NOTE: InfinityFree blocks external MySQL connections.
 * This tool generates SQL scripts to import via phpMyAdmin.
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
$db_config = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'university_portal'
];

// Tables to sync (in dependency order)
$tables_to_sync = [
    // Core tables first
    'departments',
    'programs',
    'faculties',
    'lecturers',
    'students',
    'users',
    
    // Dependent tables
    'vle_courses',
    'course_programs',
    'semester_courses',
    'vle_enrollments',
    'course_registration_requests',
    
    // Content tables
    'announcements',
    'assignments',
    'assignment_submissions',
    'course_content',
    'course_materials',
    
    // Finance tables
    'fee_structures',
    'fee_categories',
    'payment_transactions',
    'finance_users',
    
    // System tables
    'messages',
    'notifications',
    'login_attempts',
    'live_sessions',
];

// Options
$sync_schema = isset($_GET['schema']) ? $_GET['schema'] === '1' : true;
$sync_data = isset($_GET['data']) ? $_GET['data'] === '1' : true;
$download = isset($_GET['download']) && $_GET['download'] === '1';
$selected_tables = isset($_GET['tables']) ? (array)$_GET['tables'] : [];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Sync Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-size: 11px; max-height: 500px; overflow-y: auto; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid">
    <h1 class="mb-4">🔄 Database Sync Tool</h1>
    
    <div class="alert alert-info">
        <strong>Note:</strong> InfinityFree blocks external MySQL connections. This tool generates SQL that you import via phpMyAdmin.
        <br>Uses <code>INSERT IGNORE</code> - safe to run multiple times without duplicating data.
    </div>

<?php
try {
    $conn = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    // Get all tables from database
    $all_tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $all_tables[] = $row[0];
    }

    // Use all tables if none specified in predefined list
    if (empty($tables_to_sync)) {
        $tables_to_sync = $all_tables;
    }

    // Filter to only existing tables
    $tables_to_sync = array_intersect($tables_to_sync, $all_tables);
    // Add any tables not in the predefined list
    $tables_to_sync = array_unique(array_merge($tables_to_sync, $all_tables));

    // Show table selection form
    if (empty($selected_tables) && !$download):
    ?>
    <div class="card mb-4">
        <div class="card-header">Select Tables to Sync</div>
        <div class="card-body">
            <form method="GET">
                <div class="row mb-3">
                    <div class="col-auto">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="schema" value="1" id="schema" <?= $sync_schema ? 'checked' : '' ?>>
                            <label class="form-check-label" for="schema">Include Schema (CREATE TABLE IF NOT EXISTS)</label>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="data" value="1" id="data" <?= $sync_data ? 'checked' : '' ?>>
                            <label class="form-check-label" for="data">Include Data (INSERT IGNORE)</label>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><strong>Tables:</strong></label>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAll(true)">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAll(false)">Deselect All</button>
                    </div>
                    <div class="row">
                        <?php foreach ($tables_to_sync as $table): 
                            $count = $conn->query("SELECT COUNT(*) as c FROM `$table`")->fetch_assoc()['c'];
                        ?>
                            <div class="col-md-3 col-sm-4">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input table-check" name="tables[]" value="<?= $table ?>" id="t_<?= $table ?>" checked>
                                    <label class="form-check-label" for="t_<?= $table ?>">
                                        <code><?= $table ?></code> <small class="text-muted">(<?= $count ?>)</small>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Generate SQL</button>
                <button type="submit" name="download" value="1" class="btn btn-success">Download SQL File</button>
            </form>
        </div>
    </div>
    
    <script>
    function selectAll(checked) {
        document.querySelectorAll('.table-check').forEach(cb => cb.checked = checked);
    }
    </script>
    <?php
    else:
        // Generate SQL
        if (empty($selected_tables)) {
            $selected_tables = $tables_to_sync;
        }
        
        $sql_output = "";
        $sql_output .= "-- VLE Database Sync SQL\n";
        $sql_output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_output .= "-- Source: {$db_config['name']}\n";
        $sql_output .= "-- Tables: " . count($selected_tables) . "\n";
        $sql_output .= "-- SAFE: Uses IF NOT EXISTS and INSERT IGNORE\n\n";
        
        $sql_output .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql_output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        $total_rows = 0;

        foreach ($selected_tables as $table) {
            if (!in_array($table, $all_tables)) continue;
            
            $sql_output .= "-- --------------------------------------------------------\n";
            $sql_output .= "-- Table: `$table`\n";
            $sql_output .= "-- --------------------------------------------------------\n\n";

            if ($sync_schema) {
                $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                if ($row = $create_result->fetch_assoc()) {
                    $create_sql = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $row['Create Table']);
                    $sql_output .= $create_sql . ";\n\n";
                }
            }

            if ($sync_data) {
                $data_result = $conn->query("SELECT * FROM `$table`");
                if ($data_result && $data_result->num_rows > 0) {
                    $columns = [];
                    $fields = $data_result->fetch_fields();
                    foreach ($fields as $field) {
                        $columns[] = "`{$field->name}`";
                    }
                    
                    $sql_output .= "-- Data for `$table` ({$data_result->num_rows} rows)\n";
                    $total_rows += $data_result->num_rows;
                    
                    // Batch inserts
                    $batch_size = 50;
                    $batch = [];
                    
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
                            $sql_output .= "INSERT IGNORE INTO `$table` (" . implode(", ", $columns) . ") VALUES\n";
                            $sql_output .= implode(",\n", $batch) . ";\n\n";
                            $batch = [];
                        }
                    }
                    
                    if (!empty($batch)) {
                        $sql_output .= "INSERT IGNORE INTO `$table` (" . implode(", ", $columns) . ") VALUES\n";
                        $sql_output .= implode(",\n", $batch) . ";\n\n";
                    }
                }
            }
        }

        $sql_output .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        $conn->close();

        if ($download) {
            $filename = 'vle_database_sync_' . date('Y-m-d_His') . '.sql';
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sql_output));
            echo $sql_output;
            exit;
        }

        // Display preview
        ?>
        <div class="card">
            <div class="card-header">
                Generated SQL 
                <span class="badge bg-primary"><?= count($selected_tables) ?> tables</span>
                <span class="badge bg-info"><?= number_format($total_rows) ?> rows</span>
                <span class="badge bg-secondary"><?= number_format(strlen($sql_output)) ?> bytes</span>
                <a href="?<?= http_build_query(array_merge($_GET, ['download' => '1'])) ?>" class="btn btn-sm btn-success float-end">Download</a>
            </div>
            <div class="card-body">
                <pre><?= htmlspecialchars($sql_output) ?></pre>
            </div>
        </div>
        
        <div class="alert alert-success mt-4">
            <strong>Next Steps:</strong>
            <ol class="mb-0">
                <li>Download the SQL file</li>
                <li>Go to InfinityFree Control Panel → MySQL Databases</li>
                <li>Click "phpMyAdmin" for <code>if0_40881536_university_portal</code></li>
                <li>Go to "Import" tab</li>
                <li>Choose the downloaded .sql file and click "Go"</li>
            </ol>
        </div>
        <?php
    endif;

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

</div>
</body>
</html>
