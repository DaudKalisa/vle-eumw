<?php
/**
 * Dean Portal - Examinations
 * View and manage exam results approval
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Determine exam table
$exam_table = 'exams';
$table_check = $conn->query("SHOW TABLES LIKE 'exams'");
if (!$table_check || $table_check->num_rows == 0) {
    $table_check = $conn->query("SHOW TABLES LIKE 'vle_exams'");
    if ($table_check && $table_check->num_rows > 0) {
        $exam_table = 'vle_exams';
    }
}

// Handle result approval
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $action = $_POST['action'];
    
    if ($exam_id > 0) {
        // Check if results_published column exists
        $col_check = $conn->query("SHOW COLUMNS FROM $exam_table LIKE 'results_published'");
        $has_results_col = $col_check && $col_check->num_rows > 0;
        
        if ($action === 'publish_results' && $has_results_col) {
            $stmt = $conn->prepare("UPDATE $exam_table SET results_published = 1 WHERE exam_id = ?");
            $stmt->bind_param("i", $exam_id);
            if ($stmt->execute()) {
                $message = "Results published successfully.";
                $message_type = "success";
            }
        } elseif ($action === 'approve') {
            // Check if dean_approved column exists
            $col_check = $conn->query("SHOW COLUMNS FROM $exam_table LIKE 'dean_approved'");
            if (!$col_check || $col_check->num_rows == 0) {
                $conn->query("ALTER TABLE $exam_table ADD COLUMN dean_approved TINYINT DEFAULT 0, ADD COLUMN dean_approved_at TIMESTAMP NULL");
            }
            $stmt = $conn->prepare("UPDATE $exam_table SET dean_approved = 1, dean_approved_at = NOW() WHERE exam_id = ?");
            $stmt->bind_param("i", $exam_id);
            if ($stmt->execute()) {
                $message = "Exam results approved by Dean.";
                $message_type = "success";
            }
        }
    }
}

// Get exam columns
$columns = [];
$col_result = $conn->query("SHOW COLUMNS FROM $exam_table");
if ($col_result) {
    while ($col = $col_result->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
}

$has_exam_date = in_array('exam_date', $columns);
$has_status = in_array('status', $columns);
$has_results_published = in_array('results_published', $columns);
$has_dean_approved = in_array('dean_approved', $columns);

// Filters
$filter_status = $_GET['status'] ?? '';

// Get exams
$exams = [];

if (!empty($columns)) {
    $select_cols = ['exam_id'];
    if (in_array('exam_name', $columns)) $select_cols[] = 'exam_name';
    if (in_array('course_id', $columns)) $select_cols[] = 'course_id';
    if (in_array('course_code', $columns)) $select_cols[] = 'course_code';
    if ($has_exam_date) $select_cols[] = 'exam_date';
    if ($has_status) $select_cols[] = 'status';
    if ($has_results_published) $select_cols[] = 'results_published';
    if ($has_dean_approved) $select_cols[] = 'dean_approved';
    if (in_array('created_at', $columns)) $select_cols[] = 'created_at';
    
    $sql = "SELECT " . implode(', ', $select_cols) . " FROM $exam_table";
    
    if ($filter_status && $has_status) {
        $sql .= " WHERE status = '$filter_status'";
    }
    
    $sql .= $has_exam_date ? " ORDER BY exam_date DESC" : " ORDER BY exam_id DESC";
    $sql .= " LIMIT 100";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $exams[] = $row;
        }
    }
}

// Stats
$stats = [
    'total' => count($exams),
    'scheduled' => 0,
    'completed' => 0,
    'published' => 0
];

if ($has_status) {
    $stat_result = $conn->query("SELECT status, COUNT(*) as cnt FROM $exam_table GROUP BY status");
    if ($stat_result) {
        while ($row = $stat_result->fetch_assoc()) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = $row['cnt'];
            }
        }
    }
}

if ($has_results_published) {
    $pub_result = $conn->query("SELECT COUNT(*) as cnt FROM $exam_table WHERE results_published = 1");
    if ($pub_result) {
        $stats['published'] = $pub_result->fetch_assoc()['cnt'];
    }
}

$page_title = "Examinations";
$breadcrumbs = [['title' => 'Exams']];
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
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-primary"><?= $stats['total'] ?></div>
                        <small class="text-muted">Total Exams</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-warning"><?= $stats['scheduled'] ?></div>
                        <small class="text-muted">Scheduled</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-info"><?= $stats['completed'] ?></div>
                        <small class="text-muted">Completed</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success"><?= $stats['published'] ?></div>
                        <small class="text-muted">Results Published</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <?php if ($has_status): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Exams</option>
                            <option value="scheduled" <?= $filter_status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="ongoing" <?= $filter_status === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-1"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Exams Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Examinations</h5>
                <span class="badge bg-primary"><?= count($exams) ?> exams</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($exams)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x fs-1 text-muted d-block mb-3"></i>
                    <p class="text-muted">No exams found</p>
                    <p class="small text-muted">Exams created by the Examination Office will appear here.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Exam</th>
                                <?php if ($has_exam_date): ?><th>Date</th><?php endif; ?>
                                <?php if ($has_status): ?><th>Status</th><?php endif; ?>
                                <?php if ($has_results_published): ?><th>Results</th><?php endif; ?>
                                <?php if ($has_dean_approved): ?><th>Dean Approval</th><?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exams as $exam): ?>
                            <tr>
                                <td><code>#<?= $exam['exam_id'] ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($exam['exam_name'] ?? $exam['course_code'] ?? 'Exam ' . $exam['exam_id']) ?></strong>
                                </td>
                                <?php if ($has_exam_date): ?>
                                <td><?= date('M d, Y', strtotime($exam['exam_date'])) ?></td>
                                <?php endif; ?>
                                <?php if ($has_status): ?>
                                <td>
                                    <?php
                                    $badge = ['scheduled' => 'warning', 'ongoing' => 'info', 'completed' => 'success'][$exam['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $badge ?>"><?= ucfirst($exam['status']) ?></span>
                                </td>
                                <?php endif; ?>
                                <?php if ($has_results_published): ?>
                                <td>
                                    <?php if ($exam['results_published']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Published</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Not Published</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($has_dean_approved): ?>
                                <td>
                                    <?php if ($exam['dean_approved'] ?? false): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="btn-group">
                                        <?php if (($exam['status'] ?? '') === 'completed' && !($exam['dean_approved'] ?? false)): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-success" title="Approve Results">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($has_results_published && ($exam['dean_approved'] ?? false) && !$exam['results_published']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="exam_id" value="<?= $exam['exam_id'] ?>">
                                            <input type="hidden" name="action" value="publish_results">
                                            <button type="submit" class="btn btn-sm btn-primary" title="Publish Results">
                                                <i class="bi bi-megaphone"></i> Publish
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
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
        
        <!-- Exam Results Workflow Info -->
        <div class="card mt-4">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Exam Results Approval Workflow</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <div class="p-3 bg-warning bg-opacity-10 rounded mb-2">
                            <i class="bi bi-journal-text fs-3 text-warning"></i>
                        </div>
                        <small><strong>1. Exam Completed</strong><br>Results entered by examiner</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="p-3 bg-info bg-opacity-10 rounded mb-2">
                            <i class="bi bi-person-check fs-3 text-info"></i>
                        </div>
                        <small><strong>2. Dean Review</strong><br>Review and approve results</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="p-3 bg-primary bg-opacity-10 rounded mb-2">
                            <i class="bi bi-megaphone fs-3 text-primary"></i>
                        </div>
                        <small><strong>3. Publish</strong><br>Make results available to students</small>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="p-3 bg-success bg-opacity-10 rounded mb-2">
                            <i class="bi bi-check-circle fs-3 text-success"></i>
                        </div>
                        <small><strong>4. Complete</strong><br>Students can view results</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
