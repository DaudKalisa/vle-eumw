<?php
/**
 * Database Backup Script
 * Creates a SQL dump of the university_portal database
 */

// Set execution time limit for large databases
set_time_limit(300);

// Handle CLI execution - set dummy HTTP_HOST if not present
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/vle-eumw/backup_database.php';
}

// Configuration
require_once 'includes/config.php';

// Create backups directory if it doesn't exist
$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Generate backup filename with timestamp
$timestamp = date('Y-m-d_H-i-s');
$backup_file = $backup_dir . '/university_portal_' . $timestamp . '.sql';

// Get database connection
$conn = getDbConnection();

// Start output buffering
$output = "";

// Add header comments
$output .= "-- Database Backup for VLE System\n";
$output .= "-- Database: " . DB_NAME . "\n";
$output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$output .= "-- Version: " . (defined('VLE_VERSION') ? VLE_VERSION : '5.0') . "\n";
$output .= "-- ==========================================\n\n";

$output .= "SET FOREIGN_KEY_CHECKS=0;\n";
$output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
$output .= "SET time_zone = \"+00:00\";\n\n";

// Get all tables
$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

echo "<h2>Database Backup in Progress...</h2>";
echo "<pre>";

$table_count = 0;
$total_rows = 0;

foreach ($tables as $table) {
    echo "Backing up table: $table... ";
    
    // Get CREATE TABLE statement
    $result = $conn->query("SHOW CREATE TABLE `$table`");
    $row = $result->fetch_row();
    
    $output .= "-- ==========================================\n";
    $output .= "-- Table structure for `$table`\n";
    $output .= "-- ==========================================\n\n";
    $output .= "DROP TABLE IF EXISTS `$table`;\n";
    $output .= $row[1] . ";\n\n";
    
    // Get table data
    $result = $conn->query("SELECT * FROM `$table`");
    $num_fields = $result->field_count;
    $row_count = $result->num_rows;
    
    if ($row_count > 0) {
        $output .= "-- ==========================================\n";
        $output .= "-- Data for table `$table` ($row_count rows)\n";
        $output .= "-- ==========================================\n\n";
        
        // Get column names
        $fields_info = $result->fetch_fields();
        $column_names = [];
        foreach ($fields_info as $field) {
            $column_names[] = "`" . $field->name . "`";
        }
        
        // Reset result pointer
        $result->data_seek(0);
        
        while ($row = $result->fetch_row()) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = "NULL";
                } else {
                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                }
            }
            $output .= "INSERT INTO `$table` (" . implode(", ", $column_names) . ") VALUES (" . implode(", ", $values) . ");\n";
            $total_rows++;
        }
        $output .= "\n";
    }
    
    echo "$row_count rows\n";
    $table_count++;
}

$output .= "SET FOREIGN_KEY_CHECKS=1;\n";
$output .= "\n-- End of backup\n";

// Write to file
$bytes_written = file_put_contents($backup_file, $output);

echo "\n==========================================\n";
echo "Backup Complete!\n";
echo "==========================================\n";
echo "Tables backed up: $table_count\n";
echo "Total rows: $total_rows\n";
echo "File size: " . number_format($bytes_written / 1024, 2) . " KB\n";
echo "Backup file: $backup_file\n";
echo "</pre>";

// Create download link
$relative_path = 'backups/university_portal_' . $timestamp . '.sql';
echo "<br><a href='$relative_path' download class='btn btn-success' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;'>";
echo "<i class='bi bi-download'></i> Download Backup File</a>";

echo "<br><br><a href='admin/dashboard.php' style='color: #007bff;'>← Back to Dashboard</a>";

?>
