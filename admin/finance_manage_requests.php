<?php
// admin/finance_manage_requests.php - Redirect to new location in finance/
header('Location: ../finance/finance_manage_requests.php');
exit;
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
                                                <a href="process_lecturer_payment.php" class="btn btn-sm btn-warning ms-1"><i class="bi bi-cash-coin"></i> Start Payment</a>
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
