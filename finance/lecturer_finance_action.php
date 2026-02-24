<?php
// lecturer_finance_action.php - Handles lecturer finance request actions
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

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
        if ($stmt) {
            $stmt->bind_param('si', $status, $request_id);
            if ($stmt->execute()) {
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Request ' . $action . 'ed successfully.']);
                    exit;
                } else {
                    header('Location: finance_manage_requests.php');
                    exit;
                }
            } else {
                $stmt->close();
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                    exit;
                }
            }
        } else {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit;
            }
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} else {
    header('Location: finance_manage_requests.php');
}
exit;