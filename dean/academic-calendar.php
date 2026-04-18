<?php
/**
 * Dean Portal - Academic Calendar
 * Manage academic calendar events for both weekend and weekday programs
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Ensure academic_calendar table exists
$conn->query("CREATE TABLE IF NOT EXISTS academic_calendar (
    calendar_id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    event_type ENUM('semester_start','semester_end','exam_start','exam_end','registration_start','registration_end','holiday','break','graduation','other') DEFAULT 'other',
    program_type ENUM('all','weekday','weekend') DEFAULT 'all',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_year (academic_year),
    INDEX idx_semester (semester),
    INDEX idx_event_type (event_type),
    INDEX idx_program_type (program_type),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add program_type column if it doesn't exist (for existing tables)
$col_check = $conn->query("SHOW COLUMNS FROM academic_calendar LIKE 'program_type'");
if (!$col_check || $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE academic_calendar ADD COLUMN program_type ENUM('all','weekday','weekend') DEFAULT 'all' AFTER event_type");
}

// Add created_by column if it doesn't exist
$col_check = $conn->query("SHOW COLUMNS FROM academic_calendar LIKE 'created_by'");
if (!$col_check || $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE academic_calendar ADD COLUMN created_by INT NULL AFTER is_active");
}

// Calendar Year Configuration table
$conn->query("CREATE TABLE IF NOT EXISTS calendar_year_config (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    calendar_year INT NOT NULL,
    semester_number INT NOT NULL,
    semester_label VARCHAR(100) NOT NULL,
    month_start VARCHAR(20) NOT NULL,
    month_end VARCHAR(20) NOT NULL,
    academic_year_label VARCHAR(30) NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year_sem (calendar_year, semester_number),
    INDEX idx_calendar_year (calendar_year),
    INDEX idx_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed default config for current year if empty
$cfg_count = $conn->query("SELECT COUNT(*) as cnt FROM calendar_year_config")->fetch_assoc()['cnt'];
if ($cfg_count == 0) {
    $cy = (int)date('Y');
    $defaults = [
        [$cy, 1, 'January - May Semester ' . $cy, 'January', 'May', ($cy-1) . '/' . $cy],
        [$cy, 2, 'June - September Semester ' . $cy, 'June', 'September', ($cy-1) . '/' . $cy],
        [$cy, 3, 'October - December Semester ' . $cy, 'October', 'December', $cy . '/' . ($cy+1)],
    ];
    $ins_cfg = $conn->prepare("INSERT INTO calendar_year_config (calendar_year, semester_number, semester_label, month_start, month_end, academic_year_label, is_current, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    foreach ($defaults as $d) {
        $ins_cfg->bind_param("iissssi", $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $user['user_id']);
        $ins_cfg->execute();
    }
}

// Load semester configs for dropdowns
$semester_configs = [];
$all_config_years = [];
$cfg_result = $conn->query("SELECT * FROM calendar_year_config ORDER BY calendar_year DESC, semester_number ASC");
if ($cfg_result) {
    while ($row = $cfg_result->fetch_assoc()) {
        $semester_configs[] = $row;
        if (!in_array($row['calendar_year'], $all_config_years)) {
            $all_config_years[] = $row['calendar_year'];
        }
    }
}

// Helper to get semester label
function getSemesterConfigLabel($configs, $sem_num, $ay = '') {
    foreach ($configs as $c) {
        if ((string)$c['semester_number'] === (string)$sem_num) {
            if (!$ay || $c['academic_year_label'] === $ay) {
                return $c['semester_label'];
            }
        }
    }
    return 'Semester ' . $sem_num;
}

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_event'])) {
        $academic_year = trim($_POST['academic_year']);
        $semester = trim($_POST['semester']);
        $event_name = trim($_POST['event_name']);
        $event_type = trim($_POST['event_type']);
        $program_type = trim($_POST['program_type'] ?? 'all');
        $start_date = trim($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $description = trim($_POST['description'] ?? '');

        if (empty($academic_year) || empty($semester) || empty($event_name) || empty($start_date)) {
            $error = 'Please fill in all required fields.';
        } else {
            $stmt = $conn->prepare("INSERT INTO academic_calendar (academic_year, semester, event_name, event_type, program_type, start_date, end_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssi", $academic_year, $semester, $event_name, $event_type, $program_type, $start_date, $end_date, $description, $user['user_id']);
            if ($stmt->execute()) {
                $success = 'Calendar event added successfully!';
            } else {
                $error = 'Failed to add event: ' . $conn->error;
            }
        }
    }

    if (isset($_POST['update_event'])) {
        $calendar_id = (int)$_POST['calendar_id'];
        $academic_year = trim($_POST['academic_year']);
        $semester = trim($_POST['semester']);
        $event_name = trim($_POST['event_name']);
        $event_type = trim($_POST['event_type']);
        $program_type = trim($_POST['program_type'] ?? 'all');
        $start_date = trim($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("UPDATE academic_calendar SET academic_year=?, semester=?, event_name=?, event_type=?, program_type=?, start_date=?, end_date=?, description=? WHERE calendar_id=?");
        $stmt->bind_param("ssssssssi", $academic_year, $semester, $event_name, $event_type, $program_type, $start_date, $end_date, $description, $calendar_id);
        if ($stmt->execute()) {
            $success = 'Event updated successfully!';
        } else {
            $error = 'Failed to update event.';
        }
    }

    if (isset($_POST['delete_event'])) {
        $calendar_id = (int)$_POST['calendar_id'];
        $stmt = $conn->prepare("DELETE FROM academic_calendar WHERE calendar_id = ?");
        $stmt->bind_param("i", $calendar_id);
        if ($stmt->execute()) {
            $success = 'Event deleted successfully!';
        } else {
            $error = 'Failed to delete event.';
        }
    }

    if (isset($_POST['toggle_event'])) {
        $calendar_id = (int)$_POST['calendar_id'];
        $new_status = (int)$_POST['new_status'];
        $stmt = $conn->prepare("UPDATE academic_calendar SET is_active = ? WHERE calendar_id = ?");
        $stmt->bind_param("ii", $new_status, $calendar_id);
        $stmt->execute();
        $success = 'Event status updated!';
    }

    // Calendar Year Config handlers
    if (isset($_POST['add_year_config'])) {
        $cfg_year = (int)$_POST['cfg_year'];
        $cfg_semesters = $_POST['cfg_semester'] ?? [];
        if ($cfg_year < 2020 || $cfg_year > 2099) {
            $error = 'Please enter a valid year (2020-2099).';
        } elseif (empty($cfg_semesters)) {
            $error = 'Please define at least one semester.';
        } else {
            $added = 0;
            $stmt = $conn->prepare("INSERT INTO calendar_year_config (calendar_year, semester_number, semester_label, month_start, month_end, academic_year_label, created_by) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE semester_label=VALUES(semester_label), month_start=VALUES(month_start), month_end=VALUES(month_end), academic_year_label=VALUES(academic_year_label)");
            foreach ($cfg_semesters as $sem) {
                $s_num = (int)$sem['number'];
                $s_label = trim($sem['label']);
                $s_mstart = trim($sem['month_start']);
                $s_mend = trim($sem['month_end']);
                $s_ay = trim($sem['academic_year']);
                if ($s_num > 0 && $s_label && $s_mstart && $s_mend && $s_ay) {
                    $stmt->bind_param("iissssi", $cfg_year, $s_num, $s_label, $s_mstart, $s_mend, $s_ay, $user['user_id']);
                    if ($stmt->execute()) $added++;
                }
            }
            $success = "Calendar year $cfg_year configured with $added semester(s).";
            // Reload configs
            $semester_configs = [];
            $all_config_years = [];
            $cfg_result = $conn->query("SELECT * FROM calendar_year_config ORDER BY calendar_year DESC, semester_number ASC");
            if ($cfg_result) {
                while ($row = $cfg_result->fetch_assoc()) {
                    $semester_configs[] = $row;
                    if (!in_array($row['calendar_year'], $all_config_years)) $all_config_years[] = $row['calendar_year'];
                }
            }
        }
    }

    if (isset($_POST['delete_year_config'])) {
        $del_year = (int)$_POST['cfg_year'];
        $stmt = $conn->prepare("DELETE FROM calendar_year_config WHERE calendar_year = ?");
        $stmt->bind_param("i", $del_year);
        if ($stmt->execute()) {
            $success = "Calendar year $del_year configuration removed.";
            // Reload configs
            $semester_configs = [];
            $all_config_years = [];
            $cfg_result = $conn->query("SELECT * FROM calendar_year_config ORDER BY calendar_year DESC, semester_number ASC");
            if ($cfg_result) {
                while ($row = $cfg_result->fetch_assoc()) {
                    $semester_configs[] = $row;
                    if (!in_array($row['calendar_year'], $all_config_years)) $all_config_years[] = $row['calendar_year'];
                }
            }
        } else {
            $error = 'Failed to delete year configuration.';
        }
    }

    if (isset($_POST['update_year_config'])) {
        $cfg_year = (int)$_POST['cfg_year'];
        $cfg_semesters = $_POST['cfg_semester'] ?? [];
        $updated_count = 0;
        foreach ($cfg_semesters as $sem) {
            $cid = (int)($sem['config_id'] ?? 0);
            $s_label = trim($sem['label']);
            $s_mstart = trim($sem['month_start']);
            $s_mend = trim($sem['month_end']);
            $s_ay = trim($sem['academic_year']);
            if ($cid > 0 && $s_label) {
                $stmt = $conn->prepare("UPDATE calendar_year_config SET semester_label=?, month_start=?, month_end=?, academic_year_label=? WHERE config_id=?");
                $stmt->bind_param("ssssi", $s_label, $s_mstart, $s_mend, $s_ay, $cid);
                if ($stmt->execute()) $updated_count++;
            }
        }
        $success = "Year $cfg_year updated ($updated_count semester(s)).";
        // Reload configs
        $semester_configs = [];
        $all_config_years = [];
        $cfg_result = $conn->query("SELECT * FROM calendar_year_config ORDER BY calendar_year DESC, semester_number ASC");
        if ($cfg_result) {
            while ($row = $cfg_result->fetch_assoc()) {
                $semester_configs[] = $row;
                if (!in_array($row['calendar_year'], $all_config_years)) $all_config_years[] = $row['calendar_year'];
            }
        }
    }
}

// Filters
$filter_year = $_GET['year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_program = $_GET['program'] ?? '';

$where = [];
$params = [];
$types = '';

if ($filter_year) { $where[] = "academic_year = ?"; $params[] = $filter_year; $types .= 's'; }
if ($filter_semester) { $where[] = "semester = ?"; $params[] = $filter_semester; $types .= 's'; }
if ($filter_type) { $where[] = "event_type = ?"; $params[] = $filter_type; $types .= 's'; }
if ($filter_program) { $where[] = "(program_type = ? OR program_type = 'all')"; $params[] = $filter_program; $types .= 's'; }

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM academic_calendar $where_sql ORDER BY semester ASC, start_date ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct years
$years_result = $conn->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC");
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['academic_year'];
    }
}

$current_year = date('Y');
$suggested_year = $current_year . '/' . ($current_year + 1);

$event_types = [
    'semester_start' => ['label' => 'Semester Start', 'color' => 'success', 'icon' => 'bi-play-circle'],
    'semester_end' => ['label' => 'Semester End', 'color' => 'danger', 'icon' => 'bi-stop-circle'],
    'exam_start' => ['label' => 'Exams Start', 'color' => 'warning', 'icon' => 'bi-journal-check'],
    'exam_end' => ['label' => 'Exams End', 'color' => 'info', 'icon' => 'bi-journal-x'],
    'registration_start' => ['label' => 'Registration Opens', 'color' => 'primary', 'icon' => 'bi-door-open'],
    'registration_end' => ['label' => 'Registration Closes', 'color' => 'secondary', 'icon' => 'bi-door-closed'],
    'holiday' => ['label' => 'Holiday', 'color' => 'success', 'icon' => 'bi-sun'],
    'break' => ['label' => 'Break', 'color' => 'info', 'icon' => 'bi-cup-hot'],
    'graduation' => ['label' => 'Graduation', 'color' => 'warning', 'icon' => 'bi-mortarboard'],
    'other' => ['label' => 'Other', 'color' => 'secondary', 'icon' => 'bi-calendar-event'],
];

$program_types = [
    'all' => ['label' => 'All Programs', 'color' => 'primary', 'icon' => 'bi-people'],
    'weekday' => ['label' => 'Weekday', 'color' => 'info', 'icon' => 'bi-briefcase'],
    'weekend' => ['label' => 'Weekend', 'color' => 'warning', 'icon' => 'bi-calendar-week'],
];

// Get upcoming events for preview
$upcoming_weekday = [];
$upcoming_weekend = [];
$upcoming_result = $conn->query("SELECT * FROM academic_calendar WHERE is_active = 1 AND start_date >= CURDATE() ORDER BY semester ASC, start_date ASC LIMIT 20");
if ($upcoming_result) {
    while ($row = $upcoming_result->fetch_assoc()) {
        if ($row['program_type'] === 'weekday' || $row['program_type'] === 'all') {
            $upcoming_weekday[] = $row;
        }
        if ($row['program_type'] === 'weekend' || $row['program_type'] === 'all') {
            $upcoming_weekend[] = $row;
        }
    }
}

$page_title = "Academic Calendar";
$breadcrumbs = [['title' => 'Academic Calendar']];
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
    <style>
        .event-type-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .program-badge { padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .calendar-card { transition: transform 0.2s; border-left: 4px solid; }
        .calendar-card:hover { transform: translateY(-2px); }
        .preview-event { padding: 0.5rem 0.75rem; border-left: 3px solid; margin-bottom: 0.5rem; border-radius: 0 6px 6px 0; background: #f8f9fa; }
        .tab-content { padding-top: 1rem; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>

    <div class="container-fluid py-4">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1"><i class="bi bi-calendar-event me-2"></i>Academic Calendar</h3>
                <p class="text-muted mb-0">Manage calendar events for weekday and weekend programs</p>
            </div>
            <div class="d-flex gap-2">
                <a href="print_calendar.php" class="btn btn-outline-dark" target="_blank">
                    <i class="bi bi-printer me-1"></i>Print Calendar
                </a>
                <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#yearSetupPanel">
                    <i class="bi bi-gear me-1"></i>Year Setup
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEventModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Event
                </button>
            </div>
        </div>

        <!-- Calendar Year Setup Panel -->
        <div class="collapse mb-4" id="yearSetupPanel">
            <div class="card shadow-sm border-primary">
                <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-range me-2 text-primary"></i>Calendar Year Setup</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addYearModal">
                        <i class="bi bi-plus-lg me-1"></i>Add New Year
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($semester_configs)): ?>
                        <p class="text-muted text-center py-3">No calendar years configured. Click "Add New Year" to get started.</p>
                    <?php else: ?>
                        <?php
                        // Group by year
                        $grouped_configs = [];
                        foreach ($semester_configs as $c) {
                            $grouped_configs[$c['calendar_year']][] = $c;
                        }
                        ?>
                        <?php foreach ($grouped_configs as $yr => $sems): ?>
                        <div class="card mb-3 border">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                <h6 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Calendar Year <?= (int)$yr ?></h6>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#editYear<?= $yr ?>">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete all semester configs for <?= $yr ?>?')" class="d-inline">
                                        <input type="hidden" name="cfg_year" value="<?= $yr ?>">
                                        <button type="submit" name="delete_year_config" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="card-body py-2">
                                <div class="row g-2">
                                    <?php foreach ($sems as $s): ?>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2 h-100 bg-white">
                                            <div class="fw-bold text-primary small">Semester <?= (int)$s['semester_number'] ?></div>
                                            <div class="fw-semibold"><?= htmlspecialchars($s['semester_label']) ?></div>
                                            <div class="text-muted small">
                                                <i class="bi bi-clock me-1"></i><?= htmlspecialchars($s['month_start']) ?> - <?= htmlspecialchars($s['month_end']) ?>
                                            </div>
                                            <div class="text-muted small">
                                                <i class="bi bi-mortarboard me-1"></i>AY: <?= htmlspecialchars($s['academic_year_label']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <!-- Edit form (collapsed) -->
                            <div class="collapse" id="editYear<?= $yr ?>">
                                <div class="card-body border-top bg-light">
                                    <form method="POST">
                                        <input type="hidden" name="cfg_year" value="<?= $yr ?>">
                                        <?php foreach ($sems as $si => $s): ?>
                                        <div class="row g-2 mb-2 align-items-end">
                                            <input type="hidden" name="cfg_semester[<?= $si ?>][config_id]" value="<?= $s['config_id'] ?>">
                                            <div class="col-md-1">
                                                <label class="form-label small mb-0">Sem</label>
                                                <input type="text" class="form-control form-control-sm" value="<?= (int)$s['semester_number'] ?>" disabled>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small mb-0">Label</label>
                                                <input type="text" class="form-control form-control-sm" name="cfg_semester[<?= $si ?>][label]" value="<?= htmlspecialchars($s['semester_label']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small mb-0">Start Month</label>
                                                <input type="text" class="form-control form-control-sm" name="cfg_semester[<?= $si ?>][month_start]" value="<?= htmlspecialchars($s['month_start']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small mb-0">End Month</label>
                                                <input type="text" class="form-control form-control-sm" name="cfg_semester[<?= $si ?>][month_end]" value="<?= htmlspecialchars($s['month_end']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small mb-0">Academic Year</label>
                                                <input type="text" class="form-control form-control-sm" name="cfg_semester[<?= $si ?>][academic_year]" value="<?= htmlspecialchars($s['academic_year_label']) ?>" required>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="mt-2">
                                            <button type="submit" name="update_year_config" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>

        <!-- Calendar Preview - Weekday & Weekend Side by Side -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-info bg-opacity-10 border-0">
                        <h6 class="mb-0"><i class="bi bi-briefcase me-2 text-info"></i>Weekday Program - Upcoming Events</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_weekday)): ?>
                            <p class="text-muted text-center py-3"><i class="bi bi-calendar-x" style="font-size:1.5rem;"></i><br>No upcoming weekday events</p>
                        <?php else: ?>
                            <?php foreach (array_slice($upcoming_weekday, 0, 6) as $evt): ?>
                                <?php $et = $event_types[$evt['event_type']] ?? $event_types['other']; ?>
                                <div class="preview-event" style="border-left-color: var(--bs-<?= $et['color'] ?>);">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong class="small"><?= htmlspecialchars($evt['event_name']) ?></strong>
                                            <br><small class="text-muted"><i class="bi <?= $et['icon'] ?> me-1"></i><?= $et['label'] ?></small>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-<?= $et['color'] ?> fw-semibold"><?= date('M j', strtotime($evt['start_date'])) ?></small>
                                            <?php if ($evt['end_date']): ?>
                                                <br><small class="text-muted">to <?= date('M j', strtotime($evt['end_date'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-warning bg-opacity-10 border-0">
                        <h6 class="mb-0"><i class="bi bi-calendar-week me-2 text-warning"></i>Weekend Program - Upcoming Events</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_weekend)): ?>
                            <p class="text-muted text-center py-3"><i class="bi bi-calendar-x" style="font-size:1.5rem;"></i><br>No upcoming weekend events</p>
                        <?php else: ?>
                            <?php foreach (array_slice($upcoming_weekend, 0, 6) as $evt): ?>
                                <?php $et = $event_types[$evt['event_type']] ?? $event_types['other']; ?>
                                <div class="preview-event" style="border-left-color: var(--bs-<?= $et['color'] ?>);">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong class="small"><?= htmlspecialchars($evt['event_name']) ?></strong>
                                            <br><small class="text-muted"><i class="bi <?= $et['icon'] ?> me-1"></i><?= $et['label'] ?></small>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-<?= $et['color'] ?> fw-semibold"><?= date('M j', strtotime($evt['start_date'])) ?></small>
                                            <?php if ($evt['end_date']): ?>
                                                <br><small class="text-muted">to <?= date('M j', strtotime($evt['end_date'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="card-body">
                        <i class="bi bi-calendar3 text-primary" style="font-size: 1.5rem;"></i>
                        <h4 class="mb-0 mt-1"><?= count($events) ?></h4>
                        <small class="text-muted">Total Events</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="card-body">
                        <i class="bi bi-check-circle text-success" style="font-size: 1.5rem;"></i>
                        <h4 class="mb-0 mt-1"><?= count(array_filter($events, fn($e) => $e['is_active'])) ?></h4>
                        <small class="text-muted">Active Events</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="card-body">
                        <i class="bi bi-briefcase text-info" style="font-size: 1.5rem;"></i>
                        <h4 class="mb-0 mt-1"><?= count(array_filter($events, fn($e) => $e['program_type'] === 'weekday' || $e['program_type'] === 'all')) ?></h4>
                        <small class="text-muted">Weekday Events</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="card-body">
                        <i class="bi bi-calendar-week text-warning" style="font-size: 1.5rem;"></i>
                        <h4 class="mb-0 mt-1"><?= count(array_filter($events, fn($e) => $e['program_type'] === 'weekend' || $e['program_type'] === 'all')) ?></h4>
                        <small class="text-muted">Weekend Events</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small">Academic Year</label>
                        <select class="form-select" name="year">
                            <option value="">All Years</option>
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?= htmlspecialchars($y) ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Semester</label>
                        <select class="form-select" name="semester">
                            <option value="">All Semesters</option>
                            <option value="1" <?= $filter_semester === '1' ? 'selected' : '' ?>>Semester 1</option>
                            <option value="2" <?= $filter_semester === '2' ? 'selected' : '' ?>>Semester 2</option>
                            <option value="3" <?= $filter_semester === '3' ? 'selected' : '' ?>>Semester 3</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Event Type</label>
                        <select class="form-select" name="type">
                            <option value="">All Types</option>
                            <?php foreach ($event_types as $tk => $tv): ?>
                                <option value="<?= $tk ?>" <?= $filter_type === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Program Type</label>
                        <select class="form-select" name="program">
                            <option value="">All Programs</option>
                            <option value="weekday" <?= $filter_program === 'weekday' ? 'selected' : '' ?>>Weekday</option>
                            <option value="weekend" <?= $filter_program === 'weekend' ? 'selected' : '' ?>>Weekend</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="academic-calendar.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Events Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Calendar Events (<?= count($events) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Event</th>
                                <th>Type</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th>Semester</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($events)): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-calendar-x" style="font-size:2rem;"></i><br>No calendar events found. Add your first event!</td></tr>
                            <?php else: ?>
                                <?php foreach ($events as $i => $evt): ?>
                                <?php
                                    $et = $event_types[$evt['event_type']] ?? $event_types['other'];
                                    $pt = $program_types[$evt['program_type']] ?? $program_types['all'];
                                    $is_past = $evt['start_date'] < date('Y-m-d');
                                    $is_today = $evt['start_date'] === date('Y-m-d');
                                ?>
                                <tr class="<?= $is_today ? 'table-info' : '' ?>">
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($evt['event_name']) ?></strong>
                                        <?php if ($evt['description']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($evt['description'], 0, 50, '...')) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="event-type-badge bg-<?= $et['color'] ?> bg-opacity-10 text-<?= $et['color'] ?>">
                                            <i class="bi <?= $et['icon'] ?> me-1"></i><?= $et['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="program-badge bg-<?= $pt['color'] ?> bg-opacity-10 text-<?= $pt['color'] ?>">
                                            <i class="bi <?= $pt['icon'] ?> me-1"></i><?= $pt['label'] ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($evt['academic_year']) ?></td>
                                    <td><?= htmlspecialchars(getSemesterConfigLabel($semester_configs, $evt['semester'], $evt['academic_year'])) ?></td>
                                    <td>
                                        <?= date('M j, Y', strtotime($evt['start_date'])) ?>
                                        <?php if ($is_today): ?><span class="badge bg-info ms-1">Today</span><?php endif; ?>
                                    </td>
                                    <td><?= $evt['end_date'] ? date('M j, Y', strtotime($evt['end_date'])) : '-' ?></td>
                                    <td>
                                        <span class="badge bg-<?= $evt['is_active'] ? 'success' : 'secondary' ?>">
                                            <?= $evt['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $evt['calendar_id'] ?>" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="calendar_id" value="<?= $evt['calendar_id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $evt['is_active'] ? 0 : 1 ?>">
                                                <button type="submit" name="toggle_event" class="btn btn-outline-<?= $evt['is_active'] ? 'secondary' : 'success' ?>" title="<?= $evt['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi bi-<?= $evt['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                                                </button>
                                            </form>
                                            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $evt['calendar_id'] ?>" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editModal<?= $evt['calendar_id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header" style="background: linear-gradient(135deg, #1a472a, #2d5a3e); color: white;">
                                                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Event</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="calendar_id" value="<?= $evt['calendar_id'] ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">Event Name*</label>
                                                        <input type="text" class="form-control" name="event_name" value="<?= htmlspecialchars($evt['event_name']) ?>" required>
                                                    </div>
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label">Academic Year*</label>
                                                            <input type="text" class="form-control" name="academic_year" value="<?= htmlspecialchars($evt['academic_year']) ?>" required placeholder="e.g. 2025/2026">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Semester*</label>
                                                            <select class="form-select" name="semester" required>
                                                                <?php
                                                                $edit_shown = [];
                                                                foreach ($semester_configs as $sc) {
                                                                    if (!in_array($sc['semester_number'], $edit_shown)) {
                                                                        $edit_shown[] = $sc['semester_number'];
                                                                        $sel = ($evt['semester'] == $sc['semester_number']) ? 'selected' : '';
                                                                        echo '<option value="' . (int)$sc['semester_number'] . '" ' . $sel . '>Sem ' . (int)$sc['semester_number'] . ' - ' . htmlspecialchars($sc['semester_label']) . '</option>';
                                                                    }
                                                                }
                                                                if (empty($edit_shown)) {
                                                                    echo '<option value="1"' . ($evt['semester']=='1'?' selected':'') . '>Semester 1</option>';
                                                                    echo '<option value="2"' . ($evt['semester']=='2'?' selected':'') . '>Semester 2</option>';
                                                                    echo '<option value="3"' . ($evt['semester']=='3'?' selected':'') . '>Semester 3</option>';
                                                                }
                                                                ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label">Event Type</label>
                                                            <select class="form-select" name="event_type">
                                                                <?php foreach ($event_types as $tk2 => $tv2): ?>
                                                                    <option value="<?= $tk2 ?>" <?= $evt['event_type'] === $tk2 ? 'selected' : '' ?>><?= $tv2['label'] ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Program Type</label>
                                                            <select class="form-select" name="program_type">
                                                                <option value="all" <?= ($evt['program_type'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Programs</option>
                                                                <option value="weekday" <?= ($evt['program_type'] ?? '') === 'weekday' ? 'selected' : '' ?>>Weekday Only</option>
                                                                <option value="weekend" <?= ($evt['program_type'] ?? '') === 'weekend' ? 'selected' : '' ?>>Weekend Only</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="row g-3 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label">Start Date*</label>
                                                            <input type="date" class="form-control" name="start_date" value="<?= $evt['start_date'] ?>" required>
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">End Date</label>
                                                            <input type="date" class="form-control" name="end_date" value="<?= $evt['end_date'] ?? '' ?>">
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Description</label>
                                                        <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($evt['description'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="update_event" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Update</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?= $evt['calendar_id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-sm">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Event</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="calendar_id" value="<?= $evt['calendar_id'] ?>">
                                                    <p>Are you sure you want to delete <strong><?= htmlspecialchars($evt['event_name']) ?></strong>?</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="delete_event" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Event Modal -->
    <div class="modal fade" id="addEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header" style="background: linear-gradient(135deg, #1a472a, #2d5a3e); color: white;">
                        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Calendar Event</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Event Name*</label>
                            <input type="text" class="form-control" name="event_name" required placeholder="e.g. Semester 1 Begins">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Academic Year*</label>
                                <input type="text" class="form-control" name="academic_year" value="<?= htmlspecialchars($suggested_year) ?>" required placeholder="e.g. 2025/2026">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Semester*</label>
                                <select class="form-select" name="semester" required>
                                    <?php
                                    $shown_sems = [];
                                    foreach ($semester_configs as $sc) {
                                        if (!in_array($sc['semester_number'], $shown_sems)) {
                                            $shown_sems[] = $sc['semester_number'];
                                            echo '<option value="' . (int)$sc['semester_number'] . '">Sem ' . (int)$sc['semester_number'] . ' - ' . htmlspecialchars($sc['semester_label']) . '</option>';
                                        }
                                    }
                                    if (empty($shown_sems)) {
                                        echo '<option value="1">Semester 1</option><option value="2">Semester 2</option><option value="3">Semester 3</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Program Type</label>
                                <select class="form-select" name="program_type">
                                    <option value="all">All Programs</option>
                                    <option value="weekday">Weekday Only</option>
                                    <option value="weekend">Weekend Only</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Event Type</label>
                            <select class="form-select" name="event_type">
                                <?php foreach ($event_types as $tk => $tv): ?>
                                    <option value="<?= $tk ?>"><?= $tv['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date*</label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Optional details about this event"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_event" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Add New Year Modal -->
    <div class="modal fade" id="addYearModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addYearForm">
                    <div class="modal-header" style="background: linear-gradient(135deg, #1a472a, #2d5a3e); color: white;">
                        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Add New Calendar Year</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-1"></i>
                            Define the calendar year and its semesters. Example: 2026 has 3 semesters - January-May, June-September, October-December.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Calendar Year*</label>
                            <input type="number" class="form-control" name="cfg_year" id="newCfgYear" min="2020" max="2099" value="<?= date('Y') + 1 ?>" required>
                        </div>
                        <hr>
                        <h6 class="fw-bold mb-3"><i class="bi bi-list-ol me-2"></i>Semesters</h6>
                        <div id="semesterRows">
                            <div class="row g-2 mb-2 align-items-end sem-row">
                                <div class="col-md-1">
                                    <label class="form-label small mb-0">Sem #</label>
                                    <input type="number" class="form-control form-control-sm" name="cfg_semester[0][number]" value="1" min="1" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small mb-0">Label*</label>
                                    <input type="text" class="form-control form-control-sm" name="cfg_semester[0][label]" placeholder="e.g. January - May Semester 2027" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-0">Start Month*</label>
                                    <select class="form-select form-select-sm" name="cfg_semester[0][month_start]" required>
                                        <option value="January">January</option><option value="February">February</option><option value="March">March</option>
                                        <option value="April">April</option><option value="May">May</option><option value="June">June</option>
                                        <option value="July">July</option><option value="August">August</option><option value="September">September</option>
                                        <option value="October">October</option><option value="November">November</option><option value="December">December</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-0">End Month*</label>
                                    <select class="form-select form-select-sm" name="cfg_semester[0][month_end]" required>
                                        <option value="January">January</option><option value="February">February</option><option value="March">March</option>
                                        <option value="April">April</option><option value="May" selected>May</option><option value="June">June</option>
                                        <option value="July">July</option><option value="August">August</option><option value="September">September</option>
                                        <option value="October">October</option><option value="November">November</option><option value="December">December</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-0">Academic Year*</label>
                                    <input type="text" class="form-control form-control-sm" name="cfg_semester[0][academic_year]" placeholder="e.g. 2026/2027" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.sem-row').remove()" title="Remove">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addSemesterRow()">
                            <i class="bi bi-plus me-1"></i>Add Semester
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_year_config" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Year Configuration</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    let semRowCount = 1;
    function addSemesterRow() {
        const i = semRowCount++;
        const yr = document.getElementById('newCfgYear').value || <?= date('Y') + 1 ?>;
        const html = `
        <div class="row g-2 mb-2 align-items-end sem-row">
            <div class="col-md-1">
                <input type="number" class="form-control form-control-sm" name="cfg_semester[${i}][number]" value="${i+1}" min="1" required>
            </div>
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm" name="cfg_semester[${i}][label]" placeholder="Semester label" required>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="cfg_semester[${i}][month_start]" required>
                    <option value="January">January</option><option value="February">February</option><option value="March">March</option>
                    <option value="April">April</option><option value="May">May</option><option value="June" selected>June</option>
                    <option value="July">July</option><option value="August">August</option><option value="September">September</option>
                    <option value="October">October</option><option value="November">November</option><option value="December">December</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="cfg_semester[${i}][month_end]" required>
                    <option value="January">January</option><option value="February">February</option><option value="March">March</option>
                    <option value="April">April</option><option value="May">May</option><option value="June">June</option>
                    <option value="July">July</option><option value="August">August</option><option value="September" selected>September</option>
                    <option value="October">October</option><option value="November">November</option><option value="December">December</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control form-control-sm" name="cfg_semester[${i}][academic_year]" placeholder="e.g. ${yr-1}/${yr}" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.sem-row').remove()" title="Remove">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>`;
        document.getElementById('semesterRows').insertAdjacentHTML('beforeend', html);
    }
    // Auto-add 3 semesters on load
    document.addEventListener('DOMContentLoaded', function() {
        addSemesterRow(); // Sem 2
        addSemesterRow(); // Sem 3
    });
    </script>
</body>
</html>
