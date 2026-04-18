<?php
/**
 * Dean Portal - Settings
 * Manage academic settings for the faculty
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$dean_faculty_id = $user['related_dean_id'] ?? null;

// Ensure dean_settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS dean_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_label VARCHAR(255),
    setting_group VARCHAR(100) DEFAULT 'general',
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key),
    INDEX idx_setting_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Default settings
$default_settings = [
    ['current_academic_year', date('Y') . '/' . (date('Y') + 1), 'Current Academic Year', 'academic'],
    ['current_semester', '1', 'Current Semester', 'academic'],
    ['semester_start_date', date('Y-m-01'), 'Semester Start Date', 'academic'],
    ['semester_end_date', date('Y-m-d', strtotime('+4 months')), 'Semester End Date', 'academic'],
    ['weekday_class_days', 'Monday,Tuesday,Wednesday,Thursday,Friday', 'Weekday Program Class Days', 'schedule'],
    ['weekend_class_days', 'Saturday,Sunday', 'Weekend Program Class Days', 'schedule'],
    ['weekday_class_start', '08:00', 'Weekday Classes Start Time', 'schedule'],
    ['weekday_class_end', '17:00', 'Weekday Classes End Time', 'schedule'],
    ['weekend_class_start', '08:00', 'Weekend Classes Start Time', 'schedule'],
    ['weekend_class_end', '17:00', 'Weekend Classes End Time', 'schedule'],
    ['exam_period_weeks', '3', 'Exam Period (Weeks)', 'academic'],
    ['registration_open', '1', 'Course Registration Open', 'registration'],
    ['late_registration_allowed', '0', 'Allow Late Registration', 'registration'],
    ['max_courses_per_semester', '8', 'Max Courses Per Semester', 'registration'],
    ['min_courses_per_semester', '4', 'Min Courses Per Semester', 'registration'],
    ['dean_message', '', 'Dean\'s Welcome Message', 'general'],
    ['faculty_notice', '', 'Faculty Notice Board', 'general'],
];

// Insert defaults if not exist
foreach ($default_settings as $ds) {
    $check = $conn->prepare("SELECT setting_id FROM dean_settings WHERE setting_key = ?");
    $check->bind_param("s", $ds[0]);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $ins = $conn->prepare("INSERT INTO dean_settings (setting_key, setting_value, setting_label, setting_group) VALUES (?, ?, ?, ?)");
        $ins->bind_param("ssss", $ds[0], $ds[1], $ds[2], $ds[3]);
        $ins->execute();
    }
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $updated = 0;
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $setting_key = substr($key, 8); // Remove 'setting_' prefix
            $setting_value = trim($value);
            $stmt = $conn->prepare("UPDATE dean_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
            $stmt->bind_param("sis", $setting_value, $user['user_id'], $setting_key);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $updated++;
            }
        }
    }
    $success = $updated > 0 ? "Settings updated successfully ($updated changes)." : "No changes were made.";
}

// Load all settings
$settings = [];
$result = $conn->query("SELECT * FROM dean_settings ORDER BY setting_group, setting_key");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row;
    }
}

// Helper to get setting value
function getSetting($key) {
    global $settings;
    return $settings[$key]['setting_value'] ?? '';
}

// Get faculty name
$faculty_name = 'All Faculties';
if ($dean_faculty_id) {
    $result = $conn->query("SELECT faculty_name FROM faculties WHERE faculty_id = " . (int)$dean_faculty_id);
    if ($result && $row = $result->fetch_assoc()) {
        $faculty_name = $row['faculty_name'];
    }
}

$page_title = "Settings";
$breadcrumbs = [['title' => 'Settings']];
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
        .settings-group { margin-bottom: 2rem; }
        .settings-group-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a472a;
            border-bottom: 2px solid #1a472a;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .setting-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: #fff;
            transition: box-shadow 0.2s;
        }
        .setting-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
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
                <h3 class="fw-bold mb-1"><i class="bi bi-gear me-2"></i>Dean Settings</h3>
                <p class="text-muted mb-0">Manage academic and scheduling settings for <?= htmlspecialchars($faculty_name) ?></p>
            </div>
        </div>

        <form method="POST">
            <!-- Academic Settings -->
            <div class="settings-group">
                <div class="settings-group-title"><i class="bi bi-mortarboard me-2"></i>Academic Settings</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Current Academic Year</label>
                            <input type="text" class="form-control" name="setting_current_academic_year" value="<?= htmlspecialchars(getSetting('current_academic_year')) ?>" placeholder="e.g. 2025/2026">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Current Semester</label>
                            <select class="form-select" name="setting_current_semester">
                                <option value="1" <?= getSetting('current_semester') == '1' ? 'selected' : '' ?>>Semester 1</option>
                                <option value="2" <?= getSetting('current_semester') == '2' ? 'selected' : '' ?>>Semester 2</option>
                                <option value="3" <?= getSetting('current_semester') == '3' ? 'selected' : '' ?>>Semester 3 (Summer)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Semester Start Date</label>
                            <input type="date" class="form-control" name="setting_semester_start_date" value="<?= htmlspecialchars(getSetting('semester_start_date')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Semester End Date</label>
                            <input type="date" class="form-control" name="setting_semester_end_date" value="<?= htmlspecialchars(getSetting('semester_end_date')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Exam Period (Weeks)</label>
                            <input type="number" class="form-control" name="setting_exam_period_weeks" value="<?= htmlspecialchars(getSetting('exam_period_weeks')) ?>" min="1" max="8">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule Settings -->
            <div class="settings-group">
                <div class="settings-group-title"><i class="bi bi-clock me-2"></i>Class Schedule Settings</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekday Program - Class Days</label>
                            <input type="text" class="form-control" name="setting_weekday_class_days" value="<?= htmlspecialchars(getSetting('weekday_class_days')) ?>" placeholder="Monday,Tuesday,Wednesday,Thursday,Friday">
                            <small class="text-muted">Comma-separated day names</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekend Program - Class Days</label>
                            <input type="text" class="form-control" name="setting_weekend_class_days" value="<?= htmlspecialchars(getSetting('weekend_class_days')) ?>" placeholder="Saturday,Sunday">
                            <small class="text-muted">Comma-separated day names</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekday Start Time</label>
                            <input type="time" class="form-control" name="setting_weekday_class_start" value="<?= htmlspecialchars(getSetting('weekday_class_start')) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekday End Time</label>
                            <input type="time" class="form-control" name="setting_weekday_class_end" value="<?= htmlspecialchars(getSetting('weekday_class_end')) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekend Start Time</label>
                            <input type="time" class="form-control" name="setting_weekend_class_start" value="<?= htmlspecialchars(getSetting('weekend_class_start')) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Weekend End Time</label>
                            <input type="time" class="form-control" name="setting_weekend_class_end" value="<?= htmlspecialchars(getSetting('weekend_class_end')) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Registration Settings -->
            <div class="settings-group">
                <div class="settings-group-title"><i class="bi bi-journal-plus me-2"></i>Registration Settings</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Course Registration</label>
                            <select class="form-select" name="setting_registration_open">
                                <option value="1" <?= getSetting('registration_open') == '1' ? 'selected' : '' ?>>Open</option>
                                <option value="0" <?= getSetting('registration_open') == '0' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Allow Late Registration</label>
                            <select class="form-select" name="setting_late_registration_allowed">
                                <option value="1" <?= getSetting('late_registration_allowed') == '1' ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= getSetting('late_registration_allowed') == '0' ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Max Courses Per Semester</label>
                            <input type="number" class="form-control" name="setting_max_courses_per_semester" value="<?= htmlspecialchars(getSetting('max_courses_per_semester')) ?>" min="1" max="20">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Min Courses Per Semester</label>
                            <input type="number" class="form-control" name="setting_min_courses_per_semester" value="<?= htmlspecialchars(getSetting('min_courses_per_semester')) ?>" min="1" max="20">
                        </div>
                    </div>
                </div>
            </div>

            <!-- General Settings -->
            <div class="settings-group">
                <div class="settings-group-title"><i class="bi bi-chat-square-text me-2"></i>General</div>
                <div class="row g-3">
                    <div class="col-md-12">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Dean's Welcome Message</label>
                            <textarea class="form-control" name="setting_dean_message" rows="3" placeholder="A welcome message displayed to students and staff"><?= htmlspecialchars(getSetting('dean_message')) ?></textarea>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="setting-card">
                            <label class="form-label fw-semibold">Faculty Notice Board</label>
                            <textarea class="form-control" name="setting_faculty_notice" rows="3" placeholder="Important notices for the faculty"><?= htmlspecialchars(getSetting('faculty_notice')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" name="update_settings" class="btn btn-success btn-lg">
                    <i class="bi bi-check-lg me-1"></i> Save All Settings
                </button>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </form>

        <!-- Current Settings Summary -->
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Current Academic Period Summary</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-light rounded">
                            <i class="bi bi-calendar3 text-primary" style="font-size: 1.5rem;"></i>
                            <h6 class="mt-2 mb-0"><?= htmlspecialchars(getSetting('current_academic_year')) ?></h6>
                            <small class="text-muted">Academic Year</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-light rounded">
                            <i class="bi bi-bookmark text-success" style="font-size: 1.5rem;"></i>
                            <h6 class="mt-2 mb-0">Semester <?= htmlspecialchars(getSetting('current_semester')) ?></h6>
                            <small class="text-muted">Current Semester</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-light rounded">
                            <i class="bi bi-door-open text-<?= getSetting('registration_open') == '1' ? 'success' : 'danger' ?>" style="font-size: 1.5rem;"></i>
                            <h6 class="mt-2 mb-0"><?= getSetting('registration_open') == '1' ? 'Open' : 'Closed' ?></h6>
                            <small class="text-muted">Registration Status</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-light rounded">
                            <i class="bi bi-journal-check text-info" style="font-size: 1.5rem;"></i>
                            <h6 class="mt-2 mb-0"><?= htmlspecialchars(getSetting('exam_period_weeks')) ?> Weeks</h6>
                            <small class="text-muted">Exam Period</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
