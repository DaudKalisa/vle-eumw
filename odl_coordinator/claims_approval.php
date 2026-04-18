<?php
/**
 * ODL Coordinator - Claims Approval Page
 * Review and approve/reject lecturer finance claims before finance department
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Check if odl_approval_status column exists
$col_check = $conn->query("SHOW COLUMNS FROM lecturer_finance_requests LIKE 'odl_approval_status'");
$has_odl_column = $col_check && $col_check->num_rows > 0;

// Handle approval actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'];
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($request_id > 0 && in_array($action, ['approve', 'reject', 'return', 'forward_dean', 'delete'])) {
        if ($action === 'delete') {
            // Only allow deleting claims that are still pending at ODL level and not yet paid
            $check_stmt = $conn->prepare("SELECT status, odl_approval_status FROM lecturer_finance_requests WHERE request_id = ?");
            $check_stmt->bind_param("i", $request_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if (!$check_result) {
                $message = "Claim not found.";
                $message_type = 'danger';
            } elseif ($check_result['status'] === 'paid') {
                $message = "Cannot delete a paid claim.";
                $message_type = 'danger';
            } else {
                $del_stmt = $conn->prepare("DELETE FROM lecturer_finance_requests WHERE request_id = ?");
                $del_stmt->bind_param("i", $request_id);
                if ($del_stmt->execute()) {
                    $message = "Claim deleted successfully.";
                    $message_type = 'success';
                    
                    // Log the action
                    $log_stmt = $conn->prepare("
                        INSERT INTO odl_claims_approval (request_id, coordinator_id, status, remarks, approved_at) 
                        VALUES (?, ?, 'deleted', ?, NOW())
                    ");
                    $coordinator_id = $user['user_id'];
                    $log_stmt->bind_param("iis", $request_id, $coordinator_id, $remarks);
                    $log_stmt->execute();
                } else {
                    $message = "Failed to delete claim: " . $conn->error;
                    $message_type = 'danger';
                }
            }
        } else {
            $status_map = [
                'approve' => 'approved',
                'reject' => 'rejected',
                'return' => 'returned',
                'forward_dean' => 'forwarded_to_dean'
            ];
            $new_status = $status_map[$action];
            
            // Process signature for approve action
            $signature_filename = null;
            if ($action === 'approve') {
                $signature_data = $_POST['signature_data'] ?? '';
                
                // Handle uploaded signature file
                if (isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['signature_file'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    if (in_array($mime, ['image/png', 'image/jpeg']) && $file['size'] <= 2 * 1024 * 1024) {
                        $upload_dir = '../uploads/signatures';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $signature_filename = 'sig_' . $request_id . '_odl_' . time() . '.png';
                        move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $signature_filename);
                        // Also save as user default
                        $default_path = $upload_dir . '/coordinator_' . $user['user_id'] . '.png';
                        copy($upload_dir . '/' . $signature_filename, $default_path);
                    }
                }
                // Handle canvas-drawn signature (base64)
                elseif ($signature_data && strpos($signature_data, 'data:image') === 0) {
                    $parts = explode(',', $signature_data);
                    if (count($parts) === 2) {
                        $decoded = base64_decode($parts[1]);
                        if ($decoded !== false) {
                            $upload_dir = '../uploads/signatures';
                            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                            $signature_filename = 'sig_' . $request_id . '_odl_' . time() . '.png';
                            file_put_contents($upload_dir . '/' . $signature_filename, $decoded);
                            // Also save as user default
                            $default_path = $upload_dir . '/coordinator_' . $user['user_id'] . '.png';
                            file_put_contents($default_path, $decoded);
                        }
                    }
                }
                // Use saved default signature if none provided
                elseif (empty($signature_data)) {
                    $default_sig = '../uploads/signatures/coordinator_' . $user['user_id'] . '.png';
                    if (file_exists($default_sig)) {
                        $upload_dir = '../uploads/signatures';
                        $signature_filename = 'sig_' . $request_id . '_odl_' . time() . '.png';
                        copy($default_sig, $upload_dir . '/' . $signature_filename);
                    }
                }
            }
            
            if ($has_odl_column) {
                if ($action === 'approve' && $signature_filename) {
                    $stmt = $conn->prepare("
                        UPDATE lecturer_finance_requests 
                        SET odl_approval_status = ?, odl_approved_by = ?, odl_approved_at = NOW(), odl_remarks = ?, odl_signature_path = ?
                        WHERE request_id = ?
                    ");
                    $stmt->bind_param("sissi", $new_status, $user['user_id'], $remarks, $signature_filename, $request_id);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE lecturer_finance_requests 
                        SET odl_approval_status = ?, odl_approved_by = ?, odl_approved_at = NOW(), odl_remarks = ?
                        WHERE request_id = ?
                    ");
                    $stmt->bind_param("sisi", $new_status, $user['user_id'], $remarks, $request_id);
                }
            } else {
                // Fallback - update main status (only if approving sends to finance)
                if ($action === 'approve') {
                    $new_main_status = 'pending'; // Keep as pending for finance to process
                } else {
                    $new_main_status = $new_status;
                }
                $stmt = $conn->prepare("UPDATE lecturer_finance_requests SET status = ? WHERE request_id = ?");
                $stmt->bind_param("si", $new_main_status, $request_id);
            }
            
            if ($stmt->execute()) {
                $action_labels = ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned for revision', 'forward_dean' => 'forwarded to Dean'];
                $message = "Claim successfully {$action_labels[$action]}.";
                $message_type = ($action === 'approve' || $action === 'forward_dean') ? 'success' : (($action === 'reject') ? 'danger' : 'warning');
                
                // Log the action
                $log_stmt = $conn->prepare("
                    INSERT INTO odl_claims_approval (request_id, coordinator_id, status, remarks, approved_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $coordinator_id = $user['user_id'];
                $log_stmt->bind_param("iiss", $request_id, $coordinator_id, $new_status, $remarks);
                $log_stmt->execute();
            } else {
                $message = "Failed to update claim status: " . $conn->error;
                $message_type = 'danger';
            }
        }
    }
}

// Filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_lecturer = $_GET['lecturer'] ?? '';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($has_odl_column) {
    if ($filter_status) {
        $where_clauses[] = "r.odl_approval_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    } else {
        // Default: show pending
        $where_clauses[] = "r.odl_approval_status = 'pending'";
    }
} else {
    if ($filter_status) {
        $where_clauses[] = "r.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    } else {
        $where_clauses[] = "r.status = 'pending'";
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
    $where_clauses[] = "l.lecturer_id = ?";
    $params[] = $filter_lecturer;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$sql = "
    SELECT r.*, l.full_name, l.email, l.department, l.position, l.phone
    FROM lecturer_finance_requests r
    JOIN lecturers l ON r.lecturer_id = l.lecturer_id
    $where_sql
    ORDER BY r.submission_date DESC
";

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

// Get lecturers for filter dropdown
$lecturers = [];
$lect_result = $conn->query("SELECT DISTINCT l.lecturer_id, l.full_name FROM lecturers l JOIN lecturer_finance_requests r ON l.lecturer_id = r.lecturer_id ORDER BY l.full_name");
if ($lect_result) {
    while ($row = $lect_result->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Statistics
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'returned' => 0,
    'total_pending_amount' => 0
];

$status_col = $has_odl_column ? 'odl_approval_status' : 'status';
$stat_result = $conn->query("SELECT $status_col as status, COUNT(*) as count, SUM(total_amount) as amount FROM lecturer_finance_requests GROUP BY $status_col");
if ($stat_result) {
    while ($row = $stat_result->fetch_assoc()) {
        $status = $row['status'];
        if (isset($stats[$status])) {
            $stats[$status] = (int)$row['count'];
        }
        if ($status === 'pending') {
            $stats['total_pending_amount'] = $row['amount'] ?? 0;
        }
    }
}

$page_title = 'Claims Approval';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claims Approval - ODL Coordinator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .stat-badge {
            padding: 15px 20px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            color: inherit;
        }
        .stat-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-badge-link {
            text-decoration: none;
            color: inherit;
        }
        .claim-row { transition: all 0.2s; }
        .claim-row:hover { background: #f8f9fa; }
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-clipboard-check me-2"></i>Lecturer Claims Approval</h1>
                <p class="text-muted mb-0">Review and approve lecturer finance claims before finance processing</p>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <a href="?status=pending" class="stat-badge-link">
                    <div class="stat-badge bg-warning bg-opacity-10 border border-warning">
                        <div class="h3 mb-0 text-warning"><?= number_format($stats['pending']) ?></div>
                        <small class="text-muted">Pending Review</small>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="?status=approved" class="stat-badge-link">
                    <div class="stat-badge bg-success bg-opacity-10 border border-success">
                        <div class="h3 mb-0 text-success"><?= number_format($stats['approved']) ?></div>
                        <small class="text-muted">Approved</small>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="?status=rejected" class="stat-badge-link">
                    <div class="stat-badge bg-danger bg-opacity-10 border border-danger">
                        <div class="h3 mb-0 text-danger"><?= number_format($stats['rejected']) ?></div>
                        <small class="text-muted">Rejected</small>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <div class="stat-badge bg-primary bg-opacity-10 border border-primary">
                    <div class="h3 mb-0 text-primary">MKW<?= number_format($stats['total_pending_amount']) ?></div>
                    <small class="text-muted">Pending Amount</small>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Pending</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="forwarded_to_dean" <?= $filter_status === 'forwarded_to_dean' ? 'selected' : '' ?>>Forwarded to Dean</option>
                            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="returned" <?= $filter_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filter_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Lecturer</label>
                        <select name="lecturer" class="form-select form-select-sm">
                            <option value="">All Lecturers</option>
                            <?php foreach ($lecturers as $lect): ?>
                            <option value="<?= htmlspecialchars($lect['lecturer_id']) ?>" <?= $filter_lecturer == $lect['lecturer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($lect['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="claims_approval.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Claims Table -->
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Claims (<?= count($claims) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($claims)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Lecturer</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Hours</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th width="200">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($claims as $claim): ?>
                            <?php 
                                $current_status = $has_odl_column ? ($claim['odl_approval_status'] ?? 'pending') : $claim['status'];
                                $status_class = [
                                    'pending' => 'warning',
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    'returned' => 'secondary',
                                    'forwarded_to_dean' => 'info'
                                ][$current_status] ?? 'secondary';
                            ?>
                            <tr class="claim-row">
                                <td>
                                    <small><?= date('M j, Y', strtotime($claim['request_date'] ?? $claim['submission_date'])) ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($claim['full_name']) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($claim['position'] ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($claim['department'] ?? 'N/A') ?></td>
                                <td><?= date('M Y', mktime(0,0,0,$claim['month'],1,$claim['year'])) ?></td>
                                <td><?= number_format($claim['total_hours'], 1) ?></td>
                                <td><strong class="text-success">MKW<?= number_format($claim['total_amount']) ?></strong></td>
                                <td><span class="badge bg-<?= $status_class ?>"><?= ucfirst($current_status) ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewClaim(<?= $claim['request_id'] ?>)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="print_claim.php?id=<?= $claim['request_id'] ?>" class="btn btn-sm btn-outline-dark" target="_blank" title="Print Claim">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php if ($current_status === 'pending'): ?>
                                    <button class="btn btn-sm btn-success" onclick="approveClaim(<?= $claim['request_id'] ?>)" title="Approve">
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
                                    <?php if ($current_status === 'approved'): ?>
                                    <button class="btn btn-sm btn-info" onclick="forwardToDean(<?= $claim['request_id'] ?>)" title="Forward to Dean">
                                        <i class="bi bi-send"></i> Dean
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <p class="mt-3 text-muted">No claims found matching your criteria</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="actionForm" enctype="multipart/form-data">
                    <input type="hidden" name="request_id" id="modalRequestId">
                    <input type="hidden" name="action" id="modalAction">
                    <input type="hidden" name="signature_data" id="signatureData">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Confirm Action</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p id="modalMessage">Are you sure you want to proceed?</p>
                        
                        <!-- Signature Section (shown only for approve) -->
                        <div id="signatureSection" style="display:none;">
                            <label class="form-label fw-bold">Your Signature <span class="text-danger">*</span></label>
                            
                            <!-- Tabs: Draw / Upload -->
                            <ul class="nav nav-tabs mb-2" id="sigTabs">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" onclick="switchSigTab('draw'); return false;">Draw</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" onclick="switchSigTab('upload'); return false;">Upload</a>
                                </li>
                            </ul>
                            
                            <!-- Draw Tab -->
                            <div id="sigDrawTab">
                                <?php
                                $saved_sig_url = '';
                                $saved_sig_path = '../uploads/signatures/coordinator_' . $user['user_id'] . '.png';
                                if (file_exists($saved_sig_path)) {
                                    $saved_sig_url = '../uploads/signatures/coordinator_' . $user['user_id'] . '.png?t=' . filemtime($saved_sig_path);
                                }
                                ?>
                                <?php if ($saved_sig_url): ?>
                                <div class="alert alert-info py-2 small mb-2">
                                    <i class="bi bi-check-circle me-1"></i>Your saved signature is pre-loaded. You can redraw or use as-is.
                                </div>
                                <?php endif; ?>
                                <div style="border:2px solid #dee2e6; border-radius:8px; background:#fff; position:relative;">
                                    <canvas id="signatureCanvas" width="400" height="120" style="width:100%; cursor:crosshair; display:block;"></canvas>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">Draw your signature above</small>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSignature()">
                                        <i class="bi bi-eraser me-1"></i>Clear
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Upload Tab -->
                            <div id="sigUploadTab" style="display:none;">
                                <input type="file" name="signature_file" id="signatureFile" class="form-control form-control-sm" accept="image/png,image/jpeg">
                                <small class="text-muted">PNG or JPG, max 2MB. This will also be saved as your default signature.</small>
                                <div id="uploadPreview" class="mt-2" style="display:none;">
                                    <img id="uploadPreviewImg" style="max-width:100%; max-height:120px; border:1px solid #dee2e6; border-radius:4px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label">Remarks (optional)</label>
                            <textarea name="remarks" class="form-control" rows="3" placeholder="Add any notes or feedback..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="modalSubmitBtn">Confirm</button>
                    </div>
                </form>
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
                <div class="modal-body" id="viewModalBody">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
    const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    
    // Signature canvas setup
    const canvas = document.getElementById('signatureCanvas');
    const ctx = canvas.getContext('2d');
    let isDrawing = false;
    let hasDrawn = false;
    let currentSigTab = 'draw';
    
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    
    // Load saved signature if available
    const savedSigUrl = <?= json_encode($saved_sig_url) ?>;
    if (savedSigUrl) {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            hasDrawn = true;
            document.getElementById('signatureData').value = canvas.toDataURL('image/png');
        };
        img.src = savedSigUrl;
    }
    
    // Mouse events
    canvas.addEventListener('mousedown', function(e) {
        isDrawing = true;
        hasDrawn = true;
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        ctx.beginPath();
        ctx.moveTo((e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY);
    });
    canvas.addEventListener('mousemove', function(e) {
        if (!isDrawing) return;
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        ctx.lineTo((e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY);
        ctx.stroke();
    });
    canvas.addEventListener('mouseup', function() {
        isDrawing = false;
        document.getElementById('signatureData').value = canvas.toDataURL('image/png');
    });
    canvas.addEventListener('mouseleave', function() {
        isDrawing = false;
    });
    
    // Touch events
    canvas.addEventListener('touchstart', function(e) {
        e.preventDefault();
        isDrawing = true;
        hasDrawn = true;
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const touch = e.touches[0];
        ctx.beginPath();
        ctx.moveTo((touch.clientX - rect.left) * scaleX, (touch.clientY - rect.top) * scaleY);
    });
    canvas.addEventListener('touchmove', function(e) {
        e.preventDefault();
        if (!isDrawing) return;
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const touch = e.touches[0];
        ctx.lineTo((touch.clientX - rect.left) * scaleX, (touch.clientY - rect.top) * scaleY);
        ctx.stroke();
    });
    canvas.addEventListener('touchend', function() {
        isDrawing = false;
        document.getElementById('signatureData').value = canvas.toDataURL('image/png');
    });
    
    function clearSignature() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
        document.getElementById('signatureData').value = '';
    }
    
    function switchSigTab(tab) {
        currentSigTab = tab;
        document.querySelectorAll('#sigTabs .nav-link').forEach(el => el.classList.remove('active'));
        if (tab === 'draw') {
            document.querySelector('#sigTabs .nav-item:first-child .nav-link').classList.add('active');
            document.getElementById('sigDrawTab').style.display = '';
            document.getElementById('sigUploadTab').style.display = 'none';
        } else {
            document.querySelector('#sigTabs .nav-item:last-child .nav-link').classList.add('active');
            document.getElementById('sigDrawTab').style.display = 'none';
            document.getElementById('sigUploadTab').style.display = '';
        }
    }
    
    // Upload preview
    document.getElementById('signatureFile').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('uploadPreviewImg').src = ev.target.result;
                document.getElementById('uploadPreview').style.display = '';
            };
            reader.readAsDataURL(file);
        } else {
            document.getElementById('uploadPreview').style.display = 'none';
        }
    });
    
    // Form validation - require signature for approve
    document.getElementById('actionForm').addEventListener('submit', function(e) {
        const action = document.getElementById('modalAction').value;
        if (action === 'approve') {
            const sigData = document.getElementById('signatureData').value;
            const sigFile = document.getElementById('signatureFile').files.length > 0;
            if (currentSigTab === 'draw' && !hasDrawn && !sigData) {
                e.preventDefault();
                alert('Please draw or upload your signature before approving.');
                return false;
            }
            if (currentSigTab === 'upload' && !sigFile) {
                e.preventDefault();
                alert('Please select a signature image file to upload.');
                return false;
            }
            // If on draw tab, ensure data is captured
            if (currentSigTab === 'draw') {
                document.getElementById('signatureData').value = canvas.toDataURL('image/png');
            }
        }
    });
    
    function showSignatureSection(show) {
        document.getElementById('signatureSection').style.display = show ? '' : 'none';
    }
    
    function approveClaim(id) {
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalAction').value = 'approve';
        document.getElementById('modalTitle').textContent = 'Approve Claim';
        document.getElementById('modalMessage').textContent = 'Are you sure you want to approve this claim? It will be sent to Finance for payment processing.';
        document.getElementById('modalSubmitBtn').className = 'btn btn-success';
        document.getElementById('modalSubmitBtn').textContent = 'Approve';
        showSignatureSection(true);
        actionModal.show();
    }
    
    function rejectClaim(id) {
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalAction').value = 'reject';
        document.getElementById('modalTitle').textContent = 'Reject Claim';
        document.getElementById('modalMessage').textContent = 'Are you sure you want to reject this claim? The lecturer will be notified.';
        document.getElementById('modalSubmitBtn').className = 'btn btn-danger';
        document.getElementById('modalSubmitBtn').textContent = 'Reject';
        showSignatureSection(false);
        actionModal.show();
    }
    
    function returnClaim(id) {
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalAction').value = 'return';
        document.getElementById('modalTitle').textContent = 'Return for Revision';
        document.getElementById('modalMessage').textContent = 'Return this claim to the lecturer for corrections? Please provide feedback in the remarks.';
        document.getElementById('modalSubmitBtn').className = 'btn btn-warning';
        document.getElementById('modalSubmitBtn').textContent = 'Return';
        showSignatureSection(false);
        actionModal.show();
    }
    
    function forwardToDean(id) {
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalAction').value = 'forward_dean';
        document.getElementById('modalTitle').textContent = 'Forward to Dean';
        document.getElementById('modalMessage').textContent = 'Forward this approved claim to the Dean for final authorization before sending to Finance?';
        document.getElementById('modalSubmitBtn').className = 'btn btn-info';
        document.getElementById('modalSubmitBtn').textContent = 'Forward to Dean';
        showSignatureSection(false);
        actionModal.show();
    }
    
    function deleteClaim(id) {
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalAction').value = 'delete';
        document.getElementById('modalTitle').textContent = 'Delete Claim';
        document.getElementById('modalMessage').textContent = 'Are you sure you want to permanently delete this claim? This action cannot be undone.';
        document.getElementById('modalSubmitBtn').className = 'btn btn-danger';
        document.getElementById('modalSubmitBtn').textContent = 'Delete';
        showSignatureSection(false);
        actionModal.show();
    }
    
    function viewClaim(id) {
        document.getElementById('viewModalBody').innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>';
        viewModal.show();
        
        // Load claim details via AJAX
        fetch('get_claim_details.php?id=' + id)
            .then(r => r.text())
            .then(html => {
                document.getElementById('viewModalBody').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('viewModalBody').innerHTML = '<div class="alert alert-danger">Failed to load claim details</div>';
            });
    }
    </script>
</body>
</html>
