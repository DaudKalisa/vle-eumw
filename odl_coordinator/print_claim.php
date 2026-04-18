<?php
/**
 * ODL Coordinator - Print Lecturer Claim
 * Matches finance/print_lecturer_payment.php receipt design
 * Includes approval modal with signature draw/upload
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff', 'lecturer', 'dean', 'finance']);

$conn = getDbConnection();
$user = getCurrentUser();
$request_id = (int)($_GET['id'] ?? 0);

if (!$request_id) {
    echo '<div class="alert alert-danger p-4 m-4"><h5>Invalid Request</h5><p>No valid claim ID provided.</p><a href="javascript:history.back()" class="btn btn-secondary mt-2">Go Back</a></div>';
    exit;
}

// Get claim details with all approval info and approver names
$stmt = $conn->prepare("SELECT r.*, l.full_name, l.email, l.phone, l.department, l.position, l.nrc, l.lecturer_id as lid, COALESCE(r.bank_name, l.bank_name) as bank_name, COALESCE(r.account_number, l.account_number) as account_number, l.staff_id,
                        d.department_name,
                        odl_u.username as odl_approver_name,
                        dean_u.username as dean_approver_name
                        FROM lecturer_finance_requests r
                        LEFT JOIN lecturers l ON r.lecturer_id = l.lecturer_id
                        LEFT JOIN departments d ON l.department = d.department_id
                        LEFT JOIN users odl_u ON r.odl_approved_by = odl_u.user_id
                        LEFT JOIN users dean_u ON r.dean_approved_by = dean_u.user_id
                        WHERE r.request_id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$claim = $stmt->get_result()->fetch_assoc();

if (!$claim) {
    echo '<div class="alert alert-warning p-4 m-4"><h5>Claim Not Found</h5><p>The requested claim record was not found.</p><a href="javascript:history.back()" class="btn btn-secondary mt-2">Go Back</a></div>';
    exit;
}

// Dynamic status
$is_paid = ($claim['status'] === 'paid');
$status_label = strtoupper($claim['status']);
$watermark_text = $is_paid ? 'PAID' : ($claim['status'] === 'approved' ? 'APPROVED' : strtoupper($claim['status']));
$status_badge_color = $is_paid ? 'info' : ($claim['status'] === 'approved' ? 'success' : 'warning');

// Reference number
$ref_number = 'ODL/CLM/' . date('Y') . '/' . str_pad($request_id, 5, '0', STR_PAD_LEFT);

// University settings
$university_name = "Eastern University of Malawi and the World";
$university_address = "P.O. Box 123, Mzuzu, Malawi";
$university_phone = "+265 1 234 567";
$university_email = "finance@eumw.edu";
$university_website = "www.eumw.edu";
$logo_path = '../assets/img/Logo.png';

$settings_query = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($settings_query && $settings_query->num_rows > 0) {
    $settings = $settings_query->fetch_assoc();
    $university_name = $settings['university_name'] ?? $university_name;
    $university_address = ($settings['address_po_box'] ?? '') . ', ' . ($settings['address_area'] ?? '') . ', ' . ($settings['address_city'] ?? '') . ', ' . ($settings['address_country'] ?? '');
    $university_phone = $settings['phone'] ?? $university_phone;
    $university_email = $settings['email'] ?? $university_email;
    $university_website = $settings['website'] ?? $university_website;
    if (!empty($settings['logo_path'])) {
        $test = '../' . $settings['logo_path'];
        if (file_exists($test)) $logo_path = $test;
    }
}

// Decode courses data
$courses_data = json_decode($claim['courses_data'] ?? '[]', true) ?: [];

// Approval button logic
$role = $user['role'] ?? '';
$show_odl_button = ($role === 'odl_coordinator' || $role === 'admin') && $claim['odl_approval_status'] !== 'approved';
$show_dean_button = ($role === 'dean' || $role === 'admin') && $claim['dean_approval_status'] !== 'approved' && $claim['odl_approval_status'] === 'approved';
$show_finance_button = ($role === 'finance' || $role === 'admin') && ($claim['status'] !== 'approved' && $claim['status'] !== 'paid') && $claim['dean_approval_status'] === 'approved';

// Number to words function
function numberToWords($number) {
    $number = (int)$number;
    if ($number == 0) return 'zero';
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
             'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $words = '';
    if ($number >= 1000000) { $words .= numberToWords((int)($number / 1000000)) . ' million '; $number %= 1000000; }
    if ($number >= 1000) { $words .= numberToWords((int)($number / 1000)) . ' thousand '; $number %= 1000; }
    if ($number >= 100) { $words .= $ones[(int)($number / 100)] . ' hundred '; $number %= 100; }
    if ($number >= 20) { $words .= $tens[(int)($number / 10)] . ' '; $number %= 10; }
    if ($number > 0) { $words .= $ones[$number] . ' '; }
    return trim($words);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Claim - <?= htmlspecialchars($claim['full_name']) ?> - <?= $ref_number ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .receipt-container { box-shadow: none !important; border: none !important; margin: 0 !important; }
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { margin: 0.5cm; size: A4; }
            * { font-size: 11px !important; }
            .receipt-header { padding: 10px !important; }
            .receipt-header img { max-height: 50px !important; }
            .receipt-header h1 { font-size: 16px !important; }
            .receipt-header p { font-size: 10px !important; margin: 2px 0 !important; }
            .receipt-title { padding: 8px !important; font-size: 14px !important; }
            .receipt-body { padding: 10px 15px !important; }
            .receipt-number { padding: 8px !important; margin-bottom: 10px !important; }
            .receipt-number h4 { font-size: 12px !important; }
            .info-section { margin-bottom: 8px !important; }
            .info-section h5 { font-size: 12px !important; padding-bottom: 3px !important; margin-bottom: 5px !important; }
            .info-table td { padding: 2px 0 !important; font-size: 11px !important; }
            .payment-amount { padding: 10px !important; margin: 10px 0 !important; }
            .payment-amount h2 { font-size: 20px !important; }
            .signature-section { margin-top: 15px !important; padding-top: 10px !important; }
            .signature-box { width: 48% !important; }
            .receipt-footer { padding: 8px 15px !important; font-size: 9px !important; }
            .watermark { font-size: 60px !important; }
        }
        body { background: #f5f5f5; font-family: 'Arial', sans-serif; font-size: 12px; }
        .receipt-container { max-width: 800px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); overflow: hidden; }
        .receipt-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 15px; text-align: center; }
        .receipt-header img { max-height: 60px; margin-bottom: 8px; }
        .receipt-header h1 { margin: 0; font-size: 18px; font-weight: bold; }
        .receipt-header p { margin: 3px 0 0 0; opacity: 0.9; font-size: 11px; }
        .receipt-title { background: #17a2b8; color: white; text-align: center; padding: 10px; font-size: 16px; font-weight: bold; letter-spacing: 2px; }
        .receipt-body { padding: 15px 20px; }
        .receipt-number { background: #f8f9fa; border: 2px dashed #dee2e6; padding: 10px; text-align: center; border-radius: 8px; margin-bottom: 15px; }
        .receipt-number h4 { margin: 0; color: #1e3c72; font-weight: bold; font-size: 14px; }
        .info-section { margin-bottom: 12px; }
        .info-section h5 { color: #1e3c72; border-bottom: 2px solid #1e3c72; padding-bottom: 5px; font-size: 13px; margin-bottom: 8px; }
        .info-table { width: 100%; }
        .info-table td { padding: 3px 0; vertical-align: top; font-size: 12px; }
        .info-table td:first-child { font-weight: 600; color: #555; width: 40%; }
        .payment-amount { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; padding: 12px; border-radius: 10px; text-align: center; margin: 12px 0; }
        .payment-amount h2 { margin: 0; font-size: 24px; font-weight: bold; }
        .payment-amount p { margin: 3px 0 0 0; opacity: 0.9; font-size: 11px; }
        .receipt-footer { background: #f8f9fa; padding: 10px 20px; border-top: 1px solid #dee2e6; font-size: 10px; color: #666; }
        .signature-section { display: flex; justify-content: space-between; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #dee2e6; flex-wrap: wrap; gap: 10px; }
        .signature-box { text-align: center; width: 23%; }
        .signature-box p { margin: 0; font-size: 11px; }
        .signature-box small { font-size: 10px; }
        .signature-line { border-bottom: 1px solid #333; margin-bottom: 3px; height: 25px; }
        .signature-image { border: 1px solid #dee2e6; background: #fff; padding: 5px; min-height: 50px; display: flex; align-items: center; justify-content: center; }
        .signature-image img { max-height: 45px; max-width: 100%; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; color: rgba(23, 162, 184, 0.1); font-weight: bold; pointer-events: none; z-index: 0; }
        .print-buttons { text-align: center; padding: 15px; background: #f8f9fa; }
        .info-row { display: flex; gap: 20px; }
        .info-row .info-section { flex: 1; }
        .notes-section { background: #f8f9fa; border-radius: 8px; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <!-- Action Buttons -->
    <div class="print-buttons no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg me-2">
            <i class="bi bi-printer"></i> Print Claim
        </button>
        <a href="claims_approval.php" class="btn btn-secondary btn-lg me-2">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <?php if ($show_odl_button): ?>
        <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#approvalModal" onclick="setApprovalRole('odl')">
            <i class="bi bi-check-circle"></i> Approve (ODL)
        </button>
        <?php elseif ($show_dean_button): ?>
        <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#approvalModal" onclick="setApprovalRole('dean')">
            <i class="bi bi-check-circle"></i> Approve (Dean)
        </button>
        <?php elseif ($show_finance_button): ?>
        <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#approvalModal" onclick="setApprovalRole('finance')">
            <i class="bi bi-check-circle"></i> Approve (Finance)
        </button>
        <?php endif; ?>
    </div>

    <div class="receipt-container position-relative">
        <div class="watermark"><?= $watermark_text ?></div>

        <!-- Header -->
        <div class="receipt-header">
            <img src="<?= htmlspecialchars($logo_path) ?>" alt="University Logo" onerror="this.style.display='none'">
            <h1><?= htmlspecialchars($university_name) ?></h1>
            <p><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($university_address) ?></p>
            <p><i class="bi bi-telephone"></i> <?= htmlspecialchars($university_phone) ?> | <i class="bi bi-envelope"></i> <?= htmlspecialchars($university_email) ?></p>
        </div>

        <!-- Title -->
        <div class="receipt-title">
            <i class="bi bi-receipt"></i> LECTURER FINANCE CLAIM FORM
        </div>

        <div class="receipt-body">
            <!-- Reference Number -->
            <div class="receipt-number">
                <h4>Ref: <?= $ref_number ?> | Date: <?= date('M d, Y') ?></h4>
            </div>

            <!-- Lecturer & Claim Info -->
            <div class="info-row">
                <div class="info-section">
                    <h5><i class="bi bi-person-badge"></i> Lecturer Information</h5>
                    <table class="info-table">
                        <tr><td>Staff ID:</td><td><strong><?= htmlspecialchars($claim['staff_id'] ?? 'N/A') ?></strong></td></tr>
                        <tr><td>Full Name:</td><td><?= htmlspecialchars($claim['full_name']) ?></td></tr>
                        <tr><td>Department:</td><td><?= htmlspecialchars($claim['department_name'] ?? $claim['department'] ?? 'N/A') ?></td></tr>
                        <tr><td>Position:</td><td><?= htmlspecialchars($claim['position'] ?? 'N/A') ?></td></tr>
                        <tr><td>NRC:</td><td><?= htmlspecialchars($claim['nrc'] ?? 'N/A') ?></td></tr>
                    </table>
                </div>
                <div class="info-section">
                    <h5><i class="bi bi-calendar-check"></i> Claim Period</h5>
                    <table class="info-table">
                        <tr><td>Period:</td><td><strong><?= date('F Y', mktime(0,0,0,$claim['month']??1,1,$claim['year']??date('Y'))) ?></strong></td></tr>
                        <tr><td>Courses Taught:</td><td><?= htmlspecialchars($claim['total_modules'] ?? 0) ?> course(s)</td></tr>
                        <tr><td>Total Hours:</td><td><?= htmlspecialchars($claim['total_hours'] ?? 0) ?> hours</td></tr>
                        <tr><td>Submission Date:</td><td><?= !empty($claim['submission_date']) ? date('M d, Y', strtotime($claim['submission_date'])) : (!empty($claim['request_date']) ? date('M d, Y', strtotime($claim['request_date'])) : 'N/A') ?></td></tr>
                        <tr><td>Status:</td><td><span class="badge bg-<?= $status_badge_color ?>"><?= $status_label ?></span></td></tr>
                    </table>
                </div>
            </div>

            <!-- Course Breakdown -->
            <?php if (!empty($courses_data)): ?>
            <div class="info-section">
                <h5><i class="bi bi-book"></i> Course Breakdown</h5>
                <table class="table table-sm table-bordered" style="font-size: 11px;">
                    <thead class="table-light">
                        <tr><th>#</th><th>Course</th><th>Hours</th><th>Students</th><th>Assignments Marked</th><th>Content Uploaded</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses_data as $i => $course): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($course['course_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($course['hours'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($course['students'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($course['assignments'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($course['content'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Amount -->
            <div class="payment-amount">
                <p style="margin:0;"><?= $is_paid ? 'Amount Paid' : 'Amount Claimed' ?></p>
                <h2>MKW<?= number_format($claim['total_amount'] ?? 0, 2) ?></h2>
                <p>(<?= ucwords(numberToWords($claim['total_amount'] ?? 0)) ?> Kwacha Only)</p>
            </div>

            <!-- Contact & Verification -->
            <div class="info-row">
                <div class="info-section">
                    <h5><i class="bi bi-telephone"></i> Contact & Banking</h5>
                    <table class="info-table">
                        <tr><td>Email:</td><td><?= htmlspecialchars($claim['email'] ?? '') ?></td></tr>
                        <tr><td>Phone:</td><td><?= htmlspecialchars($claim['phone'] ?? '') ?></td></tr>
                        <tr><td>Bank:</td><td><?= htmlspecialchars($claim['bank_name'] ?? 'N/A') ?></td></tr>
                        <tr><td>Account No:</td><td><?= htmlspecialchars($claim['account_number'] ?? 'N/A') ?></td></tr>
                    </table>
                </div>
                <div class="info-section">
                    <h5><i class="bi bi-diagram-3"></i> Approval Chain</h5>
                    <table class="info-table">
                        <tr>
                            <td>ODL Coordinator:</td>
                            <td>
                                <?php if ($claim['odl_approval_status'] === 'approved' || $claim['odl_approval_status'] === 'forwarded_to_dean'): ?>
                                    <span class="badge bg-success"><?= ucfirst(str_replace('_', ' ', $claim['odl_approval_status'])) ?></span>
                                    <?php if (!empty($claim['odl_approver_name'])): ?> by <?= htmlspecialchars($claim['odl_approver_name']) ?><?php endif; ?>
                                    <?php if (!empty($claim['odl_approved_at'])): ?> on <?= date('M d, Y', strtotime($claim['odl_approved_at'])) ?><?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= ucfirst($claim['odl_approval_status'] ?? 'Pending') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($claim['dean_approval_status'])): ?>
                        <tr>
                            <td>Dean:</td>
                            <td>
                                <?php if ($claim['dean_approval_status'] === 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                    <?php if (!empty($claim['dean_approver_name'])): ?> by <?= htmlspecialchars($claim['dean_approver_name']) ?><?php endif; ?>
                                    <?php if (!empty($claim['dean_approved_at'])): ?> on <?= date('M d, Y', strtotime($claim['dean_approved_at'])) ?><?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= ucfirst($claim['dean_approval_status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Finance:</td>
                            <td>
                                <?php if ($is_paid): ?>
                                    <span class="badge bg-success">Paid</span>
                                    <?php if (!empty($claim['finance_paid_at'])): ?> on <?= date('M d, Y', strtotime($claim['finance_paid_at'])) ?>
                                    <?php elseif (!empty($claim['response_date'])): ?> on <?= date('M d, Y', strtotime($claim['response_date'])) ?><?php endif; ?>
                                <?php elseif ($claim['status'] === 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= ucfirst($claim['status'] ?? 'Pending') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if (!empty($claim['additional_notes'])): ?>
            <div class="notes-section">
                <strong><i class="bi bi-sticky"></i> Additional Notes:</strong>
                <p class="mb-0 mt-2"><?= nl2br(htmlspecialchars($claim['additional_notes'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Signatures -->
            <div class="signature-section">
                <div class="signature-box">
                    <?php if (!empty($claim['signature_path'])): ?>
                        <div class="signature-image"><img src="../uploads/signatures/<?= htmlspecialchars($claim['signature_path']) ?>" alt="Lecturer Signature"></div>
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <p><strong>Lecturer</strong></p>
                    <small><?= htmlspecialchars($claim['full_name']) ?></small>
                </div>
                <div class="signature-box">
                    <?php if (!empty($claim['odl_signature_path'])): ?>
                        <div class="signature-image"><img src="../uploads/signatures/<?= htmlspecialchars($claim['odl_signature_path']) ?>" alt="ODL Signature"></div>
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <p><strong>ODL Coordinator</strong></p>
                    <small><?= htmlspecialchars($claim['odl_approver_name'] ?? '') ?></small>
                </div>
                <?php if (!empty($claim['dean_approval_status'])): ?>
                <div class="signature-box">
                    <?php if (!empty($claim['dean_signature_path'])): ?>
                        <div class="signature-image"><img src="../uploads/signatures/<?= htmlspecialchars($claim['dean_signature_path']) ?>" alt="Dean Signature"></div>
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <p><strong>Dean</strong></p>
                    <small><?= htmlspecialchars($claim['dean_approver_name'] ?? '') ?></small>
                </div>
                <?php endif; ?>
                <div class="signature-box">
                    <?php if (!empty($claim['finance_signature_path'])): ?>
                        <div class="signature-image"><img src="../uploads/signatures/<?= htmlspecialchars($claim['finance_signature_path']) ?>" alt="Finance Signature"></div>
                    <?php else: ?>
                        <div class="signature-line"></div>
                    <?php endif; ?>
                    <p><strong>Finance Officer</strong></p>
                    <small>Authorized Signatory</small>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0"><strong>Important Notice:</strong></p>
                    <p class="mb-0">This is a computer-generated document. Please retain for your records.</p>
                    <p class="mb-0">For queries, contact: <?= htmlspecialchars($university_email) ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-0"><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
                    <p class="mb-0"><strong>Reference:</strong> <?= $ref_number ?></p>
                    <p class="mb-0"><?= htmlspecialchars($university_website) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade no-print" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="approvalTitle">Approve Claim</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="approvalForm">
                        <input type="hidden" id="requestId" value="<?= $request_id ?>">
                        <input type="hidden" id="approvalRole">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Signature</label>
                            <div class="alert alert-info py-2"><small>Draw your signature below or upload an existing image (PNG/JPG, 2MB max)</small></div>
                            <ul class="nav nav-tabs" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#drawPanel" type="button">&#9999;&#65039; Draw</button></li>
                                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uploadPanel" type="button">&#128228; Upload</button></li>
                            </ul>
                            <div class="tab-content mt-2">
                                <div class="tab-pane fade show active" id="drawPanel">
                                    <div style="border: 1px solid #ddd; border-radius: 5px;">
                                        <canvas id="signatureCanvas" width="400" height="150" style="display: block; cursor: crosshair; background: white; width: 100%;"></canvas>
                                    </div>
                                    <div class="btn-group w-100 mt-2">
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="clearSignature()">Clear</button>
                                        <button type="button" class="btn btn-sm btn-info" onclick="saveTempSignature()">Use This</button>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="uploadPanel">
                                    <input type="file" class="form-control mb-2" id="signatureFile" accept="image/png,image/jpeg" onchange="handleFileUpload(event)">
                                    <div id="filePreview" style="display: none;"><img id="previewImg" style="max-width: 100%; max-height: 150px; border: 1px solid #ddd; border-radius: 5px;"></div>
                                </div>
                            </div>
                            <input type="hidden" id="signatureData">
                        </div>
                        <div class="mb-3">
                            <label for="approvalRemarks" class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" id="approvalRemarks" rows="2" placeholder="Add notes..."></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmCheckbox">
                            <label class="form-check-label" for="confirmCheckbox">I confirm this signature is authentic and I approve this claim</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="submitApprovalBtn" onclick="submitApproval()" disabled>Submit Approval</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let canvas = document.getElementById('signatureCanvas');
    let ctx = canvas ? canvas.getContext('2d') : null;
    let isDrawing = false;
    let selectedSignature = null;

    if (canvas) {
        canvas.addEventListener('mousedown', (e) => { isDrawing = true; const r = canvas.getBoundingClientRect(); ctx.beginPath(); ctx.moveTo(e.clientX - r.left, e.clientY - r.top); });
        canvas.addEventListener('mousemove', (e) => { if (!isDrawing) return; const r = canvas.getBoundingClientRect(); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000'; ctx.lineTo(e.clientX - r.left, e.clientY - r.top); ctx.stroke(); });
        canvas.addEventListener('mouseup', () => isDrawing = false);
        canvas.addEventListener('mouseleave', () => isDrawing = false);
        // Touch support
        canvas.addEventListener('touchstart', (e) => { e.preventDefault(); isDrawing = true; const r = canvas.getBoundingClientRect(); const t = e.touches[0]; ctx.beginPath(); ctx.moveTo(t.clientX - r.left, t.clientY - r.top); });
        canvas.addEventListener('touchmove', (e) => { e.preventDefault(); if (!isDrawing) return; const r = canvas.getBoundingClientRect(); const t = e.touches[0]; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000'; ctx.lineTo(t.clientX - r.left, t.clientY - r.top); ctx.stroke(); });
        canvas.addEventListener('touchend', () => isDrawing = false);
    }

    function setApprovalRole(role) {
        document.getElementById('approvalRole').value = role;
        const titles = { 'odl': 'ODL Coordinator Approval', 'dean': 'Dean of Faculty Approval', 'finance': 'Finance Officer Approval' };
        document.getElementById('approvalTitle').textContent = titles[role] || 'Approve Claim';
        document.getElementById('confirmCheckbox').checked = false;
        document.getElementById('submitApprovalBtn').disabled = true;
        clearSignature();
    }
    function clearSignature() { if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height); document.getElementById('signatureData').value = ''; selectedSignature = null; updateBtn(); }
    function saveTempSignature() { document.getElementById('signatureData').value = canvas.toDataURL('image/png'); selectedSignature = 'drawn'; updateBtn(); }
    function handleFileUpload(e) {
        const f = e.target.files[0]; if (!f) return;
        if (f.size > 2 * 1024 * 1024) { alert('Max 2MB'); return; }
        const r = new FileReader();
        r.onload = (ev) => { document.getElementById('signatureData').value = ev.target.result; document.getElementById('previewImg').src = ev.target.result; document.getElementById('filePreview').style.display = 'block'; selectedSignature = 'uploaded'; updateBtn(); };
        r.readAsDataURL(f);
    }
    function updateBtn() {
        const ok = selectedSignature && document.getElementById('signatureData').value && document.getElementById('confirmCheckbox').checked;
        document.getElementById('submitApprovalBtn').disabled = !ok;
    }
    document.getElementById('confirmCheckbox').addEventListener('change', updateBtn);

    function submitApproval() {
        const sig = document.getElementById('signatureData').value;
        if (!sig) { alert('Please provide a signature'); return; }
        const btn = document.getElementById('submitApprovalBtn');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
        fetch('submit_approval.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: document.getElementById('requestId').value, role: document.getElementById('approvalRole').value, signature: sig, remarks: document.getElementById('approvalRemarks').value })
        }).then(r => r.json()).then(data => {
            if (data.success) { alert('Approval submitted!'); location.reload(); }
            else { alert('Error: ' + (data.error || 'Failed')); btn.disabled = false; btn.innerHTML = 'Submit Approval'; }
        }).catch(err => { alert('Error occurred'); btn.disabled = false; btn.innerHTML = 'Submit Approval'; });
    }
    </script>
</body>
</html>
