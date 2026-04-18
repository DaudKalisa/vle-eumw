<?php
require_once 'includes/config.php';
$c = getDbConnection();

// 1. Update semester ENUM to include 'Both'
$r = $c->query("ALTER TABLE vle_courses MODIFY COLUMN semester ENUM('One','Two','Both') DEFAULT 'One'");
echo $r ? "OK: Semester ENUM updated to include 'Both'\n" : "Error: " . $c->error . "\n";

// 2. Add applicable_years column if missing
$r = $c->query("SHOW COLUMNS FROM vle_courses LIKE 'applicable_years'");
if ($r->num_rows == 0) {
    $r2 = $c->query("ALTER TABLE vle_courses ADD COLUMN applicable_years VARCHAR(50) DEFAULT NULL AFTER year_of_study");
    echo $r2 ? "OK: applicable_years column added\n" : "Error: " . $c->error . "\n";
} else {
    echo "OK: applicable_years already exists\n";
}

// Verify
$r = $c->query("SHOW COLUMNS FROM vle_courses WHERE Field IN ('semester', 'year_of_study', 'applicable_years')");
while ($row = $r->fetch_assoc()) {
    echo "  {$row['Field']}: {$row['Type']} (Default: {$row['Default']})\n";
}
