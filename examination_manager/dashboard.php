<?php
// examination_manager/dashboard.php - Examination Manager Dashboard (Professional Design)
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get statistics
$stats = [];

// Total exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE is_active = 1");
$stats['total_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Active exam sessions (currently running)
$result = $conn->query("
    SELECT COUNT(*) as total FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    WHERE es.is_active = 1 AND NOW() BETWEEN e.start_time AND e.end_time
");
$stats['active_sessions'] = $result ? $result->fetch_assoc()['total'] : 0;

// Pending reviews/flagged submissions
$result = $conn->query("SELECT COUNT(*) as total FROM exam_results WHERE status = 'flagged'");
$stats['pending_reviews'] = $result ? $result->fetch_assoc()['total'] : 0;

// Today's exams
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE DATE(start_time) = CURDATE() AND is_active = 1");
$stats['today_exams'] = $result ? $result->fetch_assoc()['total'] : 0;

// Get active exams (happening now or scheduled for today)
$active_exams = [];
$exam_query = "
    SELECT e.*, c.course_name, c.course_code, l.full_name as lecturer_name,
           COUNT(es.session_id) as active_session_count,
           CASE
               WHEN NOW() BETWEEN e.start_time AND e.end_time THEN 'active'
               WHEN NOW() < e.start_time THEN 'upcoming'
               ELSE 'completed'
           END as exam_status
    FROM exams e
    JOIN vle_courses c ON e.course_id = c.course_id
    LEFT JOIN lecturers l ON e.lecturer_id = l.lecturer_id
    LEFT JOIN exam_sessions es ON e.exam_id = es.exam_id AND es.is_active = 1
    WHERE e.is_active = 1 AND DATE(e.start_time) = CURDATE()
    GROUP BY e.exam_id
    ORDER BY e.start_time ASC
";

$result = $conn->query($exam_query);
if ($result) {
    while ($exam = $result->fetch_assoc()) {
        if ($exam['exam_status'] === 'active') {
            $active_exams['active'][] = $exam;
        } elseif ($exam['exam_status'] === 'upcoming') {
            $active_exams['upcoming'][] = $exam;
        } else {
            $active_exams['completed'][] = $exam;
        }
    }
}

// Get recent exam sessions for monitoring
$recent_sessions = [];
$sessions_query = "
    SELECT es.*, e.exam_name, e.exam_id, s.full_name as student_name,
           TIMESTAMPDIFF(MINUTE, es.started_at, NOW()) as elapsed_minutes,
           e.duration_minutes
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.exam_id
    JOIN students s ON es.student_id = s.student_id
    WHERE es.is_active = 1
    ORDER BY es.started_at DESC
    LIMIT 10
";

$result = $conn->query($sessions_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_sessions[] = $row;
    }
}

$pageTitle = "Examination Manager Dashboard";
$breadcrumbs = [['title' => 'Dashboard']];
include 'header_nav.php';
?>

<div class="vle-content">
    <div class="vle-page-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-shield-check me-2"></i>Examination Manager Dashboard</h1>
                <p class="text-muted mb-0">Manage examinations, monitor sessions, and oversee academic integrity</p>
            </div>
            <a href="create_exam.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create New Exam
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-6 col-lg-3">
            <div class="card vle-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold"><?php echo $stats['total_exams']; ?></h2>
                            <p class="text-muted mb-0">Total Exams</p>
                        </div>
                        <div class="vle-stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-file-earmark-text"></i>
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
                            <h2 class="mb-1 fw-bold text-success"><?php echo $stats['active_sessions']; ?></h2>
                            <p class="text-muted mb-0">Active Sessions</p>
                        </div>
                        <div class="vle-stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-play-circle"></i>
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
                            <h2 class="mb-1 fw-bold text-warning"><?php echo $stats['pending_reviews']; ?></h2>
                            <p class="text-muted mb-0">Pending Reviews</p>
                        </div>
                        <div class="vle-stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-flag"></i>
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
                            <h2 class="mb-1 fw-bold text-info"><?php echo $stats['today_exams']; ?></h2>
                            <p class="text-muted mb-0">Today's Exams</p>
                        </div>
                        <div class="vle-stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-calendar-event"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Exams Section -->
    <?php if (!empty($active_exams['active'])): ?>
    <div class="card vle-card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-play-circle me-2"></i>Active Exams (Now)</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($active_exams['active'] as $exam): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-success h-100">
                        <div class="card-body">
                            <h6 class="card-title"><?php echo htmlspecialchars($exam['exam_name']); ?></h6>
                            <p class="card-text">
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($exam['course_code'] . ' - ' . $exam['course_name']); ?>
                                </small>
                            </p>
                            <div class="mb-2">
                                <span class="badge bg-success">Active Now</span>
                            </div>
                            <p class="mb-2 small">
                                <strong>Lecturer:</strong> <?php echo htmlspecialchars($exam['lecturer_name'] ?: 'N/A'); ?><br>
                                <strong>Active Sessions:</strong> <?php echo $exam['active_session_count']; ?><br>
                                <strong>Duration:</strong> <?php echo $exam['duration_minutes']; ?> minutes
                            </p>
                            <p class="mb-3 small">
                                <strong>Schedule:</strong> <?php echo date('M d, Y H:i', strtotime($exam['start_time'])); ?> -
                                <?php echo date('H:i', strtotime($exam['end_time'])); ?>
                            </p>
                            <div class="d-flex gap-2">
                                <a href="security_monitoring.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-success btn-sm">
                                    <i class="bi bi-eye"></i> Monitor
                                </a>
                                <a href="generate_tokens.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-key"></i> Tokens
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upcoming Exams Section -->
    <?php if (!empty($active_exams['upcoming'])): ?>
    <div class="card vle-card mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="bi bi-clock me-2"></i>Upcoming Exams (Today)</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($active_exams['upcoming'] as $exam): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-warning h-100">
                        <div class="card-body">
                            <h6 class="card-title"><?php echo htmlspecialchars($exam['exam_name']); ?></h6>
                            <p class="card-text">
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($exam['course_code'] . ' - ' . $exam['course_name']); ?>
                                </small>
                            </p>
                            <div class="mb-2">
                                <span class="badge bg-warning text-dark">Upcoming</span>
                            </div>
                            <p class="mb-2 small">
                                <strong>Lecturer:</strong> <?php echo htmlspecialchars($exam['lecturer_name'] ?: 'N/A'); ?><br>
                                <strong>Questions:</strong> <?php echo $exam['total_questions']; ?><br>
                                <strong>Total Marks:</strong> <?php echo $exam['total_marks']; ?>
                            </p>
                            <p class="mb-3 small">
                                <strong>Scheduled:</strong> <?php echo date('M d, Y H:i', strtotime($exam['start_time'])); ?> -
                                <?php echo date('H:i', strtotime($exam['end_time'])); ?>
                            </p>
                            <div class="d-flex gap-2">
                                <a href="generate_tokens.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-warning btn-sm">
                                    <i class="bi bi-key"></i> Generate Tokens
                                </a>
                                <a href="edit_exam.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Live Monitoring Section -->
    <?php if (!empty($recent_sessions)): ?>
    <div class="card vle-card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-eye me-2"></i>Live Session Monitor (<?php echo count($recent_sessions); ?> Active)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student Name</th>
                            <th>Exam</th>
                            <th>Elapsed Time</th>
                            <th>Remaining Time</th>
                            <th>Started At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_sessions as $session): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($session['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($session['exam_name']); ?></td>
                            <td>
                                <strong><?php echo $session['elapsed_minutes']; ?> min</strong>
                            </td>
                            <td>
                                <?php
                                $remaining = $session['duration_minutes'] - $session['elapsed_minutes'];
                                $remaining = max(0, $remaining);
                                $badge_class = $remaining <= 5 ? 'danger' : ($remaining <= 10 ? 'warning' : 'success');
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $remaining; ?> min</span>
                            </td>
                            <td><?php echo date('H:i', strtotime($session['started_at'])); ?></td>
                            <td>
                                <a href="security_monitoring.php?session_id=<?php echo $session['session_id']; ?>" class="btn btn-sm btn-info" title="View Monitoring">
                                    <i class="bi bi-camera"></i>
                                </a>
                                <button class="btn btn-sm btn-danger" onclick="confirmTerminate(<?php echo $session['session_id']; ?>)" title="Terminate Session">
                                    <i class="bi bi-stop-fill"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- No Sessions Message -->
    <?php if (empty($recent_sessions) && empty($active_exams['active'])): ?>
    <div class="card vle-card">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-1 text-muted mb-3"></i>
            <h5 class="text-muted">No Active Examinations</h5>
            <p class="text-muted">There are no exams currently running or scheduled for today.</p>
            <a href="create_exam.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create New Exam
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmTerminate(sessionId) {
    if (confirm('Are you sure you want to terminate this exam session? This action cannot be undone.')) {
        fetch('terminate_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'session_id=' + sessionId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Session terminated successfully');
                location.reload();
            } else {
                alert('Failed to terminate session: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error terminating session');
        });
    }
}
</script>
</body>
</html>