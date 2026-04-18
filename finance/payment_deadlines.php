<?php
// finance/payment_deadlines.php - Manage Payment Deadlines & Automated Reminders
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

$conn = getDbConnection();
$user = getCurrentUser();

// ==================== CREATE TABLES IF NOT EXISTS ====================
$conn->query("CREATE TABLE IF NOT EXISTS payment_deadlines (
    deadline_id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL DEFAULT '2025/2026',
    semester INT NOT NULL DEFAULT 1,
    installment_type VARCHAR(50) NOT NULL COMMENT 'application_fee, registration_fee, installment_1-4',
    installment_label VARCHAR(100) NOT NULL,
    deadline_date DATE NOT NULL,
    clearance_deadline DATE DEFAULT NULL COMMENT 'Clearance deadline before this installment',
    amount_expected DECIMAL(12,2) DEFAULT 0,
    reminder_message TEXT DEFAULT NULL COMMENT 'Custom message for reminders',
    program_type VARCHAR(50) DEFAULT 'all' COMMENT 'all, weekday, weekend',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deadline_date (deadline_date),
    INDEX idx_academic_year (academic_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS payment_deadline_reminders (
    reminder_id INT AUTO_INCREMENT PRIMARY KEY,
    deadline_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    reminder_type VARCHAR(30) NOT NULL COMMENT '10_days, 5_days, 2_days, on_day, 1_day_after, 2_days_after',
    reminder_message TEXT DEFAULT NULL,
    notification_id INT DEFAULT NULL COMMENT 'Link to vle_notifications',
    email_sent TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deadline_student (deadline_id, student_id),
    INDEX idx_reminder_type (reminder_type),
    FOREIGN KEY (deadline_id) REFERENCES payment_deadlines(deadline_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ==================== HANDLE FORM SUBMISSIONS ====================
$success = '';
$error = '';

// Add new deadline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_deadline'])) {
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = intval($_POST['semester'] ?? 1);
    $installment_type = trim($_POST['installment_type'] ?? '');
    $installment_label = trim($_POST['installment_label'] ?? '');
    $deadline_date = trim($_POST['deadline_date'] ?? '');
    $clearance_deadline = trim($_POST['clearance_deadline'] ?? '') ?: null;
    $amount_expected = floatval($_POST['amount_expected'] ?? 0);
    $reminder_message = trim($_POST['reminder_message'] ?? '');
    $program_type = trim($_POST['program_type'] ?? 'all');
    $created_by = $user['user_id'] ?? null;

    if (empty($academic_year) || empty($installment_type) || empty($installment_label) || empty($deadline_date)) {
        $error = 'Please fill in all required fields.';
    } else {
        $stmt = $conn->prepare("INSERT INTO payment_deadlines 
            (academic_year, semester, installment_type, installment_label, deadline_date, clearance_deadline, amount_expected, reminder_message, program_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssdssi", $academic_year, $semester, $installment_type, $installment_label, $deadline_date, $clearance_deadline, $amount_expected, $reminder_message, $program_type, $created_by);
        if ($stmt->execute()) {
            $success = 'Payment deadline added successfully!';
        } else {
            $error = 'Failed to add deadline: ' . $stmt->error;
        }
    }
}

// Update deadline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_deadline'])) {
    $deadline_id = intval($_POST['deadline_id']);
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = intval($_POST['semester'] ?? 1);
    $installment_type = trim($_POST['installment_type'] ?? '');
    $installment_label = trim($_POST['installment_label'] ?? '');
    $deadline_date = trim($_POST['deadline_date'] ?? '');
    $clearance_deadline = trim($_POST['clearance_deadline'] ?? '') ?: null;
    $amount_expected = floatval($_POST['amount_expected'] ?? 0);
    $reminder_message = trim($_POST['reminder_message'] ?? '');
    $program_type = trim($_POST['program_type'] ?? 'all');

    $stmt = $conn->prepare("UPDATE payment_deadlines SET 
        academic_year=?, semester=?, installment_type=?, installment_label=?, deadline_date=?, 
        clearance_deadline=?, amount_expected=?, reminder_message=?, program_type=?
        WHERE deadline_id=?");
    $stmt->bind_param("sissssdssi", $academic_year, $semester, $installment_type, $installment_label, $deadline_date, $clearance_deadline, $amount_expected, $reminder_message, $program_type, $deadline_id);
    if ($stmt->execute()) {
        $success = 'Deadline updated successfully!';
    } else {
        $error = 'Failed to update: ' . $stmt->error;
    }
}

// Delete deadline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_deadline'])) {
    $deadline_id = intval($_POST['deadline_id']);
    $stmt = $conn->prepare("DELETE FROM payment_deadlines WHERE deadline_id = ?");
    $stmt->bind_param("i", $deadline_id);
    if ($stmt->execute()) {
        $success = 'Deadline deleted.';
    } else {
        $error = 'Failed to delete: ' . $stmt->error;
    }
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $deadline_id = intval($_POST['deadline_id']);
    $conn->query("UPDATE payment_deadlines SET is_active = NOT is_active WHERE deadline_id = $deadline_id");
    $success = 'Deadline status toggled.';
}

// ==================== FETCH DEADLINES ====================
$filter_year = isset($_GET['year']) ? trim($_GET['year']) : '';
$filter_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

$where = [];
$params = [];
$types = '';

if ($filter_year) {
    $where[] = 'academic_year = ?';
    $params[] = $filter_year;
    $types .= 's';
}
if ($filter_semester) {
    $where[] = 'semester = ?';
    $params[] = $filter_semester;
    $types .= 'i';
}

$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT * FROM payment_deadlines $where_sql ORDER BY semester ASC, deadline_date ASC";

if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $deadlines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $result = $conn->query($sql);
    $deadlines = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Get reminder stats
$reminder_stats = [];
foreach ($deadlines as $dl) {
    $rid = $dl['deadline_id'];
    $r = $conn->query("SELECT reminder_type, COUNT(*) as cnt FROM payment_deadline_reminders WHERE deadline_id = $rid GROUP BY reminder_type");
    $reminder_stats[$rid] = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $reminder_stats[$rid][$row['reminder_type']] = $row['cnt'];
        }
    }
}

// Get distinct academic years for filter
$years_result = $conn->query("SELECT DISTINCT academic_year FROM payment_deadlines ORDER BY academic_year DESC");
$academic_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $academic_years[] = $row['academic_year'];
    }
}

// Get fee settings for defaults
$fee_result = $conn->query("SELECT * FROM fee_settings LIMIT 1");
$fee_settings = $fee_result ? $fee_result->fetch_assoc() : null;

$installment_types = [
    'application_fee' => 'Application Fee',
    'registration_fee' => 'Registration Fee',
    'installment_1' => '1st Installment (Tuition)',
    'installment_2' => '2nd Installment (Tuition)',
    'installment_3' => '3rd Installment (Tuition)',
    'installment_4' => 'Final Installment (Tuition)',
    'clearance' => 'Clearance / Full Balance'
];

$reminder_type_labels = [
    '10_days' => '10 Days Before',
    '5_days' => '5 Days Before',
    '2_days' => '2 Days Before',
    'on_day' => 'On Deadline Day',
    '1_day_after' => '1 Day After',
    '2_days_after' => '2 Days After'
];

// Helper function to calculate reminder dates
function getDeadlineReminderDate($deadline_date, $reminder_type) {
    $date = strtotime($deadline_date);
    switch ($reminder_type) {
        case '10_days': return date('Y-m-d', strtotime('-10 days', $date));
        case '5_days': return date('Y-m-d', strtotime('-5 days', $date));
        case '2_days': return date('Y-m-d', strtotime('-2 days', $date));
        case 'on_day': return date('Y-m-d', $date);
        case '1_day_after': return date('Y-m-d', strtotime('+1 day', $date));
        case '2_days_after': return date('Y-m-d', strtotime('+2 days', $date));
        default: return date('Y-m-d', $date);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Deadlines & Reminders - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .deadline-card { border-left: 4px solid #198754; transition: all 0.2s; }
        .deadline-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .deadline-card.inactive { border-left-color: #6c757d; opacity: 0.65; }
        .reminder-badge { font-size: 0.7rem; padding: 2px 6px; }
        .deadline-type-badge { font-size: 0.75rem; }
        .timeline-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .dot-pending { background: #ffc107; }
        .dot-sent { background: #198754; }
        .dot-future { background: #dee2e6; }
        .quick-setup-card { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; }
    </style>
</head>
<body class="bg-light">
    <?php include 'header_nav.php'; ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4 mb-5">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-alarm me-2"></i>Payment Deadlines & Reminders</h1>
                <p class="text-muted mb-0">Set payment deadlines and automated reminder schedules for students</p>
            </div>
            <div class="d-flex gap-2">
                <a href="process_payment_reminders.php" class="btn btn-warning btn-sm">
                    <i class="bi bi-send me-1"></i> Process Reminders Now
                </a>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addDeadlineModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Deadline
                </button>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#quickSetupModal">
                    <i class="bi bi-lightning me-1"></i> Quick Setup
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-1"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-0">Academic Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $y): ?>
                            <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filter_year === $y ? 'selected' : ''; ?>><?php echo htmlspecialchars($y); ?></option>
                            <?php endforeach; ?>
                            <option value="2025/2026" <?php echo $filter_year === '2025/2026' && !in_array('2025/2026', $academic_years) ? 'selected' : ''; ?>>2025/2026</option>
                            <option value="2026/2027" <?php echo $filter_year === '2026/2027' && !in_array('2026/2027', $academic_years) ? 'selected' : ''; ?>>2026/2027</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-0">Semester</label>
                        <select name="semester" class="form-select form-select-sm">
                            <option value="0">All Semesters</option>
                            <option value="1" <?php echo $filter_semester === 1 ? 'selected' : ''; ?>>Semester 1</option>
                            <option value="2" <?php echo $filter_semester === 2 ? 'selected' : ''; ?>>Semester 2</option>
                            <option value="3" <?php echo $filter_semester === 3 ? 'selected' : ''; ?>>Semester 3</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                        <a href="payment_deadlines.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reminder Schedule Legend -->
        <div class="card mb-4">
            <div class="card-body py-2">
                <small class="text-muted"><strong>Reminder Schedule:</strong></small>
                <?php foreach ($reminder_type_labels as $key => $label): ?>
                    <span class="badge bg-secondary reminder-badge ms-1"><?php echo $label; ?></span>
                <?php endforeach; ?>
                <small class="text-muted ms-2">| Reminders are sent automatically via email and in-app notifications.</small>
            </div>
        </div>

        <!-- Deadlines List -->
        <?php if (empty($deadlines)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-calendar-x display-1 text-muted"></i>
                    <h5 class="mt-3 text-muted">No Payment Deadlines Set</h5>
                    <p class="text-muted">Use "Add Deadline" or "Quick Setup" to create payment deadlines for the semester.</p>
                </div>
            </div>
        <?php else: ?>
            <?php 
            // Group by semester
            $grouped = [];
            foreach ($deadlines as $dl) {
                $grouped[$dl['semester']][] = $dl;
            }
            ?>
            <?php foreach ($grouped as $sem => $sem_deadlines): ?>
            <h5 class="mb-3 mt-4"><i class="bi bi-calendar3 me-1"></i> Semester <?php echo $sem; ?></h5>
            <div class="row g-3 mb-4">
                <?php foreach ($sem_deadlines as $dl): 
                    $today = date('Y-m-d');
                    $is_past = $dl['deadline_date'] < $today;
                    $is_today = $dl['deadline_date'] === $today;
                    $days_until = (int)((strtotime($dl['deadline_date']) - strtotime($today)) / 86400);
                ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card deadline-card <?php echo !$dl['is_active'] ? 'inactive' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($dl['installment_label']); ?></h6>
                                    <span class="badge <?php echo $dl['is_active'] ? 'bg-success' : 'bg-secondary'; ?> deadline-type-badge">
                                        <?php echo htmlspecialchars($installment_types[$dl['installment_type']] ?? $dl['installment_type']); ?>
                                    </span>
                                    <?php if ($dl['program_type'] !== 'all'): ?>
                                        <span class="badge bg-info deadline-type-badge"><?php echo ucfirst(htmlspecialchars($dl['program_type'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" onclick="editDeadline(<?php echo htmlspecialchars(json_encode($dl)); ?>)"><i class="bi bi-pencil me-1"></i> Edit</a></li>
                                        <li>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="deadline_id" value="<?php echo $dl['deadline_id']; ?>">
                                                <button type="submit" name="toggle_active" class="dropdown-item">
                                                    <i class="bi bi-toggle-<?php echo $dl['is_active'] ? 'on' : 'off'; ?> me-1"></i> 
                                                    <?php echo $dl['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" onsubmit="return confirm('Delete this deadline and all its reminders?')">
                                                <input type="hidden" name="deadline_id" value="<?php echo $dl['deadline_id']; ?>">
                                                <button type="submit" name="delete_deadline" class="dropdown-item text-danger"><i class="bi bi-trash me-1"></i> Delete</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div class="mb-2">
                                <small class="text-muted">
                                    <i class="bi bi-calendar-event me-1"></i> <strong>Deadline:</strong> 
                                    <?php echo date('d M Y', strtotime($dl['deadline_date'])); ?>
                                    <?php if ($is_today): ?>
                                        <span class="badge bg-danger ms-1">TODAY</span>
                                    <?php elseif ($is_past): ?>
                                        <span class="badge bg-dark ms-1">Passed</span>
                                    <?php elseif ($days_until <= 10): ?>
                                        <span class="badge bg-warning text-dark ms-1"><?php echo $days_until; ?> days left</span>
                                    <?php endif; ?>
                                </small>
                                <?php if ($dl['clearance_deadline']): ?>
                                <br><small class="text-muted">
                                    <i class="bi bi-shield-check me-1"></i> <strong>Clearance:</strong> 
                                    <?php echo date('d M Y', strtotime($dl['clearance_deadline'])); ?>
                                </small>
                                <?php endif; ?>
                            </div>

                            <?php if ($dl['amount_expected'] > 0): ?>
                            <div class="mb-2">
                                <small><strong>Amount:</strong> K<?php echo number_format($dl['amount_expected'], 2); ?></small>
                            </div>
                            <?php endif; ?>

                            <!-- Reminder Timeline -->
                            <div class="mt-2">
                                <small class="text-muted d-block mb-1"><strong>Reminders:</strong></small>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($reminder_type_labels as $rkey => $rlabel): 
                                        $sent_count = $reminder_stats[$dl['deadline_id']][$rkey] ?? 0;
                                        $reminder_date = getDeadlineReminderDate($dl['deadline_date'], $rkey);
                                        $is_sent = $sent_count > 0;
                                        $is_due = $reminder_date <= $today;
                                    ?>
                                        <span class="badge <?php echo $is_sent ? 'bg-success' : ($is_due ? 'bg-warning text-dark' : 'bg-light text-dark border'); ?> reminder-badge" 
                                              title="<?php echo $rlabel . ': ' . date('d M', strtotime($reminder_date)) . ($is_sent ? " - Sent ($sent_count)" : ''); ?>">
                                            <?php echo str_replace(['_days', '_day', 'on_day'], ['d', 'd', 'D-Day'], $rkey); ?>
                                            <?php if ($is_sent): ?><i class="bi bi-check"></i><?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if ($dl['reminder_message']): ?>
                            <div class="mt-2">
                                <small class="text-muted"><i class="bi bi-chat-left-text me-1"></i> <?php echo htmlspecialchars(substr($dl['reminder_message'], 0, 80)); ?><?php echo strlen($dl['reminder_message']) > 80 ? '...' : ''; ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Add Deadline Modal -->
    <div class="modal fade" id="addDeadlineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add Payment Deadline</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" name="academic_year" class="form-control" value="2025/2026" required placeholder="e.g. 2025/2026">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester <span class="text-danger">*</span></label>
                                <select name="semester" class="form-select" required>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                    <option value="3">Semester 3</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Installment Type <span class="text-danger">*</span></label>
                                <select name="installment_type" class="form-select" required onchange="autoLabel(this, 'add')">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($installment_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Custom Label <span class="text-danger">*</span></label>
                                <input type="text" name="installment_label" id="add_label" class="form-control" required placeholder="e.g. Registration Fee Payment">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deadline Date <span class="text-danger">*</span></label>
                                <input type="date" name="deadline_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Clearance Deadline</label>
                                <input type="date" name="clearance_deadline" class="form-control">
                                <small class="text-muted">Optional: clearance cutoff before this installment</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expected Amount (K)</label>
                                <input type="number" step="0.01" name="amount_expected" class="form-control" value="0" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Program Type</label>
                                <select name="program_type" class="form-select">
                                    <option value="all">All Programs</option>
                                    <option value="weekday">Weekday Only</option>
                                    <option value="weekend">Weekend Only</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reminder Message</label>
                                <textarea name="reminder_message" class="form-control" rows="3" placeholder="Custom message to include in reminder emails and notifications..."></textarea>
                                <small class="text-muted">Use {student_name}, {amount}, {deadline_date}, {installment} as placeholders.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_deadline" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i> Add Deadline</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Deadline Modal -->
    <div class="modal fade" id="editDeadlineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="deadline_id" id="edit_deadline_id">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Payment Deadline</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" name="academic_year" id="edit_academic_year" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester <span class="text-danger">*</span></label>
                                <select name="semester" id="edit_semester" class="form-select" required>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                    <option value="3">Semester 3</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Installment Type <span class="text-danger">*</span></label>
                                <select name="installment_type" id="edit_installment_type" class="form-select" required>
                                    <?php foreach ($installment_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Custom Label <span class="text-danger">*</span></label>
                                <input type="text" name="installment_label" id="edit_installment_label" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deadline Date <span class="text-danger">*</span></label>
                                <input type="date" name="deadline_date" id="edit_deadline_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Clearance Deadline</label>
                                <input type="date" name="clearance_deadline" id="edit_clearance_deadline" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expected Amount (K)</label>
                                <input type="number" step="0.01" name="amount_expected" id="edit_amount_expected" class="form-control" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Program Type</label>
                                <select name="program_type" id="edit_program_type" class="form-select">
                                    <option value="all">All Programs</option>
                                    <option value="weekday">Weekday Only</option>
                                    <option value="weekend">Weekend Only</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reminder Message</label>
                                <textarea name="reminder_message" id="edit_reminder_message" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_deadline" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Setup Modal -->
    <div class="modal fade" id="quickSetupModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="quickSetupForm">
                    <div class="modal-header text-white" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                        <h5 class="modal-title"><i class="bi bi-lightning me-1"></i> Quick Setup - All Deadlines</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Quickly set up all payment deadlines for a semester. Fill in dates and the system will create all 6 installment deadlines.</p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Academic Year</label>
                                <input type="text" id="qs_academic_year" class="form-control" value="2025/2026" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Semester</label>
                                <select id="qs_semester" class="form-select" required>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                    <option value="3">Semester 3</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Program Type</label>
                                <select id="qs_program_type" class="form-select">
                                    <option value="all">All Programs</option>
                                    <option value="weekday">Weekday</option>
                                    <option value="weekend">Weekend</option>
                                </select>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Installment</th>
                                        <th>Deadline Date</th>
                                        <th>Clearance Date</th>
                                        <th>Amount (K)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Application Fee</td>
                                        <td><input type="date" id="qs_app_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_app_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_app_amount" class="form-control form-control-sm" value="<?php echo $fee_settings['application_fee'] ?? 5500; ?>"></td>
                                    </tr>
                                    <tr>
                                        <td>Registration Fee</td>
                                        <td><input type="date" id="qs_reg_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_reg_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_reg_amount" class="form-control form-control-sm" value="<?php echo $fee_settings['registration_fee'] ?? 39500; ?>"></td>
                                    </tr>
                                    <tr>
                                        <td>1st Installment</td>
                                        <td><input type="date" id="qs_inst1_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_inst1_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_inst1_amount" class="form-control form-control-sm" value="<?php echo ($fee_settings['tuition_degree'] ?? 500000) / 4; ?>"></td>
                                    </tr>
                                    <tr>
                                        <td>2nd Installment</td>
                                        <td><input type="date" id="qs_inst2_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_inst2_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_inst2_amount" class="form-control form-control-sm" value="<?php echo ($fee_settings['tuition_degree'] ?? 500000) / 4; ?>"></td>
                                    </tr>
                                    <tr>
                                        <td>3rd Installment</td>
                                        <td><input type="date" id="qs_inst3_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_inst3_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_inst3_amount" class="form-control form-control-sm" value="<?php echo ($fee_settings['tuition_degree'] ?? 500000) / 4; ?>"></td>
                                    </tr>
                                    <tr>
                                        <td>Final Installment</td>
                                        <td><input type="date" id="qs_inst4_date" class="form-control form-control-sm"></td>
                                        <td><input type="date" id="qs_inst4_clear" class="form-control form-control-sm"></td>
                                        <td><input type="number" id="qs_inst4_amount" class="form-control form-control-sm" value="<?php echo ($fee_settings['tuition_degree'] ?? 500000) / 4; ?>"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info small mt-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i> <strong>Tip:</strong> The final installment deadline should be set to 5 days before end-of-semester exams. Leave a date blank to skip that installment.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="submitQuickSetup()"><i class="bi bi-lightning me-1"></i> Create All Deadlines</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function autoLabel(select, mode) {
        const label = select.options[select.selectedIndex].text;
        if (mode === 'add') {
            document.getElementById('add_label').value = label + ' Payment';
        }
    }

    function editDeadline(dl) {
        document.getElementById('edit_deadline_id').value = dl.deadline_id;
        document.getElementById('edit_academic_year').value = dl.academic_year;
        document.getElementById('edit_semester').value = dl.semester;
        document.getElementById('edit_installment_type').value = dl.installment_type;
        document.getElementById('edit_installment_label').value = dl.installment_label;
        document.getElementById('edit_deadline_date').value = dl.deadline_date;
        document.getElementById('edit_clearance_deadline').value = dl.clearance_deadline || '';
        document.getElementById('edit_amount_expected').value = dl.amount_expected;
        document.getElementById('edit_program_type').value = dl.program_type;
        document.getElementById('edit_reminder_message').value = dl.reminder_message || '';
        new bootstrap.Modal(document.getElementById('editDeadlineModal')).show();
    }

    function submitQuickSetup() {
        const year = document.getElementById('qs_academic_year').value;
        const semester = document.getElementById('qs_semester').value;
        const programType = document.getElementById('qs_program_type').value;

        const items = [
            { type: 'application_fee', label: 'Application Fee Payment', date: 'qs_app_date', clear: 'qs_app_clear', amount: 'qs_app_amount' },
            { type: 'registration_fee', label: 'Registration Fee Payment', date: 'qs_reg_date', clear: 'qs_reg_clear', amount: 'qs_reg_amount' },
            { type: 'installment_1', label: '1st Installment Payment', date: 'qs_inst1_date', clear: 'qs_inst1_clear', amount: 'qs_inst1_amount' },
            { type: 'installment_2', label: '2nd Installment Payment', date: 'qs_inst2_date', clear: 'qs_inst2_clear', amount: 'qs_inst2_amount' },
            { type: 'installment_3', label: '3rd Installment Payment', date: 'qs_inst3_date', clear: 'qs_inst3_clear', amount: 'qs_inst3_amount' },
            { type: 'installment_4', label: 'Final Installment Payment', date: 'qs_inst4_date', clear: 'qs_inst4_clear', amount: 'qs_inst4_amount' },
        ];

        // Create a hidden form and submit each
        let count = 0;
        const forms = [];
        items.forEach(item => {
            const date = document.getElementById(item.date).value;
            if (!date) return; // skip empty dates

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const fields = {
                'add_deadline': '1',
                'academic_year': year,
                'semester': semester,
                'installment_type': item.type,
                'installment_label': item.label,
                'deadline_date': date,
                'clearance_deadline': document.getElementById(item.clear).value || '',
                'amount_expected': document.getElementById(item.amount).value || '0',
                'reminder_message': '',
                'program_type': programType
            };

            for (const [key, val] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = val;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            forms.push(form);
            count++;
        });

        if (count === 0) {
            alert('Please set at least one deadline date.');
            return;
        }

        // Submit forms sequentially via fetch
        const submitSequentially = async () => {
            for (const form of forms) {
                const formData = new FormData(form);
                await fetch(window.location.href, { method: 'POST', body: formData });
                form.remove();
            }
            window.location.reload();
        };
        submitSequentially();
    }
    </script>
</body>
</html>
