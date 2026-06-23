<?php
/**
 * Student List Report
 * Accessible from Admin, Finance, and Dean portals.
 * Shows four student categories:
 *   1. All students in the system (students table)
 *   2. All VLE-enrolled students (vle_enrollments)
 *   3. All dissertation students (dissertations)
 *   4. All exam-clearance students (exam_clearance_students)
 * Filters: source, campus, year, semester, program type, course
 * Export: Excel (.xlsx) and PDF (print)
 */
ob_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['admin', 'super_admin', 'staff', 'finance', 'dean']);

$conn = getDbConnection();

// ─── AJAX: inline campus / program-type update (admin/super_admin only) ──────
if (isset($_POST['slr_action']) && in_array($_SESSION['vle_role'] ?? '', ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    ob_end_clean();

    $action = $_POST['slr_action'];

    if ($action === 'update_campus') {
        $old = trim($_POST['old_val'] ?? '');
        $new = trim($_POST['new_val'] ?? '');
        if ($old === '' || $new === '' || $old === $new) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid values.']);
            exit;
        }
        // Validate new_val exists in DB to prevent arbitrary injection
        $chk = $conn->prepare("SELECT COUNT(*) AS n FROM students WHERE campus = ?");
        $chk->bind_param('s', $new);
        $chk->execute();
        // new campus may not yet exist — that's fine, just ensure it's a safe string
        $chk->close();

        $stmt = $conn->prepare("UPDATE students SET campus = ? WHERE campus = ?");
        $stmt->bind_param('ss', $new, $old);
        $stmt->execute();
        $aff1 = $stmt->affected_rows;
        $stmt->close();

        $stmt2 = $conn->prepare("UPDATE exam_clearance_students SET campus = ? WHERE campus = ?");
        $stmt2->bind_param('ss', $new, $old);
        $stmt2->execute();
        $aff2 = $stmt2->affected_rows;
        $stmt2->close();

        echo json_encode(['ok' => true, 'msg' => "Updated " . ($aff1 + $aff2) . " record(s).", 'new_label' => $new]);
        exit;
    }

    if ($action === 'update_program') {
        $old = trim($_POST['old_val'] ?? '');
        $new = trim($_POST['new_val'] ?? '');
        $allowed = ['degree', 'diploma', 'professional', 'masters', 'doctorate', 'postgraduate', 'mba'];
        if ($old === '' || $new === '' || $old === $new || !in_array(strtolower($new), $allowed, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid program type.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE students SET program_type = ? WHERE program_type = ?");
        $stmt->bind_param('ss', $new, $old);
        $stmt->execute();
        $aff = $stmt->affected_rows;
        $stmt->close();

        echo json_encode(['ok' => true, 'msg' => "Updated " . $aff . " record(s).", 'new_label' => $new]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

// ─── Filters ─────────────────────────────────────────────────────────────────
$filter_source   = trim($_GET['source']   ?? 'all');         // all|vle|dissertation|exam_clearance
$filter_campus   = trim($_GET['campus']   ?? '');
$filter_year     = trim($_GET['year']     ?? '');
$filter_semester = trim($_GET['semester'] ?? '');
$filter_program  = trim($_GET['program']  ?? '');            // program_type enum value
$filter_course   = (int)($_GET['course']  ?? 0);             // vle_courses.course_id
$filter_search   = trim($_GET['search']   ?? '');
// True whenever any non-default filter is active (used to show "Filtered" badge on cards)
$has_active_filter = ($filter_source !== 'all' || $filter_campus !== '' || $filter_year !== ''
                   || $filter_semester !== '' || $filter_program !== '' || $filter_course > 0
                   || $filter_search !== '');

// ─── Dropdown options (always from students + vle_courses) ───────────────────
$campuses = [];
$cr = $conn->query("SELECT DISTINCT campus FROM students WHERE campus IS NOT NULL AND campus != '' ORDER BY campus");
if ($cr) while ($r = $cr->fetch_assoc()) $campuses[] = $r['campus'];
// Add EC campuses too
$cr2 = $conn->query("SELECT DISTINCT campus FROM exam_clearance_students WHERE campus IS NOT NULL AND campus != ''");
if ($cr2) while ($r = $cr2->fetch_assoc()) if (!in_array($r['campus'], $campuses)) $campuses[] = $r['campus'];
sort($campuses);

$programs = [
    'degree'        => 'Degree',
    'diploma'       => 'Diploma',
    'professional'  => 'Professional',
    'postgraduate'  => 'Postgraduate (Masters & Doctorate)',
    'masters'       => 'Masters',
    'doctorate'     => 'Doctorate',
];

$courses = [];
$cqr = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_code");
if ($cqr) while ($r = $cqr->fetch_assoc()) $courses[] = $r;

// ─── University settings for header ──────────────────────────────────────────
$uni_name = 'Eastern University of Management and Wellbeing';
$uni_short = 'EUMW';
$ur = $conn->query("SELECT university_name FROM university_settings LIMIT 1");
if ($ur && $row = $ur->fetch_assoc()) {
    $uni_name  = $row['university_name'] ?? $uni_name;
}

// ─── Build main query based on source ────────────────────────────────────────
/**
 * We build a UNION query across up to 4 sources.
 * Each arm returns a normalised set of columns:
 *   source_label, student_id, full_name, email, campus,
 *   program_type, program, year_of_study, semester, enrolled_courses
 */

// Helper: campus/year/semester/program_type/course WHERE clauses
// We return arrays [where_parts, params, types] to be merged per arm.

function applyFiltersToArm(array $filters, string $campus_col, string $year_col,
                           string $sem_col, string $ptype_col,
                           string $sid_col, string $name_col,
                           string $email_col, string $table_alias,
                           $conn, int $course_filter): array {
    $where = [];
    $params = [];
    $types = '';

    if ($filters['campus'] !== '') {
        $where[] = "$table_alias.$campus_col = ?";
        $params[] = $filters['campus'];
        $types .= 's';
    }
    if ($filters['year'] !== '') {
        $where[] = "$table_alias.$year_col = ?";
        $params[] = (int)$filters['year'];
        $types .= 'i';
    }
    if ($filters['semester'] !== '') {
        $where[] = "$table_alias.$sem_col = ?";
        $params[] = $filters['semester'];
        $types .= 's';
    }
    if ($filters['program'] !== '') {
        if ($filters['program'] === 'postgraduate') {
            // Special combined filter: masters + doctorate
            $where[] = "$table_alias.$ptype_col IN (?,?)";
            $params[] = 'masters';
            $params[] = 'doctorate';
            $types .= 'ss';
        } else {
            $where[] = "$table_alias.$ptype_col = ?";
            $params[] = $filters['program'];
            $types .= 's';
        }
    }
    if ($filters['search'] !== '') {
        $s = '%' . $filters['search'] . '%';
        $where[] = "($table_alias.$name_col LIKE ? OR $table_alias.$sid_col LIKE ? OR $table_alias.$email_col LIKE ?)";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
        $types .= 'sss';
    }

    return [$where, $params, $types];
}

$filters = [
    'campus'   => $filter_campus,
    'year'     => $filter_year,
    'semester' => $filter_semester,
    'program'  => $filter_program,
    'search'   => $filter_search,
];

// ── Arm 1: All students ──────────────────────────────────────────────────────
function buildSystemArm(array $filters, int $course_filter, mysqli $conn): array {
    // Detect program column
    $pc = 'program';
    $chk = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
    if ($chk && $chk->num_rows > 0) $pc = 'program_of_study';

    [$where, $params, $types] = applyFiltersToArm(
        $filters, 'campus', 'year_of_study', 'semester', 'program_type',
        'student_id', 'full_name', 'email', 's', $conn, $course_filter
    );

    // If a course filter is set, join vle_enrollments
    $join = '';
    if ($course_filter > 0) {
        $join = "INNER JOIN vle_enrollments ve ON ve.student_id = s.student_id AND ve.course_id = ?";
        array_unshift($params, $course_filter);
        $types = 'i' . $types;
    }

    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 'All Students' AS source_label,
                   s.student_id, s.full_name, s.email,
                   COALESCE(s.campus,'') AS campus,
                   COALESCE(s.program_type,'') AS program_type,
                   COALESCE(s.$pc,'') AS program,
                   COALESCE(s.year_of_study,'') AS year_of_study,
                   COALESCE(s.semester,'') AS semester
            FROM students s $join $w";
    return [$sql, $params, $types];
}

// ── Arm 2: VLE-enrolled students ─────────────────────────────────────────────
function buildVleArm(array $filters, int $course_filter, mysqli $conn): array {
    $pc = 'program';
    $chk = $conn->query("SHOW COLUMNS FROM students LIKE 'program_of_study'");
    if ($chk && $chk->num_rows > 0) $pc = 'program_of_study';

    [$where, $params, $types] = applyFiltersToArm(
        $filters, 'campus', 'year_of_study', 'semester', 'program_type',
        'student_id', 'full_name', 'email', 's', $conn, $course_filter
    );

    // VLE students must have at least one enrollment
    if ($course_filter > 0) {
        // Specific course
        $where[] = "ve.course_id = ?";
        $params[] = $course_filter;
        $types .= 'i';
        $join = "INNER JOIN vle_enrollments ve ON ve.student_id = s.student_id";
    } else {
        $join = "INNER JOIN vle_enrollments ve ON ve.student_id = s.student_id";
    }

    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 'VLE Students' AS source_label,
                   s.student_id, s.full_name, s.email,
                   COALESCE(s.campus,'') AS campus,
                   COALESCE(s.program_type,'') AS program_type,
                   COALESCE(s.$pc,'') AS program,
                   COALESCE(s.year_of_study,'') AS year_of_study,
                   COALESCE(s.semester,'') AS semester
            FROM students s $join $w GROUP BY s.student_id";
    return [$sql, $params, $types];
}

// ── Arm 3: Dissertation students ─────────────────────────────────────────────
// Includes:
//   (a) any student with a row in the dissertations table, AND
//   (b) any system-registered student with program_type IN ('masters','doctorate')
//       who has not yet submitted a dissertation record.
function buildDissertationArm(array $filters, int $course_filter): array {
    [$where, $params, $types] = applyFiltersToArm(
        $filters, 'campus', 'year_of_study', 'semester', 'program_type',
        'student_id', 'full_name', 'email', 's', null, $course_filter
    );

    // Use LEFT JOIN so system-registered dissertation students also appear.
    // The extra condition ensures we only include rows that either have a
    // dissertation record OR are in a postgrad/dissertation program.
    $extra = "(d.student_id IS NOT NULL OR s.program_type IN ('masters','doctorate'))";
    $w = $where
        ? 'WHERE ' . implode(' AND ', $where) . " AND $extra"
        : "WHERE $extra";

    $sql = "SELECT 'Dissertation' AS source_label,
                   s.student_id, s.full_name, s.email,
                   COALESCE(s.campus,'') AS campus,
                   COALESCE(s.program_type, COALESCE(d.program_type,'')) AS program_type,
                   COALESCE(d.program, COALESCE(s.program,'')) AS program,
                   COALESCE(s.year_of_study, COALESCE(d.year_of_study,'')) AS year_of_study,
                   COALESCE(s.semester, COALESCE(d.semester,'')) AS semester
            FROM students s
            LEFT JOIN dissertations d ON d.student_id = s.student_id
            $w
            GROUP BY s.student_id";
    return [$sql, $params, $types];
}

// ── Arm 4: Exam-Clearance students ───────────────────────────────────────────
function buildEcArm(array $filters, int $course_filter): array {
    [$where, $params, $types] = applyFiltersToArm(
        $filters, 'campus', 'year_of_study', 'semester', 'program_type',
        'student_id', 'full_name', 'email', 'ecs', null, $course_filter
    );

    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 'Exam Clearance' AS source_label,
                   ecs.student_id, ecs.full_name, ecs.email,
                   COALESCE(ecs.campus,'') AS campus,
                   COALESCE(ecs.program_type,'') AS program_type,
                   COALESCE(ecs.program,'') AS program,
                   COALESCE(ecs.year_of_study,'') AS year_of_study,
                   COALESCE(ecs.semester,'') AS semester
            FROM exam_clearance_students ecs
            $w
            GROUP BY ecs.student_id, ecs.clearance_type";
    return [$sql, $params, $types];
}

// ─── Select which arms to include ─────────────────────────────────────────────
$arms = [];
if ($filter_source === 'all' || $filter_source === 'system') {
    $arms[] = buildSystemArm($filters, $filter_course, $conn);
}
if ($filter_source === 'all' || $filter_source === 'vle') {
    $arms[] = buildVleArm($filters, $filter_course, $conn);
}
if ($filter_source === 'all' || $filter_source === 'dissertation') {
    $arms[] = buildDissertationArm($filters, $filter_course);
}
if ($filter_source === 'all' || $filter_source === 'exam_clearance') {
    $arms[] = buildEcArm($filters, $filter_course);
}

// If no source matched (shouldn't happen with valid input) fall back to system
if (empty($arms)) {
    $arms[] = buildSystemArm($filters, $filter_course, $conn);
}

// Build final UNION query
$union_parts = array_column($arms, 0);
$all_params  = [];
$all_types   = '';
foreach ($arms as $arm) {
    $all_params = array_merge($all_params, $arm[1]);
    $all_types .= $arm[2];
}

$union_sql = implode(' UNION ALL ', array_map(fn($s) => "($s)", $union_parts));
$final_sql = "SELECT * FROM ($union_sql) AS combined ORDER BY source_label, full_name";

// ─── Execute ──────────────────────────────────────────────────────────────────
$students = [];
if (!empty($all_params)) {
    $stmt = $conn->prepare($final_sql);
    if ($stmt) {
        $stmt->bind_param($all_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $students[] = $row;
    }
} else {
    $result = $conn->query($final_sql);
    if ($result) while ($row = $result->fetch_assoc()) $students[] = $row;
}

// ─── Counts per source ────────────────────────────────────────────────────────
$count_by_source = [];
foreach ($students as $s) {
    $count_by_source[$s['source_label']] = ($count_by_source[$s['source_label']] ?? 0) + 1;
}

// ─── Display rows: one row per student, highest-priority source wins ──────────
// Priority: Exam Clearance > Dissertation > VLE > other specific sources.
// The system arm ('All Students') is never shown in the table.
$_src_priority = ['Exam Clearance' => 1, 'Dissertation' => 2, 'VLE Students' => 3];
$_dedup = [];
foreach ($students as $_ds) {
    if ($_ds['source_label'] === 'All Students') continue;
    $sid  = $_ds['student_id'];
    $prio = $_src_priority[$_ds['source_label']] ?? 99;
    if (!isset($_dedup[$sid]) || $prio < ($_src_priority[$_dedup[$sid]['source_label']] ?? 99)) {
        $_dedup[$sid] = $_ds;
    }
}
$table_students = array_values($_dedup);

// ─── Postgraduate (Masters / MBA) count ──────────────────────────────────────
$count_postgraduate = 0;
$seen_postgrad_ids  = [];
foreach ($students as $s) {
    $ptype = strtolower(trim($s['program_type'] ?? ''));
    $prog  = strtoupper(trim($s['program']      ?? ''));
    if ($ptype === 'masters' || $ptype === 'doctorate'
        || str_contains($prog, 'MBA') || str_contains($prog, 'MASTER')) {
        $uid = $s['student_id'] . '|' . $s['source_label'];
        if (!isset($seen_postgrad_ids[$uid])) {
            $seen_postgrad_ids[$uid] = true;
            $count_postgraduate++;
        }
    }
}

// ─── Summary Statistics — All Sources Combined (mirrors "Records Found") ──────
// Derived from $students (UNION ALL: All Students + VLE + Dissertation + Exam Clearance).
// Total equals count($students) — the same number shown in "Records Found".
$total_all_students = count($students);

// Gender: check column, build student_id → 'Male'|'Female'|'Other' lookup
$has_gender    = false;
$gender_lookup = [];
$gc = $conn->query("SHOW COLUMNS FROM students LIKE 'gender'");
if ($gc && $gc->num_rows > 0) {
    $has_gender = true;
    $gr = $conn->query("SELECT student_id, COALESCE(NULLIF(TRIM(gender),''),'') AS gender FROM students");
    if ($gr) while ($grow = $gr->fetch_assoc()) {
        $g = strtolower(trim($grow['gender']));
        if ($g === 'male'   || $g === 'm') $g = 'Male';
        elseif ($g === 'female' || $g === 'f') $g = 'Female';
        else $g = 'Other';
        $gender_lookup[$grow['student_id']] = $g;
    }
}

// Output arrays (same shapes as before so HTML is unchanged)
$sum_campus          = [];
$sum_program         = [];
$sum_year            = [];
$sum_semester        = [];
$sum_class           = [];
$sum_gender          = [];
$sum_campus_gender   = [];
$sum_program_gender  = [];
$sum_year_gender     = [];
$sum_class_gender    = [];
$sum_postgrad        = [];
$sum_postgrad_gender = [];

// Temporary accumulators
$_c_agg  = [];  // campus → cnt
$_p_agg  = [];  // program_type → cnt
$_y_agg  = [];  // yr → cnt
$_s_agg  = [];  // semester → cnt
$_cl_agg = [];  // yr → sem → cnt
$_g_agg  = ['Male' => 0, 'Female' => 0, 'Other' => 0];
$_pg_agg = [];  // pg program_type → cnt
$_pg_g   = [];  // pg program_type → gender → cnt
$_pg_types = ['masters', 'doctorate', 'postgraduate', 'mba'];

// Iterate the deduplicated table list — one row per student — so every
// breakdown (campus, program, year, class, gender) counts each student once.
foreach ($table_students as $s) {
    $camp = ($s['campus']        !== '') ? $s['campus']        : '(Not Set)';
    $pt   = ($s['program_type']  !== '') ? $s['program_type']  : '(Not Set)';
    $yr   = ($s['year_of_study'] !== '') ? $s['year_of_study'] : '(Not Set)';
    $sm   = ($s['semester']      !== '') ? $s['semester']      : '(Not Set)';
    $g    = $has_gender ? ($gender_lookup[$s['student_id']] ?? 'Other') : 'Other';

    // Campus
    $_c_agg[$camp] = ($_c_agg[$camp] ?? 0) + 1;
    if ($has_gender) {
        if (!isset($sum_campus_gender[$camp])) $sum_campus_gender[$camp] = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $sum_campus_gender[$camp][$g]++;
    }

    // Program type
    $_p_agg[$pt] = ($_p_agg[$pt] ?? 0) + 1;
    if ($has_gender) {
        if (!isset($sum_program_gender[$pt])) $sum_program_gender[$pt] = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $sum_program_gender[$pt][$g]++;
    }

    // Year of study
    $_y_agg[$yr] = ($_y_agg[$yr] ?? 0) + 1;
    if ($has_gender) {
        if (!isset($sum_year_gender[$yr])) $sum_year_gender[$yr] = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $sum_year_gender[$yr][$g]++;
    }

    // Semester
    $_s_agg[$sm] = ($_s_agg[$sm] ?? 0) + 1;

    // Class (year × semester)
    if (!isset($_cl_agg[$yr][$sm])) $_cl_agg[$yr][$sm] = 0;
    $_cl_agg[$yr][$sm]++;
    if ($has_gender) {
        if (!isset($sum_class_gender[$yr][$sm])) $sum_class_gender[$yr][$sm] = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $sum_class_gender[$yr][$sm][$g]++;
    }

    // Gender overall
    if ($has_gender) $_g_agg[$g]++;

    // Postgraduate
    $pt_lower = strtolower(trim($s['program_type'] ?? ''));
    $prog_up  = strtoupper(trim($s['program']      ?? ''));
    if (in_array($pt_lower, $_pg_types) || str_contains($prog_up, 'MBA') || str_contains($prog_up, 'MASTER')) {
        $pg_key = ($s['program_type'] !== '') ? $s['program_type'] : 'postgraduate';
        $_pg_agg[$pg_key] = ($_pg_agg[$pg_key] ?? 0) + 1;
        if ($has_gender) {
            if (!isset($_pg_g[$pg_key])) $_pg_g[$pg_key] = [];
            $_pg_g[$pg_key][$g] = ($_pg_g[$pg_key][$g] ?? 0) + 1;
        }
    }
}

// Convert accumulators → expected array shapes
arsort($_c_agg);
foreach ($_c_agg as $_k => $_v) $sum_campus[] = ['campus' => $_k, 'cnt' => $_v];

arsort($_p_agg);
foreach ($_p_agg as $_k => $_v) $sum_program[] = ['program_type' => $_k, 'cnt' => $_v];

uksort($_y_agg, fn($a, $b) => (is_numeric($a) && is_numeric($b)) ? (int)$a - (int)$b : strcmp($a, $b));
foreach ($_y_agg as $_k => $_v) $sum_year[] = ['yr' => $_k, 'cnt' => $_v];

ksort($_s_agg);
foreach ($_s_agg as $_k => $_v) $sum_semester[] = ['semester' => $_k, 'cnt' => $_v];

uksort($_cl_agg, fn($a, $b) => (is_numeric($a) && is_numeric($b)) ? (int)$a - (int)$b : strcmp($a, $b));
foreach ($_cl_agg as $_yr => $_sems) {
    ksort($_sems);
    foreach ($_sems as $_sm => $_cnt) $sum_class[] = ['yr' => (string)$_yr, 'sem' => $_sm, 'cnt' => $_cnt];
}

if ($has_gender) {
    arsort($_g_agg);
    foreach ($_g_agg as $_gk => $_cnt) if ($_cnt > 0) $sum_gender[] = ['gender' => $_gk, 'cnt' => $_cnt];
}

arsort($_pg_agg);
$total_postgrad_all = array_sum($_pg_agg);
foreach ($_pg_agg as $_k => $_v) $sum_postgrad[] = ['program_type' => $_k, 'cnt' => $_v];

if ($has_gender && !empty($_pg_g)) {
    foreach ($_pg_g as $_pgt => $_gmap) {
        foreach ($_gmap as $_gk => $_cnt) {
            $sum_postgrad_gender[] = ['program_type' => $_pgt, 'gender' => $_gk, 'cnt' => $_cnt];
        }
    }
}

// ─── Override: Postgraduate from Cleared EC Students (exam number assigned) only ─────────────────
$sum_postgrad        = [];
$sum_postgrad_gender = [];
$_r = $conn->query("SELECT COALESCE(NULLIF(TRIM(program_type),''),'(Not Set)') AS program_type,
    COUNT(DISTINCT student_id) AS cnt
    FROM exam_clearance_students
    WHERE LOWER(TRIM(status)) = 'cleared'
    AND LOWER(TRIM(program_type)) IN ('masters','doctorate','postgraduate','mba')
    GROUP BY program_type ORDER BY cnt DESC");
if ($_r) while ($_row = $_r->fetch_assoc())
    $sum_postgrad[] = ['program_type' => $_row['program_type'], 'cnt' => (int)$_row['cnt']];
$total_postgrad_all = array_sum(array_column($sum_postgrad, 'cnt'));

if ($has_gender) {
    $_r = $conn->query("SELECT COALESCE(NULLIF(TRIM(ecs.program_type),''),'(Not Set)') AS program_type,
        CASE WHEN LOWER(TRIM(s.gender)) IN ('male','m') THEN 'Male'
             WHEN LOWER(TRIM(s.gender)) IN ('female','f') THEN 'Female'
             ELSE 'Other' END AS gender,
        COUNT(DISTINCT ecs.student_id) AS cnt
        FROM exam_clearance_students ecs
        LEFT JOIN students s ON s.student_id = ecs.student_id
        WHERE LOWER(TRIM(ecs.status)) = 'cleared'
        AND LOWER(TRIM(ecs.program_type)) IN ('masters','doctorate','postgraduate','mba')
        GROUP BY ecs.program_type, gender ORDER BY cnt DESC");
    if ($_r) while ($_row = $_r->fetch_assoc())
        $sum_postgrad_gender[] = ['program_type' => $_row['program_type'], 'gender' => $_row['gender'], 'cnt' => (int)$_row['cnt']];
}

// Exam Clearance stats (always system-wide from exam_clearance_students table)
$sum_clearance = ['total' => 0, 'cleared' => 0, 'pending' => 0, 'rejected' => 0];
$r = $conn->query("SELECT COALESCE(LOWER(TRIM(status)),'pending') AS status, COUNT(*) AS cnt FROM exam_clearance_students GROUP BY status");
if ($r) while ($row = $r->fetch_assoc()) {
    $st = $row['status'];
    if (array_key_exists($st, $sum_clearance)) $sum_clearance[$st] += (int)$row['cnt'];
    $sum_clearance['total'] += (int)$row['cnt'];
}

// Finance stats: from exam_clearance_students
$sum_finance = ['fully_cleared' => 0, 'has_balance' => 0, 'pending_payment' => 0];
$r = $conn->query("SELECT
    SUM(CASE WHEN LOWER(TRIM(status))='cleared' AND COALESCE(balance,0)<=0 THEN 1 ELSE 0 END) AS fully_cleared,
    SUM(CASE WHEN LOWER(TRIM(status))='cleared' AND COALESCE(balance,0)>0  THEN 1 ELSE 0 END) AS has_balance,
    SUM(CASE WHEN LOWER(TRIM(status))='pending' THEN 1 ELSE 0 END) AS pending_payment
    FROM exam_clearance_students");
if ($r && $row = $r->fetch_assoc()) {
    $sum_finance['fully_cleared']   = (int)($row['fully_cleared']   ?? 0);
    $sum_finance['has_balance']     = (int)($row['has_balance']     ?? 0);
    $sum_finance['pending_payment'] = (int)($row['pending_payment'] ?? 0);
}

// ─── Filtered Unique Counts for Summary Count Cards ──────────────────────────
// Counts are derived from the already-filtered $students UNION result so they
// reflect whatever campus / year / semester / program / search / source filters
// are currently active.  Each student_id is counted at most once per set.
// $total_unique_all = union of the 4 specific source buckets (VLE ∪ Diss ∪ EC ∪ PG)
// so a student who only exists in the plain students table and is not in any
// specific source does NOT inflate the "All Students" count.
$_f_vle  = [];
$_f_pg   = [];
$_f_diss = [];
$_f_ec   = [];
$_pg_card_types = ['masters', 'doctorate', 'postgraduate', 'mba'];

foreach ($students as $_fs) {
    $_fid = $_fs['student_id'];
    if ($_fs['source_label'] === 'VLE Students')   $_f_vle[$_fid]  = true;
    if ($_fs['source_label'] === 'Dissertation')   $_f_diss[$_fid] = true;
    if ($_fs['source_label'] === 'Exam Clearance') $_f_ec[$_fid]   = true;
    if (in_array(strtolower(trim($_fs['program_type'] ?? '')), $_pg_card_types)) $_f_pg[$_fid] = true;
}
// Union of all specific sources — PHP array + keeps first-seen key, so deduplicates by student_id
$total_unique_all          = count($_f_vle + $_f_diss + $_f_ec + $_f_pg);
$total_unique_vle          = count($_f_vle);
$total_unique_postgrad     = count($_f_pg);
$total_unique_dissertation = count($_f_diss);
$total_unique_ec           = count($_f_ec);

// Cleared / Pending: filtered query against EC table using the same active filters.
[$_ec_w_parts, $_ec_prms, $_ec_types] = applyFiltersToArm(
    $filters, 'campus', 'year_of_study', 'semester', 'program_type',
    'student_id', 'full_name', 'email', 'ecs', null, $filter_course
);
$_ec_base_w    = $_ec_w_parts ? 'WHERE ' . implode(' AND ', $_ec_w_parts) . ' AND ' : 'WHERE ';
$_cleared_sql  = "SELECT COUNT(DISTINCT ecs.student_id) AS cnt FROM exam_clearance_students ecs {$_ec_base_w}LOWER(TRIM(ecs.status))='cleared'";
$_pending_sql  = "SELECT COUNT(DISTINCT ecs.student_id) AS cnt FROM exam_clearance_students ecs {$_ec_base_w}LOWER(TRIM(ecs.status))='pending'";
$total_unique_cleared = 0;
$total_unique_pending = 0;
if (!empty($_ec_prms)) {
    $_st = $conn->prepare($_cleared_sql);
    if ($_st) { $_st->bind_param($_ec_types, ...$_ec_prms); $_st->execute(); $_r2 = $_st->get_result(); $total_unique_cleared = (int)(($_r2 && ($_rw = $_r2->fetch_assoc())) ? $_rw['cnt'] : 0); }
    $_st = $conn->prepare($_pending_sql);
    if ($_st) { $_st->bind_param($_ec_types, ...$_ec_prms); $_st->execute(); $_r2 = $_st->get_result(); $total_unique_pending = (int)(($_r2 && ($_rw = $_r2->fetch_assoc())) ? $_rw['cnt'] : 0); }
} else {
    $_r2 = $conn->query($_cleared_sql);
    $total_unique_cleared = (int)(($_r2 && ($_rw = $_r2->fetch_assoc())) ? $_rw['cnt'] : 0);
    $_r2 = $conn->query($_pending_sql);
    $total_unique_pending = (int)(($_r2 && ($_rw = $_r2->fetch_assoc())) ? $_rw['cnt'] : 0);
}

// ─── Excel Export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    require_once '../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Student List Report');

    // Meta
    $spreadsheet->getProperties()
        ->setCreator($uni_name)
        ->setTitle('Student List Report')
        ->setSubject('Student List – ' . date('Y-m-d'));

    // University header row
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', strtoupper($uni_name) . ' — Student List Report');
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3C72']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(22);

    // Filter summary row
    $filter_desc = [];
    if ($filter_source !== 'all') $filter_desc[] = 'Source: ' . ucwords(str_replace('_', ' ', $filter_source));
    if ($filter_campus)   $filter_desc[] = 'Campus: ' . $filter_campus;
    if ($filter_year)     $filter_desc[] = 'Year: ' . $filter_year;
    if ($filter_semester) $filter_desc[] = 'Semester: ' . $filter_semester;
    if ($filter_program)  $filter_desc[] = 'Program Type: ' . ucfirst($filter_program);
    if ($filter_search)   $filter_desc[] = 'Search: ' . $filter_search;
    $filter_text = empty($filter_desc) ? 'All Records — ' . date('d M Y H:i') : implode(' | ', $filter_desc) . ' — ' . date('d M Y H:i');

    $sheet->mergeCells('A2:I2');
    $sheet->setCellValue('A2', $filter_text);
    $sheet->getStyle('A2')->applyFromArray([
        'font' => ['italic' => true, 'color' => ['argb' => 'FF333333']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EDF5']],
    ]);

    // Column headers
    $headers = ['#', 'Student ID', 'Full Name', 'Email', 'Campus', 'Program Type', 'Program / Course', 'Year', 'Semester', 'Source'];
    foreach ($headers as $col => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '3';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D5A8E']],
        ]);
    }

    // Data rows
    $colMap = ['A','B','C','D','E','F','G','H','I','J'];
    foreach ($students as $i => $s) {
        $row = $i + 4;
        $sheet->setCellValue("A{$row}", $i + 1);
        $sheet->setCellValue("B{$row}", $s['student_id']);
        $sheet->setCellValue("C{$row}", $s['full_name']);
        $sheet->setCellValue("D{$row}", $s['email']);
        $sheet->setCellValue("E{$row}", $s['campus']);
        $sheet->setCellValue("F{$row}", ucfirst($s['program_type']));
        $sheet->setCellValue("G{$row}", $s['program']);
        $sheet->setCellValue("H{$row}", $s['year_of_study']);
        $sheet->setCellValue("I{$row}", $s['semester']);
        $sheet->setCellValue("J{$row}", $s['source_label']);
        if ($row % 2 === 0) {
            $sheet->getStyle("A{$row}:J{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF5F8FF');
        }
    }

    // Auto-size
    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'student_list_report_' . date('Ymd_His') . '.xlsx';
    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ─── PDF Export (via mPDF) ───────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once '../vendor/autoload.php';

    // Increase PCRE limits to handle large HTML chunks
    @ini_set('pcre.backtrack_limit', '5000000');
    @ini_set('pcre.recursion_limit', '500000');

    $mpdfTempDir = dirname(__DIR__) . '/uploads/mpdf_tmp';
    if (!is_dir($mpdfTempDir) && !@mkdir($mpdfTempDir, 0777, true)) {
        $mpdfTempDir = rtrim(sys_get_temp_dir(), '/\\') . '/mpdf_tmp_vle';
        if (!is_dir($mpdfTempDir)) @mkdir($mpdfTempDir, 0777, true);
    }

    // ── Helper: stat mini-table rows ─────────────────────────────────────────
    function _pdf_stat_rows(array $data, string $label_col, int $total, string $label_title = 'Category'): string {
        $html = '<table style="width:100%;border-collapse:collapse;font-size:9px;margin-bottom:6px">
            <thead><tr>
                <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:left">' . htmlspecialchars($label_title) . '</th>
                <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Count</th>
                <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Share</th>
            </tr></thead><tbody>';
        foreach ($data as $i => $row) {
            $bg  = $i % 2 === 0 ? '#f5f8ff' : '#fff';
            $cnt = (int)$row['cnt'];
            $pct = $total > 0 ? round(($cnt / $total) * 100, 1) : 0;
            $html .= '<tr style="background:' . $bg . '">
                <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8">' . htmlspecialchars($row[$label_col]) . '</td>
                <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center;font-weight:bold">' . $cnt . '</td>
                <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center;color:#666">' . $pct . '%</td>
            </tr>';
        }
        $html .= '<tr style="background:#e8edf5">
            <td style="padding:4px 5px;font-weight:bold">TOTAL</td>
            <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $total . '</td>
            <td style="padding:4px 5px;text-align:center">100%</td>
        </tr></tbody></table>';
        return $html;
    }

    function _pdf_kpi(string $label, string $value, string $bg, string $fg = '#fff'): string {
        return '<td style="width:25%;padding:4px">
            <div style="background:' . $bg . ';color:' . $fg . ';border-radius:6px;padding:10px 8px;text-align:center">
                <div style="font-size:18px;font-weight:bold">' . htmlspecialchars($value) . '</div>
                <div style="font-size:8px;opacity:.85;margin-top:2px">' . htmlspecialchars($label) . '</div>
            </div></td>';
    }

    // ── PDF CSS ───────────────────────────────────────────────────────────────
    $pdf_css = '
        body  { font-family: Arial, sans-serif; font-size: 9px; color: #222; }
        h2    { font-size: 15px; margin: 0 0 2px; color: #1e3c72; }
        h3    { font-size: 11px; margin: 10px 0 4px; color: #1e3c72; border-bottom: 2px solid #1e3c72; padding-bottom: 2px; }
        h4    { font-size: 9.5px; margin: 6px 0 3px; color: #2d5a8e; text-transform: uppercase; letter-spacing: .04em; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 0; }
        .data-table th { background: #1e3c72; color: #fff; padding: 5px 4px; font-size: 9px; text-align: left; }
        .data-table td { padding: 3px 4px; border-bottom: 1px solid #e0e0e0; font-size: 8.5px; }
        .section-box  { border: 1px solid #d0d9e8; border-radius: 4px; padding: 8px; margin-bottom: 8px; background: #f9fbff; }
        .label-small  { font-size: 7.5px; color: #666; text-transform: uppercase; letter-spacing: .05em; }
    ';

    // ── Summary Page HTML ─────────────────────────────────────────────────────
    $filter_desc = [];
    if ($filter_source !== 'all') $filter_desc[] = 'Source: ' . ucwords(str_replace('_', ' ', $filter_source));
    if ($filter_campus)   $filter_desc[] = 'Campus: ' . $filter_campus;
    if ($filter_year)     $filter_desc[] = 'Year: ' . $filter_year;
    if ($filter_semester) $filter_desc[] = 'Semester: ' . $filter_semester;
    if ($filter_program)  $filter_desc[] = 'Program Type: ' . ucfirst($filter_program);
    $filter_summary_txt = empty($filter_desc) ? 'Showing all records (no filters applied)' : implode('&nbsp; | &nbsp;', $filter_desc);

    $summary_html  = '<div style="border-bottom:3px solid #1e3c72;padding-bottom:8px;margin-bottom:10px">';
    $summary_html .= '<h2>' . htmlspecialchars(strtoupper($uni_name)) . '</h2>';
    $summary_html .= '<p style="font-size:9px;margin:0;color:#444"><strong>Student Enrolment &amp; Finance Summary Report</strong> &mdash; Generated: ' . date('l, d F Y H:i') . '</p>';
    $summary_html .= '<p style="font-size:8px;margin:2px 0 0;color:#888">Filters: ' . $filter_summary_txt . '</p>';
    $summary_html .= '</div>';

    // KPI row
    $summary_html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:12px"><tr>';
    $summary_html .= _pdf_kpi('All Students (Unique)',    (string)$total_unique_all,          '#1e3c72');
    $summary_html .= _pdf_kpi('Dissertation Students',    (string)$total_unique_dissertation, '#7c3aed');
    $summary_html .= _pdf_kpi('Total Cleared (Exams)',    (string)$sum_clearance['cleared'], '#166534', '#fff');
    $summary_html .= _pdf_kpi('Pending Clearance',        (string)$sum_clearance['pending'], '#92400e', '#fff');
    $summary_html .= '</tr></table>';

    // 2-column section: Campus breakdown + Program Type breakdown
    $summary_html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:8px"><tr>';
    $summary_html .= '<td style="width:48%;vertical-align:top;padding-right:8px">';
    $summary_html .= '<h4>Students by Campus</h4>';
    $summary_html .= _pdf_stat_rows($sum_campus, 'campus', $total_unique_all, 'Campus');
    $summary_html .= '</td>';
    $summary_html .= '<td style="width:4%"></td>';
    $summary_html .= '<td style="width:48%;vertical-align:top">';
    $summary_html .= '<h4>Students by Program Type</h4>';
    $summary_html .= _pdf_stat_rows($sum_program, 'program_type', $total_unique_all, 'Program Type');
    $summary_html .= '</td></tr></table>';

    // 2-column: By Year + By Semester
    $summary_html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:8px"><tr>';
    $summary_html .= '<td style="width:48%;vertical-align:top;padding-right:8px">';
    $summary_html .= '<h4>Students by Year of Study</h4>';
    $summary_html .= _pdf_stat_rows($sum_year, 'yr', $total_unique_all, 'Year');
    $summary_html .= '</td>';
    $summary_html .= '<td style="width:4%"></td>';
    $summary_html .= '<td style="width:48%;vertical-align:top">';
    $summary_html .= '<h4>Students by Semester (Class)</h4>';
    // Class = year + semester combined
    $class_html = '<table style="width:100%;border-collapse:collapse;font-size:9px;margin-bottom:6px">
        <thead><tr>
            <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:left">Year</th>
            <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:left">Semester</th>
            <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Count</th>
        </tr></thead><tbody>';
    foreach ($sum_class as $ci => $cls) {
        $bg = $ci % 2 === 0 ? '#f5f8ff' : '#fff';
        $class_html .= '<tr style="background:' . $bg . '">
            <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8">Year ' . htmlspecialchars($cls['yr']) . '</td>
            <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8">Sem ' . htmlspecialchars($cls['sem']) . '</td>
            <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center;font-weight:bold">' . (int)$cls['cnt'] . '</td>
        </tr>';
    }
    $class_html .= '<tr style="background:#e8edf5"><td colspan="2" style="padding:4px 5px;font-weight:bold">TOTAL</td>
        <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $total_unique_all . '</td></tr></tbody></table>';
    $summary_html .= $class_html;
    $summary_html .= '</td></tr></table>';

    // Exam Clearance + Finance status
    $summary_html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:8px"><tr>';
    $summary_html .= '<td style="width:48%;vertical-align:top;padding-right:8px">';
    $summary_html .= '<h4>Exam Clearance Status</h4>';
    $ec_data = [
        ['label' => 'Cleared',  'cnt' => $sum_clearance['cleared'],  'bg' => '#d1fae5', 'fg' => '#065f46'],
        ['label' => 'Pending',  'cnt' => $sum_clearance['pending'],  'bg' => '#fef3c7', 'fg' => '#92400e'],
        ['label' => 'Rejected', 'cnt' => $sum_clearance['rejected'], 'bg' => '#fee2e2', 'fg' => '#991b1b'],
    ];
    $ec_total = $sum_clearance['total'];
    $summary_html .= '<table style="width:100%;border-collapse:collapse;font-size:9px"><thead><tr>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:left">Status</th>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Count</th>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Share</th>
    </tr></thead><tbody>';
    foreach ($ec_data as $ed) {
        $pct = $ec_total > 0 ? round(($ed['cnt'] / $ec_total) * 100, 1) : 0;
        $summary_html .= '<tr><td style="padding:4px 5px;background:' . $ed['bg'] . ';color:' . $ed['fg'] . ';font-weight:bold">' . $ed['label'] . '</td>
            <td style="padding:4px 5px;text-align:center;font-weight:bold">' . $ed['cnt'] . '</td>
            <td style="padding:4px 5px;text-align:center;color:#666">' . $pct . '%</td></tr>';
    }
    $summary_html .= '<tr style="background:#e8edf5"><td style="padding:4px 5px;font-weight:bold">TOTAL</td>
        <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $ec_total . '</td>
        <td style="padding:4px 5px;text-align:center">100%</td></tr></tbody></table>';
    $summary_html .= '</td><td style="width:4%"></td>';
    $summary_html .= '<td style="width:48%;vertical-align:top">';
    $summary_html .= '<h4>Finance Status (Cleared Students)</h4>';
    $fin_data = [
        ['label' => 'Fully Paid &amp; Cleared',     'cnt' => $sum_finance['fully_cleared'],   'bg' => '#d1fae5', 'fg' => '#065f46'],
        ['label' => 'Cleared — Balance Outstanding', 'cnt' => $sum_finance['has_balance'],     'bg' => '#fef3c7', 'fg' => '#92400e'],
        ['label' => 'Pending Payment',               'cnt' => $sum_finance['pending_payment'], 'bg' => '#fee2e2', 'fg' => '#991b1b'],
    ];
    $fin_total = array_sum(array_column($fin_data, 'cnt'));
    $summary_html .= '<table style="width:100%;border-collapse:collapse;font-size:9px"><thead><tr>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:left">Finance Status</th>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Count</th>
        <th style="background:#2d5a8e;color:#fff;padding:4px 5px;text-align:center">Share</th>
    </tr></thead><tbody>';
    foreach ($fin_data as $fd) {
        $pct = $fin_total > 0 ? round(($fd['cnt'] / $fin_total) * 100, 1) : 0;
        $summary_html .= '<tr><td style="padding:4px 5px;background:' . $fd['bg'] . ';color:' . $fd['fg'] . ';font-weight:bold">' . $fd['label'] . '</td>
            <td style="padding:4px 5px;text-align:center;font-weight:bold">' . $fd['cnt'] . '</td>
            <td style="padding:4px 5px;text-align:center;color:#666">' . $pct . '%</td></tr>';
    }
    $summary_html .= '<tr style="background:#e8edf5"><td style="padding:4px 5px;font-weight:bold">TOTAL</td>
        <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $fin_total . '</td>
        <td style="padding:4px 5px;text-align:center">100%</td></tr></tbody></table>';
    $summary_html .= '</td></tr></table>';

    // Postgraduate breakdown (with gender if available)
    if (!empty($sum_postgrad)) {
        $summary_html .= '<h4>Postgraduate Students (Masters / Doctorate)</h4>';
        $pg_gender_map = [];
        foreach ($sum_postgrad_gender as $pg) {
            $pg_gender_map[$pg['program_type']][$pg['gender']] = (int)$pg['cnt'];
        }
        $summary_html .= '<table style="width:100%;border-collapse:collapse;font-size:9px;margin-bottom:6px"><thead><tr>
            <th style="background:#6d28d9;color:#fff;padding:4px 5px">Program</th>
            <th style="background:#6d28d9;color:#fff;padding:4px 5px;text-align:center">Total</th>';
        if ($has_gender) {
            $summary_html .= '<th style="background:#6d28d9;color:#fff;padding:4px 5px;text-align:center">Male</th>
                              <th style="background:#6d28d9;color:#fff;padding:4px 5px;text-align:center">Female</th>
                              <th style="background:#6d28d9;color:#fff;padding:4px 5px;text-align:center">Other</th>';
        }
        $summary_html .= '</tr></thead><tbody>';
        foreach ($sum_postgrad as $pi => $pg) {
            $bg = $pi % 2 === 0 ? '#f5f0ff' : '#fff';
            $summary_html .= '<tr style="background:' . $bg . '">
                <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;font-weight:bold">' . htmlspecialchars(ucfirst($pg['program_type'])) . '</td>
                <td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center;font-weight:bold">' . (int)$pg['cnt'] . '</td>';
            if ($has_gender) {
                $gm = $pg_gender_map[$pg['program_type']] ?? [];
                $summary_html .= '<td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center">' . ($gm['Male']   ?? $gm['male']   ?? 0) . '</td>';
                $summary_html .= '<td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center">' . ($gm['Female'] ?? $gm['female'] ?? 0) . '</td>';
                $other_g = (int)$pg['cnt'] - ($gm['Male'] ?? $gm['male'] ?? 0) - ($gm['Female'] ?? $gm['female'] ?? 0);
                $summary_html .= '<td style="padding:3px 5px;border-bottom:1px solid #e8e8e8;text-align:center">' . max(0, $other_g) . '</td>';
            }
            $summary_html .= '</tr>';
        }
        $summary_html .= '<tr style="background:#ede9fe"><td style="padding:4px 5px;font-weight:bold">TOTAL</td>
            <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $total_unique_dissertation . '</td>';
        if ($has_gender) {
            $total_m = array_sum(array_column(array_filter($sum_postgrad_gender, fn($r) => in_array(strtolower($r['gender']), ['male','m'])), 'cnt'));
            $total_f = array_sum(array_column(array_filter($sum_postgrad_gender, fn($r) => in_array(strtolower($r['gender']), ['female','f'])), 'cnt'));
            $summary_html .= '<td style="padding:4px 5px;font-weight:bold;text-align:center">' . $total_m . '</td>
                              <td style="padding:4px 5px;font-weight:bold;text-align:center">' . $total_f . '</td>
                              <td style="padding:4px 5px;text-align:center">—</td>';
        }
        $summary_html .= '</tr></tbody></table>';
    }

    // Gender breakdown (if available)
    if ($has_gender && !empty($sum_gender)) {
        $total_gen = array_sum(array_column($sum_gender, 'cnt'));
        $summary_html .= '<h4>All Students by Gender</h4>';
        $summary_html .= _pdf_stat_rows($sum_gender, 'gender', $total_gen, 'Gender');
    }

    $summary_html .= '<p style="font-size:7.5px;color:#aaa;text-align:right;margin-top:6px">Confidential &mdash; For Management Use Only &mdash; ' . htmlspecialchars($uni_name) . '</p>';

    // ── Student List Page ─────────────────────────────────────────────────────
    $list_page_header = '
    <div style="border-bottom:2px solid #1e3c72;margin-bottom:6px;padding-bottom:4px">
        <strong style="font-size:11px;color:#1e3c72">' . htmlspecialchars($uni_name) . ' &mdash; Student List</strong>
        <span style="font-size:8px;color:#888;float:right">Generated: ' . date('d M Y H:i') . ' &nbsp;|&nbsp; Total: ' . count($students) . ' record(s) &nbsp;|&nbsp; ' . htmlspecialchars($filter_summary_txt) . '</span>
    </div>';

    $list_thead = '<table class="data-table">
        <thead><tr>
            <th style="width:30px;text-align:center">#</th>
            <th style="width:80px">Student ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th style="width:80px">Campus</th>
            <th style="width:70px">Prog. Type</th>
            <th>Program / Study</th>
            <th style="width:35px;text-align:center">Yr</th>
            <th style="width:35px;text-align:center">Sem</th>
            <th style="width:75px">Source</th>
        </tr></thead><tbody>';
    $list_tfoot = '</tbody></table>';

    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'tempDir'       => $mpdfTempDir,
            'margin_top'    => 10,
            'margin_bottom' => 8,
            'margin_left'   => 8,
            'margin_right'  => 8,
            'setAutoBottomMargin' => 'stretch',
        ]);

        $mpdf->SetFooter('Page {PAGENO} of {nb}  |  ' . htmlspecialchars($uni_name) . '  |  Confidential');

        // Page 1: Summary
        $mpdf->WriteHTML($pdf_css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($summary_html, \Mpdf\HTMLParserMode::HTML_BODY);

        // Page 2+: Student list (chunked to avoid pcre.backtrack_limit)
        $mpdf->AddPage('L');
        $mpdf->WriteHTML($list_page_header, \Mpdf\HTMLParserMode::HTML_BODY);

        $chunk_size = 80;
        $chunks     = array_chunk($students, $chunk_size);
        foreach ($chunks as $ci => $chunk) {
            $chunk_html = ($ci === 0 ? $list_thead : '<table class="data-table"><tbody>');
            foreach ($chunk as $ri => $s) {
                $abs = $ci * $chunk_size + $ri;
                $bg  = $abs % 2 === 0 ? '#f5f8ff' : '#fff';
                $chunk_html .= '<tr style="background:' . $bg . '">
                    <td style="text-align:center;color:#888">' . ($abs + 1) . '</td>
                    <td><code style="font-size:8px">' . htmlspecialchars($s['student_id']) . '</code></td>
                    <td style="font-weight:bold">' . htmlspecialchars($s['full_name']) . '</td>
                    <td style="color:#555">' . htmlspecialchars($s['email']) . '</td>
                    <td>' . htmlspecialchars($s['campus']) . '</td>
                    <td><span style="background:#e0e7ff;color:#3730a3;padding:1px 4px;border-radius:3px;font-size:8px">' . ucfirst(htmlspecialchars($s['program_type'])) . '</span></td>
                    <td>' . htmlspecialchars($s['program']) . '</td>
                    <td style="text-align:center">' . htmlspecialchars($s['year_of_study']) . '</td>
                    <td style="text-align:center">' . htmlspecialchars($s['semester']) . '</td>
                    <td><span style="background:#dbe9ff;color:#1e40af;padding:1px 4px;border-radius:3px;font-size:7.5px">' . htmlspecialchars($s['source_label']) . '</span></td>
                </tr>';
            }
            $chunk_html .= $list_tfoot;
            $mpdf->WriteHTML($chunk_html, \Mpdf\HTMLParserMode::HTML_BODY);
        }

        $filename = 'student_report_' . date('Ymd_His') . '.pdf';
        ob_end_clean();
        $mpdf->Output($filename, 'D');
        exit;
    } catch (\Exception $e) {
        ob_end_clean();
        http_response_code(500);
        echo '<pre style="background:#fee2e2;color:#991b1b;padding:16px;border-radius:8px;font-family:monospace">'
           . '<strong>PDF generation failed</strong><br>' . htmlspecialchars($e->getMessage()) . '</pre>';
        exit;
    }
}

// ─── Portal-aware navigation ──────────────────────────────────────────────────
$_vle_role = $_SESSION['vle_role'] ?? '';
if (in_array($_vle_role, ['admin', 'super_admin', 'staff'], true)) {
    $_portal_header = 'header_nav.php';
    $_back_url      = 'dashboard.php';
    $_back_label    = 'Admin Dashboard';
    $_portal_name   = 'Admin';
} elseif ($_vle_role === 'dean') {
    $_portal_header = '../dean/header_nav.php';
    $_back_url      = '../dean/dashboard.php';
    $_back_label    = 'Dean Dashboard';
    $_portal_name   = 'Dean';
} else {
    // finance
    $_portal_header = '../finance/header_nav.php';
    $_back_url      = '../finance/dashboard.php';
    $_back_label    = 'Finance Dashboard';
    $_portal_name   = 'Finance';
}

$page_title = 'Student List Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> – <?= htmlspecialchars($_portal_name) ?> Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .source-badge { font-size: .72rem; padding: 3px 8px; border-radius: 20px; font-weight: 600; }
        .source-all         { background:#dbeafe; color:#1e40af; }
        .source-vle         { background:#dcfce7; color:#166534; }
        .source-dissertation{ background:#fef9c3; color:#713f12; }
        .source-ec          { background:#ffe4e6; color:#9f1239; }

        .stat-card { border-radius:10px; border:none; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        a:hover .stat-card { box-shadow:0 4px 16px rgba(0,0,0,.18); transform:translateY(-2px); }
        .filter-card { border-radius:10px; border:1px solid #dee2e6; background:#f8f9fa; }

        @media print {
            .no-print, .navbar, .vle-navbar, .filter-card, .btn, nav { display:none !important; }
            .print-header { display:block !important; }
            body { font-size:11px; }
            .table { font-size:10px; }
        }
        .print-header { display:none; }
        .print-header h2 { font-size:16px; }
        .print-header p  { font-size:11px; margin-bottom:0; }
    </style>
</head>
<body>
<?php include $_portal_header; ?>

<div class="container-fluid py-4">

    <!-- Page Title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= $_back_url ?>"><i class="bi bi-house"></i></a></li>
                    <li class="breadcrumb-item active">Student List Report</li>
                </ol>
            </nav>
            <h2 class="mb-0"><i class="bi bi-people-fill text-primary me-2"></i>Student List Report</h2>
            <p class="text-muted mb-0">Comprehensive view across all student sources</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>"
               class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>"
               class="btn btn-danger btn-sm">
                <i class="bi bi-file-earmark-pdf me-1"></i>PDF
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Print-only header -->
    <div class="print-header mb-3 text-center border-bottom pb-2">
        <h2 class="fw-bold"><?= htmlspecialchars($uni_name) ?></h2>
        <p><strong>Student List Report</strong> &mdash; Generated <?= date('d F Y, H:i') ?></p>
    </div>

    <!-- Summary Count Cards (unique students, no duplicates) -->
    <?php $base_params = array_diff_key($_GET, array_flip(['source', 'program'])); ?>
    <?php if ($has_active_filter): ?>
    <div class="alert alert-info py-1 px-3 mb-2 no-print d-flex align-items-center gap-2" style="font-size:.82rem">
        <i class="bi bi-funnel-fill"></i>
        <span><strong>Filtered view</strong> &mdash; card counts below reflect the active filters.
        <a href="student_list_report.php" class="ms-2 text-decoration-none">Clear all filters</a></span>
    </div>
    <?php endif; ?>
    <div class="row g-3 mb-4 no-print">

        <!-- All Students — unique across all sources -->
        <div class="col-6 col-md">
            <a href="?<?= http_build_query(array_merge($base_params, ['source' => 'all'])) ?>" class="text-decoration-none">
            <div class="stat-card card text-center p-3 <?= ($filter_source === 'all' && $filter_program === '') ? 'border-primary border-2' : '' ?>"
                 style="cursor:pointer;transition:box-shadow .15s;border-top:4px solid #1e3c72;">
                <i class="bi bi-people-fill fs-3 text-primary mb-1"></i>
                <div class="fs-2 fw-bold text-primary"><?= number_format($total_unique_all) ?></div>
                <div class="fw-semibold text-dark" style="font-size:.9rem">All Students</div>
                <div class="text-muted" style="font-size:.75rem">Total unique (cleared + pending)</div>
            </div>
            </a>
        </div>

        <!-- VLE Students -->
        <div class="col-6 col-md">
            <a href="?<?= http_build_query(array_merge($base_params, ['source' => 'vle'])) ?>" class="text-decoration-none">
            <div class="stat-card card text-center p-3 <?= $filter_source === 'vle' ? 'border-success border-2' : '' ?>"
                 style="cursor:pointer;transition:box-shadow .15s;border-top:4px solid #166534;">
                <i class="bi bi-laptop fs-3 text-success mb-1"></i>
                <div class="fs-2 fw-bold text-success"><?= number_format($total_unique_vle) ?></div>
                <div class="fw-semibold text-dark" style="font-size:.9rem">VLE Students</div>
                <div class="text-muted" style="font-size:.75rem">ODL / VLE enrolled</div>
            </div>
            </a>
        </div>

        <!-- Postgraduate Students -->
        <div class="col-6 col-md">
            <a href="?<?= http_build_query(array_merge($base_params, ['source' => 'all', 'program' => 'postgraduate'])) ?>" class="text-decoration-none">
            <div class="stat-card card text-center p-3 <?= ($filter_program === 'postgraduate') ? 'border-2' : '' ?>"
                 style="cursor:pointer;transition:box-shadow .15s;border-top:4px solid #6d28d9;<?= ($filter_program === 'postgraduate') ? 'border-color:#6d28d9 !important;' : '' ?>">
                <i class="bi bi-award-fill fs-3 mb-1" style="color:#7c3aed"></i>
                <div class="fs-2 fw-bold" style="color:#7c3aed"><?= number_format($total_unique_postgrad) ?></div>
                <div class="fw-semibold text-dark" style="font-size:.9rem">Postgraduate</div>
                <div class="text-muted" style="font-size:.75rem">Masters &amp; Doctorate</div>
            </div>
            </a>
        </div>

        <!-- Dissertation Students -->
        <div class="col-6 col-md">
            <a href="?<?= http_build_query(array_merge($base_params, ['source' => 'dissertation'])) ?>" class="text-decoration-none">
            <div class="stat-card card text-center p-3 <?= $filter_source === 'dissertation' ? 'border-warning border-2' : '' ?>"
                 style="cursor:pointer;transition:box-shadow .15s;border-top:4px solid #b45309;">
                <i class="bi bi-mortarboard-fill fs-3 text-warning mb-1"></i>
                <div class="fs-2 fw-bold text-warning"><?= number_format($total_unique_dissertation) ?></div>
                <div class="fw-semibold text-dark" style="font-size:.9rem">Dissertation</div>
                <div class="text-muted" style="font-size:.75rem">Masters / Doctorate students</div>
            </div>
            </a>
        </div>

        <!-- Examination Clearance — cleared + pending breakdown -->
        <div class="col-6 col-md">
            <a href="?<?= http_build_query(array_merge($base_params, ['source' => 'exam_clearance'])) ?>" class="text-decoration-none">
            <div class="stat-card card text-center p-3 <?= $filter_source === 'exam_clearance' ? 'border-danger border-2' : '' ?>"
                 style="cursor:pointer;transition:box-shadow .15s;border-top:4px solid #9f1239;">
                <i class="bi bi-clipboard2-check-fill fs-3 text-danger mb-1"></i>
                <div class="fs-2 fw-bold text-danger"><?= number_format($total_unique_ec) ?></div>
                <div class="fw-semibold text-dark" style="font-size:.9rem">Examination Clearance</div>
                <div class="d-flex justify-content-center gap-2 mt-1" style="font-size:.72rem">
                    <span class="badge bg-success">&#10003; <?= number_format($total_unique_cleared) ?> Cleared</span>
                    <span class="badge bg-warning text-dark">&#9679; <?= number_format($total_unique_pending) ?> Pending</span>
                </div>
            </div>
            </a>
        </div>

    </div>

    <!-- Filters -->
    <div class="filter-card p-3 mb-4 no-print">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Source -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Source</label>
                <select name="source" class="form-select form-select-sm">
                    <option value="all"         <?= $filter_source === 'all'          ? 'selected' : '' ?>>All Sources</option>
                    <option value="system"      <?= $filter_source === 'system'       ? 'selected' : '' ?>>All System Students</option>
                    <option value="vle"         <?= $filter_source === 'vle'          ? 'selected' : '' ?>>VLE Enrolled</option>
                    <option value="dissertation"<?= $filter_source === 'dissertation' ? 'selected' : '' ?>>Dissertation</option>
                    <option value="exam_clearance"<?= $filter_source === 'exam_clearance' ? 'selected' : '' ?>>Exam Clearance</option>
                </select>
            </div>
            <!-- Campus -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Campus</label>
                <select name="campus" class="form-select form-select-sm">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filter_campus === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Year -->
            <div class="col-sm-4 col-md-1">
                <label class="form-label fw-semibold small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach ([1,2,3,4,5,6] as $y): ?>
                    <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Semester -->
            <div class="col-sm-4 col-md-1">
                <label class="form-label fw-semibold small mb-1">Semester</label>
                <select name="semester" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="One" <?= $filter_semester === 'One' ? 'selected' : '' ?>>One</option>
                    <option value="Two" <?= $filter_semester === 'Two' ? 'selected' : '' ?>>Two</option>
                </select>
            </div>
            <!-- Program Type -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label fw-semibold small mb-1">Program Type</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter_program === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Course -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Course (VLE)</label>
                <select name="course" class="form-select form-select-sm">
                    <option value="0">All Courses</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['course_id'] ?>" <?= $filter_course === (int)$c['course_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['course_code'] . ' – ' . $c['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Search -->
            <div class="col-sm-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name / ID / Email"
                       value="<?= htmlspecialchars($filter_search) ?>">
            </div>
            <!-- Buttons -->
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="student_list_report.php" class="btn btn-outline-secondary btn-sm ms-1">
                    <i class="bi bi-x-circle me-1"></i>Clear
                </a>
            </div>
        </form>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         MANAGEMENT SUMMARY REPORT  (system-wide, all campuses, by gender)
         ══════════════════════════════════════════════════════════════════ -->
    <?php
    // Derive overall male/female totals from $sum_gender for KPI sub-labels
    $kpi_male = 0; $kpi_female = 0;
    foreach ($sum_gender as $_sg) {
        $_gl = strtolower(trim($_sg['gender']));
        if ($_gl === 'male'   || $_gl === 'm') $kpi_male   += (int)$_sg['cnt'];
        if ($_gl === 'female' || $_gl === 'f') $kpi_female += (int)$_sg['cnt'];
    }
    ?>
    <div class="card shadow-sm mb-4 border-0" id="mgmt-summary-card">
        <div class="card-header d-flex justify-content-between align-items-center py-2 text-white"
             style="background:linear-gradient(135deg,#1e3c72 0%,#2d5a8e 100%)">
            <span class="fw-semibold fs-6">
                <i class="bi bi-bar-chart-line me-2"></i>Management Summary Report
                <small class="ms-2 opacity-75" style="font-size:.75rem"><?= $has_active_filter ? 'Filtered view' : 'All Sources' ?> &mdash; Students &bull; VLE &bull; Exam Clearance &bull; Dissertation</small>
                <?php if ($has_active_filter): ?>
                <span class="badge bg-warning text-dark ms-2" style="font-size:.7rem;vertical-align:middle">
                    <i class="bi bi-funnel-fill me-1"></i>Filtered
                </span>
                <?php endif; ?>
            </span>
            <div class="d-flex gap-2">
                <a href="student_list_report.php?export=pdf" class="btn btn-sm btn-light text-primary fw-semibold no-print" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export Full PDF
                </a>
                <button class="btn btn-sm btn-outline-light no-print" type="button"
                        data-bs-toggle="collapse" data-bs-target="#mgmt-summary-body"
                        aria-expanded="true">
                    <i class="bi bi-chevron-up" id="mgmt-chevron"></i>
                </button>
            </div>
        </div>
        <div id="mgmt-summary-body" class="collapse show">
        <div class="card-body p-3">

            <!-- ── KPI Cards ── -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="rounded-3 p-3 h-100 text-white text-center d-flex flex-column justify-content-center"
                         style="background:linear-gradient(135deg,#1e3c72,#2d5a8e)">
                        <div class="fw-bold" style="font-size:2rem"><?= number_format($total_unique_all) ?></div>
                        <div class="small opacity-90 mt-1">All Students (Unique)</div>
                        <?php if ($has_gender && ($kpi_male || $kpi_female)): ?>
                        <div class="mt-1" style="font-size:.72rem;opacity:.85">
                            <span class="me-1">&#9794; <?= number_format($kpi_male) ?></span>
                            <span>&#9792; <?= number_format($kpi_female) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="rounded-3 p-3 h-100 text-white text-center d-flex flex-column justify-content-center"
                         style="background:linear-gradient(135deg,#065f46,#047857)">
                        <div class="fw-bold" style="font-size:2rem"><?= number_format($total_unique_vle) ?></div>
                        <div class="small opacity-90 mt-1">VLE Students</div>
                        <div style="font-size:.72rem;opacity:.8;margin-top:2px">ODL / VLE Enrolled</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="rounded-3 p-3 h-100 text-white text-center d-flex flex-column justify-content-center"
                         style="background:linear-gradient(135deg,#7c3aed,#6d28d9)">
                        <div class="fw-bold" style="font-size:2rem"><?= number_format($total_unique_dissertation) ?></div>
                        <div class="small opacity-90 mt-1">Dissertation</div>
                        <div style="font-size:.72rem;opacity:.8;margin-top:2px">Masters / Doctorate</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="rounded-3 p-3 h-100 text-white text-center d-flex flex-column justify-content-center"
                         style="background:linear-gradient(135deg,#9f1239,#be123c)">
                        <div class="fw-bold" style="font-size:2rem"><?= number_format($total_unique_ec) ?></div>
                        <div class="small opacity-90 mt-1">Examination Clearance</div>
                        <div class="mt-1 d-flex justify-content-center gap-2" style="font-size:.72rem;opacity:.9">
                            <span>&#10003; <?= number_format($total_unique_cleared) ?> Cleared</span>
                            <span>&#9679; <?= number_format($total_unique_pending) ?> Pending</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Student Category Breakdown (Unique) ── -->
            <div class="mb-4 p-3 rounded-3 border" style="background:#f8f9fa">
                <div class="small fw-semibold mb-2" style="color:#1e3c72">
                    <i class="bi bi-layers me-1"></i>Students by Category (Unique &mdash; No Duplicates)
                    <span class="text-muted fw-normal ms-2"><?= number_format($total_unique_all) ?> total unique</span>
                </div>
                <div class="d-flex flex-wrap gap-3">
                <?php
                $uniq_tiles = [
                    ['label'=>'All Students',  'cnt'=>$total_unique_all,          'color'=>'#1e3c72'],
                    ['label'=>'VLE Students',   'cnt'=>$total_unique_vle,          'color'=>'#0369a1'],
                    ['label'=>'Dissertation',   'cnt'=>$total_unique_dissertation, 'color'=>'#7c3aed'],
                    ['label'=>'Exam Clearance', 'cnt'=>$total_unique_ec,           'color'=>'#065f46'],
                ];
                foreach ($uniq_tiles as $ut):
                    $pct = $total_unique_all > 0 ? round($ut['cnt']/$total_unique_all*100,1) : 0;
                ?>
                <div class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-white"
                     style="background:<?= $ut['color'] ?>;min-width:160px">
                    <div>
                        <div class="fw-bold" style="font-size:1.25rem"><?= number_format($ut['cnt']) ?></div>
                        <div style="font-size:.72rem;opacity:.9"><?= htmlspecialchars($ut['label']) ?> (<?= $pct ?>%)</div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <?php if ($has_gender && ($kpi_male || $kpi_female)): ?>
            <!-- ── Overall Gender KPI bar ── -->
            <div class="mb-4 p-3 rounded-3 border" style="background:#f8f9fa">
                <div class="d-flex justify-content-between mb-1">
                    <span class="small fw-semibold" style="color:#1e3c72">Overall Gender Distribution</span>
                    <span class="small text-muted">All <?= number_format($total_unique_all) ?> Unique Students</span>
                </div>
                <?php
                $pct_m = $total_unique_all > 0 ? round($kpi_male/$total_unique_all*100,1) : 0;
                $pct_f = $total_unique_all > 0 ? round($kpi_female/$total_unique_all*100,1) : 0;
                $pct_o = max(0, 100 - $pct_m - $pct_f);
                ?>
                <div class="progress mb-1" style="height:18px;border-radius:6px">
                    <div class="progress-bar" style="width:<?= $pct_m ?>%;background:#1e3c72;font-size:.75rem" title="Male">&#9794; <?= $pct_m ?>%</div>
                    <div class="progress-bar" style="width:<?= $pct_f ?>%;background:#be185d;font-size:.75rem" title="Female">&#9792; <?= $pct_f ?>%</div>
                    <?php if ($pct_o > 0): ?>
                    <div class="progress-bar bg-secondary" style="width:<?= $pct_o ?>%;font-size:.75rem" title="Other/Not Specified"><?= $pct_o ?>%</div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-3 mt-1" style="font-size:.8rem">
                    <span><span style="color:#1e3c72">&#9632;</span> Male: <strong><?= number_format($kpi_male) ?></strong> (<?= $pct_m ?>%)</span>
                    <span><span style="color:#be185d">&#9632;</span> Female: <strong><?= number_format($kpi_female) ?></strong> (<?= $pct_f ?>%)</span>
                    <?php if ($total_unique_all - $kpi_male - $kpi_female > 0): ?>
                    <span><span style="color:#6b7280">&#9632;</span> Other/Unspecified: <strong><?= number_format($total_unique_all - $kpi_male - $kpi_female) ?></strong> (<?= $pct_o ?>%)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Campus Breakdown ── -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-geo-alt me-1"></i>Students by Campus<?= $has_gender ? ' &mdash; by Gender' : '' ?>
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr>
                                <th>Campus</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#1a3460">&#9794; Male</th>
                                <th class="text-center" style="background:#9b1458">&#9792; Female</th>
                                <th class="text-center" style="background:#374151">Other</th>
                                <?php endif; ?>
                                <th class="text-center">Share</th>
                                <?php if (in_array($_SESSION['vle_role'] ?? '', ['admin','super_admin'])): ?>
                                <th class="text-center no-print" style="width:36px"></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_campus as $row):
                            $pct = $total_unique_all > 0 ? round(($row['cnt']/$total_unique_all)*100,1) : 0;
                            $cg  = $sum_campus_gender[$row['campus']] ?? ['Male'=>0,'Female'=>0,'Other'=>0];
                            $safe_old_campus = htmlspecialchars($row['campus'], ENT_QUOTES);
                        ?>
                        <tr data-slr-type="campus" data-slr-old="<?= $safe_old_campus ?>">
                            <td class="slr-label-cell"><?= htmlspecialchars($row['campus']) ?></td>
                            <td class="text-center fw-semibold"><?= number_format($row['cnt']) ?></td>
                            <?php if ($has_gender): ?>
                            <td class="text-center"><?= number_format($cg['Male']) ?></td>
                            <td class="text-center"><?= number_format($cg['Female']) ?></td>
                            <td class="text-center text-muted"><?= number_format($cg['Other']) ?></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-grow-1" style="height:6px">
                                        <div class="progress-bar" style="width:<?= $pct ?>%;background:#1e3c72"></div>
                                    </div>
                                    <span class="text-muted small"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <?php if (in_array($_SESSION['vle_role'] ?? '', ['admin','super_admin'])): ?>
                            <td class="text-center no-print slr-edit-cell">
                                <button class="btn btn-link p-0 slr-edit-btn" title="Edit campus"
                                        style="color:#1e3c72;font-size:.8rem">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <div class="slr-editor d-none mt-1" style="min-width:160px">
                                    <select class="form-select form-select-sm slr-select mb-1">
                                        <?php foreach ($campuses as $c): ?>
                                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"
                                            <?= ($c === $row['campus']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-success btn-sm w-100 slr-save-btn">
                                        <i class="bi bi-check-lg me-1"></i>Save
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <?php
                            $foot_m = array_sum(array_column($sum_campus_gender, 'Male'));
                            $foot_f = array_sum(array_column($sum_campus_gender, 'Female'));
                            $foot_o = array_sum(array_column($sum_campus_gender, 'Other'));
                            ?>
                            <tr>
                                <th>Total</th>
                                <th class="text-center"><?= number_format($total_unique_all) ?></th>
                                <?php if ($has_gender): ?>
                                <th class="text-center"><?= number_format($foot_m) ?></th>
                                <th class="text-center"><?= number_format($foot_f) ?></th>
                                <th class="text-center"><?= number_format($foot_o) ?></th>
                                <?php endif; ?>
                                <th class="text-center">100%</th>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>

            <!-- ── Program Type Breakdown ── -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-mortarboard me-1"></i>Students by Program Type<?= $has_gender ? ' &mdash; by Gender' : '' ?>
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr>
                                <th>Program Type</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#1a3460">&#9794; Male</th>
                                <th class="text-center" style="background:#9b1458">&#9792; Female</th>
                                <th class="text-center" style="background:#374151">Other</th>
                                <?php endif; ?>
                                <th class="text-center">Share</th>
                            </tr>
                        <thead style="background:#1e3c72;color:#fff">
                            <tr>
                                <th>Program Type</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#1a3460">&#9794; Male</th>
                                <th class="text-center" style="background:#9b1458">&#9792; Female</th>
                                <th class="text-center" style="background:#374151">Other</th>
                                <?php endif; ?>
                                <th class="text-center">Share</th>
                                <?php if (in_array($_SESSION['vle_role'] ?? '', ['admin','super_admin'])): ?>
                                <th class="text-center no-print" style="width:36px"></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_program as $row):
                            $pct = $total_unique_all > 0 ? round(($row['cnt']/$total_unique_all)*100,1) : 0;
                            $pg  = $sum_program_gender[$row['program_type']] ?? ['Male'=>0,'Female'=>0,'Other'=>0];
                            $safe_old_prog = htmlspecialchars($row['program_type'], ENT_QUOTES);
                        ?>
                        <tr data-slr-type="program" data-slr-old="<?= $safe_old_prog ?>">
                            <td class="slr-label-cell"><?= htmlspecialchars(ucfirst($row['program_type'] ?: 'Not Set')) ?></td>
                            <td class="text-center fw-semibold"><?= number_format($row['cnt']) ?></td>
                            <?php if ($has_gender): ?>
                            <td class="text-center"><?= number_format($pg['Male']) ?></td>
                            <td class="text-center"><?= number_format($pg['Female']) ?></td>
                            <td class="text-center text-muted"><?= number_format($pg['Other']) ?></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <div class="d-flex align-items-center gap-1">
                                    <div class="progress flex-grow-1" style="height:6px">
                                        <div class="progress-bar" style="width:<?= $pct ?>%;background:#7c3aed"></div>
                                    </div>
                                    <span class="text-muted small"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <?php if (in_array($_SESSION['vle_role'] ?? '', ['admin','super_admin'])): ?>
                            <td class="text-center no-print slr-edit-cell">
                                <button class="btn btn-link p-0 slr-edit-btn" title="Edit program type"
                                        style="color:#7c3aed;font-size:.8rem">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <div class="slr-editor d-none mt-1" style="min-width:150px">
                                    <select class="form-select form-select-sm slr-select mb-1">
                                        <?php foreach ($programs as $pval => $plabel): ?>
                                        <option value="<?= htmlspecialchars($pval, ENT_QUOTES) ?>"
                                            <?= ($pval === $row['program_type']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($plabel) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-success btn-sm w-100 slr-save-btn">
                                        <i class="bi bi-check-lg me-1"></i>Save
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <?php
                            $foot_m = array_sum(array_column($sum_program_gender, 'Male'));
                            $foot_f = array_sum(array_column($sum_program_gender, 'Female'));
                            $foot_o = array_sum(array_column($sum_program_gender, 'Other'));
                            ?>
                            <tr>
                                <th>Total</th>
                                <th class="text-center"><?= number_format($total_unique_all) ?></th>
                                <?php if ($has_gender): ?>
                                <th class="text-center"><?= number_format($foot_m) ?></th>
                                <th class="text-center"><?= number_format($foot_f) ?></th>
                                <th class="text-center"><?= number_format($foot_o) ?></th>
                                <?php endif; ?>
                                <th class="text-center">100%</th>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>

            <!-- ── Year of Study Breakdown ── -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-calendar3 me-1"></i>Students by Year of Study<?= $has_gender ? ' &mdash; by Gender' : '' ?>
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr>
                                <th>Year</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#1a3460">&#9794; Male</th>
                                <th class="text-center" style="background:#9b1458">&#9792; Female</th>
                                <th class="text-center" style="background:#374151">Other</th>
                                <?php endif; ?>
                                <th class="text-center">%</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_year as $row):
                            $pct = $total_unique_all > 0 ? round(($row['cnt']/$total_unique_all)*100,1) : 0;
                            $yg  = $sum_year_gender[$row['yr']] ?? ['Male'=>0,'Female'=>0,'Other'=>0];
                        ?>
                        <tr>
                            <td>Year <?= htmlspecialchars($row['yr']) ?></td>
                            <td class="text-center fw-semibold"><?= number_format($row['cnt']) ?></td>
                            <?php if ($has_gender): ?>
                            <td class="text-center"><?= number_format($yg['Male']) ?></td>
                            <td class="text-center"><?= number_format($yg['Female']) ?></td>
                            <td class="text-center text-muted"><?= number_format($yg['Other']) ?></td>
                            <?php endif; ?>
                            <td class="text-center text-muted small"><?= $pct ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <?php
                            $foot_m = array_sum(array_column($sum_year_gender, 'Male'));
                            $foot_f = array_sum(array_column($sum_year_gender, 'Female'));
                            $foot_o = array_sum(array_column($sum_year_gender, 'Other'));
                            ?>
                            <tr>
                                <th>Total</th>
                                <th class="text-center"><?= number_format($total_unique_all) ?></th>
                                <?php if ($has_gender): ?>
                                <th class="text-center"><?= number_format($foot_m) ?></th>
                                <th class="text-center"><?= number_format($foot_f) ?></th>
                                <th class="text-center"><?= number_format($foot_o) ?></th>
                                <?php endif; ?>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>

                <!-- ── Class (Year × Semester) Breakdown ── -->
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-collection me-1"></i>Students per Class (Year &times; Semester)<?= $has_gender ? ' &mdash; by Gender' : '' ?>
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr>
                                <th>Year</th>
                                <th>Semester</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#1a3460">&#9794; M</th>
                                <th class="text-center" style="background:#9b1458">&#9792; F</th>
                                <th class="text-center" style="background:#374151">Oth</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_class as $cls):
                            $csg = $sum_class_gender[$cls['yr']][$cls['sem']] ?? ['Male'=>0,'Female'=>0,'Other'=>0];
                        ?>
                        <tr>
                            <td>Year <?= htmlspecialchars($cls['yr']) ?></td>
                            <td>Sem <?= htmlspecialchars($cls['sem']) ?></td>
                            <td class="text-center fw-semibold"><?= number_format($cls['cnt']) ?></td>
                            <?php if ($has_gender): ?>
                            <td class="text-center"><?= number_format($csg['Male']) ?></td>
                            <td class="text-center"><?= number_format($csg['Female']) ?></td>
                            <td class="text-center text-muted"><?= number_format($csg['Other']) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <?php
                            $cls_foot_m = $cls_foot_f = $cls_foot_o = 0;
                            foreach ($sum_class_gender as $_yr => $_sems) foreach ($_sems as $_sg_arr) {
                                $cls_foot_m += $_sg_arr['Male']; $cls_foot_f += $_sg_arr['Female']; $cls_foot_o += $_sg_arr['Other'];
                            }
                            ?>
                            <tr>
                                <th colspan="2">Total</th>
                                <th class="text-center"><?= number_format($total_unique_all) ?></th>
                                <?php if ($has_gender): ?>
                                <th class="text-center"><?= number_format($cls_foot_m) ?></th>
                                <th class="text-center"><?= number_format($cls_foot_f) ?></th>
                                <th class="text-center"><?= number_format($cls_foot_o) ?></th>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>

            <!-- ── Exam Clearance + Finance Status ── -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-patch-check me-1"></i>Exam Clearance Status
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr><th>Status</th><th class="text-center">Count</th><th class="text-center">Share</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $ec_total_html = $sum_clearance['total'];
                            $ec_rows = [
                                ['label'=>'Cleared',  'cnt'=>$sum_clearance['cleared'],  'badge'=>'bg-success'],
                                ['label'=>'Pending',  'cnt'=>$sum_clearance['pending'],  'badge'=>'bg-warning text-dark'],
                                ['label'=>'Rejected', 'cnt'=>$sum_clearance['rejected'], 'badge'=>'bg-danger'],
                            ];
                            foreach ($ec_rows as $er):
                                $pct = $ec_total_html > 0 ? round(($er['cnt']/$ec_total_html)*100,1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge <?= $er['badge'] ?>"><?= $er['label'] ?></span></td>
                                <td class="text-center fw-semibold"><?= number_format($er['cnt']) ?></td>
                                <td class="text-center text-muted small"><?= $pct ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total Processed</th><th class="text-center"><?= number_format($ec_total_html) ?></th><th></th></tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-cash-stack me-1"></i>Finance Payment Status
                    </h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr><th>Finance Status</th><th class="text-center">Count</th><th class="text-center">Share</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $fin_total_html = $sum_finance['fully_cleared'] + $sum_finance['has_balance'] + $sum_finance['pending_payment'];
                            $fin_rows = [
                                ['label'=>'Fully Paid &amp; Cleared',      'cnt'=>$sum_finance['fully_cleared'],   'badge'=>'bg-success'],
                                ['label'=>'Cleared — Balance Outstanding', 'cnt'=>$sum_finance['has_balance'],     'badge'=>'bg-warning text-dark'],
                                ['label'=>'Pending Payment',               'cnt'=>$sum_finance['pending_payment'], 'badge'=>'bg-danger'],
                            ];
                            foreach ($fin_rows as $fr):
                                $pct = $fin_total_html > 0 ? round(($fr['cnt']/$fin_total_html)*100,1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge <?= $fr['badge'] ?>"><?= $fr['label'] ?></span></td>
                                <td class="text-center fw-semibold"><?= number_format($fr['cnt']) ?></td>
                                <td class="text-center text-muted small"><?= $pct ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total</th><th class="text-center"><?= number_format($fin_total_html) ?></th><th></th></tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>

            <?php if (!empty($sum_postgrad)): ?>
            <!-- ── Postgraduate Breakdown ── -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <h6 class="fw-semibold mb-2" style="color:#7c3aed;border-bottom:2px solid #7c3aed;padding-bottom:4px">
                        <i class="bi bi-award me-1"></i>Cleared Postgraduate Enrolment &mdash; Exam Numbers Assigned<?= $has_gender ? ' &mdash; by Gender' : '' ?>
                    </h6>
                    <?php
                    $pg_gender_map = [];
                    foreach ($sum_postgrad_gender as $pg) {
                        $_gl = strtolower(trim($pg['gender']));
                        $_gk = ($_gl==='male'||$_gl==='m') ? 'Male' : (($_gl==='female'||$_gl==='f') ? 'Female' : 'Other');
                        if (!isset($pg_gender_map[$pg['program_type']])) $pg_gender_map[$pg['program_type']] = ['Male'=>0,'Female'=>0,'Other'=>0];
                        $pg_gender_map[$pg['program_type']][$_gk] += (int)$pg['cnt'];
                    }
                    ?>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#7c3aed;color:#fff">
                            <tr>
                                <th>Postgraduate Program</th>
                                <th class="text-center">Total</th>
                                <?php if ($has_gender): ?>
                                <th class="text-center" style="background:#5b21b6">&#9794; Male</th>
                                <th class="text-center" style="background:#7e1d5f">&#9792; Female</th>
                                <th class="text-center" style="background:#374151">Other</th>
                                <?php endif; ?>
                                <th class="text-center">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_postgrad as $pg):
                            $pct = $total_postgrad_all > 0 ? round(($pg['cnt']/$total_postgrad_all)*100,1) : 0;
                            $gm  = $pg_gender_map[$pg['program_type']] ?? ['Male'=>0,'Female'=>0,'Other'=>0];
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars(ucwords(str_replace('_',' ',$pg['program_type']))) ?></td>
                            <td class="text-center fw-bold"><?= number_format($pg['cnt']) ?></td>
                            <?php if ($has_gender): ?>
                            <td class="text-center"><?= number_format($gm['Male']) ?></td>
                            <td class="text-center"><?= number_format($gm['Female']) ?></td>
                            <td class="text-center text-muted"><?= number_format($gm['Other']) ?></td>
                            <?php endif; ?>
                            <td class="text-center text-muted small"><?= $pct ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#ede9fe">
                            <?php
                            $pg_foot_m = array_sum(array_column($pg_gender_map, 'Male'));
                            $pg_foot_f = array_sum(array_column($pg_gender_map, 'Female'));
                            $pg_foot_o = array_sum(array_column($pg_gender_map, 'Other'));
                            ?>
                            <tr>
                                <th>Total Cleared Postgraduate</th>
                                <th class="text-center"><?= number_format($total_postgrad_all) ?></th>
                                <?php if ($has_gender): ?>
                                <th class="text-center"><?= number_format($pg_foot_m) ?></th>
                                <th class="text-center"><?= number_format($pg_foot_f) ?></th>
                                <th class="text-center"><?= number_format($pg_foot_o) ?></th>
                                <?php endif; ?>
                                <th class="text-center">100%</th>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($has_gender && !empty($sum_gender)): ?>
            <!-- ── Overall Gender Summary ── -->
            <div class="row g-3 mb-2">
                <div class="col-md-5">
                    <h6 class="fw-semibold mb-2" style="color:#1e3c72;border-bottom:2px solid #1e3c72;padding-bottom:4px">
                        <i class="bi bi-people me-1"></i>All Students by Gender
                    </h6>
                    <?php $gen_total = array_sum(array_column($sum_gender, 'cnt')); ?>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0" style="font-size:.85rem">
                        <thead style="background:#1e3c72;color:#fff">
                            <tr><th>Gender</th><th class="text-center">Count</th><th class="text-center">Share</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sum_gender as $row):
                            $pct = $gen_total > 0 ? round(($row['cnt']/$gen_total)*100,1) : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(ucfirst($row['gender'] ?: 'Not Specified')) ?></td>
                            <td class="text-center fw-semibold"><?= number_format($row['cnt']) ?></td>
                            <td class="text-center text-muted small"><?= $pct ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot style="background:#e8edf5">
                            <tr><th>Total</th><th class="text-center"><?= number_format($gen_total) ?></th><th>100%</th></tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <p class="text-muted text-end mb-0 mt-2" style="font-size:.75rem">
                <i class="bi bi-lock me-1"></i>Confidential &mdash; For Management Use Only &mdash; <?= date('d M Y H:i') ?>
            </p>
        </div><!-- /card-body -->
        </div><!-- /collapse -->
    </div><!-- /mgmt-summary-card -->

    <script>
    // Flip chevron on collapse toggle
    (function(){
        var btn = document.querySelector('[data-bs-target="#mgmt-summary-body"]');
        var ico = document.getElementById('mgmt-chevron');
        if (btn && ico) {
            document.getElementById('mgmt-summary-body').addEventListener('shown.bs.collapse', function(){
                ico.className = 'bi bi-chevron-up';
            });
            document.getElementById('mgmt-summary-body').addEventListener('hidden.bs.collapse', function(){
                ico.className = 'bi bi-chevron-down';
            });
        }
    })();
    </script>

    <!-- Results Table -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold"><i class="bi bi-table me-1"></i><?= number_format($total_unique_all) ?> Unique Student(s) &mdash; <span class="text-muted fw-normal" style="font-size:.85rem"><?= count($table_students) ?> record(s) across specific sources</span></span>
            <?php if (!empty($filter_campus) || !empty($filter_year) || !empty($filter_semester) || !empty($filter_program) || !empty($filter_search) || $filter_source !== 'all'): ?>
            <small class="text-muted no-print">Filtered view</small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($table_students)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-search fs-1 d-block mb-2"></i>
                No students match the selected filters.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="studentTable">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center" style="width:45px">#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Campus</th>
                            <th>Program Type</th>
                            <th>Program / Study</th>
                            <th class="text-center">Year</th>
                            <th class="text-center">Sem</th>
                            <th class="no-print">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table_students as $i => $s):
                            $src = $s['source_label'];
                            $src_class = match(true) {
                                str_contains($src, 'VLE')          => 'source-vle',
                                str_contains($src, 'Dissertation') => 'source-dissertation',
                                str_contains($src, 'Exam')         => 'source-ec',
                                default                            => 'source-all',
                            };
                        ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $i + 1 ?></td>
                            <td><code class="small"><?= htmlspecialchars($s['student_id']) ?></code></td>
                            <td class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($s['email']) ?></td>
                            <td class="small"><?= htmlspecialchars($s['campus']) ?></td>
                            <td><span class="badge bg-secondary"><?= ucfirst(htmlspecialchars($s['program_type'])) ?></span></td>
                            <td class="small"><?= htmlspecialchars($s['program']) ?></td>
                            <td class="text-center small"><?= htmlspecialchars($s['year_of_study']) ?></td>
                            <td class="text-center small"><?= htmlspecialchars($s['semester']) ?></td>
                            <td class="no-print">
                                <span class="source-badge <?= $src_class ?>"><?= htmlspecialchars($src) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($table_students)): ?>
        <div class="card-footer small text-muted d-flex justify-content-between no-print">
            <span>Total: <strong><?= count($table_students) ?></strong> record(s) from specific sources</span>
            <span>Generated: <?= date('d M Y H:i') ?></span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ── Inline campus / program-type editor ── -->
<script>
(function () {
    'use strict';

    // Toggle editor open; lock if already saved
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.slr-edit-btn');
        if (!btn) return;
        var row = btn.closest('tr');
        if (row.dataset.slrSaved) return; // already saved once — locked
        var editor = btn.closest('.slr-edit-cell').querySelector('.slr-editor');
        editor.classList.toggle('d-none');
    });

    // Save
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.slr-save-btn');
        if (!btn) return;

        var row     = btn.closest('tr');
        var type    = row.dataset.slrType;   // 'campus' or 'program'
        var oldVal  = row.dataset.slrOld;
        var select  = btn.closest('.slr-editor').querySelector('.slr-select');
        var newVal  = select.value;

        if (!newVal || newVal === oldVal) {
            select.closest('.slr-editor').classList.add('d-none');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        var fd = new FormData();
        fd.append('slr_action',  type === 'campus' ? 'update_campus' : 'update_program');
        fd.append('old_val', oldVal);
        fd.append('new_val', newVal);

        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    // Update label cell
                    var labelCell = row.querySelector('.slr-label-cell');
                    labelCell.textContent = type === 'campus'
                        ? data.new_label
                        : (data.new_label.charAt(0).toUpperCase() + data.new_label.slice(1));

                    // Update data-slr-old so tfoot / display stays consistent
                    row.dataset.slrOld = newVal;
                    row.dataset.slrSaved = '1';

                    // Hide editor, replace pencil with lock icon
                    var editCell = btn.closest('.slr-edit-cell');
                    editCell.innerHTML = '<span title="' + data.msg + '" style="color:#16a34a;font-size:.85rem"><i class="bi bi-lock-fill"></i></span>';

                    // Green flash on row
                    row.style.transition = 'background .15s';
                    row.style.background = '#d1fae5';
                    setTimeout(function () {
                        row.style.background = '';
                    }, 1800);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                    alert('Error: ' + (data.msg || 'Could not save.'));
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                alert('Network error — please try again.');
            });
    });
})();
</script>
</body>
</html>
