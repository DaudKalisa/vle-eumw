<?php
// finance/print_lecturer_payment.php - Print payment confirmation for lecturer
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$request_id) {
    die('Invalid request');
}
$stmt = $conn->prepare("SELECT lfr.*, l.full_name, l.email, l.phone, l.department, l.position, l.nrc FROM lecturer_finance_requests lfr LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id WHERE lfr.request_id = ?");
$stmt->bind_param('i', $request_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die('Request not found');
}

$req = $result->fetch_assoc();
if ($req['status'] !== 'paid') {
    $conn->close();
    die('This request is not marked as paid.');
}
// Fetch university settings for logo/address
$uni = [
    'university_name' => 'Exploits University',
    'address_po_box' => 'P.O.Box 301752',
    'address_area' => 'Area 4',
    'address_street' => '',
    'address_city' => 'Lilongwe',
    'address_country' => 'Malawi',
    'phone' => '',
    'email' => '',
    'logo_path' => '../pictures/logo.bmp',
];
$res = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($res && $res->num_rows > 0) {
    $uni = $res->fetch_assoc();
    if (empty($uni['logo_path'])) $uni['logo_path'] = '../pictures/logo.bmp';
}
$conn->close();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lecturer Payment Confirmation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print { .no-print { display: none; } }
        .signature-box { border: 1px solid #ccc; height: 60px; margin-top: 20px; }
    </style>
</head>
<body class="bg-white">
<div class="container my-5">
    <div class="card shadow">
        <div class="card-header bg-white text-center">
            <img src="<?php echo htmlspecialchars($uni['logo_path']); ?>" alt="Logo" style="max-height:70px; max-width:120px; margin-bottom:10px;">
            <h4 class="mb-0 fw-bold text-success">Lecturer Payment Confirmation</h4>
            <div class="mt-2" style="font-size:1.1rem; color:#333;">
                <strong><?php echo htmlspecialchars($uni['university_name']); ?></strong><br>
                <?php echo htmlspecialchars($uni['address_po_box'] . ', ' . $uni['address_area'] . ', ' . $uni['address_city'] . ', ' . $uni['address_country']); ?><br>
                <?php if (!empty($uni['phone'])): ?>Phone: <?php echo htmlspecialchars($uni['phone']); ?><br><?php endif; ?>
                <?php if (!empty($uni['email'])): ?>Email: <?php echo htmlspecialchars($uni['email']); ?><br><?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>Lecturer Name:</strong> <?php echo htmlspecialchars($req['full_name']); ?><br>
                    <strong>Lecturer ID:</strong> <?php echo htmlspecialchars($req['lecturer_id']); ?><br>
                    <strong>Email:</strong> <?php echo htmlspecialchars($req['email']); ?><br>
                    <strong>Phone:</strong> <?php echo htmlspecialchars($req['phone']); ?><br>
                    <strong>Department:</strong> <?php echo htmlspecialchars($req['department']); ?><br>
                    <strong>Position:</strong> <?php echo htmlspecialchars($req['position']); ?><br>
                    <strong>NRC:</strong> <?php echo htmlspecialchars((string)($req['nrc'] ?? 'N/A')); ?><br>
                </div>
                <div class="col-md-6 text-end">
                    <strong>Payment Date:</strong> <?php echo date('F d, Y', strtotime($req['response_date'])); ?><br>
                    <strong>Request Period:</strong> <?php echo date('F Y', mktime(0,0,0,$req['month'],1,$req['year'])); ?><br>
                    <strong>Modules:</strong> <?php echo htmlspecialchars($req['total_modules']); ?><br>
                    <strong>Hours:</strong> <?php echo htmlspecialchars($req['total_hours']); ?>h<br>
                    <strong>Amount Paid:</strong> <span class="fs-5 fw-bold text-success">K<?php echo number_format($req['total_amount'],2); ?></span><br>
                </div>
            </div>
            <hr>
            <div class="row mb-3">
                <div class="col-md-12">
                    <strong>Additional Notes:</strong><br>
                    <div class="border p-2 bg-light"> <?php echo nl2br(htmlspecialchars($req['additional_notes'])); ?> </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-6">
                    <strong>Finance Officer Signature:</strong>
                    <div class="signature-box"></div>
                </div>
                <div class="col-md-6">
                    <strong>Lecturer Signature:</strong>
                    <div class="signature-box"></div>
                </div>
            </div>
            <div class="mt-4 text-center">
                <button class="btn btn-primary no-print me-2" onclick="window.print()"><i class="bi bi-printer"></i> Print / Export PDF</button>
                <button class="btn btn-secondary no-print" onclick="window.close()"><i class="bi bi-x-circle"></i> Close</button>
            </div>
        </div>
    </div>
</div>
</body>
</html>