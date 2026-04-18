<?php
/**
 * Auto-Generate Semester Learning Timetable
 * Called via AJAX from ODL/Dean timetable pages
 * 
 * Algorithm:
 * 1. Get all active courses for specified semester/year levels
 * 2. Get available rooms and time slots
 * 3. For each year group, assign courses to slots avoiding:
 *    - Same venue at same time
 *    - Same lecturer at same time
 *    - Same year group at same time
 * 4. Insert into class_timetable
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['odl_coordinator', 'dean', 'admin', 'staff']);

header('Content-Type: application/json');

$conn = getDbConnection();
$user = getCurrentUser();

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS class_timetable (
    timetable_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(100),
    session_type ENUM('lecture','tutorial','practical','exam','online') DEFAULT 'lecture',
    semester VARCHAR(20) DEFAULT 'One',
    academic_year VARCHAR(20),
    year_of_study INT DEFAULT NULL,
    program_type ENUM('weekday','weekend','all') DEFAULT 'all',
    generation_log_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (course_id), INDEX (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fall back to POST
    $input = $_POST;
}

$semester = $input['semester'] ?? 'One';
$academic_year = $input['academic_year'] ?? (date('Y') . '/' . (date('Y') + 1));
$year_levels = $input['year_levels'] ?? [1, 2, 3, 4]; // Years to generate for
$program_type = $input['program_type'] ?? 'weekday';
$clear_existing = !empty($input['clear_existing']);
$slot_duration = (int)($input['slot_duration'] ?? 90); // minutes

if (!is_array($year_levels)) {
    $year_levels = explode(',', $year_levels);
}
$year_levels = array_map('intval', $year_levels);
$year_levels = array_filter($year_levels, fn($y) => $y >= 1 && $y <= 6);

if (empty($year_levels)) {
    echo json_encode(['success' => false, 'error' => 'No valid year levels specified']);
    exit;
}

// Define time slots based on program type
if ($program_type === 'weekend') {
    $days = ['Saturday', 'Sunday'];
    $time_slots = [
        ['08:00', '09:30'], ['09:45', '11:15'], ['11:30', '13:00'],
        ['14:00', '15:30'], ['15:45', '17:15']
    ];
} else {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $time_slots = [
        ['08:00', '09:30'], ['09:45', '11:15'], ['11:30', '13:00'],
        ['14:00', '15:30'], ['15:45', '17:15']
    ];
}

// Override slot times if custom duration
if ($slot_duration !== 90) {
    $time_slots = [];
    $morning_start = 8 * 60; // 08:00 in minutes
    $lunch_start = 13 * 60;
    $afternoon_start = 14 * 60;
    $day_end = 17 * 60;
    $break = 15; // 15 min break between slots
    
    $t = $morning_start;
    while ($t + $slot_duration <= $lunch_start) {
        $start = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
        $end_min = $t + $slot_duration;
        $end = sprintf('%02d:%02d', intdiv($end_min, 60), $end_min % 60);
        $time_slots[] = [$start, $end];
        $t = $end_min + $break;
    }
    $t = $afternoon_start;
    while ($t + $slot_duration <= $day_end) {
        $start = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
        $end_min = $t + $slot_duration;
        $end = sprintf('%02d:%02d', intdiv($end_min, 60), $end_min % 60);
        $time_slots[] = [$start, $end];
        $t = $end_min + $break;
    }
}

// Get available rooms
$rooms = [];
$rooms_result = $conn->query("SELECT room_name FROM timetable_rooms WHERE is_active = 1 AND room_type IN ('lecture_hall', 'seminar_room', 'lab') ORDER BY capacity DESC");
if ($rooms_result) {
    while ($row = $rooms_result->fetch_assoc()) {
        $rooms[] = $row['room_name'];
    }
}
if (empty($rooms)) {
    $rooms = ['LH-101', 'LH-102', 'LH-103', 'LH-201', 'LH-202', 'SR-101', 'SR-102', 'LAB-A', 'LAB-B'];
}

// Clear existing entries if requested
if ($clear_existing) {
    $year_list = implode(',', $year_levels);
    $stmt = $conn->prepare("DELETE FROM class_timetable WHERE semester = ? AND academic_year = ? AND year_of_study IN ($year_list) AND program_type = ?");
    $stmt->bind_param("sss", $semester, $academic_year, $program_type);
    $stmt->execute();
}

// Get courses for the specified semester and year levels
$year_placeholders = implode(',', array_fill(0, count($year_levels), '?'));
$types = 's' . str_repeat('i', count($year_levels));
$bind_params = array_merge([$semester], $year_levels);

$sql = "SELECT c.course_id, c.course_code, c.course_name, c.year_of_study, c.lecturer_id, c.semester as course_semester
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
    echo json_encode([
        'success' => false,
        'error' => 'No active courses found for semester ' . $semester . ' in year levels ' . implode(', ', $year_levels)
    ]);
    exit;
}

// Track occupied slots: venue-day-time, lecturer-day-time, yeargroup-day-time
$occupied_venue = [];    // "venue|day|start" => true
$occupied_lecturer = []; // "lecturer_id|day|start" => true
$occupied_year = [];     // "year|day|start" => true

// Load existing timetable entries to avoid conflicts
$existing = $conn->query("SELECT * FROM class_timetable WHERE semester = '$semester' AND academic_year = '" . $conn->real_escape_string($academic_year) . "' AND is_active = 1");
if ($existing) {
    while ($ex = $existing->fetch_assoc()) {
        $key_v = $ex['venue'] . '|' . $ex['day_of_week'] . '|' . $ex['start_time'];
        $occupied_venue[$key_v] = true;
        
        // Get lecturer for existing entry
        $c_check = $conn->query("SELECT lecturer_id FROM vle_courses WHERE course_id = " . (int)$ex['course_id']);
        if ($c_check && $c_row = $c_check->fetch_assoc()) {
            $key_l = $c_row['lecturer_id'] . '|' . $ex['day_of_week'] . '|' . $ex['start_time'];
            $occupied_lecturer[$key_l] = true;
        }
        
        if ($ex['year_of_study']) {
            $key_y = $ex['year_of_study'] . '|' . $ex['day_of_week'] . '|' . $ex['start_time'];
            $occupied_year[$key_y] = true;
        }
    }
}

// Log the generation
$conn->query("CREATE TABLE IF NOT EXISTS timetable_generation_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    generation_type ENUM('semester','exam') NOT NULL,
    semester VARCHAR(20) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    year_levels VARCHAR(50) DEFAULT 'all',
    total_entries INT DEFAULT 0,
    conflicts_found INT DEFAULT 0,
    generated_by INT DEFAULT NULL,
    status ENUM('draft','published','archived') DEFAULT 'draft',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$log_stmt = $conn->prepare("INSERT INTO timetable_generation_log (generation_type, semester, academic_year, year_levels, generated_by) VALUES ('semester', ?, ?, ?, ?)");
$yl_str = implode(',', $year_levels);
$log_stmt->bind_param("sssi", $semester, $academic_year, $yl_str, $user['user_id']);
$log_stmt->execute();
$log_id = $conn->insert_id;

$entries_created = 0;
$conflicts = 0;
$unassigned = [];

// Assign courses to slots
foreach ($courses_by_year as $year => $courses) {
    // Shuffle days for variety each year group
    $year_days = $days;
    
    $day_index = 0;
    $slot_index = 0;
    
    foreach ($courses as $course) {
        $assigned = false;
        
        // Try each day/slot/room combination
        for ($d = 0; $d < count($year_days) && !$assigned; $d++) {
            $day_try = $year_days[($day_index + $d) % count($year_days)];
            
            for ($s = 0; $s < count($time_slots) && !$assigned; $s++) {
                $slot_try = $time_slots[($slot_index + $s) % count($time_slots)];
                $start = $slot_try[0];
                $end = $slot_try[1];
                
                // Check year group conflict
                $key_y = $year . '|' . $day_try . '|' . $start;
                if (isset($occupied_year[$key_y])) continue;
                
                // Check lecturer conflict
                if ($course['lecturer_id']) {
                    $key_l = $course['lecturer_id'] . '|' . $day_try . '|' . $start;
                    if (isset($occupied_lecturer[$key_l])) continue;
                }
                
                // Find available room
                $assigned_room = null;
                foreach ($rooms as $room) {
                    $key_v = $room . '|' . $day_try . '|' . $start;
                    if (!isset($occupied_venue[$key_v])) {
                        $assigned_room = $room;
                        break;
                    }
                }
                
                if (!$assigned_room) continue;
                
                // Assign this slot
                $insert_stmt = $conn->prepare("INSERT INTO class_timetable (course_id, day_of_week, start_time, end_time, venue, session_type, semester, academic_year, year_of_study, program_type, generation_log_id, is_active, created_by) VALUES (?, ?, ?, ?, ?, 'lecture', ?, ?, ?, ?, ?, 1, ?)");
                $insert_stmt->bind_param("issssssisii", 
                    $course['course_id'], $day_try, $start, $end, $assigned_room,
                    $semester, $academic_year, $year, $program_type, $log_id, $user['user_id']
                );
                
                if ($insert_stmt->execute()) {
                    // Mark slots as occupied
                    $occupied_venue[$assigned_room . '|' . $day_try . '|' . $start] = true;
                    $occupied_year[$key_y] = true;
                    if ($course['lecturer_id']) {
                        $occupied_lecturer[$course['lecturer_id'] . '|' . $day_try . '|' . $start] = true;
                    }
                    $entries_created++;
                    $assigned = true;
                }
            }
        }
        
        if (!$assigned) {
            $conflicts++;
            $unassigned[] = $course['course_code'] . ' (' . $course['course_name'] . ') - Year ' . $year;
        }
        
        // Advance starting position for next course to distribute across days
        $day_index = ($day_index + 1) % count($year_days);
        if ($day_index === 0) {
            $slot_index = ($slot_index + 1) % count($time_slots);
        }
    }
}

// Update log
$conn->query("UPDATE timetable_generation_log SET total_entries = $entries_created, conflicts_found = $conflicts WHERE log_id = $log_id");

echo json_encode([
    'success' => true,
    'message' => "Timetable generated: $entries_created entries created" . ($conflicts ? ", $conflicts courses could not be assigned" : ""),
    'entries_created' => $entries_created,
    'conflicts' => $conflicts,
    'unassigned' => $unassigned,
    'log_id' => $log_id
]);

$conn->close();
