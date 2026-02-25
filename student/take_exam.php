<?php
// student/take_exam.php - Redirect to the main examination system with camera invigilation
// All exam-taking is now handled by examination/take_exam.php which has full
// camera invigilation, violation detection, and snapshot capture
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if ($session_id) {
    header("Location: ../examination/take_exam.php?session_id=$session_id");
} elseif ($exam_id) {
    header("Location: ../examination/take_exam.php?exam_id=$exam_id");
} else {
    header("Location: ../examination/exams.php");
}
exit();
