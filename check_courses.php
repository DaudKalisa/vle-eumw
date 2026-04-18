<?php
require_once 'includes/config.php';
$c = getDbConnection();

echo "=== vle_courses columns ===\n";
$r = $c->query('DESCRIBE vle_courses');
while ($row = $r->fetch_assoc()) {
    echo "  {$row['Field']} ({$row['Type']}) {$row['Null']} {$row['Default']}\n";
}

echo "\n=== Sample courses ===\n";
$r = $c->query('SELECT course_id, course_code, course_name FROM vle_courses LIMIT 10');
while ($row = $r->fetch_assoc()) {
    echo "  [{$row['course_id']}] {$row['course_code']} - {$row['course_name']}\n";
}
