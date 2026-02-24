<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$tables = ['vle_courses', 'lecturers', 'examination_managers'];
foreach ($tables as $table) {
    $result = $conn->query('DESCRIBE ' . $table);
    if ($result) {
        echo $table . ' exists' . "\n";
    } else {
        echo $table . ' does not exist' . "\n";
    }
}
?>