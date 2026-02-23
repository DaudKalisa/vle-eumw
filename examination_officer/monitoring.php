<?php
/**
 * Exam Monitoring Dashboard - Examination Officer
 * Live camera snapshots, tab violations, session monitoring
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

$filter = $_GET['filter'] ?? '';
$exam_id = (int)($_GET['exam_id'] ?? 0);

// Get active sessions
$active_sessions = [];
$where = "es.status = 'in_progress'";
if ($exam_id) $where .= " AND es.exam_id = $exam_id";

$result = $conn->query("
    SELECT es.*, s.full_name as student_name, s.student_id as sid,
           ex.exam_name, ex.exam_code, ex.duration_minutes,
           TIMESTAMPDIFF(MINUTE, es.started_at, NOW()) as minutes_elapsed,
           (SELECT COUNT(*) FROM exam_monitoring em WHERE em.session_id = es.session_id AND em.event_type IN ('tab_change','fullscreen_exit','violation')) as alert_count,
           (SELECT snapshot_path FROM exam_monitoring em WHERE em.session_id = es.session_id AND em.event_type = 'camera_snapshot' ORDER BY em.timestamp DESC LIMIT 1) as latest_snapshot
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    JOIN exams ex ON es.exam_id = ex.exam_id
    WHERE $where
    ORDER BY es.started_at DESC
");
if ($result) while ($row = $result->fetch_assoc()) $active_sessions[] = $row;

// Get monitoring events (violations)
$events_where = "1=1";
if ($filter === 'violations') $events_where .= " AND em.event_type IN ('violation', 'tab_change', 'fullscreen_exit')";
if ($filter === 'snapshots') $events_where .= " AND em.event_type = 'camera_snapshot'";
if ($exam_id) $events_where .= " AND es.exam_id = $exam_id";

$events = [];
$result = $conn->query("
    SELECT em.*, es.student_id as sid, s.full_name as student_name,
           ex.exam_name, ex.exam_code
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams ex ON es.exam_id = ex.exam_id
    WHERE $events_where
    ORDER BY em.timestamp DESC
    LIMIT 100
");
if ($result) while ($row = $result->fetch_assoc()) $events[] = $row;

$all_exams = $conn->query("SELECT exam_id, exam_code, exam_name FROM exams WHERE is_active = 1 ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$page_title = "Exam Monitoring";
$breadcrumbs = [['title' => 'Monitoring']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Monitoring - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .snapshot-thumb { width: 120px; height: 90px; object-fit: cover; border-radius: 8px; border: 2px solid var(--vle-border); cursor: pointer; }
        .snapshot-thumb:hover { border-color: var(--vle-primary); transform: scale(1.05); }
        .live-indicator { animation: pulse-live 1.5s infinite; }
        @keyframes pulse-live { 0%,100%{opacity:1;} 50%{opacity:0.4;} }
        .monitoring-card { transition: var(--vle-transition); }
        .monitoring-card:hover { box-shadow: var(--vle-shadow-md); }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-camera-video me-2"></i>Examination Monitoring</h2>
                <p class="text-muted mb-0">Live invigilate exams, review camera snapshots and violations</p>
            </div>
            <?php if (count($active_sessions) > 0): ?>
                <span class="badge bg-danger fs-6 live-indicator"><i class="bi bi-broadcast me-1"></i><?= count($active_sessions) ?> Live Session(s)</span>
            <?php endif; ?>
        </div>

        <!-- Filter -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small">Exam</label>
                        <select name="exam_id" class="form-select">
                            <option value="">All Exams</option>
                            <?php foreach ($all_exams as $e): ?>
                                <option value="<?= $e['exam_id'] ?>" <?= $exam_id == $e['exam_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['exam_code'] . ' - ' . $e['exam_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Filter</label>
                        <select name="filter" class="form-select">
                            <option value="">All Events</option>
                            <option value="violations" <?= $filter === 'violations' ? 'selected' : '' ?>>Violations Only</option>
                            <option value="snapshots" <?= $filter === 'snapshots' ? 'selected' : '' ?>>Camera Snapshots</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="monitoring.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Live Sessions -->
        <?php if (!empty($active_sessions)): ?>
        <h4 class="mb-3"><i class="bi bi-broadcast live-indicator text-danger me-2"></i>Live Sessions</h4>
        <div class="row g-3 mb-4">
            <?php foreach ($active_sessions as $s): 
                $time_remaining = max(0, $s['duration_minutes'] - $s['minutes_elapsed']);
                $pct_elapsed = min(100, ($s['minutes_elapsed'] / max(1, $s['duration_minutes'])) * 100);
            ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm monitoring-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($s['sid']) ?></small>
                            </div>
                            <?php if ($s['alert_count'] > 0): ?>
                                <span class="badge bg-danger"><?= $s['alert_count'] ?> alerts</span>
                            <?php else: ?>
                                <span class="badge bg-success">Clean</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted d-block mb-2"><?= htmlspecialchars($s['exam_name']) ?></small>
                        
                        <?php if ($s['latest_snapshot'] && file_exists('../' . $s['latest_snapshot'])): ?>
                            <img src="../<?= htmlspecialchars($s['latest_snapshot']) ?>" class="snapshot-thumb d-block mb-2" alt="Latest snapshot" 
                                 data-bs-toggle="modal" data-bs-target="#snapshotModal" onclick="showSnapshot('../<?= htmlspecialchars($s['latest_snapshot']) ?>')">
                        <?php endif; ?>
                        
                        <div class="progress mb-1" style="height: 6px;">
                            <div class="progress-bar bg-<?= $pct_elapsed > 80 ? 'danger' : ($pct_elapsed > 60 ? 'warning' : 'success') ?>" style="width: <?= $pct_elapsed ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $s['minutes_elapsed'] ?>m elapsed / <?= $time_remaining ?>m remaining</small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Events Log -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Monitoring Events</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($events)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-shield-check display-4 d-block mb-3"></i>
                        <p>No monitoring events recorded.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Time</th>
                                    <th>Student</th>
                                    <th>Exam</th>
                                    <th>Event</th>
                                    <th>Details</th>
                                    <th>Snapshot</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $ev):
                                    $event_colors = [
                                        'camera_snapshot' => 'info',
                                        'tab_change' => 'warning',
                                        'window_blur' => 'warning',
                                        'window_focus' => 'success',
                                        'fullscreen_exit' => 'danger',
                                        'copy_attempt' => 'danger',
                                        'right_click' => 'secondary',
                                        'violation' => 'danger',
                                    ];
                                ?>
                                <tr>
                                    <td>
                                        <?= date('M d', strtotime($ev['timestamp'])) ?>
                                        <br><small class="text-muted"><?= date('h:i:s A', strtotime($ev['timestamp'])) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($ev['student_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($ev['sid']) ?></small>
                                    </td>
                                    <td><small><?= htmlspecialchars($ev['exam_code']) ?></small></td>
                                    <td><span class="badge bg-<?= $event_colors[$ev['event_type']] ?? 'secondary' ?>"><?= ucfirst(str_replace('_', ' ', $ev['event_type'])) ?></span></td>
                                    <td>
                                        <?php 
                                        $data = json_decode($ev['event_data'] ?? '{}', true);
                                        if ($data) echo '<small class="text-muted">' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</small>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($ev['snapshot_path'] && file_exists('../' . $ev['snapshot_path'])): ?>
                                            <img src="../<?= htmlspecialchars($ev['snapshot_path']) ?>" class="snapshot-thumb" 
                                                 onclick="showSnapshot('../<?= htmlspecialchars($ev['snapshot_path']) ?>')" data-bs-toggle="modal" data-bs-target="#snapshotModal">
                                        <?php else: ?>
                                            -
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
    </div>

    <!-- Snapshot Modal -->
    <div class="modal fade" id="snapshotModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Camera Snapshot</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center"><img id="snapshotImage" src="" class="img-fluid rounded" style="max-height: 500px;"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showSnapshot(src) { document.getElementById('snapshotImage').src = src; }
    
    // Auto-refresh every 15 seconds
    <?php if (!empty($active_sessions)): ?>
    setTimeout(() => location.reload(), 15000);
    <?php endif; ?>
    </script>
</body>
</html>
