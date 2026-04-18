<?php
/**
 * Finance Portal - Get Claim Details (AJAX)
 * Returns HTML for claim details modal with all signatures
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$claim_id = (int)($_GET['id'] ?? 0);

if ($claim_id <= 0) {
    echo '<div class="alert alert-danger">Invalid claim ID</div>';
    exit;
}

// Get claim details
$stmt = $conn->prepare("
    SELECT r.*, l.full_name, l.email, l.department, l.phone, l.position, l.profile_picture
    FROM lecturer_finance_requests r
    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
    WHERE r.request_id = ?
");
$stmt->bind_param("i", $claim_id);
$stmt->execute();
$result = $stmt->get_result();
$claim = $result->fetch_assoc();

if (!$claim) {
    echo '<div class="alert alert-danger">Claim not found</div>';
    exit;
}

// Decode courses data
$courses = [];
if (!empty($claim['courses_data'])) {
    $courses = json_decode($claim['courses_data'], true) ?: [];
}
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Lecturer Information</h6>
        <div class="d-flex align-items-center mb-3">
            <?php if (!empty($claim['profile_picture']) && file_exists('../uploads/profiles/' . $claim['profile_picture'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($claim['profile_picture']) ?>" class="rounded-circle me-3" style="width: 60px; height: 60px; object-fit: cover;">
            <?php else: ?>
                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px; font-weight: 700; font-size: 1.5rem;">
                    <?= strtoupper(substr($claim['full_name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div>
                <h5 class="mb-0"><?= htmlspecialchars($claim['full_name']) ?></h5>
                <div class="text-muted"><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></div>
                <small class="text-muted"><?= htmlspecialchars($claim['email']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <h6 class="text-muted mb-2">Claim Summary</h6>
        <table class="table table-sm">
            <tr>
                <td class="text-muted">Period:</td>
                <td><strong><?= date('F Y', mktime(0, 0, 0, $claim['month'], 1, $claim['year'])) ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Total Hours:</td>
                <td><strong><?= number_format($claim['total_hours'], 1) ?> hours</strong></td>
            </tr>
            <tr>
                <td class="text-muted">Hourly Rate:</td>
                <td>MKW <?= number_format($claim['hourly_rate']) ?></td>
            </tr>
            <?php if (!empty($claim['revised_hourly_rate'])): ?>
            <tr>
                <td class="text-muted">Revised Rate:</td>
                <td><strong class="text-warning">MKW <?= number_format($claim['revised_hourly_rate']) ?></strong></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="text-muted">Total Amount:</td>
                <td><strong class="text-success fs-5">MKW <?= number_format($claim['total_amount']) ?></strong></td>
            </tr>
        </table>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <h6 class="text-muted mb-2">Work Statistics</h6>
        <div class="row g-3">
            <div class="col-md-3">
                <div class="p-3 bg-primary bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-primary"><?= $claim['total_modules'] ?? 0 ?></div>
                    <small class="text-muted">Modules</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-success bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-success"><?= $claim['total_students'] ?? 0 ?></div>
                    <small class="text-muted">Students</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-info bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-info"><?= $claim['total_assignments_marked'] ?? 0 ?></div>
                    <small class="text-muted">Assignments Marked</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-warning bg-opacity-10 rounded text-center">
                    <div class="fs-4 fw-bold text-warning"><?= $claim['total_content_uploaded'] ?? 0 ?></div>
                    <small class="text-muted">Content Uploaded</small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($courses)): ?>
<div class="row mb-4">
    <div class="col-12">
        <h6 class="text-muted mb-2">Courses Claimed</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Course</th>
                        <th>Students</th>
                        <th>Assignments</th>
                        <th>Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><?= htmlspecialchars($course['course_name'] ?? $course['name'] ?? 'N/A') ?></td>
                        <td><?= $course['students'] ?? $course['student_count'] ?? 0 ?></td>
                        <td><?= $course['assignments'] ?? $course['assignments_marked'] ?? 0 ?></td>
                        <td><?= number_format($course['hours'] ?? 0, 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-12">
        <h6 class="text-muted mb-2">Approval Workflow Status</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="p-3 border rounded">
                    <strong>ODL Coordinator:</strong>
                    <?php
                    $odl_status = $claim['odl_approval_status'] ?? 'pending';
                    $odl_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'forwarded_to_dean' => 'info'][$odl_status] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?= $odl_badge ?>"><?= ucfirst(str_replace('_', ' ', $odl_status)) ?></span>
                    <?php if (!empty($claim['odl_remarks'])): ?>
                    <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($claim['odl_remarks']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 border rounded">
                    <strong>Dean:</strong>
                    <?php
                    $dean_status = $claim['dean_approval_status'] ?? '';
                    if (empty($dean_status)) {
                        echo '<span class="badge bg-secondary">N/A</span>';
                    } else {
                        $dean_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$dean_status] ?? 'secondary';
                        echo '<span class="badge bg-' . $dean_badge . '">' . ucfirst($dean_status) . '</span>';
                    }
                    ?>
                    <?php if (!empty($claim['dean_remarks'])): ?>
                    <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($claim['dean_remarks']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 border rounded">
                    <strong>Finance:</strong>
                    <?php
                    $fin_status = $claim['status'] ?? 'pending';
                    $fin_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'paid' => 'info'][$fin_status] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?= $fin_badge ?>"><?= ucfirst($fin_status) ?></span>
                    <?php if (!empty($claim['finance_remarks'])): ?>
                    <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($claim['finance_remarks']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($claim['additional_notes'])): ?>
<div class="row mb-3">
    <div class="col-12">
        <h6 class="text-muted mb-2">Additional Notes</h6>
        <div class="p-3 bg-light rounded">
            <?= nl2br(htmlspecialchars($claim['additional_notes'])) ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Signatures Section -->
<div class="row mb-3">
    <div class="col-12">
        <h6 class="text-muted mb-2"><i class="bi bi-pen me-1"></i>Signatures</h6>
        <div class="row g-3">
            <!-- Lecturer Signature -->
            <div class="col-md-4">
                <div class="border rounded p-3 text-center">
                    <small class="text-muted d-block mb-2">Lecturer</small>
                    <?php 
                    $lec_sig = $claim['signature_path'] ?? '';
                    $lec_sig_exists = !empty($lec_sig) && (file_exists('../' . $lec_sig) || file_exists('../uploads/signatures/' . $lec_sig));
                    if ($lec_sig_exists):
                        $lec_sig_src = file_exists('../' . $lec_sig) ? '../' . $lec_sig : '../uploads/signatures/' . $lec_sig;
                    ?>
                    <img src="<?= htmlspecialchars($lec_sig_src) ?>" alt="Lecturer Signature" class="img-fluid border rounded" style="max-height: 80px;">
                    <?php else: ?>
                    <div class="text-muted py-3"><i class="bi bi-dash-lg"></i><br><small>Not signed</small></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ODL Coordinator Signature -->
            <div class="col-md-4">
                <div class="border rounded p-3 text-center">
                    <small class="text-muted d-block mb-2">ODL Coordinator</small>
                    <?php 
                    $odl_sig = $claim['odl_signature_path'] ?? '';
                    $odl_sig_exists = !empty($odl_sig) && (file_exists('../' . $odl_sig) || file_exists('../uploads/signatures/' . $odl_sig));
                    if ($odl_sig_exists):
                        $odl_sig_src = file_exists('../' . $odl_sig) ? '../' . $odl_sig : '../uploads/signatures/' . $odl_sig;
                    ?>
                    <img src="<?= htmlspecialchars($odl_sig_src) ?>" alt="ODL Coordinator Signature" class="img-fluid border rounded" style="max-height: 80px;">
                    <?php if (!empty($claim['odl_signed_at'])): ?>
                    <br><small class="text-muted"><?= date('M j, Y g:i A', strtotime($claim['odl_signed_at'])) ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-muted py-3"><i class="bi bi-dash-lg"></i><br><small>Not signed</small></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dean Signature -->
            <div class="col-md-4">
                <div class="border rounded p-3 text-center">
                    <small class="text-muted d-block mb-2">Dean</small>
                    <?php 
                    $dean_sig = $claim['dean_signature_path'] ?? '';
                    $dean_sig_exists = !empty($dean_sig) && (file_exists('../' . $dean_sig) || file_exists('../uploads/signatures/' . $dean_sig));
                    if ($dean_sig_exists):
                        $dean_sig_src = file_exists('../' . $dean_sig) ? '../' . $dean_sig : '../uploads/signatures/' . $dean_sig;
                    ?>
                    <img src="<?= htmlspecialchars($dean_sig_src) ?>" alt="Dean Signature" class="img-fluid border rounded" style="max-height: 80px;">
                    <?php if (!empty($claim['dean_signed_at'])): ?>
                    <br><small class="text-muted"><?= date('M j, Y g:i A', strtotime($claim['dean_signed_at'])) ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-muted py-3"><i class="bi bi-dash-lg"></i><br><small>Not signed</small></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Finance Signature (if approved) -->
        <?php 
        $fin_sig = $claim['finance_signature_path'] ?? '';
        $fin_sig_exists = !empty($fin_sig) && (file_exists('../' . $fin_sig) || file_exists('../uploads/signatures/' . $fin_sig));
        if ($fin_sig_exists):
            $fin_sig_src = file_exists('../' . $fin_sig) ? '../' . $fin_sig : '../uploads/signatures/' . $fin_sig;
        ?>
        <div class="mt-3">
            <div class="border rounded p-3 text-center" style="max-width: 33%;">
                <small class="text-muted d-block mb-2">Finance Officer</small>
                <img src="<?= htmlspecialchars($fin_sig_src) ?>" alt="Finance Signature" class="img-fluid border rounded" style="max-height: 80px;">
                <?php if (!empty($claim['finance_signed_at'])): ?>
                <br><small class="text-muted"><?= date('M j, Y g:i A', strtotime($claim['finance_signed_at'])) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Buttons -->
<div class="d-flex justify-content-between align-items-center mt-4">
    <a href="finance_request_pdf.php?id=<?= $claim['request_id'] ?>" class="btn btn-outline-primary" target="_blank">
        <i class="bi bi-file-earmark-pdf me-1"></i> View PDF
    </a>
    <div>
    <?php
    // Determine if finance can approve this claim
    $can_finance_approve = (
        ($claim['status'] === 'pending') && (
            (($claim['odl_approval_status'] ?? '') === 'approved' && empty($claim['dean_approval_status'])) ||
            (in_array($claim['odl_approval_status'] ?? '', ['approved', 'forwarded_to_dean']) && ($claim['dean_approval_status'] ?? '') === 'approved')
        )
    );
    ?>
    <?php if ($can_finance_approve): ?>
        <button type="button" class="btn btn-danger me-2" onclick="if(window.parent.openRejectModal) window.parent.openRejectModal(<?= $claim['request_id'] ?>); else openRejectModal(<?= $claim['request_id'] ?>);">
            <i class="bi bi-x-circle me-1"></i> Reject
        </button>
        <button type="button" class="btn btn-success" onclick="if(window.parent.openApproveModal) window.parent.openApproveModal(<?= $claim['request_id'] ?>); else openApproveModal(<?= $claim['request_id'] ?>);">
            <i class="bi bi-check-circle me-1"></i> Approve
        </button>
    <?php elseif ($claim['status'] === 'approved'): ?>
        <button type="button" class="btn btn-warning" onclick="if(window.parent.payAndPrint) window.parent.payAndPrint(<?= $claim['request_id'] ?>); else payAndPrint(<?= $claim['request_id'] ?>);">
            <i class="bi bi-cash-coin me-1"></i> Mark as Paid
        </button>
    <?php endif; ?>
    </div>
</div>
