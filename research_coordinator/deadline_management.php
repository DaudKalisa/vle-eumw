<?php
/**
 * Research Coordinator - Deadline Management
 * Set and manage deadlines for dissertation submission phases
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$message = '';
$error = '';

// Auto-create deadline table
$conn->query("
    CREATE TABLE IF NOT EXISTS dissertation_deadlines (
        deadline_id INT AUTO_INCREMENT PRIMARY KEY,
        phase ENUM('topic','concept_note','chapter1','chapter2','chapter3','proposal','ethics','defense','chapter4','chapter5','final_draft','presentation','final_submission') NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        deadline_date DATETIME NOT NULL,
        academic_year VARCHAR(20) DEFAULT NULL,
        program VARCHAR(100) DEFAULT NULL,
        program_type ENUM('all','degree','professional','masters','doctorate') DEFAULT 'all',
        is_active TINYINT(1) DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_phase (phase),
        INDEX idx_deadline (deadline_date),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$phase_labels = [
    'topic' => 'Topic Submission',
    'concept_note' => 'Concept Note',
    'chapter1' => 'Chapter 1 – Introduction',
    'chapter2' => 'Chapter 2 – Literature Review',
    'chapter3' => 'Chapter 3 – Methodology',
    'proposal' => 'Proposal Defense',
    'ethics' => 'Ethics Submission',
    'defense' => 'Final Defense',
    'chapter4' => 'Chapter 4 – Results',
    'chapter5' => 'Chapter 5 – Conclusion',
    'final_draft' => 'Final Draft',
    'presentation' => 'Final Result Presentation',
    'final_submission' => 'Final Submission',
];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_deadline') {
        $phase = $_POST['phase'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $deadline_date = $_POST['deadline_date'] ?? '';
        $academic_year = trim($_POST['academic_year'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $program_type = $_POST['program_type'] ?? 'all';

        if (empty($phase) || empty($title) || empty($deadline_date)) {
            $error = 'Phase, title and deadline date are required.';
        } elseif (!array_key_exists($phase, $phase_labels)) {
            $error = 'Invalid phase selected.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO dissertation_deadlines (phase, title, description, deadline_date, academic_year, program, program_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $uid = $_SESSION['vle_user_id'] ?? null;
            $stmt->bind_param("sssssssi", $phase, $title, $description, $deadline_date, $academic_year, $program, $program_type, $uid);
            if ($stmt->execute()) {
                $message = "Deadline for <strong>{$phase_labels[$phase]}</strong> added successfully.";
            } else {
                $error = 'Failed to add deadline.';
            }
        }
    } elseif ($action === 'update_deadline') {
        $deadline_id = (int)($_POST['deadline_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $deadline_date = $_POST['deadline_date'] ?? '';
        $program_type = $_POST['program_type'] ?? 'all';

        if ($deadline_id && $title && $deadline_date) {
            $stmt = $conn->prepare("UPDATE dissertation_deadlines SET title = ?, description = ?, deadline_date = ?, program_type = ? WHERE deadline_id = ?");
            $stmt->bind_param("ssssi", $title, $description, $deadline_date, $program_type, $deadline_id);
            if ($stmt->execute()) {
                $message = 'Deadline updated successfully.';
            } else {
                $error = 'Failed to update deadline.';
            }
        }
    } elseif ($action === 'toggle_deadline') {
        $deadline_id = (int)($_POST['deadline_id'] ?? 0);
        if ($deadline_id) {
            $stmt = $conn->prepare("UPDATE dissertation_deadlines SET is_active = NOT is_active WHERE deadline_id = ?");
            $stmt->bind_param("i", $deadline_id);
            $stmt->execute();
            $message = 'Deadline status toggled.';
        }
    } elseif ($action === 'delete_deadline') {
        $deadline_id = (int)($_POST['deadline_id'] ?? 0);
        if ($deadline_id) {
            $stmt = $conn->prepare("DELETE FROM dissertation_deadlines WHERE deadline_id = ?");
            $stmt->bind_param("i", $deadline_id);
            $stmt->execute();
            $message = 'Deadline deleted.';
        }
    }
}

// Fetch all deadlines
$deadlines = [];
$r = $conn->query("SELECT * FROM dissertation_deadlines ORDER BY deadline_date ASC");
if ($r) while ($row = $r->fetch_assoc()) $deadlines[] = $row;

// Group by phase
$grouped = [];
foreach ($deadlines as $dl) {
    $grouped[$dl['phase']][] = $dl;
}

// Stats
$now = date('Y-m-d H:i:s');
$total = count($deadlines);
$active = count(array_filter($deadlines, fn($d) => $d['is_active']));
$upcoming = count(array_filter($deadlines, fn($d) => $d['is_active'] && $d['deadline_date'] > $now));
$overdue = count(array_filter($deadlines, fn($d) => $d['is_active'] && $d['deadline_date'] < $now));

$page_title = 'Deadline Management';
$breadcrumbs = [['title' => 'Deadline Management']];
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
        .deadline-card { border-left: 4px solid #6c757d; transition: box-shadow .2s; }
        .deadline-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .deadline-card.overdue { border-left-color: #dc3545; background: #fff5f5; }
        .deadline-card.upcoming { border-left-color: #ffc107; }
        .deadline-card.active-ok { border-left-color: #198754; }
        .deadline-card.inactive { border-left-color: #adb5bd; opacity: .6; }
        .phase-section { margin-bottom: 2rem; }
        .phase-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .phase-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .countdown { font-size: 0.78rem; font-weight: 600; }
        .stat-card { border-radius: 10px; padding: 20px; text-align: center; }
        .stat-num { font-size: 2rem; font-weight: 700; }
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
        <h3 class="fw-bold mb-0"><i class="bi bi-calendar-event me-2"></i>Dissertation Deadlines</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeadlineModal">
            <i class="bi bi-plus-circle me-1"></i>Add Deadline
        </button>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card bg-primary bg-opacity-10">
                <div class="stat-num text-primary"><?= $total ?></div>
                <small class="text-muted">Total Deadlines</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-success bg-opacity-10">
                <div class="stat-num text-success"><?= $active ?></div>
                <small class="text-muted">Active</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-warning bg-opacity-10">
                <div class="stat-num text-warning"><?= $upcoming ?></div>
                <small class="text-muted">Upcoming</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-danger bg-opacity-10">
                <div class="stat-num text-danger"><?= $overdue ?></div>
                <small class="text-muted">Past Due</small>
            </div>
        </div>
    </div>

    <!-- Deadlines by phase -->
    <?php if (empty($deadlines)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x display-4 text-muted"></i>
                <p class="mt-3 text-muted">No deadlines set yet. Click <strong>Add Deadline</strong> to create the first one.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($phase_labels as $phase_key => $phase_name): ?>
            <?php if (!isset($grouped[$phase_key])) continue; ?>
            <div class="phase-section">
                <div class="phase-header">
                    <span class="phase-badge bg-primary bg-opacity-15 text-primary"><?= $phase_name ?></span>
                    <small class="text-muted"><?= count($grouped[$phase_key]) ?> deadline(s)</small>
                </div>
                <?php foreach ($grouped[$phase_key] as $dl): ?>
                    <?php
                    $isOverdue = $dl['is_active'] && $dl['deadline_date'] < $now;
                    $isUpcoming = $dl['is_active'] && $dl['deadline_date'] > $now && $dl['deadline_date'] < date('Y-m-d H:i:s', strtotime('+7 days'));
                    $cls = 'active-ok';
                    if (!$dl['is_active']) $cls = 'inactive';
                    elseif ($isOverdue) $cls = 'overdue';
                    elseif ($isUpcoming) $cls = 'upcoming';
                    
                    $diff = strtotime($dl['deadline_date']) - time();
                    if ($diff > 0) {
                        $days = floor($diff / 86400);
                        $countdown = $days > 0 ? "$days day" . ($days != 1 ? 's' : '') . " left" : "Due today";
                    } else {
                        $days = floor(abs($diff) / 86400);
                        $countdown = $days > 0 ? "$days day" . ($days != 1 ? 's' : '') . " overdue" : "Due today";
                    }
                    ?>
                    <div class="card deadline-card <?= $cls ?> shadow-sm mb-2">
                        <div class="card-body py-3">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <h6 class="mb-1"><?= htmlspecialchars($dl['title']) ?></h6>
                                    <?php if ($dl['description']): ?>
                                        <small class="text-muted"><?= htmlspecialchars(mb_strimwidth($dl['description'], 0, 80, '...')) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2">
                                    <i class="bi bi-calendar3 me-1 text-muted"></i>
                                    <strong><?= date('M j, Y', strtotime($dl['deadline_date'])) ?></strong>
                                    <br><small class="text-muted"><?= date('h:i A', strtotime($dl['deadline_date'])) ?></small>
                                </div>
                                <div class="col-md-2">
                                    <span class="countdown <?= $isOverdue ? 'text-danger' : ($isUpcoming ? 'text-warning' : 'text-success') ?>">
                                        <i class="bi <?= $isOverdue ? 'bi-exclamation-triangle' : 'bi-clock' ?> me-1"></i><?= $countdown ?>
                                    </span>
                                </div>
                                <div class="col-md-1">
                                    <?php if ($dl['program_type'] !== 'all'): ?>
                                        <span class="badge bg-info"><?= ucfirst($dl['program_type']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">All</span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $dl['deadline_id'] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_deadline">
                                        <input type="hidden" name="deadline_id" value="<?= $dl['deadline_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $dl['is_active'] ? 'warning' : 'success' ?>" title="<?= $dl['is_active'] ? 'Disable' : 'Enable' ?>">
                                            <i class="bi bi-<?= $dl['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this deadline?')">
                                        <input type="hidden" name="action" value="delete_deadline">
                                        <input type="hidden" name="deadline_id" value="<?= $dl['deadline_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Modal -->
                    <div class="modal fade" id="editModal<?= $dl['deadline_id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_deadline">
                                    <input type="hidden" name="deadline_id" value="<?= $dl['deadline_id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Deadline</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Title</label>
                                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($dl['title']) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Description</label>
                                            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($dl['description'] ?? '') ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Deadline Date & Time</label>
                                            <input type="datetime-local" name="deadline_date" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($dl['deadline_date'])) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Program Type</label>
                                            <select name="program_type" class="form-select">
                                                <?php foreach (['all'=>'All Programs','degree'=>'Degree','professional'=>'Professional','masters'=>'Masters','doctorate'=>'Doctorate'] as $k=>$v): ?>
                                                    <option value="<?= $k ?>" <?= $dl['program_type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Deadline Modal -->
<div class="modal fade" id="addDeadlineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_deadline">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Add Deadline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Phase <span class="text-danger">*</span></label>
                        <select name="phase" class="form-select" required>
                            <option value="">Select Phase...</option>
                            <?php foreach ($phase_labels as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Concept Note Submission Deadline – 2026" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Additional instructions..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Deadline Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="deadline_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" placeholder="e.g. 2025/2026">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Program Type</label>
                        <select name="program_type" class="form-select">
                            <option value="all">All Programs</option>
                            <option value="degree">Degree</option>
                            <option value="professional">Professional</option>
                            <option value="masters">Masters</option>
                            <option value="doctorate">Doctorate</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Deadline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
