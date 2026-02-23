<?php
// update_profile_columns.php - Add profile-related columns to database
require_once 'includes/config.php';

$conn = getDbConnection();
$success = true;

echo "Updating database tables for profile functionality...\n\n";

// Add columns to students table
$student_columns = [
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS phone VARCHAR(20)",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS address TEXT",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255)"
];

echo "Updating students table:\n";
foreach ($student_columns as $sql) {
    if ($conn->query($sql)) {
        echo "✓ Column added successfully\n";
    } else {
        echo "✗ Error: " . $conn->error . "\n";
        $success = false;
    }
}

echo "\n";

// Add columns to lecturers table
$lecturer_columns = [
    "ALTER TABLE lecturers ADD COLUMN IF NOT EXISTS phone VARCHAR(20)",
    "ALTER TABLE lecturers ADD COLUMN IF NOT EXISTS office VARCHAR(100)",
    "ALTER TABLE lecturers ADD COLUMN IF NOT EXISTS bio TEXT",
    "ALTER TABLE lecturers ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255)"
];

echo "Updating lecturers table:\n";
foreach ($lecturer_columns as $sql) {
    if ($conn->query($sql)) {
        echo "✓ Column added successfully\n";
    } else {
        echo "✗ Error: " . $conn->error . "\n";
        $success = false;
    }
}

echo "\n";

// Create uploads/profiles directory
$upload_dir = 'uploads/profiles';
if (!is_dir($upload_dir)) {
    if (mkdir($upload_dir, 0755, true)) {
        echo "✓ Created directory: $upload_dir\n";
    } else {
        echo "✗ Failed to create directory: $upload_dir\n";
        $success = false;
    }
} else {
    echo "✓ Directory already exists: $upload_dir\n";
}

echo "\n";

if ($success) {
    echo "========================================\n";
    echo "Database update completed successfully!\n";
    echo "========================================\n";
} else {
    echo "========================================\n";
    echo "Database update completed with errors!\n";
    echo "========================================\n";
}

$conn->close();
?>
