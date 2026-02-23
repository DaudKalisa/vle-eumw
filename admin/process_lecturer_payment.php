<?php
// admin/process_lecturer_payment.php - Start payment process for approved lecturer requests
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();

// Get all approved and unpaid requests
$sql = "SELECT r.*, l.full_name, l.email, l.position, l.department FROM lecturer_finance_requests r JOIN lecturers l ON r.lecturer_id = l.lecturer_id WHERE r.status = 'approved' ORDER BY r.request_date DESC";
$result = $conn->query($sql);
$requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Process Lecturer Payments</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css'>
</head>
<body>
    <nav class='navbar navbar-expand-lg navbar-dark bg-success'>
        <?php
        // admin/process_lecturer_payment.php - Redirect to new location in finance/
        header('Location: ../finance/process_lecturer_payment.php');
        exit;
            </div>
