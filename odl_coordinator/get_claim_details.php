<?php
/**
 * Get Claim Details - AJAX endpoint for ODL Coordinator
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$request_id = (int)($_GET['id'] ?? 0);

if (!$request_id) {
    echo '<div class="alert alert-danger">Invalid request ID</div>';
    exit;
}

// Get claim details
$stmt = $conn->prepare("
    SELECT r.*, l.full_name, l.email, l.department, l.position, l.phone, l.profile_picture,
           COALESCE(r.bank_name, l.bank_name) as claim_bank_name,
           COALESCE(r.account_number, l.account_number) as claim_account_number
    FROM lecturer_finance_requests r
    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
    WHERE r.request_id = ?
");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$claim = $stmt->get_result()->fetch_assoc();

if (!$claim) {
    echo '<div class="alert alert-danger">Claim not found</div>';
    exit;
}

// Parse courses data
$courses = [];
if (!empty($claim['courses_data'])) {
    $courses = json_decode($claim['courses_data'], true) ?: [];
}

// Check ODL approval column
$col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
$has_odl_column = $col_check && $col_check->num_rows > 0;
$current_status = $has_odl_column ? ($claim['odl_approval_status'] ?? 'pending') : $claim['status'];

$status_class = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'returned' => 'secondary',
    'paid' => 'info'
][$current_status] ?? 'secondary';
?>

<div class="row">
    <!-- Lecturer Info -->
    <div class="col-md-4">
        <div class="text-center mb-3">
            <?php if (!empty($claim['profile_picture']) && file_exists('../uploads/profiles/' . $claim['profile_picture'])): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($claim['profile_picture']) ?>" class="rounded-circle" style="width: 80px; height: 80px; object-fit: cover;">
            <?php else: ?>
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center mx-auto" style="width: 80px; height: 80px; font-size: 32px; color: white;">
                    <?= strtoupper(substr($claim['full_name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <h5 class="mt-2 mb-1"><?= htmlspecialchars($claim['full_name']) ?></h5>
            <p class="text-muted mb-0"><?= htmlspecialchars($claim['position'] ?? 'Lecturer') ?></p>
        </div>
        
        <ul class="list-group list-group-flush small">
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Department</span>
                <strong><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Email</span>
                <strong><?= htmlspecialchars($claim['email']) ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Phone</span>
                <strong><?= htmlspecialchars($claim['phone'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Bank</span>
                <strong><?= htmlspecialchars($claim['claim_bank_name'] ?? 'N/A') ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between">
                <span class="text-muted">Account No</span>
                <strong><?= htmlspecialchars($claim['claim_account_number'] ?? 'N/A') ?></strong>
            </li>
        </ul>
    </div>
    
    <!-- Claim Details -->
    <div class="col-md-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Claim #<?= $request_id ?></h6>
            <span class="badge bg-<?= $status_class ?>"><?= ucfirst($current_status) ?></span>
        </div>
        
        <div class="row g-3 mb-3">
            <div class="col-6">
                <div class="border rounded p-3">
                    <small class="text-muted">Period</small>
                    <div class="h5 mb-0"><?= date('F Y', mktime(0,0,0,$claim['month'],1,$claim['year'])) ?></div>
                </div>
            </div>
            <div class="col-6">
                <div class="border rounded p-3">
                    <small class="text-muted">Submission Date</small>
                    <div class="h5 mb-0"><?= date('M j, Y', strtotime($claim['request_date'] ?? $claim['submission_date'])) ?></div>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-3">
            <div class="col-4">
                <div class="border rounded p-3 text-center">
                    <small class="text-muted">Total Hours</small>
                    <div class="h4 mb-0 text-primary"><?= number_format($claim['total_hours'], 1) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded p-3 text-center">
                    <small class="text-muted">Hourly Rate</small>
                    <div class="h4 mb-0 text-info">MKW<?= number_format($claim['hourly_rate']) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded p-3 text-center bg-success bg-opacity-10">
                    <small class="text-muted">Total Amount</small>
                    <div class="h4 mb-0 text-success">MKW<?= number_format($claim['total_amount']) ?></div>
                </div>
            </div>
        </div>
        
        <!-- Courses Breakdown -->
        <?php if (!empty($courses)): ?>
        <h6 class="mt-4"><i class="bi bi-book me-2"></i>Courses Included</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Course</th>
                        <th class="text-center">Students</th>
                        <th class="text-center">Assignments</th>
                        <th class="text-center">Content</th>
                        <th class="text-center">Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><?= htmlspecialchars($course['course_name'] ?? 'N/A') ?></td>
                        <td class="text-center"><?= $course['students'] ?? 0 ?></td>
                        <td class="text-center"><?= $course['assignments'] ?? 0 ?></td>
                        <td class="text-center"><?= $course['content'] ?? 0 ?></td>
                        <td class="text-center"><?= number_format($course['hours'] ?? 0, 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th>Total</th>
                        <th class="text-center"><?= $claim['total_students'] ?? 0 ?></th>
                        <th class="text-center"><?= $claim['total_assignments_marked'] ?? 0 ?></th>
                        <th class="text-center"><?= $claim['total_content_uploaded'] ?? 0 ?></th>
                        <th class="text-center"><?= number_format($claim['total_hours'] ?? 0, 1) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Signature -->
        <?php if (!empty($claim['signature_path']) && file_exists('../uploads/signatures/' . $claim['signature_path'])): ?>
        <h6 class="mt-4"><i class="bi bi-pen me-2"></i>Lecturer Signature</h6>
        <div class="border rounded p-2 bg-white">
            <img src="../uploads/signatures/<?= htmlspecialchars($claim['signature_path']) ?>" alt="Signature" style="max-height: 80px;">
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
        <h6 class="mt-4"><i class="bi bi-pen me-2"></i>ODL Coordinator Signature</h6>
        <div class="border rounded p-2 bg-white d-flex align-items-end gap-3">
            <img src="<?= htmlspecialchars($odl_sig_src) ?>" alt="ODL Coordinator Signature" style="max-height: 80px;">
            <?php if (!empty($claim['odl_signed_at'])): ?>
            <small class="text-muted">Signed: <?= date('M j, Y g:i A', strtotime($claim['odl_signed_at'])) ?></small>
            <?php endif; ?>
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
        <h6 class="mt-4"><i class="bi bi-pen me-2"></i>Dean Signature</h6>
        <div class="border rounded p-2 bg-white d-flex align-items-end gap-3">
            <img src="<?= htmlspecialchars($dean_sig_src) ?>" alt="Dean Signature" style="max-height: 80px;">
            <?php if (!empty($claim['dean_signed_at'])): ?>
            <small class="text-muted">Signed: <?= date('M j, Y g:i A', strtotime($claim['dean_signed_at'])) ?></small>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Notes -->
        <?php if (!empty($claim['additional_notes'])): ?>
        <h6 class="mt-4"><i class="bi bi-chat-left-text me-2"></i>Additional Notes</h6>
        <div class="border rounded p-3 bg-light">
            <?= nl2br(htmlspecialchars($claim['additional_notes'])) ?>
        </div>
        <?php endif; ?>
        
        <!-- ODL Remarks -->
        <?php if ($has_odl_column && !empty($claim['odl_remarks'])): ?>
        <h6 class="mt-4"><i class="bi bi-info-circle me-2"></i>ODL Coordinator Remarks</h6>
        <div class="border rounded p-3 bg-warning bg-opacity-10">
            <?= nl2br(htmlspecialchars($claim['odl_remarks'])) ?>
        </div>
        <?php endif; ?>
        
        <!-- Approve with Signature (only if pending) -->
        <?php if ($current_status === 'pending'): ?>
        <div class="mt-4 p-3 border rounded bg-light" id="odlApprovalSection_<?= $request_id ?>">
            <h6 class="mb-3"><i class="bi bi-check-circle me-2 text-success"></i>Approve & Sign</h6>
            <div class="mb-3">
                <label class="form-label fw-bold">Draw Your Signature</label>
                <div class="border rounded bg-white" style="position:relative;">
                    <canvas id="odlSigCanvas_<?= $request_id ?>" width="400" height="120" style="width:100%;height:120px;cursor:crosshair;"></canvas>
                </div>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearOdlCanvas_<?= $request_id ?>()"><i class="bi bi-eraser me-1"></i>Clear</button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <textarea class="form-control" id="odlRemarks_<?= $request_id ?>" rows="2" placeholder="Add remarks..."></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success" onclick="submitOdlApproval_<?= $request_id ?>()">
                    <i class="bi bi-check-circle me-1"></i> Approve & Sign
                </button>
                <button type="button" class="btn btn-outline-danger" onclick="submitOdlReject_<?= $request_id ?>()">
                    <i class="bi bi-x-circle me-1"></i> Reject
                </button>
            </div>
            <div id="odlApprovalMsg_<?= $request_id ?>" class="mt-2"></div>
        </div>
        <script>
        (function() {
            var rid = <?= $request_id ?>;
            var canvas = document.getElementById('odlSigCanvas_' + rid);
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

            window['clearOdlCanvas_' + rid] = function() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            };

            function isCanvasBlank() {
                var blank = document.createElement('canvas');
                blank.width = canvas.width; blank.height = canvas.height;
                return canvas.toDataURL() === blank.toDataURL();
            }

            window['submitOdlApproval_' + rid] = function() {
                if (isCanvasBlank()) { alert('Please draw your signature before approving.'); return; }
                var sig = canvas.toDataURL('image/png');
                var remarks = document.getElementById('odlRemarks_' + rid).value;
                fetch('submit_approval.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request_id: rid, role: 'odl', signature: sig, remarks: remarks })
                }).then(function(r) { return r.json(); }).then(function(data) {
                    var msg = document.getElementById('odlApprovalMsg_' + rid);
                    if (data.success) {
                        msg.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i> ' + (data.message || 'Approved successfully!') + '</div>';
                        document.getElementById('odlApprovalSection_' + rid).querySelector('.d-flex.gap-2').style.display = 'none';
                        if (typeof loadRequests === 'function') loadRequests();
                    } else {
                        msg.innerHTML = '<div class="alert alert-danger py-2">' + (data.message || 'Approval failed.') + '</div>';
                    }
                }).catch(function() {
                    document.getElementById('odlApprovalMsg_' + rid).innerHTML = '<div class="alert alert-danger py-2">Error submitting approval.</div>';
                });
            };

            window['submitOdlReject_' + rid] = function() {
                var remarks = document.getElementById('odlRemarks_' + rid).value;
                if (!remarks) { alert('Please provide remarks for rejection.'); return; }
                fetch('submit_approval.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request_id: rid, role: 'odl', action: 'reject', remarks: remarks })
                }).then(function(r) { return r.json(); }).then(function(data) {
                    var msg = document.getElementById('odlApprovalMsg_' + rid);
                    if (data.success) {
                        msg.innerHTML = '<div class="alert alert-warning py-2"><i class="bi bi-x-circle me-1"></i> Claim rejected.</div>';
                        document.getElementById('odlApprovalSection_' + rid).querySelector('.d-flex.gap-2').style.display = 'none';
                        if (typeof loadRequests === 'function') loadRequests();
                    } else {
                        msg.innerHTML = '<div class="alert alert-danger py-2">' + (data.message || 'Rejection failed.') + '</div>';
                    }
                }).catch(function() {
                    document.getElementById('odlApprovalMsg_' + rid).innerHTML = '<div class="alert alert-danger py-2">Error submitting rejection.</div>';
                });
            };
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
