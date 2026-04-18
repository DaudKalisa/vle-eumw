<?php
/**
 * Research Coordinator Dashboard
 * Overview of all dissertation activities
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();

// Get coordinator ID
$coordinator_id = null;
$stmt = $conn->prepare("SELECT coordinator_id FROM research_coordinators WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $_SESSION['vle_user_id']);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($row = $r->fetch_assoc()) {
        $coordinator_id = $row['coordinator_id'];
    }
}

// Dashboard statistics
$stats = [];

// Total dissertations
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE is_active = 1");
$stats['total'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Pending topic reviews
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE status IN ('topic_submission','concept_review') AND is_active = 1");
$stats['pending_topics'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Awaiting supervisor assignment
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE status IN ('topic_approved','concept_approved') AND supervisor_id IS NULL AND is_active = 1");
$stats['needs_supervisor'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Active dissertations (in progress)
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE status NOT IN ('topic_submission','completed','archived') AND is_active = 1");
$stats['active'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Pending submissions (submitted, waiting for review)
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertation_submissions WHERE status = 'submitted'");
$stats['pending_reviews'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Defense ready
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE status = 'defense_listed' AND is_active = 1");
$stats['defense_ready'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Completed
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertations WHERE status = 'completed' AND is_active = 1");
$stats['completed'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Similarity alerts (high scores > 30%)
$r = $conn->query("SELECT COUNT(*) as cnt FROM dissertation_similarity_checks WHERE similarity_score > 30 OR ai_detection_score > 30");
$stats['similarity_alerts'] = $r ? $r->fetch_assoc()['cnt'] : 0;

// Recent submissions
$recent_submissions = [];
$r = $conn->query("
    SELECT ds.*, d.title as dissertation_title, d.student_id,
           s.full_name as student_name
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    WHERE ds.status = 'submitted'
    ORDER BY ds.submitted_at DESC
    LIMIT 10
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $recent_submissions[] = $row;
    }
}

// Recent dissertations needing attention
$recent_dissertations = [];
$r = $conn->query("
    SELECT d.*, s.full_name as student_name, s.program, s.year_of_study,
           l.full_name as supervisor_name
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE d.is_active = 1
    ORDER BY d.updated_at DESC
    LIMIT 10
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $recent_dissertations[] = $row;
    }
}

// Phase distribution
$phase_stats = [];
$r = $conn->query("
    SELECT current_phase, COUNT(*) as cnt FROM dissertations 
    WHERE is_active = 1 GROUP BY current_phase ORDER BY FIELD(current_phase,
        'topic','concept_note','chapter1','chapter2','chapter3',
        'proposal','ethics','defense','chapter4','chapter5',
        'final_draft','final_submission')
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $phase_stats[$row['current_phase']] = $row['cnt'];
    }
}

$phase_labels = [
    'topic' => 'Topic/Concept', 'concept_note' => 'Concept Note',
    'chapter1' => 'Chapter 1', 'chapter2' => 'Chapter 2', 'chapter3' => 'Chapter 3',
    'proposal' => 'Full Proposal', 'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Chapter 4', 'chapter5' => 'Chapter 5',
    'final_draft' => 'Final Draft', 'final_submission' => 'Final Submission'
];

$page_title = 'Research Coordinator Dashboard';
$breadcrumbs = [];

// Graduation clearance pending for RC
$pending_graduation_rc = 0;
$grad_chk = $conn->query("SHOW TABLES LIKE 'graduation_applications'");
if ($grad_chk && $grad_chk->num_rows > 0) {
    $gr = $conn->query("SELECT COUNT(*) as c FROM graduation_applications WHERE current_step = 'rc' AND status NOT IN ('completed','rejected')");
    if ($gr) $pending_graduation_rc = (int)$gr->fetch_assoc()['c'];
}
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
        .stat-card {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        .phase-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pill {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .progress-pipeline {
            display: flex;
            gap: 2px;
            margin-top: 8px;
        }
        .progress-pipeline .pipe-step {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
        }
        .progress-pipeline .pipe-step.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        .progress-pipeline .pipe-step.completed {
            background: #28a745;
        }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <!-- Welcome Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold mb-1">
                <i class="bi bi-journal-bookmark-fill me-2" style="color: #667eea;"></i>
                Research Coordinator Dashboard
            </h2>
            <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($user['display_name'] ?? 'Coordinator') ?>. Manage dissertations, assign supervisors, and track progress.</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                            <i class="bi bi-journal-bookmark"></i>
                        </div>
                        <div>
                            <div class="stat-value text-primary"><?= $stats['total'] ?></div>
                            <small class="text-muted">Total Dissertations</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-value text-warning"><?= $stats['pending_topics'] ?></div>
                            <small class="text-muted">Pending Topics</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                            <i class="bi bi-person-plus"></i>
                        </div>
                        <div>
                            <div class="stat-value text-danger"><?= $stats['needs_supervisor'] ?></div>
                            <small class="text-muted">Need Supervisor</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="bi bi-activity"></i>
                        </div>
                        <div>
                            <div class="stat-value text-info"><?= $stats['active'] ?></div>
                            <small class="text-muted">Active</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3">
                            <i class="bi bi-file-earmark-check"></i>
                        </div>
                        <div>
                            <div class="stat-value text-secondary"><?= $stats['pending_reviews'] ?></div>
                            <small class="text-muted">Pending Reviews</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-purple bg-opacity-10" style="color: #6f42c1; background: rgba(111,66,193,0.1);">
                            <i class="bi bi-mortarboard"></i>
                        </div>
                        <div>
                            <div class="stat-value" style="color:#6f42c1;"><?= $stats['defense_ready'] ?></div>
                            <small class="text-muted">Defense Ready</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value text-success"><?= $stats['completed'] ?></div>
                            <small class="text-muted">Completed</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                            <i class="bi bi-shield-exclamation"></i>
                        </div>
                        <div>
                            <div class="stat-value text-danger"><?= $stats['similarity_alerts'] ?></div>
                            <small class="text-muted">Similarity Alerts</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Phase Distribution -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>Phase Distribution</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($phase_stats)): ?>
                        <p class="text-muted text-center py-4">No dissertations yet</p>
                    <?php else: ?>
                        <?php foreach ($phase_labels as $key => $label): ?>
                            <?php $count = $phase_stats[$key] ?? 0; ?>
                            <?php if ($count > 0): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small"><?= $label ?></span>
                                <span class="badge bg-primary rounded-pill"><?= $count ?></span>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Submissions -->
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent Submissions</h5>
                    <a href="review_submissions.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_submissions)): ?>
                        <p class="text-muted text-center py-4">No pending submissions</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Phase</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_submissions as $sub): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($sub['student_name'] ?? $sub['student_id'] ?? '') ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($sub['dissertation_title'] ?? 'Untitled') ?></small>
                                    </td>
                                    <td>
                                        <span class="phase-badge bg-primary bg-opacity-10 text-primary">
                                            <?= $phase_labels[$sub['phase']] ?? ucfirst($sub['phase']) ?>
                                        </span>
                                    </td>
                                    <td><small><?= $sub['submitted_at'] ? date('M j, Y H:i', strtotime($sub['submitted_at'])) : '-' ?></small></td>
                                    <td>
                                        <a href="review_submissions.php?submission_id=<?= $sub['submission_id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>Review
                                        </a>
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
    </div>

    <!-- Recent Dissertations -->
    <div class="row g-4 mt-2">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-journal-text me-2"></i>Recent Dissertations</h5>
                    <a href="manage_dissertations.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_dissertations)): ?>
                        <p class="text-muted text-center py-4">No dissertations found</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Title</th>
                                    <th>Program</th>
                                    <th>Phase</th>
                                    <th>Supervisor</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_dissertations as $d): ?>
                                <?php
                                    $status_class = 'secondary';
                                    $status_text = str_replace('_', ' ', $d['status']);
                                    if (strpos($d['status'], 'approved') !== false || $d['status'] === 'completed') $status_class = 'success';
                                    elseif (strpos($d['status'], 'rejected') !== false || strpos($d['status'], 'failed') !== false) $status_class = 'danger';
                                    elseif (strpos($d['status'], 'review') !== false || strpos($d['status'], 'submitted') !== false) $status_class = 'warning';
                                    elseif (strpos($d['status'], 'writing') !== false) $status_class = 'info';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($d['student_name'] ?? $d['student_id']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($d['student_id']) ?></small>
                                    </td>
                                    <td><small><?= htmlspecialchars(mb_strimwidth($d['title'] ?? 'Untitled', 0, 50, '...')) ?></small></td>
                                    <td><small><?= htmlspecialchars($d['program'] ?? '-') ?></small></td>
                                    <td>
                                        <span class="phase-badge bg-primary bg-opacity-10 text-primary">
                                            <?= $phase_labels[$d['current_phase']] ?? ucfirst($d['current_phase'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td><small><?= htmlspecialchars($d['supervisor_name'] ?? 'Not assigned') ?></small></td>
                                    <td><span class="status-pill bg-<?= $status_class ?> bg-opacity-10 text-<?= $status_class ?>"><?= ucfirst($status_text) ?></span></td>
                                    <td>
                                        <a href="manage_dissertations.php?id=<?= $d['dissertation_id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
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
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mt-2">
        <div class="col-md-3">
            <a href="manage_dissertations.php" class="card shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-journal-bookmark-fill display-5 text-primary mb-2"></i>
                    <h6 class="mb-0">Manage Dissertations</h6>
                    <small class="text-muted">View & manage all dissertations</small>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="assign_supervisors.php" class="card shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-person-lines-fill display-5 text-success mb-2"></i>
                    <h6 class="mb-0">Assign Supervisors</h6>
                    <small class="text-muted">Match students with supervisors</small>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="defense_management.php" class="card shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-mortarboard-fill display-5 text-warning mb-2"></i>
                    <h6 class="mb-0">Defense Management</h6>
                    <small class="text-muted">Schedule & grade defenses</small>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="similarity_reports.php" class="card shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-shield-check display-5 text-danger mb-2"></i>
                    <h6 class="mb-0">Similarity Reports</h6>
                    <small class="text-muted">View plagiarism & AI checks</small>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="student_link.php" class="card shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-link-45deg display-5 text-info mb-2"></i>
                    <h6 class="mb-0">Invite Links</h6>
                    <small class="text-muted">Create student & supervisor invites</small>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="graduation_clearance.php" class="card shadow-sm text-decoration-none h-100" style="position:relative;">
                <div class="card-body text-center py-4">
                    <i class="bi bi-mortarboard-fill display-5 mb-2" style="color:#059669;"></i>
                    <h6 class="mb-0">Graduation Clearance</h6>
                    <small class="text-muted">RC sign-off for graduating students</small>
                    <?php if ($pending_graduation_rc > 0): ?>
                    <span style="position:absolute;top:8px;right:8px;background:#ef4444;color:#fff;border-radius:50%;font-size:.7rem;font-weight:700;min-width:20px;height:20px;display:flex;align-items:center;justify-content:center;"><?= $pending_graduation_rc ?></span>
                    <?php endif; ?>
                </div>
            </a>
        </div>
    </div>
    
    <?php
    $current_role_context = 'research_coordinator';
    include '../includes/role_cards.php';
    ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
