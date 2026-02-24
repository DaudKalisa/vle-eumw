<?php
// finance/finance_manage_requests.php - Manage lecturer finance requests (moved from admin)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();

// Fetch all finance requests
$stmt = $conn->query("SELECT r.*, l.full_name, l.email, l.position, l.department FROM lecturer_finance_requests r JOIN lecturers l ON r.lecturer_id = l.lecturer_id ORDER BY request_date DESC");
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
                                <th>Position</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Modules</th>
                                <th>Hours</th>
                                <th>Amount</th>
                                <th>Status</th>
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
                                    <td>${req.request_date ? new Date(req.request_date).toLocaleDateString() : ''}</td>
                                    <td>${req.lecturer_name || ''}<br><small>${req.lecturer_email || ''}</small></td>
                                    <td>${req.position || ''}</td>
                                    <td>${req.department || ''}</td>
                                    <td>${req.month && req.year ? new Date(req.year, req.month-1, 1).toLocaleString('default', {month:'long', year:'numeric'}) : ''}</td>
                                    <td>${req.total_modules || 0}</td>
                                    <td>${req.total_hours || 0}h</td>
                                    <td><strong>K${Number(req.total_amount || 0).toLocaleString()}</strong></td>
                                    <td><span class="badge bg-${req.status=='pending'?'warning':(req.status=='approved'?'success':(req.status=='rejected'?'danger':'secondary'))}">${req.status.charAt(0).toUpperCase() + req.status.slice(1)}</span></td>
                                    <td>
                                        <a href="finance_request_pdf.php?id=${req.request_id}" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                        ${(window.userRole === 'finance' || window.userRole === 'admin') ? (
                                            req.status=='pending' ? `
                                                <button type="button" class="btn btn-sm btn-success ms-1" onclick="openApproveModal(${req.request_id})"><i class="bi bi-check-circle"></i> Approve</button>
                                                <form method="post" action="lecturer_finance_action.php" style="display:inline;">
                                                    <input type="hidden" name="request_id" value="${req.request_id}">
                                                    <input type="hidden" name="action" value="reject">
                                                    <button type="submit" class="btn btn-sm btn-danger ms-1" onclick="return confirm('Reject this request?');"><i class="bi bi-x-circle"></i> Reject</button>
                                                </form>
                                            ` : req.status=='approved' ? `
                                                <button type="button" class="btn btn-sm btn-warning ms-1" onclick="payAndPrint(${req.request_id})"><i class="bi bi-cash-coin"></i> Pay & Print</button>
                                            ` : req.status=='paid' ? `
                                                <a href="print_lecturer_payment.php?id=${req.request_id}" class="btn btn-sm btn-success ms-1" target="_blank"><i class="bi bi-printer"></i> Print Confirmation</a>
                                            ` : ''
                                        ) : ''}
                                    </td>
                                </tr>
                            `;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No requests found.</td></tr>';
                    }
                });
        }
        document.addEventListener('DOMContentLoaded', loadRequests);
        </script>
        <!-- Approve Confirmation Modal -->
        <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="approveModalLabel"><i class="bi bi-check-circle me-2"></i>Confirm Approval</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Are you sure you want to approve this finance request?</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmApprove()">Yes, Approve</button>
              </div>
            </div>
          </div>
        </div>
        <script>
            let approveRequestId = null;
            function openApproveModal(requestId) {
                approveRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('approveModal'));
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
                    } else {
                        alert(data.message || 'Approval failed.');
                    }
                    approveRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('approveModal'));
                    if(modal) modal.hide();
                }).catch(() => {
                    alert('Approval failed.');
                    approveRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('approveModal'));
                    if(modal) modal.hide();
                });
            }
            let payPrintRequestId = null;
            function payAndPrint(requestId) {
                payPrintRequestId = requestId;
                var modal = new bootstrap.Modal(document.getElementById('payPrintModal'));
                modal.show();
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
                        window.open('print_lecturer_payment.php?id=' + payPrintRequestId, '_blank');
                    } else {
                        alert(data.message || 'Payment failed.');
                    }
                    payPrintRequestId = null;
                    var modal = bootstrap.Modal.getInstance(document.getElementById('payPrintModal'));
                    if(modal) modal.hide();
                }).catch(() => {
                    alert('Payment failed.');
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
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
