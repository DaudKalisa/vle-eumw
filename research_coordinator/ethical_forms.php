<?php
/**
 * Research Coordinator - Ethical Forms Management
 * Upload ethical clearance forms for students to download
 * Manage ethics submissions from students
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

// Auto-create ethical_forms table for template forms uploaded by coordinator
$conn->query("
    CREATE TABLE IF NOT EXISTS dissertation_ethical_forms (
        form_id INT AUTO_INCREMENT PRIMARY KEY,
        form_name VARCHAR(255) NOT NULL,
        form_description TEXT DEFAULT NULL,
        form_type ENUM('ethical_clearance','informed_consent','data_protection','irb_application','other') DEFAULT 'ethical_clearance',
        file_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT DEFAULT 0,
        is_required TINYINT(1) DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        uploaded_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (form_type),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$upload_dir = '../uploads/ethical_forms/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$form_types = [
    'ethical_clearance' => 'Ethical Clearance Form',
    'informed_consent' => 'Informed Consent Form',
    'data_protection' => 'Data Protection Form',
    'irb_application' => 'IRB Application Form',
    'other' => 'Other Form',
];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_form') {
        $form_name = trim($_POST['form_name'] ?? '');
        $form_description = trim($_POST['form_description'] ?? '');
        $form_type = $_POST['form_type'] ?? 'ethical_clearance';
        $is_required = isset($_POST['is_required']) ? 1 : 0;

        if (empty($form_name)) {
            $error = 'Form name is required.';
        } elseif (!isset($_FILES['form_file']) || $_FILES['form_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a file to upload.';
        } else {
            $file = $_FILES['form_file'];
            $allowed = ['pdf', 'doc', 'docx', 'xlsx', 'xls'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed)) {
                $error = 'Only PDF, DOC, DOCX, XLS, XLSX files are allowed.';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $error = 'File size must not exceed 10MB.';
            } else {
                $safe_name = 'ethical_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                $dest = $upload_dir . $safe_name;
                
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $rel_path = 'uploads/ethical_forms/' . $safe_name;
                    $stmt = $conn->prepare("
                        INSERT INTO dissertation_ethical_forms (form_name, form_description, form_type, file_path, file_name, file_size, is_required, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $uid = $_SESSION['vle_user_id'] ?? null;
                    $stmt->bind_param("sssssiii", $form_name, $form_description, $form_type, $rel_path, $file['name'], $file['size'], $is_required, $uid);
                    if ($stmt->execute()) {
                        $message = "Form <strong>" . htmlspecialchars($form_name) . "</strong> uploaded successfully.";
                    } else {
                        $error = 'Failed to save form record.';
                        @unlink($dest);
                    }
                } else {
                    $error = 'Failed to upload file. Check directory permissions.';
                }
            }
        }
    } elseif ($action === 'toggle_form') {
        $form_id = (int)($_POST['form_id'] ?? 0);
        if ($form_id) {
            $stmt = $conn->prepare("UPDATE dissertation_ethical_forms SET is_active = NOT is_active WHERE form_id = ?");
            $stmt->bind_param("i", $form_id);
            $stmt->execute();
            $message = 'Form status updated.';
        }
    } elseif ($action === 'delete_form') {
        $form_id = (int)($_POST['form_id'] ?? 0);
        if ($form_id) {
            $stmt = $conn->prepare("SELECT file_path FROM dissertation_ethical_forms WHERE form_id = ?");
            $stmt->bind_param("i", $form_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $full_path = '../' . $row['file_path'];
                if (file_exists($full_path)) @unlink($full_path);
                $del = $conn->prepare("DELETE FROM dissertation_ethical_forms WHERE form_id = ?");
                $del->bind_param("i", $form_id);
                $del->execute();
                $message = 'Form deleted.';
            }
        }
    } elseif ($action === 'approve_ethics') {
        $ethics_id = (int)($_POST['ethics_id'] ?? 0);
        $reviewer_notes = trim($_POST['reviewer_notes'] ?? '');
        if ($ethics_id) {
            $conn->begin_transaction();
            try {
                // Update ethics submission
                $stmt = $conn->prepare("UPDATE dissertation_ethics SET status = 'approved', reviewer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE ethics_id = ?");
                $uid = $_SESSION['vle_user_id'] ?? 0;
                $stmt->bind_param("sii", $reviewer_notes, $uid, $ethics_id);
                $stmt->execute();

                // Get dissertation_id
                $stmt2 = $conn->prepare("SELECT dissertation_id FROM dissertation_ethics WHERE ethics_id = ?");
                $stmt2->bind_param("i", $ethics_id);
                $stmt2->execute();
                $eth = $stmt2->get_result()->fetch_assoc();

                if ($eth) {
                    // Move dissertation to defense phase
                    $stmt3 = $conn->prepare("UPDATE dissertations SET status = 'defense_listed', current_phase = 'defense', updated_at = NOW() WHERE dissertation_id = ?");
                    $stmt3->bind_param("i", $eth['dissertation_id']);
                    $stmt3->execute();
                }

                $conn->commit();
                $message = 'Ethics form approved. Student is now listed for defense scheduling.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error approving ethics: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'revision_ethics') {
        $ethics_id = (int)($_POST['ethics_id'] ?? 0);
        $reviewer_notes = trim($_POST['reviewer_notes'] ?? '');
        if ($ethics_id && !empty($reviewer_notes)) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE dissertation_ethics SET status = 'revision_required', reviewer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE ethics_id = ?");
                $uid = $_SESSION['vle_user_id'] ?? 0;
                $stmt->bind_param("sii", $reviewer_notes, $uid, $ethics_id);
                $stmt->execute();

                $stmt2 = $conn->prepare("SELECT dissertation_id FROM dissertation_ethics WHERE ethics_id = ?");
                $stmt2->bind_param("i", $ethics_id);
                $stmt2->execute();
                $eth = $stmt2->get_result()->fetch_assoc();

                if ($eth) {
                    $stmt3 = $conn->prepare("UPDATE dissertations SET status = 'ethics_revision', updated_at = NOW() WHERE dissertation_id = ?");
                    $stmt3->bind_param("i", $eth['dissertation_id']);
                    $stmt3->execute();
                }

                $conn->commit();
                $message = 'Revision requested. Student will need to resubmit the ethics form.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error requesting revision: ' . $e->getMessage();
            }
        } else {
            $error = 'Please provide feedback when requesting a revision.';
        }
    } elseif ($action === 'reject_ethics') {
        $ethics_id = (int)($_POST['ethics_id'] ?? 0);
        $reviewer_notes = trim($_POST['reviewer_notes'] ?? '');
        if ($ethics_id) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE dissertation_ethics SET status = 'rejected', reviewer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE ethics_id = ?");
                $uid = $_SESSION['vle_user_id'] ?? 0;
                $stmt->bind_param("sii", $reviewer_notes, $uid, $ethics_id);
                $stmt->execute();

                $stmt2 = $conn->prepare("SELECT dissertation_id FROM dissertation_ethics WHERE ethics_id = ?");
                $stmt2->bind_param("i", $ethics_id);
                $stmt2->execute();
                $eth = $stmt2->get_result()->fetch_assoc();

                if ($eth) {
                    $stmt3 = $conn->prepare("UPDATE dissertations SET status = 'ethics_revision', updated_at = NOW() WHERE dissertation_id = ?");
                    $stmt3->bind_param("i", $eth['dissertation_id']);
                    $stmt3->execute();
                }

                $conn->commit();
                $message = 'Ethics form rejected.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error rejecting ethics: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all forms
$forms = [];
$r = $conn->query("SELECT * FROM dissertation_ethical_forms ORDER BY form_type ASC, created_at DESC");
if ($r) while ($row = $r->fetch_assoc()) $forms[] = $row;

// Fetch student ethics submissions
$submissions = [];
$r = $conn->query("
    SELECT de.*, d.title AS dissertation_title, d.student_id, d.current_phase, d.status AS diss_status,
           s.full_name AS student_name, s.program,
           u.username AS reviewer_name
    FROM dissertation_ethics de
    JOIN dissertations d ON de.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN users u ON de.reviewed_by = u.user_id
    ORDER BY FIELD(de.status, 'pending', 'revision_requested', 'under_review', 'approved', 'rejected'), de.submitted_at DESC
    LIMIT 50
");
if ($r) while ($row = $r->fetch_assoc()) $submissions[] = $row;

$page_title = 'Ethical Forms';
$breadcrumbs = [['title' => 'Ethical Forms']];
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
        <h3 class="fw-bold mb-0"><i class="bi bi-file-earmark-medical me-2"></i>Ethical Forms Management</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadFormModal">
            <i class="bi bi-cloud-upload me-1"></i>Upload Form
        </button>
    </div>

    <!-- Uploaded Template Forms -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-files text-primary me-2"></i>Template Forms for Students (<?= count($forms) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($forms)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-4 text-muted"></i>
                    <p class="mt-2 text-muted">No ethical forms uploaded yet. Upload forms for students to download.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Form Name</th>
                                <th>Type</th>
                                <th>File</th>
                                <th>Size</th>
                                <th>Required</th>
                                <th>Status</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $f): ?>
                            <tr class="<?= $f['is_active'] ? '' : 'text-muted' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($f['form_name']) ?></strong>
                                    <?php if ($f['form_description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($f['form_description'], 0, 60, '...')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-info"><?= $form_types[$f['form_type']] ?? $f['form_type'] ?></span></td>
                                <td>
                                    <a href="../<?= htmlspecialchars($f['file_path']) ?>" target="_blank" class="text-decoration-none">
                                        <i class="bi bi-file-earmark-arrow-down me-1"></i><?= htmlspecialchars($f['file_name']) ?>
                                    </a>
                                </td>
                                <td><small><?= round($f['file_size'] / 1024) ?> KB</small></td>
                                <td>
                                    <?php if ($f['is_required']): ?>
                                        <span class="badge bg-danger">Required</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Optional</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($f['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= date('M j, Y', strtotime($f['created_at'])) ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_form">
                                        <input type="hidden" name="form_id" value="<?= $f['form_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $f['is_active'] ? 'warning' : 'success' ?>" title="<?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <i class="bi bi-<?= $f['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this form?')">
                                        <input type="hidden" name="action" value="delete_form">
                                        <input type="hidden" name="form_id" value="<?= $f['form_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Ethics Submissions -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-person-check text-success me-2"></i>Student Ethics Submissions</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($submissions)): ?>
                <p class="text-muted text-center py-4">No ethics submissions from students yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Dissertation</th>
                                <th>Summary</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Files</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $sub): ?>
                            <?php
                                $sc = 'secondary';
                                if ($sub['status'] === 'approved') $sc = 'success';
                                elseif ($sub['status'] === 'pending' || $sub['status'] === 'under_review') $sc = 'warning';
                                elseif ($sub['status'] === 'revision_required') $sc = 'info';
                                elseif ($sub['status'] === 'rejected') $sc = 'danger';
                                $is_pending = in_array($sub['status'], ['pending', 'under_review', 'submitted']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($sub['student_name'] ?? $sub['student_id']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($sub['program'] ?? '') ?></small>
                                </td>
                                <td><small><?= htmlspecialchars(mb_strimwidth($sub['dissertation_title'] ?? '', 0, 40, '...')) ?></small></td>
                                <td><small><?= htmlspecialchars(mb_strimwidth($sub['research_summary'] ?? '-', 0, 60, '...')) ?></small></td>
                                <td>
                                    <span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_', ' ', $sub['status'])) ?></span>
                                    <?php if (!empty($sub['reviewer_name']) && $sub['status'] !== 'pending'): ?>
                                        <br><small class="text-muted">by <?= htmlspecialchars($sub['reviewer_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($sub['reviewer_notes'])): ?>
                                        <br><small class="text-muted fst-italic"><?= htmlspecialchars(mb_strimwidth($sub['reviewer_notes'], 0, 50, '...')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= $sub['submitted_at'] ? date('M j, Y', strtotime($sub['submitted_at'])) : '-' ?></small></td>
                                <td>
                                    <?php if (!empty($sub['ethics_form_path'])): ?>
                                        <a href="../<?= htmlspecialchars($sub['ethics_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Ethics Form">
                                            <i class="bi bi-file-earmark"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($sub['consent_form_path'])): ?>
                                        <a href="../<?= htmlspecialchars($sub['consent_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Consent Form">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_pending): ?>
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $sub['ethics_id'] ?>" title="Review">
                                            <i class="bi bi-check-circle me-1"></i>Review
                                        </button>
                                    <?php elseif ($sub['status'] === 'approved'): ?>
                                        <span class="text-success"><i class="bi bi-check-circle-fill"></i> Approved</span>
                                    <?php elseif ($sub['status'] === 'rejected'): ?>
                                        <span class="text-danger"><i class="bi bi-x-circle-fill"></i> Rejected</span>
                                    <?php elseif ($sub['status'] === 'revision_required'): ?>
                                        <span class="text-info"><i class="bi bi-arrow-repeat"></i> Revision Required</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Review Modals for pending submissions -->
    <?php foreach ($submissions as $sub): ?>
    <?php if (in_array($sub['status'], ['pending', 'under_review', 'submitted'])): ?>
    <div class="modal fade" id="reviewModal<?= $sub['ethics_id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Review Ethics Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Student:</strong> <?= htmlspecialchars($sub['student_name'] ?? $sub['student_id']) ?></p>
                            <p class="mb-1"><strong>Program:</strong> <?= htmlspecialchars($sub['program'] ?? '-') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Dissertation:</strong> <?= htmlspecialchars($sub['dissertation_title'] ?? '-') ?></p>
                            <p class="mb-1"><strong>Submitted:</strong> <?= $sub['submitted_at'] ? date('M j, Y g:i A', strtotime($sub['submitted_at'])) : '-' ?></p>
                        </div>
                    </div>
                    <?php if (!empty($sub['research_summary'])): ?>
                    <div class="mb-3">
                        <strong>Research Summary:</strong>
                        <div class="border rounded p-2 bg-light mt-1"><small><?= nl2br(htmlspecialchars($sub['research_summary'])) ?></small></div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <strong>Uploaded Files:</strong>
                        <div class="mt-1">
                            <?php if (!empty($sub['ethics_form_path'])): ?>
                                <a href="../<?= htmlspecialchars($sub['ethics_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-2">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>View Ethics Form
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($sub['consent_form_path'])): ?>
                                <a href="../<?= htmlspecialchars($sub['consent_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-file-earmark-text me-1"></i>View Consent Form
                                </a>
                            <?php endif; ?>
                            <?php if (empty($sub['ethics_form_path']) && empty($sub['consent_form_path'])): ?>
                                <span class="text-muted">No files uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reviewer Notes (optional for approval, required for revision)</label>
                        <textarea class="form-control" id="reviewerNotes<?= $sub['ethics_id'] ?>" rows="3" placeholder="Enter your review comments..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="reject_ethics">
                        <input type="hidden" name="ethics_id" value="<?= $sub['ethics_id'] ?>">
                        <input type="hidden" name="reviewer_notes" class="reviewerNotesInput" data-source="reviewerNotes<?= $sub['ethics_id'] ?>">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this ethics form?')">
                            <i class="bi bi-x-circle me-1"></i>Reject
                        </button>
                    </form>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="revision_ethics">
                        <input type="hidden" name="ethics_id" value="<?= $sub['ethics_id'] ?>">
                        <input type="hidden" name="reviewer_notes" class="reviewerNotesInput" data-source="reviewerNotes<?= $sub['ethics_id'] ?>">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-arrow-repeat me-1"></i>Request Revision
                        </button>
                    </form>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="approve_ethics">
                        <input type="hidden" name="ethics_id" value="<?= $sub['ethics_id'] ?>">
                        <input type="hidden" name="reviewer_notes" class="reviewerNotesInput" data-source="reviewerNotes<?= $sub['ethics_id'] ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Approve &amp; Advance to Defense
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Upload Form Modal -->
<div class="modal fade" id="uploadFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_form">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Upload Ethical Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Form Name <span class="text-danger">*</span></label>
                        <input type="text" name="form_name" class="form-control" placeholder="e.g. Ethical Clearance Application Form" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Form Type</label>
                        <select name="form_type" class="form-select">
                            <?php foreach ($form_types as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="form_description" class="form-control" rows="2" placeholder="Brief description of the form..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">File <span class="text-danger">*</span></label>
                        <input type="file" name="form_file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                        <small class="text-muted">Accepted: PDF, DOC, DOCX, XLS, XLSX (max 10MB)</small>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_required" id="isRequired" checked>
                        <label class="form-check-label" for="isRequired">Mark as required for all students</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sync textarea values to hidden inputs before form submission
document.querySelectorAll('.reviewerNotesInput').forEach(function(input) {
    input.closest('form').addEventListener('submit', function() {
        var sourceId = input.getAttribute('data-source');
        var textarea = document.getElementById(sourceId);
        if (textarea) input.value = textarea.value;
    });
});
</script>
</body>
</html>
