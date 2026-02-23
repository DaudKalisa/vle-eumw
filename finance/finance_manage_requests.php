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
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Finance Requests - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body class="bg-light">
        <!-- Header Bar with User Info and Logout -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container-fluid">
                <a class="navbar-brand" href="dashboard.php">VLE Finance</a>
                <div class="d-flex align-items-center ms-auto">
                    <span class="text-white me-3">
                        <i class="bi bi-person-circle"></i>
                        <?php echo htmlspecialchars($user['display_name'] ?? $user['username'] ?? ''); ?>
                        <small class="text-secondary ms-2">(<?php echo htmlspecialchars($user['role'] ?? ''); ?>)</small>
                    </span>
                    <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
        <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-cash-coin"></i> Lecturer Finance Requests</h2>
            <div>
                <a href="lecturer_accounts.php" class="btn btn-info me-2"><i class="bi bi-person-lines-fill"></i> Lecturer Accounts</a>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
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
                                                <a href="finance_request_action.php?id=${req.request_id}&action=approve" class="btn btn-sm btn-success ms-1" onclick="return confirm('Approve this request?');"><i class="bi bi-check-circle"></i> Approve</a>
                                                <a href="finance_request_action.php?id=${req.request_id}&action=reject" class="btn btn-sm btn-danger ms-1" onclick="return confirm('Reject this request?');"><i class="bi bi-x-circle"></i> Reject</a>
                                            ` : req.status=='approved' ? `
                                                <form method="post" action="pay_lecturer.php" style="display:inline;">
                                                    <input type="hidden" name="request_id" value="${req.request_id}">
                                                    <button type="submit" class="btn btn-sm btn-warning ms-1" onclick="return confirm('Mark as paid and print confirmation?');"><i class="bi bi-cash-coin"></i> Pay & Print</button>
                                                </form>
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
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
