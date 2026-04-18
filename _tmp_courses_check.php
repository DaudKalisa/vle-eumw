<?php
require_once 'includes/config.php';
$conn = getDbConnection();
$r = $conn->query('SELECT COUNT(*) as cnt FROM modules');
echo $r->fetch_assoc()['cnt'].' modules total'."\n";
$r2 = $conn->query('SELECT DISTINCT program_of_study FROM modules ORDER BY program_of_study');
while($row=$r2->fetch_assoc()) echo $row['program_of_study']."\n";
$r3 = $conn->query('SELECT module_code,module_name,program_of_study,year_of_study,semester FROM modules ORDER BY program_of_study,year_of_study,module_code LIMIT 20');
while($row=$r3->fetch_assoc()) echo json_encode($row)."\n";
