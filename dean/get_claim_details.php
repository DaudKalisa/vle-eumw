<?php
/**
 * Dean Portal - Get Claim Details (AJAX)
 * Returns HTML for claim details modal
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$claim_id = (int)($_GET['id'] ?? 0);

if ($claim_id <= 0) {
    echo '<div class="alert alert-danger">Invalid claim ID</div>';
    exit;
}

// Get claim details
$stmt = $conn->prepare("
    SELECT r.*, l.full_name, l.email, l.department, l.phone, l.profile_picture,
           COALESCE(r.bank_name, l.bank_name) as claim_bank_name,
           COALESCE(r.account_number, l.account_number) as claim_account_number
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
            <tr>
                <td class="text-muted">Total Amount:</td>
                <td><strong class="text-success fs-5">MKW <?= number_format($claim['total_amount']) ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Bank:</td>
                <td><strong><?= htmlspecialchars($claim['claim_bank_name'] ?? 'N/A') ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Account No:</td>
                <td><strong><?= htmlspecialchars($claim['claim_account_number'] ?? 'N/A') ?></strong></td>
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
        <h6 class="text-muted mb-2">Approval Status</h6>
        <div class="row g-3">
            <div class="col-md-6">
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
            <div class="col-md-6">
                <div class="p-3 border rounded">
                    <strong>Dean:</strong>
                    <?php
                    $dean_status = $claim['dean_approval_status'] ?? 'pending';
                    $dean_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$dean_status] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?= $dean_badge ?>"><?= ucfirst($dean_status) ?></span>
                    <?php if (!empty($claim['dean_remarks'])): ?>
                    <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($claim['dean_remarks']) ?></p>
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

<?php if (!empty($claim['signature_path']) && file_exists('../' . $claim['signature_path'])): ?>
<div class="row">
    <div class="col-12">
        <h6 class="text-muted mb-2">Lecturer Signature</h6>
        <img src="../<?= htmlspecialchars($claim['signature_path']) ?>" alt="Signature" class="img-fluid border rounded" style="max-height: 100px;">
    </div>
</div>
<?php endif; ?>

<!-- ODL Coordinator Signature -->
<?php 
$odl_sig = $claim['odl_signature_path'] ?? '';
$odl_sig_exists = !empty($odl_sig) && (file_exists('../' . $odl_sig) || file_exists('../uploads/signatures/' . $odl_sig));
$odl_sig_src = '';
if ($odl_sig_exists) {
    $odl_sig_src = file_exists('../' . $odl_sig) ? '../' . $odl_sig : '../uploads/signatures/' . $odl_sig;
}
?>
<?php if ($odl_sig_exists): ?>
<div class="row mt-3">
    <div class="col-12">
        <h6 class="text-muted mb-2"><i class="bi bi-pen me-1"></i>ODL Coordinator Signature</h6>
        <div class="d-flex align-items-end gap-3">
            <img src="<?= htmlspecialchars($odl_sig_src) ?>" alt="ODL Coordinator Signature" class="img-fluid border rounded" style="max-height: 100px;">
            <?php if (!empty($claim['odl_signed_at'])): ?>
            <small class="text-muted">Signed: <?= date('M j, Y g:i A', strtotime($claim['odl_signed_at'])) ?></small>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Dean Signature -->
<?php 
$dean_sig = $claim['dean_signature_path'] ?? '';
$dean_sig_exists = !empty($dean_sig) && (file_exists('../' . $dean_sig) || file_exists('../uploads/signatures/' . $dean_sig));
$dean_sig_src = '';
if ($dean_sig_exists) {
    $dean_sig_src = file_exists('../' . $dean_sig) ? '../' . $dean_sig : '../uploads/signatures/' . $dean_sig;
}
?>
<?php if ($dean_sig_exists): ?>
<div class="row mt-3">
    <div class="col-12">
        <h6 class="text-muted mb-2"><i class="bi bi-pen me-1"></i>Dean Signature</h6>
        <div class="d-flex align-items-end gap-3">
            <img src="<?= htmlspecialchars($dean_sig_src) ?>" alt="Dean Signature" class="img-fluid border rounded" style="max-height: 100px;">
            <?php if (!empty($claim['dean_signed_at'])): ?>
            <small class="text-muted">Signed: <?= date('M j, Y g:i A', strtotime($claim['dean_signed_at'])) ?></small>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approve with Signature (only if dean approval is pending and ODL has approved/forwarded) -->
<?php
$dean_can_approve = (
    (($claim['dean_approval_status'] ?? '') === 'pending' || empty($claim['dean_approval_status'])) &&
    in_array($claim['odl_approval_status'] ?? '', ['approved', 'forwarded_to_dean'])
);
?>
<?php if ($dean_can_approve): ?>
<div class="mt-4 p-3 border rounded bg-light" id="deanApprovalSection_<?= $claim['request_id'] ?>">
    <h6 class="mb-3"><i class="bi bi-check-circle me-2 text-success"></i>Approve & Sign</h6>
    <div class="mb-3">
        <label class="form-label fw-bold">Draw Your Signature</label>
        <div class="border rounded bg-white" style="position:relative;">
            <canvas id="deanSigCanvas_<?= $claim['request_id'] ?>" width="400" height="120" style="width:100%;height:120px;cursor:crosshair;"></canvas>
        </div>
        <div class="mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearDeanCanvas_<?= $claim['request_id'] ?>()"><i class="bi bi-eraser me-1"></i>Clear</button>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Remarks (optional)</label>
        <textarea class="form-control" id="deanRemarks_<?= $claim['request_id'] ?>" rows="2" placeholder="Add remarks..."></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" onclick="submitDeanApproval_<?= $claim['request_id'] ?>()">
            <i class="bi bi-check-circle me-1"></i> Approve & Sign
        </button>
        <button type="button" class="btn btn-outline-danger" onclick="submitDeanReject_<?= $claim['request_id'] ?>()">
            <i class="bi bi-x-circle me-1"></i> Reject
        </button>
    </div>
    <div id="deanApprovalMsg_<?= $claim['request_id'] ?>" class="mt-2"></div>
</div>
<script>
(function() {
    var rid = <?= $claim['request_id'] ?>;
    var canvas = document.getElementById('deanSigCanvas_' + rid);
    var ctx = canvas.getContext('2d');
    var drawing = false;
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var clientX = e.touches ? e.touches[0].clientX : e.clientX;
        var clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return { x: (clientX - rect.left) * (canvas.width / rect.width), y: (clientY - rect.top) * (canvas.height / rect.height) };
    }
    canvas.addEventListener('mousedown', function(e) { drawing = true; ctx.beginPath(); var p = getPos(e); ctx.moveTo(p.x, p.y); });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; ctx.beginPath(); var p = getPos(e); ctx.moveTo(p.x, p.y); });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    canvas.addEventListener('touchend', function() { drawing = false; });

    window['clearDeanCanvas_' + rid] = function() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    };

    function isCanvasBlank() {
        var blank = document.createElement('canvas');
        blank.width = canvas.width; blank.height = canvas.height;
        return canvas.toDataURL() === blank.toDataURL();
    }

    window['submitDeanApproval_' + rid] = function() {
        if (isCanvasBlank()) { alert('Please draw your signature before approving.'); return; }
        var sig = canvas.toDataURL('image/png');
        var remarks = document.getElementById('deanRemarks_' + rid).value;
        fetch('../odl_coordinator/submit_approval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: rid, role: 'dean', signature: sig, remarks: remarks })
        }).then(function(r) { return r.json(); }).then(function(data) {
            var msg = document.getElementById('deanApprovalMsg_' + rid);
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i> ' + (data.message || 'Approved successfully!') + '</div>';
                document.getElementById('deanApprovalSection_' + rid).querySelector('.d-flex.gap-2').style.display = 'none';
                if (typeof loadRequests === 'function') loadRequests();
            } else {
                msg.innerHTML = '<div class="alert alert-danger py-2">' + (data.message || 'Approval failed.') + '</div>';
            }
        }).catch(function() {
            document.getElementById('deanApprovalMsg_' + rid).innerHTML = '<div class="alert alert-danger py-2">Error submitting approval.</div>';
        });
    };

    window['submitDeanReject_' + rid] = function() {
        var remarks = document.getElementById('deanRemarks_' + rid).value;
        if (!remarks) { alert('Please provide remarks for rejection.'); return; }
        fetch('submit_approval.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'request_id=' + rid + '&action=reject&remarks=' + encodeURIComponent(remarks)
        }).then(function(r) { return r.json(); }).then(function(data) {
            var msg = document.getElementById('deanApprovalMsg_' + rid);
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-warning py-2"><i class="bi bi-x-circle me-1"></i> Claim rejected.</div>';
                document.getElementById('deanApprovalSection_' + rid).querySelector('.d-flex.gap-2').style.display = 'none';
                if (typeof loadRequests === 'function') loadRequests();
            } else {
                msg.innerHTML = '<div class="alert alert-danger py-2">' + (data.message || 'Rejection failed.') + '</div>';
            }
        }).catch(function() {
            document.getElementById('deanApprovalMsg_' + rid).innerHTML = '<div class="alert alert-danger py-2">Error submitting rejection.</div>';
        });
    };
})();
</script>
<?php endif; ?>

<div class="text-end mt-4">
    <a href="print_claim.php?id=<?= $claim['request_id'] ?>" class="btn btn-outline-primary" target="_blank">
        <i class="bi bi-printer me-1"></i> Print Claim Form
    </a>
</div>
