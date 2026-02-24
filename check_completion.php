<?php
require_once 'includes/config.php';
$conn = getDbConnection();

$tables = ['exams', 'exam_questions', 'exam_tokens', 'exam_sessions', 'exam_answers', 'exam_monitoring', 'exam_results', 'examination_managers'];
$all_exist = true;

echo "Checking examination system tables:\n";
foreach ($tables as $table) {
    $result = $conn->query('DESCRIBE ' . $table);
    if ($result) {
        echo "✅ $table - OK\n";
    } else {
        echo "❌ $table - MISSING\n";
        $all_exist = false;
    }
}

if ($all_exist) {
    echo "\n✅ All 8 examination tables exist\n";

    // Check if examination manager user exists
    $result = $conn->query('SELECT COUNT(*) as count FROM users WHERE username = "exam_manager"');
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        echo "✅ Examination manager user account exists\n";
    } else {
        echo "❌ Examination manager user account missing\n";
    }

    // Check if examination manager record exists
    $result = $conn->query('SELECT COUNT(*) as count FROM examination_managers');
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        echo "✅ Examination manager record exists\n";
    } else {
        echo "❌ Examination manager record missing\n";
    }
} else {
    echo "\n❌ Some tables are missing\n";
}
?>