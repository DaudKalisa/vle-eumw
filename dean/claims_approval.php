<?php
/**
 * Dean Portal - Claims Approval
 * Review and approve/reject lecturer finance claims forwarded from ODL Coordinator
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Check column existence
$col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
$has_odl_column = $col_check && $col_check->num_rows > 0;

$col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'dean_approval_status'");
$has_dean_column = $col_check && $col_check->num_rows > 0;

// Add dean columns if they don't exist
if (!$has_dean_column) {
    $conn->query("ALTER TABLE lecturer_finance_requests 
                  ADD COLUMN dean_approval_status ENUM('pending','approved','rejected','returned') DEFAULT 'pending',
                  ADD COLUMN dean_approved_by INT NULL,
                  ADD COLUMN dean_approved_at TIMESTAMP NULL,
                  ADD COLUMN dean_remarks TEXT NULL");
    $has_dean_column = true;
}

// Handle approval actions via AJAX (submit_approval.php)
// Legacy POST handler kept for backwards compatibility
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'];
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($request_id > 0 && in_array($action, ['approve', 'reject', 'return', 'forward_finance'])) {
        $status_map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'return' => 'returned',
            'forward_finance' => 'approved'
        ];
        $new_status = $status_map[$action];
        
        if ($has_dean_column) {
            $stmt = $conn->prepare("
                UPDATE lecturer_finance_requests 
                SET dean_approval_status = ?, dean_approved_by = ?, dean_approved_at = NOW(), dean_remarks = ?
                WHERE request_id = ?
            ");
            $stmt->bind_param("sisi", $new_status, $user['user_id'], $remarks, $request_id);
            
            if ($stmt->execute()) {
                $action_labels = [
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    'return' => 'returned to ODL Coordinator',
                    'forward_finance' => 'approved and forwarded to Finance'
                ];
                $message = "Claim successfully {$action_labels[$action]}.";
                $message_type = ($action === 'approve' || $action === 'forward_finance') ? 'success' : (($action === 'reject') ? 'danger' : 'warning');
            } else {
                $message = "Failed to update claim status: " . $conn->error;
                $message_type = 'danger';
            }
        }
    }
}

// Create dean approval log table if doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS dean_claims_approval (
    approval_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    dean_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure all required columns exist
$check_columns = ['status', 'created_at'];
foreach ($check_columns as $col) {
    $col_check = $conn->query("SHOW COLUMNS FROM dean_claims_approval LIKE '$col'");
    if (!$col_check || $col_check->num_rows === 0) {
        if ($col === 'status') {
            $conn->query("ALTER TABLE dean_claims_approval ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
        } elseif ($col === 'created_at') {
            $conn->query("ALTER TABLE dean_claims_approval ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
    }
}

// Filter parameters
$filter_status = $_GET['status'] ?? 'forwarded_to_dean';
$filter_month = $_GET['month'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_lecturer = $_GET['lecturer'] ?? '';

// Build query
$where_clauses = [];
$params = [];
$types = "";

// Dean sees claims forwarded from ODL or pending dean approval
if ($has_odl_column && $has_dean_column) {
    if ($filter_status === 'forwarded_to_dean') {
        $where_clauses[] = "(r.odl_approval_status = 'forwarded_to_dean' OR r.odl_approval_status = 'approved')";
        $where_clauses[] = "(r.dean_approval_status = 'pending' OR r.dean_approval_status IS NULL)";
    } elseif ($filter_status) {
        $where_clauses[] = "r.dean_approval_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
} elseif ($has_odl_column) {
    if ($filter_status === 'forwarded_to_dean') {
        $where_clauses[] = "r.odl_approval_status = 'forwarded_to_dean'";
    } else {
        $where_clauses[] = "r.odl_approval_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
} else {
    if ($filter_status) {
        $where_clauses[] = "r.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
}

if ($filter_month) {
    $where_clauses[] = "r.month = ?";
    $params[] = $filter_month;
    $types .= "i";
}

if ($filter_year) {
    $where_clauses[] = "r.year = ?";
    $params[] = $filter_year;
    $types .= "i";
}

if ($filter_lecturer) {
    $where_clauses[] = "(l.full_name LIKE ? OR l.lecturer_id LIKE ?)";
    $params[] = "%$filter_lecturer%";
    $params[] = "%$filter_lecturer%";
    $types .= "ss";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get claims
$sql = "SELECT r.*, l.full_name, l.email, l.department, l.profile_picture
        FROM lecturer_finance_requests r
        JOIN lecturers l ON r.lecturer_id = l.lecturer_id
        $where_sql
        ORDER BY r.request_date DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$claims = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $claims[] = $row;
    }
}

// Get lecturers for filter
$lecturers = [];
$lec_result = $conn->query("SELECT DISTINCT l.lecturer_id, l.full_name FROM lecturers l 
                            JOIN lecturer_finance_requests r ON l.lecturer_id = r.lecturer_id 
                            ORDER BY l.full_name");
if ($lec_result) {
    while ($row = $lec_result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Count stats
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total_amount' => 0
];

if ($has_dean_column) {
    $stat_result = $conn->query("SELECT dean_approval_status, COUNT(*) as cnt, SUM(total_amount) as amt FROM lecturer_finance_requests GROUP BY dean_approval_status");
} elseif ($has_odl_column) {
    $stat_result = $conn->query("SELECT odl_approval_status, COUNT(*) as cnt, SUM(total_amount) as amt FROM lecturer_finance_requests GROUP BY odl_approval_status");
} else {
    $stat_result = $conn->query("SELECT status, COUNT(*) as cnt, SUM(total_amount) as amt FROM lecturer_finance_requests GROUP BY status");
}

if ($stat_result) {
    while ($row = $stat_result->fetch_assoc()) {
        // Get status based on which column exists in query result
        if ($has_dean_column && array_key_exists('dean_approval_status', $row)) {
            $s = $row['dean_approval_status'];
        } elseif ($has_odl_column && array_key_exists('odl_approval_status', $row)) {
            $s = $row['odl_approval_status'];
        } else {
            $s = $row['status'] ?? null;
        }
        if ($s !== null && isset($stats[$s])) {
            $stats[$s] = $row['cnt'];
        }
        if ($s === 'approved') {
            $stats['total_amount'] = $row['amt'] ?? 0;
        }
    }
}

// Count forwarded claims
if ($has_odl_column) {
    $fwd_result = $conn->query("SELECT COUNT(*) as cnt FROM lecturer_finance_requests WHERE odl_approval_status = 'forwarded_to_dean'");
    $stats['forwarded'] = $fwd_result ? $fwd_result->fetch_assoc()['cnt'] : 0;
}

$page_title = "Claims Approval";
$breadcrumbs = [['title' => 'Claims Approval']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .claim-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .claim-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .stat-badge {
            font-size: 1.5rem;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <div class="stat-badge text-warning"><?= $stats['forwarded'] ?? $stats['pending'] ?></div>
                        <small class="text-muted">Pending Review</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <div class="stat-badge text-success"><?= $stats['approved'] ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <div class="stat-badge text-danger"><?= $stats['rejected'] ?></div>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <div class="stat-badge text-info">MKW <?= number_format($stats['total_amount']) ?></div>
                        <small class="text-muted">Total Approved</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="forwarded_to_dean" <?= $filter_status === 'forwarded_to_dean' ? 'selected' : '' ?>>Pending Review</option>
                            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="returned" <?= $filter_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                            <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>All Claims</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filter_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All Years</option>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lecturer</label>
                        <input type="text" name="lecturer" class="form-control" value="<?= htmlspecialchars($filter_lecturer) ?>" placeholder="Search lecturer...">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Claims Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Claims for Dean Approval</h5>
                <span class="badge bg-primary"><?= count($claims) ?> claims</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($claims)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <p class="text-muted">No claims found matching your criteria</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Lecturer</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Modules</th>
                                <th>Hours</th>
                                <th>Amount</th>
                                <th>ODL Status</th>
                                <th>Dean Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($claims as $claim): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($claim['profile_picture']) && file_exists('../uploads/profiles/' . $claim['profile_picture'])): ?>
                                            <img src="../uploads/profiles/<?= htmlspecialchars($claim['profile_picture']) ?>" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-weight: 700;">
                                                <?= strtoupper(substr($claim['full_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($claim['full_name']) ?></strong>
                                            <div class="small text-muted"><?= htmlspecialchars($claim['email'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></td>
                                <td>
                                    <strong><?= date('M Y', mktime(0, 0, 0, $claim['month'], 1, $claim['year'])) ?></strong>
                                </td>
                                <td><span class="badge bg-secondary"><?= $claim['total_modules'] ?? 0 ?></span></td>
                                <td><?= number_format($claim['total_hours'] ?? 0, 1) ?> hrs</td>
                                <td>
                                    <strong class="text-success">MKW <?= number_format($claim['total_amount']) ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $odl_status = $claim['odl_approval_status'] ?? 'pending';
                                    $odl_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'forwarded_to_dean' => 'info', 'returned' => 'secondary'][$odl_status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $odl_badge ?>"><?= ucfirst(str_replace('_', ' ', $odl_status)) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $dean_status = $claim['dean_approval_status'] ?? 'pending';
                                    $dean_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'returned' => 'secondary'][$dean_status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $dean_badge ?>"><?= ucfirst($dean_status) ?></span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewClaim(<?= $claim['request_id'] ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($dean_status === 'pending'): ?>
                                        <button class="btn btn-sm btn-success" onclick="approveClaim(<?= $claim['request_id'] ?>)" title="Approve with Signature">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="rejectClaim(<?= $claim['request_id'] ?>)" title="Reject">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="returnClaim(<?= $claim['request_id'] ?>)" title="Return for Revision">
                                            <i class="bi bi-arrow-return-left"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteClaim(<?= $claim['request_id'] ?>)" title="Delete Claim">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                        <a href="print_claim.php?id=<?= $claim['request_id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Print">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Approve Modal (with Signature) -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Dean Approval with Signature</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="approveRequestId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Signature <span class="text-danger">*</span></label>
                        <div class="alert alert-info py-2 mb-2"><small><i class="bi bi-info-circle me-1"></i>Draw your signature below or upload an image (PNG/JPG, max 2MB)</small></div>
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#drawPanel" type="button">&#9999;&#65039; Draw</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uploadPanel" type="button">&#128228; Upload</button></li>
                        </ul>
                        <div class="tab-content mt-2">
                            <div class="tab-pane fade show active" id="drawPanel">
                                <div style="border: 2px dashed #ccc; border-radius: 8px; background: #fafafa;">
                                    <canvas id="signatureCanvas" width="460" height="150" style="display: block; cursor: crosshair; background: white; width: 100%; border-radius: 6px;"></canvas>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="clearSignature()"><i class="bi bi-eraser me-1"></i>Clear</button>
                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="saveTempSignature()"><i class="bi bi-check me-1"></i>Use This Signature</button>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="uploadPanel">
                                <input type="file" class="form-control mb-2" id="signatureFile" accept="image/png,image/jpeg" onchange="handleFileUpload(event)">
                                <div id="filePreview" style="display: none;" class="text-center p-2 border rounded">
                                    <img id="previewImg" style="max-width: 100%; max-height: 120px;">
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="signatureData">
                        <div id="sigStatus" class="mt-2" style="display:none;"></div>
                    </div>
                    <div class="mb-3">
                        <label for="approveRemarks" class="form-label">Remarks (Optional)</label>
                        <textarea class="form-control" id="approveRemarks" rows="2" placeholder="Add any notes about this approval..."></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmApproveCheckbox" onchange="updateApproveBtn()">
                        <label class="form-check-label" for="confirmApproveCheckbox">I confirm this signature is authentic and I approve this claim for payment processing</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="submitApproveBtn" onclick="submitDeanApproval()" disabled>
                        <i class="bi bi-check-circle me-1"></i>Approve &amp; Forward to Finance
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject / Return Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="actionModalHeader">
                    <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="actionRequestId">
                    <input type="hidden" id="actionType">
                    <p id="actionMessage">Are you sure?</p>
                    <div class="mb-3">
                        <label class="form-label">Remarks <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="actionRemarks" rows="3" placeholder="Provide a reason for this action..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="actionSubmitBtn" onclick="submitAction()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Claim</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteRequestId">
                    <p class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>This action cannot be undone!</p>
                    <p>Are you sure you want to permanently delete this claim?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteSubmitBtn" onclick="submitDelete()">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Claim Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Claim Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="claimDetails">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading claim details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal instances
        const approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
        const actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

        // =====================
        // Signature Canvas Setup
        // =====================
        let canvas = document.getElementById('signatureCanvas');
        let ctx = canvas ? canvas.getContext('2d') : null;
        let isDrawing = false;
        let selectedSignature = null;
        let hasDrawn = false;

        if (canvas) {
            canvas.addEventListener('mousedown', (e) => {
                isDrawing = true;
                hasDrawn = true;
                const r = canvas.getBoundingClientRect();
                const scaleX = canvas.width / r.width;
                const scaleY = canvas.height / r.height;
                ctx.beginPath();
                ctx.moveTo((e.clientX - r.left) * scaleX, (e.clientY - r.top) * scaleY);
            });
            canvas.addEventListener('mousemove', (e) => {
                if (!isDrawing) return;
                const r = canvas.getBoundingClientRect();
                const scaleX = canvas.width / r.width;
                const scaleY = canvas.height / r.height;
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#000';
                ctx.lineTo((e.clientX - r.left) * scaleX, (e.clientY - r.top) * scaleY);
                ctx.stroke();
            });
            canvas.addEventListener('mouseup', () => isDrawing = false);
            canvas.addEventListener('mouseleave', () => isDrawing = false);

            // Touch support for tablets/mobile
            canvas.addEventListener('touchstart', (e) => {
                e.preventDefault();
                isDrawing = true;
                hasDrawn = true;
                const r = canvas.getBoundingClientRect();
                const t = e.touches[0];
                const scaleX = canvas.width / r.width;
                const scaleY = canvas.height / r.height;
                ctx.beginPath();
                ctx.moveTo((t.clientX - r.left) * scaleX, (t.clientY - r.top) * scaleY);
            });
            canvas.addEventListener('touchmove', (e) => {
                e.preventDefault();
                if (!isDrawing) return;
                const r = canvas.getBoundingClientRect();
                const t = e.touches[0];
                const scaleX = canvas.width / r.width;
                const scaleY = canvas.height / r.height;
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#000';
                ctx.lineTo((t.clientX - r.left) * scaleX, (t.clientY - r.top) * scaleY);
                ctx.stroke();
            });
            canvas.addEventListener('touchend', () => isDrawing = false);
        }

        function clearSignature() {
            if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
            document.getElementById('signatureData').value = '';
            selectedSignature = null;
            hasDrawn = false;
            showSigStatus('', '');
            updateApproveBtn();
        }

        function saveTempSignature() {
            if (!hasDrawn) {
                showSigStatus('Please draw a signature first', 'warning');
                return;
            }
            document.getElementById('signatureData').value = canvas.toDataURL('image/png');
            selectedSignature = 'drawn';
            showSigStatus('<i class="bi bi-check-circle me-1"></i>Signature captured successfully', 'success');
            updateApproveBtn();
        }

        function handleFileUpload(e) {
            const f = e.target.files[0];
            if (!f) return;
            if (f.size > 2 * 1024 * 1024) { alert('File too large. Maximum size is 2MB.'); return; }
            if (!['image/png', 'image/jpeg'].includes(f.type)) { alert('Only PNG and JPEG images are allowed.'); return; }
            const r = new FileReader();
            r.onload = (ev) => {
                document.getElementById('signatureData').value = ev.target.result;
                document.getElementById('previewImg').src = ev.target.result;
                document.getElementById('filePreview').style.display = 'block';
                selectedSignature = 'uploaded';
                showSigStatus('<i class="bi bi-check-circle me-1"></i>Signature image uploaded', 'success');
                updateApproveBtn();
            };
            r.readAsDataURL(f);
        }

        function showSigStatus(msg, type) {
            const el = document.getElementById('sigStatus');
            if (!msg) { el.style.display = 'none'; return; }
            el.style.display = 'block';
            el.className = 'mt-2 small text-' + type;
            el.innerHTML = msg;
        }

        function updateApproveBtn() {
            const hasSig = selectedSignature && document.getElementById('signatureData').value;
            const confirmed = document.getElementById('confirmApproveCheckbox').checked;
            document.getElementById('submitApproveBtn').disabled = !(hasSig && confirmed);
        }

        // =====================
        // Action Functions
        // =====================

        function approveClaim(id) {
            // Reset the approve modal state
            document.getElementById('approveRequestId').value = id;
            document.getElementById('approveRemarks').value = '';
            document.getElementById('signatureData').value = '';
            document.getElementById('confirmApproveCheckbox').checked = false;
            document.getElementById('filePreview').style.display = 'none';
            document.getElementById('signatureFile').value = '';
            selectedSignature = null;
            hasDrawn = false;
            if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
            showSigStatus('', '');
            updateApproveBtn();
            
            const btn = document.getElementById('submitApproveBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Approve & Forward to Finance';
            
            approveModal.show();
        }

        function rejectClaim(id) {
            document.getElementById('actionRequestId').value = id;
            document.getElementById('actionType').value = 'reject';
            document.getElementById('actionModalTitle').textContent = 'Reject Claim';
            document.getElementById('actionModalHeader').className = 'modal-header bg-danger text-white';
            document.getElementById('actionMessage').textContent = 'Are you sure you want to reject this claim? Please provide a reason.';
            document.getElementById('actionSubmitBtn').className = 'btn btn-danger';
            document.getElementById('actionSubmitBtn').textContent = 'Reject Claim';
            document.getElementById('actionRemarks').value = '';
            actionModal.show();
        }

        function returnClaim(id) {
            document.getElementById('actionRequestId').value = id;
            document.getElementById('actionType').value = 'return';
            document.getElementById('actionModalTitle').textContent = 'Return for Revision';
            document.getElementById('actionModalHeader').className = 'modal-header bg-warning text-dark';
            document.getElementById('actionMessage').textContent = 'Return this claim to the ODL Coordinator for corrections. Please specify what needs to be revised.';
            document.getElementById('actionSubmitBtn').className = 'btn btn-warning';
            document.getElementById('actionSubmitBtn').textContent = 'Return Claim';
            document.getElementById('actionRemarks').value = '';
            actionModal.show();
        }

        function deleteClaim(id) {
            document.getElementById('deleteRequestId').value = id;
            const btn = document.getElementById('deleteSubmitBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete';
            deleteModal.show();
        }

        // =====================
        // Submit Functions (AJAX)
        // =====================

        function submitDeanApproval() {
            const requestId = document.getElementById('approveRequestId').value;
            const signature = document.getElementById('signatureData').value;
            const remarks = document.getElementById('approveRemarks').value;

            if (!signature) { alert('Please provide your signature'); return; }

            const btn = document.getElementById('submitApproveBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';

            fetch('submit_approval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    request_id: parseInt(requestId),
                    action: 'approve',
                    signature: signature,
                    remarks: remarks
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    approveModal.hide();
                    showToast('success', data.message || 'Claim approved successfully!');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    alert('Error: ' + (data.error || 'Approval failed'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Approve & Forward to Finance';
                }
            })
            .catch(err => {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Approve & Forward to Finance';
            });
        }

        function submitAction() {
            const requestId = document.getElementById('actionRequestId').value;
            const action = document.getElementById('actionType').value;
            const remarks = document.getElementById('actionRemarks').value.trim();

            if (!remarks) { alert('Please provide remarks for this action'); return; }

            const btn = document.getElementById('actionSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';

            fetch('submit_approval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    request_id: parseInt(requestId),
                    action: action,
                    remarks: remarks
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    actionModal.hide();
                    showToast(action === 'reject' ? 'danger' : 'warning', data.message || 'Action completed');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    alert('Error: ' + (data.error || 'Action failed'));
                    btn.disabled = false;
                    btn.innerHTML = 'Confirm';
                }
            })
            .catch(err => {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = 'Confirm';
            });
        }

        function submitDelete() {
            const requestId = document.getElementById('deleteRequestId').value;
            const btn = document.getElementById('deleteSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deleting...';

            fetch('submit_approval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    request_id: parseInt(requestId),
                    action: 'delete',
                    remarks: 'Deleted by Dean'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    deleteModal.hide();
                    showToast('danger', data.message || 'Claim deleted');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    alert('Error: ' + (data.error || 'Delete failed'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete';
                }
            })
            .catch(err => {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete';
            });
        }

        // =====================
        // View Claim Details
        // =====================
        function viewClaim(id) {
            document.getElementById('claimDetails').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading...</p></div>';
            viewModal.show();
            
            fetch('get_claim_details.php?id=' + id)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('claimDetails').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('claimDetails').innerHTML = '<div class="alert alert-danger">Failed to load claim details</div>';
                });
        }

        // =====================
        // Toast Notification
        // =====================
        function showToast(type, message) {
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show shadow" role="alert">' +
                '<i class="bi bi-' + (type === 'success' ? 'check-circle' : type === 'danger' ? 'x-circle' : 'exclamation-triangle') + ' me-2"></i>' +
                message +
                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }
    </script>
</body>
</html>
