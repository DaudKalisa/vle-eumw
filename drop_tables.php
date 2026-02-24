<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$tables = [
    'exam_results',
    'exam_monitoring',
    'exam_answers',
    'exam_sessions',
    'exam_tokens',
    'exam_questions'
];

foreach ($tables as $table) {
    $conn->query('DROP TABLE IF EXISTS ' . $table);
    echo 'Dropped ' . $table . "\n";
}
?>