<?php
// examination_manager/security_monitoring.php - Security monitoring dashboard
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get active exam sessions with monitoring data
$query = "
    SELECT
        es.session_id,
        es.student_id,
        es.exam_id,
        es.started_at,
        es.last_activity,
        e.title as exam_title,
        s.first_name,
        s.last_name,
        s.student_number,
        COUNT(em.monitoring_id) as total_events,
        SUM(CASE WHEN em.event_type IN ('tab_visibility_change', 'fullscreen_exited') THEN 1 ELSE 0 END) as suspicious_events
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN students s ON es.student_id = s.student_id
    LEFT JOIN exam_monitoring em ON es.session_id = em.session_id
    WHERE es.is_active = 1
    GROUP BY es.session_id, es.student_id, es.exam_id, es.started_at, es.last_activity, e.title, s.first_name, s.last_name, s.student_number
    ORDER BY es.last_activity DESC
";

$result = $conn->query($query);
$activeSessions = $result->fetch_all(MYSQLI_ASSOC);

// Get recent monitoring events
$eventsQuery = "
    SELECT
        em.*,
        s.first_name,
        s.last_name,
        s.student_number,
        e.title as exam_title
    FROM exam_monitoring em
    JOIN exam_sessions es ON em.session_id = es.session_id
    JOIN students s ON es.student_id = s.student_id
    JOIN exams e ON es.exam_id = e.exam_id
    ORDER BY em.timestamp DESC
    LIMIT 50
";

$eventsResult = $conn->query($eventsQuery);
$recentEvents = $eventsResult->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Security Monitoring";
$breadcrumbs = [['title' => 'Security Monitoring']];
include 'header_nav.php';
?>

<div class="vle-content">
    <div class="vle-page-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-shield-check me-2"></i>Security Monitoring Dashboard</h1>
                <p class="text-muted mb-0">Monitor active exam sessions and security events in real-time</p>
            </div>
        </div>
    </div>

    <!-- Active Sessions -->
    <div class="card vle-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Active Exam Sessions</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Started</th>
                            <th>Last Activity</th>
                            <th>Events</th>
                            <th>Suspicious</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeSessions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No active exam sessions</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($activeSessions as $session): ?>
                        <tr class="<?php echo $session['suspicious_events'] > 0 ? 'table-warning' : ''; ?>">
                            <td>
                                <?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($session['student_number']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($session['exam_title']); ?></td>
                            <td><?php echo $session['started_at'] ? date('H:i:s', strtotime($session['started_at'])) : 'Not started'; ?></td>
                            <td><?php echo $session['last_activity'] ? date('H:i:s', strtotime($session['last_activity'])) : 'N/A'; ?></td>
                            <td><?php echo $session['total_events']; ?></td>
                            <td>
                                <span class="badge <?php echo $session['suspicious_events'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo $session['suspicious_events']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewSessionDetails(<?php echo $session['session_id']; ?>)">
                                    <i class="bi bi-eye"></i> Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Events -->
    <div class="card vle-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Recent Monitoring Events</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Event Type</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentEvents)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No monitoring events recorded</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recentEvents as $event): ?>
                        <tr>
                            <td><?php echo date('H:i:s', strtotime($event['timestamp'])); ?></td>
                            <td><?php echo htmlspecialchars($event['first_name'] . ' ' . $event['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($event['exam_title']); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($event['event_type']); ?></span>
                            </td>
                            <td>
                                <?php
                                $eventData = json_decode($event['event_data'], true);
                                if ($eventData) {
                                    echo '<small>' . htmlspecialchars(json_encode($eventData, JSON_PRETTY_PRINT)) . '</small>';
                                } else {
                                    echo '<small class="text-muted">No details</small>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Session Details Modal -->
<div class="modal fade" id="sessionDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Session Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="sessionDetailsContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewSessionDetails(sessionId) {
    var modal = new bootstrap.Modal(document.getElementById('sessionDetailsModal'));
    modal.show();

    // Load session details via AJAX
    fetch('get_session_details.php?session_id=' + sessionId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('sessionDetailsContent').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('sessionDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load session details</div>';
        });
}

// Auto-refresh every 30 seconds
setInterval(function() {
    location.reload();
}, 30000);
</script>
</body>
</html>