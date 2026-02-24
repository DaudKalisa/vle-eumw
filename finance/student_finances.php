<?php
// finance/student_finances.php - Student Financial Accounts Management
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Handle AJAX payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_payment'])) {
    header('Content-Type: application/json');
    
    $student_id = $_POST['student_id'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_type = $_POST['payment_type'] ?? 'payment';
    $payment_method = $_POST['payment_method'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';
    
    // Validation
    if (empty($student_id)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid amount greater than 0']);
        exit;
    }
    
    if (empty($payment_method)) {
        echo json_encode(['success' => false, 'message' => 'Please select a payment method']);
        exit;
    }
    
    // Record payment
    $stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, amount, payment_type, payment_method, reference_number, payment_date, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $recorded_by = $user['display_name'];
    $stmt->bind_param("sdssssss", $student_id, $amount, $payment_type, $payment_method, $reference_number, $payment_date, $notes, $recorded_by);
    
    if ($stmt->execute()) {
        $transaction_id = $conn->insert_id; // Get the newly inserted transaction ID
        
        // Check if student_finances record exists, if not create it
        $check_finance = $conn->prepare("SELECT student_id FROM student_finances WHERE student_id = ?");
        $check_finance->bind_param("s", $student_id);
        $check_finance->execute();
        $finance_exists = $check_finance->get_result();
        
        if ($finance_exists->num_rows == 0) {
            // Create new finance record with default expected_total of 539500
            $create_finance = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, 539500, 0, 539500, 0, 0)");
            $create_finance->bind_param("s", $student_id);
            $create_finance->execute();
        }
        
        // Update student_finances
        $update_finance = "UPDATE student_finances 
                          SET total_paid = total_paid + ?,
                              balance = expected_total - (total_paid + ?),
                              payment_percentage = ROUND(((total_paid + ?) / expected_total) * 100),
                              content_access_weeks = CASE 
                                  WHEN ((total_paid + ?) / expected_total) >= 1.0 THEN 52
                                  WHEN ((total_paid + ?) / expected_total) >= 0.75 THEN 13
                                  WHEN ((total_paid + ?) / expected_total) >= 0.50 THEN 9
                                  WHEN ((total_paid + ?) / expected_total) >= 0.25 THEN 4
                                  ELSE 0
                              END
                          WHERE student_id = ?";
        $stmt = $conn->prepare($update_finance);
        $stmt->bind_param("ddddddds", $amount, $amount, $amount, $amount, $amount, $amount, $amount, $student_id);
        $stmt->execute();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Payment recorded successfully',
            'transaction_id' => $transaction_id,
            'student_id' => $student_id,
            'amount' => $amount
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to record payment']);
    }
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$payment_filter = isset($_GET['payment_filter']) ? $_GET['payment_filter'] : 'all';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

// Build query
$where = ['s.is_active = TRUE'];
if ($search) {
    $search_safe = $conn->real_escape_string($search);
    $where[] = "(s.student_id LIKE '%$search_safe%' OR s.full_name LIKE '%$search_safe%' OR s.email LIKE '%$search_safe%')";
}
if ($payment_filter != 'all') {
    $where[] = "sf.payment_percentage = " . intval($payment_filter);
}
// Outstanding filter - students who haven't paid in full
if ($filter === 'outstanding') {
    $where[] = "(sf.total_paid < sf.expected_total OR sf.total_paid IS NULL OR sf.total_paid = 0)";
}

$where_clause = implode(' AND ', $where);

$query = "SELECT s.student_id, s.full_name, s.email, s.program_type,
                 sf.expected_total, sf.total_paid, sf.balance, sf.payment_percentage, sf.content_access_weeks,
                 sf.registration_paid, sf.installment_1, sf.installment_2, sf.installment_3, sf.installment_4,
                 d.department_name, d.department_code as program_code, d.department_id
          FROM students s 
          LEFT JOIN student_finances sf ON s.student_id = sf.student_id 
          LEFT JOIN departments d ON s.department = d.department_id 
          WHERE $where_clause 
          ORDER BY s.student_id";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Financial Accounts - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .payment-badge-0 { background-color: var(--vle-danger); }
        .payment-badge-25 { background-color: var(--vle-warning); }
        .payment-badge-50 { background-color: var(--vle-info); }
        .payment-badge-75 { background-color: var(--vle-accent); }
        .payment-badge-100 { background-color: var(--vle-success); }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'student_finances';
    $pageTitle = 'Student Financial Accounts';
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <h1 class="h3 mb-1"><i class="bi bi-people-fill me-2"></i>Student Financial Accounts</h1>
            <p class="text-muted mb-0">View and manage student payment records</p>
        </div>

        <?php if ($filter === 'outstanding'): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div>
                <strong>Viewing Outstanding Balances Only</strong><br>
                <small>Showing students with unpaid fees. <a href="student_finances.php" class="alert-link">View all students</a></small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters and Search -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Search Student</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Search by ID, Name, or Email" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_filter" class="form-select">
                            <option value="all" <?php echo $payment_filter == 'all' ? 'selected' : ''; ?>>All Students</option>
                            <option value="0" <?php echo $payment_filter == '0' ? 'selected' : ''; ?>>0% - Not Paid</option>
                            <option value="25" <?php echo $payment_filter == '25' ? 'selected' : ''; ?>>25% - 1st Installment</option>
                            <option value="50" <?php echo $payment_filter == '50' ? 'selected' : ''; ?>>50% - 2nd Installment</option>
                            <option value="75" <?php echo $payment_filter == '75' ? 'selected' : ''; ?>>75% - 3rd Installment</option>
                            <option value="100" <?php echo $payment_filter == '100' ? 'selected' : ''; ?>>100% - Fully Paid</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Students Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-table"></i> Student Accounts (<?php echo $result->num_rows; ?> records)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th>Total Fees</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Payment %</th>
                                <th>Access</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): 
                                    // Calculate correct total fees based on program type
                                    $application_fee = 5500;
                                    $registration_fee = 39500;
                                    $program_type = $row['program_type'] ?? 'degree';
                                    
                                    switch ($program_type) {
                                        case 'professional':
                                            $tuition = 200000;
                                            break;
                                        case 'masters':
                                            $tuition = 1100000;
                                            break;
                                        case 'doctorate':
                                            $tuition = 2200000;
                                            break;
                                        case 'degree':
                                        default:
                                            $tuition = 500000;
                                            break;
                                    }
                                    
                                    $correct_total_fees = $application_fee + $registration_fee + $tuition;
                                    $total_paid = $row['total_paid'] ?? 0;
                                    $balance = $correct_total_fees - $total_paid;
                                    $payment_percentage = $correct_total_fees > 0 ? round(($total_paid / $correct_total_fees) * 100) : 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['program_code'] ?? 'N/A'); ?></td>
                                        <td>K<?php echo number_format($correct_total_fees); ?></td>
                                        <td class="text-success"><strong>K<?php echo number_format($total_paid); ?></strong></td>
                                        <td class="text-danger"><strong>K<?php echo number_format($balance); ?></strong></td>
                                        <td>
                                            <span class="badge payment-badge-<?php echo $payment_percentage; ?>">
                                                <?php echo $payment_percentage; ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            // Calculate access weeks using payment percentage logic, max 16 weeks
                                            $total_paid = $row['total_paid'] ?? 0;
                                            $expected_total = $row['expected_total'] ?? 1;
                                            $payment_percentage = $expected_total > 0 ? ($total_paid / $expected_total) : 0;
                                            if ($payment_percentage >= 1) {
                                                $weeks = 16;
                                            } elseif ($payment_percentage >= 0.75) {
                                                $weeks = 12;
                                            } elseif ($payment_percentage >= 0.5) {
                                                $weeks = 8;
                                            } elseif ($payment_percentage >= 0.25) {
                                                $weeks = 4;
                                            } else {
                                                $weeks = 0;
                                            }
                                            if ($weeks == 0) {
                                                echo '<span class="badge bg-danger">No Access</span>';
                                            } else {
                                                echo '<span class="badge bg-success">' . $weeks . ' Weeks</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view_student_finance.php?id=<?php echo urlencode($row['student_id']); ?>" class="btn btn-info" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-success" title="Record Payment" 
                                                        onclick="openPaymentModal('<?php echo htmlspecialchars($row['student_id']); ?>', '<?php echo htmlspecialchars($row['full_name']); ?>', <?php echo $row['balance'] ?? 539500; ?>)">
                                                    <i class="bi bi-cash-coin"></i>
                                                </button>
                                                <a href="payment_history.php?student_id=<?php echo urlencode($row['student_id']); ?>" class="btn btn-primary" title="Payment History">
                                                    <i class="bi bi-clock-history"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                                        <p class="text-muted">No students found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Record Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="paymentForm">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Student:</strong> <span id="modal_student_name"></span><br>
                            <strong>Student ID:</strong> <span id="modal_student_id"></span><br>
                            <strong>Current Balance:</strong> K<span id="modal_balance"></span>
                        </div>
                        
                        <input type="hidden" name="ajax_payment" value="1">
                        <input type="hidden" name="student_id" id="payment_student_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Amount Paid <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">K</span>
                                <input type="number" class="form-control" name="amount" id="payment_amount" required min="1" step="0.01">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="payment_type" required>
                                <option value="registration_fee">Registration Fee</option>
                                <option value="installment_1">1st Installment</option>
                                <option value="installment_2">2nd Installment</option>
                                <option value="installment_3">3rd Installment</option>
                                <option value="installment_4">4th Installment</option>
                                <option value="payment" selected>General Payment</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" name="payment_method" required>
                                <option value="">-- Select Method --</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="cheque">Cheque</option>
                                <option value="card">Card Payment</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="Transaction reference">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes"></textarea>
                        </div>
                        
                        <div id="payment_message"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Save Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let paymentModal;
        
        document.addEventListener('DOMContentLoaded', function() {
            paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
        });
        
        function openPaymentModal(studentId, studentName, balance) {
            document.getElementById('modal_student_id').textContent = studentId;
            document.getElementById('modal_student_name').textContent = studentName;
            document.getElementById('modal_balance').textContent = Number(balance).toLocaleString();
            document.getElementById('payment_student_id').value = studentId;
            document.getElementById('paymentForm').reset();
            document.getElementById('payment_student_id').value = studentId; // Reset clears it, so set again
            document.getElementById('payment_message').innerHTML = '';
            paymentModal.show();
        }
        
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const messageDiv = document.getElementById('payment_message');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
            
            fetch('student_finances.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Auto-open the receipt in a new window using unified payment_receipt.php
                    window.open('payment_receipt.php?id=' + data.transaction_id + '&type=transaction', '_blank');
                    
                    // Show success message with print option
                    messageDiv.innerHTML = '<div class="alert alert-success">' +
                        '<i class="bi bi-check-circle-fill me-2"></i>' + data.message + 
                        '<div class="mt-3 d-flex gap-2 flex-wrap">' +
                        '<a href="payment_receipt.php?id=' + data.transaction_id + '&type=transaction" target="_blank" class="btn btn-primary btn-sm">' +
                        '<i class="bi bi-file-earmark-text me-1"></i> Print Receipt</a>' +
                        '<button type="button" class="btn btn-secondary btn-sm" onclick="paymentModal.hide(); location.reload();">' +
                        '<i class="bi bi-x-circle me-1"></i> Close</button>' +
                        '</div></div>';
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Payment Saved';
                } else {
                    messageDiv.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Payment';
                }
            })
            .catch(error => {
                messageDiv.innerHTML = '<div class="alert alert-danger">An error occurred. Please try again.</div>';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save Payment';
            });
        });
    </script>
</body>
</html>

<?php  ?>
