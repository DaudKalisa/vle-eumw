<?php
// finance/pay_lecturer.php - Mark lecturer request as paid and print receipt
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);
    // Only allow paying approved requests
    $stmt = $conn->prepare("SELECT status FROM lecturer_finance_requests WHERE request_id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row && $row['status'] === 'approved') {
        $stmt2 = $conn->prepare("UPDATE lecturer_finance_requests SET status = 'paid', response_date = NOW() WHERE request_id = ?");
        $stmt2->bind_param('i', $request_id);
        if ($stmt2->execute()) {
            $stmt2->close();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Payment processed successfully.']);
                exit;
            } else {
                // Redirect to print report
                header('Location: print_lecturer_payment.php?id=' . $request_id);
                exit;
            }
        } else {
            $stmt2->close();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit;
            } else {
                header('Location: finance_manage_requests.php?msg=Payment+failed');
                exit;
            }
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Only approved requests can be paid.']);
            exit;
        } else {
            header('Location: finance_manage_requests.php?msg=Only approved requests can be paid');
            exit;
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} else {
    header('Location: finance_manage_requests.php?msg=Invalid+request');
}
exit;
