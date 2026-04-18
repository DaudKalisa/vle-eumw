<?php
/**
 * Auto-Generate Examination Timetable
 * Called via AJAX from Exam Officer/Manager pages
 * 
 * Algorithm:
 * 1. Get all courses for specified semester/year levels
 * 2. Get exam period dates from academic calendar
 * 3. Spread exams across the period: 2 sessions/day (AM + PM)
 * 4. No year group should have 2 exams on same day
 * 5. Insert into exam_timetable
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'admin', 'staff']);

header('Content-Type: application/json');

$conn = getDbConnection();
$user = getCurrentUser();

// Ensure exam_timetable exists
$conn->query("CREATE TABLE IF NOT EXISTS exam_timetable (
    exam_timetable_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(100) DEFAULT '',
    exam_type ENUM('mid_term','final','supplementary','deferred') DEFAULT 'final',
    semester VARCHAR(20) DEFAULT 'One',
    academic_year VARCHAR(20) DEFAULT '',
    year_of_study INT DEFAULT 1,
    program_type ENUM('weekday','weekend','all') DEFAULT 'all',
    duration_minutes INT DEFAULT 180,
    invigilator_id INT DEFAULT NULL,
    status ENUM('draft','published','cancelled') DEFAULT 'draft',
    created_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (course_id), INDEX (exam_date), INDEX (semester, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$semester = $input['semester'] ?? 'One';
$academic_year = $input['academic_year'] ?? (date('Y') . '/' . (date('Y') + 1));
$exam_type = $input['exam_type'] ?? 'final';
$year_levels = $input['year_levels'] ?? [1, 2, 3, 4];
$exam_start_date = $input['exam_start_date'] ?? '';
$exam_end_date = $input['exam_end_date'] ?? '';
$duration = (int)($input['duration_minutes'] ?? 180);
$clear_existing = !empty($input['clear_existing']);

if (!is_array($year_levels)) {
    $year_levels = explode(',', $year_levels);
}
$year_levels = array_map('intval', array_filter($year_levels, fn($y) => $y >= 1 && $y <= 6));

if (empty($year_levels)) {
    echo json_encode(['success' => false, 'error' => 'No valid year levels specified']);
    exit;
}

// Try to get exam dates from academic calendar if not provided
if (empty($exam_start_date) || empty($exam_end_date)) {
    $event_type = ($exam_type === 'mid_term') ? 'Mid-Semester' : 'End of Semester';
    $cal = $conn->prepare("SELECT start_date, end_date FROM academic_calendar WHERE academic_year = ? AND semester = ? AND event_name LIKE CONCAT('%', ?, '%') AND event_type = 'exam_start' LIMIT 1");
    $sem_num = ($semester === 'One') ? '1' : '2';
    $cal->bind_param("sss", $academic_year, $sem_num, $event_type);
    $cal->execute();
    $cal_result = $cal->get_result();
    if ($row = $cal_result->fetch_assoc()) {
        if (empty($exam_start_date)) $exam_start_date = $row['start_date'];
        if (empty($exam_end_date)) $exam_end_date = $row['end_date'];
    }
}

// Fallback: start in 2 weeks, run for 3 weeks
if (empty($exam_start_date)) {
    $exam_start_date = date('Y-m-d', strtotime('+14 days'));
}
if (empty($exam_end_date)) {
    $exam_end_date = date('Y-m-d', strtotime($exam_start_date . ' +21 days'));
}

// Exam sessions: Morning and Afternoon
$sessions = [
    ['start' => '08:00', 'end_offset' => $duration],
    ['start' => '14:00', 'end_offset' => $duration]
];

// Calculate end times
foreach ($sessions as &$s) {
    $start_min = intval(substr($s['start'], 0, 2)) * 60 + intval(substr($s['start'], 3, 2));
    $end_min = $start_min + $s['end_offset'];
    $s['end'] = sprintf('%02d:%02d', intdiv($end_min, 60), $end_min % 60);
}
unset($s);

// Get exam venues
$venues = [];
$v_result = $conn->query("SELECT room_name FROM timetable_rooms WHERE is_active = 1 AND room_type = 'exam_hall' ORDER BY capacity DESC");
if ($v_result) {
    while ($row = $v_result->fetch_assoc()) {
        $venues[] = $row['room_name'];
    }
}
if (empty($venues)) {
    $venues = ['EH-Main', 'EH-A', 'EH-B'];
}

// Generate exam dates (weekdays only, excluding weekends)
$exam_dates = [];
$current = new DateTime($exam_start_date);
$end = new DateTime($exam_end_date);
while ($current <= $end) {
    $dow = (int)$current->format('N'); // 1=Mon, 7=Sun
    if ($dow <= 5) { // Weekdays only
        $exam_dates[] = $current->format('Y-m-d');
    }
    $current->modify('+1 day');
}

if (empty($exam_dates)) {
    echo json_encode(['success' => false, 'error' => 'No valid exam dates in the specified range']);
    exit;
}

// Clear existing if requested
if ($clear_existing) {
    $stmt = $conn->prepare("DELETE FROM exam_timetable WHERE semester = ? AND academic_year = ? AND exam_type = ?");
    $stmt->bind_param("sss", $semester, $academic_year, $exam_type);
    $stmt->execute();
}

// Get courses
$year_placeholders = implode(',', array_fill(0, count($year_levels), '?'));
$types = 's' . str_repeat('i', count($year_levels));
$bind_params = array_merge([$semester], $year_levels);

$sql = "SELECT c.course_id, c.course_code, c.course_name, c.year_of_study, c.lecturer_id
        FROM vle_courses c
        WHERE c.is_active = 1 
        AND (c.semester = ? OR c.semester = 'Both')
        AND c.year_of_study IN ($year_placeholders)
        ORDER BY c.year_of_study, c.course_code";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();

$courses_by_year = [];
while ($row = $result->fetch_assoc()) {
    $year = (int)$row['year_of_study'];
    $courses_by_year[$year][] = $row;
}

if (empty($courses_by_year)) {
    echo json_encode(['success' => false, 'error' => 'No active courses found for the specified criteria']);
    exit;
}

// Log
$conn->query("CREATE TABLE IF NOT EXISTS timetable_generation_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    generation_type ENUM('semester','exam') NOT NULL,
    semester VARCHAR(20), academic_year VARCHAR(20),
    year_levels VARCHAR(50), total_entries INT DEFAULT 0,
    conflicts_found INT DEFAULT 0, generated_by INT,
    status ENUM('draft','published','archived') DEFAULT 'draft',
    notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$log_stmt = $conn->prepare("INSERT INTO timetable_generation_log (generation_type, semester, academic_year, year_levels, generated_by) VALUES ('exam', ?, ?, ?, ?)");
$yl_str = implode(',', $year_levels);
$log_stmt->bind_param("sssi", $semester, $academic_year, $yl_str, $user['user_id']);
$log_stmt->execute();
$log_id = $conn->insert_id;

// Track: date|session => year_group (no 2 exams same day for same year)
$year_day_used = []; // "year|date" => count
$venue_session = []; // "venue|date|session_index" => true

$entries_created = 0;
$conflicts = 0;
$unassigned = [];

$date_idx = 0;
$session_idx = 0;

foreach ($courses_by_year as $year => $courses) {
    $di = 0;
    $si = 0;
    
    foreach ($courses as $course) {
        $assigned = false;
        
        // Try each date/session/venue
        for ($try_d = 0; $try_d < count($exam_dates) && !$assigned; $try_d++) {
            $date = $exam_dates[($di + $try_d) % count($exam_dates)];
            
            // Check: year group shouldn't have more than 1 exam per day
            $yd_key = $year . '|' . $date;
            if (isset($year_day_used[$yd_key])) continue;
            
            for ($try_s = 0; $try_s < count($sessions) && !$assigned; $try_s++) {
                $sess_idx = ($si + $try_s) % count($sessions);
                $session = $sessions[$sess_idx];
                
                // Find available venue
                foreach ($venues as $venue) {
                    $vk = $venue . '|' . $date . '|' . $sess_idx;
                    if (isset($venue_session[$vk])) continue;
                    
                    // Assign
                    $insert = $conn->prepare("INSERT INTO exam_timetable (course_id, exam_date, start_time, end_time, venue, exam_type, semester, academic_year, year_of_study, duration_minutes, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
                    $insert->bind_param("isssssssiis", 
                        $course['course_id'], $date, $session['start'], $session['end'],
                        $venue, $exam_type, $semester, $academic_year, $year, $duration, $user['user_id']
                    );
                    
                    if ($insert->execute()) {
                        $venue_session[$vk] = true;
                        $year_day_used[$yd_key] = true;
                        $entries_created++;
                        $assigned = true;
                    }
                    break;
                }
            }
        }
        
        if (!$assigned) {
            $conflicts++;
            $unassigned[] = $course['course_code'] . ' - Year ' . $year;
        }
        
        // Advance
        $di = ($di + 1) % count($exam_dates);
        if ($di === 0) $si = ($si + 1) % count($sessions);
    }
}

$conn->query("UPDATE timetable_generation_log SET total_entries = $entries_created, conflicts_found = $conflicts WHERE log_id = $log_id");

echo json_encode([
    'success' => true,
    'message' => "Exam timetable generated: $entries_created entries" . ($conflicts ? ", $conflicts unassigned" : ""),
    'entries_created' => $entries_created,
    'conflicts' => $conflicts,
    'unassigned' => $unassigned,
    'exam_start' => $exam_start_date,
    'exam_end' => $exam_end_date,
    'log_id' => $log_id
]);

$conn->close();
