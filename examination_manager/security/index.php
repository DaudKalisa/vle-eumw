<?php
// examination_manager/security/index.php - Security reports and files
require_once '../../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get security statistics
$statsQuery = "
    SELECT
        COUNT(DISTINCT es.session_id) as total_sessions,
        COUNT(DISTINCT CASE WHEN em.event_type IN ('tab_visibility_change', 'fullscreen_exited') THEN es.session_id END) as suspicious_sessions,
        COUNT(em.monitoring_id) as total_events,
        COUNT(CASE WHEN em.event_type = 'camera_snapshot' THEN 1 END) as camera_snapshots
    FROM exam_sessions es
    LEFT JOIN exam_monitoring em ON es.session_id = em.session_id
";

$stats = $conn->query($statsQuery)->fetch_assoc();

// Get recent security incidents
$incidentsQuery = "
    SELECT
        es.session_id,
        s.first_name,
        s.last_name,
        s.student_number,
        e.title as exam_title,
        COUNT(em.monitoring_id) as event_count,
        MAX(em.timestamp) as last_incident
    FROM exam_sessions es
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN exam_monitoring em ON es.session_id = em.session_id
    WHERE em.event_type IN ('tab_visibility_change', 'fullscreen_exited')
    GROUP BY es.session_id, s.first_name, s.last_name, s.student_number, e.title
    ORDER BY last_incident DESC
    LIMIT 10
";

$incidents = $conn->query($incidentsQuery)->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Security Center";
$breadcrumbs = [['title' => 'Security Center']];
include 'header_nav.php';
?>

<div class="vle-content">
    <div class="vle-page-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-lock me-2"></i>Security Center</h1>
                <p class="text-muted mb-0">Manage security reports, settings, and view incident history</p>
            </div>
        </div>
    </div>

    <!-- Security Statistics -->
    <div class="row mb-4 g-3">
        <div class="col-md-6 col-lg-3">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold"><?php echo $stats['total_sessions']; ?></h2>
                            <p class="text-muted mb-0">Total Exam Sessions</p>
                        </div>
                        <div class="vle-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-warning"><?php echo $stats['suspicious_sessions']; ?></h2>
                            <p class="text-muted mb-0">Suspicious Sessions</p>
                        </div>
                        <div class="vle-stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-info"><?php echo $stats['total_events']; ?></h2>
                            <p class="text-muted mb-0">Monitoring Events</p>
                        </div>
                        <div class="vle-stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-activity"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-success"><?php echo $stats['camera_snapshots']; ?></h2>
                            <p class="text-muted mb-0">Camera Snapshots</p>
                        </div>
                        <div class="vle-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-camera"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Reports -->
    <div class="card vle-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Security Reports</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <a href="generate_report.php?type=incidents" class="btn btn-danger w-100">
                        <i class="bi bi-exclamation-triangle me-2"></i>Generate Incident Report
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="generate_report.php?type=sessions" class="btn btn-info w-100">
                        <i class="bi bi-list-ul me-2"></i>Session Activity Report
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="view_snapshots.php" class="btn btn-success w-100">
                        <i class="bi bi-camera me-2"></i>View Camera Snapshots
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Security Incidents -->
    <div class="card vle-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Recent Security Incidents</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Events</th>
                            <th>Last Incident</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incidents)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No security incidents recorded</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($incidents as $incident): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($incident['first_name'] . ' ' . $incident['last_name']); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($incident['student_number']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($incident['exam_title']); ?></td>
                            <td>
                                <span class="badge bg-danger"><?php echo $incident['event_count']; ?></span>
                            </td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($incident['last_incident'])); ?></td>
                            <td>
                                <a href="../security_monitoring.php" class="btn btn-sm btn-warning">
                                    <i class="bi bi-eye"></i> Monitor
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

    <!-- Security Settings -->
    <div class="card vle-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Security Settings</h5>
        </div>
        <div class="card-body">
            <form method="post" action="update_security_settings.php">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="snapshot_interval" class="form-label">Camera Snapshot Interval (seconds)</label>
                        <input type="number" class="form-control" id="snapshot_interval" name="snapshot_interval"
                               value="30" min="10" max="300">
                    </div>
                    <div class="col-md-4">
                        <label for="max_tab_changes" class="form-label">Maximum Tab Changes Before Alert</label>
                        <input type="number" class="form-control" id="max_tab_changes" name="max_tab_changes"
                               value="3" min="1" max="10">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="enable_fullscreen_alert" name="enable_fullscreen_alert" checked>
                            <label class="form-check-label" for="enable_fullscreen_alert">
                                Alert on Fullscreen Exit
                            </label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Update Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>