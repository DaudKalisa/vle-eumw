<?php
/**
 * Dean - Semester Learning Timetable Management
 * View, approve, and manage class timetables for Year 1-4
 * Dean can also trigger auto-generation alongside ODL Coordinator
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// Create table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS class_timetable (
        timetable_id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        venue VARCHAR(100),
        session_type ENUM('lecture','tutorial','practical','exam','online') DEFAULT 'lecture',
        semester VARCHAR(20) DEFAULT 'One',
        academic_year VARCHAR(20),
        year_of_study INT DEFAULT NULL,
        program_type ENUM('weekday','weekend','all') DEFAULT 'all',
        generation_log_id INT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (course_id), INDEX (day_of_week)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$success_message = '';
$error_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'toggle':
            $timetable_id = (int)$_POST['timetable_id'];
            $stmt = $conn->prepare("UPDATE class_timetable SET is_active = NOT is_active WHERE timetable_id = ?");
            $stmt->bind_param("i", $timetable_id);
            $stmt->execute();
            $success_message = "Entry status updated.";
            break;

        case 'delete':
            $timetable_id = (int)$_POST['timetable_id'];
            $stmt = $conn->prepare("DELETE FROM class_timetable WHERE timetable_id = ?");
            $stmt->bind_param("i", $timetable_id);
            if ($stmt->execute()) {
                $success_message = "Timetable entry deleted.";
            } else {
                $error_message = "Failed to delete entry.";
            }
            break;

        case 'clear_generated':
            $sem = $_POST['clear_semester'] ?? '';
            $ay = $_POST['clear_academic_year'] ?? '';
            if ($sem && $ay) {
                $del = $conn->prepare("DELETE FROM class_timetable WHERE semester = ? AND academic_year = ? AND generation_log_id IS NOT NULL");
                $del->bind_param("ss", $sem, $ay);
                $del->execute();
                $success_message = "Cleared " . $del->affected_rows . " generated entries.";
            }
            break;
    }
}

// Filters
$filter_semester = $_GET['semester'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_day = $_GET['day'] ?? '';

$where = ["1=1"];
$params = [];
$types = "";

if ($filter_semester) { $where[] = "t.semester = ?"; $params[] = $filter_semester; $types .= "s"; }
if ($filter_year) { $where[] = "t.year_of_study = ?"; $params[] = (int)$filter_year; $types .= "i"; }
if ($filter_day) { $where[] = "t.day_of_week = ?"; $params[] = $filter_day; $types .= "s"; }

$where_sql = implode(" AND ", $where);
$sql = "SELECT t.*, c.course_code, c.course_name, l.full_name as lecturer_name
        FROM class_timetable t
        JOIN vle_courses c ON t.course_id = c.course_id
        LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
        WHERE $where_sql
        ORDER BY t.year_of_study, FIELD(t.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), t.start_time";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$timetable = [];
if ($result) { while ($row = $result->fetch_assoc()) $timetable[] = $row; }

// Stats
$year_stats = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($timetable as $t) {
    $y = (int)($t['year_of_study'] ?? 0);
    if (isset($year_stats[$y])) $year_stats[$y]++;
}

// Academic year (dean_settings is a key-value table)
$current_ay = date('Y') . '/' . (date('Y') + 1);
try {
    $ay_result = $conn->query("SELECT setting_value FROM dean_settings WHERE setting_key = 'current_academic_year' LIMIT 1");
    if ($ay_result && $row = $ay_result->fetch_assoc()) $current_ay = $row['setting_value'];
} catch (Exception $e) {
    // dean_settings table may not exist
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$page_title = 'Semester Timetable';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Timetable - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; }
        .time-slot { font-family: monospace; font-size: 0.85rem; }
        .session-lecture { border-left: 4px solid #0d6efd; }
        .session-tutorial { border-left: 4px solid #198754; }
        .session-practical { border-left: 4px solid #ffc107; }
        .session-exam { border-left: 4px solid #dc3545; }
        .session-online { border-left: 4px solid #6f42c1; }
        .stat-card { border: none; border-radius: 12px; transition: transform 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .year-group-header { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; }
        .grid-cell { min-height: 60px; border: 1px solid #dee2e6; padding: 4px; font-size: 0.8rem; vertical-align: top; }
        .grid-cell .entry { background: #e8f4fd; border-left: 3px solid #0d6efd; padding: 2px 6px; margin-bottom: 2px; border-radius: 4px; }
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-calendar-week me-2"></i>Semester Learning Timetable</h1>
                <p class="text-muted mb-0">View and manage class schedules for Year 1-4</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#generateModal">
                    <i class="bi bi-lightning me-1"></i>Auto-Generate
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
                <div class="card stat-card bg-primary text-white" onclick="filterYear('')">
                    <div class="card-body py-3 text-center">
                        <div class="h3 mb-0"><?= count($timetable) ?></div>
                        <small>Total Entries</small>
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
        
        <!-- Filters -->
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
                            <option value="">All Years</option>
                            <option value="1" <?= $filter_year === '1' ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= $filter_year === '2' ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= $filter_year === '3' ? 'selected' : '' ?>>Year 3</option>
                            <option value="4" <?= $filter_year === '4' ? 'selected' : '' ?>>Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Day</label>
                        <select name="day" class="form-select form-select-sm">
                            <option value="">All Days</option>
                            <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>" <?= $filter_day === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="manage_timetable.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </div>
                    <div class="col-md-3 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-dark btn-sm" onclick="toggleView()">
                            <i class="bi bi-grid me-1" id="viewIcon"></i><span id="viewLabel">Grid</span>
                        </button>
                        <a href="../odl_coordinator/print_timetable.php" class="btn btn-outline-dark btn-sm" target="_blank">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Table View -->
        <div id="tableView">
            <?php
            $grouped = [];
            foreach ($timetable as $t) {
                $yr = (int)($t['year_of_study'] ?? 0);
                $grouped[$yr][] = $t;
            }
            ksort($grouped);
            
            if (empty($timetable)): ?>
            <div class="card">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-calendar-x display-4 d-block mb-3"></i>
                    <h5>No timetable entries found</h5>
                    <p>Use <strong>Auto-Generate</strong> to create a semester timetable or ask the ODL Coordinator to set it up.</p>
                </div>
            </div>
            <?php else:
            foreach ($grouped as $yr => $entries): ?>
            <div class="card mb-4">
                <div class="card-header year-group-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <span class="badge me-2" style="background: <?= $year_colors[$yr] ?? '#6c757d' ?>;">Year <?= $yr ?: '?' ?></span>
                        <?= count($entries) ?> entries
                    </h6>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Clear all auto-generated entries for this semester?')">
                        <input type="hidden" name="action" value="clear_generated">
                        <input type="hidden" name="clear_semester" value="<?= htmlspecialchars($filter_semester ?: 'One') ?>">
                        <input type="hidden" name="clear_academic_year" value="<?= htmlspecialchars($current_ay) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Clear Generated</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Course</th>
                                    <th>Lecturer</th>
                                    <th>Venue</th>
                                    <th>Type</th>
                                    <th>Program</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($entries as $t): ?>
                                <tr class="session-<?= $t['session_type'] ?>">
                                    <td><strong><?= $t['day_of_week'] ?></strong></td>
                                    <td class="time-slot"><?= date('H:i', strtotime($t['start_time'])) ?> - <?= date('H:i', strtotime($t['end_time'])) ?></td>
                                    <td>
                                        <code><?= htmlspecialchars($t['course_code']) ?></code>
                                        <div class="small text-muted"><?= htmlspecialchars($t['course_name']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($t['lecturer_name'] ?? 'Not assigned') ?></td>
                                    <td><?= htmlspecialchars($t['venue'] ?: '-') ?></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst($t['session_type']) ?></span></td>
                                    <td><span class="badge bg-light text-dark"><?= ucfirst($t['program_type'] ?? 'all') ?></span></td>
                                    <td>
                                        <?php if ($t['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['generation_log_id'])): ?>
                                        <span class="badge bg-info">Auto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="timetable_id" value="<?= $t['timetable_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle"><i class="bi bi-toggle-<?= $t['is_active'] ? 'on' : 'off' ?>"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="timetable_id" value="<?= $t['timetable_id'] ?>">
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
        
        <!-- Grid View -->
        <div id="gridView" style="display:none;">
            <?php
            $grid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
            $grid_slots = [['08:00','09:30'],['09:45','11:15'],['11:30','13:00'],['14:00','15:30'],['15:45','17:15']];
            foreach ($grouped as $yr => $entries):
                $grid = [];
                foreach ($entries as $t) {
                    $key = $t['day_of_week'] . '|' . date('H:i', strtotime($t['start_time']));
                    $grid[$key][] = $t;
                }
            ?>
            <div class="card mb-4">
                <div class="card-header year-group-header">
                    <h6 class="mb-0"><span class="badge me-2" style="background: <?= $year_colors[$yr] ?? '#6c757d' ?>;">Year <?= $yr ?: '?' ?></span> Grid View</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0" style="table-layout:fixed;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:100px;">Time</th>
                                    <?php foreach ($grid_days as $gd): ?>
                                    <th><?= $gd ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($grid_slots as $slot): ?>
                                <tr>
                                    <td class="time-slot fw-bold"><?= $slot[0] ?><br><?= $slot[1] ?></td>
                                    <?php foreach ($grid_days as $gd):
                                        $key = $gd . '|' . $slot[0];
                                        $cell_entries = $grid[$key] ?? [];
                                    ?>
                                    <td class="grid-cell">
                                        <?php foreach ($cell_entries as $ce): ?>
                                        <div class="entry">
                                            <strong><?= htmlspecialchars($ce['course_code']) ?></strong><br>
                                            <small><?= htmlspecialchars($ce['venue'] ?: '') ?></small>
                                        </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Generate Modal -->
    <div class="modal fade" id="generateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-lightning me-2"></i>Auto-Generate Semester Timetable</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Automatically assigns all active courses to time slots, avoiding venue, lecturer, and year-group conflicts.
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program Type</label>
                            <select id="genProgramType" class="form-select">
                                <option value="weekday">Weekday (Mon-Fri)</option>
                                <option value="weekend">Weekend (Sat-Sun)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Slot Duration</label>
                            <select id="genSlotDuration" class="form-select">
                                <option value="60">60 min</option>
                                <option value="90" selected>90 min</option>
                                <option value="120">120 min</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="genClearExisting">
                        <label class="form-check-label text-danger" for="genClearExisting">Clear existing generated entries before generating</label>
                    </div>
                    <div id="genResult" class="d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="btnGenerate" onclick="generateTimetable()">
                        <i class="bi bi-lightning me-1"></i>Generate
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let currentView = 'table';
    function toggleView() {
        if (currentView === 'table') {
            document.getElementById('tableView').style.display = 'none';
            document.getElementById('gridView').style.display = 'block';
            document.getElementById('viewIcon').className = 'bi bi-list me-1';
            document.getElementById('viewLabel').textContent = 'List';
            currentView = 'grid';
        } else {
            document.getElementById('tableView').style.display = 'block';
            document.getElementById('gridView').style.display = 'none';
            document.getElementById('viewIcon').className = 'bi bi-grid me-1';
            document.getElementById('viewLabel').textContent = 'Grid';
            currentView = 'table';
        }
    }
    function filterYear(yr) {
        document.getElementById('yearFilter').value = yr;
        document.getElementById('filterForm').submit();
    }
    function generateTimetable() {
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
        resultDiv.textContent = 'Generating timetable...';
        
        fetch('../api/generate_timetable.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                semester: document.getElementById('genSemester').value,
                academic_year: document.getElementById('genAcademicYear').value,
                year_levels: yearLevels,
                program_type: document.getElementById('genProgramType').value,
                slot_duration: parseInt(document.getElementById('genSlotDuration').value),
                clear_existing: document.getElementById('genClearExisting').checked
            })
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning me-1"></i>Generate';
            if (data.success) {
                resultDiv.className = 'alert alert-success';
                let msg = '<strong>Success!</strong> ' + data.message;
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
            btn.innerHTML = '<i class="bi bi-lightning me-1"></i>Generate';
            resultDiv.className = 'alert alert-danger';
            resultDiv.textContent = 'Error: ' + err.message;
        });
    }
    </script>
</body>
</html>
