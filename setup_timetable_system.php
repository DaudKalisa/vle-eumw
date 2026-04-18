<?php
/**
 * Setup Timetable System
 * Creates tables for automated semester learning and exam timetables
 */

require_once 'includes/config.php';
$conn = getDbConnection();

$tables_created = [];
$errors = [];

// 1. Timetable Rooms / Venues
$sql = "CREATE TABLE IF NOT EXISTS timetable_rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(100) NOT NULL,
    building VARCHAR(100) DEFAULT '',
    capacity INT DEFAULT 50,
    room_type ENUM('lecture_hall','lab','seminar_room','exam_hall','online') DEFAULT 'lecture_hall',
    has_projector TINYINT(1) DEFAULT 1,
    has_computers TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if ($conn->query($sql)) {
    $tables_created[] = 'timetable_rooms';
} else {
    $errors[] = 'timetable_rooms: ' . $conn->error;
}

// Insert default rooms if empty
$check = $conn->query("SELECT COUNT(*) as cnt FROM timetable_rooms");
if ($check && $check->fetch_assoc()['cnt'] == 0) {
    $conn->query("INSERT INTO timetable_rooms (room_name, building, capacity, room_type) VALUES
        ('LH-101', 'Main Block', 100, 'lecture_hall'),
        ('LH-102', 'Main Block', 80, 'lecture_hall'),
        ('LH-103', 'Main Block', 60, 'lecture_hall'),
        ('LH-201', 'Block B', 120, 'lecture_hall'),
        ('LH-202', 'Block B', 80, 'lecture_hall'),
        ('SR-101', 'Main Block', 30, 'seminar_room'),
        ('SR-102', 'Main Block', 25, 'seminar_room'),
        ('LAB-A', 'ICT Block', 40, 'lab'),
        ('LAB-B', 'ICT Block', 40, 'lab'),
        ('LAB-C', 'ICT Block', 30, 'lab'),
        ('EH-Main', 'Exam Centre', 200, 'exam_hall'),
        ('EH-A', 'Exam Centre', 100, 'exam_hall'),
        ('EH-B', 'Exam Centre', 100, 'exam_hall'),
        ('Online', 'Virtual', 999, 'online')
    ");
}

// 2. Exam Timetable
$sql = "CREATE TABLE IF NOT EXISTS exam_timetable (
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
    INDEX (course_id),
    INDEX (exam_date),
    INDEX (semester, academic_year),
    INDEX (year_of_study)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if ($conn->query($sql)) {
    $tables_created[] = 'exam_timetable';
} else {
    $errors[] = 'exam_timetable: ' . $conn->error;
}

// 3. Timetable generation log
$sql = "CREATE TABLE IF NOT EXISTS timetable_generation_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if ($conn->query($sql)) {
    $tables_created[] = 'timetable_generation_log';
} else {
    $errors[] = 'timetable_generation_log: ' . $conn->error;
}

// 4. Add year_of_study + program_type columns to class_timetable if missing
$col_check = $conn->query("SHOW COLUMNS FROM class_timetable LIKE 'year_of_study'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE class_timetable ADD COLUMN year_of_study INT DEFAULT NULL AFTER semester");
}

$col_check = $conn->query("SHOW COLUMNS FROM class_timetable LIKE 'program_type'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE class_timetable ADD COLUMN program_type ENUM('weekday','weekend','all') DEFAULT 'all' AFTER year_of_study");
}

$col_check = $conn->query("SHOW COLUMNS FROM class_timetable LIKE 'generation_log_id'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE class_timetable ADD COLUMN generation_log_id INT DEFAULT NULL AFTER program_type");
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup Timetable System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2><i class="bi bi-calendar-week"></i> Timetable System Setup</h2>
    <hr>
    <?php if (!empty($tables_created)): ?>
    <div class="alert alert-success">
        <strong>Tables created/verified:</strong>
        <ul class="mb-0"><?php foreach ($tables_created as $t): ?><li><?= $t ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Errors:</strong>
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <p class="mt-3">Additional columns added to <code>class_timetable</code>: <code>year_of_study</code>, <code>program_type</code>, <code>generation_log_id</code></p>
    <a href="odl_coordinator/manage_timetable.php" class="btn btn-primary">Go to Timetable Management</a>
    <a href="examination_officer/manage_exam_timetable.php" class="btn btn-outline-primary">Go to Exam Timetable</a>
</div>
</body>
</html>
