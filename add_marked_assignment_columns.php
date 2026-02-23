<?php
/**
 * Database Migration: Add marked assignment columns to vle_submissions table
 * This allows lecturers to upload marked/graded assignments for students to download
 */

// Set HTTP_HOST for CLI execution
$_SERVER['HTTP_HOST'] = 'localhost';

require_once 'includes/config.php';

$conn = getDbConnection();

echo "Adding marked assignment columns to vle_submissions table...\n";

// Check if columns already exist
$check_query = "SHOW COLUMNS FROM vle_submissions LIKE 'marked_file_path'";
$result = $conn->query($check_query);

if ($result->num_rows > 0) {
    echo "Columns already exist. Skipping migration.\n";
    exit();
}

// Add columns for marked assignment files
$sql = "ALTER TABLE vle_submissions 
        ADD COLUMN marked_file_path VARCHAR(255) DEFAULT NULL AFTER feedback,
        ADD COLUMN marked_file_name VARCHAR(255) DEFAULT NULL AFTER marked_file_path";

if ($conn->query($sql)) {
    echo "✓ Successfully added marked_file_path and marked_file_name columns to vle_submissions table.\n";
    echo "✓ Lecturers can now upload marked assignments for students to download.\n";
} else {
    echo "✗ Error adding columns: " . $conn->error . "\n";
}

// Create uploads directory for marked assignments
$marked_dir = __DIR__ . '/uploads/marked_assignments';
if (!is_dir($marked_dir)) {
    if (mkdir($marked_dir, 0755, true)) {
        echo "✓ Created directory: uploads/marked_assignments/\n";
    } else {
        echo "✗ Failed to create directory: uploads/marked_assignments/\n";
    }
} else {
    echo "✓ Directory already exists: uploads/marked_assignments/\n";
}

$conn->close();

echo "\nMigration completed successfully!\n";
echo "\nNext steps:\n";
echo "1. Lecturers can upload marked assignments when grading in the gradebook\n";
echo "2. Students can download their marked assignments from the Grades tab\n";
?>
