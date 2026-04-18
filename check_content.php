<?php
require_once 'includes/config.php';
$conn = getDbConnection();

echo "Weekly Content for Course 100:\n";
echo "==============================\n\n";

$r = $conn->query("SELECT content_id, course_id, week_number, title, file_path, file_name, content_type 
                   FROM vle_weekly_content 
                   WHERE course_id = 100 
                   ORDER BY week_number, sort_order 
                   LIMIT 20");

if ($r && $r->num_rows > 0) {
    while ($row = $r->fetch_assoc()) {
        echo "Week " . $row['week_number'] . " - " . $row['title'] . "\n";
        echo "  Type: " . $row['content_type'] . "\n";
        echo "  File Path: " . ($row['file_path'] ?: 'NULL') . "\n";
        echo "  File Name: " . ($row['file_name'] ?: 'NULL') . "\n";
        echo "---\n";
    }
} else {
    echo "No content found for course 100\n";
}

echo "\n\nChecking uploads folder structure:\n";
echo "==================================\n";

$uploads_path = __DIR__ . '/uploads';
if (is_dir($uploads_path)) {
    echo "Uploads folder exists at: $uploads_path\n";
    $subdirs = glob($uploads_path . '/*', GLOB_ONLYDIR);
    echo "Subdirectories: " . implode(', ', array_map('basename', $subdirs)) . "\n";
    
    // Check for course_content subfolder
    if (is_dir($uploads_path . '/course_content')) {
        echo "\nFiles in uploads/course_content:\n";
        $files = glob($uploads_path . '/course_content/*');
        foreach (array_slice($files, 0, 10) as $f) {
            echo "  - " . basename($f) . "\n";
        }
    }
} else {
    echo "Uploads folder NOT found\n";
}
?>
