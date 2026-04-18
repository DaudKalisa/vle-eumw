<?php
// admin/calendar_settings.php - Academic Calendar Settings
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Ensure calendar_settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS academic_calendar (
    calendar_id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    event_type ENUM('semester_start','semester_end','exam_start','exam_end','registration_start','registration_end','holiday','break','graduation','other') DEFAULT 'other',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_year (academic_year),
    INDEX idx_semester (semester),
    INDEX idx_event_type (event_type),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_event'])) {
        $academic_year = trim($_POST['academic_year']);
        $semester = trim($_POST['semester']);
        $event_name = trim($_POST['event_name']);
        $event_type = trim($_POST['event_type']);
        $start_date = trim($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $description = trim($_POST['description'] ?? '');

        if (empty($academic_year) || empty($semester) || empty($event_name) || empty($start_date)) {
            $error = 'Please fill in all required fields.';
        } else {
            $stmt = $conn->prepare("INSERT INTO academic_calendar (academic_year, semester, event_name, event_type, start_date, end_date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $academic_year, $semester, $event_name, $event_type, $start_date, $end_date, $description);
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
        $start_date = trim($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $description = trim($_POST['description'] ?? '');

        $stmt = $conn->prepare("UPDATE academic_calendar SET academic_year=?, semester=?, event_name=?, event_type=?, start_date=?, end_date=?, description=? WHERE calendar_id=?");
        $stmt->bind_param("sssssssi", $academic_year, $semester, $event_name, $event_type, $start_date, $end_date, $description, $calendar_id);
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
}

// Filters
$filter_year = $_GET['year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = [];
$params = [];
$types = '';

if ($filter_year) { $where[] = "academic_year = ?"; $params[] = $filter_year; $types .= 's'; }
if ($filter_semester) { $where[] = "semester = ?"; $params[] = $filter_semester; $types .= 's'; }
if ($filter_type) { $where[] = "event_type = ?"; $params[] = $filter_type; $types .= 's'; }

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM academic_calendar $where_sql ORDER BY start_date DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct years for filter
$years_result = $conn->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC");
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['academic_year'];
    }
}

// Current/next academic year suggestion
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

$page_title = 'Calendar Settings';
$breadcrumbs = [['title' => 'Calendar Settings']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <style>
        .event-type-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .calendar-card { transition: transform 0.2s; border-left: 4px solid; }
        .calendar-card:hover { transform: translateY(-2px); }
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
        <h3 class="fw-bold mb-0"><i class="bi bi-calendar-event me-2"></i>Academic Calendar Settings</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
            <i class="bi bi-plus-lg me-1"></i>Add Event
        </button>
    </div>

    <!-- Summary Cards -->
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
                    <i class="bi bi-calendar-check text-info" style="font-size: 1.5rem;"></i>
                    <h4 class="mb-0 mt-1"><?= count(array_filter($events, fn($e) => $e['start_date'] >= date('Y-m-d'))) ?></h4>
                    <small class="text-muted">Upcoming</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="card-body">
                    <i class="bi bi-clock-history text-warning" style="font-size: 1.5rem;"></i>
                    <h4 class="mb-0 mt-1"><?= count($available_years) ?></h4>
                    <small class="text-muted">Academic Years</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Academic Year</label>
                    <select class="form-select" name="year">
                        <option value="">All Years</option>
                        <?php foreach ($available_years as $y): ?>
                            <option value="<?= htmlspecialchars($y) ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Semester</label>
                    <select class="form-select" name="semester">
                        <option value="">All Semesters</option>
                        <option value="1" <?= $filter_semester === '1' ? 'selected' : '' ?>>Semester 1</option>
                        <option value="2" <?= $filter_semester === '2' ? 'selected' : '' ?>>Semester 2</option>
                        <option value="3" <?= $filter_semester === '3' ? 'selected' : '' ?>>Semester 3 (Summer)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Event Type</label>
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($event_types as $tk => $tv): ?>
                            <option value="<?= $tk ?>" <?= $filter_type === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="calendar_settings.php" class="btn btn-outline-secondary">Clear</a>
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
                            <th>Academic Year</th>
                            <th>Semester</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($events)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-calendar-x" style="font-size:2rem;"></i><br>No calendar events found. Add your first event!</td></tr>
                        <?php else: ?>
                            <?php foreach ($events as $i => $evt): ?>
                            <?php
                                $et = $event_types[$evt['event_type']] ?? $event_types['other'];
                                $is_past = $evt['start_date'] < date('Y-m-d');
                                $is_today = $evt['start_date'] === date('Y-m-d');
                            ?>
                            <tr class="<?= $is_today ? 'table-info' : ($is_past && $evt['is_active'] ? '' : '') ?>">
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
                                <td><?= htmlspecialchars($evt['academic_year']) ?></td>
                                <td>Sem <?= htmlspecialchars($evt['semester']) ?></td>
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
                                            <div class="modal-header bg-primary text-white">
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
                                                            <option value="1" <?= $evt['semester'] == '1' ? 'selected' : '' ?>>Semester 1</option>
                                                            <option value="2" <?= $evt['semester'] == '2' ? 'selected' : '' ?>>Semester 2</option>
                                                            <option value="3" <?= $evt['semester'] == '3' ? 'selected' : '' ?>>Semester 3</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Event Type</label>
                                                    <select class="form-select" name="event_type">
                                                        <?php foreach ($event_types as $tk2 => $tv2): ?>
                                                            <option value="<?= $tk2 ?>" <?= $evt['event_type'] === $tk2 ? 'selected' : '' ?>><?= $tv2['label'] ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
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
                                                <button type="submit" name="update_event" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
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
                                                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body text-center">
                                                <input type="hidden" name="calendar_id" value="<?= $evt['calendar_id'] ?>">
                                                <i class="bi bi-exclamation-triangle text-danger" style="font-size: 2.5rem;"></i>
                                                <p class="mt-2">Delete <strong><?= htmlspecialchars($evt['event_name']) ?></strong>?</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="delete_event" class="btn btn-danger btn-sm">Delete</button>
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
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white;">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Add Calendar Event</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Event Name*</label>
                        <input type="text" class="form-control" name="event_name" required placeholder="e.g. Semester 1 Lectures Begin">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">Academic Year*</label>
                            <input type="text" class="form-control" name="academic_year" required value="<?= $suggested_year ?>" placeholder="e.g. 2025/2026">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Semester*</label>
                            <select class="form-select" name="semester" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Semester 3 (Summer)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Event Type</label>
                        <select class="form-select" name="event_type">
                            <?php foreach ($event_types as $tk3 => $tv3): ?>
                                <option value="<?= $tk3 ?>"><?= $tv3['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">Start Date*</label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Optional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_event" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
