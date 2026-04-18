<?php
/**
 * Printable Academic Calendar Template
 * Resembles the original PDF format with university header and semester tables
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();

// Filters
$filter_year = $_GET['year'] ?? '';
$filter_program = $_GET['program'] ?? '';

// Get available academic years
$years = [];
$yr = $conn->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC");
if ($yr) { while ($r = $yr->fetch_assoc()) { $years[] = $r['academic_year']; } }

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if ($filter_year) {
    $where[] = "academic_year = ?";
    $params[] = $filter_year;
    $types .= 's';
}
if ($filter_program && in_array($filter_program, ['weekday', 'weekend'])) {
    $where[] = "(program_type = ? OR program_type = 'all')";
    $params[] = $filter_program;
    $types .= 's';
}

$sql = "SELECT * FROM academic_calendar WHERE " . implode(' AND ', $where) . " ORDER BY semester ASC, start_date ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Group events by semester
$semesters = [];
while ($row = $result->fetch_assoc()) {
    $key = $row['semester'];
    if (!isset($semesters[$key])) {
        $semesters[$key] = ['academic_year' => $row['academic_year'], 'events' => []];
    }
    $semesters[$key]['events'][] = $row;
}

// Get university settings
$uni = ['university_name' => 'Exploits University', 'logo_path' => '', 'address_po_box' => '', 'address_city' => '', 'address_country' => '', 'phone' => '', 'email' => '', 'website' => ''];
$uni_result = $conn->query("SELECT * FROM university_settings LIMIT 1");
if ($uni_result && $uni_result->num_rows > 0) {
    $uni = array_merge($uni, $uni_result->fetch_assoc());
}

$conn->close();

// Determine date range for title
$all_dates = [];
foreach ($semesters as $sem) {
    foreach ($sem['events'] as $evt) {
        $all_dates[] = $evt['start_date'];
        if ($evt['end_date']) $all_dates[] = $evt['end_date'];
    }
}
sort($all_dates);
$date_range = '';
if (!empty($all_dates)) {
    $first = date('F Y', strtotime(reset($all_dates)));
    $last = date('F Y', strtotime(end($all_dates)));
    $date_range = $first . ' - ' . $last;
}

// Semester label mapping - load from DB config
$semester_label_map = [];
$slm_result = $conn->query("SELECT * FROM calendar_year_config ORDER BY calendar_year DESC, semester_number ASC");
if ($slm_result) {
    while ($slm_row = $slm_result->fetch_assoc()) {
        $key = $slm_row['academic_year_label'] . '_' . $slm_row['semester_number'];
        $semester_label_map[$key] = $slm_row['semester_label'];
        // Also store by semester_number only as fallback
        if (!isset($semester_label_map['_' . $slm_row['semester_number']])) {
            $semester_label_map['_' . $slm_row['semester_number']] = $slm_row['semester_label'];
        }
    }
}

function getSemesterLabel($sem_num, $ay) {
    global $semester_label_map;
    // Try exact match first
    $key = $ay . '_' . $sem_num;
    if (isset($semester_label_map[$key])) {
        return strtoupper($semester_label_map[$key]);
    }
    // Fallback to semester number only
    if (isset($semester_label_map['_' . $sem_num])) {
        return strtoupper($semester_label_map['_' . $sem_num]);
    }
    $labels = [
        '1' => 'SEMESTER ONE',
        '2' => 'SEMESTER TWO',
        '3' => 'SEMESTER THREE'
    ];
    return $labels[$sem_num] ?? "SEMESTER $sem_num";
}

function getSemesterDateRange($events) {
    $dates = [];
    foreach ($events as $e) {
        $dates[] = $e['start_date'];
        if ($e['end_date']) $dates[] = $e['end_date'];
    }
    sort($dates);
    if (empty($dates)) return '';
    return date('F', strtotime(reset($dates))) . ' - ' . date('F Y', strtotime(end($dates)));
}

function formatDateRange($start, $end) {
    if (!$end) {
        return date('jS F, Y', strtotime($start));
    }
    $s = strtotime($start);
    $e = strtotime($end);
    if (date('F Y', $s) === date('F Y', $e)) {
        return date('jS', $s) . ' - ' . date('jS F, Y', $e);
    }
    return date('jS F', $s) . ' - ' . date('jS F, Y', $e);
}

function getProgramLabel($type) {
    switch ($type) {
        case 'weekday': return '(Day)';
        case 'weekend': return '(Weekend)';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar <?= htmlspecialchars($date_range) ?> - <?= htmlspecialchars($uni['university_name']) ?></title>
    <style>
        /* Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            color: #000;
            background: #fff;
            line-height: 1.4;
        }

        /* Print controls - hidden when printing */
        .print-controls {
            background: #f8f9fa;
            border-bottom: 2px solid #1a472a;
            padding: 12px 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .print-controls label { font-family: Arial, sans-serif; font-size: 13px; color: #333; }
        .print-controls select, .print-controls button {
            font-family: Arial, sans-serif;
            font-size: 13px;
            padding: 6px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .print-controls button {
            background: #1a472a;
            color: #fff;
            border: none;
            cursor: pointer;
            padding: 8px 20px;
            font-weight: bold;
        }
        .print-controls button:hover { background: #2d5a3e; }
        .print-controls .btn-back {
            background: #6c757d;
            text-decoration: none;
            display: inline-block;
        }
        .print-controls .btn-back:hover { background: #5a6268; }

        /* Page container */
        .page {
            max-width: 210mm;
            margin: 0 auto;
            padding: 15mm 20mm;
        }

        /* University header */
        .uni-header {
            text-align: center;
            margin-bottom: 8mm;
            border-bottom: 3px double #1a472a;
            padding-bottom: 5mm;
        }
        .uni-header img {
            height: 70px;
            margin-bottom: 3mm;
        }
        .uni-header h1 {
            font-size: 20pt;
            text-transform: uppercase;
            color: #1a472a;
            letter-spacing: 3px;
            margin-bottom: 2mm;
        }
        .uni-header .subtitle {
            font-size: 9pt;
            color: #555;
            margin-bottom: 1mm;
        }

        /* Calendar title */
        .cal-title {
            text-align: center;
            margin: 6mm 0;
        }
        .cal-title h2 {
            font-size: 16pt;
            text-transform: uppercase;
            color: #1a472a;
            letter-spacing: 2px;
            border-bottom: 1px solid #1a472a;
            display: inline-block;
            padding-bottom: 2mm;
        }
        .cal-title .date-range {
            font-size: 12pt;
            color: #333;
            margin-top: 3mm;
            font-style: italic;
        }
        .cal-title .program-label {
            font-size: 10pt;
            color: #666;
            margin-top: 2mm;
        }

        /* Semester section */
        .semester-section {
            margin-bottom: 8mm;
            page-break-inside: avoid;
        }
        .semester-header {
            background: #1a472a;
            color: #fff;
            padding: 3mm 5mm;
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .semester-header .sem-dates {
            font-size: 9pt;
            font-weight: normal;
            font-style: italic;
            letter-spacing: 0;
        }

        /* Events table */
        .events-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        .events-table th {
            background: #e8efe8;
            color: #1a472a;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2.5mm 3mm;
            border: 0.5px solid #999;
            text-align: left;
        }
        .events-table th.col-no { width: 7%; text-align: center; }
        .events-table th.col-event { width: 40%; }
        .events-table th.col-date { width: 28%; }
        .events-table th.col-program { width: 12%; text-align: center; }
        .events-table th.col-type { width: 13%; text-align: center; }

        .events-table td {
            padding: 2mm 3mm;
            border: 0.5px solid #bbb;
            font-size: 10pt;
            vertical-align: top;
        }
        .events-table td.col-no { text-align: center; color: #666; }
        .events-table td.col-program { text-align: center; font-size: 9pt; }
        .events-table td.col-type { text-align: center; font-size: 8.5pt; }

        .events-table tr:nth-child(even) { background: #f9fbf9; }
        .events-table tr.row-holiday { background: #fff8e1; }
        .events-table tr.row-exam { background: #fff3e0; }
        .events-table tr.row-break { background: #e8f5e9; }

        .event-name { font-weight: bold; }
        .event-desc { font-size: 8.5pt; color: #555; display: block; margin-top: 1mm; }

        .type-badge {
            display: inline-block;
            padding: 1mm 2mm;
            border-radius: 2px;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .type-semester_start { background: #c8e6c9; color: #2e7d32; }
        .type-semester_end { background: #ffcdd2; color: #c62828; }
        .type-exam_start, .type-exam_end { background: #ffe0b2; color: #e65100; }
        .type-registration_start, .type-registration_end { background: #bbdefb; color: #1565c0; }
        .type-holiday { background: #fff9c4; color: #f57f17; }
        .type-break { background: #c8e6c9; color: #2e7d32; }
        .type-graduation { background: #e1bee7; color: #6a1b9a; }
        .type-other { background: #e0e0e0; color: #424242; }

        .program-badge {
            display: inline-block;
            padding: 0.5mm 2mm;
            border-radius: 2px;
            font-size: 7.5pt;
            font-weight: bold;
        }
        .program-all { background: #e3f2fd; color: #1565c0; }
        .program-weekday { background: #e0f2f1; color: #00695c; }
        .program-weekend { background: #fff3e0; color: #e65100; }

        /* Footer */
        .doc-footer {
            margin-top: 8mm;
            padding-top: 3mm;
            border-top: 1px solid #999;
            font-size: 8pt;
            color: #666;
            display: flex;
            justify-content: space-between;
        }

        /* Print styles */
        @media print {
            .print-controls { display: none !important; }
            body { font-size: 10pt; }
            .page { padding: 10mm 15mm; max-width: none; }
            .semester-section { page-break-inside: avoid; }
            .events-table { page-break-inside: auto; }
            .events-table tr { page-break-inside: avoid; }
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- Print Controls (hidden when printing) -->
    <div class="print-controls">
        <a href="academic-calendar.php" class="btn-back" style="color:#fff; border-radius:4px; padding:8px 16px; font-size:13px;">&#8592; Back</a>
        <label>Academic Year:</label>
        <select id="filterYear" onchange="applyFilters()">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= htmlspecialchars($y) ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Program:</label>
        <select id="filterProgram" onchange="applyFilters()">
            <option value="">All Programs</option>
            <option value="weekday" <?= $filter_program === 'weekday' ? 'selected' : '' ?>>Weekday Only</option>
            <option value="weekend" <?= $filter_program === 'weekend' ? 'selected' : '' ?>>Weekend Only</option>
        </select>
        <button onclick="window.print()">&#128438; Print Calendar</button>
    </div>

    <div class="page">
        <!-- University Header -->
        <div class="uni-header">
            <?php
            $logo_path = '';
            if (!empty($uni['logo_path'])) {
                $logo_path = $uni['logo_path'];
                if (strpos($logo_path, '../') === 0) {
                    $logo_path = substr($logo_path, 3);
                }
                $logo_path = '../' . $logo_path;
            }
            ?>
            <?php if ($logo_path): ?>
            <img src="<?= htmlspecialchars($logo_path) ?>" alt="University Logo">
            <?php endif; ?>
            <h1><?= htmlspecialchars($uni['university_name']) ?></h1>
            <?php if (!empty($uni['address_po_box']) || !empty($uni['address_city'])): ?>
            <div class="subtitle">
                <?= htmlspecialchars(implode(', ', array_filter([$uni['address_po_box'], $uni['address_area'] ?? '', $uni['address_city'], $uni['address_country']]))) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($uni['phone']) || !empty($uni['email'])): ?>
            <div class="subtitle">
                <?= htmlspecialchars(implode(' | ', array_filter([$uni['phone'], $uni['email'], $uni['website']]))) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Calendar Title -->
        <div class="cal-title">
            <h2>Academic Calendar</h2>
            <?php if ($date_range): ?>
            <div class="date-range"><?= htmlspecialchars($date_range) ?></div>
            <?php endif; ?>
            <?php if ($filter_program): ?>
            <div class="program-label"><?= ucfirst($filter_program) ?> Program</div>
            <?php endif; ?>
        </div>

        <?php if (empty($semesters)): ?>
        <div style="text-align:center; padding:20mm 0; color:#999;">
            <p style="font-size:14pt;">No calendar events found.</p>
            <p>Please select a different academic year or check the calendar settings.</p>
        </div>
        <?php endif; ?>

        <?php
        $type_labels = [
            'semester_start' => 'Sem Start',
            'semester_end' => 'Sem End',
            'exam_start' => 'Exams',
            'exam_end' => 'Exams End',
            'registration_start' => 'Registration',
            'registration_end' => 'Reg. Close',
            'holiday' => 'Holiday',
            'break' => 'Break',
            'graduation' => 'Graduation',
            'other' => 'Activity'
        ];

        foreach ($semesters as $sem_num => $sem_data):
            $sem_label = getSemesterLabel($sem_num, $sem_data['academic_year']);
            $sem_range = getSemesterDateRange($sem_data['events']);
        ?>
        <div class="semester-section">
            <div class="semester-header">
                <span><?= $sem_label ?> &mdash; Academic Year <?= htmlspecialchars($sem_data['academic_year']) ?></span>
                <span class="sem-dates"><?= htmlspecialchars($sem_range) ?></span>
            </div>
            <table class="events-table">
                <thead>
                    <tr>
                        <th class="col-no">No.</th>
                        <th class="col-event">Event / Activity</th>
                        <th class="col-date">Date(s)</th>
                        <th class="col-program">Program</th>
                        <th class="col-type">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sem_data['events'] as $i => $event):
                        $row_class = '';
                        if ($event['event_type'] === 'holiday') $row_class = 'row-holiday';
                        elseif (in_array($event['event_type'], ['exam_start', 'exam_end'])) $row_class = 'row-exam';
                        elseif ($event['event_type'] === 'break') $row_class = 'row-break';
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="col-no"><?= $i + 1 ?></td>
                        <td>
                            <span class="event-name"><?= htmlspecialchars($event['event_name']) ?></span>
                            <?php if (!empty($event['description']) && $event['description'] !== $event['event_name']): ?>
                            <span class="event-desc"><?= htmlspecialchars($event['description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateRange($event['start_date'], $event['end_date']) ?></td>
                        <td class="col-program">
                            <span class="program-badge program-<?= $event['program_type'] ?? 'all' ?>">
                                <?= ucfirst($event['program_type'] ?? 'All') ?>
                            </span>
                        </td>
                        <td class="col-type">
                            <span class="type-badge type-<?= $event['event_type'] ?>">
                                <?= $type_labels[$event['event_type']] ?? ucfirst($event['event_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- Document Footer -->
        <div class="doc-footer">
            <span><?= htmlspecialchars($uni['university_name']) ?> &mdash; Office of the Registrar</span>
            <span>Generated: <?= date('jS F, Y') ?></span>
        </div>
    </div>

    <script>
        function applyFilters() {
            var year = document.getElementById('filterYear').value;
            var program = document.getElementById('filterProgram').value;
            var url = 'print_calendar.php?';
            if (year) url += 'year=' + encodeURIComponent(year) + '&';
            if (program) url += 'program=' + encodeURIComponent(program) + '&';
            window.location.href = url;
        }
    </script>
</body>
</html>
