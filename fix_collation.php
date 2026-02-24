<?php
/**
 * Fix Collation Mismatch
 * 
 * Converts tables with utf8mb4_unicode_ci to utf8mb4_general_ci
 * so all tables use the same collation and JOINs work without COLLATE overrides.
 * 
 * Run once: php fix_collation.php  or  visit http://localhost/vle-eumw/fix_collation.php
 */
require_once __DIR__ . '/includes/config.php';
$conn = getDbConnection();

echo "<h2>Fixing Database Collation Mismatches</h2>";

$target_collation = 'utf8mb4_general_ci';
$target_charset = 'utf8mb4';

// Get all tables in the database
$result = $conn->query("SHOW TABLE STATUS");
$fixed = 0;
$skipped = 0;
$errors = [];

while ($table = $result->fetch_assoc()) {
    $name = $table['Name'];
    $current = $table['Collation'];
    
    if ($current !== $target_collation) {
        // Convert table default collation
        $sql = "ALTER TABLE `$name` CONVERT TO CHARACTER SET $target_charset COLLATE $target_collation";
        if ($conn->query($sql)) {
            echo "<p style='color:green;'>&#10003; <strong>$name</strong>: $current &rarr; $target_collation</p>";
            $fixed++;
        } else {
            $err = $conn->error;
            echo "<p style='color:red;'>&#10007; <strong>$name</strong>: Failed - $err</p>";
            $errors[] = "$name: $err";
        }
    } else {
        $skipped++;
    }
}

echo "<hr>";
echo "<p><strong>Fixed:</strong> $fixed tables</p>";
echo "<p><strong>Already OK:</strong> $skipped tables</p>";
if (count($errors) > 0) {
    echo "<p style='color:red;'><strong>Errors:</strong> " . count($errors) . "</p>";
    foreach ($errors as $e) echo "<p style='color:red;'>  - $e</p>";
}
echo "<p style='color:green;font-weight:bold;'>Done! All tables should now use $target_collation.</p>";
