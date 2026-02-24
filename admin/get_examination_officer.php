<?php
// get_examination_officer.php - API endpoint to get examination officer details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid officer ID']);
    exit;
}

$manager_id = (int)$_GET['id'];
$conn = getDbConnection();

$stmt = $conn->prepare("SELECT manager_id, full_name, email, phone, department, position, is_active FROM examination_managers WHERE manager_id = ?");
$stmt->bind_param("i", $manager_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Officer not found']);
    exit;
}

$officer = $result->fetch_assoc();

echo json_encode([
    'success' => true,
    'officer' => $officer
]);
?>