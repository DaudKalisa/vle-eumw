<?php
// lecturer/get_claim_details.php - AJAX endpoint for lecturer's own claim preview
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = getRelatedIdForRole('lecturer');
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$request_id) {
    echo '<div class="alert alert-danger">Invalid request.</div>';
    exit;
}

// Get claim - only if it belongs to this lecturer
$stmt = $conn->prepare("
    SELECT r.*, l.full_name, l.email, l.phone, l.position, l.department, l.nrc,
           COALESCE(r.bank_name, l.bank_name) as claim_bank_name,
           COALESCE(r.account_number, l.account_number) as claim_account_number
    FROM lecturer_finance_requests r
    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
    WHERE r.request_id = ? AND r.lecturer_id = ?
");
$stmt->bind_param("is", $request_id, $lecturer_id);
$stmt->execute();
$claim = $stmt->get_result()->fetch_assoc();

if (!$claim) {
    echo '<div class="alert alert-danger">Claim not found or access denied.</div>';
    exit;
}

// Get courses data
$courses = json_decode($claim['courses_data'], true) ?: [];

// Get airtime rate
$fee_rates = $conn->query("SELECT lecturer_airtime_rate FROM fee_settings LIMIT 1")->fetch_assoc();
$airtime_rate = (float)($fee_rates['lecturer_airtime_rate'] ?? 15000);

// Status badge
$status_badges = [
    'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
    'approved' => '<span class="badge bg-success">Approved</span>',
    'rejected' => '<span class="badge bg-danger">Rejected</span>',
    'paid' => '<span class="badge bg-info">Paid</span>'
];
$odl_badges = [
    'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
    'approved' => '<span class="badge bg-success">Approved</span>',
    'rejected' => '<span class="badge bg-danger">Rejected</span>',
    'forwarded_to_dean' => '<span class="badge bg-primary">Forwarded to Dean</span>'
];
$dean_badges = [
    'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
    'approved' => '<span class="badge bg-success">Approved</span>',
    'rejected' => '<span class="badge bg-danger">Rejected</span>'
];
?>

<!-- Lecturer Info -->
<div class="row mb-3">
    <div class="col-md-6">
        <h6><i class="bi bi-person-badge me-2"></i>Lecturer Information</h6>
        <table class="table table-sm table-borderless mb-0">
            <tr><td class="text-muted" style="width:40%">Name:</td><td><strong><?= htmlspecialchars($claim['full_name']) ?></strong></td></tr>
            <tr><td class="text-muted">Lecturer ID:</td><td><?= htmlspecialchars($claim['lecturer_id']) ?></td></tr>
            <tr><td class="text-muted">Email:</td><td><?= htmlspecialchars($claim['email']) ?></td></tr>
            <tr><td class="text-muted">Position:</td><td><?= htmlspecialchars($claim['position'] ?? 'N/A') ?></td></tr>
            <tr><td class="text-muted">Department:</td><td><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></td></tr>
            <tr><td class="text-muted">NRC:</td><td><?= htmlspecialchars($claim['nrc'] ?? 'N/A') ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6><i class="bi bi-cash-stack me-2"></i>Claim Summary</h6>
        <table class="table table-sm table-borderless mb-0">
            <tr><td class="text-muted" style="width:40%">Period:</td><td><?= date('F Y', mktime(0,0,0,$claim['month'],1,$claim['year'])) ?></td></tr>
            <tr><td class="text-muted">Submitted:</td><td><?= !empty($claim['submission_date']) ? date('M d, Y', strtotime($claim['submission_date'])) : 'N/A' ?></td></tr>
            <tr><td class="text-muted">Total Hours:</td><td><?= $claim['total_hours'] ?>h</td></tr>
            <tr><td class="text-muted">Hourly Rate:</td><td>MKW<?= number_format($claim['hourly_rate']) ?></td></tr>
            <tr><td class="text-muted">Airtime/Bundle:</td><td>MKW<?= number_format($airtime_rate) ?></td></tr>
            <tr><td class="text-muted">Total Amount:</td><td><strong class="text-success fs-5">MKW<?= number_format($claim['total_amount'], 2) ?></strong></td></tr>
            <tr><td class="text-muted">Bank:</td><td><?= htmlspecialchars($claim['claim_bank_name'] ?? 'N/A') ?></td></tr>
            <tr><td class="text-muted">Account No:</td><td><?= htmlspecialchars($claim['claim_account_number'] ?? 'N/A') ?></td></tr>
        </table>
    </div>
</div>

<!-- Work Statistics -->
<div class="row mb-3">
    <div class="col-md-3 text-center">
        <div class="border rounded p-2">
            <div class="text-muted small">Modules</div>
            <div class="fs-4 fw-bold text-primary"><?= $claim['total_modules'] ?></div>
        </div>
    </div>
    <div class="col-md-3 text-center">
        <div class="border rounded p-2">
            <div class="text-muted small">Students</div>
            <div class="fs-4 fw-bold text-info"><?= $claim['total_students'] ?></div>
        </div>
    </div>
    <div class="col-md-3 text-center">
        <div class="border rounded p-2">
            <div class="text-muted small">Marked</div>
            <div class="fs-4 fw-bold text-success"><?= $claim['total_assignments_marked'] ?></div>
        </div>
    </div>
    <div class="col-md-3 text-center">
        <div class="border rounded p-2">
            <div class="text-muted small">Content</div>
            <div class="fs-4 fw-bold text-warning"><?= $claim['total_content_uploaded'] ?></div>
        </div>
    </div>
</div>

<!-- Courses -->
<?php if (!empty($courses)): ?>
<h6 class="mt-3"><i class="bi bi-book me-2"></i>Courses Claimed</h6>
<table class="table table-sm table-bordered">
    <thead class="table-light">
        <tr><th>Course</th><th class="text-center">Hours</th><th class="text-center">Students</th><th class="text-center">Assignments</th><th class="text-center">Content</th></tr>
    </thead>
    <tbody>
        <?php foreach ($courses as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['course_name'] ?? '') ?></td>
            <td class="text-center"><?= $c['hours'] ?? '-' ?></td>
            <td class="text-center"><?= $c['students'] ?? 0 ?></td>
            <td class="text-center"><?= $c['assignments'] ?? 0 ?></td>
            <td class="text-center"><?= $c['content'] ?? 0 ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Approval Workflow Status -->
<h6 class="mt-3"><i class="bi bi-diagram-3 me-2"></i>Approval Workflow</h6>
<div class="d-flex justify-content-between align-items-center mb-3 p-3 border rounded bg-light">
    <div class="text-center">
        <div class="small text-muted mb-1">ODL Coordinator</div>
        <?= $odl_badges[$claim['odl_approval_status'] ?? 'pending'] ?? '<span class="badge bg-secondary">Unknown</span>' ?>
    </div>
    <i class="bi bi-arrow-right text-muted"></i>
    <div class="text-center">
        <div class="small text-muted mb-1">Dean</div>
        <?php if (!empty($claim['dean_approval_status'])): ?>
            <?= $dean_badges[$claim['dean_approval_status']] ?? '<span class="badge bg-secondary">N/A</span>' ?>
        <?php else: ?>
            <span class="badge bg-secondary">Not Required</span>
        <?php endif; ?>
    </div>
    <i class="bi bi-arrow-right text-muted"></i>
    <div class="text-center">
        <div class="small text-muted mb-1">Finance</div>
        <?= $status_badges[$claim['status']] ?? '<span class="badge bg-secondary">Unknown</span>' ?>
    </div>
</div>

<!-- Signatures -->
<h6 class="mt-3"><i class="bi bi-pen me-2"></i>Signatures</h6>
<div class="row">
    <!-- Lecturer Signature -->
    <div class="col-md-6 mb-3">
        <div class="border rounded p-2 text-center">
            <div class="small text-muted mb-1">Lecturer Signature</div>
            <?php if (!empty($claim['signature_path']) && file_exists('../uploads/signatures/' . $claim['signature_path'])): ?>
                <img src="../uploads/signatures/<?= htmlspecialchars($claim['signature_path']) ?>" style="max-height:60px;" class="border">
            <?php else: ?>
                <span class="text-muted small">Not available</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- ODL Signature -->
    <?php
    $odl_sig_col = 'odl_signature_path';
    $has_odl_sig = isset($claim[$odl_sig_col]) && !empty($claim[$odl_sig_col]);
    ?>
    <div class="col-md-6 mb-3">
        <div class="border rounded p-2 text-center">
            <div class="small text-muted mb-1">ODL Coordinator Signature</div>
            <?php if ($has_odl_sig && file_exists('../' . $claim[$odl_sig_col])): ?>
                <img src="../<?= htmlspecialchars($claim[$odl_sig_col]) ?>" style="max-height:60px;" class="border">
            <?php else: ?>
                <span class="text-muted small">Pending</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Dean Signature -->
    <?php
    $dean_sig_col = 'dean_signature_path';
    $has_dean_sig = isset($claim[$dean_sig_col]) && !empty($claim[$dean_sig_col]);
    ?>
    <?php if (!empty($claim['dean_approval_status'])): ?>
    <div class="col-md-6 mb-3">
        <div class="border rounded p-2 text-center">
            <div class="small text-muted mb-1">Dean Signature</div>
            <?php if ($has_dean_sig && file_exists('../' . $claim[$dean_sig_col])): ?>
                <img src="../<?= htmlspecialchars($claim[$dean_sig_col]) ?>" style="max-height:60px;" class="border">
            <?php else: ?>
                <span class="text-muted small">Pending</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <!-- Finance Signature -->
    <?php
    $fin_sig_col = 'finance_signature_path';
    $has_fin_sig = isset($claim[$fin_sig_col]) && !empty($claim[$fin_sig_col]);
    ?>
    <div class="col-md-6 mb-3">
        <div class="border rounded p-2 text-center">
            <div class="small text-muted mb-1">Finance Signature</div>
            <?php if ($has_fin_sig && file_exists('../' . $claim[$fin_sig_col])): ?>
                <img src="../<?= htmlspecialchars($claim[$fin_sig_col]) ?>" style="max-height:60px;" class="border">
            <?php else: ?>
                <span class="text-muted small">Pending</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Additional Notes -->
<?php if (!empty($claim['additional_notes'])): ?>
<h6 class="mt-3"><i class="bi bi-card-text me-2"></i>Additional Notes</h6>
<div class="border rounded p-3 bg-light">
    <?= nl2br(htmlspecialchars($claim['additional_notes'])) ?>
</div>
<?php endif; ?>

<!-- ODL Remarks -->
<?php if (!empty($claim['odl_remarks'])): ?>
<h6 class="mt-3"><i class="bi bi-info-circle me-2"></i>ODL Coordinator Remarks</h6>
<div class="border rounded p-3 bg-warning bg-opacity-10">
    <?= nl2br(htmlspecialchars($claim['odl_remarks'])) ?>
</div>
<?php endif; ?>

<!-- Dean Remarks -->
<?php if (!empty($claim['dean_remarks'])): ?>
<h6 class="mt-3"><i class="bi bi-info-circle me-2"></i>Dean Remarks</h6>
<div class="border rounded p-3 bg-info bg-opacity-10">
    <?= nl2br(htmlspecialchars($claim['dean_remarks'])) ?>
</div>
<?php endif; ?>
