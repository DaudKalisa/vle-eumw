<?php
require_once 'includes/config.php';
$conn = getDbConnection();

echo "Assignment Counts per Course:\n";
echo "============================\n";

$result = $conn->query("SELECT vc.course_id, vc.course_name, COUNT(va.assignment_id) as cnt 
FROM vle_courses vc 
LEFT JOIN vle_assignments va ON vc.course_id = va.course_id 
WHERE vc.is_active = 1 
GROUP BY vc.course_id 
ORDER BY vc.course_id 
LIMIT 20");

while ($row = $result->fetch_assoc()) {
    echo $row['course_id'] . " - " . $row['course_name'] . ": " . $row['cnt'] . " assignments\n";
}

$cnt = $conn->query("SELECT COUNT(*) as total FROM vle_assignments")->fetch_assoc();
echo "\n============================\n";
echo "Total assignments in database: " . $cnt['total'] . "\n";
?>
