<?php
/**
 * Research Coordinator - Assign Supervisors
 * Assign lecturers as supervisors to dissertations
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();

$message = '';
$error = '';

// Handle supervisor assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
    
    if ($action === 'assign_supervisor' && $dissertation_id) {
        $supervisor_id = (int)($_POST['supervisor_id'] ?? 0);
        $co_supervisor_id = !empty($_POST['co_supervisor_id']) ? (int)$_POST['co_supervisor_id'] : null;
        
        if ($supervisor_id) {
            $stmt = $conn->prepare("
                UPDATE dissertations 
                SET supervisor_id = ?, co_supervisor_id = ?, 
                    status = 'supervisor_assigned', current_phase = 'chapter1',
                    supervisor_assigned_at = NOW(), updated_at = NOW()
                WHERE dissertation_id = ? AND status IN ('topic_approved', 'concept_approved') AND supervisor_id IS NULL
            ");
            $stmt->bind_param("iii", $supervisor_id, $co_supervisor_id, $dissertation_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Notify student
                $notif = $conn->prepare("INSERT INTO dissertation_notifications (dissertation_id, user_id, type, title, message) 
                    SELECT ?, u.user_id, 'supervisor_assigned', 'Supervisor Assigned', 'A supervisor has been assigned to your dissertation. You may now begin Chapter 1.'
                    FROM dissertations d JOIN users u ON d.student_id = u.username OR d.student_id = CAST(u.related_student_id AS CHAR)
                    WHERE d.dissertation_id = ? LIMIT 1");
                $notif->bind_param("ii", $dissertation_id, $dissertation_id);
                $notif->execute();
                
                $message = 'Supervisor assigned successfully. Student has been notified to begin Chapter 1.';
            } else {
                $error = 'Failed to assign supervisor. Ensure the dissertation has an approved topic or concept and no supervisor yet.';
            }
        } else {
            $error = 'Please select a supervisor.';
        }
    } elseif ($action === 'reassign_supervisor' && $dissertation_id) {
        $supervisor_id = (int)($_POST['supervisor_id'] ?? 0);
        $co_supervisor_id = !empty($_POST['co_supervisor_id']) ? (int)$_POST['co_supervisor_id'] : null;
        
        if ($supervisor_id) {
            $stmt = $conn->prepare("UPDATE dissertations SET supervisor_id = ?, co_supervisor_id = ?, updated_at = NOW() WHERE dissertation_id = ?");
            $stmt->bind_param("iii", $supervisor_id, $co_supervisor_id, $dissertation_id);
            if ($stmt->execute()) {
                $message = 'Supervisor re-assigned successfully.';
            }
        }
    }
}

// Get dissertations needing supervisor assignment (topic_approved OR concept_approved)
$needs_supervisor = [];
$r = $conn->query("
    SELECT d.*, s.full_name as student_name, s.program as student_program, s.department,
           l.full_name as current_supervisor,
           dep.department_name
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    LEFT JOIN departments dep ON s.department = dep.department_id
    WHERE d.is_active = 1 AND d.status IN ('topic_approved', 'concept_approved') AND d.supervisor_id IS NULL
    ORDER BY d.topic_submitted_at ASC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $needs_supervisor[] = $row;
    }
}

// Get all active lecturers for supervisor selection
$lecturers = [];
$r = $conn->query("
    SELECT l.lecturer_id, l.full_name, l.department, l.email, l.position,
           (SELECT COUNT(*) FROM dissertations d WHERE d.supervisor_id = l.lecturer_id AND d.is_active = 1 AND d.status NOT IN ('completed','archived')) as active_count
    FROM lecturers l
    WHERE l.is_active = 1
    ORDER BY l.full_name
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $lecturers[] = $row;
    }
}

// Get all dissertations with supervisors (for management view)
$all_assigned = [];
$r = $conn->query("
    SELECT d.dissertation_id, d.title, d.student_id, d.current_phase, d.status,
           s.full_name as student_name, s.program,
           l.full_name as supervisor_name, l.lecturer_id as supervisor_id,
           cl.full_name as co_supervisor_name
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    LEFT JOIN lecturers cl ON d.co_supervisor_id = cl.lecturer_id
    WHERE d.is_active = 1 AND d.supervisor_id IS NOT NULL
    ORDER BY d.updated_at DESC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $all_assigned[] = $row;
    }
}

// Pre-select dissertation if coming from manage page
$preselect_id = (int)($_GET['dissertation_id'] ?? 0);

$page_title = 'Assign Supervisors';
$breadcrumbs = [['title' => 'Assign Supervisors']];

$phase_labels = [
    'topic' => 'Topic', 'concept_note' => 'Concept Note',
    'chapter1' => 'Ch 1', 'chapter2' => 'Ch 2', 'chapter3' => 'Ch 3',
    'proposal' => 'Proposal', 'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Ch 4', 'chapter5' => 'Ch 5',
    'final_draft' => 'Final Draft', 'final_submission' => 'Final'
];
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
        .supervisor-card { border:2px solid transparent; cursor:pointer; transition:all 0.2s; }
        .supervisor-card:hover { border-color:#667eea; }
        .supervisor-card.selected { border-color:#667eea; background:#f8f9ff; }
        .workload-badge { font-size:0.7rem; }
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

    <h3 class="fw-bold mb-4"><i class="bi bi-person-lines-fill me-2"></i>Assign Supervisors</h3>

    <!-- Dissertations Needing Supervisor -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Dissertations Awaiting Supervisor Assignment (<?= count($needs_supervisor) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($needs_supervisor)): ?>
                <p class="text-muted text-center py-3">All dissertations with submitted topics have supervisors assigned.</p>
            <?php else: ?>
                <?php foreach ($needs_supervisor as $d): ?>
                <div class="border rounded p-3 mb-3 <?= $preselect_id === $d['dissertation_id'] ? 'border-primary bg-light' : '' ?>">
                    <div class="row align-items-center">
                        <div class="col-md-5">
                            <strong><?= htmlspecialchars($d['student_name'] ?? $d['student_id']) ?></strong>
                            <span class="text-muted small ms-2"><?= htmlspecialchars($d['student_id']) ?></span>
                            <br><small class="text-muted"><?= htmlspecialchars($d['student_program'] ?? '-') ?> | Dept: <?= htmlspecialchars($d['department_name'] ?? $d['department'] ?? '-') ?></small>
                            <p class="mb-0 mt-1"><strong>Title:</strong> <?= htmlspecialchars($d['title'] ?? 'Untitled') ?></p>
                        </div>
                        <div class="col-md-7">
                            <form method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="action" value="assign_supervisor">
                                <input type="hidden" name="dissertation_id" value="<?= $d['dissertation_id'] ?>">
                                <div class="col-md-4">
                                    <label class="form-label small">Supervisor*</label>
                                    <select name="supervisor_id" class="form-select form-select-sm" required>
                                        <option value="">Select Supervisor...</option>
                                        <?php foreach ($lecturers as $l): ?>
                                            <option value="<?= $l['lecturer_id'] ?>">
                                                <?= htmlspecialchars($l['full_name']) ?> (<?= $l['active_count'] ?> active)
                                                <?= $l['department'] ? ' - ' . $l['department'] : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Co-Supervisor</label>
                                    <select name="co_supervisor_id" class="form-select form-select-sm">
                                        <option value="">None</option>
                                        <?php foreach ($lecturers as $l): ?>
                                            <option value="<?= $l['lecturer_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-person-check me-1"></i>Assign
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Supervisor Workload Overview -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Supervisor Workload</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Lecturer</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Active Supervisions</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lecturers as $l): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($l['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($l['department'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($l['position'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $l['active_count'] >= 5 ? 'bg-danger' : ($l['active_count'] >= 3 ? 'bg-warning' : 'bg-success') ?>">
                                    <?= $l['active_count'] ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($l['email'] ?? '') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Currently Assigned -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>All Assignments (<?= count($all_assigned) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Title</th>
                            <th>Supervisor</th>
                            <th>Co-Supervisor</th>
                            <th>Phase</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_assigned)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No assignments yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_assigned as $a): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($a['student_name'] ?? $a['student_id']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($a['program'] ?? '') ?></small>
                                </td>
                                <td><small><?= htmlspecialchars(mb_strimwidth($a['title'] ?? '', 0, 40, '...')) ?></small></td>
                                <td><?= htmlspecialchars($a['supervisor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($a['co_supervisor_name'] ?? 'None') ?></td>
                                <td><span class="phase-badge bg-primary bg-opacity-10 text-primary"><?= $phase_labels[$a['current_phase']] ?? '' ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reassignModal<?= $a['dissertation_id'] ?>">
                                        <i class="bi bi-arrow-repeat"></i> Reassign
                                    </button>
                                </td>
                            </tr>
                            <!-- Reassign Modal -->
                            <div class="modal fade" id="reassignModal<?= $a['dissertation_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Reassign Supervisor</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="reassign_supervisor">
                                                <input type="hidden" name="dissertation_id" value="<?= $a['dissertation_id'] ?>">
                                                <p>Student: <strong><?= htmlspecialchars($a['student_name'] ?? '') ?></strong></p>
                                                <div class="mb-3">
                                                    <label class="form-label">New Supervisor</label>
                                                    <select name="supervisor_id" class="form-select" required>
                                                        <?php foreach ($lecturers as $l): ?>
                                                            <option value="<?= $l['lecturer_id'] ?>" <?= $l['lecturer_id'] == $a['supervisor_id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($l['full_name']) ?> (<?= $l['active_count'] ?> active)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Co-Supervisor</label>
                                                    <select name="co_supervisor_id" class="form-select">
                                                        <option value="">None</option>
                                                        <?php foreach ($lecturers as $l): ?>
                                                            <option value="<?= $l['lecturer_id'] ?>"><?= htmlspecialchars($l['full_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Reassign</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
