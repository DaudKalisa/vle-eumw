<?php
// class_session.php - Lecturer starts a class session and generates QR code
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

// Get courses for dropdown
$courses = [];
$result = $conn->query("SELECT course_id, course_name FROM vle_courses WHERE lecturer_id = '$lecturer_id' AND is_active = 1");
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

$session_code = null;
$session_id = null;
$qr_url = null;
$created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = (int)$_POST['course_id'];
    $topic = trim($_POST['topic'] ?? '');
    $session_code = bin2hex(random_bytes(8));
    $session_date = date('Y-m-d');
    // Insert session
    $stmt = $conn->prepare("INSERT INTO vle_class_sessions (course_id, lecturer_id, session_date, topic, is_completed, session_code) VALUES (?, ?, ?, ?, 0, ?)");
    $stmt->bind_param("iisss", $course_id, $lecturer_id, $session_date, $topic, $session_code);
    $stmt->execute();
    $session_id = $stmt->insert_id;
    $stmt->close();
    $created = true;
    $qr_url = "https://your-vle.com/student/attendance_confirm.php?session=" . urlencode($session_code);
    // Get enrolled students for manual entry and reporting
    $students = [];
    $stmt = $conn->prepare("SELECT s.student_id, s.full_name FROM vle_enrollments ve JOIN students s ON ve.student_id = s.student_id WHERE ve.course_id = ?");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    // Get attendance records for this session
    $attendance = [];
    $stmt = $conn->prepare("SELECT a.student_id, s.full_name, a.attended, a.timestamp FROM vle_attendance a JOIN students s ON a.student_id = s.student_id WHERE a.session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $attendance[$row['student_id']] = $row;
    }
    $stmt->close();
    // Handle session close
    if (isset($_POST['close_session']) && count($_POST) === 1) {
        // Only close session if ONLY close_session is set in POST (no other actions)
        $stmt = $conn->prepare("UPDATE vle_class_sessions SET is_completed = 1 WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $stmt->close();
        echo '<div class="alert alert-info mt-2">Session closed. No further attendance can be registered.</div>';
    }
    // Handle CSV export
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=attendance_session_' . $session_id . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Student ID', 'Full Name', 'Status', 'Timestamp'));
        foreach ($students as $stu) {
            if (isset($attendance[$stu['student_id']])) {
                $row = array($stu['student_id'], $stu['full_name'], 'Present', $attendance[$stu['student_id']]['timestamp']);
            } else {
                $row = array($stu['student_id'], $stu['full_name'], 'Absent', '-');
            }
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Class Session</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white text-center">
                    <h3 class="mb-0">Start Class Session</h3>
                </div>
                <div class="card-body p-4">
                    <?php if ($created): ?>
                        <div class="alert alert-success">Class session started! Students can scan the QR code below to register attendance.</div>
                        <div class="text-center mb-3">
                            <canvas id="qr-code"></canvas>
                            <p class="mt-2"><strong>Session Code:</strong> <?php echo htmlspecialchars($session_code); ?></p>
                            <p class="small">Or visit: <a href="<?php echo $qr_url; ?>" target="_blank"><?php echo $qr_url; ?></a></p>
                        </div>
                        <script>
                            var qr = new QRious({
                                element: document.getElementById('qr-code'),
                                value: '<?php echo $qr_url; ?>',
                                size: 200
                            });
                        </script>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="close_session" class="btn btn-danger me-2" onclick="return confirm('Are you sure you want to close this session? No further attendance will be accepted.');">Close Session</button>
                        </form>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="export_csv" class="btn btn-success">Export Attendance (CSV)</button>
                        </form>
                        <a href="class_session.php" class="btn btn-outline-primary ms-2">Start Another Session</a>
                        <hr>
                        <h5>Manual Attendance Entry</h5>
                        <form method="POST" class="row g-2 mb-3" <?php if (isset($session) && $session['is_completed']) echo 'style="pointer-events:none;opacity:0.6;"'; ?>>
                            <input type="hidden" name="manual_session_id" value="<?php echo $session_id; ?>">
                            <div class="col-md-8">
                                <select name="manual_student_id" class="form-select" required <?php if (isset($session) && $session['is_completed']) echo 'disabled'; ?>>
                                    <option value="">-- Select Student --</option>
                                    <?php foreach ($students as $stu): ?>
                                        <option value="<?php echo $stu['student_id']; ?>"><?php echo htmlspecialchars($stu['full_name'] . ' (' . $stu['student_id'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-grid">
                                <button type="submit" name="mark_manual" class="btn btn-success" <?php if (isset($session) && $session['is_completed']) echo 'disabled'; ?>>Mark Present</button>
                            </div>
                        </form>
                        <?php if (isset($session) && $session['is_completed']): ?>
                            <div class="alert alert-info">Session closed. Manual attendance entry is now disabled.</div>
                        <?php endif; ?>
                        <?php
                        // Handle manual attendance entry and present/absent actions
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_session_id'], $_POST['manual_student_id'])) {
                            $manual_session_id = (int)$_POST['manual_session_id'];
                            $manual_student_id = $_POST['manual_student_id'];
                            if (isset($_POST['mark_present'])) {
                                // Mark as present
                                $stmt = $conn->prepare("SELECT attendance_id FROM vle_attendance WHERE session_id = ? AND student_id = ?");
                                $stmt->bind_param("is", $manual_session_id, $manual_student_id);
                                $stmt->execute();
                                $stmt->store_result();
                                if ($stmt->num_rows == 0) {
                                    $stmt->close();
                                    $stmt = $conn->prepare("INSERT INTO vle_attendance (session_id, course_id, student_id, attended) VALUES (?, ?, ?, 1)");
                                    $stmt->bind_param("iis", $manual_session_id, $course_id, $manual_student_id);
                                    $stmt->execute();
                                    echo '<div class="alert alert-success mt-2">Attendance marked as Present for student ID ' . htmlspecialchars($manual_student_id) . '.</div>';
                                } else {
                                    $stmt->close();
                                    $stmt = $conn->prepare("UPDATE vle_attendance SET attended = 1 WHERE session_id = ? AND student_id = ?");
                                    $stmt->bind_param("is", $manual_session_id, $manual_student_id);
                                    $stmt->execute();
                                    echo '<div class="alert alert-success mt-2">Attendance updated to Present for student ID ' . htmlspecialchars($manual_student_id) . '.</div>';
                                }
                                $stmt->close();
                            } elseif (isset($_POST['mark_absent'])) {
                                // Mark as absent
                                $stmt = $conn->prepare("SELECT attendance_id FROM vle_attendance WHERE session_id = ? AND student_id = ?");
                                $stmt->bind_param("is", $manual_session_id, $manual_student_id);
                                $stmt->execute();
                                $stmt->store_result();
                                if ($stmt->num_rows == 0) {
                                    $stmt->close();
                                    $stmt = $conn->prepare("INSERT INTO vle_attendance (session_id, course_id, student_id, attended) VALUES (?, ?, ?, 0)");
                                    $stmt->bind_param("iis", $manual_session_id, $course_id, $manual_student_id);
                                    $stmt->execute();
                                    echo '<div class="alert alert-warning mt-2">Attendance marked as Absent for student ID ' . htmlspecialchars($manual_student_id) . '.</div>';
                                } else {
                                    $stmt->close();
                                    $stmt = $conn->prepare("UPDATE vle_attendance SET attended = 0 WHERE session_id = ? AND student_id = ?");
                                    $stmt->bind_param("is", $manual_session_id, $manual_student_id);
                                    $stmt->execute();
                                    echo '<div class="alert alert-warning mt-2">Attendance updated to Absent for student ID ' . htmlspecialchars($manual_student_id) . '.</div>';
                                }
                                $stmt->close();
                            } elseif (isset($_POST['mark_manual'])) {
                                // Legacy: Mark as present (default)
                                $stmt = $conn->prepare("SELECT attendance_id FROM vle_attendance WHERE session_id = ? AND student_id = ?");
                                $stmt->bind_param("is", $manual_session_id, $manual_student_id);
                                $stmt->execute();
                                $stmt->store_result();
                                if ($stmt->num_rows == 0) {
                                    $stmt->close();
                                    $stmt = $conn->prepare("INSERT INTO vle_attendance (session_id, course_id, student_id, attended) VALUES (?, ?, ?, 1)");
                                    $stmt->bind_param("iis", $manual_session_id, $course_id, $manual_student_id);
                                    $stmt->execute();
                                    echo '<div class="alert alert-success mt-2">Attendance marked for student ID ' . htmlspecialchars($manual_student_id) . '.</div>';
                                } else {
                                    echo '<div class="alert alert-info mt-2">Attendance already marked for this student.</div>';
                                }
                                $stmt->close();
                            }
                        }
                        ?>
                        <hr>
                        <h5>Attendance Report</h5>
                        <?php
                        // Fetch extra info for report header
                        $course_info = $conn->query("SELECT course_code FROM vle_courses WHERE course_id = $course_id")->fetch_assoc();
                        $lecturer_info = $conn->query("SELECT full_name FROM lecturers WHERE lecturer_id = $lecturer_id")->fetch_assoc();
                        $session_info = $conn->query("SELECT session_date FROM vle_class_sessions WHERE session_id = $session_id")->fetch_assoc();
                        ?>
                        <div class="mb-2">
                            <strong>Course Code:</strong> <?php echo htmlspecialchars($course_info['course_code'] ?? ''); ?> |
                            <strong>Lecturer:</strong> <?php echo htmlspecialchars($lecturer_info['full_name'] ?? ''); ?> |
                            <strong>Date:</strong> <?php echo htmlspecialchars($session_info['session_date'] ?? ''); ?> |
                            <strong>Time:</strong> <?php echo date('H:i'); ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Student ID</th>
                                        <th>Year of Study</th>
                                        <th>Status</th>
                                        <th>Timestamp</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $stu):
                                        // Get year of study for each student
                                        $stu_info = $conn->query("SELECT year_of_study FROM students WHERE student_id = '" . $conn->real_escape_string($stu['student_id']) . "'")->fetch_assoc();
                                        $year_of_study = $stu_info['year_of_study'] ?? '';
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($stu['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($stu['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($year_of_study); ?></td>
                                            <td>
                                                <?php if (isset($attendance[$stu['student_id']]) && $attendance[$stu['student_id']]['attended'] == 1): ?>
                                                    <span class="badge bg-success">Present</span>
                                                <?php elseif (isset($attendance[$stu['student_id']]) && $attendance[$stu['student_id']]['attended'] == 0): ?>
                                                    <span class="badge bg-danger">Absent</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Not Marked</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo isset($attendance[$stu['student_id']]) ? $attendance[$stu['student_id']]['timestamp'] : '-'; ?>
                                            </td>
                                            <td>
                                            <?php if (!isset($session) || !$session['is_completed']): ?>
                                                <form method="POST" style="display:inline-block">
                                                    <input type="hidden" name="manual_session_id" value="<?php echo $session_id; ?>">
                                                    <input type="hidden" name="manual_student_id" value="<?php echo $stu['student_id']; ?>">
                                                    <button type="submit" name="mark_present" class="btn btn-success btn-sm" <?php if (isset($attendance[$stu['student_id']]) && $attendance[$stu['student_id']]['attended'] == 1) echo 'disabled'; ?>>Present</button>
                                                </form>
                                                <form method="POST" style="display:inline-block">
                                                    <input type="hidden" name="manual_session_id" value="<?php echo $session_id; ?>">
                                                    <input type="hidden" name="manual_student_id" value="<?php echo $stu['student_id']; ?>">
                                                    <button type="submit" name="mark_absent" class="btn btn-danger btn-sm" <?php if (isset($attendance[$stu['student_id']]) && $attendance[$stu['student_id']]['attended'] == 0) echo 'disabled'; ?>>Absent</button>
                                                </form>
                                            <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="course_id" class="form-label">Select Course</label>
                                <select name="course_id" id="course_id" class="form-select" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?php echo $c['course_id']; ?>"><?php echo htmlspecialchars($c['course_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="topic" class="form-label">Topic (optional)</label>
                                <input type="text" name="topic" id="topic" class="form-control" maxlength="255">
                            </div>
                            <button type="submit" class="btn btn-primary">Start Class Session</button>
                        </form>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary mt-3">Back to Dashboard</a>
                </div>
            </div>
        </div>

    </div>
</div>
<?php  ?>
</body>
</html>
