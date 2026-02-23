<?php
// finance/pay_lecturer.php - Mark lecturer request as paid and print receipt
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);
    // Only allow paying approved requests
    $stmt = $conn->prepare("SELECT status FROM lecturer_finance_requests WHERE request_id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row && $row['status'] === 'approved') {
        $stmt2 = $conn->prepare("UPDATE lecturer_finance_requests SET status = 'paid', response_date = NOW() WHERE request_id = ?");
        $stmt2->bind_param('i', $request_id);
        $stmt2->execute();
        $stmt2->close();
        // Redirect to print report
        header('Location: print_lecturer_payment.php?id=' . $request_id);
        exit();
    } else {
        header('Location: finance_manage_requests.php?msg=Only approved requests can be paid');
        exit();
    }
}
header('Location: finance_manage_requests.php?msg=Invalid+request');
exit;
