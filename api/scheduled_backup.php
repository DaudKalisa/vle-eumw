<?php
/**
 * api/scheduled_backup.php - Scheduled Backup Runner
 * 
 * Can be called by:
 * 1. Cron job: php /path/to/api/scheduled_backup.php
 * 2. URL with secret key: https://domain.com/api/scheduled_backup.php?key=YOUR_SECRET
 * 3. Auto-triggered on admin page load (checks if backup is due)
 * 
 * Supports:
 * - Full backup: all tables
 * - Custom (incremental): only tables with changes since last backup
 * - Retention: keeps latest N backups, deletes older ones
 */

// Allow CLI or authenticated URL access
$is_cli = (php_sapi_name() === 'cli');
$_is_direct_call = !defined('INTERNAL_BACKUP_CALL');

if ($_is_direct_call) {
    if (!$is_cli) {
        require_once __DIR__ . '/../includes/config.php';
    } else {
        require_once __DIR__ . '/../includes/config.php';
    }
    
    $backup_dir = __DIR__ . '/../backups/';
    $config_file = __DIR__ . '/../backups/schedule_config.json';
    
    // Create backups directory
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Validate access
    if (!$is_cli) {
        $config = loadScheduleConfig($config_file);
        $provided_key = $_GET['key'] ?? '';
        
        if (empty($config['secret_key']) || $provided_key !== $config['secret_key']) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }
}

/**
 * Load schedule configuration
 */
function loadScheduleConfig($config_file) {
    $defaults = [
        'enabled' => false,
        'backup_type' => 'full', // 'full' or 'custom'
        'frequency' => 'daily', // 'every_6h', 'every_12h', 'daily', 'weekly'
        'retention_count' => 5,
        'custom_tables' => [], // for custom backup type
        'last_backup' => null,
        'last_backup_file' => null,
        'secret_key' => '',
        'created_at' => null,
        'total_backups_run' => 0,
    ];
    
    if (file_exists($config_file)) {
        $data = json_decode(file_get_contents($config_file), true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    return $defaults;
}

/**
 * Save schedule configuration
 */
function saveScheduleConfig($config_file, $config) {
    $dir = dirname($config_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
}

/**
 * Check if a backup is due based on frequency
 */
function isBackupDue($config) {
    if (!$config['enabled']) return false;
    if (empty($config['last_backup'])) return true;
    
    $last = strtotime($config['last_backup']);
    $now = time();
    $diff = $now - $last;
    
    switch ($config['frequency']) {
        case 'every_6h':  return $diff >= 6 * 3600;
        case 'every_12h': return $diff >= 12 * 3600;
        case 'daily':     return $diff >= 24 * 3600;
        case 'weekly':    return $diff >= 7 * 24 * 3600;
        default:          return $diff >= 24 * 3600;
    }
}

/**
 * Get table checksums for incremental detection
 */
function getTableChecksums($conn) {
    $checksums = [];
    $result = $conn->query("SHOW TABLE STATUS");
    while ($row = $result->fetch_assoc()) {
        $checksums[$row['Name']] = [
            'rows' => $row['Rows'],
            'update_time' => $row['Update_time'],
            'data_length' => $row['Data_length'],
        ];
    }
    return $checksums;
}

/**
 * Run the backup
 */
function runScheduledBackup($conn, $config, $backup_dir, $config_file) {
    $backup_type = $config['backup_type'];
    
    // Get all tables
    $all_tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $all_tables[] = $row[0];
    }
    
    // Determine which tables to backup
    $tables_to_backup = [];
    
    if ($backup_type === 'full') {
        $tables_to_backup = $all_tables;
        $prefix = 'scheduled_full';
    } else {
        // Custom/incremental - only tables with changes
        $checksum_file = $backup_dir . 'table_checksums.json';
        $old_checksums = [];
        if (file_exists($checksum_file)) {
            $old_checksums = json_decode(file_get_contents($checksum_file), true) ?: [];
        }
        
        $current_checksums = getTableChecksums($conn);
        
        foreach ($all_tables as $table) {
            $current = $current_checksums[$table] ?? null;
            $old = $old_checksums[$table] ?? null;
            
            // Include if: no old data, rows changed, data length changed, or update time changed
            if (!$old || !$current ||
                $current['rows'] != ($old['rows'] ?? 0) ||
                $current['data_length'] != ($old['data_length'] ?? 0) ||
                $current['update_time'] != ($old['update_time'] ?? '')) {
                $tables_to_backup[] = $table;
            }
        }
        
        // Save current checksums for next comparison
        file_put_contents($checksum_file, json_encode($current_checksums, JSON_PRETTY_PRINT));
        
        // If no changes detected, still note this
        if (empty($tables_to_backup)) {
            $config['last_backup'] = date('Y-m-d H:i:s');
            $config['last_backup_file'] = 'No changes detected';
            saveScheduleConfig($config_file, $config);
            return ['success' => true, 'message' => 'No changes detected since last backup', 'tables' => 0];
        }
        
        $prefix = 'scheduled_incremental';
    }
    
    // Create backup
    $filename = $prefix . '_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backup_dir . $filename;
    
    $sql_content = "-- VLE Scheduled Backup ($backup_type)\n";
    $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_content .= "-- Database: " . DB_NAME . "\n";
    $sql_content .= "-- Tables: " . count($tables_to_backup) . "\n";
    $sql_content .= "-- Type: $backup_type\n\n";
    $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql_content .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
    
    foreach ($tables_to_backup as $table) {
        $create_result = $conn->query("SHOW CREATE TABLE `$table`");
        if ($row = $create_result->fetch_assoc()) {
            $sql_content .= "-- Table: $table\n";
            $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql_content .= $row['Create Table'] . ";\n\n";
        }
        
        $data_result = $conn->query("SELECT * FROM `$table`");
        if ($data_result && $data_result->num_rows > 0) {
            $columns = [];
            $fields = $data_result->fetch_fields();
            foreach ($fields as $field) {
                $columns[] = "`{$field->name}`";
            }
            
            while ($row = $data_result->fetch_assoc()) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $conn->real_escape_string($value) . "'";
                    }
                }
                $sql_content .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
            }
            $sql_content .= "\n";
        }
    }
    
    $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    
    if (!file_put_contents($filepath, $sql_content)) {
        return ['success' => false, 'message' => 'Failed to write backup file'];
    }
    
    // Update config
    $config['last_backup'] = date('Y-m-d H:i:s');
    $config['last_backup_file'] = $filename;
    $config['total_backups_run'] = ($config['total_backups_run'] ?? 0) + 1;
    saveScheduleConfig($config_file, $config);
    
    // Apply retention - keep only latest N scheduled backups
    applyRetention($backup_dir, $config['retention_count'], $prefix);
    
    return [
        'success' => true,
        'message' => "Backup created: $filename",
        'filename' => $filename,
        'tables' => count($tables_to_backup),
        'size' => strlen($sql_content),
        'type' => $backup_type,
    ];
}

/**
 * Apply retention policy - keep latest N scheduled backups, delete older ones
 */
function applyRetention($backup_dir, $keep_count, $prefix = 'scheduled') {
    $scheduled_backups = [];
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'sql') continue;
        // Only apply retention to scheduled backups (not manual ones)
        if (strpos($file, 'scheduled_') === 0) {
            $scheduled_backups[] = $file;
        }
    }
    
    // Sort descending by name (which includes timestamp)
    rsort($scheduled_backups);
    
    // Delete files beyond retention count
    $deleted = 0;
    for ($i = $keep_count; $i < count($scheduled_backups); $i++) {
        $filepath = $backup_dir . $scheduled_backups[$i];
        if (file_exists($filepath) && unlink($filepath)) {
            $deleted++;
        }
    }
    
    return $deleted;
}

// --- Main execution (only when called directly, not when included) ---
if ($_is_direct_call) {
    $conn = getDbConnection();
    $config = loadScheduleConfig($config_file);
    
    if (!$config['enabled'] && !$is_cli) {
        echo json_encode(['error' => 'Scheduled backups are disabled']);
        exit;
    }
    
    $result = runScheduledBackup($conn, $config, $backup_dir, $config_file);
    
    if ($is_cli) {
        echo date('Y-m-d H:i:s') . " - " . $result['message'] . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
