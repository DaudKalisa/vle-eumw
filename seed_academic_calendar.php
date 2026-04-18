<?php
/**
 * Academic Calendar Seeder
 * Seeds the academic_calendar table with the 3-semester 2026 calendar data
 * Run once via browser: /seed_academic_calendar.php
 */

require_once 'includes/config.php';
$conn = getDbConnection();

// Ensure table and columns exist
$conn->query("CREATE TABLE IF NOT EXISTS academic_calendar (
    calendar_id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    event_type ENUM('semester_start','semester_end','exam_start','exam_end','registration_start','registration_end','holiday','break','graduation','other') DEFAULT 'other',
    program_type ENUM('all','weekday','weekend') DEFAULT 'all',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_year (academic_year),
    INDEX idx_semester (semester),
    INDEX idx_event_type (event_type),
    INDEX idx_program_type (program_type),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$col_check = $conn->query("SHOW COLUMNS FROM academic_calendar LIKE 'program_type'");
if (!$col_check || $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE academic_calendar ADD COLUMN program_type ENUM('all','weekday','weekend') DEFAULT 'all' AFTER event_type");
}
$col_check = $conn->query("SHOW COLUMNS FROM academic_calendar LIKE 'created_by'");
if (!$col_check || $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE academic_calendar ADD COLUMN created_by INT NULL AFTER is_active");
}

// =====================================================================
// SEMESTER 1: January to June 2026 (Academic Year 2025/2026)
// =====================================================================
$semester1 = [
    ['Orientation for New Students (Weekday)', 'other', 'weekday', '2026-01-12', null, 'Orientation for new Day students'],
    ['Public Holiday - John Chilembwe Day', 'holiday', 'all', '2026-01-15', null, 'National public holiday'],
    ['Orientation for New Students (Weekend)', 'other', 'weekend', '2026-01-17', null, 'Orientation for new Weekend students'],
    ['Opening Day & Commencement of Classes (Weekday)', 'semester_start', 'weekday', '2026-01-19', null, 'Weekday classes begin'],
    ['Supplementary Exams', 'exam_start', 'all', '2026-01-20', '2026-01-22', 'Supplementary examinations period'],
    ['1st Assignment Submission', 'other', 'all', '2026-02-16', null, 'Deadline for 1st assignment submission'],
    ['Public Holiday - Martyrs Day', 'holiday', 'all', '2026-03-03', null, 'National public holiday'],
    ['Mid-Semester Examination', 'exam_start', 'all', '2026-03-09', '2026-03-13', 'Mid-semester examination period'],
    ['Mid-Semester Break', 'break', 'all', '2026-03-16', '2026-03-20', 'Mid-semester break'],
    ['Resumption of Classes (Weekday)', 'other', 'weekday', '2026-03-23', null, 'Weekday classes resume after mid-semester break'],
    ['Resumption of Classes (Weekend)', 'other', 'weekend', '2026-03-28', null, 'Weekend classes resume after mid-semester break'],
    ['Public Holiday - Easter Holiday', 'holiday', 'all', '2026-04-03', '2026-04-06', 'Easter holiday period'],
    ['2nd Assignment Submission', 'other', 'all', '2026-04-13', null, 'Deadline for 2nd assignment submission'],
    ['Study Break', 'break', 'all', '2026-04-27', '2026-04-30', 'Study break before end of semester exams'],
    ['End of Semester Examination Period', 'exam_start', 'all', '2026-05-04', '2026-05-08', 'End of semester examination period'],
    ['Marking & Uploading End Semester Results', 'other', 'all', '2026-05-04', '2026-06-02', 'Marking and uploading of end semester examination results'],
    ['Deferred Examinations', 'exam_start', 'all', '2026-05-11', '2026-05-13', 'Deferred examinations period'],
    ['End of Semester Holiday', 'break', 'all', '2026-05-11', '2026-06-05', 'End of semester holiday period'],
    ['Marking & Uploading Deferred Exam Results', 'other', 'all', '2026-05-13', '2026-06-02', 'Marking and uploading of deferred examination results'],
    ['Departmental Meeting & Uploading Grades', 'other', 'all', '2026-05-26', null, 'Departmental meeting and uploading grades into the system'],
    ['Verification of Results by Exam Committee', 'other', 'all', '2026-05-27', null, 'Verification of uploaded results in the system by exam committee'],
    ['Pre-Senate Meeting', 'other', 'weekday', '2026-05-28', null, 'Pre-senate meeting'],
    ['Consolidation of Grades (Exam Office)', 'other', 'weekday', '2026-05-28', '2026-06-02', 'Consolidation of grades by exam office'],
    ['Research Dissertation Final Presentation', 'other', 'all', '2026-05-31', null, 'Research dissertation final presentations'],
    ['Senate Meeting', 'other', 'weekday', '2026-06-03', null, 'Senate meeting'],
    ['Release of End of Semester Results', 'other', 'all', '2026-06-06', null, 'Official release of end of semester results'],
];

// =====================================================================
// SEMESTER 2: June to October 2026 (Academic Year 2025/2026)
// =====================================================================
$semester2 = [
    ['Orientation for New Students (Weekday)', 'other', 'weekday', '2026-06-01', null, 'Orientation for new Day students'],
    ['Orientation for New Students (Weekend)', 'other', 'weekend', '2026-06-06', null, 'Orientation for new Weekend students'],
    ['Opening Day & Commencement of Classes (Weekday)', 'semester_start', 'weekday', '2026-06-08', null, 'Weekday classes begin'],
    ['Opening Day & Commencement of Classes (Weekend)', 'semester_start', 'weekend', '2026-06-13', null, 'Weekend classes begin'],
    ['1st Assignment Submission', 'other', 'all', '2026-07-03', null, 'Deadline for 1st assignment submission'],
    ['Public Holiday - Independence Day', 'holiday', 'all', '2026-07-06', null, 'National public holiday - Independence Day'],
    ['Mid-Semester Examination', 'exam_start', 'all', '2026-07-20', '2026-07-24', 'Mid-semester examination period'],
    ['Mid-Semester Break', 'break', 'all', '2026-07-27', '2026-07-31', 'Mid-semester break'],
    ['Resumption of Classes (Weekday)', 'other', 'weekday', '2026-08-03', null, 'Weekday classes resume after mid-semester break'],
    ['Resumption of Classes (Weekend)', 'other', 'weekend', '2026-08-08', null, 'Weekend classes resume after mid-semester break'],
    ['2nd Assignment Submission', 'other', 'all', '2026-08-17', null, 'Deadline for 2nd assignment submission'],
    ['Study Break', 'break', 'all', '2026-09-07', '2026-09-11', 'Study break before end of semester exams'],
    ['End of Semester Examination Period', 'exam_start', 'all', '2026-09-14', '2026-09-18', 'End of semester examination period'],
    ['Marking & Uploading End Semester Results', 'other', 'all', '2026-09-14', '2026-09-30', 'Marking and uploading of end semester examination results'],
    ['Deferred Examinations', 'exam_start', 'all', '2026-09-21', '2026-09-23', 'Deferred examinations period'],
    ['Marking & Uploading Deferred Exam Results', 'other', 'all', '2026-09-23', '2026-09-30', 'Marking and uploading of deferred examination results'],
    ['End of Semester Holiday', 'break', 'all', '2026-09-21', '2026-10-02', 'End of semester holiday period'],
    ['Departmental Meeting & Uploading Grades', 'other', 'all', '2026-09-28', null, 'Departmental meeting and uploading grades into the system'],
    ['Verification of Results by Exam Committee', 'other', 'all', '2026-09-28', null, 'Verification of uploaded results in the system by exam committee'],
    ['Pre-Senate Meeting', 'other', 'weekday', '2026-09-29', null, 'Pre-senate meeting'],
    ['Consolidation of Grades (Exam Office)', 'other', 'weekday', '2026-09-30', null, 'Consolidation of grades by exam office'],
    ['Senate Meeting', 'other', 'weekday', '2026-10-01', null, 'Senate meeting'],
    ['Release of End of Semester Results', 'other', 'all', '2026-10-04', null, 'Official release of end of semester results'],
];

// =====================================================================
// SEMESTER 3: October 2026 to January 2027 (Academic Year 2026/2027)
// =====================================================================
$semester3 = [
    ['Orientation for New Students (Weekday)', 'other', 'weekday', '2026-09-28', null, 'Orientation for new Day students'],
    ['Orientation for New Students (Weekend)', 'other', 'weekend', '2026-10-03', null, 'Orientation for new Weekend students'],
    ['Opening Day & Commencement of Classes (Weekday)', 'semester_start', 'weekday', '2026-10-05', null, 'Weekday classes begin'],
    ['Opening Day & Commencement of Classes (Weekend)', 'semester_start', 'weekend', '2026-10-10', null, 'Weekend classes begin'],
    ['1st Assignment Submission', 'other', 'all', '2026-10-19', null, 'Deadline for 1st assignment submission'],
    ['Mid-Semester Examination', 'exam_start', 'all', '2026-11-02', '2026-11-06', 'Mid-semester examination period'],
    ['Mid-Semester Break', 'break', 'all', '2026-11-09', '2026-11-13', 'Mid-semester break'],
    ['Resumption of Classes (Weekday)', 'other', 'weekday', '2026-11-16', null, 'Weekday classes resume after mid-semester break'],
    ['Resumption of Classes (Weekend)', 'other', 'weekend', '2026-11-21', null, 'Weekend classes resume after mid-semester break'],
    ['2nd Assignment Submission', 'other', 'all', '2026-11-30', null, 'Deadline for 2nd assignment submission'],
    ['Study Break', 'break', 'all', '2026-12-07', '2026-12-11', 'Study break before end of semester exams'],
    ['End of Semester Examination Period', 'exam_start', 'all', '2026-12-14', '2026-12-18', 'End of semester examination period'],
    ['Marking & Uploading End Semester Results', 'other', 'all', '2026-12-14', '2026-12-30', 'Marking and uploading of end semester examination results'],
    ['Deferred Examinations', 'exam_start', 'all', '2026-12-21', '2026-12-23', 'Deferred examinations period'],
    ['Marking & Uploading Deferred Exam Results', 'other', 'all', '2026-12-23', '2026-12-30', 'Marking and uploading of deferred examination results'],
    ['End of Semester Holiday', 'break', 'all', '2026-12-23', '2027-01-15', 'End of semester holiday period'],
    ['Departmental Meeting & Uploading Grades', 'other', 'all', '2026-12-28', null, 'Departmental meeting and uploading grades into the system'],
    ['Verification of Results by Exam Committee', 'other', 'all', '2026-12-29', null, 'Verification of uploaded results in the system by exam committee'],
    ['Pre-Senate Meeting', 'other', 'weekday', '2026-12-30', null, 'Pre-senate meeting'],
    ['Consolidation of Grades (Exam Office)', 'other', 'weekday', '2026-12-30', '2027-01-01', 'Consolidation of grades by exam office'],
    ['Senate Meeting', 'other', 'weekday', '2027-01-04', null, 'Senate meeting'],
    ['Release of End of Semester Results', 'other', 'all', '2027-01-08', null, 'Official release of end of semester results'],
];

// Check how many events already exist
$existing = $conn->query("SELECT COUNT(*) as cnt FROM academic_calendar")->fetch_assoc()['cnt'];

echo "<h2>Academic Calendar Seeder - Exploits University 2026</h2>";
echo "<p>Existing events in database: <strong>$existing</strong></p>";

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    // Clear existing events if requested
    if (isset($_GET['clear']) && $_GET['clear'] === 'yes') {
        $conn->query("DELETE FROM academic_calendar");
        echo "<p style='color:orange;'>Cleared all existing calendar events.</p>";
    }

    $inserted = 0;
    $errors = 0;

    $stmt = $conn->prepare("INSERT INTO academic_calendar (academic_year, semester, event_name, event_type, program_type, start_date, end_date, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");

    // Insert Semester 1
    foreach ($semester1 as $evt) {
        $ay = '2025/2026';
        $sem = '1';
        $stmt->bind_param("ssssssss", $ay, $sem, $evt[0], $evt[1], $evt[2], $evt[3], $evt[4], $evt[5]);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $errors++;
            echo "<p style='color:red;'>Error inserting '{$evt[0]}': {$conn->error}</p>";
        }
    }

    // Insert Semester 2
    foreach ($semester2 as $evt) {
        $ay = '2025/2026';
        $sem = '2';
        $stmt->bind_param("ssssssss", $ay, $sem, $evt[0], $evt[1], $evt[2], $evt[3], $evt[4], $evt[5]);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $errors++;
            echo "<p style='color:red;'>Error inserting '{$evt[0]}': {$conn->error}</p>";
        }
    }

    // Insert Semester 3
    foreach ($semester3 as $evt) {
        $ay = '2026/2027';
        $sem = '3';
        $stmt->bind_param("ssssssss", $ay, $sem, $evt[0], $evt[1], $evt[2], $evt[3], $evt[4], $evt[5]);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $errors++;
            echo "<p style='color:red;'>Error inserting '{$evt[0]}': {$conn->error}</p>";
        }
    }

    echo "<hr>";
    echo "<p style='color:green; font-size:1.2em;'><strong>Done!</strong> Inserted <strong>$inserted</strong> events. Errors: <strong>$errors</strong></p>";
    echo "<p><a href='dean/academic-calendar.php'>Go to Dean Academic Calendar &rarr;</a></p>";
    echo "<p><a href='student/dashboard.php'>Go to Student Dashboard &rarr;</a></p>";

} else {
    // Preview
    echo "<h3>Semester 1: January - June 2026 (Academic Year 2025/2026)</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse; margin-bottom:20px;'>";
    echo "<tr style='background:#1a472a;color:white;'><th>#</th><th>Event</th><th>Type</th><th>Program</th><th>Start</th><th>End</th></tr>";
    foreach ($semester1 as $i => $evt) {
        echo "<tr><td>" . ($i+1) . "</td><td>{$evt[0]}</td><td>{$evt[1]}</td><td>{$evt[2]}</td><td>{$evt[3]}</td><td>" . ($evt[4] ?? '-') . "</td></tr>";
    }
    echo "</table>";

    echo "<h3>Semester 2: June - October 2026 (Academic Year 2025/2026)</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse; margin-bottom:20px;'>";
    echo "<tr style='background:#1a472a;color:white;'><th>#</th><th>Event</th><th>Type</th><th>Program</th><th>Start</th><th>End</th></tr>";
    foreach ($semester2 as $i => $evt) {
        echo "<tr><td>" . ($i+1) . "</td><td>{$evt[0]}</td><td>{$evt[1]}</td><td>{$evt[2]}</td><td>{$evt[3]}</td><td>" . ($evt[4] ?? '-') . "</td></tr>";
    }
    echo "</table>";

    echo "<h3>Semester 3: October 2026 - January 2027 (Academic Year 2026/2027)</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse; margin-bottom:20px;'>";
    echo "<tr style='background:#1a472a;color:white;'><th>#</th><th>Event</th><th>Type</th><th>Program</th><th>Start</th><th>End</th></tr>";
    foreach ($semester3 as $i => $evt) {
        echo "<tr><td>" . ($i+1) . "</td><td>{$evt[0]}</td><td>{$evt[1]}</td><td>{$evt[2]}</td><td>{$evt[3]}</td><td>" . ($evt[4] ?? '-') . "</td></tr>";
    }
    echo "</table>";

    $total = count($semester1) + count($semester2) + count($semester3);
    echo "<hr>";
    echo "<p><strong>Total events to insert: $total</strong> (Sem 1: " . count($semester1) . ", Sem 2: " . count($semester2) . ", Sem 3: " . count($semester3) . ")</p>";
    echo "<p><a href='?confirm=yes' style='padding:10px 20px;background:#198754;color:white;text-decoration:none;border-radius:5px;font-size:1.1em;'>Insert All Events (Keep Existing)</a></p>";
    echo "<p><a href='?confirm=yes&clear=yes' style='padding:10px 20px;background:#dc3545;color:white;text-decoration:none;border-radius:5px;font-size:1.1em;'>Clear &amp; Re-insert All Events</a></p>";
}

$conn->close();
