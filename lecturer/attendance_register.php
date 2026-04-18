<?php
/**
 * Attendance Register - Smart Attendance Tracking System
 * Tracks student attendance based on:
 *  1. Weekly material engagement (auto-tracked via vle_progress)
 *  2. Live session QR/code attendance
 *  3. Manual lecturer override
 * Uses attendance_sessions + attendance_records tables from setup.php
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = getRelatedIdForRole('lecturer');

// Ensure attendance tables exist
$conn->query("CREATE TABLE IF NOT EXISTS attendance_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    lecturer_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    topic VARCHAR(255) NULL,
    week_number INT NULL,
    session_code VARCHAR(10) UNIQUE NOT NULL,
    qr_code_data TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_course (course_id),
    INDEX idx_lecturer (lecturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS attendance_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    check_in_time DATETIME NOT NULL,
    check_out_time DATETIME NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('present','late','absent','excused','auto_tracked') DEFAULT 'present',
    source ENUM('qr_scan','manual','auto_material') DEFAULT 'manual',
    duration_minutes INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_session_student (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add columns if they don't exist (for existing tables)
$conn->query("ALTER TABLE attendance_sessions ADD COLUMN IF NOT EXISTS topic VARCHAR(255) NULL AFTER end_time");
$conn->query("ALTER TABLE attendance_sessions ADD COLUMN IF NOT EXISTS week_number INT NULL AFTER topic");
$conn->query("ALTER TABLE attendance_records ADD COLUMN IF NOT EXISTS source ENUM('qr_scan','manual','auto_material') DEFAULT 'manual' AFTER status");

// Get lecturer courses
$courses = [];
$stmt = $conn->prepare("SELECT course_id, course_code, course_name FROM vle_courses WHERE lecturer_id = ? AND is_active = 1 ORDER BY course_name");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $courses[] = $row;
$stmt->close();

$success = '';
$error = '';
$selected_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : (isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0);
$view = $_GET['view'] ?? 'overview';

// ==============================
// POST Action Handlers
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Create a new live session ---
    if (isset($_POST['create_session'])) {
        $course_id = (int)$_POST['course_id'];
        $topic = trim($_POST['topic'] ?? '');
        $week_number = !empty($_POST['week_number']) ? (int)$_POST['week_number'] : null;
        $session_date = $_POST['session_date'] ?? date('Y-m-d');
        $start_time = $_POST['start_time'] ?? date('H:i:s');

        // Generate unique 8-char session code
        $session_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $qr_url = $base_url . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/student/attendance_confirm.php?session=' . urlencode($session_code);

        $stmt = $conn->prepare("INSERT INTO attendance_sessions (course_id, lecturer_id, session_date, start_time, topic, week_number, session_code, qr_code_data, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("iisssiis", $course_id, $lecturer_id, $session_date, $start_time, $topic, $week_number, $session_code, $qr_url);
        if ($stmt->execute()) {
            $success = "Live session created! Code: <strong>$session_code</strong>";
            $selected_course = $course_id;
            $view = 'sessions';
        } else {
            $error = "Failed to create session: " . $conn->error;
        }
        $stmt->close();
    }

    // --- Close a session ---
    if (isset($_POST['close_session'])) {
        $session_id = (int)$_POST['session_id'];
        $end_time = date('H:i:s');
        $stmt = $conn->prepare("UPDATE attendance_sessions SET is_active = 0, end_time = ? WHERE session_id = ? AND lecturer_id = ?");
        $stmt->bind_param("sii", $end_time, $session_id, $lecturer_id);
        $stmt->execute();
        $stmt->close();
        $success = "Session closed successfully.";
    }

    // --- Manual attendance toggle ---
    if (isset($_POST['mark_attendance'])) {
        $session_id = (int)$_POST['session_id'];
        $student_id = $_POST['student_id'];
        $new_status = $_POST['new_status'];
        $allowed = ['present', 'late', 'absent', 'excused'];
        if (!in_array($new_status, $allowed)) {
            $error = "Invalid status.";
        } else {
            // Upsert
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO attendance_records (session_id, student_id, check_in_time, status, source) VALUES (?, ?, ?, ?, 'manual') ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'manual'");
            $stmt->bind_param("isss", $session_id, $student_id, $now, $new_status);
            $stmt->execute();
            $stmt->close();
            $success = "Attendance updated.";
        }
    }

    // --- Bulk mark all unmarked as absent ---
    if (isset($_POST['mark_all_absent'])) {
        $session_id = (int)$_POST['session_id'];
        // Get session course
        $stmt = $conn->prepare("SELECT course_id FROM attendance_sessions WHERE session_id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $session_id, $lecturer_id);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($sess) {
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT IGNORE INTO attendance_records (session_id, student_id, check_in_time, status, source) SELECT ?, ve.student_id, ?, 'absent', 'manual' FROM vle_enrollments ve WHERE ve.course_id = ? AND ve.student_id NOT IN (SELECT student_id FROM attendance_records WHERE session_id = ?)");
            $stmt->bind_param("isii", $session_id, $now, $sess['course_id'], $session_id);
            $stmt->execute();
            $success = "All unmarked students marked as absent.";
            $stmt->close();
        }
    }

    // --- Auto-track from materials ---
    if (isset($_POST['auto_track'])) {
        $course_id = (int)$_POST['course_id'];
        $week = (int)$_POST['week_number'];

        // Find or create a session for this week
        $stmt = $conn->prepare("SELECT session_id FROM attendance_sessions WHERE course_id = ? AND lecturer_id = ? AND week_number = ? LIMIT 1");
        $stmt->bind_param("iii", $course_id, $lecturer_id, $week);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $auto_session_id = $existing['session_id'];
        } else {
            // Create auto-tracked session
            $session_code = 'AW' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $session_date = date('Y-m-d');
            $start_time = '00:00:00';
            $topic = "Week $week - Material Engagement";
            $qr_data = '';
            $stmt = $conn->prepare("INSERT INTO attendance_sessions (course_id, lecturer_id, session_date, start_time, topic, week_number, session_code, qr_code_data, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("iisssiss", $course_id, $lecturer_id, $session_date, $start_time, $topic, $week, $session_code, $qr_data);
            $stmt->execute();
            $auto_session_id = $conn->insert_id;
            $stmt->close();
        }

        // Get total content items for this week
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vle_weekly_content WHERE course_id = ? AND week_number = ?");
        $stmt->bind_param("ii", $course_id, $week);
        $stmt->execute();
        $total_content = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($total_content == 0) {
            $error = "No content found for Week $week in this course.";
        } else {
            // Get enrolled students and their content view count for this week
            $stmt = $conn->prepare("
                SELECT ve.student_id,
                    COUNT(DISTINCT vp.content_id) as viewed
                FROM vle_enrollments ve
                LEFT JOIN vle_progress vp ON vp.enrollment_id = ve.enrollment_id
                    AND vp.week_number = ?
                    AND vp.progress_type = 'content_viewed'
                    AND vp.content_id IS NOT NULL
                WHERE ve.course_id = ?
                GROUP BY ve.student_id
            ");
            $stmt->bind_param("ii", $week, $course_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $tracked = 0;
            $now = date('Y-m-d H:i:s');
            while ($row = $result->fetch_assoc()) {
                $pct = ($row['viewed'] / $total_content) * 100;
                // >=50% content viewed = present, >=25% = late, else absent
                if ($pct >= 50) {
                    $status = 'present';
                } elseif ($pct >= 25) {
                    $status = 'late';
                } else {
                    $status = 'absent';
                }
                $ins = $conn->prepare("INSERT INTO attendance_records (session_id, student_id, check_in_time, status, source) VALUES (?, ?, ?, ?, 'auto_material') ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'auto_material'");
                $ins->bind_param("isss", $auto_session_id, $row['student_id'], $now, $status);
                $ins->execute();
                $ins->close();
                $tracked++;
            }
            $stmt->close();
            $success = "Auto-tracked $tracked students for Week $week based on material engagement ($total_content items). &ge;50% = Present, &ge;25% = Late, &lt;25% = Absent.";
        }
        $selected_course = $course_id;
        $view = 'overview';
    }

    // --- CSV Export ---
    if (isset($_POST['export_csv'])) {
        $course_id = (int)$_POST['course_id'];
        // Get course info
        $stmt = $conn->prepare("SELECT course_code, course_name FROM vle_courses WHERE course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $cinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get all sessions
        $stmt = $conn->prepare("SELECT session_id, session_date, topic, week_number FROM attendance_sessions WHERE course_id = ? AND lecturer_id = ? ORDER BY session_date");
        $stmt->bind_param("ii", $course_id, $lecturer_id);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get enrolled students
        $stmt = $conn->prepare("SELECT s.student_id, s.full_name FROM vle_enrollments ve JOIN students s ON ve.student_id = s.student_id WHERE ve.course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Build attendance map
        $att_map = [];
        if (!empty($sessions)) {
            $s_ids = array_column($sessions, 'session_id');
            $placeholders = implode(',', array_fill(0, count($s_ids), '?'));
            $types = str_repeat('i', count($s_ids));
            $stmt = $conn->prepare("SELECT session_id, student_id, status FROM attendance_records WHERE session_id IN ($placeholders)");
            $stmt->bind_param($types, ...$s_ids);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $att_map[$row['student_id']][$row['session_id']] = $row['status'];
            }
            $stmt->close();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=attendance_' . ($cinfo['course_code'] ?? 'course') . '_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        // Header row
        $header = ['Student ID', 'Full Name'];
        foreach ($sessions as $s) {
            $label = $s['session_date'];
            if ($s['week_number']) $label .= " (Wk{$s['week_number']})";
            if ($s['topic']) $label .= " - {$s['topic']}";
            $header[] = $label;
        }
        $header[] = 'Attendance %';
        fputcsv($out, $header);

        foreach ($students as $stu) {
            $row = [$stu['student_id'], $stu['full_name']];
            $present_count = 0;
            foreach ($sessions as $s) {
                $st = $att_map[$stu['student_id']][$s['session_id']] ?? 'N/A';
                $row[] = ucfirst($st);
                if (in_array($st, ['present', 'late', 'auto_tracked'])) $present_count++;
            }
            $row[] = count($sessions) > 0 ? round(($present_count / count($sessions)) * 100, 1) . '%' : '0%';
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // Preserve selected course
    if (!$selected_course && isset($_POST['course_id'])) {
        $selected_course = (int)$_POST['course_id'];
    }
}

// ==============================
// Data for views
// ==============================
$course_data = null;
$sessions_list = [];
$enrolled_students = [];
$weekly_engagement = [];
$attendance_grid = [];
$course_stats = [];

if ($selected_course) {
    // Verify ownership
    $stmt = $conn->prepare("SELECT course_id, course_code, course_name FROM vle_courses WHERE course_id = ? AND lecturer_id = ? AND is_active = 1");
    $stmt->bind_param("ii", $selected_course, $lecturer_id);
    $stmt->execute();
    $course_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($course_data) {
        // Get enrolled students
        $stmt = $conn->prepare("SELECT s.student_id, s.full_name, s.year_of_study, ve.enrollment_id, ve.current_week FROM vle_enrollments ve JOIN students s ON ve.student_id = s.student_id WHERE ve.course_id = ? ORDER BY s.full_name");
        $stmt->bind_param("i", $selected_course);
        $stmt->execute();
        $enrolled_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get sessions
        $stmt = $conn->prepare("SELECT * FROM attendance_sessions WHERE course_id = ? AND lecturer_id = ? ORDER BY session_date DESC, start_time DESC");
        $stmt->bind_param("ii", $selected_course, $lecturer_id);
        $stmt->execute();
        $sessions_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Build attendance grid: student_id => session_id => status
        if (!empty($sessions_list)) {
            $s_ids = array_column($sessions_list, 'session_id');
            $placeholders = implode(',', array_fill(0, count($s_ids), '?'));
            $types = str_repeat('i', count($s_ids));
            $stmt = $conn->prepare("SELECT session_id, student_id, status, source, check_in_time FROM attendance_records WHERE session_id IN ($placeholders)");
            $stmt->bind_param($types, ...$s_ids);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $attendance_grid[$row['student_id']][$row['session_id']] = $row;
            }
            $stmt->close();
        }

        // Weekly engagement data from vle_progress
        $stmt = $conn->prepare("
            SELECT ve.student_id, vp.week_number, COUNT(DISTINCT vp.content_id) as items_viewed,
                (SELECT COUNT(*) FROM vle_weekly_content wc WHERE wc.course_id = ? AND wc.week_number = vp.week_number) as total_items
            FROM vle_enrollments ve
            JOIN vle_progress vp ON vp.enrollment_id = ve.enrollment_id AND vp.progress_type = 'content_viewed' AND vp.content_id IS NOT NULL
            WHERE ve.course_id = ?
            GROUP BY ve.student_id, vp.week_number
            ORDER BY vp.week_number
        ");
        $stmt->bind_param("ii", $selected_course, $selected_course);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $weekly_engagement[$row['student_id']][$row['week_number']] = $row;
        }
        $stmt->close();

        // Get total weeks of content
        $stmt = $conn->prepare("SELECT DISTINCT week_number FROM vle_weekly_content WHERE course_id = ? ORDER BY week_number");
        $stmt->bind_param("i", $selected_course);
        $stmt->execute();
        $content_weeks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Course stats
        $total_sessions = count($sessions_list);
        $total_students = count($enrolled_students);
        $avg_attendance = 0;
        if ($total_sessions > 0 && $total_students > 0) {
            $present_total = 0;
            foreach ($enrolled_students as $stu) {
                foreach ($sessions_list as $sess) {
                    $st = $attendance_grid[$stu['student_id']][$sess['session_id']]['status'] ?? 'absent';
                    if (in_array($st, ['present', 'late', 'auto_tracked'])) $present_total++;
                }
            }
            $avg_attendance = round(($present_total / ($total_sessions * $total_students)) * 100, 1);
        }
        $course_stats = [
            'total_sessions' => $total_sessions,
            'total_students' => $total_students,
            'avg_attendance' => $avg_attendance,
            'content_weeks' => count($content_weeks)
        ];
    }
}

$page_title = "Attendance Register";
$breadcrumbs = [['title' => 'Attendance Register']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Register - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <style>
        .att-badge { display: inline-block; width: 28px; height: 28px; border-radius: 50%; text-align: center; line-height: 28px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .att-present { background: #dcfce7; color: #166534; }
        .att-late { background: #fef9c3; color: #854d0e; }
        .att-absent { background: #fee2e2; color: #991b1b; }
        .att-excused { background: #dbeafe; color: #1e40af; }
        .att-auto { background: #f3e8ff; color: #6b21a8; }
        .att-na { background: #f3f4f6; color: #9ca3af; }
        .engagement-bar { height: 8px; border-radius: 4px; background: #e5e7eb; overflow: hidden; }
        .engagement-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
        .session-card { transition: transform 0.2s; }
        .session-card:hover { transform: translateY(-2px); }
        .stat-card { border-left: 4px solid; }
        .table-attendance th { font-size: 0.75rem; writing-mode: vertical-lr; text-align: center; min-width: 40px; padding: 8px 4px; }
        .table-attendance td { text-align: center; padding: 4px; vertical-align: middle; }
        @media (max-width: 768px) { .table-attendance th { writing-mode: horizontal-tb; font-size: 0.65rem; } }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="vle-page-title"><i class="bi bi-clipboard-data me-2"></i>Attendance Register</h2>
            <p class="text-muted mb-0">Track student attendance through live sessions and material engagement</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($course_data): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newSessionModal"><i class="bi bi-plus-circle me-1"></i>New Live Session</button>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#autoTrackModal"><i class="bi bi-robot me-1"></i>Auto-Track</button>
            <form method="post" class="d-inline">
                <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                <button type="submit" name="export_csv" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Export CSV</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Course Selector -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><i class="bi bi-book me-1"></i>Select Course</label>
                    <select name="course_id" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Choose a course --</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $selected_course == $c['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                <?php if ($course_data): ?>
                <div class="col-md-6">
                    <div class="btn-group w-100">
                        <a href="?course_id=<?= $selected_course ?>&view=overview" class="btn btn-<?= $view === 'overview' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-grid me-1"></i>Overview</a>
                        <a href="?course_id=<?= $selected_course ?>&view=sessions" class="btn btn-<?= $view === 'sessions' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-calendar-event me-1"></i>Sessions</a>
                        <a href="?course_id=<?= $selected_course ?>&view=grid" class="btn btn-<?= $view === 'grid' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-table me-1"></i>Full Grid</a>
                        <a href="?course_id=<?= $selected_course ?>&view=engagement" class="btn btn-<?= $view === 'engagement' ? 'primary' : 'outline-primary' ?>"><i class="bi bi-activity me-1"></i>Engagement</a>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (!$selected_course): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-clipboard-data display-1 d-block mb-3"></i>
        <h4>Select a course to view attendance</h4>
        <p>Choose from your assigned courses above to see the attendance register.</p>
    </div>
    <?php elseif (!$course_data): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Course not found or not assigned to you.</div>
    <?php else: ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color:#2563eb;">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-primary"><?= $course_stats['total_sessions'] ?></div>
                    <small class="text-muted">Total Sessions</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color:#059669;">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-success"><?= $course_stats['total_students'] ?></div>
                    <small class="text-muted">Enrolled Students</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color:#d97706;">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-warning"><?= $course_stats['avg_attendance'] ?>%</div>
                    <small class="text-muted">Avg Attendance</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card" style="border-left-color:#7c3aed;">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold" style="color:#7c3aed;"><?= $course_stats['content_weeks'] ?></div>
                    <small class="text-muted">Content Weeks</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ======= OVERVIEW VIEW ======= -->
    <?php if ($view === 'overview'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Student Attendance Summary</h5>
            <span class="badge bg-primary"><?= count($enrolled_students) ?> students</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($enrolled_students)): ?>
            <div class="text-center py-4 text-muted">No students enrolled in this course.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>ID</th>
                            <th>Year</th>
                            <th>Sessions Attended</th>
                            <th>Attendance %</th>
                            <th>Material Engagement</th>
                            <th>Overall Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolled_students as $stu):
                            $total_s = count($sessions_list);
                            $attended = 0;
                            foreach ($sessions_list as $sess) {
                                $st = $attendance_grid[$stu['student_id']][$sess['session_id']]['status'] ?? '';
                                if (in_array($st, ['present', 'late', 'auto_tracked'])) $attended++;
                            }
                            $att_pct = $total_s > 0 ? round(($attended / $total_s) * 100, 1) : 0;

                            // Material engagement
                            $total_engagement = 0;
                            $engage_count = 0;
                            if (!empty($weekly_engagement[$stu['student_id']])) {
                                foreach ($weekly_engagement[$stu['student_id']] as $we) {
                                    if ($we['total_items'] > 0) {
                                        $total_engagement += ($we['items_viewed'] / $we['total_items']) * 100;
                                        $engage_count++;
                                    }
                                }
                            }
                            $engage_pct = $engage_count > 0 ? round($total_engagement / $engage_count, 1) : 0;
                            $overall = round(($att_pct * 0.6) + ($engage_pct * 0.4), 1);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($stu['full_name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($stu['student_id']) ?></code></td>
                            <td><?= htmlspecialchars($stu['year_of_study'] ?? '-') ?></td>
                            <td><?= $attended ?> / <?= $total_s ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="engagement-bar flex-grow-1" style="width:80px;">
                                        <div class="engagement-fill bg-<?= $att_pct >= 75 ? 'success' : ($att_pct >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $att_pct ?>%"></div>
                                    </div>
                                    <span class="fw-bold text-<?= $att_pct >= 75 ? 'success' : ($att_pct >= 50 ? 'warning' : 'danger') ?>"><?= $att_pct ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="engagement-bar flex-grow-1" style="width:80px;">
                                        <div class="engagement-fill" style="width:<?= $engage_pct ?>%; background:#7c3aed;"></div>
                                    </div>
                                    <span class="fw-bold" style="color:#7c3aed;"><?= $engage_pct ?>%</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($overall >= 75): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Good</span>
                                <?php elseif ($overall >= 50): ?>
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

    <!-- Legend -->
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

    <!-- ======= SESSIONS VIEW ======= -->
    <?php elseif ($view === 'sessions'): ?>

    <!-- Active Sessions -->
    <?php
    $active_sessions = array_filter($sessions_list, fn($s) => $s['is_active']);
    $past_sessions = array_filter($sessions_list, fn($s) => !$s['is_active']);
    ?>
    <?php if (!empty($active_sessions)): ?>
    <h5 class="mb-3"><i class="bi bi-broadcast me-2 text-success"></i>Active Sessions</h5>
    <div class="row g-3 mb-4">
        <?php foreach ($active_sessions as $sess): ?>
        <div class="col-md-6">
            <div class="card border-success shadow-sm session-card">
                <div class="card-header bg-success bg-opacity-10 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-success me-2">LIVE</span>
                        <strong><?= htmlspecialchars($sess['topic'] ?: 'Session') ?></strong>
                    </div>
                    <small class="text-muted"><?= date('M d, Y', strtotime($sess['session_date'])) ?></small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <p class="mb-1"><small class="text-muted">Session Code</small></p>
                            <h4 class="font-monospace text-success"><?= htmlspecialchars($sess['session_code']) ?></h4>
                        </div>
                        <div class="col-6 text-center">
                            <canvas id="qr_<?= $sess['session_id'] ?>" width="120" height="120"></canvas>
                            <script>new QRious({element:document.getElementById('qr_<?= $sess['session_id'] ?>'),value:'<?= addslashes($sess['qr_code_data']) ?>',size:120});</script>
                        </div>
                    </div>
                    <div class="mt-3">
                        <?php
                        $present_count = 0;
                        foreach ($enrolled_students as $stu) {
                            $st = $attendance_grid[$stu['student_id']][$sess['session_id']]['status'] ?? '';
                            if (in_array($st, ['present', 'late'])) $present_count++;
                        }
                        ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="bi bi-people me-1"></i><?= $present_count ?> / <?= count($enrolled_students) ?> present</span>
                            <span><i class="bi bi-clock me-1"></i>Started: <?= date('H:i', strtotime($sess['start_time'])) ?></span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="?course_id=<?= $selected_course ?>&view=session_detail&session_id=<?= $sess['session_id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-list-check me-1"></i>Manage</a>
                            <form method="post" class="flex-grow-1">
                                <input type="hidden" name="session_id" value="<?= $sess['session_id'] ?>">
                                <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                                <button type="submit" name="close_session" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Close this session? Students will no longer be able to check in.')"><i class="bi bi-stop-circle me-1"></i>Close</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Session History -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Session History</h5>
            <span class="badge bg-secondary"><?= count($sessions_list) ?> sessions</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($sessions_list)): ?>
            <div class="text-center py-4 text-muted"><i class="bi bi-calendar-x display-4 d-block mb-2"></i>No sessions yet. Create a new live session or auto-track from materials.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Topic</th>
                            <th>Week</th>
                            <th>Time</th>
                            <th>Code</th>
                            <th>Present</th>
                            <th>Late</th>
                            <th>Absent</th>
                            <th>Rate</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions_list as $sess):
                            $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'auto_tracked' => 0];
                            foreach ($enrolled_students as $stu) {
                                $st = $attendance_grid[$stu['student_id']][$sess['session_id']]['status'] ?? 'absent';
                                if (isset($counts[$st])) $counts[$st]++;
                                else $counts['absent']++;
                            }
                            $present_rate = count($enrolled_students) > 0 ? round((($counts['present'] + $counts['late'] + $counts['auto_tracked']) / count($enrolled_students)) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($sess['session_date'])) ?></td>
                            <td><?= htmlspecialchars($sess['topic'] ?: '-') ?></td>
                            <td><?= $sess['week_number'] ? 'Wk ' . $sess['week_number'] : '-' ?></td>
                            <td><?= date('H:i', strtotime($sess['start_time'])) ?><?= $sess['end_time'] ? ' - ' . date('H:i', strtotime($sess['end_time'])) : '' ?></td>
                            <td><code><?= htmlspecialchars($sess['session_code']) ?></code></td>
                            <td><span class="badge bg-success"><?= $counts['present'] + $counts['auto_tracked'] ?></span></td>
                            <td><span class="badge bg-warning text-dark"><?= $counts['late'] ?></span></td>
                            <td><span class="badge bg-danger"><?= $counts['absent'] ?></span></td>
                            <td><strong class="text-<?= $present_rate >= 75 ? 'success' : ($present_rate >= 50 ? 'warning' : 'danger') ?>"><?= $present_rate ?>%</strong></td>
                            <td>
                                <?php if ($sess['is_active']): ?>
                                <span class="badge bg-success"><i class="bi bi-broadcast"></i> Live</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?course_id=<?= $selected_course ?>&view=session_detail&session_id=<?= $sess['session_id'] ?>" class="btn btn-sm btn-outline-primary" title="View/Edit"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======= SESSION DETAIL VIEW ======= -->
    <?php elseif ($view === 'session_detail' && isset($_GET['session_id'])):
        $detail_session_id = (int)$_GET['session_id'];
        $detail_session = null;
        foreach ($sessions_list as $s) {
            if ($s['session_id'] == $detail_session_id) { $detail_session = $s; break; }
        }
        if ($detail_session):
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1"><?= htmlspecialchars($detail_session['topic'] ?: 'Session') ?></h5>
                    <small class="text-muted"><?= date('M d, Y', strtotime($detail_session['session_date'])) ?> | Code: <code><?= htmlspecialchars($detail_session['session_code']) ?></code>
                    <?= $detail_session['week_number'] ? ' | Week ' . $detail_session['week_number'] : '' ?></small>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($detail_session['is_active']): ?>
                    <form method="post">
                        <input type="hidden" name="session_id" value="<?= $detail_session_id ?>">
                        <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                        <button type="submit" name="close_session" class="btn btn-sm btn-outline-danger" onclick="return confirm('Close session?')"><i class="bi bi-stop-circle me-1"></i>Close Session</button>
                    </form>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="session_id" value="<?= $detail_session_id ?>">
                        <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                        <button type="submit" name="mark_all_absent" class="btn btn-sm btn-outline-warning" onclick="return confirm('Mark all unmarked students as absent?')"><i class="bi bi-x-circle me-1"></i>Mark Remaining Absent</button>
                    </form>
                    <a href="?course_id=<?= $selected_course ?>&view=sessions" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th>Check-in</th>
                            <th>Source</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 0; foreach ($enrolled_students as $stu):
                            $idx++;
                            $rec = $attendance_grid[$stu['student_id']][$detail_session_id] ?? null;
                            $current_status = $rec['status'] ?? 'absent';
                            $source = $rec['source'] ?? '-';
                            $check_in = $rec['check_in_time'] ?? '-';
                        ?>
                        <tr>
                            <td><?= $idx ?></td>
                            <td><strong><?= htmlspecialchars($stu['full_name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($stu['student_id']) ?></code></td>
                            <td><?= htmlspecialchars($stu['year_of_study'] ?? '-') ?></td>
                            <td>
                                <?php
                                $status_classes = ['present' => 'bg-success', 'late' => 'bg-warning text-dark', 'absent' => 'bg-danger', 'excused' => 'bg-info', 'auto_tracked' => 'bg-purple'];
                                $badge_class = $status_classes[$current_status] ?? 'bg-secondary';
                                ?>
                                <span class="badge <?= $badge_class ?>"><?= ucfirst(str_replace('_', ' ', $current_status)) ?></span>
                            </td>
                            <td><small><?= $check_in !== '-' ? date('H:i:s', strtotime($check_in)) : '-' ?></small></td>
                            <td><small class="text-muted"><?= ucfirst(str_replace('_', ' ', $source)) ?></small></td>
                            <td>
                                <form method="post" class="d-inline-flex gap-1">
                                    <input type="hidden" name="session_id" value="<?= $detail_session_id ?>">
                                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($stu['student_id']) ?>">
                                    <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                                    <input type="hidden" name="mark_attendance" value="1">
                                    <button type="submit" name="new_status" value="present" class="btn btn-sm <?= $current_status === 'present' ? 'btn-success' : 'btn-outline-success' ?>" title="Present"><i class="bi bi-check"></i></button>
                                    <button type="submit" name="new_status" value="late" class="btn btn-sm <?= $current_status === 'late' ? 'btn-warning' : 'btn-outline-warning' ?>" title="Late"><i class="bi bi-clock"></i></button>
                                    <button type="submit" name="new_status" value="absent" class="btn btn-sm <?= $current_status === 'absent' ? 'btn-danger' : 'btn-outline-danger' ?>" title="Absent"><i class="bi bi-x"></i></button>
                                    <button type="submit" name="new_status" value="excused" class="btn btn-sm <?= $current_status === 'excused' ? 'btn-info' : 'btn-outline-info' ?>" title="Excused"><i class="bi bi-shield-check"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning">Session not found.</div>
    <?php endif; ?>

    <!-- ======= FULL GRID VIEW ======= -->
    <?php elseif ($view === 'grid'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-table me-2"></i>Attendance Grid - <?= htmlspecialchars($course_data['course_code']) ?></h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($sessions_list) || empty($enrolled_students)): ?>
            <div class="text-center py-4 text-muted">No data to display. Create sessions and enroll students first.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-attendance mb-0">
                    <thead>
                        <tr>
                            <th style="writing-mode:horizontal-tb; min-width:180px; position:sticky; left:0; background:#fff; z-index:1;">Student</th>
                            <?php foreach ($sessions_list as $sess): ?>
                            <th title="<?= htmlspecialchars($sess['topic'] ?: $sess['session_date']) ?>">
                                <?= date('M d', strtotime($sess['session_date'])) ?>
                                <?= $sess['week_number'] ? '<br>W' . $sess['week_number'] : '' ?>
                            </th>
                            <?php endforeach; ?>
                            <th style="writing-mode:horizontal-tb;">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolled_students as $stu):
                            $att_count = 0;
                        ?>
                        <tr>
                            <td style="text-align:left; white-space:nowrap; position:sticky; left:0; background:#fff; z-index:1;">
                                <small><strong><?= htmlspecialchars($stu['full_name']) ?></strong></small>
                            </td>
                            <?php foreach ($sessions_list as $sess):
                                $st = $attendance_grid[$stu['student_id']][$sess['session_id']]['status'] ?? '';
                                $source = $attendance_grid[$stu['student_id']][$sess['session_id']]['source'] ?? '';
                                if (in_array($st, ['present', 'late', 'auto_tracked'])) $att_count++;
                                $badge_map = [
                                    'present' => ['P', 'att-present'],
                                    'late' => ['L', 'att-late'],
                                    'absent' => ['A', 'att-absent'],
                                    'excused' => ['E', 'att-excused'],
                                    'auto_tracked' => ['AT', 'att-auto'],
                                ];
                                $info = $badge_map[$st] ?? ['-', 'att-na'];
                            ?>
                            <td>
                                <span class="att-badge <?= $info[1] ?>" title="<?= ucfirst($st ?: 'Not marked') ?> (<?= $source ?: 'N/A' ?>)"><?= $info[0] ?></span>
                            </td>
                            <?php endforeach; ?>
                            <td>
                                <?php $pct = count($sessions_list) > 0 ? round(($att_count / count($sessions_list)) * 100) : 0; ?>
                                <strong class="text-<?= $pct >= 75 ? 'success' : ($pct >= 50 ? 'warning' : 'danger') ?>"><?= $pct ?>%</strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3">
                <small class="text-muted">
                    <span class="att-badge att-present me-1">P</span>Present
                    <span class="att-badge att-late ms-2 me-1">L</span>Late
                    <span class="att-badge att-absent ms-2 me-1">A</span>Absent
                    <span class="att-badge att-excused ms-2 me-1">E</span>Excused
                    <span class="att-badge att-auto ms-2 me-1">AT</span>Auto-tracked
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======= ENGAGEMENT VIEW ======= -->
    <?php elseif ($view === 'engagement'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Weekly Material Engagement</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($enrolled_students) || empty($content_weeks)): ?>
            <div class="text-center py-4 text-muted">No content or students to analyze.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:180px;">Student</th>
                            <?php foreach ($content_weeks as $cw): ?>
                            <th class="text-center">Week <?= $cw['week_number'] ?></th>
                            <?php endforeach; ?>
                            <th class="text-center">Avg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolled_students as $stu):
                            $total_pct = 0;
                            $week_count = 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($stu['full_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($stu['student_id']) ?></small></td>
                            <?php foreach ($content_weeks as $cw):
                                $wk = $cw['week_number'];
                                $we = $weekly_engagement[$stu['student_id']][$wk] ?? null;
                                $viewed = $we ? (int)$we['items_viewed'] : 0;
                                $total = $we ? (int)$we['total_items'] : 0;
                                // Fallback: count total items for this week directly
                                if ($total == 0) {
                                    $cnt_stmt = $conn->prepare("SELECT COUNT(*) as c FROM vle_weekly_content WHERE course_id = ? AND week_number = ?");
                                    $cnt_stmt->bind_param("ii", $selected_course, $wk);
                                    $cnt_stmt->execute();
                                    $total = $cnt_stmt->get_result()->fetch_assoc()['c'];
                                    $cnt_stmt->close();
                                }
                                $pct = $total > 0 ? round(($viewed / $total) * 100) : 0;
                                $total_pct += $pct;
                                $week_count++;
                                $color = $pct >= 75 ? '#22c55e' : ($pct >= 50 ? '#eab308' : ($pct > 0 ? '#f97316' : '#ef4444'));
                            ?>
                            <td class="text-center">
                                <div class="engagement-bar mx-auto" style="width:60px;">
                                    <div class="engagement-fill" style="width:<?= $pct ?>%; background:<?= $color ?>;"></div>
                                </div>
                                <small class="d-block mt-1" style="color:<?= $color ?>;"><?= $viewed ?>/<?= $total ?> (<?= $pct ?>%)</small>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php $avg = $week_count > 0 ? round($total_pct / $week_count) : 0; ?>
                                <strong class="text-<?= $avg >= 75 ? 'success' : ($avg >= 50 ? 'warning' : 'danger') ?>"><?= $avg ?>%</strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end course_data check ?>
</div>

<!-- New Session Modal -->
<?php if ($course_data): ?>
<div class="modal fade" id="newSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create Live Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Topic / Description</label>
                        <input type="text" class="form-control" name="topic" placeholder="e.g. Introduction to Database Systems">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Week Number</label>
                            <input type="number" class="form-control" name="week_number" min="1" max="52">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="session_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="start_time" value="<?= date('H:i') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_session" class="btn btn-success"><i class="bi bi-broadcast me-1"></i>Start Session</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Auto-Track Modal -->
<div class="modal fade" id="autoTrackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header text-white" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                <h5 class="modal-title"><i class="bi bi-robot me-2"></i>Auto-Track from Materials</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>This will automatically generate attendance records based on student engagement with weekly course materials:
                        <ul class="mb-0 mt-2">
                            <li><strong>&ge;50%</strong> materials viewed = <span class="badge bg-success">Present</span></li>
                            <li><strong>&ge;25%</strong> materials viewed = <span class="badge bg-warning text-dark">Late</span></li>
                            <li><strong>&lt;25%</strong> materials viewed = <span class="badge bg-danger">Absent</span></li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Week Number <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="week_number" min="1" max="52" required placeholder="Enter week to auto-track">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="auto_track" class="btn text-white" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);"><i class="bi bi-robot me-1"></i>Auto-Track Now</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
