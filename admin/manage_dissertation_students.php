<?php
/**
 * Admin - Manage Dissertation Students
 *
 * Central hub for managing dissertation-only students:
 * - View all dissertation students with details
 * - Reset passwords
 * - Update campus
 * - Convert to full student
 * - View student details
 */
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();

$message = '';
$error = '';

$valid_campuses = ['Mzuzu Campus', 'Lilongwe Campus', 'Blantyre Campus', 'ODel Campus'];

// AJAX: auto-save campus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_campus') {
    header('Content-Type: application/json');
    $user_id = (int)($_POST['user_id'] ?? 0);
    $student_id = trim($_POST['student_id'] ?? '');
    $campus = trim($_POST['campus'] ?? '');
    if ($user_id <= 0 || empty($student_id) || !in_array($campus, $valid_campuses, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        exit;
    }
    $upd = $conn->prepare("UPDATE students SET campus = ? WHERE student_id = ?");
    $upd->bind_param('ss', $campus, $student_id);
    if ($upd->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database update failed.']);
    }
    $upd->close();
    exit;
}

// AJAX: auto-save student number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_student_id') {
    header('Content-Type: application/json');
    $user_id = (int)($_POST['user_id'] ?? 0);
    $old_student_id = trim($_POST['old_student_id'] ?? '');
    $new_student_id = trim($_POST['new_student_id'] ?? '');
    if ($user_id <= 0 || empty($new_student_id)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        exit;
    }
    if ($new_student_id === $old_student_id) {
        echo json_encode(['success' => true]);
        exit;
    }
    $conn->begin_transaction();
    try {
        $chk = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND student_id != ?");
        $chk->bind_param('ss', $new_student_id, $old_student_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            throw new Exception('Student number already exists.');
        }
        $chk->close();
        if (!empty($old_student_id)) {
            $upd = $conn->prepare("UPDATE students SET student_id = ? WHERE student_id = ?");
            $upd->bind_param('ss', $new_student_id, $old_student_id); $upd->execute(); $upd->close();
            $upd = $conn->prepare("UPDATE student_finances SET student_id = ? WHERE student_id = ?");
            $upd->bind_param('ss', $new_student_id, $old_student_id); $upd->execute(); $upd->close();
            $upd = $conn->prepare("UPDATE vle_enrollments SET student_id = ? WHERE student_id = ?");
            $upd->bind_param('ss', $new_student_id, $old_student_id); $upd->execute(); $upd->close();
        }
        $upd = $conn->prepare("UPDATE users SET related_student_id = ? WHERE user_id = ?");
        $upd->bind_param('si', $new_student_id, $user_id); $upd->execute(); $upd->close();
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: auto-save year/semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_update_year_semester') {
    header('Content-Type: application/json');
    $student_id = trim($_POST['student_id'] ?? '');
    $year_of_study = (int)($_POST['year_of_study'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);
    $valid_years = [1,2,3,4,5,6];
    $valid_semesters = [1,2,3];
    if (empty($student_id) || !in_array($year_of_study, $valid_years, true) || !in_array($semester, $valid_semesters, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        exit;
    }
    $upd = $conn->prepare("UPDATE students SET year_of_study = ?, semester = ? WHERE student_id = ?");
    $upd->bind_param('iis', $year_of_study, $semester, $student_id);
    if ($upd->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database update failed.']);
    }
    $upd->close();
    exit;
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } else {
        $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE user_id = ? AND additional_roles LIKE '%dissertation_student%' LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) {
            $error = 'Dissertation student user not found.';
        } else {
            $new_password = 'password123';
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?");
            $upd->bind_param('si', $hash, $user_id);
            if ($upd->execute()) {
                $message = "Password reset for <strong>" . htmlspecialchars($u['username']) . "</strong>. Temporary password: <code>{$new_password}</code> (must change on next login).";
            } else {
                $error = 'Failed to reset password.';
            }
            $upd->close();
        }
    }
}

// Handle update details (campus + student number) - single student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_details') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $old_student_id = trim($_POST['old_student_id'] ?? '');
    $new_student_id = trim($_POST['new_student_id'] ?? '');
    $new_campus = trim($_POST['campus'] ?? '');
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } elseif (!in_array($new_campus, $valid_campuses, true)) {
        $error = 'Invalid campus selected.';
    } elseif (empty($new_student_id)) {
        $error = 'Student number cannot be empty.';
    } else {
        $conn->begin_transaction();
        try {
            $changes = [];
            // Update campus
            if (!empty($old_student_id)) {
                $upd = $conn->prepare("UPDATE students SET campus = ? WHERE student_id = ?");
                $upd->bind_param('ss', $new_campus, $old_student_id);
                $upd->execute();
                $upd->close();
                $changes[] = 'campus';
            }
            // Update student number if changed
            if ($new_student_id !== $old_student_id) {
                // Check for duplicate
                $chk = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND student_id != ?");
                $chk->bind_param('ss', $new_student_id, $old_student_id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Student number "' . $new_student_id . '" already exists.');
                }
                $chk->close();
                // Update students table
                if (!empty($old_student_id)) {
                    $upd = $conn->prepare("UPDATE students SET student_id = ? WHERE student_id = ?");
                    $upd->bind_param('ss', $new_student_id, $old_student_id);
                    $upd->execute();
                    $upd->close();
                    // Update related tables
                    $upd = $conn->prepare("UPDATE student_finances SET student_id = ? WHERE student_id = ?");
                    $upd->bind_param('ss', $new_student_id, $old_student_id);
                    $upd->execute();
                    $upd->close();
                    $upd = $conn->prepare("UPDATE vle_enrollments SET student_id = ? WHERE student_id = ?");
                    $upd->bind_param('ss', $new_student_id, $old_student_id);
                    $upd->execute();
                    $upd->close();
                }
                // Update users table
                $upd = $conn->prepare("UPDATE users SET related_student_id = ? WHERE user_id = ?");
                $upd->bind_param('si', $new_student_id, $user_id);
                $upd->execute();
                $upd->close();
                $changes[] = 'student number';
            }
            $conn->commit();
            $message = "Updated " . implode(' & ', $changes) . " for <strong>" . htmlspecialchars($new_student_id) . "</strong>.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

// Handle bulk update (all students at once)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
    $students_data = $_POST['students'] ?? [];
    if (!is_array($students_data) || empty($students_data)) {
        $error = 'No student data to update.';
    } else {
        $updated = 0;
        $skipped = 0;
        $bulk_errors = [];
        foreach ($students_data as $uid => $data) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            $old_sid = trim($data['old_student_id'] ?? '');
            $new_sid = trim($data['new_student_id'] ?? '');
            $campus = trim($data['campus'] ?? '');
            if (empty($new_sid) || !in_array($campus, $valid_campuses, true)) { $skipped++; continue; }
            // Skip if nothing changed - fetch current values
            $chk = $conn->prepare("SELECT u.related_student_id, s.campus FROM users u LEFT JOIN students s ON u.related_student_id = s.student_id WHERE u.user_id = ?");
            $chk->bind_param('i', $uid);
            $chk->execute();
            $cur = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($cur && $cur['related_student_id'] === $new_sid && $cur['campus'] === $campus) { $skipped++; continue; }

            $conn->begin_transaction();
            try {
                if (!empty($old_sid)) {
                    $upd = $conn->prepare("UPDATE students SET campus = ? WHERE student_id = ?");
                    $upd->bind_param('ss', $campus, $old_sid);
                    $upd->execute();
                    $upd->close();
                }
                if ($new_sid !== $old_sid) {
                    $dup = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND student_id != ?");
                    $dup->bind_param('ss', $new_sid, $old_sid);
                    $dup->execute();
                    if ($dup->get_result()->num_rows > 0) {
                        $dup->close();
                        throw new Exception("Student number \"{$new_sid}\" already exists.");
                    }
                    $dup->close();
                    if (!empty($old_sid)) {
                        $upd = $conn->prepare("UPDATE students SET student_id = ? WHERE student_id = ?");
                        $upd->bind_param('ss', $new_sid, $old_sid); $upd->execute(); $upd->close();
                        $upd = $conn->prepare("UPDATE student_finances SET student_id = ? WHERE student_id = ?");
                        $upd->bind_param('ss', $new_sid, $old_sid); $upd->execute(); $upd->close();
                        $upd = $conn->prepare("UPDATE vle_enrollments SET student_id = ? WHERE student_id = ?");
                        $upd->bind_param('ss', $new_sid, $old_sid); $upd->execute(); $upd->close();
                    }
                    $upd = $conn->prepare("UPDATE users SET related_student_id = ? WHERE user_id = ?");
                    $upd->bind_param('si', $new_sid, $uid); $upd->execute(); $upd->close();
                }
                $conn->commit();
                $updated++;
            } catch (Throwable $e) {
                $conn->rollback();
                $bulk_errors[] = "User #{$uid}: " . $e->getMessage();
            }
        }
        if ($updated > 0) {
            $message = "<strong>{$updated}</strong> student(s) updated successfully." . ($skipped > 0 ? " {$skipped} unchanged." : '');
        }
        if (!empty($bulk_errors)) {
            $error = implode('<br>', array_map('htmlspecialchars', $bulk_errors));
        }
    }
}

// Handle conversion to full student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } else {
        $stmt = $conn->prepare("SELECT user_id, username, related_student_id, additional_roles FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) {
            $error = 'User not found.';
        } else {
            $roles = array_filter(array_map('trim', explode(',', $u['additional_roles'] ?? '')));
            $roles = array_filter($roles, function($r) { return $r !== 'dissertation_student'; });
            $new_roles = implode(', ', $roles);
            $upd = $conn->prepare("UPDATE users SET additional_roles = ? WHERE user_id = ?");
            $upd->bind_param('si', $new_roles, $user_id);
            if ($upd->execute()) {
                $message = "User <strong>" . htmlspecialchars($u['username']) . "</strong> converted to full student.";
                if (!empty($u['related_student_id'])) {
                    $check = $conn->prepare("SELECT finance_id FROM student_finances WHERE student_id = ? LIMIT 1");
                    $check->bind_param('s', $u['related_student_id']);
                    $check->execute();
                    if ($check->get_result()->num_rows === 0) {
                        $ins = $conn->prepare("INSERT INTO student_finances (student_id, expected_total, expected_tuition, total_paid, balance, payment_percentage, content_access_weeks) VALUES (?, 0, 0, 0, 0, 0, 0)");
                        $ins->bind_param('s', $u['related_student_id']);
                        $ins->execute();
                        $ins->close();
                    }
                    $check->close();
                }
            } else {
                $error = 'Failed to convert student.';
            }
            $upd->close();
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $student_id = trim($_POST['student_id'] ?? '');
    if ($user_id <= 0) {
        $error = 'Invalid user selected.';
    } else {
        $conn->begin_transaction();
        try {
            if (!empty($student_id)) {
                $del = $conn->prepare("DELETE FROM student_finances WHERE student_id = ?");
                $del->bind_param('s', $student_id); $del->execute(); $del->close();
                $del = $conn->prepare("DELETE FROM vle_enrollments WHERE student_id = ?");
                $del->bind_param('s', $student_id); $del->execute(); $del->close();
                $del = $conn->prepare("DELETE FROM students WHERE student_id = ?");
                $del->bind_param('s', $student_id); $del->execute(); $del->close();
            }
            $del = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $del->bind_param('i', $user_id); $del->execute(); $del->close();
            $conn->commit();
            $message = "Dissertation student account deleted successfully.";
        } catch (Throwable $e) {
            $conn->rollback();
            $error = "Failed to delete: " . $e->getMessage();
        }
    }
}

// Search / filter
$search = trim($_GET['search'] ?? '');
$campus_filter = trim($_GET['campus'] ?? '');

$where_extra = '';
$params = [];
$types = '';
if (!empty($search)) {
    $where_extra .= " AND (u.username LIKE ? OR u.email LIKE ? OR s.full_name LIKE ? OR u.related_student_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}
if (!empty($campus_filter) && in_array($campus_filter, $valid_campuses, true)) {
    $where_extra .= " AND s.campus = ?";
    $params[] = $campus_filter;
    $types .= 's';
}

$sql = "SELECT u.user_id, u.username, u.email, u.related_student_id, u.additional_roles, u.created_at as user_created,
    s.full_name, s.campus, s.program, s.department, s.phone, s.gender, s.year_of_study, s.semester, s.student_type,
    d.department_name
    FROM users u
    LEFT JOIN students s ON u.related_student_id = s.student_id
    LEFT JOIN departments d ON s.department = d.department_id
    WHERE u.additional_roles LIKE '%dissertation_student%'" . $where_extra . "
    ORDER BY u.user_id DESC";

$rows = [];
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
} else {
    $result = $conn->query($sql);
    if ($result) { while ($row = $result->fetch_assoc()) $rows[] = $row; }
}

$total_count = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Dissertation Students - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .diss-card {
            background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;
            overflow: hidden; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s;
        }
        .diss-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .diss-card .card-head {
            padding: 14px 20px; display: flex; justify-content: space-between; align-items: center;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .diss-card .card-body { padding: 16px 20px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .info-item .label { color: #94a3b8; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .info-item .value { color: #1e293b; font-weight: 500; font-size: 0.85rem; }
        .action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .campus-select { min-width: 150px; transition: background-color 0.4s, border-color 0.4s; }
        .campus-select.saving { opacity: 0.6; pointer-events: none; }
        .campus-select.saved { background-color: #d1fae5 !important; border-color: #10b981 !important; }
        .campus-select.save-error { background-color: #fee2e2 !important; border-color: #ef4444 !important; }
        .student-id-input { transition: background-color 0.4s, border-color 0.4s; }
        .student-id-input.saved { background-color: #d1fae5 !important; border-color: #10b981 !important; }
        .student-id-input.save-error { background-color: #fee2e2 !important; border-color: #ef4444 !important; }
        .save-sid-btn { font-size: 0.75rem; line-height: 1; }
        .year-select, .semester-select { transition: background-color 0.4s, border-color 0.4s; min-width: 68px; }
        .year-select.saving, .semester-select.saving { opacity: 0.6; pointer-events: none; }
        .year-select.saved, .semester-select.saved { background-color: #d1fae5 !important; border-color: #10b981 !important; }
        .year-select.save-error, .semester-select.save-error { background-color: #fee2e2 !important; border-color: #ef4444 !important; }
        .top-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; }
        .top-bar .form-control, .top-bar .form-select { max-width: 250px; }
        .badge-diss { background: #faf5ff; color: #7c3aed; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; border: 1px solid #ddd6fe; }
    </style>
</head>
<body>
<?php
$breadcrumbs = [['title' => 'Manage Dissertation Students']];
include 'header_nav.php';
?>

<div class="vle-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-mortarboard me-2" style="color:#7c3aed;"></i>Manage Dissertation Students</h4>
            <p class="text-muted mb-0" style="font-size:0.85rem;">View, manage, reset passwords, update campus, and convert dissertation-only students</p>
        </div>
        <div class="d-flex gap-2">
            <a href="student_invite_links.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg me-1"></i>Invite Links</a>
            <a href="convert_dissertation_students.php" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Convert Page</a>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius:10px;"><i class="bi bi-check-circle me-2"></i><?= $message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="border-radius:10px;"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Search & Filter -->
    <form method="GET" class="top-bar">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, email, ID..." value="<?= htmlspecialchars($search) ?>">
        <select name="campus" class="form-select form-select-sm">
            <option value="">All Campuses</option>
            <?php foreach ($valid_campuses as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $campus_filter === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
        <?php if (!empty($search) || !empty($campus_filter)): ?>
        <a href="manage_dissertation_students.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Clear</a>
        <?php endif; ?>
        <span class="text-muted" style="font-size:0.85rem;"><strong><?= $total_count ?></strong> student(s)</span>
    </form>



    <?php if (empty($rows)): ?>
    <div class="text-center py-5">
        <i class="bi bi-mortarboard" style="font-size:3rem;color:#cbd5e1;"></i>
        <p class="text-muted mt-2">No dissertation-only students found.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
    <div class="diss-card">
        <div class="card-head">
            <div class="d-flex align-items-center gap-3">
                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#a855f7,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">
                    <?= strtoupper(substr($row['full_name'] ?? $row['username'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight:600;font-size:0.95rem;"><?= htmlspecialchars($row['full_name'] ?? $row['username']) ?></div>
                    <div style="font-size:0.8rem;color:#64748b;">
                        <?= htmlspecialchars($row['email']) ?>
                        <?php if (!empty($row['phone'])): ?> &bull; <?= htmlspecialchars($row['phone']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <span class="badge-diss"><i class="bi bi-mortarboard me-1"></i>Dissertation Only</span>
        </div>
        <div class="card-body">
            <div class="info-grid mb-3">
                <div class="info-item">
                    <div class="label">Username</div>
                    <div class="value"><?= htmlspecialchars($row['username']) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Student Number</div>
                    <div class="value">
                        <div class="d-flex align-items-center gap-1">
                            <input type="text" class="form-control form-control-sm student-id-input" data-user-id="<?= (int)$row['user_id'] ?>" data-original="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>" style="max-width:170px;font-weight:600;color:#7c3aed;" value="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>">
                            <button type="button" class="btn btn-sm btn-outline-primary save-sid-btn" data-user-id="<?= (int)$row['user_id'] ?>" style="display:none;padding:2px 8px;" title="Save student number"><i class="bi bi-check-lg"></i></button>
                        </div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Department</div>
                    <div class="value"><?= htmlspecialchars($row['department_name'] ?? 'Not set') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Program</div>
                    <div class="value"><?= htmlspecialchars($row['program'] ?? 'Not set') ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Campus</div>
                    <div class="value">
                        <select class="form-select form-select-sm campus-select" data-user-id="<?= (int)$row['user_id'] ?>" data-student-id="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>">
                            <?php foreach ($valid_campuses as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($row['campus'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Year / Semester</div>
                    <div class="value">
                        <div class="d-flex align-items-center gap-1">
                            <select class="form-select form-select-sm year-select" data-student-id="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>" title="Year of Study">
                                <?php for ($y = 1; $y <= 6; $y++): ?>
                                <option value="<?= $y ?>" <?= (int)($row['year_of_study'] ?? 0) === $y ? 'selected' : '' ?>>Yr <?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select form-select-sm semester-select" data-student-id="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>" title="Semester">
                                <?php for ($sem = 1; $sem <= 3; $sem++): ?>
                                <option value="<?= $sem ?>" <?= (int)($row['semester'] ?? 0) === $sem ? 'selected' : '' ?>>Sem <?= $sem ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Gender</div>
                    <div class="value"><?= htmlspecialchars($row['gender'] ?? 'N/A') ?></div>
                </div>
            </div>

            <hr style="border-color:#e2e8f0;">
            <div class="action-btns">
                <!-- Reset Password -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Reset password for <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>?');">
                        <i class="bi bi-key me-1"></i>Reset Password
                    </button>
                </form>
                <!-- Convert to Full Student -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="convert_student">
                    <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Convert <?= htmlspecialchars($row['username'], ENT_QUOTES) ?> to a full student? This removes dissertation-only restriction.');">
                        <i class="bi bi-arrow-repeat me-1"></i>Convert to Full
                    </button>
                </form>
                <!-- Delete -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($row['related_student_id'] ?? '') ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('DELETE <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? This removes the user, student record, and finance data. This cannot be undone!');">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.campus-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var select = this;
        var userId = select.dataset.userId;
        var studentId = select.dataset.studentId;
        var campus = select.value;
        if (!userId || !studentId) return;

        select.classList.add('saving');
        select.classList.remove('saved', 'save-error');

        var fd = new FormData();
        fd.append('action', 'ajax_update_campus');
        fd.append('user_id', userId);
        fd.append('student_id', studentId);
        fd.append('campus', campus);

        fetch('manage_dissertation_students.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                select.classList.remove('saving');
                if (data.success) {
                    select.classList.add('saved');
                    setTimeout(function() { select.classList.remove('saved'); }, 2000);
                } else {
                    select.classList.add('save-error');
                    setTimeout(function() { select.classList.remove('save-error'); }, 3000);
                    alert('Save failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function() {
                select.classList.remove('saving');
                select.classList.add('save-error');
                setTimeout(function() { select.classList.remove('save-error'); }, 3000);
                alert('Network error. Please try again.');
            });
    });
});

// Student number: show save button when changed
document.querySelectorAll('.student-id-input').forEach(function(inp) {
    var uid = inp.dataset.userId;
    var btn = document.querySelector('.save-sid-btn[data-user-id="' + uid + '"]');
    inp.addEventListener('input', function() {
        if (inp.value.trim() !== inp.dataset.original) {
            btn.style.display = 'inline-block';
        } else {
            btn.style.display = 'none';
        }
    });
});

// Student number: AJAX save
document.querySelectorAll('.save-sid-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var uid = btn.dataset.userId;
        var inp = document.querySelector('.student-id-input[data-user-id="' + uid + '"]');
        var oldId = inp.dataset.original;
        var newId = inp.value.trim();
        if (!newId) { alert('Student number cannot be empty.'); return; }
        if (newId === oldId) { btn.style.display = 'none'; return; }

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        inp.classList.remove('saved', 'save-error');

        var fd = new FormData();
        fd.append('action', 'ajax_update_student_id');
        fd.append('user_id', uid);
        fd.append('old_student_id', oldId);
        fd.append('new_student_id', newId);

        fetch('manage_dissertation_students.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                if (data.success) {
                    inp.dataset.original = newId;
                    inp.classList.add('saved');
                    btn.style.display = 'none';
                    // Also update the campus select's data-student-id
                    var card = inp.closest('.diss-card');
                    if (card) {
                        var sel = card.querySelector('.campus-select');
                        if (sel) sel.dataset.studentId = newId;
                    }
                    setTimeout(function() { inp.classList.remove('saved'); }, 2000);
                } else {
                    inp.classList.add('save-error');
                    setTimeout(function() { inp.classList.remove('save-error'); }, 3000);
                    alert('Save failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                inp.classList.add('save-error');
                setTimeout(function() { inp.classList.remove('save-error'); }, 3000);
                alert('Network error. Please try again.');
            });
    });
});
// Year/Semester: AJAX auto-save
function saveYearSemester(changedEl) {
    var card = changedEl.closest('.diss-card');
    var yearSel = card.querySelector('.year-select');
    var semSel = card.querySelector('.semester-select');
    var studentId = changedEl.dataset.studentId;
    if (!studentId) return;

    yearSel.classList.add('saving');
    semSel.classList.add('saving');
    yearSel.classList.remove('saved', 'save-error');
    semSel.classList.remove('saved', 'save-error');

    var fd = new FormData();
    fd.append('action', 'ajax_update_year_semester');
    fd.append('student_id', studentId);
    fd.append('year_of_study', yearSel.value);
    fd.append('semester', semSel.value);

    fetch('manage_dissertation_students.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            yearSel.classList.remove('saving');
            semSel.classList.remove('saving');
            if (data.success) {
                yearSel.classList.add('saved');
                semSel.classList.add('saved');
                setTimeout(function() {
                    yearSel.classList.remove('saved');
                    semSel.classList.remove('saved');
                }, 2000);
            } else {
                yearSel.classList.add('save-error');
                semSel.classList.add('save-error');
                setTimeout(function() {
                    yearSel.classList.remove('save-error');
                    semSel.classList.remove('save-error');
                }, 3000);
                alert('Save failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function() {
            yearSel.classList.remove('saving');
            semSel.classList.remove('saving');
            yearSel.classList.add('save-error');
            semSel.classList.add('save-error');
            setTimeout(function() {
                yearSel.classList.remove('save-error');
                semSel.classList.remove('save-error');
            }, 3000);
            alert('Network error. Please try again.');
        });
}

document.querySelectorAll('.year-select, .semester-select').forEach(function(sel) {
    sel.addEventListener('change', function() { saveYearSemester(this); });
});</script>
</body>
</html>
