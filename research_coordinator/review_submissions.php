<?php
/**
 * Research Coordinator - Review Submissions
 * Review student chapter submissions, provide feedback, approve/reject
 */
session_start();
require_once '../includes/auth.php';
require_once '../includes/dissertation_format_check.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

$phase_labels = [
    'topic' => 'Topic/Concept', 'concept_note' => 'Concept Note',
    'chapter1' => 'Chapter 1', 'chapter2' => 'Chapter 2', 'chapter3' => 'Chapter 3',
    'proposal' => 'Full Proposal', 'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Chapter 4', 'chapter5' => 'Chapter 5',
    'final_draft' => 'Final Draft', 'presentation' => 'Final Result Presentation', 'final_submission' => 'Final Submission'
];

// Handle file attachment upload for review feedback
function handleReviewAttachment($dissertation_id) {
    if (empty($_FILES['feedback_attachment']['name'])) return null;
    $file = $_FILES['feedback_attachment'];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['doc','docx','pdf','txt','rtf','odt','xls','xlsx','ppt','pptx','jpg','jpeg','png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 20 * 1024 * 1024) return null;
    $upload_dir = "../uploads/dissertation_feedback/{$dissertation_id}/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $filename = 'coord_review_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $path = $upload_dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $path)) {
        return "uploads/dissertation_feedback/{$dissertation_id}/{$filename}";
    }
    return null;
}

// Handle review actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
    $feedback_text = trim($_POST['feedback_text'] ?? '');
    $phase = $_POST['phase'] ?? '';
    
    if ($action === 'approve' && $submission_id) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE dissertation_submissions SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE submission_id = ?");
            $stmt->bind_param("ii", $_SESSION['vle_user_id'], $submission_id);
            $stmt->execute();
            
            $next_phase_map = [
                'chapter1' => ['chapter2_writing', 'chapter2'],
                'chapter2' => ['chapter3_writing', 'chapter3'],
                'chapter3' => ['proposal_submitted', 'proposal'],
                'proposal' => ['ethics_submitted', 'ethics'],
                'ethics'   => ['defense_listed', 'defense'],
                'chapter4' => ['chapter5_writing', 'chapter5'],
                'chapter5' => ['final_draft_submitted', 'final_draft'],
                'final_draft' => ['presentation_submitted', 'presentation'],
                'presentation' => ['defense_listed', 'presentation'],
            ];
            
            if (isset($next_phase_map[$phase])) {
                $next = $next_phase_map[$phase];
                $stmt2 = $conn->prepare("UPDATE dissertations SET status = ?, current_phase = ?, updated_at = NOW() WHERE dissertation_id = ?");
                $stmt2->bind_param("ssi", $next[0], $next[1], $dissertation_id);
                $stmt2->execute();
            }
            
            if (!empty($feedback_text)) {
                $attachment_path = handleReviewAttachment($dissertation_id);
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, ?, ?, ?, 'coordinator', ?, 'approval', ?)");
                $fb->bind_param("iisiss", $dissertation_id, $submission_id, $phase, $_SESSION['vle_user_id'], $feedback_text, $attachment_path);
                $fb->execute();
            }
            
            $conn->commit();
            $message = ucfirst($phase_labels[$phase] ?? $phase) . ' approved successfully.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error approving submission: ' . $e->getMessage();
        }
    } elseif ($action === 'request_revision' && $submission_id) {
        if (empty($feedback_text)) {
            $error = 'Please provide revision feedback.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE dissertation_submissions SET status = 'revision_requested', reviewed_at = NOW(), reviewed_by = ? WHERE submission_id = ?");
                $stmt->bind_param("ii", $_SESSION['vle_user_id'], $submission_id);
                $stmt->execute();
                
                $revision_status = $phase . '_revision';
                $stmt2 = $conn->prepare("UPDATE dissertations SET status = ?, updated_at = NOW() WHERE dissertation_id = ?");
                $stmt2->bind_param("si", $revision_status, $dissertation_id);
                $stmt2->execute();
                
                $attachment_path = handleReviewAttachment($dissertation_id);
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, ?, ?, ?, 'coordinator', ?, 'revision_request', ?)");
                $fb->bind_param("iisiss", $dissertation_id, $submission_id, $phase, $_SESSION['vle_user_id'], $feedback_text, $attachment_path);
                $fb->execute();
                
                $conn->commit();
                $message = 'Revision requested. Student has been notified.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reject' && $submission_id) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE dissertation_submissions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE submission_id = ?");
            $stmt->bind_param("ii", $_SESSION['vle_user_id'], $submission_id);
            $stmt->execute();
            
            if (!empty($feedback_text)) {
                $attachment_path = handleReviewAttachment($dissertation_id);
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, ?, ?, ?, 'coordinator', ?, 'rejection', ?)");
                $fb->bind_param("iisiss", $dissertation_id, $submission_id, $phase, $_SESSION['vle_user_id'], $feedback_text, $attachment_path);
                $fb->execute();
            }
            
            $conn->commit();
            $message = 'Submission rejected.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error: ' . $e->getMessage();
        }
    } elseif ($action === 'run_format_check' && $submission_id) {
        $result = runFormattingCheckOnSubmission($submission_id, $conn);
        if ($result) {
            $message = "Formatting check complete. Score: {$result['score']}% ({$result['passed_checks']}/{$result['total_checks']} checks passed).";
        } else {
            $error = 'Could not run formatting check. Ensure a DOCX file is uploaded.';
        }
    }
}

// View single submission
$view_sub = null;
$view_similarity = null;
$view_formatting = null;
if (isset($_GET['submission_id'])) {
    $sid = (int)$_GET['submission_id'];
    $stmt = $conn->prepare("
        SELECT ds.*, d.title as dissertation_title, d.student_id, d.dissertation_id,
               s.full_name as student_name, s.email as student_email,
               l.full_name as supervisor_name,
               u.username as reviewer_username
        FROM dissertation_submissions ds
        JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
        LEFT JOIN students s ON d.student_id = s.student_id
        LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
        LEFT JOIN users u ON ds.reviewed_by = u.user_id
        WHERE ds.submission_id = ?
    ");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $view_sub = $stmt->get_result()->fetch_assoc();
    
    if ($view_sub && $view_sub['similarity_check_id']) {
        $stmt2 = $conn->prepare("SELECT * FROM dissertation_similarity_checks WHERE check_id = ?");
        $stmt2->bind_param("i", $view_sub['similarity_check_id']);
        $stmt2->execute();
        $view_similarity = $stmt2->get_result()->fetch_assoc();
    }
}

// Get all pending submissions
$filter_phase = $_GET['phase'] ?? '';
$where = ["ds.status IN ('submitted','under_review')"];
$params = [];
$types = '';

if ($filter_phase) {
    $where[] = "ds.phase = ?";
    $params[] = $filter_phase;
    $types .= 's';
}

$where_sql = implode(' AND ', $where);
$sql = "
    SELECT ds.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name, l.full_name as supervisor_name
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE $where_sql
    ORDER BY ds.submitted_at ASC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Review Submissions';
$breadcrumbs = [['title' => 'Review Submissions']];
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
    <?php include '../includes/tinymce_head.php'; ?>
    <style>
        .phase-badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .score-circle { width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; }
        .formatting-item { padding:8px 12px; border-bottom:1px solid #eee; }
        .formatting-item:last-child { border-bottom:none; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if ($view_sub): ?>
    <!-- Single Submission Review -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2"></i>Review Submission</h3>
        <a href="review_submissions.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Submission Details -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= $phase_labels[$view_sub['phase']] ?? ucfirst($view_sub['phase']) ?> - Version <?= $view_sub['version'] ?></h5>
                        <span class="phase-badge bg-<?= $view_sub['status'] === 'approved' ? 'success' : ($view_sub['status'] === 'submitted' ? 'warning' : 'secondary') ?> bg-opacity-10 text-<?= $view_sub['status'] === 'approved' ? 'success' : ($view_sub['status'] === 'submitted' ? 'warning' : 'secondary') ?>">
                            <?= ucfirst(str_replace('_',' ',$view_sub['status'])) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Student</label>
                            <p class="mb-0 fw-bold"><?= htmlspecialchars($view_sub['student_name'] ?? $view_sub['student_id']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Supervisor</label>
                            <p class="mb-0"><?= htmlspecialchars($view_sub['supervisor_name'] ?? 'Not assigned') ?></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="text-muted small">Dissertation</label>
                            <p class="mb-0"><?= htmlspecialchars($view_sub['dissertation_title'] ?? 'Untitled') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Word Count</label>
                            <p class="mb-0 fw-bold"><?= number_format($view_sub['word_count'] ?? 0) ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Submitted</label>
                            <p class="mb-0"><?= $view_sub['submitted_at'] ? date('M j, Y H:i', strtotime($view_sub['submitted_at'])) : '-' ?></p>
                        </div>
                    </div>
                    
                    <?php if ($view_sub['file_path']): ?>
                    <div class="mt-3 d-flex gap-2 flex-wrap align-items-center">
                        <button class="btn btn-success" onclick="openDocEditor('<?= htmlspecialchars($view_sub['file_path']) ?>', '<?= htmlspecialchars(!empty($view_sub['file_name']) ? $view_sub['file_name'] : basename($view_sub['file_path'])) ?>')">
                            <i class="bi bi-eye me-1"></i>Open in System
                        </button>
                        <a href="../<?= htmlspecialchars($view_sub['file_path']) ?>" target="_blank" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open
                        </a>
                        <a href="../<?= htmlspecialchars($view_sub['file_path']) ?>" download class="btn btn-outline-primary">
                            <i class="bi bi-download me-1"></i>Download <?= htmlspecialchars($view_sub['file_name'] ?? 'Document') ?>
                            <small class="ms-1">(<?= round(($view_sub['file_size'] ?? 0) / 1024) ?> KB)</small>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($view_sub['submission_text']): ?>
                    <div class="mt-3">
                        <label class="text-muted small">Submission Text</label>
                        <div class="border rounded p-3 bg-light" style="max-height:400px; overflow-y:auto;">
                            <?= nl2br(htmlspecialchars($view_sub['submission_text'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Similarity & Formatting Reports -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Similarity Check</h6>
                        </div>
                        <div class="card-body text-center">
                            <?php if ($view_similarity): ?>
                                <div class="d-flex justify-content-center gap-3 mb-3">
                                    <div>
                                        <div class="score-circle mx-auto <?= $view_similarity['similarity_score'] > 30 ? 'bg-danger text-white' : ($view_similarity['similarity_score'] > 15 ? 'bg-warning' : 'bg-success text-white') ?>">
                                            <?= round($view_similarity['similarity_score']) ?>%
                                        </div>
                                        <small class="text-muted">Similarity</small>
                                    </div>
                                    <div>
                                        <div class="score-circle mx-auto <?= $view_similarity['ai_detection_score'] > 30 ? 'bg-danger text-white' : ($view_similarity['ai_detection_score'] > 15 ? 'bg-warning' : 'bg-success text-white') ?>">
                                            <?= round($view_similarity['ai_detection_score']) ?>%
                                        </div>
                                        <small class="text-muted">AI Content</small>
                                    </div>
                                </div>
                                <a href="similarity_reports.php?check_id=<?= $view_similarity['check_id'] ?>" class="btn btn-sm btn-outline-primary">View Full Report</a>
                            <?php else: ?>
                                <p class="text-muted py-3">No similarity check performed yet</p>
                                <form method="POST" action="similarity_reports.php">
                                    <input type="hidden" name="action" value="run_check">
                                    <input type="hidden" name="submission_id" value="<?= $view_sub['submission_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-play-circle me-1"></i>Run Check
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-file-earmark-ruled me-2"></i>Formatting Check</h6>
                        </div>
                        <div class="card-body">
                            <?php 
                                $fmt = $view_sub['formatting_check'] ? json_decode($view_sub['formatting_check'], true) : null;
                                $fmt_score = $view_sub['formatting_score'] ?? null;
                            ?>
                            <?php if ($fmt): ?>
                                <div class="text-center mb-3">
                                    <div class="score-circle mx-auto <?= $fmt_score >= 80 ? 'bg-success text-white' : ($fmt_score >= 60 ? 'bg-warning' : 'bg-danger text-white') ?>">
                                        <?= round($fmt_score) ?>%
                                    </div>
                                    <small class="text-muted">Formatting Score</small>
                                </div>
                                <?php foreach ($fmt as $check_item): if (is_array($check_item)): ?>
                                <div class="formatting-item">
                                    <i class="bi bi-<?= !empty($check_item['passed']) ? 'check-circle text-success' : 'x-circle text-danger' ?> me-2"></i>
                                    <small><strong><?= htmlspecialchars($check_item['name'] ?? '') ?></strong>: <?= htmlspecialchars($check_item['actual'] ?? '') ?> (expected: <?= htmlspecialchars($check_item['expected'] ?? '') ?>)</small>
                                </div>
                                <?php endif; endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-2">Formatting analysis pending</p>
                                <?php if ($view_sub['file_path'] && pathinfo($view_sub['file_name'] ?? '', PATHINFO_EXTENSION) === 'docx'): ?>
                                <form method="POST" class="text-center">
                                    <input type="hidden" name="action" value="run_format_check">
                                    <input type="hidden" name="submission_id" value="<?= $view_sub['submission_id'] ?>">
                                    <input type="hidden" name="dissertation_id" value="<?= $view_sub['dissertation_id'] ?>">
                                    <input type="hidden" name="phase" value="<?= $view_sub['phase'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-fill me-1"></i>Run Format Check</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review Actions -->
            <?php if (in_array($view_sub['status'], ['submitted', 'under_review'])): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Review Actions</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="submission_id" value="<?= $view_sub['submission_id'] ?>">
                        <input type="hidden" name="dissertation_id" value="<?= $view_sub['dissertation_id'] ?>">
                        <input type="hidden" name="phase" value="<?= $view_sub['phase'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Feedback / Comments</label>
                            <textarea name="feedback_text" id="rc_feedback_text" class="form-control" rows="4" placeholder="Provide detailed feedback for the student..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                            <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                            <div class="form-text">Max 20 MB. Attach annotated document, rubric, or reference material.</div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="approve" class="btn btn-success">
                                <i class="bi bi-check-circle me-1"></i>Approve & Advance
                            </button>
                            <button type="submit" name="action" value="request_revision" class="btn btn-warning">
                                <i class="bi bi-arrow-repeat me-1"></i>Request Revision
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                <i class="bi bi-x-circle me-1"></i>Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right sidebar: Guidelines for this phase -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-book me-2"></i>Guidelines: <?= $phase_labels[$view_sub['phase']] ?? '' ?></h6>
                </div>
                <div class="card-body">
                    <?php
                        $g_stmt = $conn->prepare("SELECT * FROM dissertation_guidelines WHERE phase = ? AND is_active = 1 ORDER BY section_order");
                        $g_stmt->bind_param("s", $view_sub['phase']);
                        $g_stmt->execute();
                        $guidelines = $g_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    ?>
                    <?php if (empty($guidelines)): ?>
                        <p class="text-muted small">No specific guidelines for this phase.</p>
                    <?php else: ?>
                        <?php foreach ($guidelines as $g): ?>
                        <div class="mb-3">
                            <h6 class="small fw-bold"><?= htmlspecialchars($g['section_title']) ?></h6>
                            <p class="small text-muted mb-1"><?= nl2br(htmlspecialchars($g['content'])) ?></p>
                            <?php if ($g['min_pages'] || $g['max_pages']): ?>
                                <span class="badge bg-info bg-opacity-10 text-info">
                                    <?= $g['min_pages'] ?>-<?= $g['max_pages'] ?> pages
                                </span>
                            <?php endif; ?>
                            <?php if ($g['min_word_count'] || $g['max_word_count']): ?>
                                <span class="badge bg-info bg-opacity-10 text-info">
                                    <?= number_format($g['min_word_count'] ?? 0) ?>-<?= number_format($g['max_word_count'] ?? 0) ?> words
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Submissions List -->
    <h3 class="fw-bold mb-4"><i class="bi bi-file-earmark-check me-2"></i>Review Submissions</h3>
    
    <!-- Filter -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small">Filter by Phase</label>
                    <select class="form-select" name="phase">
                        <option value="">All Phases</option>
                        <?php foreach ($phase_labels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $filter_phase === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="review_submissions.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Dissertation</th>
                            <th>Phase</th>
                            <th>Version</th>
                            <th>Words</th>
                            <th>Supervisor</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No pending submissions</td></tr>
                        <?php else: ?>
                            <?php foreach ($pending as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['student_name'] ?? $p['student_id'] ?? '') ?></strong></td>
                                <td><small><?= htmlspecialchars(mb_strimwidth($p['dissertation_title'] ?? '', 0, 35, '...')) ?></small></td>
                                <td><span class="phase-badge bg-primary bg-opacity-10 text-primary"><?= $phase_labels[$p['phase']] ?? '' ?></span></td>
                                <td>v<?= $p['version'] ?></td>
                                <td><?= number_format($p['word_count'] ?? 0) ?></td>
                                <td><small><?= htmlspecialchars($p['supervisor_name'] ?? '-') ?></small></td>
                                <td><small><?= $p['submitted_at'] ? date('M j H:i', strtotime($p['submitted_at'])) : '-' ?></small></td>
                                <td>
                                    <a href="?submission_id=<?= $p['submission_id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-eye me-1"></i>Review
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Document Viewer Modal (same as supervisor) -->
<div class="modal fade" id="docEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#1e3a5f;">
                <div class="d-flex align-items-center">
                    <i class="bi bi-file-earmark-richtext text-white me-2"></i>
                    <h6 class="modal-title text-white mb-0" id="docEditorTitle">Document</h6>
                    <span id="docFormatBadge" class="badge bg-light text-dark ms-2" style="font-size:0.7rem;"></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" id="modeToggleGroup">
                        <button type="button" id="readModeBtn" class="btn btn-light"><i class="bi bi-eye me-1"></i>Read</button>
                        <button type="button" id="editModeBtn" class="btn btn-outline-light"><i class="bi bi-pencil me-1"></i>Edit</button>
                    </div>
                    <button type="button" id="saveDocBtn" class="btn btn-success btn-sm d-none">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i>Save as DOCX
                    </button>
                    <a id="docDownloadBtn" href="#" download class="btn btn-outline-light btn-sm">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <a id="docOpenTabBtn" href="#" target="_blank" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open
                    </a>
                    <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="background:#f1f5f9;">
                <div id="docLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading&hellip;</span></div>
                    <p class="mt-3 text-muted">Converting document&hellip;</p>
                </div>
                <div id="docError" class="d-none text-center py-5">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size:3rem;"></i>
                    <p class="mt-2 text-muted" id="docErrorMsg">Unable to load document.</p>
                    <a id="docErrorDownload" href="#" download class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Download instead</a>
                </div>
                <div id="docReadView" class="d-none" style="max-width:960px; margin:0 auto; background:#fff; min-height:calc(100vh - 56px); padding:40px 48px; box-shadow:0 0 20px rgba(0,0,0,0.06); font-family:'Calibri','Segoe UI',sans-serif; font-size:11pt; line-height:1.6;"></div>
                <div id="docEditView" class="d-none" style="background:#fff; padding:10px;">
                    <textarea id="tinymceDocEditor" style="width:100%;"></textarea>
                </div>
                <iframe id="pdfFrame" class="d-none" style="width:100%; height:calc(100vh - 56px); border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
var docState = { editorReady: false, html: '', filePath: '', fileName: '', editing: false, isPdf: false };

function openDocEditor(filePath, fileName) {
    ['docLoading','docError','docReadView','docEditView','pdfFrame'].forEach(function(id) {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById('docLoading').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.add('d-none');
    document.getElementById('modeToggleGroup').classList.remove('d-none');
    setMode('read');

    docState.filePath = filePath;
    docState.fileName = fileName || decodeURIComponent(filePath.split('/').pop());
    docState.html = '';
    docState.editing = false;
    docState.isPdf = false;

    document.getElementById('docEditorTitle').textContent = docState.fileName;
    document.getElementById('docDownloadBtn').href = '../' + filePath;
    document.getElementById('docDownloadBtn').setAttribute('download', docState.fileName);
    document.getElementById('docOpenTabBtn').href = '../' + filePath;
    document.getElementById('docErrorDownload').href = '../' + filePath;
    document.getElementById('docErrorDownload').setAttribute('download', docState.fileName);

    var ext = filePath.split('.').pop().toLowerCase();
    document.getElementById('docFormatBadge').textContent = '.' + ext;

    var modal = new bootstrap.Modal(document.getElementById('docEditorModal'));
    modal.show();

    fetch('../api/export_docx.php?file=' + encodeURIComponent(filePath), { credentials: 'same-origin' })
        .then(function(r) {
            var status = r.status;
            return r.text().then(function(text) {
                var data;
                try { data = JSON.parse(text); }
                catch (e) {
                    if (status === 302 || status === 0 || text === '') {
                        throw new Error('Session expired. Please refresh the page and log in again.');
                    }
                    throw new Error('Server returned an unreadable response. Try downloading the file instead.');
                }
                return {ok: status >= 200 && status < 300, data: data};
            });
        })
        .then(function(res) {
            document.getElementById('docLoading').classList.add('d-none');
            if (!res.ok) { throw new Error((res.data && res.data.error) ? res.data.error : 'Conversion failed'); }
            if (res.data.format === 'pdf') {
                docState.isPdf = true;
                document.getElementById('pdfFrame').src = '../' + res.data.url;
                document.getElementById('pdfFrame').classList.remove('d-none');
                document.getElementById('modeToggleGroup').classList.add('d-none');
            } else if (res.data.format === 'google_docs') {
                // Open in Google Docs Viewer (requires publicly accessible URL)
                var absUrl = res.data.abs_url || '';
                if (absUrl) {
                    docState.isPdf = true;
                    document.getElementById('pdfFrame').src = 'https://docs.google.com/viewer?url=' + encodeURIComponent(absUrl) + '&embedded=true';
                    document.getElementById('pdfFrame').classList.remove('d-none');
                    document.getElementById('modeToggleGroup').classList.add('d-none');
                } else {
                    var fileUrl = '../' + (res.data.url || docState.filePath);
                    var html = '<div style="text-align:center;padding:60px 24px;"><p>Preview unavailable.</p>' +
                        '<a href="' + fileUrl + '" download class="btn btn-primary"><i class="bi bi-download me-1"></i>Download</a></div>';
                    docState.html = html;
                    document.getElementById('docReadView').innerHTML = html;
                    document.getElementById('docReadView').classList.remove('d-none');
                    document.getElementById('modeToggleGroup').classList.add('d-none');
                }
            } else {
                var html = res.data.html || '';
                docState.html = html;
                document.getElementById('docReadView').innerHTML = html;
                document.getElementById('docReadView').classList.remove('d-none');
            }
        })
        .catch(function(err) {
            document.getElementById('docLoading').classList.add('d-none');
            document.getElementById('docErrorMsg').textContent = (err && err.message) ? err.message : 'Unable to load document. Please use the Download button instead.';
            document.getElementById('docError').classList.remove('d-none');
            document.getElementById('modeToggleGroup').classList.add('d-none');
        });
}

function setMode(mode) {
    document.getElementById('readModeBtn').className = mode === 'read' ? 'btn btn-light' : 'btn btn-outline-light';
    document.getElementById('editModeBtn').className = mode === 'edit' ? 'btn btn-light' : 'btn btn-outline-light';
}

document.getElementById('readModeBtn').addEventListener('click', function() {
    if (docState.editorReady && docState.editing) {
        var ed = tinymce.get('tinymceDocEditor');
        if (ed) docState.html = ed.getContent();
        document.getElementById('docReadView').innerHTML = docState.html;
    }
    document.getElementById('docEditView').classList.add('d-none');
    document.getElementById('docReadView').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.add('d-none');
    docState.editing = false;
    setMode('read');
});

document.getElementById('editModeBtn').addEventListener('click', function() {
    if (!docState.html || docState.isPdf) return;
    document.getElementById('docReadView').classList.add('d-none');
    document.getElementById('docEditView').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.remove('d-none');
    docState.editing = true;
    setMode('edit');
    if (!docState.editorReady) {
        if (typeof initTinyMCE === 'function') {
            initTinyMCE('#tinymceDocEditor', { mode: 'full', height: Math.max(500, window.innerHeight - 120) });
        }
        var checkReady = setInterval(function() {
            var ed = typeof tinymce !== 'undefined' && tinymce.get('tinymceDocEditor');
            if (ed && ed.initialized) {
                clearInterval(checkReady);
                docState.editorReady = true;
                ed.setContent(docState.html);
            }
        }, 100);
    } else {
        var ed = tinymce.get('tinymceDocEditor');
        if (ed) ed.setContent(docState.html);
    }
});

document.getElementById('saveDocBtn').addEventListener('click', function() {
    var ed = typeof tinymce !== 'undefined' && tinymce.get('tinymceDocEditor');
    if (!ed) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    var form = document.createElement('form');
    form.method = 'POST'; form.action = '../api/export_docx.php';
    form.target = '_blank'; form.style.display = 'none';
    var h = document.createElement('input'); h.type='hidden'; h.name='html_content'; h.value = ed.getContent();
    var f = document.createElement('input'); f.type='hidden'; f.name='filename'; f.value = docState.fileName;
    form.appendChild(h); form.appendChild(f);
    document.body.appendChild(form); form.submit();
    setTimeout(function() { document.body.removeChild(form); btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-arrow-down me-1"></i>Save as DOCX'; }, 1500);
});

document.getElementById('docEditorModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('docReadView').innerHTML = '';
    document.getElementById('pdfFrame').src = '';
    if (typeof tinymce !== 'undefined') {
        var ed = tinymce.get('tinymceDocEditor');
        if (ed) { ed.destroy(); docState.editorReady = false; }
    }
    docState.html = ''; docState.editing = false; docState.isPdf = false;
});

if (typeof initTinyMCE === 'function' && document.getElementById('rc_feedback_text')) {
    initTinyMCE('#rc_feedback_text', { mode: 'dissertation', height: 250 });
}
</script>
</body>
</html>
