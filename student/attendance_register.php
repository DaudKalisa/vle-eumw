<?php
/**
 * Student Attendance Register
 * Shows attendance from attendance_sessions + attendance_records
 * plus material engagement from vle_progress
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);

$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'];

// Get enrolled courses
$courses = [];
$stmt = $conn->prepare("
    SELECT vc.course_id, vc.course_code, vc.course_name, l.full_name AS lecturer_name, ve.enrollment_id
    FROM vle_enrollments ve
    JOIN vle_courses vc ON ve.course_id = vc.course_id
    LEFT JOIN lecturers l ON vc.lecturer_id = l.lecturer_id
    WHERE ve.student_id = ? AND vc.is_active = TRUE
    ORDER BY vc.course_name
");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $courses[] = $row;
$stmt->close();

// For each course compute attendance + engagement
$attendance = [];
foreach ($courses as $course) {
    $cid = $course['course_id'];

    // Session attendance
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE course_id = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $stmt->bind_result($total_sessions);
    $stmt->fetch();
    $stmt->close();

    $attended = 0;
    if ($total_sessions > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions asn ON ar.session_id = asn.session_id WHERE asn.course_id = ? AND ar.student_id = ? AND ar.status IN ('present','late','auto_tracked')");
        $stmt->bind_param("is", $cid, $student_id);
        $stmt->execute();
        $stmt->bind_result($attended);
        $stmt->fetch();
        $stmt->close();
    }
    $att_pct = $total_sessions > 0 ? round(($attended / $total_sessions) * 100, 1) : 0;

    // Material engagement
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT content_id) FROM vle_progress WHERE enrollment_id = ? AND progress_type = 'content_viewed' AND content_id IS NOT NULL");
    $stmt->bind_param("i", $course['enrollment_id']);
    $stmt->execute();
    $stmt->bind_result($viewed);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM vle_weekly_content WHERE course_id = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $stmt->bind_result($total_content);
    $stmt->fetch();
    $stmt->close();

    $eng_pct = $total_content > 0 ? round(($viewed / $total_content) * 100, 1) : 0;
    $overall = round(($att_pct * 0.6) + ($eng_pct * 0.4), 1);

    $attendance[] = [
        'course_code' => $course['course_code'],
        'course_name' => $course['course_name'],
        'lecturer_name' => $course['lecturer_name'],
        'total_sessions' => $total_sessions,
        'attended' => $attended,
        'att_pct' => $att_pct,
        'viewed' => $viewed,
        'total_content' => $total_content,
        'eng_pct' => $eng_pct,
        'overall' => $overall,
    ];
}

$page_title = "Attendance Register";

// Check payment status
require_once '../includes/student_attendance_helper.php';
$payment_status = getStudentPaymentStatus($conn, $student_id);

// Fetch activity attendance data (course access, content views, live sessions)
$activity_attendance = [];
$activity_summary = ['total_activities' => 0, 'course_access' => 0, 'content_view' => 0, 'live_session' => 0];
try {
    $aa_check = $conn->query("SHOW TABLES LIKE 'student_activity_attendance'");
    if ($aa_check && $aa_check->num_rows > 0) {
        $aa_stmt = $conn->prepare("SELECT saa.id, saa.course_id, saa.access_type, saa.detail, saa.access_time, saa.attendance_date, vc.course_code, vc.course_name FROM student_activity_attendance saa LEFT JOIN vle_courses vc ON saa.course_id = vc.course_id WHERE saa.student_id = ? ORDER BY saa.access_time DESC LIMIT 50");
        if ($aa_stmt) {
            $aa_stmt->bind_param("s", $student_id);
            $aa_stmt->execute();
            $aa_res = $aa_stmt->get_result();
            while ($aa_row = $aa_res->fetch_assoc()) $activity_attendance[] = $aa_row;
            $aa_stmt->close();
        }

        // Summary counts
        $aa_sum = $conn->prepare("SELECT access_type, COUNT(*) AS cnt FROM student_activity_attendance WHERE student_id = ? GROUP BY access_type");
        if ($aa_sum) {
            $aa_sum->bind_param("s", $student_id);
            $aa_sum->execute();
            $aa_sum_res = $aa_sum->get_result();
            while ($sr = $aa_sum_res->fetch_assoc()) {
                $activity_summary[$sr['access_type']] = (int)$sr['cnt'];
                $activity_summary['total_activities'] += (int)$sr['cnt'];
            }
            $aa_sum->close();
        }
    }
} catch (Throwable $e) {
    // Table may not exist yet
}

// Fetch login attendance data
$login_attendance = [];
$login_summary = ['total_logins' => 0, 'total_minutes' => 0, 'avg_minutes' => 0];
try {
    $la_stmt = $conn->prepare("SELECT id, login_time, logout_time, duration_minutes, ip_address, attendance_date FROM student_login_attendance WHERE student_id = ? ORDER BY login_time DESC LIMIT 50");
    if ($la_stmt) {
        $la_stmt->bind_param("s", $student_id);
        $la_stmt->execute();
        $la_res = $la_stmt->get_result();
        while ($la_row = $la_res->fetch_assoc()) $login_attendance[] = $la_row;
        $la_stmt->close();
    }

    // Summary stats
    $la_stmt2 = $conn->prepare("SELECT COUNT(*) AS total_logins, COALESCE(SUM(duration_minutes), 0) AS total_minutes, COALESCE(ROUND(AVG(duration_minutes), 0), 0) AS avg_minutes FROM student_login_attendance WHERE student_id = ? AND duration_minutes IS NOT NULL");
    if ($la_stmt2) {
        $la_stmt2->bind_param("s", $student_id);
        $la_stmt2->execute();
        $la_summary_row = $la_stmt2->get_result()->fetch_assoc();
        if ($la_summary_row) $login_summary = $la_summary_row;
        $la_stmt2->close();
    }
} catch (Throwable $e) {
    // Table may not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .engagement-bar { height: 8px; border-radius: 4px; background: #e5e7eb; overflow: hidden; }
        .engagement-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="vle-page-title"><i class="bi bi-clipboard-data me-2"></i>My Attendance</h2>
            <p class="text-muted mb-0">Track your session attendance and material engagement across all courses</p>
        </div>
        <a href="attendance_confirm.php" class="btn btn-success"><i class="bi bi-qr-code-scan me-1"></i>Check In (QR/Code)</a>
    </div>

    <!-- Summary Cards -->
    <?php
    $total_att = count($attendance);
    $good = count(array_filter($attendance, fn($a) => $a['overall'] >= 75));
    $risk = count(array_filter($attendance, fn($a) => $a['overall'] >= 50 && $a['overall'] < 75));
    $critical = count(array_filter($attendance, fn($a) => $a['overall'] < 50));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="display-6 fw-bold text-primary"><?= $total_att ?></div><small class="text-muted">Enrolled Courses</small></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="display-6 fw-bold text-success"><?= $good ?></div><small class="text-muted">Good Standing</small></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="display-6 fw-bold text-warning"><?= $risk ?></div><small class="text-muted">At Risk</small></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="display-6 fw-bold text-danger"><?= $critical ?></div><small class="text-muted">Critical</small></div></div></div>
    </div>

    <!-- Payment Status Banner -->
    <?php if ($payment_status['has_paid']): ?>
    <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-shield-check fs-4 me-3"></i>
        <div>
            <strong>Tuition Fee Paid</strong> &mdash; <?= $payment_status['payment_percentage'] ?>% paid. Your attendance is being automatically tracked when you access courses, content, and live sessions.
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-exclamation-triangle fs-4 me-3"></i>
        <div>
            <strong>Tuition Fee Unpaid</strong> &mdash; Automatic attendance tracking is only available for students who have paid their tuition fees. Please make your payment to enable attendance tracking.
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Attendance & Engagement per Course</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($attendance)): ?>
            <div class="text-center py-4 text-muted">You are not enrolled in any active courses.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th>Lecturer</th>
                            <th>Sessions</th>
                            <th>Session Attendance</th>
                            <th>Material Engagement</th>
                            <th>Overall</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $row): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['course_code']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($row['course_name']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($row['lecturer_name'] ?? 'N/A') ?></td>
                            <td><?= $row['attended'] ?> / <?= $row['total_sessions'] ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="engagement-bar flex-grow-1" style="width:80px;">
                                        <div class="engagement-fill bg-<?= $row['att_pct'] >= 75 ? 'success' : ($row['att_pct'] >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $row['att_pct'] ?>%"></div>
                                    </div>
                                    <span class="fw-bold"><?= $row['att_pct'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="engagement-bar flex-grow-1" style="width:80px;">
                                        <div class="engagement-fill" style="width:<?= $row['eng_pct'] ?>%; background:#7c3aed;"></div>
                                    </div>
                                    <span class="fw-bold" style="color:#7c3aed;"><?= $row['eng_pct'] ?>%</span>
                                </div>
                                <small class="text-muted"><?= $row['viewed'] ?>/<?= $row['total_content'] ?> items</small>
                            </td>
                            <td><strong class="text-<?= $row['overall'] >= 75 ? 'success' : ($row['overall'] >= 50 ? 'warning' : 'danger') ?>"><?= $row['overall'] ?>%</strong></td>
                            <td>
                                <?php if ($row['overall'] >= 75): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Good</span>
                                <?php elseif ($row['overall'] >= 50): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>At Risk</span>
                                <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Critical</span>
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

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body">
            <small class="text-muted">
                <strong>Overall Score:</strong> 60% Session Attendance + 40% Material Engagement |
                <span class="text-success"><i class="bi bi-circle-fill"></i> Good (&ge;75%)</span>
                <span class="text-warning ms-2"><i class="bi bi-circle-fill"></i> At Risk (50-74%)</span>
                <span class="text-danger ms-2"><i class="bi bi-circle-fill"></i> Critical (&lt;50%)</span>
            </small>
        </div>
    </div>

    <!-- Course Access Activity (Auto-Tracked) -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Course Access Activity</h5>
            <div class="d-flex gap-3">
                <span class="badge bg-primary fs-6"><?= $activity_summary['total_activities'] ?> total</span>
                <span class="badge bg-info text-dark fs-6"><i class="bi bi-book me-1"></i><?= $activity_summary['course_access'] ?> courses</span>
                <span class="badge bg-success fs-6"><i class="bi bi-file-text me-1"></i><?= $activity_summary['content_view'] ?> content</span>
                <span class="badge bg-warning text-dark fs-6"><i class="bi bi-camera-video me-1"></i><?= $activity_summary['live_session'] ?> live</span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($activity_attendance)): ?>
            <div class="text-center py-4 text-muted">
                <?php if (!$payment_status['has_paid']): ?>
                    <i class="bi bi-lock fs-3 d-block mb-2"></i>
                    Pay your tuition fees to enable automatic attendance tracking.
                <?php else: ?>
                    No course access activity recorded yet. Visit your courses to start tracking.
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Activity Type</th>
                            <th>Course</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity_attendance as $aa): ?>
                        <tr>
                            <td><?= date('D, d M Y', strtotime($aa['attendance_date'])) ?></td>
                            <td><?= date('H:i:s', strtotime($aa['access_time'])) ?></td>
                            <td>
                                <?php
                                $type_labels = [
                                    'course_access' => ['My Courses', 'bg-info text-dark', 'bi-book'],
                                    'content_view' => ['Content View', 'bg-success', 'bi-file-text'],
                                    'live_session' => ['Live Session', 'bg-warning text-dark', 'bi-camera-video'],
                                    'assignment_view' => ['Assignment', 'bg-primary', 'bi-pencil-square'],
                                ];
                                $tl = $type_labels[$aa['access_type']] ?? ['Unknown', 'bg-secondary', 'bi-question'];
                                ?>
                                <span class="badge <?= $tl[1] ?>"><i class="bi <?= $tl[2] ?> me-1"></i><?= $tl[0] ?></span>
                            </td>
                            <td>
                                <?php if ($aa['course_code']): ?>
                                    <strong><?= htmlspecialchars($aa['course_code']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($aa['course_name'] ?? '') ?></small>
                                <?php else: ?>
                                    <span class="text-muted">General</span>
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

    <!-- System Activity / Login Attendance -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>System Activity</h5>
            <div class="d-flex gap-3">
                <span class="badge bg-primary fs-6"><?= (int)$login_summary['total_logins'] ?> sessions</span>
                <span class="badge bg-info text-dark fs-6"><?= (int)$login_summary['avg_minutes'] ?> min avg</span>
                <span class="badge bg-success fs-6">
                    <?php
                    $total_hrs = floor((int)$login_summary['total_minutes'] / 60);
                    $total_mins = (int)$login_summary['total_minutes'] % 60;
                    echo $total_hrs . 'h ' . $total_mins . 'm total';
                    ?>
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($login_attendance)): ?>
            <div class="text-center py-4 text-muted">No login activity recorded yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Login Time</th>
                            <th>Logout Time</th>
                            <th>Duration</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($login_attendance as $la): ?>
                        <tr>
                            <td><?= date('D, d M Y', strtotime($la['attendance_date'])) ?></td>
                            <td><?= date('H:i:s', strtotime($la['login_time'])) ?></td>
                            <td>
                                <?php if ($la['logout_time']): ?>
                                    <?= date('H:i:s', strtotime($la['logout_time'])) ?>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="bi bi-circle-fill me-1"></i>Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($la['duration_minutes'] !== null): ?>
                                    <?php
                                    $d_hrs = floor($la['duration_minutes'] / 60);
                                    $d_mins = $la['duration_minutes'] % 60;
                                    echo ($d_hrs > 0 ? $d_hrs . 'h ' : '') . $d_mins . 'min';
                                    ?>
                                <?php elseif (!$la['logout_time']): ?>
                                    <span class="text-muted">In progress</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$la['logout_time']): ?>
                                    <span class="badge bg-success">Online</span>
                                <?php elseif ($la['duration_minutes'] !== null && $la['duration_minutes'] >= 30): ?>
                                    <span class="badge bg-primary">Completed</span>
                                <?php elseif ($la['duration_minutes'] !== null): ?>
                                    <span class="badge bg-warning text-dark">Short Session</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unknown</span>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
