<?php
/**
 * Research Coordinator - Reference Letters
 * Generate reference/introduction letters for data collection
 * Workflow: Student requests → Coordinator approves → Registrar signs
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

// Auto-create reference_letters table
$conn->query("
    CREATE TABLE IF NOT EXISTS dissertation_reference_letters (
        letter_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        student_id VARCHAR(20) NOT NULL,
        
        -- Letter details
        letter_type ENUM('data_collection','case_study','institutional_access','other') DEFAULT 'data_collection',
        addressed_to VARCHAR(255) DEFAULT NULL,
        organization VARCHAR(255) DEFAULT NULL,
        purpose TEXT DEFAULT NULL,
        study_description TEXT DEFAULT NULL,
        data_collection_period VARCHAR(100) DEFAULT NULL,
        
        -- Approval workflow
        status ENUM('draft','pending','coordinator_approved','registrar_signed','rejected') DEFAULT 'pending',
        coordinator_approved_by INT DEFAULT NULL,
        coordinator_approved_at DATETIME DEFAULT NULL,
        coordinator_comments TEXT DEFAULT NULL,
        registrar_signed_by INT DEFAULT NULL,
        registrar_signed_at DATETIME DEFAULT NULL,
        registrar_signature_path VARCHAR(500) DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,
        
        -- Generated letter
        letter_reference VARCHAR(50) DEFAULT NULL,
        generated_file_path VARCHAR(500) DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_student (student_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Auto-create registrar_signatures table
$conn->query("
    CREATE TABLE IF NOT EXISTS registrar_signatures (
        signature_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        signatory_name VARCHAR(255) NOT NULL,
        signatory_title VARCHAR(255) DEFAULT 'University Registrar',
        signature_image_path VARCHAR(500) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$letter_types = [
    'data_collection' => 'Data Collection Introduction',
    'case_study' => 'Case Study Access',
    'institutional_access' => 'Institutional Access',
    'other' => 'Other',
];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_letter') {
        $letter_id = (int)($_POST['letter_id'] ?? 0);
        $comments = trim($_POST['coordinator_comments'] ?? '');
        
        if ($letter_id) {
            // Generate reference number: REF/EUMW/YYYY/XXXX
            $year = date('Y');
            $count_r = $conn->query("SELECT COUNT(*) AS c FROM dissertation_reference_letters WHERE YEAR(created_at) = $year");
            $count = ($count_r ? ($count_r->fetch_assoc()['c'] ?? 0) : 0) + 1;
            $ref_number = "REF/EUMW/{$year}/" . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            $uid = $_SESSION['vle_user_id'] ?? null;
            $stmt = $conn->prepare("
                UPDATE dissertation_reference_letters 
                SET status = 'coordinator_approved', 
                    coordinator_approved_by = ?, 
                    coordinator_approved_at = NOW(),
                    coordinator_comments = ?,
                    letter_reference = ?
                WHERE letter_id = ? AND status = 'pending'
            ");
            $stmt->bind_param("issi", $uid, $comments, $ref_number, $letter_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Letter approved with reference: <strong>$ref_number</strong>. Awaiting registrar signature.";
            } else {
                $error = 'Failed to approve letter or letter already processed.';
            }
        }
    } elseif ($action === 'reject_letter') {
        $letter_id = (int)($_POST['letter_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        
        if ($letter_id) {
            $stmt = $conn->prepare("UPDATE dissertation_reference_letters SET status = 'rejected', rejection_reason = ? WHERE letter_id = ?");
            $stmt->bind_param("si", $reason, $letter_id);
            if ($stmt->execute()) {
                $message = 'Letter request rejected.';
            }
        }
    } elseif ($action === 'registrar_sign') {
        $letter_id = (int)($_POST['letter_id'] ?? 0);
        
        if ($letter_id) {
            $uid = $_SESSION['vle_user_id'] ?? null;
            
            // Get active registrar signature
            $sig_q = $conn->query("SELECT * FROM registrar_signatures WHERE is_active = 1 LIMIT 1");
            $signature = $sig_q ? $sig_q->fetch_assoc() : null;
            $sig_path = $signature ? $signature['signature_image_path'] : null;
            
            $stmt = $conn->prepare("
                UPDATE dissertation_reference_letters 
                SET status = 'registrar_signed', 
                    registrar_signed_by = ?,
                    registrar_signed_at = NOW(),
                    registrar_signature_path = ?
                WHERE letter_id = ? AND status = 'coordinator_approved'
            ");
            $stmt->bind_param("isi", $uid, $sig_path, $letter_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = 'Letter signed by registrar. Students can now download it.';
            } else {
                $error = 'Failed to sign letter or letter not in correct state.';
            }
        }
    } elseif ($action === 'upload_signature') {
        $sig_name = trim($_POST['signatory_name'] ?? '');
        $sig_title = trim($_POST['signatory_title'] ?? 'University Registrar');
        
        if (empty($sig_name)) {
            $error = 'Signatory name is required.';
        } elseif (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a signature image.';
        } else {
            $file = $_FILES['signature_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $error = 'Signature must be PNG or JPG image.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'Signature image must not exceed 2MB.';
            } else {
                $sig_dir = '../uploads/signatures/';
                if (!is_dir($sig_dir)) mkdir($sig_dir, 0755, true);
                
                $safe_name = 'sig_' . time() . '.' . $ext;
                $dest = $sig_dir . $safe_name;
                
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    // Deactivate existing signatures
                    $conn->query("UPDATE registrar_signatures SET is_active = 0");
                    
                    $rel_path = 'uploads/signatures/' . $safe_name;
                    $uid = $_SESSION['vle_user_id'] ?? 0;
                    $stmt = $conn->prepare("INSERT INTO registrar_signatures (user_id, signatory_name, signatory_title, signature_image_path) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isss", $uid, $sig_name, $sig_title, $rel_path);
                    if ($stmt->execute()) {
                        $message = 'Registrar signature uploaded successfully.';
                    }
                } else {
                    $error = 'Failed to upload signature image.';
                }
            }
        }
    }
}

// Fetch letters by status
$pending_letters = [];
$approved_letters = [];
$signed_letters = [];
$rejected_letters = [];

$r = $conn->query("
    SELECT rl.*, d.title AS dissertation_title, d.program, d.topic_area,
           s.full_name AS student_name, s.email AS student_email,
           l.full_name AS supervisor_name
    FROM dissertation_reference_letters rl
    LEFT JOIN dissertations d ON rl.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON rl.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    ORDER BY rl.created_at DESC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        switch ($row['status']) {
            case 'pending': $pending_letters[] = $row; break;
            case 'coordinator_approved': $approved_letters[] = $row; break;
            case 'registrar_signed': $signed_letters[] = $row; break;
            case 'rejected': $rejected_letters[] = $row; break;
        }
    }
}

// Get active signature
$active_signature = null;
$sig_q = $conn->query("SELECT * FROM registrar_signatures WHERE is_active = 1 LIMIT 1");
if ($sig_q) $active_signature = $sig_q->fetch_assoc();

$page_title = 'Reference Letters';
$breadcrumbs = [['title' => 'Reference Letters']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .letter-card { border-left: 4px solid #6c757d; margin-bottom: 12px; }
        .letter-card.pending { border-left-color: #ffc107; }
        .letter-card.approved { border-left-color: #0d6efd; }
        .letter-card.signed { border-left-color: #198754; }
        .letter-card.rejected { border-left-color: #dc3545; }
        .workflow-step { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="bi bi-envelope-paper me-2"></i>Reference Letters</h3>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#signatureModal">
            <i class="bi bi-pen me-1"></i>Registrar Signature
        </button>
    </div>

    <!-- Workflow overview -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 justify-content-center">
                <div class="text-center px-3">
                    <div class="rounded-circle bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:50px;height:50px">
                        <span class="fw-bold text-warning fs-5"><?= count($pending_letters) ?></span>
                    </div>
                    <div class="small mt-1">Pending Review</div>
                </div>
                <div class="d-flex align-items-center text-muted"><i class="bi bi-arrow-right"></i></div>
                <div class="text-center px-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:50px;height:50px">
                        <span class="fw-bold text-primary fs-5"><?= count($approved_letters) ?></span>
                    </div>
                    <div class="small mt-1">Awaiting Signature</div>
                </div>
                <div class="d-flex align-items-center text-muted"><i class="bi bi-arrow-right"></i></div>
                <div class="text-center px-3">
                    <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:50px;height:50px">
                        <span class="fw-bold text-success fs-5"><?= count($signed_letters) ?></span>
                    </div>
                    <div class="small mt-1">Completed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Signature Status -->
    <?php if (!$active_signature): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No registrar signature uploaded. Letters cannot be fully signed until a signature is uploaded.</div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#pending">Pending (<?= count($pending_letters) ?>)</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#approved">Awaiting Signature (<?= count($approved_letters) ?>)</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#signed">Completed (<?= count($signed_letters) ?>)</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#rejected">Rejected (<?= count($rejected_letters) ?>)</a></li>
    </ul>

    <div class="tab-content">
        <!-- Pending Letters -->
        <div class="tab-pane fade show active" id="pending">
            <?php if (empty($pending_letters)): ?>
                <div class="card shadow-sm"><div class="card-body text-center py-4 text-muted">No pending letter requests.</div></div>
            <?php endif; ?>
            <?php foreach ($pending_letters as $lt): ?>
                <div class="card letter-card pending shadow-sm">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($lt['student_name'] ?? $lt['student_id']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($lt['program'] ?? '') ?></small>
                                <p class="mb-1 mt-2"><strong>Dissertation:</strong> <?= htmlspecialchars($lt['dissertation_title'] ?? 'N/A') ?></p>
                                <p class="mb-1"><strong>Type:</strong> <?= $letter_types[$lt['letter_type']] ?? $lt['letter_type'] ?></p>
                                <p class="mb-1"><strong>Addressed To:</strong> <?= htmlspecialchars($lt['addressed_to'] ?? 'N/A') ?></p>
                                <p class="mb-1"><strong>Organization:</strong> <?= htmlspecialchars($lt['organization'] ?? 'N/A') ?></p>
                                <?php if ($lt['data_collection_period']): ?>
                                    <p class="mb-1"><strong>Period:</strong> <?= htmlspecialchars($lt['data_collection_period']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($lt['purpose']): ?>
                                    <p><strong>Purpose:</strong><br><small><?= nl2br(htmlspecialchars($lt['purpose'])) ?></small></p>
                                <?php endif; ?>
                                <p class="text-muted"><small>Requested: <?= date('M j, Y h:i A', strtotime($lt['created_at'])) ?></small></p>
                                
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= $lt['letter_id'] ?>">
                                        <i class="bi bi-check-circle me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $lt['letter_id'] ?>">
                                        <i class="bi bi-x-circle me-1"></i>Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Approve Modal -->
                <div class="modal fade" id="approveModal<?= $lt['letter_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="action" value="approve_letter">
                                <input type="hidden" name="letter_id" value="<?= $lt['letter_id'] ?>">
                                <div class="modal-header bg-success bg-opacity-10">
                                    <h5 class="modal-title text-success"><i class="bi bi-check-circle me-2"></i>Approve Letter Request</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p><strong>Student:</strong> <?= htmlspecialchars($lt['student_name'] ?? '') ?></p>
                                    <p><strong>For:</strong> <?= htmlspecialchars($lt['organization'] ?? '') ?></p>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Coordinator Comments (Optional)</label>
                                        <textarea name="coordinator_comments" class="form-control" rows="3" placeholder="Any notes for the registrar or student..."></textarea>
                                    </div>
                                    <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>A reference number will be generated automatically. The letter will then await registrar signature.</div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Approve & Generate Reference</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Reject Modal -->
                <div class="modal fade" id="rejectModal<?= $lt['letter_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="action" value="reject_letter">
                                <input type="hidden" name="letter_id" value="<?= $lt['letter_id'] ?>">
                                <div class="modal-header bg-danger bg-opacity-10">
                                    <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-2"></i>Reject Letter Request</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Reason for Rejection</label>
                                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Explain why this request is rejected..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger">Reject</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Approved (awaiting registrar signature) -->
        <div class="tab-pane fade" id="approved">
            <?php if (empty($approved_letters)): ?>
                <div class="card shadow-sm"><div class="card-body text-center py-4 text-muted">No letters awaiting signature.</div></div>
            <?php endif; ?>
            <?php foreach ($approved_letters as $lt): ?>
                <div class="card letter-card approved shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-5">
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($lt['student_name'] ?? $lt['student_id']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($lt['dissertation_title'] ?? '') ?></small>
                                <p class="mb-0 mt-1"><strong>Ref:</strong> <code><?= htmlspecialchars($lt['letter_reference'] ?? '') ?></code></p>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-1"><strong>To:</strong> <?= htmlspecialchars($lt['organization'] ?? '') ?></p>
                                <p class="mb-0"><strong>Type:</strong> <?= $letter_types[$lt['letter_type']] ?? '' ?></p>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="print_reference_letter.php?letter_id=<?= $lt['letter_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-printer me-1"></i>Preview
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sign this letter as registrar?')">
                                    <input type="hidden" name="action" value="registrar_sign">
                                    <input type="hidden" name="letter_id" value="<?= $lt['letter_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-pen me-1"></i>Sign as Registrar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Signed / Completed -->
        <div class="tab-pane fade" id="signed">
            <?php if (empty($signed_letters)): ?>
                <div class="card shadow-sm"><div class="card-body text-center py-4 text-muted">No completed letters yet.</div></div>
            <?php endif; ?>
            <?php foreach ($signed_letters as $lt): ?>
                <div class="card letter-card signed shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-5">
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($lt['student_name'] ?? $lt['student_id']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($lt['dissertation_title'] ?? '') ?></small>
                            </div>
                            <div class="col-md-3">
                                <p class="mb-0"><strong>Ref:</strong> <code><?= htmlspecialchars($lt['letter_reference'] ?? '') ?></code></p>
                                <small>Signed: <?= $lt['registrar_signed_at'] ? date('M j, Y', strtotime($lt['registrar_signed_at'])) : '' ?></small>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="print_reference_letter.php?letter_id=<?= $lt['letter_id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-printer me-1"></i>Print Letter
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Rejected -->
        <div class="tab-pane fade" id="rejected">
            <?php if (empty($rejected_letters)): ?>
                <div class="card shadow-sm"><div class="card-body text-center py-4 text-muted">No rejected letters.</div></div>
            <?php endif; ?>
            <?php foreach ($rejected_letters as $lt): ?>
                <div class="card letter-card rejected shadow-sm">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($lt['student_name'] ?? $lt['student_id']) ?></h6>
                                <p class="mb-0"><?= htmlspecialchars($lt['dissertation_title'] ?? '') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-0 text-danger"><strong>Reason:</strong> <?= htmlspecialchars($lt['rejection_reason'] ?? 'No reason given') ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Registrar Signature Modal -->
<div class="modal fade" id="signatureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_signature">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pen me-2"></i>Registrar Signature</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($active_signature): ?>
                        <div class="alert alert-info">
                            <strong>Current signature:</strong> <?= htmlspecialchars($active_signature['signatory_name']) ?>
                            (<?= htmlspecialchars($active_signature['signatory_title']) ?>)
                            <br><img src="../<?= htmlspecialchars($active_signature['signature_image_path']) ?>" alt="Signature" style="max-height:60px;margin-top:8px;border:1px solid #ddd;padding:4px;border-radius:4px">
                        </div>
                        <hr>
                        <p class="small text-muted">Upload a new signature to replace the current one:</p>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Registrar Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="signatory_name" class="form-control" required placeholder="e.g. Dr. John Banda" value="<?= htmlspecialchars($active_signature['signatory_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Title</label>
                        <input type="text" name="signatory_title" class="form-control" placeholder="e.g. University Registrar" value="<?= htmlspecialchars($active_signature['signatory_title'] ?? 'University Registrar') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Signature Image <span class="text-danger">*</span></label>
                        <input type="file" name="signature_file" class="form-control" accept=".png,.jpg,.jpeg" required>
                        <small class="text-muted">PNG or JPG image of the registrar's signature (transparent PNG recommended, max 2MB)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Upload Signature</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
