<?php
// lecturer_finance_action.php - Handles lecturer finance request actions
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    $status = '';
    if ($action === 'approve') {
        $status = 'approved';
    } elseif ($action === 'reject') {
        $status = 'rejected';
    } elseif ($action === 'mark_paid') {
        $status = 'paid';
    }
    if ($status) {
        $stmt = $conn->prepare("UPDATE lecturer_finance_requests SET status = ? WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('si', $status, $request_id);
        $stmt->execute();
        $stmt->close();
    }
}
$conn->close();
header('Location: dashboard.php');
exit;