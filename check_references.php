<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$result = $conn->query('DESCRIBE exams');
if ($result) {
    echo "Exams table exists\n";
} else {
    echo "Exams table does not exist\n";
}

$result = $conn->query('DESCRIBE students');
if ($result) {
    echo "Students table exists\n";
} else {
    echo "Students table does not exist\n";
}
?>