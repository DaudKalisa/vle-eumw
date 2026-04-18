<?php
/**
 * Examination Officer - Exam Timetable Management
 * Auto-generate and manage examination timetables for Year 1-4
 * Exam Manager and Officer are responsible for examination timetables
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Ensure exam_timetable table exists
$conn->query("CREATE TABLE IF NOT EXISTS exam_timetable (
    exam_timetable_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(100) DEFAULT '',
    exam_type ENUM('mid_term','final','supplementary','deferred') DEFAULT 'final',
    semester VARCHAR(20) DEFAULT 'One',
    academic_year VARCHAR(20) DEFAULT '',
    year_of_study INT DEFAULT 1,
    program_type ENUM('weekday','weekend','all') DEFAULT 'all',
    duration_minutes INT DEFAULT 180,
    invigilator_id INT DEFAULT NULL,
    status ENUM('draft','published','cancelled') DEFAULT 'draft',
    created_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (course_id), INDEX (exam_date), INDEX (semester, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$success_message = '';
$error_message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            $course_id = (int)$_POST['course_id'];
            $exam_date = $_POST['exam_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $venue = trim($_POST['venue']);
            $exam_type = $_POST['exam_type'];
            $semester = $_POST['semester'];
            $academic_year = trim($_POST['academic_year']);
            $year_of_study = (int)($_POST['year_of_study'] ?? 1);
            $duration = (int)($_POST['duration_minutes'] ?? 180);

            // Conflict check: same venue, same date, overlapping times
            $conflict = $conn->prepare("SELECT et.*, c.course_name FROM exam_timetable et JOIN vle_courses c ON et.course_id = c.course_id
                WHERE et.exam_date = ? AND et.venue = ? AND et.status != 'cancelled'
                AND ((et.start_time <= ? AND et.end_time > ?) OR (et.start_time < ? AND et.end_time >= ?))");
            $conflict->bind_param("ssssss", $exam_date, $venue, $start_time, $start_time, $end_time, $end_time);
            $conflict->execute();
            $conflicts = $conflict->get_result();

            if ($conflicts->num_rows > 0) {
                $c = $conflicts->fetch_assoc();
                $error_message = "Conflict! Venue '$venue' is booked for '{$c['course_name']}' on $exam_date during that time.";
            } else {
                $stmt = $conn->prepare("INSERT INTO exam_timetable (course_id, exam_date, start_time, end_time, venue, exam_type, semester, academic_year, year_of_study, duration_minutes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
                $uid = $user['user_id'];
                $stmt->bind_param("isssssssiii", $course_id, $exam_date, $start_time, $end_time, $venue, $exam_type, $semester, $academic_year, $year_of_study, $duration, $uid);
                if ($stmt->execute()) {
                    $success_message = "Exam entry added successfully.";
                } else {
                    $error_message = "Failed to add: " . $conn->error;
                }
            }
            break;

        case 'delete':
            $id = (int)$_POST['exam_timetable_id'];
            $stmt = $conn->prepare("DELETE FROM exam_timetable WHERE exam_timetable_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success_message = "Exam entry deleted.";
            break;

        case 'publish':
            $id = (int)$_POST['exam_timetable_id'];
            $stmt = $conn->prepare("UPDATE exam_timetable SET status = 'published', approved_by = ?, approved_at = NOW() WHERE exam_timetable_id = ?");
            $uid = $user['user_id'];
            $stmt->bind_param("ii", $uid, $id);
            $stmt->execute();
            $success_message = "Exam entry published.";
            break;

        case 'publish_all':
            $sem = $_POST['publish_semester'] ?? '';
            $ay = $_POST['publish_academic_year'] ?? '';
            if ($sem && $ay) {
                $stmt = $conn->prepare("UPDATE exam_timetable SET status = 'published', approved_by = ?, approved_at = NOW() WHERE semester = ? AND academic_year = ? AND status = 'draft'");
                $uid = $user['user_id'];
                $stmt->bind_param("iss", $uid, $sem, $ay);
                $stmt->execute();
                $success_message = "Published " . $stmt->affected_rows . " exam entries.";
            }
            break;

        case 'cancel':
            $id = (int)$_POST['exam_timetable_id'];
            $stmt = $conn->prepare("UPDATE exam_timetable SET status = 'cancelled' WHERE exam_timetable_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success_message = "Exam entry cancelled.";
            break;

        case 'clear_generated':
            $sem = $_POST['clear_semester'] ?? '';
            $ay = $_POST['clear_academic_year'] ?? '';
            $et = $_POST['clear_exam_type'] ?? '';
            if ($sem && $ay) {
                $sql = "DELETE FROM exam_timetable WHERE semester = ? AND academic_year = ?";
                $params = [$sem, $ay];
                $types = "ss";
                if ($et) { $sql .= " AND exam_type = ?"; $params[] = $et; $types .= "s"; }
                $del = $conn->prepare($sql);
                $del->bind_param($types, ...$params);
                $del->execute();
                $success_message = "Cleared " . $del->affected_rows . " exam entries.";
            }
            break;
    }
}

// Filters
$filter_semester = $_GET['semester'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_exam_type = $_GET['exam_type'] ?? '';
$filter_status = $_GET['status'] ?? '';

$where = ["1=1"];
$params = [];
$types = "";

if ($filter_semester) { $where[] = "et.semester = ?"; $params[] = $filter_semester; $types .= "s"; }
if ($filter_year) { $where[] = "et.year_of_study = ?"; $params[] = (int)$filter_year; $types .= "i"; }
if ($filter_exam_type) { $where[] = "et.exam_type = ?"; $params[] = $filter_exam_type; $types .= "s"; }
if ($filter_status) { $where[] = "et.status = ?"; $params[] = $filter_status; $types .= "s"; }

$where_sql = implode(" AND ", $where);
$sql = "SELECT et.*, c.course_code, c.course_name, l.full_name as lecturer_name
        FROM exam_timetable et
        JOIN vle_courses c ON et.course_id = c.course_id
        LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
        WHERE $where_sql
        ORDER BY et.year_of_study, et.exam_date, et.start_time";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$exams = [];
if ($result) { while ($row = $result->fetch_assoc()) $exams[] = $row; }

// Stats
$total = count($exams);
$draft_count = count(array_filter($exams, fn($e) => $e['status'] === 'draft'));
$published_count = count(array_filter($exams, fn($e) => $e['status'] === 'published'));
$year_stats = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($exams as $e) {
    $y = (int)($e['year_of_study'] ?? 0);
    if (isset($year_stats[$y])) $year_stats[$y]++;
}

// Courses dropdown
$courses = [];
$cr = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses ORDER BY course_code");
if ($cr) { while ($row = $cr->fetch_assoc()) $courses[] = $row; }

// Venues
$venues = [];
try {
    $vr = $conn->query("SELECT room_name, building, capacity FROM timetable_rooms WHERE is_active = 1 ORDER BY room_type, room_name");
    if ($vr) { while ($row = $vr->fetch_assoc()) $venues[] = $row; }
} catch (Exception $e) {
    // timetable_rooms table may not exist yet - run setup_timetable_system.php
}

// Academic year (dean_settings is a key-value table)
$current_ay = date('Y') . '/' . (date('Y') + 1);
try {
    $ay_result = $conn->query("SELECT setting_value FROM dean_settings WHERE setting_key = 'current_academic_year' LIMIT 1");
    if ($ay_result && $row = $ay_result->fetch_assoc()) $current_ay = $row['setting_value'];
} catch (Exception $e) {
    // dean_settings table may not exist
}

$page_title = 'Exam Timetable';
$exam_types = ['final' => 'Final', 'mid_term' => 'Mid-Term', 'supplementary' => 'Supplementary', 'deferred' => 'Deferred'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Timetable - Examination Officer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .time-slot { font-family: monospace; font-size: 0.85rem; }
        .stat-card { border: none; border-radius: 12px; transition: transform 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .year-group-header { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; }
        .status-draft { border-left: 4px solid #ffc107; }
        .status-published { border-left: 4px solid #198754; }
        .status-cancelled { border-left: 4px solid #dc3545; opacity: 0.6; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-journal-bookmark me-2"></i>Examination Timetable</h1>
                <p class="text-muted mb-0">Auto-generate and manage exam schedules for Year 1-4</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#generateModal">
                    <i class="bi bi-lightning me-1"></i>Auto-Generate
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Exam
                </button>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success_message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error_message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md col-6">
                <div class="card stat-card bg-primary text-white" onclick="filterStatus('')">
                    <div class="card-body py-3 text-center">
                        <div class="h3 mb-0"><?= $total ?></div>
                        <small>Total Exams</small>
                    </div>
                </div>
            </div>
            <div class="col-md col-6">
                <div class="card stat-card text-dark" style="background: #fff3cd;" onclick="filterStatus('draft')">
                    <div class="card-body py-3 text-center">
                        <div class="h3 mb-0"><?= $draft_count ?></div>
                        <small>Draft</small>
                    </div>
                </div>
            </div>
            <div class="col-md col-6">
                <div class="card stat-card text-white" style="background: #198754;" onclick="filterStatus('published')">
                    <div class="card-body py-3 text-center">
                        <div class="h3 mb-0"><?= $published_count ?></div>
                        <small>Published</small>
                    </div>
                </div>
            </div>
            <?php 
            $year_colors = [1 => '#0d6efd', 2 => '#198754', 3 => '#fd7e14', 4 => '#6f42c1'];
            foreach ($year_stats as $yr => $cnt): ?>
            <div class="col-md col-6">
                <div class="card stat-card text-white" style="background: <?= $year_colors[$yr] ?>;" onclick="filterYear(<?= $yr ?>)">
                    <div class="card-body py-3 text-center">
                        <div class="h3 mb-0"><?= $cnt ?></div>
                        <small>Year <?= $yr ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Filters & Publish All -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end" id="filterForm">
                    <div class="col-md-2">
                        <label class="form-label small">Semester</label>
                        <select name="semester" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="One" <?= $filter_semester === 'One' ? 'selected' : '' ?>>Semester One</option>
                            <option value="Two" <?= $filter_semester === 'Two' ? 'selected' : '' ?>>Semester Two</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Year</label>
                        <select name="year" class="form-select form-select-sm" id="yearFilter">
                            <option value="">All</option>
                            <option value="1" <?= $filter_year === '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year === '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year === '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year === '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Exam Type</label>
                        <select name="exam_type" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($exam_types as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $filter_exam_type === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm" id="statusFilter">
                            <option value="">All</option>
                            <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="manage_exam_timetable.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="col-md-2 text-end">
                        <?php if ($draft_count > 0): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Publish all <?= $draft_count ?> draft entries?')">
                            <input type="hidden" name="action" value="publish_all">
                            <input type="hidden" name="publish_semester" value="<?= htmlspecialchars($filter_semester ?: 'One') ?>">
                            <input type="hidden" name="publish_academic_year" value="<?= htmlspecialchars($current_ay) ?>">
                            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-all me-1"></i>Publish All Draft</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Exam Timetable grouped by year -->
        <?php
        $grouped = [];
        foreach ($exams as $e) {
            $yr = (int)($e['year_of_study'] ?? 0);
            $grouped[$yr][] = $e;
        }
        ksort($grouped);
        
        if (empty($exams)): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-journal-x display-4 d-block mb-3"></i>
                <h5>No exam timetable entries found</h5>
                <p>Use <strong>Auto-Generate</strong> to create an exam timetable or add entries manually.</p>
            </div>
        </div>
        <?php else:
        foreach ($grouped as $yr => $entries): ?>
        <div class="card mb-4">
            <div class="card-header year-group-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <span class="badge me-2" style="background: <?= $year_colors[$yr] ?? '#6c757d' ?>;">Year <?= $yr ?></span>
                    <?= count($entries) ?> exams
                </h6>
                <form method="POST" class="d-inline" onsubmit="return confirm('Clear all exam entries for Year <?= $yr ?>?')">
                    <input type="hidden" name="action" value="clear_generated">
                    <input type="hidden" name="clear_semester" value="<?= htmlspecialchars($filter_semester ?: 'One') ?>">
                    <input type="hidden" name="clear_academic_year" value="<?= htmlspecialchars($current_ay) ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Clear</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Course</th>
                                <th>Lecturer</th>
                                <th>Venue</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $e): ?>
                            <tr class="status-<?= $e['status'] ?>">
                                <td><strong><?= date('D, M j', strtotime($e['exam_date'])) ?></strong><div class="small text-muted"><?= $e['exam_date'] ?></div></td>
                                <td class="time-slot"><?= date('H:i', strtotime($e['start_time'])) ?> - <?= date('H:i', strtotime($e['end_time'])) ?></td>
                                <td>
                                    <code><?= htmlspecialchars($e['course_code']) ?></code>
                                    <div class="small text-muted"><?= htmlspecialchars($e['course_name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($e['lecturer_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($e['venue'] ?: '-') ?></td>
                                <td><span class="badge bg-secondary"><?= $exam_types[$e['exam_type']] ?? ucfirst($e['exam_type']) ?></span></td>
                                <td><?= $e['duration_minutes'] ?> min</td>
                                <td>
                                    <?php
                                    $status_colors = ['draft' => 'warning', 'published' => 'success', 'cancelled' => 'danger'];
                                    ?>
                                    <span class="badge bg-<?= $status_colors[$e['status']] ?? 'secondary' ?>"><?= ucfirst($e['status']) ?></span>
                                </td>
                                <td>
                                    <?php if ($e['status'] === 'draft'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="publish">
                                        <input type="hidden" name="exam_timetable_id" value="<?= $e['exam_timetable_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Publish"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($e['status'] !== 'cancelled'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="exam_timetable_id" value="<?= $e['exam_timetable_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Cancel"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this exam entry?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="exam_timetable_id" value="<?= $e['exam_timetable_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach;
        endif; ?>
    </div>
    
    <!-- Auto-Generate Exam Modal -->
    <div class="modal fade" id="generateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-lightning me-2"></i>Auto-Generate Exam Timetable</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Spreads exams across the exam period: 2 sessions/day (AM 08:00, PM 14:00). Max 1 exam per day per year group. Weekdays only.
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Semester</label>
                            <select id="genSemester" class="form-select">
                                <option value="One">Semester One</option>
                                <option value="Two">Semester Two</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Academic Year</label>
                            <input type="text" id="genAcademicYear" class="form-control" value="<?= htmlspecialchars($current_ay) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam Type</label>
                            <select id="genExamType" class="form-select">
                                <option value="final">Final</option>
                                <option value="mid_term">Mid-Term</option>
                                <option value="supplementary">Supplementary</option>
                                <option value="deferred">Deferred</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <select id="genDuration" class="form-select">
                                <option value="120">120 min (2 hours)</option>
                                <option value="180" selected>180 min (3 hours)</option>
                                <option value="240">240 min (4 hours)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam Start Date</label>
                            <input type="date" id="genStartDate" class="form-control">
                            <small class="text-muted">Leave blank to auto-detect from academic calendar or default to +2 weeks</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam End Date</label>
                            <input type="date" id="genEndDate" class="form-control">
                            <small class="text-muted">Leave blank for 3 weeks after start</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Year Levels</label>
                        <div class="d-flex gap-3">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="<?= $i ?>" id="genY<?= $i ?>" checked>
                                <label class="form-check-label" for="genY<?= $i ?>">Year <?= $i ?></label>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="genClearExisting">
                        <label class="form-check-label text-danger" for="genClearExisting">Clear existing entries for this exam type/semester before generating</label>
                    </div>
                    <div id="genResult" class="d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="btnGenerate" onclick="generateExamTimetable()">
                        <i class="bi bi-lightning me-1"></i>Generate Exam Timetable
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Manual Entry Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Exam Entry</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select name="course_id" class="form-select" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['course_id'] ?>"><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Exam Date <span class="text-danger">*</span></label>
                                <input type="date" name="exam_date" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Start Time <span class="text-danger">*</span></label>
                                <input type="time" name="start_time" class="form-control" value="08:00" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">End Time <span class="text-danger">*</span></label>
                                <input type="time" name="end_time" class="form-control" value="11:00" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Venue</label>
                                <select name="venue" class="form-select">
                                    <option value="">Select Venue</option>
                                    <?php foreach ($venues as $v): ?>
                                    <option value="<?= htmlspecialchars($v['room_name']) ?>"><?= htmlspecialchars($v['room_name'] . ' (' . $v['building'] . ', Cap: ' . $v['capacity'] . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Exam Type</label>
                                <select name="exam_type" class="form-select">
                                    <?php foreach ($exam_types as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Year of Study</label>
                                <select name="year_of_study" class="form-select">
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Duration (min)</label>
                                <input type="number" name="duration_minutes" class="form-control" value="180" min="30" max="480">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Semester</label>
                                <select name="semester" class="form-select">
                                    <option value="One">One</option>
                                    <option value="Two">Two</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($current_ay) ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Exam Entry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function filterYear(yr) {
        document.getElementById('yearFilter').value = yr;
        document.getElementById('filterForm').submit();
    }
    function filterStatus(st) {
        document.getElementById('statusFilter').value = st;
        document.getElementById('filterForm').submit();
    }
    
    function generateExamTimetable() {
        const btn = document.getElementById('btnGenerate');
        const resultDiv = document.getElementById('genResult');
        const yearLevels = [];
        for (let i = 1; i <= 4; i++) {
            if (document.getElementById('genY' + i).checked) yearLevels.push(i);
        }
        if (!yearLevels.length) { resultDiv.className = 'alert alert-warning'; resultDiv.textContent = 'Select at least one year level.'; return; }
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
        resultDiv.className = 'alert alert-info';
        resultDiv.textContent = 'Generating exam timetable...';
        
        fetch('../api/generate_exam_timetable.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                semester: document.getElementById('genSemester').value,
                academic_year: document.getElementById('genAcademicYear').value,
                exam_type: document.getElementById('genExamType').value,
                year_levels: yearLevels,
                duration_minutes: parseInt(document.getElementById('genDuration').value),
                exam_start_date: document.getElementById('genStartDate').value,
                exam_end_date: document.getElementById('genEndDate').value,
                clear_existing: document.getElementById('genClearExisting').checked
            })
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning me-1"></i>Generate Exam Timetable';
            if (data.success) {
                resultDiv.className = 'alert alert-success';
                let msg = '<strong>Success!</strong> ' + data.message;
                if (data.exam_start) msg += '<br><small>Period: ' + data.exam_start + ' to ' + data.exam_end + '</small>';
                if (data.unassigned && data.unassigned.length) msg += '<br><small class="text-warning">Unassigned: ' + data.unassigned.join(', ') + '</small>';
                resultDiv.innerHTML = msg + '<br><small>Reloading...</small>';
                setTimeout(() => location.reload(), 1500);
            } else {
                resultDiv.className = 'alert alert-danger';
                resultDiv.textContent = data.error || 'Failed';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning me-1"></i>Generate Exam Timetable';
            resultDiv.className = 'alert alert-danger';
            resultDiv.textContent = 'Error: ' + err.message;
        });
    }
    </script>
</body>
</html>
