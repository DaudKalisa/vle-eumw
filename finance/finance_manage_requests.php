<?php
// finance/finance_manage_requests.php - Manage lecturer finance requests (moved from admin)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();

// Fetch all finance requests
$stmt = $conn->query("SELECT r.*, l.full_name, l.email, l.position, l.department FROM lecturer_finance_requests r JOIN lecturers l ON r.lecturer_id = l.lecturer_id ORDER BY r.submission_date DESC");
$requests = $stmt->fetch_all(MYSQLI_ASSOC);
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Finance Requests - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'finance_manage_requests';
    $pageTitle = 'Lecturer Finance Requests';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-cash-coin me-2"></i>Lecturer Finance Requests</h1>
                    <p class="text-muted mb-0">Manage lecturer finance requests and payments</p>
                </div>
                <a href="lecturer_accounts.php" class="btn btn-vle-accent"><i class="bi bi-person-lines-fill me-2"></i>Lecturer Accounts</a>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-primary text-white d-flex flex-wrap justify-content-between align-items-center">
                <h5 class="mb-0">All Requests</h5>
                <form class="d-flex flex-wrap gap-2 align-items-center" id="filterForm" onsubmit="return false;">
                    <select class="form-select form-select-sm" name="status" id="statusFilter" style="width:auto;">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="ready_for_finance">Ready for Finance</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="paid">Paid</option>
                    </select>
                    <input type="number" class="form-control form-control-sm" name="year" id="yearFilter" placeholder="Year" min="2020" style="width:90px;">
                    <select class="form-select form-select-sm" name="month" id="monthFilter" style="width:auto;">
                        <option value="">All Months</option>
                        <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?php echo $m; ?>"><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <button class="btn btn-light btn-sm" type="button" onclick="loadRequests()"><i class="bi bi-search"></i> Filter</button>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="requestsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Lecturer</th>
                                <th>Amount</th>
                                <th>ODL Status</th>
                                <th>Dean Status</th>
                                <th>Finance Status</th>
                                <th>Workflow</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="requestsBody">
                            <!-- AJAX content here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        function getStatusBadge(status, statusType) {
            const badges = {
                'pending': 'warning',
                'approved': 'success',
                'rejected': 'danger',
                'returned': 'info',
                'forwarded_to_dean': 'info',
                'paid': 'success'
            };
            const color = badges[status] || 'secondary';
            const display = status ? status.replace('_', ' ').charAt(0).toUpperCase() + status.slice(1).replace('_', ' ') : 'Pending';
            return `<span class="badge bg-${color}">${display}</span>`;
        }

        function getActionButtons(req) {
            let buttons = `<button type="button" class="btn btn-sm btn-outline-info" onclick="viewClaimDetails(${req.request_id})" title="View Details"><i class="bi bi-eye"></i></button>`;
            buttons += ` <a href="finance_request_pdf.php?id=${req.request_id}" class="btn btn-sm btn-outline-primary" target="_blank" title="View PDF"><i class="bi bi-file-earmark-pdf"></i></a>`;
            
            if (window.userRole !== 'finance' && window.userRole !== 'admin') {
                return buttons;
            }

            // Finance can only approve if proper approvals are in place
            const canApprove = (
                (req.odl_approval_status === 'approved' && !req.dean_approval_status) ||
                (req.odl_approval_status === 'forwarded_to_dean' && req.dean_approval_status === 'approved') ||
                (req.odl_approval_status === 'approved' && req.dean_approval_status === 'approved')
            ) && req.status === 'pending';

            const canReject = req.status !== 'paid' && req.status !== 'rejected';
            const canMarkPaid = req.status === 'approved';

            // Can edit rates if pending or approved
            const canEditRates = req.status === 'pending' || req.status === 'approved';

            if (canApprove) {
                buttons += ` <button type="button" class="btn btn-sm btn-success" onclick="openApproveModal(${req.request_id})" title="Approve Request"><i class="bi bi-check-circle"></i> Approve</button>`;
            }
            if (canReject && req.status === 'pending') {
                buttons += ` <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(${req.request_id})" title="Reject Request"><i class="bi bi-x-circle"></i> Reject</button>`;
            }
            if (canEditRates) {
                buttons += ` <button type="button" class="btn btn-sm btn-outline-warning" onclick="openRateRevisionModal(${req.request_id})" title="Edit Rates"><i class="bi bi-pencil"></i> Edit Rates</button>`;
            }
            if (canMarkPaid) {
                buttons += ` <button type="button" class="btn btn-sm btn-warning" onclick="payAndPrint(${req.request_id})" title="Mark as Paid"><i class="bi bi-cash-coin"></i> Pay</button>`;
            }
            if (req.status === 'paid') {
                buttons += ` <a href="print_lecturer_payment.php?id=${req.request_id}" class="btn btn-sm btn-info" target="_blank" title="Print Receipt"><i class="bi bi-printer"></i> Receipt</a>`;
            }

            // Delete button - only for pending/rejected claims
            if (req.status === 'pending' || req.status === 'rejected') {
                buttons += ` <button type="button" class="btn btn-sm btn-outline-danger" onclick="openDeleteModal(${req.request_id})" title="Delete Request"><i class="bi bi-trash"></i> Delete</button>`;
            }

            return buttons;
        }

        function getWorkflowIndicator(req) {
            let workflow = '<small>';
            
            // ODL step
            if (!req.odl_approval_status || req.odl_approval_status === 'pending') {
                workflow += '<span class="badge bg-warning">ODL ⏳</span> ';
            } else if (req.odl_approval_status === 'approved') {
                workflow += '<span class="badge bg-success">ODL ✓</span> ';
            } else if (req.odl_approval_status === 'forwarded_to_dean') {
                workflow += '<span class="badge bg-info">ODL→Dean</span> ';
            } else {
                workflow += '<span class="badge bg-danger">ODL ✗</span> ';
            }

            // Dean step (if needed)
            if (req.odl_approval_status === 'forwarded_to_dean' || req.dean_approval_status) {
                if (!req.dean_approval_status || req.dean_approval_status === 'pending') {
                    workflow += '<span class="badge bg-warning">Dean ⏳</span> ';
                } else if (req.dean_approval_status === 'approved') {
                    workflow += '<span class="badge bg-success">Dean ✓</span> ';
                } else {
                    workflow += '<span class="badge bg-danger">Dean ✗</span> ';
                }
            }

            // Finance step
            if (req.status === 'pending') {
                workflow += '<span class="badge bg-warning">Finance ⏳</span>';
            } else if (req.status === 'approved') {
                workflow += '<span class="badge bg-success">Finance ✓</span>';
            } else if (req.status === 'paid') {
                workflow += '<span class="badge bg-success">Paid ✓</span>';
            } else {
                workflow += '<span class="badge bg-danger">Finance ✗</span>';
            }

            workflow += '</small>';
            return workflow;
        }

        // Expose user role to JS for action control
        window.userRole = <?php echo json_encode($user['role'] ?? ''); ?>;
        function loadRequests() {
            const status = document.getElementById('statusFilter').value;
            const year = document.getElementById('yearFilter').value;
            const month = document.getElementById('monthFilter').value;
            let url = '../finance/get_lecturer_finance.php?action=list';
            if(status) url += '&status=' + encodeURIComponent(status);
            if(year) url += '&year=' + encodeURIComponent(year);
            if(month) url += '&month=' + encodeURIComponent(month);
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('requestsBody');
                    tbody.innerHTML = '';
                    if(data.success && data.data.length) {
                        data.data.forEach(req => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${req.submission_date ? new Date(req.submission_date).toLocaleDateString() : ''}</td>
                                    <td>${req.lecturer_name || ''}<br><small>${req.lecturer_email || ''}</small></td>
                                    <td>MKW ${Number(req.total_amount || 0).toLocaleString()}</td>
                                    <td>${getStatusBadge(req.odl_approval_status, 'odl')}</td>
                                    <td>${getStatusBadge(req.dean_approval_status, 'dean')}</td>
                                    <td>${getStatusBadge(req.status, 'finance')}</td>
                                    <td>${getWorkflowIndicator(req)}</td>
                                    <td style="white-space: nowrap;">${getActionButtons(req)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No requests found.</td></tr>';
                    }
                })
                .catch(err => {
                    console.error('Error loading requests:', err);
                    document.getElementById('requestsBody').innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading requests</td></tr>';
                });
        }
        document.addEventListener('DOMContentLoaded', loadRequests);

        // Auto-set filter from URL param
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            const filter = urlParams.get('filter');
            if (filter === 'ready') {
                document.getElementById('statusFilter').value = 'ready_for_finance';
            }
        })();

        function viewClaimDetails(requestId) {
            document.getElementById('claimDetailsContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading claim details...</p></div>';
            var modal = new bootstrap.Modal(document.getElementById('claimDetailsModal'));
            modal.show();
            fetch('get_claim_details.php?id=' + requestId)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('claimDetailsContent').innerHTML = html;
                })
                .catch(err => {
                    document.getElementById('claimDetailsContent').innerHTML = '<div class="alert alert-danger">Error loading claim details.</div>';
                });
        }
        </script>
        <!-- Rate Revision Modal -->
        <div class="modal fade" id="rateRevisionModal" tabindex="-1" aria-labelledby="rateRevisionModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="rateRevisionModalLabel"><i class="bi bi-pencil me-2"></i>Revise Rates</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label for="revisedHourlyRate" class="form-label">Hourly Rate (MKW)</label>
                  <input type="number" class="form-control" id="revisedHourlyRate" placeholder="Enter revised hourly rate" step="100" min="0">
                </div>
                <div class="mb-3">
                  <label for="revisedAirtimeRate" class="form-label">Airtime Rate (MKW)</label>
                  <input type="number" class="form-control" id="revisedAirtimeRate" placeholder="Enter revised airtime rate" step="100" min="0">
                </div>
                <div class="mb-3">
                  <label for="rateRevisionReason" class="form-label">Reason for Revision</label>
                  <textarea class="form-control" id="rateRevisionReason" rows="3" placeholder="Explain why rates are being revised..."></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="confirmRateRevision()">Save Revised Rates</button>
              </div>
            </div>
          </div>
        </div>
        <!-- Approve Confirmation Modal -->
        <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="approveModalLabel"><i class="bi bi-check-circle me-2"></i>Confirm Approval</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Are you sure you want to approve this lecturer finance request?</p>
                <p class="text-muted"><small>Note: This will mark the request as ready for payment.</small></p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmApprove()">Yes, Approve</button>
              </div>
            </div>
          </div>
        </div>
        <!-- Reject Confirmation Modal -->
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectModalLabel"><i class="bi bi-x-circle me-2"></i>Reject Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Please provide reasons for rejecting this request:</p>
                <textarea id="rejectRemarks" class="form-control" rows="3" placeholder="Enter rejection reasons..."></textarea>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmReject()">Reject Request</button>
              </div>
            </div>
          </div>
        </div>
        <script>
            let approveRequestId = null;
            let rejectRequestId = null;
            let rateRevisionRequestId = null;
            let deleteRequestId = null;

            function openApproveModal(requestId) {
                approveRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('approveModal'));
                modal.show();
            }

            function openRejectModal(requestId) {
                rejectRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
                modal.show();
            }

            function openRateRevisionModal(requestId) {
                rateRevisionRequestId = requestId;
                // Clear previous values
                document.getElementById('revisedHourlyRate').value = '';
                document.getElementById('revisedAirtimeRate').value = '';
                document.getElementById('rateRevisionReason').value = '';
                var modal = new bootstrap.Modal(document.getElementById('rateRevisionModal'));
                modal.show();
            }

            function confirmApprove() {
                if(!approveRequestId) return;
                const formData = new URLSearchParams();
                formData.append('request_id', approveRequestId);
                formData.append('action', 'approve');
                fetch('lecturer_finance_action.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                }).then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadRequests();
                        alert('Request approved successfully!');
                    } else {
                        alert(data.message || 'Approval failed.');
                    }
                    approveRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('approveModal'));
                    if(modal) modal.hide();
                }).catch(err => {
                    console.error('Error:', err);
                    alert('Approval failed. Check console for errors.');
                    approveRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('approveModal'));
                    if(modal) modal.hide();
                });
            }

            function confirmReject() {
                if(!rejectRequestId) return;
                const remarks = document.getElementById('rejectRemarks').value;
                const formData = new URLSearchParams();
                formData.append('request_id', rejectRequestId);
                formData.append('action', 'reject');
                formData.append('remarks', remarks);
                fetch('lecturer_finance_action.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                }).then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadRequests();
                        alert('Request rejected successfully!');
                    } else {
                        alert(data.message || 'Rejection failed.');
                    }
                    rejectRequestId = null;
                    document.getElementById('rejectRemarks').value = '';
                    var modal = bootstrap.Modal.getInstance(document.getElementById('rejectModal'));
                    if(modal) modal.hide();
                }).catch(err => {
                    console.error('Error:', err);
                    alert('Rejection failed. Check console for errors.');
                    rejectRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('rejectModal'));
                    if(modal) modal.hide();
                });
            }

            function confirmRateRevision() {
                if(!rateRevisionRequestId) return;
                const hourlyRate = document.getElementById('revisedHourlyRate').value;
                const airtimeRate = document.getElementById('revisedAirtimeRate').value;
                const reason = document.getElementById('rateRevisionReason').value;

                if (!hourlyRate && !airtimeRate) {
                    alert('Please enter at least one revised rate.');
                    return;
                }

                const formData = new URLSearchParams();
                formData.append('request_id', rateRevisionRequestId);
                formData.append('action', 'revise_rates');
                if (hourlyRate) formData.append('revised_hourly_rate', hourlyRate);
                if (airtimeRate) formData.append('revised_airtime_rate', airtimeRate);
                if (reason) formData.append('rate_revision_reason', reason);

                fetch('lecturer_finance_action.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                }).then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadRequests();
                        alert('Rates revised successfully!');
                    } else {
                        alert(data.message || 'Rate revision failed.');
                    }
                    rateRevisionRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('rateRevisionModal'));
                    if(modal) modal.hide();
                }).catch(err => {
                    console.error('Error:', err);
                    alert('Rate revision failed. Check console for errors.');
                    rateRevisionRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('rateRevisionModal'));
                    if(modal) modal.hide();
                });
            }

            let payPrintRequestId = null;
            function payAndPrint(requestId) {
                payPrintRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('payPrintModal'));
                modal.show();
            }

            function openDeleteModal(requestId) {
                deleteRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
                modal.show();
            }

            function confirmDelete() {
                if(!deleteRequestId) return;
                const formData = new URLSearchParams();
                formData.append('request_id', deleteRequestId);
                formData.append('action', 'delete');
                fetch('lecturer_finance_action.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                }).then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadRequests();
                        alert('Request deleted successfully!');
                    } else {
                        alert(data.message || 'Delete failed.');
                    }
                    deleteRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                    if(modal) modal.hide();
                }).catch(err => {
                    console.error('Error:', err);
                    alert('Delete failed. Check console for errors.');
                    deleteRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                    if(modal) modal.hide();
                });
            }

            function confirmPayAndPrint() {
                if(!payPrintRequestId) return;
                fetch('pay_lecturer.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'request_id=' + encodeURIComponent(payPrintRequestId)
                }).then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadRequests();
                        alert('Payment processed successfully!');
                        window.open('print_lecturer_payment.php?id=' + payPrintRequestId, '_blank');
                    } else {
                        alert(data.message || 'Payment failed.');
                    }
                    payPrintRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('payPrintModal'));
                    if(modal) modal.hide();
                }).catch(err => {
                    console.error('Error:', err);
                    alert('Payment failed. Check console for errors.');
                    payPrintRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('payPrintModal'));
                    if(modal) modal.hide();
                });
            }
        </script>
        <!-- Pay & Print Confirmation Modal -->
        <div class="modal fade" id="payPrintModal" tabindex="-1" aria-labelledby="payPrintModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="payPrintModalLabel"><i class="bi bi-cash-coin me-2"></i>Confirm Pay & Print</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Are you sure you want to mark this request as paid and print the confirmation?</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmPayAndPrint()">Yes, Pay & Print</button>
              </div>
            </div>
          </div>
        </div>
        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-trash me-2"></i>Delete Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Are you sure you want to permanently delete this lecturer finance request?</p>
                <p class="text-danger"><small><strong>Warning:</strong> This action cannot be undone.</small></p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Yes, Delete</button>
              </div>
            </div>
          </div>
        </div>
        <!-- View Claim Details Modal -->
        <div class="modal fade" id="claimDetailsModal" tabindex="-1" aria-labelledby="claimDetailsModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
              <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="claimDetailsModalLabel"><i class="bi bi-eye me-2"></i>Claim Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body" id="claimDetailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading...</p>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
