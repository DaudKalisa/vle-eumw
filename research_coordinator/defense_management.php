<?php
/**
 * Research Coordinator - Defense Management
 * Schedule defenses, create defense lists, enter grades
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

$phase_labels = [
    'topic' => 'Topic', 'concept_note' => 'Concept Note',
    'chapter1' => 'Ch 1', 'chapter2' => 'Ch 2', 'chapter3' => 'Ch 3',
    'proposal' => 'Proposal', 'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Ch 4', 'chapter5' => 'Ch 5',
    'final_draft' => 'Final Draft', 'presentation' => 'Final Result Presentation', 'final_submission' => 'Final'
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_template') {
        // RC uploads a PowerPoint template for students
        $template_name = trim($_POST['template_name'] ?? '');
        $template_type = $_POST['template_type'] ?? 'proposal';
        $description = trim($_POST['description'] ?? '');
        
        if (in_array($template_type, ['proposal', 'final']) && !empty($template_name) && !empty($_FILES['template_file']['name'])) {
            $file = $_FILES['template_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['ppt', 'pptx', 'pdf', 'doc', 'docx'];
            
            if (!in_array($ext, $allowed)) {
                $error = 'Invalid file type. Only PPT, PPTX, PDF, DOC, DOCX allowed.';
            } elseif ($file['size'] > 20 * 1024 * 1024) {
                $error = 'File too large. Maximum 20MB.';
            } else {
                $safe_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                $filename = time() . '_' . $safe_name . '.' . $ext;
                $dest = '../uploads/dissertations/templates/' . $filename;
                $rel_path = 'uploads/dissertations/templates/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $stmt = $conn->prepare("INSERT INTO defense_templates (template_name, template_type, file_path, original_filename, uploaded_by, description) VALUES (?, ?, ?, ?, ?, ?)");
                    $orig = $file['name'];
                    $uid = $user['user_id'];
                    $stmt->bind_param("ssssds", $template_name, $template_type, $rel_path, $orig, $uid, $description);
                    $stmt->execute();
                    $message = 'Template uploaded successfully.';
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'delete_template') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        if ($template_id) {
            $stmt = $conn->prepare("SELECT file_path FROM defense_templates WHERE template_id = ?");
            $stmt->bind_param("i", $template_id);
            $stmt->execute();
            $tmpl = $stmt->get_result()->fetch_assoc();
            if ($tmpl) {
                $full = '../' . $tmpl['file_path'];
                if (file_exists($full)) unlink($full);
                $conn->prepare("DELETE FROM defense_templates WHERE template_id = ?")->bind_param("i", $template_id);
                $conn->query("DELETE FROM defense_templates WHERE template_id = $template_id");
                $message = 'Template deleted.';
            }
        }
    } elseif ($action === 'schedule_defense') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $defense_type = $_POST['defense_type'] ?? 'proposal';
        $defense_date_raw = $_POST['defense_date'] ?? '';
        $venue = trim($_POST['venue'] ?? '');
        $is_virtual = isset($_POST['is_virtual']) ? 1 : 0;
        $meeting_link = trim($_POST['meeting_link'] ?? '');
        
        // Convert datetime-local format (2026-03-29T14:00) to MySQL datetime (2026-03-29 14:00:00)
        $defense_date = '';
        if ($defense_date_raw) {
            $dt = date_create($defense_date_raw);
            $defense_date = $dt ? $dt->format('Y-m-d H:i:s') : '';
        }
        
        if ($dissertation_id && $defense_date) {
            $stmt = $conn->prepare("
                INSERT INTO dissertation_defense (dissertation_id, defense_type, defense_date, venue, is_virtual, meeting_link, status)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            $stmt->bind_param("isssis", $dissertation_id, $defense_type, $defense_date, $venue, $is_virtual, $meeting_link);
            
            if ($stmt->execute()) {
                // Update dissertation status
                $upd = $conn->prepare("UPDATE dissertations SET status = 'defense_scheduled', defense_date = ?, updated_at = NOW() WHERE dissertation_id = ?");
                $upd->bind_param("si", $defense_date, $dissertation_id);
                $upd->execute();
                $message = 'Defense scheduled successfully.';
            } else {
                $error = 'Failed to schedule defense.';
            }
        }
    } elseif ($action === 'enter_grade') {
        $defense_id = (int)($_POST['defense_id'] ?? 0);
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $grade = (float)($_POST['grade'] ?? 0);
        $result_val = $_POST['result'] ?? '';
        $comments = trim($_POST['panel_comments'] ?? '');
        $conditions = trim($_POST['conditions'] ?? '');
        
        if ($defense_id && $result_val) {
            $stmt = $conn->prepare("
                UPDATE dissertation_defense 
                SET grade = ?, result = ?, panel_comments = ?, conditions = ?, 
                    status = 'completed', conducted_at = NOW()
                WHERE defense_id = ?
            ");
            $stmt->bind_param("dsssi", $grade, $result_val, $comments, $conditions, $defense_id);
            
            if ($stmt->execute()) {
                // Update dissertation based on result
                if ($result_val === 'pass' || $result_val === 'conditional_pass') {
                    // Check defense type
                    $def_check = $conn->query("SELECT defense_type FROM dissertation_defense WHERE defense_id = $defense_id");
                    $def_type = $def_check->fetch_assoc()['defense_type'] ?? 'proposal';
                    
                    if ($def_type === 'proposal') {
                        $new_status = 'chapter4_writing';
                        $new_phase = 'chapter4';
                    } else {
                        // Final defense passed → student can now submit final dissertation
                        $new_status = 'final_submission_writing';
                        $new_phase = 'final_submission';
                    }
                    
                    $conn->query("UPDATE dissertations SET status = '$new_status', current_phase = '$new_phase', defense_grade = $grade, defense_result = '$result_val', updated_at = NOW() WHERE dissertation_id = $dissertation_id");
                } else {
                    $conn->query("UPDATE dissertations SET status = 'defense_failed', defense_grade = $grade, defense_result = '$result_val', updated_at = NOW() WHERE dissertation_id = $dissertation_id");
                }
                
                $message = 'Defense grade recorded successfully.';
            }
        }
    }
}

// Get defense-ready dissertations
$defense_ready = [];
$r = $conn->query("
    SELECT d.*, s.full_name as student_name, s.program,
           l.full_name as supervisor_name
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE d.status = 'defense_listed' AND d.is_active = 1
    ORDER BY d.updated_at ASC
");
if ($r) while ($row = $r->fetch_assoc()) $defense_ready[] = $row;

// Get scheduled defenses
$scheduled = [];
$r = $conn->query("
    SELECT dd.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name, s.program,
           l.full_name as supervisor_name
    FROM dissertation_defense dd
    JOIN dissertations d ON dd.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE dd.status = 'scheduled'
    ORDER BY dd.defense_date ASC
");
if ($r) while ($row = $r->fetch_assoc()) $scheduled[] = $row;

// Get completed defenses
$completed_defenses = [];
$r = $conn->query("
    SELECT dd.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name, l.full_name as supervisor_name
    FROM dissertation_defense dd
    JOIN dissertations d ON dd.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE dd.status = 'completed'
    ORDER BY dd.conducted_at DESC
    LIMIT 20
");
if ($r) while ($row = $r->fetch_assoc()) $completed_defenses[] = $row;

// Get defense templates
$templates = [];
$r = $conn->query("SELECT * FROM defense_templates WHERE is_active = 1 ORDER BY template_type, created_at DESC");
if ($r) while ($row = $r->fetch_assoc()) $templates[] = $row;

// Get student presentation submissions for scheduled defenses
$presentations = [];
$r = $conn->query("
    SELECT ds.*, d.title as dissertation_title, d.student_id, d.dissertation_id,
           s.full_name as student_name, l.full_name as supervisor_name,
           dd.defense_type, dd.defense_date, dd.defense_id
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    LEFT JOIN dissertation_defense dd ON dd.dissertation_id = d.dissertation_id AND dd.status = 'scheduled'
    WHERE ds.phase = 'defense' AND ds.status IN ('submitted','under_review','approved')
    ORDER BY ds.submitted_at DESC
");
if ($r) while ($row = $r->fetch_assoc()) $presentations[] = $row;

$page_title = 'Defense Management';
$breadcrumbs = [['title' => 'Defense Management']];
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
        .phase-badge { display:inline-block; padding:3px 8px; border-radius:20px; font-size:0.72rem; font-weight:600; }
        .result-badge { padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600; }
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

    <h3 class="fw-bold mb-4"><i class="bi bi-mortarboard me-2"></i>Defense Management</h3>

    <!-- Defense Ready - Schedule -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-calendar-plus text-warning me-2"></i>Ready for Defense (<?= count($defense_ready) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($defense_ready)): ?>
                <p class="text-muted text-center py-3">No dissertations ready for defense scheduling.</p>
            <?php else: ?>
                <?php foreach ($defense_ready as $d): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <strong><?= htmlspecialchars($d['student_name'] ?? $d['student_id']) ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($d['program'] ?? '') ?></small>
                            <p class="mb-0 mt-1 small"><strong>Title:</strong> <?= htmlspecialchars($d['title'] ?? 'Untitled') ?></p>
                            <small class="text-muted">Supervisor: <?= htmlspecialchars($d['supervisor_name'] ?? 'N/A') ?></small>
                            <br><span class="badge bg-<?= in_array($d['current_phase'] ?? '', ['presentation', 'final_draft']) ? 'success' : 'warning' ?> mt-1"><?= htmlspecialchars($phase_labels[$d['current_phase'] ?? ''] ?? ucfirst($d['current_phase'] ?? '')) ?> Phase</span>
                        </div>
                        <div class="col-md-8">
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="action" value="schedule_defense">
                                <input type="hidden" name="dissertation_id" value="<?= $d['dissertation_id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label small">Type</label>
                                    <?php $auto_type = in_array($d['current_phase'] ?? '', ['presentation', 'final_draft']) ? 'final' : 'proposal'; ?>
                                    <select name="defense_type" class="form-select form-select-sm">
                                        <option value="proposal" <?= $auto_type === 'proposal' ? 'selected' : '' ?>>Proposal Defense</option>
                                        <option value="final" <?= $auto_type === 'final' ? 'selected' : '' ?>>Final Defense</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Date & Time</label>
                                    <input type="datetime-local" name="defense_date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Venue</label>
                                    <input type="text" name="venue" class="form-control form-control-sm" placeholder="Room / Hall">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check me-2">
                                        <input class="form-check-input" type="checkbox" name="is_virtual" id="virt<?= $d['dissertation_id'] ?>">
                                        <label class="form-check-label small" for="virt<?= $d['dissertation_id'] ?>">Virtual</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-calendar-check me-1"></i>Schedule</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Presentation Templates Management -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-file-earmark-slides text-info me-2"></i>Presentation Templates</h5>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadTemplateModal">
                <i class="bi bi-upload me-1"></i>Upload Template
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($templates)): ?>
                <p class="text-muted text-center py-3">No presentation templates uploaded yet. Upload a PowerPoint template for students to use.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Template Name</th><th>Type</th><th>File</th><th>Description</th><th>Uploaded</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['template_name']) ?></strong></td>
                            <td><span class="badge bg-<?= $t['template_type'] === 'proposal' ? 'warning' : 'success' ?>"><?= ucfirst($t['template_type']) ?> Defense</span></td>
                            <td><a href="../<?= htmlspecialchars($t['file_path']) ?>" download class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i><?= htmlspecialchars($t['original_filename']) ?></a></td>
                            <td><small><?= htmlspecialchars($t['description'] ?? '') ?></small></td>
                            <td><small class="text-muted"><?= date('M j, Y', strtotime($t['created_at'])) ?></small></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="template_id" value="<?= $t['template_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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

    <!-- Upload Template Modal -->
    <div class="modal fade" id="uploadTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-file-earmark-slides me-2"></i>Upload Presentation Template</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload_template">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Template Name <span class="text-danger">*</span></label>
                            <input type="text" name="template_name" class="form-control" required placeholder="e.g. Proposal Defense Slide Template">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Defense Type <span class="text-danger">*</span></label>
                            <select name="template_type" class="form-select" required>
                                <option value="proposal">Proposal Defense</option>
                                <option value="final">Final Defense</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Template File <span class="text-danger">*</span></label>
                            <input type="file" name="template_file" class="form-control" accept=".ppt,.pptx,.pdf,.doc,.docx" required>
                            <div class="form-text">Upload PPT, PPTX, PDF, DOC, or DOCX (max 20MB).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Brief description of this template..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student Presentation Submissions -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-easel text-primary me-2"></i>Student Presentation Submissions (<?= count($presentations) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($presentations)): ?>
                <p class="text-muted text-center py-4">No student presentations submitted yet.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Student</th><th>Dissertation</th><th>Presentation File</th><th>Defense Date</th><th>Status</th><th>Submitted</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($presentations as $p):
                        $ext = strtolower(pathinfo($p['file_path'] ?? '', PATHINFO_EXTENSION));
                        $is_ppt = in_array($ext, ['ppt','pptx']);
                        $icon = $is_ppt ? 'bi-file-earmark-slides text-warning' : ($ext === 'pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-word text-primary');
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['student_name'] ?? '') ?></strong><br><small class="text-muted">Supervisor: <?= htmlspecialchars($p['supervisor_name'] ?? '-') ?></small></td>
                            <td><small><?= htmlspecialchars(mb_strimwidth($p['dissertation_title'] ?? '', 0, 40, '...')) ?></small></td>
                            <td>
                                <?php if (!empty($p['file_path'])): ?>
                                <a href="../<?= htmlspecialchars($p['file_path']) ?>" download class="btn btn-sm btn-outline-primary">
                                    <i class="bi <?= $icon ?> me-1"></i>Download
                                </a>
                                <a href="../<?= htmlspecialchars($p['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1">
                                    <i class="bi bi-eye me-1"></i>View
                                </a>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($p['defense_date']) ? date('M j, Y H:i', strtotime($p['defense_date'])) : '<span class="text-muted">Not scheduled</span>' ?></td>
                            <td>
                                <?php
                                    $sc = 'secondary';
                                    if ($p['status'] === 'approved') $sc = 'success';
                                    elseif ($p['status'] === 'submitted') $sc = 'info';
                                    elseif ($p['status'] === 'under_review') $sc = 'warning';
                                ?>
                                <span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$p['status'])) ?></span>
                            </td>
                            <td><small><?= date('M j, Y', strtotime($p['submitted_at'])) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scheduled Defenses -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-event text-primary me-2"></i>Scheduled Defenses (<?= count($scheduled) ?>)</h5>
            <div>
                <a href="defense_schedule_pdf.php" target="_blank" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export Schedule
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($scheduled)): ?>
                <p class="text-muted text-center py-4">No defenses currently scheduled.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Venue</th>
                            <th>Supervisor</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduled as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['student_name'] ?? '') ?></strong></td>
                            <td><small><?= htmlspecialchars(mb_strimwidth($s['dissertation_title'] ?? '', 0, 35, '...')) ?></small></td>
                            <td><span class="badge bg-info"><?= ucfirst($s['defense_type']) ?></span></td>
                            <td><?= date('M j, Y H:i', strtotime($s['defense_date'])) ?></td>
                            <td><?= htmlspecialchars($s['venue'] ?? ($s['is_virtual'] ? 'Virtual' : '-')) ?></td>
                            <td><small><?= htmlspecialchars($s['supervisor_name'] ?? '-') ?></small></td>
                            <td>
                                <a href="marking_sheet.php?defense_id=<?= $s['defense_id'] ?>&type=<?= $s['defense_type'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-1" title="Print Marking Sheet">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#gradeModal<?= $s['defense_id'] ?>">
                                    <i class="bi bi-pencil-square me-1"></i>Enter Grade
                                </button>
                            </td>
                        </tr>
                        <!-- Grade Modal -->
                        <div class="modal fade" id="gradeModal<?= $s['defense_id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Enter Defense Grade</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="enter_grade">
                                            <input type="hidden" name="defense_id" value="<?= $s['defense_id'] ?>">
                                            <input type="hidden" name="dissertation_id" value="<?= $s['dissertation_id'] ?>">
                                            
                                            <p><strong>Student:</strong> <?= htmlspecialchars($s['student_name'] ?? '') ?></p>
                                            <p><strong>Type:</strong> <?= ucfirst($s['defense_type']) ?> Defense</p>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Grade (%)</label>
                                                <input type="number" name="grade" class="form-control" min="0" max="100" step="0.5" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Result</label>
                                                <select name="result" class="form-select" required>
                                                    <option value="">Select Result...</option>
                                                    <option value="pass">Pass</option>
                                                    <option value="conditional_pass">Conditional Pass</option>
                                                    <option value="major_revision">Major Revision Required</option>
                                                    <option value="fail">Fail</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Panel Comments</label>
                                                <textarea name="panel_comments" class="form-control" rows="3"></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Conditions (if conditional pass)</label>
                                                <textarea name="conditions" class="form-control" rows="2"></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-success">Save Grade</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Completed Defenses -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>Completed Defenses</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($completed_defenses)): ?>
                <p class="text-muted text-center py-4">No completed defenses yet.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Grade</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_defenses as $cd): ?>
                        <?php
                            $rc = 'secondary';
                            if ($cd['result'] === 'pass') $rc = 'success';
                            elseif ($cd['result'] === 'conditional_pass') $rc = 'warning';
                            elseif ($cd['result'] === 'fail') $rc = 'danger';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cd['student_name'] ?? '') ?></strong></td>
                            <td><small><?= htmlspecialchars(mb_strimwidth($cd['dissertation_title'] ?? '', 0, 40, '...')) ?></small></td>
                            <td><span class="badge bg-info"><?= ucfirst($cd['defense_type']) ?></span></td>
                            <td><?= $cd['conducted_at'] ? date('M j, Y', strtotime($cd['conducted_at'])) : '-' ?></td>
                            <td><strong><?= $cd['grade'] ? number_format($cd['grade'], 1) . '%' : '-' ?></strong></td>
                            <td><span class="result-badge bg-<?= $rc ?> bg-opacity-10 text-<?= $rc ?>"><?= ucfirst(str_replace('_',' ',$cd['result'] ?? '-')) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
