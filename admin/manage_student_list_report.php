<?php
// manage_student_list_report.php — Per-student data-cleaning tool
// Fixes students whose campus / gender / program is missing or invalid
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'super_admin', 'staff']);

$conn = getDbConnection();

// ── AJAX POST handler ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mslr_action'])) {
    header('Content-Type: application/json');

    $action     = $_POST['mslr_action']  ?? '';
    $student_id = trim($_POST['student_id'] ?? '');
    $new_val    = trim($_POST['new_val']    ?? '');

    if ($student_id === '' || $new_val === '') {
        echo json_encode(['ok' => false, 'msg' => 'Missing student_id or new_val.']);
        exit;
    }

    $allowed_actions = ['update_campus', 'update_gender', 'update_program_type'];
    if (!in_array($action, $allowed_actions, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
        exit;
    }

    // Map action → column
    $col_map = [
        'update_campus'       => 'campus',
        'update_gender'       => 'gender',
        'update_program_type' => 'program_type',
    ];
    $col = $col_map[$action];

    // Whitelist allowed values per field
    $allowed_vals = [
        'update_campus'       => null,  // any non-empty string is fine; validated below
        'update_gender'       => ['male', 'female', 'other', 'not specified'],
        'update_program_type' => ['degree', 'diploma', 'professional', 'masters', 'doctorate', 'postgraduate', 'mba'],
    ];

    if ($allowed_vals[$action] !== null) {
        if (!in_array(strtolower($new_val), $allowed_vals[$action], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid value for ' . $col . '.']);
            exit;
        }
    }

    $stmt = $conn->prepare("UPDATE students SET {$col} = ? WHERE student_id = ?");
    if (!$stmt) {
        echo json_encode(['ok' => false, 'msg' => 'Prepare error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('ss', $new_val, $student_id);
    if ($stmt->execute()) {
        echo json_encode([
            'ok'        => true,
            'msg'       => 'Saved successfully.',
            'new_label' => $new_val,
            'col'       => $col,
        ]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Update failed: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// ── Build dropdown data ──────────────────────────────────────────────────────
// Campuses from existing clean data
$campuses = [];
$cr = $conn->query("SELECT DISTINCT campus FROM students WHERE campus IS NOT NULL AND TRIM(campus) != '' AND campus != '0' ORDER BY campus");
if ($cr) while ($r = $cr->fetch_assoc()) $campuses[] = $r['campus'];
$cr2 = $conn->query("SELECT DISTINCT campus FROM exam_clearance_students WHERE campus IS NOT NULL AND TRIM(campus) != '' AND campus != '0' ORDER BY campus");
if ($cr2) while ($r = $cr2->fetch_assoc()) if (!in_array($r['campus'], $campuses)) $campuses[] = $r['campus'];
sort($campuses);
// Fallback known campuses if table is empty
if (empty($campuses)) {
    $campuses = ['Blantyre Campus', 'Lilongwe Campus', 'Mzuzu Campus', 'ODeL Campus', 'Postgraduate Campus'];
}

$programs_list = ['degree', 'diploma', 'professional', 'masters', 'doctorate', 'postgraduate', 'mba'];
$genders_list  = ['Male', 'Female', 'Other', 'Not Specified'];

// ── Query students with missing / invalid data ───────────────────────────────
// campus is null, empty, or '0'
$campus_issues = [];
$r1 = $conn->query(
    "SELECT student_id, full_name, email, campus, gender, program_type
     FROM students
     WHERE campus IS NULL OR TRIM(campus) = '' OR campus = '0'
     ORDER BY full_name"
);
if ($r1) while ($row = $r1->fetch_assoc()) $campus_issues[] = $row;

// gender is null or empty
$gender_issues = [];
$r2 = $conn->query(
    "SELECT student_id, full_name, email, campus, gender, program_type
     FROM students
     WHERE (gender IS NULL OR TRIM(gender) = '')
       AND NOT (campus IS NULL OR TRIM(campus) = '' OR campus = '0')
     ORDER BY full_name"
);
if ($r2) while ($row = $r2->fetch_assoc()) $gender_issues[] = $row;

// program_type is null, empty, or looks like a number
$program_issues = [];
$r3 = $conn->query(
    "SELECT student_id, full_name, email, campus, gender, program_type
     FROM students
     WHERE (program_type IS NULL OR TRIM(program_type) = '' OR program_type REGEXP '^[0-9]')
       AND NOT (campus IS NULL OR TRIM(campus) = '' OR campus = '0')
       AND NOT (gender IS NULL OR TRIM(gender) = '')
     ORDER BY full_name"
);
if ($r3) while ($row = $r3->fetch_assoc()) $program_issues[] = $row;

$total_issues = count($campus_issues) + count($gender_issues) + count($program_issues);

// ── Detect nav role ──────────────────────────────────────────────────────────
$nav_role = $_SESSION['vle_role'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Missing Student Data — VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global-theme.css">
    <style>
        body { background: #f0f4ff; font-family: 'Inter', sans-serif; }

        .page-hero {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #fff;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(30,60,114,.25);
        }

        .section-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            margin-bottom: 1.5rem;
        }
        .section-card .card-header {
            border-radius: 14px 14px 0 0 !important;
            padding: .75rem 1.25rem;
            font-weight: 600;
        }

        /* inline field cells */
        .fix-cell { vertical-align: middle !important; }
        .fix-select {
            min-width: 160px;
            font-size: .82rem;
            padding: .25rem .5rem;
        }
        .save-btn {
            font-size: .8rem;
            padding: .25rem .6rem;
            white-space: nowrap;
        }
        .lock-badge {
            color: #16a34a;
            font-size: .85rem;
        }

        /* double green blink animation */
        @keyframes blink-green {
            0%   { background-color: inherit; }
            15%  { background-color: #d1fae5; }
            30%  { background-color: inherit; }
            55%  { background-color: #d1fae5; }
            75%  { background-color: inherit; }
            100% { background-color: inherit; }
        }
        .row-saved {
            animation: blink-green 1.5s ease forwards;
        }

        .issue-count-badge {
            font-size: .75rem;
            padding: .35em .65em;
            vertical-align: middle;
        }
        .no-issues-msg {
            padding: 2.5rem 0;
        }
    </style>
</head>
<body>

<?php include 'header_nav.php'; ?>

<div class="container-xl py-4">

    <!-- Page hero -->
    <div class="page-hero d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-tools me-2"></i>Fix Missing Student Data</h4>
            <p class="mb-0 opacity-75 small">
                Students below are missing or have invalid <strong>campus</strong>, <strong>gender</strong>,
                or <strong>program type</strong>. Select the correct value and click&nbsp;<em>Save</em>.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="manage_students.php" class="btn btn-light btn-sm">
                <i class="bi bi-people me-1"></i>Manage Students
            </a>
            <a href="student_list_report.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Full Report
            </a>
        </div>
    </div>

    <!-- Summary badges -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card border-0 rounded-3 text-center py-3 shadow-sm" style="background:linear-gradient(135deg,#fef3c7,#fde68a)">
                <div class="fs-2 fw-bold" style="color:#92400e"><?= count($campus_issues) ?></div>
                <div class="small fw-semibold" style="color:#78350f">Missing Campus</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 rounded-3 text-center py-3 shadow-sm" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe)">
                <div class="fs-2 fw-bold" style="color:#1e3a8a"><?= count($gender_issues) ?></div>
                <div class="small fw-semibold" style="color:#1e3a8a">Missing Gender</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 rounded-3 text-center py-3 shadow-sm" style="background:linear-gradient(135deg,#f3e8ff,#e9d5ff)">
                <div class="fs-2 fw-bold" style="color:#6b21a8"><?= count($program_issues) ?></div>
                <div class="small fw-semibold" style="color:#6b21a8">Missing Program Type</div>
            </div>
        </div>
    </div>

    <?php if ($total_issues === 0): ?>
    <div class="card section-card">
        <div class="card-body text-center no-issues-msg">
            <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
            <h5 class="mt-3 mb-1">All clean!</h5>
            <p class="text-muted">No students have missing campus, gender, or program type.</p>
            <a href="student_list_report.php" class="btn btn-success mt-1">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>View Full Student Report
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SECTION 1: Campus Issues ──────────────────────────────────────── -->
    <?php if (!empty($campus_issues)): ?>
    <div class="card section-card" id="campus-section">
        <div class="card-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff">
            <i class="bi bi-geo-alt-fill me-2"></i>Missing / Invalid Campus
            <span class="badge bg-white text-dark issue-count-badge"><?= count($campus_issues) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="campusTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Current Campus</th>
                            <th>Set Campus</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($campus_issues as $i => $s): ?>
                    <tr id="campus-row-<?= htmlspecialchars($s['student_id']) ?>"
                        data-student-id="<?= htmlspecialchars($s['student_id']) ?>"
                        data-field="campus">
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td><code class="small"><?= htmlspecialchars($s['student_id']) ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['email']) ?></td>
                        <td class="fix-cell">
                            <span class="badge bg-danger small">
                                <?= ($s['campus'] === '0' || $s['campus'] === null || $s['campus'] === '') ? 'Not set' : htmlspecialchars($s['campus']) ?>
                            </span>
                        </td>
                        <td class="fix-cell">
                            <select class="form-select fix-select mslr-select" data-action="update_campus" disabled>
                                <option value="">— select campus —</option>
                                <?php foreach ($campuses as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="fix-cell text-center mslr-action-cell">
                            <button class="btn btn-sm btn-outline-warning mslr-edit-btn" title="Edit campus">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SECTION 2: Gender Issues ──────────────────────────────────────── -->
    <?php if (!empty($gender_issues)): ?>
    <div class="card section-card" id="gender-section">
        <div class="card-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff">
            <i class="bi bi-person-fill me-2"></i>Missing Gender
            <span class="badge bg-white text-dark issue-count-badge"><?= count($gender_issues) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="genderTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Campus</th>
                            <th>Set Gender</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($gender_issues as $i => $s): ?>
                    <tr id="gender-row-<?= htmlspecialchars($s['student_id']) ?>"
                        data-student-id="<?= htmlspecialchars($s['student_id']) ?>"
                        data-field="gender">
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td><code class="small"><?= htmlspecialchars($s['student_id']) ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['email']) ?></td>
                        <td class="small"><?= htmlspecialchars($s['campus'] ?: '—') ?></td>
                        <td class="fix-cell">
                            <select class="form-select fix-select mslr-select" data-action="update_gender" disabled>
                                <option value="">— select gender —</option>
                                <?php foreach ($genders_list as $g): ?>
                                <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="fix-cell text-center mslr-action-cell">
                            <button class="btn btn-sm btn-outline-primary mslr-edit-btn" title="Edit gender">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SECTION 3: Program Type Issues ────────────────────────────────── -->
    <?php if (!empty($program_issues)): ?>
    <div class="card section-card" id="program-section">
        <div class="card-header" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff">
            <i class="bi bi-mortarboard-fill me-2"></i>Missing / Invalid Program Type
            <span class="badge bg-white text-dark issue-count-badge"><?= count($program_issues) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="programTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Campus</th>
                            <th>Current Program</th>
                            <th>Set Program Type</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($program_issues as $i => $s): ?>
                    <tr id="program-row-<?= htmlspecialchars($s['student_id']) ?>"
                        data-student-id="<?= htmlspecialchars($s['student_id']) ?>"
                        data-field="program_type">
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td><code class="small"><?= htmlspecialchars($s['student_id']) ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['email']) ?></td>
                        <td class="small"><?= htmlspecialchars($s['campus'] ?: '—') ?></td>
                        <td class="fix-cell">
                            <span class="badge bg-secondary small">
                                <?= htmlspecialchars($s['program_type'] ?: 'Not set') ?>
                            </span>
                        </td>
                        <td class="fix-cell">
                            <select class="form-select fix-select mslr-select" data-action="update_program_type" disabled>
                                <option value="">— select type —</option>
                                <?php foreach ($programs_list as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= ucfirst(htmlspecialchars($p)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="fix-cell text-center mslr-action-cell">
                            <button class="btn btn-sm btn-outline-secondary mslr-edit-btn" title="Edit program type">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted text-end small mt-3">
        <i class="bi bi-lock me-1"></i>Confidential — Admin Use Only — <?= date('d M Y H:i') ?>
    </p>
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    function doSave(row, select, actionIconEl) {
        var studentId = row.dataset.studentId;
        var action    = select.dataset.action;
        var newVal    = select.value;

        if (!newVal) return;

        // Show spinner while saving
        select.disabled = true;
        if (actionIconEl) actionIconEl.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span>';

        var fd = new FormData();
        fd.append('mslr_action', action);
        fd.append('student_id',  studentId);
        fd.append('new_val',     newVal);

        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.ok) {
                // Lock dropdown permanently
                select.disabled = true;

                // Replace action icon with green lock
                if (actionIconEl) {
                    actionIconEl.innerHTML =
                        '<span class="lock-badge text-success" title="Saved">' +
                        '<i class="bi bi-lock-fill"></i></span>';
                }

                // Mark row so pencil can't re-open it
                row.dataset.mslrSaved = '1';

                // Double green blink
                row.classList.add('row-saved');
                setTimeout(function () { row.classList.remove('row-saved'); }, 1600);

                // Update displayed badge in the row
                var badge = row.querySelector('.badge.bg-danger, .badge.bg-secondary, .badge.bg-warning');
                if (badge) {
                    badge.className = 'badge bg-success small';
                    badge.textContent = newVal.charAt(0).toUpperCase() + newVal.slice(1);
                }
            } else {
                // Re-enable so user can try a different value
                select.disabled = false;
                select.focus();
                if (actionIconEl) actionIconEl.innerHTML =
                    '<button class="btn btn-sm btn-outline-secondary mslr-edit-btn" title="Choose again">' +
                    '<i class="bi bi-pencil-square"></i></button>';
                alert('Could not save: ' + (data.msg || 'Unknown error.'));
            }
        })
        .catch(function (err) {
            // Re-enable so user can try again without a page reload
            select.disabled = false;
            select.focus();
            if (actionIconEl) actionIconEl.innerHTML =
                '<button class="btn btn-sm btn-outline-danger mslr-edit-btn" title="Retry">' +
                '<i class="bi bi-arrow-repeat"></i></button>';
            console.error('Save error:', err);
        });
    }

    // ── Pencil click → unlock dropdown (auto-saves on change) ────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mslr-edit-btn');
        if (!btn) return;

        var row = btn.closest('tr');
        if (row.dataset.mslrSaved) return;  // already locked

        var select = row.querySelector('.mslr-select');
        select.disabled = false;
        select.focus();

        // Show a small hint that changing the value will auto-save
        btn.title = 'Select a value — it saves automatically';
    });

    // ── Dropdown change → auto-save immediately ───────────────────────────────
    document.addEventListener('change', function (e) {
        var select = e.target.closest('.mslr-select');
        if (!select) return;

        var row = select.closest('tr');
        if (row.dataset.mslrSaved) return;

        // Find the action icon cell (pencil or lock holder)
        var actionIconEl = row.querySelector('.mslr-action-cell');

        doSave(row, select, actionIconEl);
    });

})();
</script>
</body>
</html>
