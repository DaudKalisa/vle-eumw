<?php
require_once 'includes/config.php';
$conn = getDbConnection();

// Check users table ENUM
$r = $conn->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
echo "users.role: " . $r->fetch_assoc()['Type'] . PHP_EOL;

// Check students table columns
$r = $conn->query("SHOW COLUMNS FROM students");
echo "\nstudents columns:\n";
while($c = $r->fetch_assoc()) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";

// Check course-related tables
foreach (['courses','course_offerings','enrollments','student_courses'] as $tbl) {
    $r = $conn->query("SHOW TABLES LIKE '$tbl'");
    if ($r->num_rows > 0) {
        $r2 = $conn->query("SHOW COLUMNS FROM $tbl");
        echo "\n$tbl columns:\n";
        while($c = $r2->fetch_assoc()) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";
    } else {
        echo "\n$tbl: does not exist\n";
    }
}

// Check programs table
$r = $conn->query("SHOW TABLES LIKE 'programs'");
if ($r->num_rows > 0) {
    $r2 = $conn->query("SHOW COLUMNS FROM programs");
    echo "\nprograms columns:\n";
    while($c = $r2->fetch_assoc()) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";
}

// Check lecturers table columns
$r = $conn->query("SHOW COLUMNS FROM lecturers");
echo "\nlecturers columns:\n";
while($c = $r->fetch_assoc()) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";

$conn->close();
