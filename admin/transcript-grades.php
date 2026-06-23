<?php
/**
 * Admin – Upload Old Transcript Grades
 * Upload an Excel template filled from a paper/PDF transcript and convert
 * it directly into graduation_ict_modules grade records in the system.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error   = '';

// ── Grade helper (mirrors graduation_ict_clearance.php) ──────────────────────
function gradeFromMark($mark) {
    if ($mark === null || $mark === '') return ['', 0.0];
    $mark = (float)$mark;
    if ($mark >= 85) return ['Distinction',       4.0];
    if ($mark >= 75) return ['Lower Distinction', 3.5];
    if ($mark >= 70) return ['High Credit',       3.0];
    if ($mark >= 65) return ['Credit',            2.5];
    if ($mark >= 60) return ['Low Credit',        2.0];
    if ($mark >= 55) return ['Pass',              1.5];
    if ($mark >= 50) return ['Bare Pass',         1.0];
    if ($mark >= 45) return ['Marginal Failure',  0.5];
    return ['Failure', 0.0];
}

// ── Ensure tables ─────────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS transcript_uploads (
    upload_id        INT AUTO_INCREMENT PRIMARY KEY,
    application_id   INT DEFAULT NULL COMMENT 'linked graduation_applications row if exists',
    student_name     VARCHAR(255) NOT NULL,
    student_id_number VARCHAR(50) DEFAULT NULL,
    program          VARCHAR(200) DEFAULT NULL,
    campus           VARCHAR(100) DEFAULT NULL,
    year_of_entry    YEAR DEFAULT NULL,
    year_of_completion YEAR DEFAULT NULL,
    overall_gpa      DECIMAL(4,2) DEFAULT NULL,
    classification   VARCHAR(100) DEFAULT NULL,
    uploaded_by      INT NOT NULL,
    uploaded_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes            TEXT DEFAULT NULL,
    status           ENUM('draft','confirmed') DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS transcript_upload_modules (
    row_id       INT AUTO_INCREMENT PRIMARY KEY,
    upload_id    INT NOT NULL,
    year_of_study INT DEFAULT NULL,
    semester     VARCHAR(20) DEFAULT NULL,
    module_code  VARCHAR(30) DEFAULT NULL,
    module_name  VARCHAR(255) NOT NULL,
    marks        DECIMAL(5,2) DEFAULT NULL,
    grade        VARCHAR(50) DEFAULT NULL,
    grade_point  DECIMAL(3,2) DEFAULT NULL,
    FOREIGN KEY (upload_id) REFERENCES transcript_uploads(upload_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Download Excel template ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    require_once '../vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Transcript Template');

    // Instructions sheet
    $info = $spreadsheet->createSheet();
    $info->setTitle('Instructions');
    $info->setCellValue('A1', 'TRANSCRIPT UPLOAD TEMPLATE – INSTRUCTIONS');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $infoRows = [
        ['', ''],
        ['STUDENT INFO (fill in the yellow sheet header rows):', ''],
        ['Student Name',    'Full legal name e.g. Alice Manyunya'],
        ['Student ID',      'e.g. 2019/BSc/ICT/001'],
        ['Program',         'e.g. Bachelor of Science in Information and Communication Technology'],
        ['Campus',          'e.g. Blantyre Campus'],
        ['Year of Entry',   'e.g. 2019'],
        ['Year of Completion','e.g. 2023'],
        ['', ''],
        ['MODULE COLUMNS (one row per module):', ''],
        ['Year of Study',   'Integer: 1, 2, 3 or 4'],
        ['Semester',        'One  OR  Two'],
        ['Module Code',     'e.g. ICT 101'],
        ['Module Name',     'Full module title'],
        ['Marks',           'Numeric mark 0–100 (leave blank if not available)'],
        ['Grade',           'Leave blank – auto-calculated from Marks on upload (or type manually)'],
        ['Grade Point',     'Leave blank – auto-calculated from Marks on upload (or type manually)'],
        ['', ''],
        ['GRADE SCALE REFERENCE:', ''],
        ['85 – 100', 'Distinction       (4.0)'],
        ['75 – 84',  'Lower Distinction (3.5)'],
        ['70 – 74',  'High Credit       (3.0)'],
        ['65 – 69',  'Credit            (2.5)'],
        ['60 – 64',  'Low Credit        (2.0)'],
        ['55 – 59',  'Pass              (1.5)'],
        ['50 – 54',  'Bare Pass         (1.0)'],
        ['45 – 49',  'Marginal Failure  (0.5)'],
        ['0  – 44',  'Failure           (0.0)'],
    ];
    foreach ($infoRows as $i => $row) {
        $info->setCellValue('A' . ($i + 2), $row[0]);
        $info->setCellValue('B' . ($i + 2), $row[1]);
    }
    $info->getColumnDimension('A')->setWidth(30);
    $info->getColumnDimension('B')->setWidth(55);

    // Template sheet
    $spreadsheet->setActiveSheetIndex(0);
    $sheet = $spreadsheet->getActiveSheet();

    // Header meta rows
    $metaHeaders = ['Student Name', 'Student ID Number', 'Program', 'Campus', 'Year of Entry', 'Year of Completion'];
    $metaDefaults = ['', '', '', 'Blantyre Campus', '', ''];
    foreach ($metaHeaders as $idx => $label) {
        $row = $idx + 1;
        $sheet->setCellValue('A' . $row, $label);
        $sheet->setCellValue('B' . $row, $metaDefaults[$idx]);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFF3CD');
        $sheet->getStyle('B' . $row)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFFDE7');
    }

    // Column headers for modules
    $colRow = 8;
    $cols = ['Year of Study', 'Semester', 'Module Code', 'Module Name', 'Marks (0-100)', 'Grade (auto)', 'Grade Point (auto)'];
    foreach ($cols as $c => $label) {
        $col = chr(65 + $c);
        $sheet->setCellValue($col . $colRow, $label);
        $sheet->getStyle($col . $colRow)->getFont()->setBold(true)->setColor(
            (new \PhpOffice\PhpSpreadsheet\Style\Color())->setRGB('FFFFFF')
        );
        $sheet->getStyle($col . $colRow)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1A1A2E');
    }

    // Sample data rows
    $samples = [
        [1,'One','ICT 101','Introduction to Computing',72,'',''],
        [1,'One','ICT 102','Mathematics for Computing',68,'',''],
        [1,'Two','ICT 103','Programming Fundamentals',80,'',''],
        [1,'Two','ICT 104','Database Systems I',65,'',''],
        [2,'One','ICT 201','Data Structures & Algorithms',75,'',''],
        [2,'Two','ICT 202','Software Engineering',70,'',''],
    ];
    foreach ($samples as $si => $row) {
        $dataRow = $colRow + 1 + $si;
        foreach ($row as $ci => $val) {
            $sheet->setCellValue(chr(65 + $ci) . $dataRow, $val);
        }
    }

    // Column widths
    foreach (['A'=>10,'B'=>10,'C'=>14,'D'=>45,'E'=>14,'F'=>22,'G'=>20] as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }

    // Note row
    $noteRow = $colRow + count($samples) + 3;
    $sheet->setCellValue('A' . $noteRow, 'Add more rows below – do NOT change row 8 headers or rows 1-6.');
    $sheet->getStyle('A' . $noteRow . ':G' . $noteRow)->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '888888']],
    ]);
    $sheet->mergeCells('A' . $noteRow . ':G' . $noteRow);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="transcript_upload_template.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ── DELETE upload ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_upload') {
    $uid_del = (int)$_POST['upload_id'];
    $stmt = $conn->prepare("DELETE FROM transcript_uploads WHERE upload_id = ?");
    $stmt->bind_param("i", $uid_del);
    $stmt->execute();
    $success = 'Upload record deleted.';
}

// ── CONFIRM (link to graduation application) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_upload') {
    $uid_conf = (int)$_POST['upload_id'];
    $app_id_link = (int)($_POST['link_app_id'] ?? 0);

    // Copy rows into graduation_ict_modules
    if ($app_id_link > 0) {
        // Verify app exists
        $ca = $conn->prepare("SELECT application_id FROM graduation_applications WHERE application_id = ?");
        $ca->bind_param("i", $app_id_link);
        $ca->execute();
        $app_row = $ca->get_result()->fetch_assoc();
        if ($app_row) {
            $conn->query("DELETE FROM graduation_ict_modules WHERE application_id = $app_id_link");
            $ins = $conn->prepare("INSERT INTO graduation_ict_modules (application_id, year_of_study, module_code, module_name, marks, grade, grade_point) SELECT ?, year_of_study, module_code, module_name, marks, grade, grade_point FROM transcript_upload_modules WHERE upload_id = ?");
            $ins->bind_param("ii", $app_id_link, $uid_conf);
            $ins->execute();
            $conn->prepare("UPDATE transcript_uploads SET application_id=?, status='confirmed' WHERE upload_id=?")->execute()
                || null;
            $upd = $conn->prepare("UPDATE transcript_uploads SET application_id=?, status='confirmed' WHERE upload_id=?");
            $upd->bind_param("ii", $app_id_link, $uid_conf);
            $upd->execute();
            $success = "Grades copied to graduation application #{$app_id_link} and marked confirmed.";
        } else {
            $error = 'Graduation application not found.';
        }
    } else {
        $upd2 = $conn->prepare("UPDATE transcript_uploads SET status='confirmed' WHERE upload_id=?");
        $upd2->bind_param("i", $uid_conf);
        $upd2->execute();
        $success = 'Upload confirmed (not linked to a graduation application).';
    }
}

// ── PROCESS UPLOADED EXCEL ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_transcript') {
    if (!isset($_FILES['transcript_file']) || $_FILES['transcript_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or upload error.';
    } else {
        $allowed = ['xlsx','xls','csv'];
        $ext = strtolower(pathinfo($_FILES['transcript_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $error = 'Only .xlsx, .xls, or .csv files are accepted.';
        } else {
            require_once '../vendor/autoload.php';
            try {
                $tmpPath = $_FILES['transcript_file']['tmp_name'];
                $reader  = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($tmpPath);
                $sheet = $spreadsheet->getActiveSheet();

                // Read meta (rows 1–6, column B)
                $student_name     = trim((string)$sheet->getCell('B1')->getValue());
                $student_id_num   = trim((string)$sheet->getCell('B2')->getValue());
                $program          = trim((string)$sheet->getCell('B3')->getValue());
                $campus           = trim((string)$sheet->getCell('B4')->getValue()) ?: 'Blantyre Campus';
                $year_entry       = (int)$sheet->getCell('B5')->getValue() ?: null;
                $year_completion  = (int)$sheet->getCell('B6')->getValue() ?: null;

                // Manual override from the POST form (if admin typed directly)
                if (empty($student_name))   $student_name   = trim($_POST['student_name'] ?? '');
                if (empty($student_id_num)) $student_id_num = trim($_POST['student_id_number'] ?? '');
                if (empty($program))        $program        = trim($_POST['program'] ?? '');
                if (!$year_entry)           $year_entry     = (int)($_POST['year_entry'] ?? 0) ?: null;
                if (!$year_completion)      $year_completion= (int)($_POST['year_completion'] ?? 0) ?: null;

                if (empty($student_name)) {
                    $error = 'Student Name is required (row 1, column B of the Excel file, or the form field).';
                } else {
                    // Read module rows starting from row 9 (row 8 = header)
                    $modules = [];
                    $maxRow  = $sheet->getHighestRow();
                    for ($r = 9; $r <= $maxRow; $r++) {
                        $yr   = trim((string)$sheet->getCell('A' . $r)->getValue());
                        $sem  = trim((string)$sheet->getCell('B' . $r)->getValue());
                        $code = trim((string)$sheet->getCell('C' . $r)->getValue());
                        $name = trim((string)$sheet->getCell('D' . $r)->getValue());
                        $mrk  = trim((string)$sheet->getCell('E' . $r)->getValue());
                        $grd  = trim((string)$sheet->getCell('F' . $r)->getValue());
                        $gp   = trim((string)$sheet->getCell('G' . $r)->getValue());

                        if (empty($code) && empty($name)) continue; // skip blank rows

                        $marks_val = ($mrk !== '') ? (float)$mrk : null;
                        // Auto-calculate grade/grade_point from marks if columns are blank
                        if ($marks_val !== null && ($grd === '' || $gp === '')) {
                            [$auto_grade, $auto_gp] = gradeFromMark($marks_val);
                            if ($grd === '') $grd = $auto_grade;
                            if ($gp  === '') $gp  = $auto_gp;
                        }

                        $modules[] = [
                            'year'   => $yr !== '' ? (int)$yr : null,
                            'sem'    => $sem,
                            'code'   => $code,
                            'name'   => $name,
                            'marks'  => $marks_val,
                            'grade'  => $grd,
                            'gp'     => $gp !== '' ? (float)$gp : null,
                        ];
                    }

                    if (empty($modules)) {
                        $error = 'No module rows found. Make sure data starts at row 9.';
                    } else {
                        // Calculate overall GPA
                        $gp_sum = 0; $gp_count = 0;
                        foreach ($modules as $m) {
                            if ($m['gp'] !== null) { $gp_sum += $m['gp']; $gp_count++; }
                        }
                        $overall_gpa = $gp_count > 0 ? round($gp_sum / $gp_count, 2) : null;
                        $classification = '';
                        if ($overall_gpa !== null) {
                            if ($overall_gpa >= 3.8) $classification = 'First Class';
                            elseif ($overall_gpa >= 3.2) $classification = 'Upper Second Class';
                            elseif ($overall_gpa >= 2.5) $classification = 'Lower Second Class';
                            elseif ($overall_gpa >= 1.5) $classification = 'Pass';
                            else $classification = 'Fail';
                        }

                        $uid_by = (int)$user['user_id'];
                        $notes  = trim($_POST['notes'] ?? '');

                        $ins_up = $conn->prepare("INSERT INTO transcript_uploads (student_name, student_id_number, program, campus, year_of_entry, year_of_completion, overall_gpa, classification, uploaded_by, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $ins_up->bind_param("ssssiiidis",
                            $student_name, $student_id_num, $program, $campus,
                            $year_entry, $year_completion, $overall_gpa, $classification,
                            $uid_by, $notes
                        );
                        $ins_up->execute();
                        $new_upload_id = $conn->insert_id;

                        $ins_mod = $conn->prepare("INSERT INTO transcript_upload_modules (upload_id, year_of_study, semester, module_code, module_name, marks, grade, grade_point) VALUES (?,?,?,?,?,?,?,?)");
                        foreach ($modules as $m) {
                            $ins_mod->bind_param("iisssdsd",
                                $new_upload_id, $m['year'], $m['sem'], $m['code'],
                                $m['name'], $m['marks'], $m['grade'], $m['gp']
                            );
                            $ins_mod->execute();
                        }

                        $success = count($modules) . ' module record(s) uploaded successfully for <strong>' . htmlspecialchars($student_name) . '</strong>. Upload ID: #' . $new_upload_id;
                    }
                }
            } catch (\Exception $e) {
                $error = 'Could not read file: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// ── Load existing uploads for listing ────────────────────────────────────────
$uploads = [];
$search_q = trim($_GET['q'] ?? '');
if ($search_q !== '') {
    $like = '%' . $search_q . '%';
    $ur = $conn->prepare("SELECT u.*, (SELECT COUNT(*) FROM transcript_upload_modules WHERE upload_id=u.upload_id) AS module_count FROM transcript_uploads u WHERE u.student_name LIKE ? OR u.student_id_number LIKE ? ORDER BY u.uploaded_at DESC LIMIT 50");
    $ur->bind_param("ss", $like, $like);
} else {
    $ur = $conn->prepare("SELECT u.*, (SELECT COUNT(*) FROM transcript_upload_modules WHERE upload_id=u.upload_id) AS module_count FROM transcript_uploads u ORDER BY u.uploaded_at DESC LIMIT 50");
}
$ur->execute();
$uploads_rs = $ur->get_result();
while ($row = $uploads_rs->fetch_assoc()) $uploads[] = $row;

// Detail view
$view_upload = null;
$view_modules = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    $vs = $conn->prepare("SELECT * FROM transcript_uploads WHERE upload_id = ?");
    $vs->bind_param("i", $vid);
    $vs->execute();
    $view_upload = $vs->get_result()->fetch_assoc();
    if ($view_upload) {
        $vm = $conn->prepare("SELECT * FROM transcript_upload_modules WHERE upload_id = ? ORDER BY year_of_study, semester, module_code");
        $vm->bind_param("i", $vid);
        $vm->execute();
        $vm_rs = $vm->get_result();
        while ($r = $vm_rs->fetch_assoc()) $view_modules[] = $r;
    }
}

// Available graduation applications for linking
$grad_apps = [];
$gar = $conn->query("SELECT application_id, student_id_number, CONCAT(first_name,' ',last_name) as full_name FROM graduation_applications ORDER BY submitted_at DESC LIMIT 200");
if ($gar) while ($ga = $gar->fetch_assoc()) $grad_apps[] = $ga;

$page_title = 'Upload Old Transcript Grades';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $page_title ?> – VLE Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
    body{background:#f0f4f8;font-family:'Segoe UI',sans-serif;}
    .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .page-header{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
    .section-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
    .upload-zone{border:2px dashed #a78bfa;border-radius:12px;padding:2rem;text-align:center;background:#faf5ff;cursor:pointer;transition:.2s;}
    .upload-zone:hover{background:#f3e8ff;border-color:#7c3aed;}
    .grade-table thead th{background:#1a1a2e;color:#fff;font-size:.82rem;}
    .grade-table tbody tr:nth-child(even){background:#f8f5ff;}
    .badge-confirmed{background:#d1fae5;color:#065f46;}
    .badge-draft{background:#fef3c7;color:#92400e;}
    .step-gpa{font-weight:700;font-size:1.1rem;color:#7c3aed;}
</style>
</head>
<body>

<div class="top-bar">
    <a href="transcript-dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Transcript Dashboard
    </a>
    <span class="fw-bold text-purple" style="color:#7c3aed;"><i class="bi bi-upload me-1"></i><?= $page_title ?></span>
    <a href="?action=download_template" class="btn btn-sm btn-success">
        <i class="bi bi-file-earmark-excel me-1"></i> Download Template
    </a>
</div>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1200px;">

    <div class="page-header">
        <h4 class="mb-1"><i class="bi bi-file-earmark-arrow-up me-2"></i><?= $page_title ?></h4>
        <p class="mb-0 opacity-75">
            Fill the Excel template with grades from a paper or PDF transcript, then upload it here to create digital grade records.
        </p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($view_upload): ?>
    <!-- ── Detail View ──────────────────────────────────────────────────────── -->
    <div class="section-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-person-circle me-2 text-purple" style="color:#7c3aed;"></i><?= htmlspecialchars($view_upload['student_name']) ?></h5>
                <div class="text-muted small">
                    ID: <?= htmlspecialchars($view_upload['student_id_number'] ?? '—') ?> &nbsp;|&nbsp;
                    <?= htmlspecialchars($view_upload['program'] ?? '—') ?> &nbsp;|&nbsp;
                    <?= htmlspecialchars($view_upload['campus'] ?? '—') ?> &nbsp;|&nbsp;
                    Entry: <?= $view_upload['year_of_entry'] ?? '—' ?> &nbsp;|&nbsp;
                    Completion: <?= $view_upload['year_of_completion'] ?? '—' ?>
                </div>
                <?php if ($view_upload['overall_gpa']): ?>
                <div class="mt-1">
                    <span class="step-gpa">GPA: <?= number_format($view_upload['overall_gpa'], 2) ?></span>
                    &nbsp;<span class="badge bg-secondary"><?= htmlspecialchars($view_upload['classification'] ?? '') ?></span>
                    &nbsp;<span class="badge <?= $view_upload['status'] === 'confirmed' ? 'badge-confirmed' : 'badge-draft' ?>"><?= ucfirst($view_upload['status']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <a href="transcript-grades.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>

        <!-- Confirm / Link to Graduation Application -->
        <?php if ($view_upload['status'] === 'draft'): ?>
        <form method="POST" class="border rounded p-3 bg-light mb-3">
            <input type="hidden" name="action" value="confirm_upload">
            <input type="hidden" name="upload_id" value="<?= $view_upload['upload_id'] ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Link to Graduation Application (optional)</label>
                    <select name="link_app_id" class="form-select form-select-sm">
                        <option value="0">— No link (keep as standalone record) —</option>
                        <?php foreach ($grad_apps as $ga): ?>
                        <option value="<?= $ga['application_id'] ?>" <?= $view_upload['application_id'] == $ga['application_id'] ? 'selected' : '' ?>>
                            #<?= $ga['application_id'] ?> – <?= htmlspecialchars($ga['full_name']) ?> (<?= htmlspecialchars($ga['student_id_number'] ?? '') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-circle me-1"></i>Confirm &amp; Push Grades to System
                    </button>
                </div>
            </div>
            <div class="small text-muted mt-1">Confirming with a linked application will copy all modules into <code>graduation_ict_modules</code>, making them visible on the student transcript.</div>
        </form>
        <?php else: ?>
        <div class="alert alert-success py-2">
            <i class="bi bi-check-circle-fill me-1"></i>
            Confirmed<?= $view_upload['application_id'] ? ' — linked to Graduation Application #' . $view_upload['application_id'] : '' ?>.
        </div>
        <?php endif; ?>

        <!-- Modules Table -->
        <?php
        $by_year = [];
        foreach ($view_modules as $vm) $by_year[(int)($vm['year_of_study'] ?? 0)][] = $vm;
        ksort($by_year);
        ?>
        <?php foreach ($by_year as $yr => $mods): ?>
        <h6 class="mt-3 mb-2 text-muted"><i class="bi bi-calendar3 me-1"></i>Year <?= $yr ?: '?' ?></h6>
        <div class="table-responsive mb-3">
        <table class="table table-sm grade-table">
            <thead>
                <tr><th>Semester</th><th>Code</th><th>Module Name</th><th class="text-center">Marks</th><th class="text-center">Grade</th><th class="text-center">Grade Point</th></tr>
            </thead>
            <tbody>
            <?php foreach ($mods as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['semester'] ?? '—') ?></td>
                <td><code><?= htmlspecialchars($m['module_code'] ?? '—') ?></code></td>
                <td><?= htmlspecialchars($m['module_name']) ?></td>
                <td class="text-center"><?= $m['marks'] !== null ? number_format($m['marks'], 1) : '—' ?></td>
                <td class="text-center"><span class="badge bg-secondary"><?= htmlspecialchars($m['grade'] ?? '—') ?></span></td>
                <td class="text-center fw-bold"><?= $m['grade_point'] !== null ? number_format($m['grade_point'], 1) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- ── Upload Form ────────────────────────────────────────────────────────── -->
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="section-card">
                <h6 class="fw-bold mb-3"><i class="bi bi-upload me-2 text-purple" style="color:#7c3aed;"></i>Upload Filled Template</h6>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_transcript">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Student Name <span class="text-danger">*</span></label>
                        <input type="text" name="student_name" class="form-control" placeholder="e.g. Alice Manyunya"
                               value="<?= htmlspecialchars($_POST['student_name'] ?? '') ?>">
                        <div class="form-text">Overrides the name in the Excel file if both are provided (Excel row 1 takes priority).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Student ID Number</label>
                        <input type="text" name="student_id_number" class="form-control" placeholder="e.g. 2019/BSc/ICT/001"
                               value="<?= htmlspecialchars($_POST['student_id_number'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Program</label>
                        <input type="text" name="program" class="form-control" placeholder="e.g. BSc Information Technology"
                               value="<?= htmlspecialchars($_POST['program'] ?? '') ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label fw-bold">Year of Entry</label>
                            <input type="number" name="year_entry" class="form-control" placeholder="e.g. 2019" min="1990" max="2099"
                                   value="<?= htmlspecialchars($_POST['year_entry'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label class="form-label fw-bold">Year of Completion</label>
                            <input type="number" name="year_completion" class="form-control" placeholder="e.g. 2023" min="1990" max="2099"
                                   value="<?= htmlspecialchars($_POST['year_completion'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional admin notes about this upload"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Transcript Excel File <span class="text-danger">*</span></label>
                        <div class="upload-zone" onclick="document.getElementById('transcriptFile').click();">
                            <i class="bi bi-file-earmark-excel display-6 text-success"></i>
                            <p class="mb-0 mt-2">Click to select <strong>.xlsx</strong> or <strong>.csv</strong></p>
                            <p class="text-muted small mb-0" id="fileNameLabel">No file chosen</p>
                        </div>
                        <input type="file" id="transcriptFile" name="transcript_file" accept=".xlsx,.xls,.csv" class="d-none"
                               onchange="document.getElementById('fileNameLabel').textContent = this.files[0]?.name || 'No file chosen'">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-cloud-upload me-2"></i>Upload &amp; Convert to Grades
                    </button>
                </form>

                <hr>
                <div class="d-grid">
                    <a href="?action=download_template" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-arrow-down me-2"></i>Download Excel Template
                    </a>
                </div>
                <div class="small text-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Fill the template from the student's PDF transcript, then upload it above. Grades and grade points are auto-calculated from marks if left blank.
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <!-- Existing uploads -->
            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-purple" style="color:#7c3aed;"></i>Uploaded Transcripts</h6>
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name / ID…" value="<?= htmlspecialchars($search_q) ?>" style="width:200px;">
                        <button class="btn btn-sm btn-outline-secondary">Search</button>
                        <?php if ($search_q): ?><a href="transcript-grades.php" class="btn btn-sm btn-outline-danger">Clear</a><?php endif; ?>
                    </form>
                </div>

                <?php if (empty($uploads)): ?>
                <p class="text-muted text-center py-4"><i class="bi bi-inbox display-5 d-block mb-2"></i>No transcripts uploaded yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>ID</th>
                            <th class="text-center">Modules</th>
                            <th class="text-center">GPA</th>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($uploads as $u): ?>
                    <tr>
                        <td class="text-muted"><?= $u['upload_id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($u['student_name']) ?></strong>
                            <div class="text-muted small"><?= htmlspecialchars($u['program'] ?? '') ?></div>
                        </td>
                        <td class="small"><?= htmlspecialchars($u['student_id_number'] ?? '—') ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $u['module_count'] ?></span></td>
                        <td class="text-center fw-bold <?= $u['overall_gpa'] ? '' : 'text-muted' ?>">
                            <?= $u['overall_gpa'] ? number_format($u['overall_gpa'], 2) : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $u['status'] === 'confirmed' ? 'badge-confirmed' : 'badge-draft' ?>">
                                <?= $u['status'] === 'confirmed' ? '<i class="bi bi-check-circle me-1"></i>' : '<i class="bi bi-pencil me-1"></i>' ?>
                                <?= ucfirst($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="?view=<?= $u['upload_id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2">View</a>
                            <button type="button" class="btn btn-xs btn-outline-danger btn-sm py-0 px-2"
                                    data-bs-toggle="modal" data-bs-target="#delModal<?= $u['upload_id'] ?>">Del</button>
                            <!-- Delete modal -->
                            <div class="modal fade" id="delModal<?= $u['upload_id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-sm">
                                    <div class="modal-content">
                                        <div class="modal-header"><h6 class="modal-title">Delete Upload?</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">Delete transcript upload for <strong><?= htmlspecialchars($u['student_name']) ?></strong>? This cannot be undone.</div>
                                        <div class="modal-footer">
                                            <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="delete_upload">
                                                <input type="hidden" name="upload_id" value="<?= $u['upload_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /row -->
    <?php endif; ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
