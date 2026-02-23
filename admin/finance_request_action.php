<?php
// admin/finance_request_action.php - Approve/Reject finance requests
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);


$conn = getDbConnection();
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$ref = isset($_GET['ref']) ? $_GET['ref'] : '';

if ($request_id && in_array($action, ['approve', 'reject'])) {
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $conn->prepare("UPDATE lecturer_finance_requests SET status = ?, response_date = NOW() WHERE request_id = ?");
    $stmt->bind_param("si", $status, $request_id);
    $stmt->execute();
    $stmt->close();
    $msg = ($action === 'approve') ? 'Request approved.' : 'Request rejected.';
    if ($ref === 'recent') {
        header('Location: ../finance/recent_lecturer_requests.php?msg=' . urlencode($msg));
    } else {
        header('Location: ../finance/finance_manage_requests.php?msg=' . urlencode($msg));
    }
    exit();
}
if ($ref === 'recent') {
    header('Location: ../finance/recent_lecturer_requests.php?msg=Invalid+action');
} else {
    header('Location: ../finance/finance_manage_requests.php?msg=Invalid+action');
}
exit;
