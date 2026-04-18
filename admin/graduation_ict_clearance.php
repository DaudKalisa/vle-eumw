<?php
/**
 * ICT – Graduation Clearance Step
 * Check academic transcript, enter module grades by year
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Load all courses from modules table for dropdown
$all_modules_raw = [];
$_mods_rs = $conn->query("SELECT module_code, module_name, program_of_study, year_of_study, semester FROM modules ORDER BY program_of_study, year_of_study, semester, module_name");
if ($_mods_rs) while ($_mod = $_mods_rs->fetch_assoc()) $all_modules_raw[] = $_mod;
$all_modules_json = json_encode($all_modules_raw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Ensure signature_image column in graduation_clearance_steps
$_cs_cols = [];
$_cs_cr = $conn->query("SHOW COLUMNS FROM graduation_clearance_steps");
if ($_cs_cr) while ($_c = $_cs_cr->fetch_assoc()) $_cs_cols[] = $_c['Field'];
if (!in_array('signature_image', $_cs_cols)) {
    $conn->query("ALTER TABLE graduation_clearance_steps ADD COLUMN signature_image MEDIUMTEXT DEFAULT NULL AFTER signature_text");
}

// Ensure modules table
$conn->query("CREATE TABLE IF NOT EXISTS graduation_ict_modules (
    module_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    year_of_study INT DEFAULT NULL,
    module_code VARCHAR(30) DEFAULT NULL,
    module_name VARCHAR(200) NOT NULL,
    marks_obtained DECIMAL(5,2) DEFAULT NULL,
    grade VARCHAR(5) DEFAULT NULL,
    grade_point DECIMAL(3,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migrate: add columns that may be missing from older schema
$_ict_cols = [];
$_ict_cr = $conn->query("SHOW COLUMNS FROM graduation_ict_modules");
if ($_ict_cr) while ($_c = $_ict_cr->fetch_assoc()) $_ict_cols[] = $_c['Field'];
if (!in_array('year_of_study', $_ict_cols)) $conn->query("ALTER TABLE graduation_ict_modules ADD COLUMN year_of_study INT DEFAULT NULL AFTER application_id");
if (!in_array('module_code', $_ict_cols))   $conn->query("ALTER TABLE graduation_ict_modules ADD COLUMN module_code VARCHAR(30) DEFAULT NULL AFTER year_of_study");
if (!in_array('semester', $_ict_cols))      $conn->query("ALTER TABLE graduation_ict_modules ADD COLUMN semester VARCHAR(10) DEFAULT NULL AFTER year_of_study");
if (!in_array('grade', $_ict_cols) || in_array('grade', $_ict_cols)) $conn->query("ALTER TABLE graduation_ict_modules MODIFY COLUMN grade VARCHAR(50) DEFAULT NULL");

// Semester summaries table (admin-entered per Year+Semester)
$conn->query("CREATE TABLE IF NOT EXISTS graduation_ict_semester_summaries (
    summary_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    year_of_study INT NOT NULL,
    semester VARCHAR(10) NOT NULL,
    avg_marks DECIMAL(5,2) DEFAULT NULL,
    grade VARCHAR(50) DEFAULT NULL,
    grade_point DECIMAL(3,2) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_yr_sem (application_id, year_of_study, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// POST – save modules & approve
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $app_id = (int)$_POST['app_id'];

    // Verify app
    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ? AND current_step = 'ict'");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();

    if (!$app) {
        $error = 'Application not found or not at ICT step.';
    } elseif ($action === 'save_modules') {
        // Save entered modules
        $codes  = $_POST['module_code'] ?? [];
        $names  = $_POST['module_name'] ?? [];
        $marks  = $_POST['marks_obtained'] ?? [];
        $grades = $_POST['grade'] ?? [];
        $gps    = $_POST['grade_point'] ?? [];
        $years  = $_POST['year_of_study'] ?? [];

        // Remove old entries and re-insert
        $conn->query("DELETE FROM graduation_ict_modules WHERE application_id = $app_id");

        $stmt = $conn->prepare("INSERT INTO graduation_ict_modules (application_id, year_of_study, module_code, module_name, marks_obtained, grade, grade_point) VALUES (?,?,?,?,?,?,?)");
        $count = 0;
        for ($i = 0; $i < count($codes); $i++) {
            $code = trim($codes[$i] ?? '');
            $name = trim($names[$i] ?? '');
            if (empty($code) && empty($name)) continue;
            $mark = ($marks[$i] ?? '') !== '' ? (float)$marks[$i] : null;
            $g    = trim($grades[$i] ?? '');
            $gp   = ($gps[$i] ?? '') !== '' ? (float)$gps[$i] : null;
            $yr   = ($years[$i] ?? '') !== '' ? (int)$years[$i] : null;
            $stmt->bind_param("iisdsd", $app_id, $yr, $code, $name, $mark, $g, $gp);
            $stmt->execute();
            $count++;
        }
        $success = "$count module(s) saved for Application #$app_id.";
    } elseif ($action === 'save_semester_summary') {
        // Admin saves semester summary rows
        $sum_years   = $_POST['sum_year']    ?? [];
        $sum_sems    = $_POST['sum_semester'] ?? [];
        $sum_marks   = $_POST['sum_avg_marks'] ?? [];
        $sum_notes   = $_POST['sum_notes']   ?? [];

        // Delete all existing summaries for this application then re-insert
        $conn->query("DELETE FROM graduation_ict_semester_summaries WHERE application_id = $app_id");

        $stmt_sum = $conn->prepare("INSERT INTO graduation_ict_semester_summaries (application_id, year_of_study, semester, avg_marks, grade, grade_point, notes) VALUES (?,?,?,?,?,?,?)");
        $saved_sums = 0;
        for ($i = 0; $i < count($sum_years); $i++) {
            $syr  = ($sum_years[$i] ?? '') !== '' ? (int)$sum_years[$i] : null;
            $ssem = trim($sum_sems[$i] ?? '');
            if (!$syr || !$ssem) continue;
            $smk  = ($sum_marks[$i] ?? '') !== '' ? (float)$sum_marks[$i] : null;
            $snote = trim($sum_notes[$i] ?? '');
            // Auto grade from marks
            [$sgrade, $sgp] = getGradeFromMark($smk);
            $stmt_sum->bind_param("iisdsds", $app_id, $syr, $ssem, $smk, $sgp, $sgrade, $snote);
            $stmt_sum->execute();
            $saved_sums++;
        }
        $success = "$saved_sums semester summary row(s) saved for Application #$app_id.";
    } elseif ($action === 'approve') {
        $notes = trim($_POST['notes'] ?? '');
        $signature_image = trim($_POST['signature_image'] ?? '');
        // Validate signature is a proper PNG data URL
        if ($signature_image && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $signature_image)) {
            $signature_image = '';
        }
        $officer_name = $user['display_name'] ?? $user['username'];
        $uid = (int)$user['user_id'];

        // Calculate overall GPA from semester summaries (preferred) then fall back to modules
        $mr = $conn->query("SELECT AVG(grade_point) as avg_gpa, COUNT(*) as total_credits FROM graduation_ict_semester_summaries WHERE application_id = $app_id AND grade_point IS NOT NULL");
        $ms = $mr ? $mr->fetch_assoc() : ['avg_gpa' => 0, 'total_credits' => 0];
        if (!$ms['total_credits']) {
            // Fall back to module-level average
            $mr2 = $conn->query("SELECT AVG(grade_point) as avg_gpa, SUM(1) as total_credits FROM graduation_ict_modules WHERE application_id = $app_id AND grade_point IS NOT NULL");
            $ms  = $mr2 ? $mr2->fetch_assoc() : ['avg_gpa' => 0, 'total_credits' => 0];
        }
        $gpa = round($ms['avg_gpa'] ?? 0, 2);
        $credits = (int)($ms['total_credits'] ?? 0);

        // Classification based on 9-level GPA scale
        if ($gpa >= 4.0)      $classification = 'Distinction';
        elseif ($gpa >= 3.5)  $classification = 'Lower Distinction';
        elseif ($gpa >= 3.0)  $classification = 'High Credit';
        elseif ($gpa >= 2.5)  $classification = 'Credit';
        elseif ($gpa >= 2.0)  $classification = 'Low Credit';
        elseif ($gpa >= 1.5)  $classification = 'Pass';
        elseif ($gpa >= 1.0)  $classification = 'Bare Pass';
        elseif ($gpa >= 0.5)  $classification = 'Marginal Failure';
        else                   $classification = 'Failure';

        $conn->query("DELETE FROM graduation_grade_summary WHERE application_id = $app_id");
        $stmt = $conn->prepare("INSERT INTO graduation_grade_summary (application_id, gpa, classification, total_credits) VALUES (?,?,?,?)");
        $stmt->bind_param("idsi", $app_id, $gpa, $classification, $credits);
        $stmt->execute();

        // Update step
        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status='approved', officer_user_id=?, officer_name=?, officer_role='ict', signature_text=?, signature_image=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='ict'");
        $stmt->bind_param("issssi", $uid, $officer_name, $officer_name, $signature_image, $notes, $app_id);
        $stmt->execute();

        // Advance
        $conn->query("UPDATE graduation_applications SET status='ict_approved', current_step='dean' WHERE application_id=$app_id");
        $success = "ICT clearance approved for Application #$app_id. GPA: $gpa ($classification)";
    } elseif ($action === 'reject') {
        $notes = trim($_POST['notes'] ?? '');
        $uid = (int)$user['user_id'];
        $officer_name = $user['display_name'] ?? $user['username'];
        $stmt = $conn->prepare("UPDATE graduation_clearance_steps SET status='rejected', officer_user_id=?, officer_name=?, notes=?, actioned_at=NOW() WHERE application_id=? AND step_name='ict'");
        $stmt->bind_param("issi", $uid, $officer_name, $notes, $app_id);
        $stmt->execute();
        $conn->query("UPDATE graduation_applications SET status='rejected', rejection_reason='" . $conn->real_escape_string($notes) . "' WHERE application_id=$app_id");
        $success = 'Application #' . $app_id . ' rejected at ICT step.';
    }
}

// Fetch pending
$pending = [];
$rs = $conn->query("SELECT ga.* FROM graduation_applications ga WHERE ga.current_step = 'ict' ORDER BY ga.submitted_at ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $pending[] = $r;

// For selected app, load existing modules
$selected_id = (int)($_GET['app_id'] ?? ($pending[0]['application_id'] ?? 0));
$selected_app = null;
$modules = [];
if ($selected_id) {
    $stmt = $conn->prepare("SELECT * FROM graduation_applications WHERE application_id = ?");
    $stmt->bind_param("i", $selected_id);
    $stmt->execute();
    $selected_app = $stmt->get_result()->fetch_assoc();

    $mr = $conn->query("SELECT * FROM graduation_ict_modules WHERE application_id = $selected_id ORDER BY year_of_study, semester, module_code");
    if ($mr) while ($m = $mr->fetch_assoc()) $modules[] = $m;

    $sem_summaries = [];
    $smr = $conn->query("SELECT * FROM graduation_ict_semester_summaries WHERE application_id = $selected_id ORDER BY year_of_study, semester");
    if ($smr) while ($sm = $smr->fetch_assoc()) $sem_summaries[] = $sm;

    // Pre-compute overall GPA from semester summaries
    $overall_gpa = null;
    if (!empty($sem_summaries)) {
        $gp_vals = array_filter(array_column($sem_summaries, 'grade_point'), fn($v) => $v !== null);
        $overall_gpa = count($gp_vals) ? round(array_sum($gp_vals) / count($gp_vals), 2) : null;
    }
}

function getGradeFromMark($mark) {
    if ($mark === null) return ['', 0.0];
    $mark = (float)$mark;
    if ($mark >= 85)   return ['Distinction',       4.0];
    if ($mark >= 75)   return ['Lower Distinction',  3.5];
    if ($mark >= 70)   return ['High Credit',        3.0];
    if ($mark >= 65)   return ['Credit',             2.5];
    if ($mark >= 60)   return ['Low Credit',         2.0];
    if ($mark >= 55)   return ['Pass',               1.5];
    if ($mark >= 50)   return ['Bare Pass',          1.0];
    if ($mark >= 45)   return ['Marginal Failure',   0.5];
    return ['Failure', 0.0];
}

function gpToLetterGrade($gp) {
    if ($gp >= 4.0) return 'A+';
    if ($gp >= 3.5) return 'A';
    if ($gp >= 3.0) return 'B+';
    if ($gp >= 2.5) return 'B';
    if ($gp >= 2.0) return 'B-';
    if ($gp >= 1.5) return 'C';
    if ($gp >= 1.0) return 'D';
    if ($gp >= 0.5) return 'D-';
    return 'F';
}

function overallClassification($gpa) {
    if ($gpa >= 4.0)     return 'Distinction';
    if ($gpa >= 3.5)     return 'Lower Distinction';
    if ($gpa >= 3.0)     return 'High Credit';
    if ($gpa >= 2.5)     return 'Credit';
    if ($gpa >= 2.0)     return 'Low Credit';
    if ($gpa >= 1.5)     return 'Pass';
    if ($gpa >= 1.0)     return 'Bare Pass';
    if ($gpa >= 0.5)     return 'Marginal Failure';
    return 'Failure';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ICT Clearance – Graduation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
        .page-header{background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .module-table input{border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:.85rem;width:100%;}
        .module-table select{border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:.85rem;width:100%;}
        .grade-guide{background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:.75rem 1rem;font-size:.82rem;}
        .grade-guide table td,.grade-guide table th{padding:3px 10px;}
        input.grade-auto{background:#f8fafc;font-weight:600;color:#1e40af;text-align:center;}
        select.course-name-sel{width:100%;min-width:200px;font-size:.82rem;}
        .sig-canvas-wrap{border:2px solid #94a3b8;border-radius:10px;background:#fff;overflow:hidden;}
        #sigCanvas{display:block;touch-action:none;width:100%;height:160px;cursor:crosshair;}
        .sem-gp-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:700;}
    </style>
</head>
<body>
<div class="top-bar">
    <a href="dashboard.php" style="font-weight:700;color:#3b82f6;text-decoration:none;"><i class="bi bi-arrow-left me-2"></i>Admin Dashboard</a>
    <span class="badge bg-primary"><?= count($pending) ?> Pending ICT Clearance</span>
</div>
<div class="container-fluid py-4" style="max-width:1200px;">
    <div class="page-header">
        <h4 class="mb-0"><i class="bi bi-pc-display me-2"></i>ICT – Academic Transcript &amp; Course Grades</h4>
        <p class="mb-0 opacity-75 mt-1">Enter final semester course grades from the student's academic record</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Grade Guide Reference -->
    <div class="grade-guide mb-3">
        <strong><i class="bi bi-award me-1"></i>Grading Scale</strong>
        <table class="table table-sm table-bordered mt-1 mb-0" style="font-size:.82rem;max-width:540px;">
            <thead class="table-primary"><tr><th>Marks</th><th>Grade</th><th>Letter</th><th>GP</th></tr></thead>
            <tbody>
                <tr><td>85 – 100</td><td><strong>Distinction</strong></td><td>A+</td><td>4.0</td></tr>
                <tr><td>75 – 84</td><td><strong>Lower Distinction</strong></td><td>A</td><td>3.5</td></tr>
                <tr><td>70 – 74</td><td><strong>High Credit</strong></td><td>B+</td><td>3.0</td></tr>
                <tr><td>65 – 69</td><td><strong>Credit</strong></td><td>B</td><td>2.5</td></tr>
                <tr><td>60 – 64</td><td><strong>Low Credit</strong></td><td>B-</td><td>2.0</td></tr>
                <tr><td>55 – 59</td><td><strong>Pass</strong></td><td>C</td><td>1.5</td></tr>
                <tr><td>50 – 54</td><td><strong>Bare Pass</strong></td><td>D</td><td>1.0</td></tr>
                <tr><td>45 – 49</td><td><strong>Marginal Failure</strong></td><td>D-</td><td>0.5</td></tr>
                <tr><td>0 – 44</td><td><strong>Failure</strong></td><td>F</td><td>0.0</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pending list -->
    <?php if (!empty($pending)): ?>
    <div class="mb-4">
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($pending as $p): ?>
            <a href="?app_id=<?= $p['application_id'] ?>" class="btn btn-sm <?= $p['application_id']===$selected_id?'btn-primary':'btn-outline-primary' ?>">
                #<?= $p['application_id'] ?> <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($selected_app && $selected_app['current_step'] === 'ict'): ?>
    <div class="card mb-3">
        <div class="card-header bg-light">
            <strong><?= htmlspecialchars($selected_app['first_name'] . ' ' . ($selected_app['middle_name'] ?? '') . ' ' . $selected_app['last_name']) ?></strong>
            — <?= htmlspecialchars($selected_app['campus']) ?>, <?= htmlspecialchars($selected_app['program']) ?>
            | Entry: <?= $selected_app['year_of_entry'] ?> → <?= $selected_app['year_of_completion'] ?>
            | Previously had transcript: <?= $selected_app['transcript_processed_before'] ? '<span class="text-success">Yes</span>' : 'No' ?>
        </div>
        <div class="card-body">
            <!-- Module Entry Form -->
            <form method="post" id="modulesForm">
                <input type="hidden" name="action" value="save_modules">
                <input type="hidden" name="app_id" value="<?= $selected_app['application_id'] ?>">

                <table class="table table-sm module-table" id="moduleTable">
                    <thead>
                        <tr>
                            <th style="width:65px;">Year</th>
                            <th style="min-width:220px;">Course Name</th>
                            <th style="width:120px;">Course Code</th>
                            <th style="width:90px;">Marks (/100)</th>
                            <th style="width:135px;">Grade</th>
                            <th style="width:65px;">GP</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($modules)):
                            foreach ($modules as $m): ?>
                        <tr>
                            <td><input type="number" name="year_of_study[]" value="<?= (int)($m['year_of_study'] ?? '') ?>" placeholder="1" min="1" max="10" style="width:58px;"></td>
                            <td>
                                <select class="course-name-sel" onchange="courseSelected(this)"
                                    data-saved-code="<?= htmlspecialchars($m['module_code'] ?? '', ENT_QUOTES) ?>"
                                    data-saved-name="<?= htmlspecialchars($m['module_name'] ?? '', ENT_QUOTES) ?>"></select>
                                <input type="hidden" name="module_name[]" value="<?= htmlspecialchars($m['module_name'] ?? '') ?>">
                            </td>
                            <td><input type="text" name="module_code[]" value="<?= htmlspecialchars($m['module_code'] ?? '') ?>" class="grade-auto" style="text-align:left;width:100%;" readonly></td>
                            <td><input type="number" name="marks_obtained[]" value="<?= $m['marks_obtained'] ?>" step="0.01" min="0" max="100" oninput="autoGrade(this)"></td>
                            <td><input type="text" name="grade[]" value="<?= htmlspecialchars($m['grade'] ?? '') ?>" class="grade-auto" readonly></td>
                            <td><input type="number" name="grade_point[]" value="<?= $m['grade_point'] ?>" step="0.01" class="grade-auto" readonly></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td><input type="number" name="year_of_study[]" placeholder="1" min="1" max="10" style="width:58px;"></td>
                            <td>
                                <select class="course-name-sel" onchange="courseSelected(this)"></select>
                                <input type="hidden" name="module_name[]" value="">
                            </td>
                            <td><input type="text" name="module_code[]" class="grade-auto" style="text-align:left;width:100%;" readonly></td>
                            <td><input type="number" name="marks_obtained[]" step="0.01" min="0" max="100" oninput="autoGrade(this)"></td>
                            <td><input type="text" name="grade[]" class="grade-auto" readonly></td>
                            <td><input type="number" name="grade_point[]" step="0.01" class="grade-auto" readonly></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addRow()"><i class="bi bi-plus me-1"></i>Add Course</button>
                <br>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Modules</button>
            </form>

            <hr>

            <!-- ── Semester Summaries ──────────────────────────────────────────── -->
            <div class="mb-3">
                <h6 class="fw-bold mb-2"><i class="bi bi-bar-chart-steps me-1"></i>Semester Summaries
                    <span class="text-muted fw-normal" style="font-size:.82rem;"> — Enter the average mark per semester; grade &amp; GP auto-fill</span>
                </h6>
                <form method="post" id="semSummaryForm">
                    <input type="hidden" name="action" value="save_semester_summary">
                    <input type="hidden" name="app_id" value="<?= $selected_app['application_id'] ?>">

                    <table class="table table-sm module-table" id="semSummaryTable">
                        <thead>
                            <tr>
                                <th style="width:70px;">Year</th>
                                <th style="width:120px;">Semester</th>
                                <th style="width:110px;">Avg Marks (/100)</th>
                                <th style="width:170px;">Grade</th>
                                <th style="width:60px;">GP</th>
                                <th style="width:180px;">Notes</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="semSummaryBody">
                            <?php if (!empty($sem_summaries)):
                                foreach ($sem_summaries as $ss): ?>
                            <tr>
                                <td><input type="number" name="sum_year[]" value="<?= (int)$ss['year_of_study'] ?>" min="1" max="10" style="width:58px;"></td>
                                <td>
                                    <select name="sum_semester[]" class="form-select form-select-sm">
                                        <option value="One"<?= $ss['semester']==='One'?' selected':'' ?>>Semester 1</option>
                                        <option value="Two"<?= $ss['semester']==='Two'?' selected':'' ?>>Semester 2</option>
                                    </select>
                                </td>
                                <td><input type="number" name="sum_avg_marks[]" value="<?= $ss['avg_marks'] ?>" step="0.01" min="0" max="100" class="sum-marks-input" oninput="autoSemGrade(this)"></td>
                                <td><input type="text" name="sum_grade[]" value="<?= htmlspecialchars($ss['grade'] ?? '') ?>" class="grade-auto" readonly></td>
                                <td><input type="number" name="sum_gp[]" value="<?= $ss['grade_point'] ?>" step="0.01" class="grade-auto" readonly style="width:52px;"></td>
                                <td><input type="text" name="sum_notes[]" value="<?= htmlspecialchars($ss['notes'] ?? '') ?>" placeholder="remarks…" style="width:100%;"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();recalcOverall()"><i class="bi bi-trash"></i></button></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td><input type="number" name="sum_year[]" placeholder="1" min="1" max="10" style="width:58px;"></td>
                                <td>
                                    <select name="sum_semester[]" class="form-select form-select-sm">
                                        <option value="One">Semester 1</option>
                                        <option value="Two">Semester 2</option>
                                    </select>
                                </td>
                                <td><input type="number" name="sum_avg_marks[]" placeholder="0" step="0.01" min="0" max="100" class="sum-marks-input" oninput="autoSemGrade(this)"></td>
                                <td><input type="text" name="sum_grade[]" class="grade-auto" readonly></td>
                                <td><input type="number" name="sum_gp[]" step="0.01" class="grade-auto" readonly style="width:52px;"></td>
                                <td><input type="text" name="sum_notes[]" placeholder="remarks…" style="width:100%;"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();recalcOverall()"><i class="bi bi-trash"></i></button></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="d-flex gap-2 align-items-center mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addSemRow()"><i class="bi bi-plus me-1"></i>Add Semester</button>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save Semester Summaries</button>
                    </div>
                </form>

                <!-- Overall Grade Panel -->
                <div class="p-3 rounded" style="background:#f0fdf4;border:2px solid #86efac;" id="overallGradePanel">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="text-muted" style="font-size:.78rem;font-weight:600;text-transform:uppercase;">Overall GPA</div>
                            <div id="overallGpaDisplay" class="fw-bold" style="font-size:1.6rem;color:#15803d;">
                                <?= $overall_gpa !== null ? number_format($overall_gpa, 2) : '—' ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-muted" style="font-size:.78rem;font-weight:600;text-transform:uppercase;">Classification</div>
                            <div id="overallClassDisplay" class="fw-bold" style="font-size:1.15rem;color:#166534;">
                                <?= $overall_gpa !== null ? overallClassification($overall_gpa) : '—' ?>
                            </div>
                        </div>
                        <div class="ms-auto text-muted" style="font-size:.78rem;">
                            <i class="bi bi-info-circle me-1"></i>Auto-calculated from semester summary GPs above
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            <!-- Approve / Reject -->
            <!-- Hidden approve form; submitted via signature modal -->
            <form method="post" id="approveForm">
                <input type="hidden" name="app_id" value="<?= $selected_app['application_id'] ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="notes" id="approveNotesHidden" value="">
                <input type="hidden" name="signature_image" id="signatureImageInput" value="">
            </form>
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <button type="button" class="btn btn-success btn-sm" onclick="openApproveModal()">
                        <i class="bi bi-pen me-1"></i>Approve &amp; Sign
                    </button>
                </div>
                <div class="col-md-4 text-end">
                    <form method="post">
                        <input type="hidden" name="app_id" value="<?= $selected_app['application_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="notes" value="Rejected at ICT transcript check">
                        <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject this application?')"><i class="bi bi-x-lg me-1"></i>Reject</button>
                    </form>
                </div>
            </div>
            </div>
        </div>
    </div>
    <?php elseif (empty($pending)): ?>
    <div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>No pending ICT clearances.</div>
    <?php endif; ?>
</div>

<!-- ── Signature / Approve Modal ─────────────────────────────────────── -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="approveModalLabel"><i class="bi bi-patch-check me-2"></i>Sign &amp; Approve ICT Clearance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes / Remarks <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="modalNotes" class="form-control form-control-sm" placeholder="ICT clearance remarks…">
                </div>
                <label class="form-label fw-semibold">Officer Signature <span class="text-danger">*</span></label>
                <div class="sig-canvas-wrap">
                    <canvas id="sigCanvas" width="700" height="160"></canvas>
                </div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <small class="text-muted"><i class="bi bi-pencil me-1"></i>Draw your signature above using mouse or finger</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSig()"><i class="bi bi-eraser me-1"></i>Clear</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmApprove()"><i class="bi bi-check-lg me-1"></i>Sign &amp; Approve</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Shared grading logic ─────────────────────────────────────────────
const GP_SCALE = [
    { min: 85, grade: 'Distinction',       letter: 'A+', gp: 4.0 },
    { min: 75, grade: 'Lower Distinction', letter: 'A',  gp: 3.5 },
    { min: 70, grade: 'High Credit',       letter: 'B+', gp: 3.0 },
    { min: 65, grade: 'Credit',            letter: 'B',  gp: 2.5 },
    { min: 60, grade: 'Low Credit',        letter: 'B-', gp: 2.0 },
    { min: 55, grade: 'Pass',              letter: 'C',  gp: 1.5 },
    { min: 50, grade: 'Bare Pass',         letter: 'D',  gp: 1.0 },
    { min: 45, grade: 'Marginal Failure',  letter: 'D-', gp: 0.5 },
    { min:  0, grade: 'Failure',           letter: 'F',  gp: 0.0 },
];

function gradeFromMark(mark) {
    if (mark === '' || mark === null || isNaN(mark)) return { grade: '', letter: '', gp: '' };
    mark = parseFloat(mark);
    for (var s of GP_SCALE) { if (mark >= s.min) return s; }
    return GP_SCALE[GP_SCALE.length - 1];
}

function gpaToClassification(gpa) {
    if (gpa >= 4.0) return 'Distinction';
    if (gpa >= 3.5) return 'Lower Distinction';
    if (gpa >= 3.0) return 'High Credit';
    if (gpa >= 2.5) return 'Credit';
    if (gpa >= 2.0) return 'Low Credit';
    if (gpa >= 1.5) return 'Pass';
    if (gpa >= 1.0) return 'Bare Pass';
    if (gpa >= 0.5) return 'Marginal Failure';
    return 'Failure';
}

// ── Course data from DB ───────────────────────────────────────────────
const MODULES = <?= $all_modules_json ?>;

function buildCourseSelect(sel, savedCode, savedName) {
    sel.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = ''; blank.textContent = '-- Select Course --';
    sel.appendChild(blank);
    var lastProg = null, grp = null;
    MODULES.forEach(function(m) {
        if (m.program_of_study !== lastProg) {
            grp = document.createElement('optgroup');
            grp.label = m.program_of_study;
            sel.appendChild(grp);
            lastProg = m.program_of_study;
        }
        var opt = document.createElement('option');
        opt.value = m.module_code;
        opt.textContent = '[' + m.module_code + '] ' + m.module_name + ' (Yr' + m.year_of_study + ' Sem' + (m.semester === 'Two' ? '2' : '1') + ')';
        opt.dataset.name = m.module_name;
        opt.dataset.year = m.year_of_study;
        opt.dataset.semester = m.semester || '';
        if (savedCode && m.module_code === savedCode) opt.selected = true;
        else if (!savedCode && savedName && m.module_name === savedName) opt.selected = true;
        grp.appendChild(opt);
    });
}

function courseSelected(sel) {
    var tr = sel.closest('tr');
    var opt = sel.selectedIndex > 0 ? sel.options[sel.selectedIndex] : null;
    var code  = opt ? opt.value        : '';
    var cname = opt ? (opt.dataset.name || '') : '';
    var yr    = opt ? (opt.dataset.year || '') : '';
    var sem   = opt ? (opt.dataset.semester || '') : '';
    tr.querySelector('[name="module_code[]"]').value = code;
    tr.querySelector('[name="module_name[]"]').value = cname;
    var yrInput = tr.querySelector('[name="year_of_study[]"]');
    if (yr && !yrInput.value) yrInput.value = yr;
    var semSel = tr.querySelector('[name="semester[]"]');
    if (semSel && sem && !semSel.value) semSel.value = sem;
}

function addRow() {
    var tbody = document.querySelector('#moduleTable tbody');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" name="year_of_study[]" placeholder="1" min="1" max="10" style="width:58px;"></td>' +
        '<td><select class="course-name-sel" onchange="courseSelected(this)"></select>' +
            '<input type="hidden" name="module_name[]" value=""></td>' +
        '<td><input type="text" name="module_code[]" class="grade-auto" style="text-align:left;width:100%;" readonly></td>' +
        '<td><input type="number" name="marks_obtained[]" step="0.01" min="0" max="100" oninput="autoGrade(this)"></td>' +
        '<td><input type="text" name="grade[]" class="grade-auto" readonly></td>' +
        '<td><input type="number" name="grade_point[]" step="0.01" class="grade-auto" readonly></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-trash"></i></button></td>';
    tbody.appendChild(tr);
    buildCourseSelect(tr.querySelector('.course-name-sel'), '', '');
}

function autoGrade(el) {
    var tr = el.closest('tr');
    var s = gradeFromMark(el.value);
    tr.querySelector('[name="grade[]"]').value       = s.grade ? s.grade + ' (' + s.letter + ')' : '';
    tr.querySelector('[name="grade_point[]"]').value = s.gp !== '' ? s.gp : '';
}

// ── Semester Summary functions ────────────────────────────────────────
function autoSemGrade(el) {
    var tr = el.closest('tr');
    var s = gradeFromMark(el.value);
    tr.querySelector('[name="sum_grade[]"]').value = s.grade ? s.grade + ' (' + s.letter + ')' : '';
    tr.querySelector('[name="sum_gp[]"]').value    = s.gp !== '' ? s.gp : '';
    recalcOverall();
}

function addSemRow() {
    var tbody = document.getElementById('semSummaryBody');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" name="sum_year[]" placeholder="1" min="1" max="10" style="width:58px;"></td>' +
        '<td><select name="sum_semester[]" class="form-select form-select-sm">' +
            '<option value="One">Semester 1</option><option value="Two">Semester 2</option></select></td>' +
        '<td><input type="number" name="sum_avg_marks[]" placeholder="0" step="0.01" min="0" max="100" class="sum-marks-input" oninput="autoSemGrade(this)"></td>' +
        '<td><input type="text" name="sum_grade[]" class="grade-auto" readonly></td>' +
        '<td><input type="number" name="sum_gp[]" step="0.01" class="grade-auto" readonly style="width:52px;"></td>' +
        '<td><input type="text" name="sum_notes[]" placeholder="remarks…" style="width:100%;"></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove();recalcOverall()"><i class="bi bi-trash"></i></button></td>';
    tbody.appendChild(tr);
}

function recalcOverall() {
    var gps = [];
    document.querySelectorAll('[name="sum_gp[]"]').forEach(function(inp) {
        if (inp.value !== '' && !isNaN(inp.value)) gps.push(parseFloat(inp.value));
    });
    var gpaEl  = document.getElementById('overallGpaDisplay');
    var clsEl  = document.getElementById('overallClassDisplay');
    if (gps.length === 0) {
        gpaEl.textContent = '—'; clsEl.textContent = '—'; return;
    }
    var avg = gps.reduce(function(a, b){ return a + b; }, 0) / gps.length;
    avg = Math.round(avg * 100) / 100;
    gpaEl.textContent = avg.toFixed(2);
    clsEl.textContent = gpaToClassification(avg);
}

// ── Signature pad ────────────────────────────────────────────────────
var _sigDrawing = false;

function _sigPos(e, canvas) {
    var rect = canvas.getBoundingClientRect();
    var sx = canvas.width / rect.width, sy = canvas.height / rect.height;
    var pt = e.touches ? e.touches[0] : e;
    return { x: (pt.clientX - rect.left) * sx, y: (pt.clientY - rect.top) * sy };
}

function clearSig() {
    var c = document.getElementById('sigCanvas');
    if (c) c.getContext('2d').clearRect(0, 0, c.width, c.height);
}

function openApproveModal() {
    clearSig();
    document.getElementById('modalNotes').value = '';
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function confirmApprove() {
    var canvas = document.getElementById('sigCanvas');
    var ctx = canvas.getContext('2d');
    var pix = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    var blank = true;
    for (var i = 3; i < pix.length; i += 4) { if (pix[i] !== 0) { blank = false; break; } }
    if (blank) { alert('Please draw your signature before approving.'); return; }
    document.getElementById('signatureImageInput').value = canvas.toDataURL('image/png');
    document.getElementById('approveNotesHidden').value  = document.getElementById('modalNotes').value;
    document.getElementById('approveForm').submit();
}

document.addEventListener('DOMContentLoaded', function () {
    // Populate course dropdowns for pre-saved rows
    document.querySelectorAll('.course-name-sel').forEach(function(sel) {
        buildCourseSelect(sel, sel.dataset.savedCode || '', sel.dataset.savedName || '');
    });

    // Trigger overall GPA recalc from saved semester summaries
    recalcOverall();

    // Wire up signature canvas
    var canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';

    canvas.addEventListener('mousedown',  function(e) { _sigDrawing = true; ctx.beginPath(); var p = _sigPos(e, canvas); ctx.moveTo(p.x, p.y); });
    canvas.addEventListener('mousemove',  function(e) { if (!_sigDrawing) return; var p = _sigPos(e, canvas); ctx.lineTo(p.x, p.y); ctx.stroke(); });
    canvas.addEventListener('mouseup',    function()  { _sigDrawing = false; });
    canvas.addEventListener('mouseleave', function()  { _sigDrawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); _sigDrawing = true; ctx.beginPath(); var p = _sigPos(e, canvas); ctx.moveTo(p.x, p.y); }, { passive: false });
    canvas.addEventListener('touchmove',  function(e) { e.preventDefault(); if (!_sigDrawing) return; var p = _sigPos(e, canvas); ctx.lineTo(p.x, p.y); ctx.stroke(); }, { passive: false });
    canvas.addEventListener('touchend',   function()  { _sigDrawing = false; });
});
</script>
</body>
</html>
