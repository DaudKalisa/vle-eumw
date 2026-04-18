<?php
/**
 * Setup Sample Students and Lecturers
 * Creates 10 sample students and 3 lecturers to manage Year 1 Semester 1 courses
 * 
 * Run: http://localhost/vle-eumw/setup_sample_users.php
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo '<!DOCTYPE html><html><head><title>Setup Sample Users</title>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '</head><body class="p-4"><div class="container">';
echo '<h1 class="mb-4">👥 Setup Sample Students & Lecturers</h1>';

// Default password (hashed)
$default_password = password_hash('password123', PASSWORD_DEFAULT);

// ==========================================
// CREATE 3 LECTURERS
// ==========================================
echo '<h3 class="mt-4">Creating Lecturers...</h3>';

$lecturers = [
    [
        'full_name' => 'Dr. John Banda',
        'email' => 'john.banda@eumw.ac.mw',
        'phone' => '+265 999 111 001',
        'department' => 'Business Administration',
        'position' => 'Senior Lecturer',
    ],
    [
        'full_name' => 'Prof. Mary Phiri',
        'email' => 'mary.phiri@eumw.ac.mw',
        'phone' => '+265 999 111 002',
        'department' => 'Accounting & Finance',
        'position' => 'Professor',
    ],
    [
        'full_name' => 'Mr. James Mwale',
        'email' => 'james.mwale@eumw.ac.mw',
        'phone' => '+265 999 111 003',
        'department' => 'Economics',
        'position' => 'Lecturer',
    ],
];

$lecturer_ids = [];

foreach ($lecturers as $lec) {
    // Check if lecturer exists by email
    $check = $conn->query("SELECT lecturer_id FROM lecturers WHERE email = '{$lec['email']}'")->fetch_assoc();
    
    if ($check) {
        echo "<p class='text-muted'>• Lecturer already exists: {$lec['full_name']}</p>";
        $lecturer_ids[] = $check['lecturer_id'];
        continue;
    }
    
    // Insert lecturer
    $stmt = $conn->prepare("
        INSERT INTO lecturers (full_name, email, phone, department, position, hire_date, is_active)
        VALUES (?, ?, ?, ?, ?, CURDATE(), 1)
    ");
    $stmt->bind_param("sssss", $lec['full_name'], $lec['email'], $lec['phone'], $lec['department'], $lec['position']);
    
    if ($stmt->execute()) {
        $new_lecturer_id = $stmt->insert_id;
        echo "<p class='text-success'>✓ Created lecturer: {$lec['full_name']} (ID: $new_lecturer_id)</p>";
        $lecturer_ids[] = $new_lecturer_id;
        
        // Create user account for lecturer
        $username = strtolower(explode('@', $lec['email'])[0]);
        $check_user = $conn->query("SELECT user_id FROM users WHERE username = '$username' OR email = '{$lec['email']}'")->fetch_assoc();
        
        if (!$check_user) {
            $stmt2 = $conn->prepare("
                INSERT INTO users (username, password_hash, email, role, related_lecturer_id, is_active)
                VALUES (?, ?, ?, 'lecturer', ?, 1)
            ");
            $stmt2->bind_param("sssi", $username, $default_password, $lec['email'], $new_lecturer_id);
            if ($stmt2->execute()) {
                echo "<p class='text-info'>  → Created user account: $username (password: password123)</p>";
            }
        }
    } else {
        echo "<p class='text-danger'>✗ Failed to create lecturer: {$lec['full_name']} - " . $conn->error . "</p>";
    }
}

// ==========================================
// ASSIGN LECTURERS TO COURSES
// ==========================================
echo '<h3 class="mt-4">Assigning Lecturers to Courses...</h3>';

// Get Year 1 Semester 1 courses
$courses = $conn->query("
    SELECT course_id, course_code, course_name 
    FROM vle_courses 
    WHERE year_of_study = 1 AND semester = 'One' AND is_active = 1
    ORDER BY course_code
")->fetch_all(MYSQLI_ASSOC);

if (!empty($courses) && !empty($lecturer_ids)) {
    // Distribute courses among lecturers
    $i = 0;
    foreach ($courses as $course) {
        $assigned_lecturer = $lecturer_ids[$i % count($lecturer_ids)];
        
        $stmt = $conn->prepare("UPDATE vle_courses SET lecturer_id = ? WHERE course_id = ?");
        $stmt->bind_param("si", $assigned_lecturer, $course['course_id']);
        
        if ($stmt->execute()) {
            echo "<p class='text-success'>✓ Assigned {$course['course_code']} to Lecturer: $assigned_lecturer</p>";
        }
        $i++;
    }
}

// ==========================================
// CREATE 10 SAMPLE STUDENTS
// ==========================================
echo '<h3 class="mt-4">Creating Students...</h3>';

$students = [
    [
        'student_id' => 'BBA/26/MZ/NE/0001',
        'full_name' => 'Grace Tembo',
        'email' => 'grace.tembo@student.eumw.ac.mw',
        'phone' => '+265 888 001 001',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'Mzuzu',
        'gender' => 'Female',
    ],
    [
        'student_id' => 'BBA/26/MZ/NE/0002',
        'full_name' => 'Peter Chirwa',
        'email' => 'peter.chirwa@student.eumw.ac.mw',
        'phone' => '+265 888 001 002',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'Mzuzu',
        'gender' => 'Male',
    ],
    [
        'student_id' => 'BBA/26/LL/NE/0003',
        'full_name' => 'Mercy Banda',
        'email' => 'mercy.banda@student.eumw.ac.mw',
        'phone' => '+265 888 001 003',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'Lilongwe',
        'gender' => 'Female',
    ],
    [
        'student_id' => 'BBA/26/BT/NE/0004',
        'full_name' => 'Daniel Mwanza',
        'email' => 'daniel.mwanza@student.eumw.ac.mw',
        'phone' => '+265 888 001 004',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'Blantyre',
        'gender' => 'Male',
    ],
    [
        'student_id' => 'BBA/26/ODL/NE/0005',
        'full_name' => 'Faith Nyirenda',
        'email' => 'faith.nyirenda@student.eumw.ac.mw',
        'phone' => '+265 888 001 005',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'ODel',
        'gender' => 'Female',
    ],
    [
        'student_id' => 'BAC/26/MZ/NE/0001',
        'full_name' => 'Joseph Kamanga',
        'email' => 'joseph.kamanga@student.eumw.ac.mw',
        'phone' => '+265 888 001 006',
        'program' => 'Bachelor of Accounting',
        'department' => 'BAC',
        'campus' => 'Mzuzu',
        'gender' => 'Male',
    ],
    [
        'student_id' => 'BAC/26/LL/NE/0002',
        'full_name' => 'Ruth Phiri',
        'email' => 'ruth.phiri@student.eumw.ac.mw',
        'phone' => '+265 888 001 007',
        'program' => 'Bachelor of Accounting',
        'department' => 'BAC',
        'campus' => 'Lilongwe',
        'gender' => 'Female',
    ],
    [
        'student_id' => 'BAC/26/BT/NE/0003',
        'full_name' => 'Samuel Gondwe',
        'email' => 'samuel.gondwe@student.eumw.ac.mw',
        'phone' => '+265 888 001 008',
        'program' => 'Bachelor of Accounting',
        'department' => 'BAC',
        'campus' => 'Blantyre',
        'gender' => 'Male',
    ],
    [
        'student_id' => 'BBA/26/MZ/NE/0006',
        'full_name' => 'Elizabeth Nkhoma',
        'email' => 'elizabeth.nkhoma@student.eumw.ac.mw',
        'phone' => '+265 888 001 009',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'Mzuzu',
        'gender' => 'Female',
    ],
    [
        'student_id' => 'BBA/26/ODL/NE/0007',
        'full_name' => 'Michael Mbewe',
        'email' => 'michael.mbewe@student.eumw.ac.mw',
        'phone' => '+265 888 001 010',
        'program' => 'Bachelor of Business Administration',
        'department' => 'BBA',
        'campus' => 'ODel',
        'gender' => 'Male',
    ],
];

$student_ids = [];

foreach ($students as $stu) {
    // Check if student exists
    $check = $conn->query("SELECT student_id FROM students WHERE student_id = '{$stu['student_id']}' OR email = '{$stu['email']}'")->fetch_assoc();
    
    if ($check) {
        echo "<p class='text-muted'>• Student already exists: {$stu['full_name']}</p>";
        $student_ids[] = $check['student_id'];
        continue;
    }
    
    // Insert student (using columns that exist in the table)
    $stmt = $conn->prepare("
        INSERT INTO students (student_id, full_name, email, phone, program, department, campus, gender, year_of_study, semester, enrollment_date, is_active, year_of_registration, entry_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'One', CURDATE(), 1, '2026', 'NE')
    ");
    $stmt->bind_param("ssssssss", $stu['student_id'], $stu['full_name'], $stu['email'], $stu['phone'], $stu['program'], $stu['department'], $stu['campus'], $stu['gender']);
    
    if ($stmt->execute()) {
        echo "<p class='text-success'>✓ Created student: {$stu['full_name']} ({$stu['student_id']})</p>";
        $student_ids[] = $stu['student_id'];
        
        // Create user account for student
        $username = strtolower(str_replace(['/', ' '], ['_', ''], $stu['student_id']));
        $check_user = $conn->query("SELECT user_id FROM users WHERE username = '$username' OR email = '{$stu['email']}'")->fetch_assoc();
        
        if (!$check_user) {
            $stmt2 = $conn->prepare("
                INSERT INTO users (username, password_hash, email, role, related_student_id, is_active)
                VALUES (?, ?, ?, 'student', ?, 1)
            ");
            $stmt2->bind_param("ssss", $username, $default_password, $stu['email'], $stu['student_id']);
            if ($stmt2->execute()) {
                echo "<p class='text-info'>  → Created user account: $username (password: password123)</p>";
            }
        }
    } else {
        echo "<p class='text-danger'>✗ Failed to create student: {$stu['full_name']} - " . $conn->error . "</p>";
    }
}

// ==========================================
// ENROLL STUDENTS IN COURSES
// ==========================================
echo '<h3 class="mt-4">Enrolling Students in Courses...</h3>';

if (!empty($student_ids) && !empty($courses)) {
    foreach ($student_ids as $student_id) {
        // Get student's program
        $student = $conn->query("SELECT department, program FROM students WHERE student_id = '$student_id'")->fetch_assoc();
        
        foreach ($courses as $course) {
            // Check if already enrolled
            $check = $conn->query("SELECT enrollment_id FROM vle_enrollments WHERE student_id = '$student_id' AND course_id = {$course['course_id']}")->fetch_assoc();
            
            if ($check) {
                continue; // Already enrolled
            }
            
            // Enroll student
            $stmt = $conn->prepare("
                INSERT INTO vle_enrollments (student_id, course_id, enrollment_date, current_week, is_completed)
                VALUES (?, ?, NOW(), 1, 0)
            ");
            $stmt->bind_param("si", $student_id, $course['course_id']);
            $stmt->execute();
        }
    }
    echo "<p class='text-success'>✓ Enrolled all students in Year 1 Semester 1 courses</p>";
}

// ==========================================
// SUMMARY
// ==========================================
echo '<hr>';
echo '<h3 class="text-success">✓ Setup Complete!</h3>';

echo '<div class="row mt-4">';

// Lecturers summary
echo '<div class="col-md-6">';
echo '<div class="card">';
echo '<div class="card-header bg-primary text-white"><h5 class="mb-0">👨‍🏫 Lecturers Created</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-sm">';
echo '<thead><tr><th>ID</th><th>Name</th><th>Login</th></tr></thead><tbody>';
foreach ($lecturers as $idx => $lec) {
    $username = strtolower(explode('@', $lec['email'])[0]);
    $lid = isset($lecturer_ids[$idx]) ? $lecturer_ids[$idx] : 'N/A';
    echo "<tr><td>$lid</td><td>{$lec['full_name']}</td><td><code>$username</code></td></tr>";
}
echo '</tbody></table>';
echo '<p class="text-muted small mb-0">Default password: <code>password123</code></p>';
echo '</div></div></div>';

// Students summary
echo '<div class="col-md-6">';
echo '<div class="card">';
echo '<div class="card-header bg-success text-white"><h5 class="mb-0">👨‍🎓 Students Created</h5></div>';
echo '<div class="card-body">';
echo '<table class="table table-sm">';
echo '<thead><tr><th>ID</th><th>Name</th><th>Campus</th></tr></thead><tbody>';
foreach ($students as $stu) {
    echo "<tr><td><small>{$stu['student_id']}</small></td><td>{$stu['full_name']}</td><td>{$stu['campus']}</td></tr>";
}
echo '</tbody></table>';
echo '<p class="text-muted small mb-0">Default password: <code>password123</code></p>';
echo '</div></div></div>';

echo '</div>';

// Course assignments
echo '<div class="card mt-4">';
echo '<div class="card-header bg-info text-white"><h5 class="mb-0">📚 Course Assignments</h5></div>';
echo '<div class="card-body">';
$course_assignments = $conn->query("
    SELECT c.course_code, c.course_name, l.full_name as lecturer_name, l.lecturer_id
    FROM vle_courses c
    LEFT JOIN lecturers l ON c.lecturer_id = l.lecturer_id
    WHERE c.year_of_study = 1 AND c.semester = 'One' AND c.is_active = 1
    ORDER BY c.course_code
")->fetch_all(MYSQLI_ASSOC);

echo '<table class="table table-sm">';
echo '<thead><tr><th>Course Code</th><th>Course Name</th><th>Lecturer</th></tr></thead><tbody>';
foreach ($course_assignments as $ca) {
    echo "<tr><td>{$ca['course_code']}</td><td>{$ca['course_name']}</td><td>" . ($ca['lecturer_name'] ?: 'Not assigned') . "</td></tr>";
}
echo '</tbody></table>';
echo '</div></div>';

echo '<div class="mt-4">';
echo '<a href="login.php" class="btn btn-primary btn-lg">Login to VLE</a> ';
echo '<a href="admin/dashboard.php" class="btn btn-secondary">Admin Dashboard</a> ';
echo '<a href="lecturer/dashboard.php" class="btn btn-success">Lecturer Dashboard</a> ';
echo '<a href="student/dashboard.php" class="btn btn-info">Student Dashboard</a>';
echo '</div>';

echo '</div></body></html>';
?>
